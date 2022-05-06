<?php

namespace Vanderbilt\CareerDevLibrary;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../../../redcap_connect.php");

function updateFederalRePORTER($token, $server, $pid, $records) {
    updateRePORTER("Federal", $token, $server, $pid, $records);
}

function updateNIHRePORTER($token, $server, $pid, $records) {
    updateRePORTER("NIH", $token, $server, $pid, $records);
}

function updateRePORTER($cat, $token, $server, $pid, $records) {
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
    } else if ($cat == "Federal") {
        $reporterFields = CareerDev::$reporterFields;
        $projectField = "projectnumber";
        $instrument = "reporter";
        $prefix = "reporter_";
        $applicationField = "reporter_smapplid";
    }

    $excludeList = Download::excludeList($token, $server, "exclude_grants", $metadata);
    $universalInstitutions = array_unique(array_merge(CareerDev::getInstitutions(), Application::getHelperInstitutions()));
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
	    $myInstitutions = Scholar::explodeInstitutions(REDCapManagement::findField($redcapData, $recordId, "identifier_institution"));
	    $institutions = array_unique(array_merge($universalInstitutions, $myInstitutions));
	    $reporter = new RePORTER($pid, $recordId, $cat, $excludeList[$recordId]);
	    $upload = [];
	    foreach ($lastNames as $lastName) {
	        foreach ($firstNames as $firstName) {
	            $name = "$firstName $lastName";
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
    REDCapManagement::deduplicateByKey($token, $server, $pid, $records, $applicationField, $prefix, $instrument);

	$today = date("Y-m-d");
	if (REDCapManagement::dateCompare($today, "<=", "2022-03-01")) {
        cleanUpMiddleNamesSeepage($token, $server, $pid, $records);
    }
    CareerDev::saveCurrentDate("Last $cat RePORTER Download", $pid);
}

function cleanUpMiddleNamesSeepage($token, $server, $pid, $records) {
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
    $defaultInstitutions = array_unique(array_merge(Application::getInstitutions(), Application::getHelperInstitutions()));
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

