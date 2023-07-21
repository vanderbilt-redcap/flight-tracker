<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
if (Application::isSocialMediaProject($project_id) && ($instrument == "scholars_social_media")) {
    $handleFields = ["identifier_twitter", "identifier_linkedin"];
    $myData = \REDCap::getData($project_id, "json-array", [$record]);
    $hasData = FALSE;
    foreach ($myData as $row) {
        foreach ($handleFields as $field) {
            $myField = makeSocialMediaField($field);
            if ($row[$myField]) {
                $hasData = TRUE;
            }
        }
    }
    if (!$hasData) {
        exit;
    }

    $matches = [];
    foreach (Application::getPids() as $currPid) {
        if (REDCapManagement::isActiveProject($currPid)) {
            $currToken = Application::getSetting("token", $currPid);
            $currServer = Application::getSetting("server", $currPid);
            foreach ($myData as $row) {
                $values = [];
                foreach ($handleFields as $field) {
                    $myField = makeSocialMediaField($field);
                    if ($row[$myField]) {
                        $values[$field] = $row[$myField];
                    }
                }
                if (!empty($values) && $currToken && $currServer) {
                    $firstNames = Download::firstnames($currToken, $currServer);
                    $lastNames = Download::lastnames($currToken, $currServer);
                    foreach ($firstNames as $recordId => $firstName) {
                        $lastName = $lastNames[$recordId] ?? "";
                        if (
                            $firstName && $lastName && $row['first_name'] && $row['last_name']
                            && NameMatcher::matchName($row['first_name'], $row['last_name'], $firstName, $lastName)
                        ) {
                            $id = $currPid.":".$recordId;
                            $matches[$id] = $values;
                        }
                    }
                }
            }
        }
    }

    foreach ($matches as $id => $values) {
        list($currPid, $recordId) = explode(":", $id);
        $currToken = Application::getSetting("token", $currPid);
        $currServer = Application::getSetting("server", $currPid);
        $redcapData = Download::fieldsForRecords($currToken, $currServer, array_merge(["record_id"], $handleFields), [$recordId]);
        $uploadRow = ["record_id" => $recordId];
        foreach ($redcapData as $row) {
            foreach ($handleFields as $field) {
                if (isset($values[$field]) && $values[$field]) {
                    $uploadRow[$field] = $row[$field] ? $row[$field].", ".$values[$field] : $values[$field];
                }
            }
        }
        if (count($uploadRow) > 1) {
            Upload::oneRow($uploadRow, $currToken, $currServer);
        }
    }
    exit;
}

require_once(dirname(__FILE__)."/../small_base.php");

global $token, $server;

Application::refreshRecordSummary($token, $server, $project_id, $record);
if (in_array($instrument, ["initial_survey", "followup", "initial_import"])) {
    uploadPositionChangesFromSurveys($token, $server, $project_id, $record, $instrument, $repeat_instance ?? 1);
}



function uploadPositionChangesFromSurveys($token, $server, $pid, $recordId, $instrument, $requestedInstance = 1) {
    $metadata = Download::metadata($token, $server);
    $choices = REDCapManagement::getChoices($metadata);
    $relevantFields = REDCapManagement::getFieldsFromMetadata($metadata, $instrument);
    if (empty($relevantFields)) {
        return;
    }
    $relevantFields[] = $instrument."_complete";
    $positionFields = REDCapManagement::getFieldsFromMetadata($metadata, "position_change");
    $positionFields[] = "position_change_complete";
    $repeatingForms = REDCapManagement::getRepeatingForms($pid);
    $prefix = REDCapManagement::getPrefixFromInstrument($instrument);
    if (!preg_match("/_$/", $prefix)) {
        $prefix = $prefix."_";
    }
    $relevantFields[] = "record_id";
    $positionFields[] = "record_id";

    $redcapData = Download::fieldsForRecords($token, $server, $relevantFields, [$recordId]);
    $positionData = Download::fieldsForRecords($token, $server, $positionFields, [$recordId]);
    $maxPositionInstance = REDCapManagement::getMaxInstance($positionData, "position_change", $recordId);
    // Application::log("$recordId has maxInstance $maxInstance");

    $fieldPrefixes = [ preg_replace("/_$/", "", $prefix), $prefix."prev_1", $prefix."prev_2", $prefix."prev_3", $prefix."prev_4", $prefix."prev_5"];
    if (in_array($instrument, $repeatingForms)) {
        foreach ($redcapData as $row) {
            if (($row['redcap_repeat_instrument'] == $instrument) && ($row['redcap_repeat_instance'] == $requestedInstance)) {
                getDataForPrefixAndPossiblyUpload($token, $server, $row, $choices, $fieldPrefixes, $recordId, $positionData, $maxPositionInstance);
            }
        }
    } else {
        getDataForPrefixAndPossiblyUpload($token, $server, $redcapData[0], $choices, $fieldPrefixes, $recordId, $positionData, $maxPositionInstance);
    }
}

function getDataForPrefixAndPossiblyUpload($token, $server, $row, $choices, $prefixes, $recordId, $positionData, &$maxInstance) {
    foreach ($prefixes as $prefix) {
        $transferData = getPositionDataFromSurvey($row, $choices, $prefix);
        if (!empty($transferData) && !isDataAlreadyCopied($positionData, $transferData)) {
            $maxInstance++;
            $transferData['record_id'] = $recordId;
            $transferData['redcap_repeat_instrument'] = "position_change";
            $transferData['redcap_repeat_instance'] = $maxInstance;
            Upload::oneRow($transferData, $token, $server);
        }
    }
}

function getPositionDataFromSurvey($row, $choices, $prefix) {
    if (!preg_match("/_$/", $prefix)) {
        $prefix .= "_";
    }

    $fields = ['job_title', 'job_category', 'institution', 'primary_dept', 'academic_rank'];
    $hasData = FALSE;
    foreach ($fields as $field) {
        if (isset($row[$prefix.$field]) && $row[$prefix.$field]) {
            $hasData = TRUE;
            break;
        }
    }

    if (!$hasData) {
        return [];
    }
    $transferData = [];
    $transferData['promotion_job_title'] = $row[$prefix.'job_title'] ?? "";
    $transferData['promotion_job_category'] = $row[$prefix.'job_category'] ?? "";
    if (isset($choices[$prefix.'institution'])) {
        $institutionName = $choices[$prefix.'institution'][$row[$prefix.'institution']];
    } else {
        $institutionName = $row[$prefix.'institution'] ?? "";
    }
    $institutionName = trim($institutionName);
    $lowercaseInstitutionName = strtolower($institutionName);
    if ($lowercaseInstitutionName == "vanderbilt") {
        $transferData['promotion_institution'] = "Vanderbilt University Medical Center";   // ???
    } else if ($lowercaseInstitutionName == "other") {
        $transferData['promotion_institution'] = $row[$prefix.'institution_oth'] ?? "";
    } else {
        $transferData['promotion_institution'] = $institutionName;
    }
    $department = $row[$prefix.'primary_dept'];
    if (isset($choices["promotion_department"][$department])) {
        $transferData['promotion_department'] = $department;
    } else if (isset($choices["promotion_department"]['999999'])) {
        $transferData['promotion_department'] = '999999';
        $transferData['promotion_department_other'] = $choices[$prefix.'primary_dept'][$department];
    } else {
        $transferData['promotion_department'] = $choices[$prefix.'primary_dept'][$department] ?? $department;
    }
    $transferData['promotion_division'] = $row[$prefix.'division'] ?? "";
    $transferData['promotion_date'] = date("Y-m-d");
    $transferData['promotion_in_effect'] = $row[$prefix.'academic_rank_dt'] ?? "";
    $transferData['promotion_rank'] = $row[$prefix.'academic_rank'] ?? "";
    $transferData['position_change_complete'] = "2";

    return $transferData;
}

function isDataAlreadyCopied($positionData, $transferData) {
    $skip = ["promotion_date", "redcap_repeat_instance", "position_change_complete"];
    $hasDataAlready = FALSE;
    foreach ($positionData as $row) {
        $allFieldsPresent = TRUE;
        foreach ($transferData as $field => $value) {
            if (!in_array($field, $skip) && (trim($row[$field]) != trim($value))) {
                Application::log("For $field, ".$row[$field]." != ".$value);
                $allFieldsPresent = FALSE;
                break;
            }
        }
        if ($allFieldsPresent) {
            $hasDataAlready = TRUE;
            break;
        }
    }
    return $hasDataAlready;
}

function makeSocialMediaField($redcapField) {
    return str_replace("identifier_", "", $redcapField)."_handle";
}