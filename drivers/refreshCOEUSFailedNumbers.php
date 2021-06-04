<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\StarBRITE;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

function refreshCoeus2Numbers($token, $server, $pid) {
    $startingRecord = 1;

    $coeus2Fields = [
        "record_id",
        "coeus2_id",
        "coeus2_altid",
        "coeus2_title",
        "coeus2_collaborators",
        "coeus2_role",
        "coeus2_in_progress",
        "coeus2_approval_in_progress",
        "coeus2_award_status",
        "coeus2_submitted_to_agency",
        "coeus2_awaiting_pi_approval",
        "coeus2_status",
        "coeus2_center_number",
        "coeus2_lead_department",
        "coeus2_agency_id",
        "coeus2_agency_name",
        "coeus2_agency_grant_number",
        "coeus2_grant_award_type",
        "coeus2_current_period_indirect_funding",
        "coeus2_current_period_total_funding",
        "coeus2_current_period_start",
        "coeus2_current_period_end",
        "coeus2_grant_activity_type",
        "coeus2_current_period_direct_funding",
        "coeus2_last_update",
    ];
    $metadata = Download::metadata($token, $server);
    $choices = REDCapManagement::getChoices($metadata);
    $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
    $records = Download::recordIds($token, $server);
    $vunets = Download::userids($token, $server, $metadata);
    foreach ($records as $recordId) {
        $vunet = $vunets[$recordId];
        if ($vunet && ($recordId >= $startingRecord)) {
            $starbriteData = StarBRITE::dataForUserid($vunet, $pid);
            $redcapData = Download::fieldsForRecords($token, $server, $coeus2Fields, [$recordId]);
            $maxInstance = REDCapManagement::getMaxInstance($redcapData, "coeus2", $recordId);
            $instances = [];
            $awarded = [];
            $upload = [];
            foreach ($redcapData as $row) {
                if ($row['redcap_repeat_instrument'] == "coeus2") {
                    if ($row['coeus2_award_status'] != "Awarded") {
                        $instances[$row['coeus2_id']] = $row['redcap_repeat_instance'];
                    } else {
                        $awarded[$row['coeus2_id']] = $row['redcap_repeat_instance'];
                    }
                }
            }
            foreach ($starbriteData['data'] as $award) {
                if (!$awarded[$award['id']]) {
                    $instance = $instances[$award['id']];
                    if (!$instance) {
                        $maxInstance++;
                        $instance = $maxInstance;
                    }
                    $uploadRow = StarBRITE::formatForUpload($award, $vunet, $instance, $recordId, $choices);
                    if (REDCapManagement::allFieldsValid($uploadRow, $metadataFields)) {
                        $upload[] = $uploadRow;
                    } else {
                        Application::log("Row invalid: ".REDCapManagement::json_encode_with_spaces($uploadRow));
                    }
                }
            }
            if (!empty($upload)) {
                $feedback = Upload::rows($upload, $token, $server);
                Application::log("Record $recordId: ".json_encode($feedback));
            }
        }
    }
}

