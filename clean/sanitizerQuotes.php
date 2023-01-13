<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

function transformBadQuotes($token, $server, $pid, $records) {
    $patterns = [
        "&, amp, #039, " => "'",
        "&amp, amp, #039, " => "'",
        "&amp, #039, " => "'",
        "&amp, amp, " => "&",
        "&amp, amp" => "&",
        "&#039, " => "'",
        "&amp;#039;" => "'",
        "&#039;" => "'",
        "&amp;" => "&",
        "&#039" => "'",
        "&amp" => "&",
    ];
    $screens = [
        "#039",
        "&amp",
    ];

    $module = Application::getModule();
    foreach ($screens as $char) {
        $regex = "%$char%";
        $q = $module->query("SELECT * FROM redcap_data WHERE project_id = ? AND value LIKE ?", [$pid, $regex]);
        while ($row = $q->fetch_assoc()) {
            $value = $row['value'];
            $found = FALSE;
            foreach ($patterns as $pattern => $fill) {
                if (strpos($value, $pattern) !== FALSE) {
                    $value = str_replace($pattern, $fill, $value);
                    $found = TRUE;
                    break;
                }
            }
            if ($found) {
                $fieldName = $row['field_name'];
                $record = $row['record'];
                $params = [$value, $pid, $record, $fieldName];
                if (!$row['instance']) {
                    $instance = " IS NULL";
                } else {
                    $instance = " = ?";
                    $params[] = $row['instance'];
                }
                $sql = "UPDATE redcap_data SET value = ? WHERE project_id = ? AND record = ? AND field_name = ? AND instance $instance";
                $module->query($sql, $params);
            }
        }
    }

    # regenerate identifier_institution
    $metadata = Download::metadata($token, $server);
    foreach ($records as $recordId) {
        $rows = Download::records($token, $server, [$recordId]);
        $grants = new Grants($token, $server, $metadata);
        $grants->setRows($rows);
        $grants->compileGrants();
        $grants->uploadGrants();

        $scholar = new Scholar($token, $server, $metadata, $pid);
        $scholar->setGrants($grants);
        $scholar->downloadAndSetup($recordId);
        $scholar->process();
        $scholar->upload();

        removeCitationsDownloadedAfter($token, $server, $pid, $rows, $metadata, $recordId, "2022-10-20");
    }
}

function removeCitationsDownloadedAfter($token, $server, $pid, $rows, $metadata, $recordId, $date)
{
    $metadataFields = DataDictionaryManagement::getFieldsFromMetadata($metadata);
    $threshold = strtotime($date);
    $instancesToDelete = [];
    if (in_array("citation_last_update", $metadataFields)) {
        foreach ($rows as $row) {
            if (($row['redcap_repeat_instrument'] == "citation") && $row['citation_last_update']) {
                $ts = strtotime($row['citation_last_update']);
                if ($ts >= $threshold) {
                    $instancesToDelete[] = $row['redcap_repeat_instance'];
                }
            }
        }
    } else {
        $dateTs = strtotime($date);
        $redcapTs = date("Ymd000000", $dateTs);
        $log_event_table = method_exists('\REDCap', 'getLogEventTable') ? \REDCap::getLogEventTable($pid) : "redcap_log_event";

        $module = Application::getModule();
        $params = [$pid, $redcapTs, $recordId];
        $sql = "SELECT data_values FROM $log_event_table WHERE project_id = ? AND ts >= ? AND pk = ? AND data_values LIKE '%citation_%'";
        $q = $module->query($sql, $params);
        while ($row = $q->fetch_assoc()) {
            if (
                preg_match("/\[instance = (\d+)\]/", $row['data_values'], $matches)
                && preg_match("/citation_pmid/", $row['data_values'])
            ) {
                for ($i = 1; $i < count($matches); $i++) {
                    $instancesToDelete[] = $matches[$i];
                }
            }
        }
    }
    if (!empty($instancesToDelete)) {
        Upload::deleteFormInstances($token, $server, $pid, "citation_", $recordId, $instancesToDelete);
    }
}