<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../classes/Download.php");

$metadata = Download::metadata($token, $server);
$recordIds = Download::recordIds($token, $server);
$names = Download::names($token, $server);
foreach ($recordIds as $recordId) {
    $recordData = Download::fieldsForRecords($token, $server, Application::getCitationFields($metadata), [$recordId]);
    $dupInstances = [];
    $pmids = [];
    $invalidPMIDs = [];
    $validIncludes = [1, ""];
    foreach ($recordData as $row) {
        if ($row['redcap_repeat_instrument'] == "citation") {
            if (!in_array($row['citation_pmid'], $pmids) && in_array($row['citation_include'], $validIncludes)) {
                $pmids[] = $row['citation_pmid'];
            } else if (!in_array($row['citation_pmid'], $pmids)) {
                # not a validInclude
                $invalidPMIDs[$row['redcap_repeat_instance']] = $row['citation_pmid'];
            } else {
                $dupInstances[] = $row['redcap_repeat_instance'];
            }
        }
    }

    foreach ($invalidPMIDs as $instance => $pmid) {
        if (in_array($pmid, $pmids)) {
            # dup
            $dupInstances[] = $instance;
        } # else rejected PMID => valid
    }

    # delete $dupInstances
    if (!empty($dupInstances)) {
        $errors = [];
        $successful = [];
        foreach ($dupInstances as $instance) {
            if ($instance == "1") {
                $instanceClause = "instance IS NULL";
            } else {
                $instanceClause = "instance = '".db_real_escape_string($instance)."'";
            }
            $sql = "DELETE FROM redcap_data WHERE project_id = '".db_real_escape_string($pid)."' AND field_name LIKE 'citation_%' AND record='".db_real_escape_string($recordId)."' AND ".$instanceClause;
            db_query($sql);
            if ($error = db_error()) {
                echo "ERROR for Record $recordId instance $instance: $error in $sql<br>";
                $errors[] = $instance;
            } else {
                $successful[] = $instance;
            }
        }
        if (!empty($successful)) {
            echo "Record $recordId (".$names[$recordId]."): ".count($successful)." instances successfully deleted.<br>";
        }
        if (!empty($errors)) {
            echo "Record $recordId (".$names[$recordId]."): ".count($errors)." ERRORS!<br>";
        }
    } else {
        echo "Record $recordId: No duplicate instances found.<br>";
    }
}