<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

global $token, $server;

if ($instrument == "identifiers") {
    $module = Application::getModule();
	$sql = "SELECT field_name FROM redcap_data WHERE project_id = ? AND record = ? AND field_name LIKE '%_complete'";
	$q = $module->query($sql, [$project_id, $record]);
	if ($q->num_rows == 1) {
		if ($row = $q->fetch_assoc($q)) {
			if ($row['field_name'] == "identifiers_complete") {
				# new record => only identifiers form filled out
				\Vanderbilt\FlightTrackerExternalModule\queueUpInitialEmail($record);
			}
		}
	}
}
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
    if ($institutionName == "Vanderbilt") {
        $transferData['promotion_institution'] = "Vanderbilt University Medical Center";   // ???
    } else if ($institutionName == "Other") {
        $transferData['promotion_institution'] = $row[$prefix.'institution_oth'] ?? "";
    } else {
        $transferData['promotion_institution'] = $institutionName;
    }
    $department = $row[$prefix.'primary_dept'];
    if ($choices["promotion_department"][$department]) {
        $transferData['promotion_department'] = $department;
    } else if ($department !== "") {
        $transferData['promotion_department'] = '999999';
        $transferData['promotion_department_other'] = $choices[$prefix.'primary_dept'][$department];
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

