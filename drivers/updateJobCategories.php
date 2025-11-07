<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

function updateJobCategories($token, $server, $pid, $records) {
    if (Application::getSetting("updated_job_categories", $pid)) {
        return;
    }
    $records = Download::recordIds($token, $server);
    $metadataFields = Download::metadataFields($token, $server);

    if (
        in_array("promotion_workforce_sector", $metadataFields)
        && in_array("promotion_activity", $metadataFields)
    ) {
        $jobCategories = Download::oneFieldWithInstances($token, $server, "promotion_job_category");
        $employers = Download::oneFieldWithInstances($token, $server, "promotion_institution");

        $upload = [];
        foreach ($records as $recordId) {
            foreach ($jobCategories[$recordId] ?? [] as $instance => $cat) {
                $employer = $employers[$recordId][$instance] ?? "";
                $sector = "";
                $activity = "";
                if ($cat == "1") {
                    $sector = "1";
                    $activity = "1";
                } else if ($cat == "2") {
                    $sector = "1";
                    $activity = "3";
                } else if ($cat == "3") {
                    $sector = "3";
                    $activity = "3";
                } else if ($cat == "4") {
                    if (isGovernment($employer)) {
                        $sector = "2";
                    } else {
                        $sector = "3";
                    }
                    $activity = "1";
                } else if ($cat == "5") {
                    $sector = "1";
                    $activity = "1";
                } else if ($cat == "6") {
                    if (isGovernment($employer)) {
                        $sector = "2";
                    } else {
                        $sector = "3";
                    }
                    $activity = "6";
                } else if ($cat == "7") {
                    $sector = "1";
                    $activity = "5";
                }
                if ($sector && $activity) {
                    $upload[] = [
                        "record_id" => $recordId,
                        "redcap_repeat_instrument" => "position_change",
                        "redcap_repeat_instance" => $instance,
                        "promotion_workforce_sector" => $sector,
                        "promotion_activity" => $activity,
                        "position_change_complete" => "2",
                    ];
                }
            }
        }

        if (!empty($upload)) {
            Upload::rows($upload, $token, $server);
        }
        Application::saveSetting("updated_job_categories", "1", $pid);
    }
}

function isGovernment($employer) {
    $employer = strtolower($employer);
    $governmentAgencies = [
        "CDC",
        "Centers for Disease Control",
        "NIH",
        "National Institutes of Health",
        "AHRQ",
        "HHS",
        "Health and Human Services",
        "VHA",
        "Veterans Health Administration",
        "Veterans Affairs",
        "Indian Health Service",
    ];
    foreach ($governmentAgencies as $agency) {
        if (preg_match("/$agency/i", $employer)) {
            return TRUE;
        }
    }
    return FALSE;
}