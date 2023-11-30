<?php

namespace Vanderbilt\CareerDevLibrary;

use Vanderbilt\FlightTrackerExternalModule\FlightTrackerExternalModule;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

function preprocessFindSharingMatches($pids, $destPids = NULL) {
    if (!isset($destPids)) {
        $destPids = $pids;
    }
    if (empty($pids) || empty($destPids)) {
        return;
    }
    $module = Application::getModule();
    if ($module) {
        $module->findMatches($pids, $destPids);
        $module->addMatchProcessingToCron($destPids);
    }
}

function preprocessMissingEmails($pids) {
    $module = Application::getModule();
    if ($module) {
        $module->searchForMissingEmails($pids);
    }
}

function preprocessSharingMatches($destPids, $idx = 0) {
    $allPids = Application::getPids();
    if (empty($allPids) || empty($destPids)) {
        return;
    }
    $module = Application::getModule();
    if ($module) {
        $module->processFoundMatches($allPids, $destPids, $idx);
    }
}

function preprocessPortal($pids, $destPids = NULL) {
    if (!isset($destPids)) {
        $destPids = $pids;
    }
    if (empty($pids) || empty($destPids)) {
        return;
    }
    $module = Application::getModule();
    if ($module) {
        $module->preprocessScholarPortal($pids, $destPids);
    }
}

function downloadPortalData($pids) {
    if (empty($pids)) {
        return;
    }
    $module = Application::getModule();
    if ($module) {
        $module->preprocessScholarPortalData($pids);
    }
}

# one-time cleanup script
# adapted from projects/dedupResources.php
function dedupResources($token, $server, $pid, $records) {
    $fields = [
        "record_id",
        "resources_date",
        "resources_resource",
    ];
    $metadataFields = Download::metadataFieldsByPid($pid);
    if (in_array("resources_resource", $metadataFields)) {
        $records = Download::recordIdsByPid($pid);
        foreach ($records as $recordId) {
            $redcapData = Download::fieldsForRecordsByPid($pid, $fields, [$recordId]);
            $seen = [];
            $instancesToDelete = [];
            foreach ($redcapData as $row) {
                if (($row['redcap_repeat_instrument'] == "resources") && $row['resources_resource']) {
                    # resources_resource is a dropdown value and date
                    $key = $row['resources_resource']."|".$row['resources_date'];
                    if (!in_array($key, $seen)) {
                        $seen[] = $key;
                    } else {
                        $instancesToDelete[] = $row['redcap_repeat_instance'];
                    }
                }
            }
            if (!empty($instancesToDelete)) {
                $token = Application::getSetting("token", $pid);
                $server = Application::getSetting("server", $pid);
                Upload::deleteFormInstances($token, $server, $pid, "resources_", $recordId, $instancesToDelete);
            }
        }
    }
}