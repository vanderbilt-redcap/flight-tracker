<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\StarBRITE;
use \Vanderbilt\CareerDevLibrary\LDAP;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/../classes/StarBRITE.php");
require_once(dirname(__FILE__)."/../classes/LDAP.php");

function processCoeus2($token, $server, $pid) {
    $userids = Download::userids($token, $server);
    $metadata = Download::metadata($token, $server);
    $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
    $choices = REDCapManagement::getChoices($metadata);
    $instrument = "coeus2";
    $prefix = "coeus2_";
    foreach ($userids as $recordId => $userid) {
        Application::log("Looking up COEUS information for Record $recordId");
        if ($userid) {
            $starbriteData = StarBRITE::dataForUserid($userid);
            $redcapData = Download::formForRecords($token, $server, $instrument, [$recordId]);
            $maxInstance = REDCapManagement::getMaxInstance($redcapData, $instrument, $recordId);
            $nextInstance = $maxInstance + 1;
            $priorIDs = getPriorCOEUSAwardIds($redcapData);
            $upload = [];
            $instancesToUpload = [];
            foreach ($starbriteData['data'] as $award) {
                $id = $award['id'];
                if (!in_array($id, $priorIDs)) {
                    $altId = $award['altId'];
                    $title = $award['title'];
                    $role = getCOEUSUser($userid, $award["users"], $choices[$prefix . 'role']);
                    $collabs = getCOEUSCollabs($userid, $award["users"]);
                    $uploadRow = [
                        "record_id" => $recordId,
                        "redcap_repeat_instrument" => $instrument,
                        "redcap_repeat_instance" => $nextInstance,
                        "coeus2_last_update" => date("Y-m-d"),
                        "coeus2_complete" => "2",
                        $prefix . "id" => $id,
                        $prefix . "altid" => $altId,
                        $prefix . "role" => $role,
                        $prefix . "collaborators" => $collabs,
                        $prefix . "title" => $title,
                    ];
                    foreach ($award['blocks'] as $item) {
                        $field = $prefix . makeLabelIntoField($item['label']);
                        $order = ["date", "content", "description"];
                        $uploadRow[$field] = getCOEUSNodeValue($order, $item);
                    }
                    foreach ($award['details'] as $item) {
                        $field = $prefix . makeLabelIntoField($item['label']);
                        $order = ["content", "description"];
                        $uploadRow[$field] = REDCapManagement::convertDollarsToNumber(getCOEUSNodeValue($order, $item));
                    }

                    if (allFieldsValid($uploadRow, $metadataFields)) {
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
    CareerDev::saveCurrentDate("Last StarBRITE COEUS Pull", $pid);
}

function allFieldsValid($row, $metadataFields) {
    $skip = ["redcap_repeat_instrument", "redcap_repeat_instance", "coeus2_complete"];
    foreach ($row as $field => $value) {
        if (!in_array($field, $skip) && !in_array($field, $metadataFields)) {
            return FALSE;
        }
    }
    return TRUE;
}

function getCOEUSNodeValue($order, $item) {
    foreach ($order as $awardField) {
        if ($item[$awardField]) {
            return $item[$awardField];
        }
    }
    return "";
}

function makeLabelIntoField($label) {
    return REDCapManagement::makeHTMLId(strtolower($label));
}

function getCOEUSCollabs($userid, $awardUsers) {
    $collabs = [];
    foreach ($awardUsers as $user) {
        if ($user['vunet'] != $userid) {
            try {
                $name = LDAP::getName($user['vunet']);
            } catch (\Exception $e) {
                Application::log("ERROR: ".$e->getMessage());
                $name = "";
            }
            if ($name) {
                $collabs[] = $name." (".$user['vunet']."; ".$user['role'].")";
            } else {
                $collabs[] = $user['vunet']." (".$user['role'].")";
            }
        }
    }
    return implode(", ", $collabs);
}

function getCOEUSUser($userid, $awardUsers, $roleChoices) {
    foreach ($awardUsers as $user) {
        if ($user['vunet'] == $userid) {
            foreach ($roleChoices as $idx => $label) {
                if ($label == $user['role']) {
                    return $idx;
                }
            }
        }
    }
    return "";
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