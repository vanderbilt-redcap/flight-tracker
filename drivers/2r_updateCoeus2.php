<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\StarBRITE;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\LDAP;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/../classes/StarBRITE.php");
require_once(dirname(__FILE__)."/../classes/LDAP.php");

function processCoeus2($token, $server, $pid, $records) {
    $userids = Download::userids($token, $server);
    $metadata = Download::metadata($token, $server);
    $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
    $choices = REDCapManagement::getChoices($metadata);
    $instrument = "coeus2";
    $prefix = "coeus2_";
    foreach ($records as $recordId) {
        $userid = $userids[$recordId];
        Application::log("Looking up COEUS information for Record $recordId");
        $upload = [];
        if ($userid) {
            $starbriteData = StarBRITE::dataForUserid($userid, $pid);
            $redcapData = Download::formForRecords($token, $server, $instrument, [$recordId]);
            $maxInstance = REDCapManagement::getMaxInstance($redcapData, $instrument, $recordId);
            $nextInstance = $maxInstance + 1;
            $priorIDs = getPriorCOEUSAwardIds($redcapData);
            $instancesToUpload = [];
            foreach ($starbriteData['data'] as $award) {
                $id = $award['id'];
                if (!in_array($id, $priorIDs)) {
                    $uploadRow = StarBRITE::formatForUpload($award, $userid, $nextInstance, $recordId, $choices);
                    if (REDCapManagement::allFieldsValid($uploadRow, $metadataFields)) {
                        $upload[] = $uploadRow;
                        $instancesToUpload[] = $uploadRow['redcap_repeat_instance'];
                        $nextInstance++;
                    } else {
                        $invalidFields = [];
                        $skip = ["redcap_repeat_instrument", "redcap_repeat_instance", "coeus2_complete"];
                        foreach ($uploadRow as $field => $value) {
                            if (!in_array($field, $skip) && !in_array($field, $metadataFields)) {
                                $invalidFields[] = $field;
                            }
                        }
                        Application::log("ERROR: Invalid fields: ".json_encode($invalidFields));
                    }
                }
            }
        }
        if (!empty($upload)) {
            Application::log("Uploading instances ".json_encode($instancesToUpload)." to Record $recordId");
            Upload::rows($upload, $token, $server);
        }
    }
    REDCapManagement::deduplicateByKey($token, $server, $pid, $records, "coeus2_id", "coeus2", "coeus2");
    CareerDev::saveCurrentDate("Last StarBRITE COEUS Pull", $pid);
}

function getPriorCOEUSAwardIds($redcapData) {
    $ids = [];
    foreach ($redcapData as $row) {
        if ($row['coeus2_id'] && ($row['redcap_repeat_instrument'] == "coeus2")) {
            $ids[] = $row['coeus2_id'];
        }
    }
    return $ids;
}