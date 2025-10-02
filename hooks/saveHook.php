<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\HonorsAwardsActivities;
use Vanderbilt\CareerDevLibrary\NameMatcher;
use Vanderbilt\CareerDevLibrary\Upload;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

if (Application::isSocialMediaProject($project_id) && ($instrument == "scholars_social_media")) {
	$handleFields = ["identifier_twitter", "identifier_linkedin"];
	$myData = \REDCap::getData($project_id, "json-array", [$record]);
	$hasData = false;
	foreach ($myData as $row) {
		foreach ($handleFields as $field) {
			$myField = makeSocialMediaField($field);
			if ($row[$myField]) {
				$hasData = true;
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

# avoids special projects; now, we know that this is a normal Flight Tracker project
require_once(dirname(__FILE__)."/../small_base.php");

global $token, $server;

Application::refreshRecordSummary($token, $server, $project_id, $record);
if (in_array($instrument, ["initial_survey", "followup", "initial_import"])) {
	uploadPositionChangesFromSurveys($project_id, $record, $instrument, $repeat_instance ?? 1);
}
if (in_array($instrument, ["initial_survey", "followup"])) {
	uploadActivitiesFromSurveys($project_id, $record, $instrument, $repeat_instance ?? 1);
}

function uploadActivitiesFromSurveys($pid, $recordId, $instrument, $requestedInstance = 1) {
	$surveyPrefix = REDCapManagement::getPrefixFromInstrument($instrument);
	$activityInstrument = "honors_awards_and_activities";
	$activityPrefix = REDCapManagement::getPrefixFromInstrument($activityInstrument);
	$metadataFields = Download::metadataFieldsByPid($pid);
	$allSurveyFields = DataDictionaryManagement::filterFieldsForPrefix($metadataFields, $surveyPrefix);
	$activityFields = DataDictionaryManagement::filterFieldsForPrefix($metadataFields, $activityPrefix);

	$surveyHonorFields = ["record_id"];
	foreach ($allSurveyFields as $field) {
		if (preg_match("/^$surveyPrefix"."_honor\d_/", $field) && !preg_match("/_descr$/", $field)) {
			$surveyHonorFields[] = $field;
		}
	}
	$surveyHonorFields[] = $surveyPrefix."_date";    // date the survey was filled out

	// Suffixes on the award form should mirror all instances on each survey, except for the date and userid
	$suffixes = [];
	foreach ($activityFields as $field) {
		if (!preg_match("/_datetime$/", $field) && !preg_match("/_userid$/", $field)) {
			$suffixes[] = preg_replace("/^$activityPrefix/", "", $field);
		}
	}

	# activityData will only match on two fields for duplicates: Name and award year
	# coordinated with function hasMatchWithActivityData
	# if there's a match based on these two fields, no data will be copied
	# this should avoid the situation when there are repetitive survey saves
	$activityData = Download::fieldsForRecordsByPid($pid, ["record_id", $activityPrefix."_name", $activityPrefix."_award_year"], [$recordId]);
	$surveyData = Download::fieldsForRecordsByPid($pid, $surveyHonorFields, [$recordId]);
	$surveyRow = REDCapManagement::getNormativeRow($surveyData);
	foreach ($surveyData as $row) {
		if (($row['redcap_repeat_instrument'] == $instrument) && ($row['redcap_repeat_instance'] == $requestedInstance)) {
			# in case of a repeating survey
			$surveyRow = $row;
			break;
		}
	}
	$prefixesWithNewData = [];
	for ($i = 1; $i <= HonorsAwardsActivities::NUM_SURVEY_ACTIVITIES; $i++) {
		$honorPrefix = $surveyPrefix."_honor$i";
		$honorName = $surveyRow[$honorPrefix."_name"];   // required
		$honorYear = $surveyRow[$honorPrefix."_award_year"];
		if ($honorName && !hasMatchWithActivityData($activityData, $activityPrefix, $honorName, $honorYear)) {
			foreach ($suffixes as $suffix) {
				$value = $surveyRow[$honorPrefix.$suffix] ?? "";
				if ($value !== "") {
					$prefixesWithNewData[] = $honorPrefix;
					break;
				}
			}
		}
	}

	$maxActivityInstance = REDCapManagement::getMaxInstance($activityData, $activityInstrument, $recordId);
	$surveyDate = $surveyRow[$surveyPrefix."_date"] ?: date("Y-m-d");  // will need to append time
	$upload = [];
	foreach ($prefixesWithNewData as $prefix) {
		$honorName = $surveyRow[$prefix."_name"] ?? "";
		if ($honorName !== "") {      // double-check ==> should always have a name by this point
			$uploadRow = [
				"record_id" => $recordId,
				"redcap_repeat_instrument" => $activityInstrument,
				"redcap_repeat_instance" => $maxActivityInstance + 1,
				$activityPrefix."_datetime" => $surveyDate." 00:00",    // unknown time - not super-important
				$activityPrefix."_userid" => "Scholar",
				$activityInstrument."_complete" => "2",
			];
			$maxActivityInstance++;
			foreach ($suffixes as $suffix) {
				$value = $surveyRow[$prefix.$suffix];
				if ($value !== "") {
					$uploadRow[$activityPrefix.$suffix] = $value;
				}
			}
			if (count($uploadRow) > 6) {
				# Should always be true, but I want to be safe in case of a bug-laden runaway process
				$upload[] = $uploadRow;
			}
		}
	}
	if (!empty($upload)) {
		Upload::rowsByPid($upload, $pid);
	}
}

function hasMatchWithActivityData($activityData, $activityPrefix, $honorName, $honorYear) {
	if (!$honorName) {
		return false;
	}
	foreach ($activityData as $row) {
		if (($row[$activityPrefix."_name"] == $honorName) && ($row[$activityPrefix."_award_year"] == $honorYear)) {
			return true;
		}
	}
	return false;
}

function uploadPositionChangesFromSurveys($pid, $recordId, $instrument, $requestedInstance = 1) {
	$prefix = REDCapManagement::getPrefixFromInstrument($instrument);
	if (!preg_match("/_$/", $prefix)) {
		$prefix = $prefix."_";
	}
	$positionPrefix = REDCapManagement::getPrefixFromInstrument("position_change");
	$metadataFields = Download::metadataFieldsByPid($pid);
	$relevantFields = DataDictionaryManagement::filterFieldsForPrefix($metadataFields, $prefix);
	$positionFields = DataDictionaryManagement::filterFieldsForPrefix($metadataFields, $positionPrefix);
	$metadata = Download::metadataByPid($pid, array_unique(array_merge($relevantFields, $positionFields)));
	$choices = DataDictionaryManagement::getChoices($metadata);
	$repeatingForms = REDCapManagement::getRepeatingForms($pid);
	if (empty($relevantFields)) {
		return;
	}
	$relevantFields[] = $instrument."_complete";
	$positionFields[] = "position_change_complete";
	$relevantFields[] = "record_id";
	$positionFields[] = "record_id";

	$redcapData = Download::fieldsForRecordsByPid($pid, $relevantFields, [$recordId]);
	$positionData = Download::fieldsForRecordsByPid($pid, $positionFields, [$recordId]);
	$maxPositionInstance = REDCapManagement::getMaxInstance($positionData, "position_change", $recordId);
	// Application::log("$recordId has maxInstance $maxInstance");

	$fieldPrefixes = [ preg_replace("/_$/", "", $prefix), $prefix."prev_1", $prefix."prev_2", $prefix."prev_3", $prefix."prev_4", $prefix."prev_5"];
	if (in_array($instrument, $repeatingForms)) {
		foreach ($redcapData as $row) {
			if (($row['redcap_repeat_instrument'] == $instrument) && ($row['redcap_repeat_instance'] == $requestedInstance)) {
				getDataForPrefixAndPossiblyUpload($pid, $row, $choices, $fieldPrefixes, $recordId, $positionData, $maxPositionInstance);
			}
		}
	} else {
		getDataForPrefixAndPossiblyUpload($pid, $redcapData[0], $choices, $fieldPrefixes, $recordId, $positionData, $maxPositionInstance);
	}
}

function getDataForPrefixAndPossiblyUpload($pid, $row, $choices, $prefixes, $recordId, $positionData, &$maxInstance) {
	foreach ($prefixes as $prefix) {
		$transferData = getPositionDataFromSurvey($row, $choices, $prefix);
		if (!empty($transferData) && !isDataAlreadyCopied($positionData, $transferData)) {
			$maxInstance++;
			$transferData['record_id'] = $recordId;
			$transferData['redcap_repeat_instrument'] = "position_change";
			$transferData['redcap_repeat_instance'] = $maxInstance;
			Upload::rowsByPid([$transferData], $pid);
		}
	}
}

function getPositionDataFromSurvey($row, $choices, $prefix) {
	if (!preg_match("/_$/", $prefix)) {
		$prefix .= "_";
	}

	$fields = ['job_title', 'job_category', 'institution', 'primary_dept', 'academic_rank'];
	$hasData = false;
	foreach ($fields as $field) {
		if (isset($row[$prefix.$field]) && $row[$prefix.$field]) {
			$hasData = true;
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
	} elseif ($lowercaseInstitutionName == "other") {
		$transferData['promotion_institution'] = $row[$prefix.'institution_oth'] ?? "";
	} else {
		$transferData['promotion_institution'] = $institutionName;
	}
	$department = $row[$prefix.'primary_dept'];
	if (isset($choices["promotion_department"][$department])) {
		$transferData['promotion_department'] = $department;
	} elseif (isset($choices["promotion_department"]['999999'])) {
		$transferData['promotion_department'] = '999999';
		$transferData['promotion_department_other'] = $choices[$prefix.'primary_dept'][$department];
	} else {
		$label = $choices[$prefix.'primary_dept'][$department] ?? $department;
		$transferData['promotion_department'] = $label;
		if (isset($choices["promotion_department"])) {
			foreach ($choices["promotion_department"] as $choiceIndex => $choiceLabel) {
				if ($choiceLabel == $label) {
					$transferData['promotion_department'] = $choiceIndex;
				}
			}
		}
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
	$hasDataAlready = false;
	foreach ($positionData as $row) {
		$allFieldsPresent = true;
		foreach ($transferData as $field => $value) {
			if (!in_array($field, $skip) && (trim($row[$field]) != trim($value))) {
				Application::log("For $field, ".$row[$field]." != ".$value);
				$allFieldsPresent = false;
				break;
			}
		}
		if ($allFieldsPresent) {
			$hasDataAlready = true;
			break;
		}
	}
	return $hasDataAlready;
}

function makeSocialMediaField($redcapField) {
	return str_replace("identifier_", "", $redcapField)."_handle";
}
