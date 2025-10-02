<?php

namespace Vanderbilt\CareerDevLibrary;

use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../../../redcap_connect.php");

function updateFederalRePORTER($token, $server, $pid, $records) {
	updateRePORTER("Federal", $token, $server, $pid, $records, false);
}

function updateNIHRePORTER($token, $server, $pid, $records) {
	if (!Application::getSetting("update_nih_reporter_v2", $pid)) {
		backfillVersion2NIHFields($pid, $records);
		Application::saveSetting("update_nih_reporter_v2", "1", $pid);
	}
	updateRePORTER("NIH", $token, $server, $pid, $records, false);
}

function updateNIHRePORTERByName($token, $server, $pid, $records) {
	updateRePORTER("NIH", $token, $server, $pid, $records, true);
}

function updateRePORTER($cat, $token, $server, $pid, $records, $searchWithoutInstitutions) {
	$metadata = Download::metadata($token, $server);
	$metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
	$allFirstNames = Download::firstnames($token, $server);
	$allLastNames = Download::lastnames($token, $server);
	$allMiddleNames = Download::middlenames($token, $server);

	if ($cat == "NIH") {
		$reporterFields = CareerDev::$nihreporterFields;
		$projectField = "project_num";
		$instrument = "nih_reporter";
		$prefix = "nih_";
		$applicationField = "nih_appl_id";
	} elseif ($cat == "Federal") {
		$reporterFields = CareerDev::$reporterFields;
		$projectField = "projectnumber";
		$instrument = "reporter";
		$prefix = "reporter_";
		$applicationField = "reporter_smapplid";
	}

	$excludeList = Download::excludeList($token, $server, "exclude_grants", $metadataFields);
	$universalInstitutions = array_unique(array_merge(CareerDev::getInstitutions($pid), Application::getHelperInstitutions($pid)));
	foreach ($records as $recordId) {
		$redcapData = Download::fieldsForRecords($token, $server, array_unique(array_merge(Application::getCustomFields($metadata), $reporterFields, ["identifier_institution"])), [$recordId]);
		$existingGrants = [];
		$maxInstance = 0;
		foreach ($redcapData as $row) {
			if ($row['redcap_repeat_instrument'] == $instrument) {
				$existingGrants[] = $row[$prefix.$projectField];
				$instance = $row['redcap_repeat_instance'];
				if ($instance > $maxInstance) {
					$maxInstance = $instance;
				}
			}
		}

		$lastNames = NameMatcher::explodeLastName($allLastNames[$recordId]);
		$firstNames = NameMatcher::explodeFirstName($allFirstNames[$recordId], $allMiddleNames[$recordId]);
		if ($searchWithoutInstitutions) {
			$institutions = ["all"];
		} else {
			$myInstitutions = Scholar::explodeInstitutions(REDCapManagement::findField($redcapData, $recordId, "identifier_institution"));
			$institutions = array_unique(array_merge($universalInstitutions, $myInstitutions));
		}
		$reporter = new RePORTER($pid, $recordId, $cat, $excludeList[$recordId]);
		$upload = [];
		foreach ($lastNames as $lastName) {
			foreach ($firstNames as $firstName) {
				$name = NameMatcher::formatName($firstName, "", $lastName);
				$reporter->searchPIAndAddToList($name, $institutions);
			}
		}
		$reporter->deduplicateData();
		$rows = $reporter->getUploadRows($maxInstance, $existingGrants);
		foreach ($rows as $row) {
			foreach ($row as $field => $value) {
				$row[$field] = REDCapmanagement::convert_from_latin1_to_utf8_recursively($value);
			}
			$upload[] = REDCapManagement::filterForREDCap($row, $metadataFields);
		}
		if (!empty($upload)) {
			Application::log("Uploading ". count($upload)." rows from $cat RePORTER for Record $recordId", $pid);
			Upload::rows($upload, $token, $server);
		}
	}
	RePORTER::updateEndDates($token, $server, $pid, $records, $prefix, $instrument);
	REDCapManagement::deduplicateByKey($token, $server, $pid, $records, $applicationField, $prefix, $instrument);

	$today = date("Y-m-d");
	if (REDCapManagement::dateCompare($today, "<=", "2022-03-01")) {
		cleanUpMiddleNamesSeepage_2s($token, $server, $pid, $records);
	}
	CareerDev::saveCurrentDate("Last $cat RePORTER Download", $pid);
}

function backfillVersion2NIHFields($pid, $records) {
	$newFields = [
		"nih_opportunity_number",
		"nih_organization",
		"nih_arra_funded",
		"nih_budget_start",
		"nih_budget_end",
		"nih_cfda_code",
		"nih_funding_mechanism",
		"nih_direct_cost_amt",
		"nih_indirect_cost_amt",
		"nih_project_detail_url",
		"nih_date_added",
	];
	$projectField = "project_num";
	$instrument = "nih_reporter";
	$prefix = "nih_";
	$testField = "nih_opportunity_number";
	foreach ($records as $recordId) {
		$fields = ["record_id", $prefix.$projectField, $testField];
		$redcapData = Download::fieldsForRecordsByPid($pid, $fields, [$recordId]);
		$awardsToFill = [];
		foreach ($redcapData as $row) {
			if (
				($row["redcap_repeat_instrument"] == $instrument)
				&& !$row[$testField]
				&& ($row[$prefix.$projectField])
			) {
				$awardsToFill[$row[$prefix.$projectField]] = $row['redcap_repeat_instance'];
			}
		}
		$upload = [];
		$reporter = new RePORTER($pid, $recordId, "NIH");
		$reporter->searchAwards(array_keys($awardsToFill));
		$unnecessaryMaxInstance = 0;
		$unnecessaryExistingGrants = [];
		$rows = $reporter->getUploadRows($unnecessaryMaxInstance, $unnecessaryExistingGrants);
		foreach ($rows as $row) {
			foreach ($row as $field => $value) {
				$row[$field] = REDCapmanagement::convert_from_latin1_to_utf8_recursively($value);
			}
			if (isset($awardsToFill[$row[$prefix.$projectField]])) {
				$row['redcap_repeat_instance'] = $awardsToFill[$row[$prefix.$projectField]];
				$upload[] = REDCapManagement::filterForREDCap($row, $newFields);
			}
		}
		if (!empty($upload)) {
			Application::log("Backfilling old NIH RePORTER Data for record $recordId: ".count($upload)." rows", $pid);
			Upload::rowsByPid($upload, $pid);
		}
	}
}

function cleanUpMiddleNamesSeepage_2s($token, $server, $pid, $records) {
	if (empty($records)) {
		$records = Download::recordIds($token, $server);
	}

	$cleaningField = "cleaned_by_middle_name_grants";
	$recordsCleanedByMiddleName = Application::getSetting($cleaningField, $pid);
	if (!is_array($recordsCleanedByMiddleName)) {
		$recordsCleanedByMiddleName = [];
	}
	if (count(array_unique(array_merge($records, $recordsCleanedByMiddleName))) == count($recordsCleanedByMiddleName)) {
		# nothing new
		return;
	}

	$firstNames = Download::firstnames($token, $server);
	$lastNames = Download::lastnames($token, $server);
	$middleNames = Download::middlenames($token, $server);
	$allInstitutions = Download::institutions($token, $server);
	$defaultInstitutions = array_unique(array_merge(Application::getInstitutions($pid), Application::getHelperInstitutions($pid)));
	$metadata = Download::metadata($token, $server);

	foreach (RePORTER::getTypes() as $type) {
		foreach ($records as $recordId) {
			if (
				$middleNames[$recordId]
				&& NameMatcher::isInitial($middleNames[$recordId])
				&& !in_array($recordId, $recordsCleanedByMiddleName)
			) {
				$reporter = new RePORTER($pid, $recordId, $type);
				$fields = REDCapManagement::getFieldsFromMetadata($metadata, $reporter->getInstrument());
				if (!in_array("record_id", $fields)) {
					$fields[] = "record_id";
				}
				$redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
				$institutions = isset($allInstitutions[$recordId]) ? Scholar::explodeInstitutions($allInstitutions[$recordId]) : [];
				$institutions = array_unique(array_merge($institutions, $defaultInstitutions));
				$reporter->deleteMiddleNameOnlyMatches($redcapData, $firstNames[$recordId], $lastNames[$recordId], $middleNames[$recordId], $institutions);
			}
		}
	}
	$recordsCleanedByMiddleName = array_unique(array_merge($recordsCleanedByMiddleName, $records));
	Application::saveSetting($cleaningField, $recordsCleanedByMiddleName, $pid);
}
