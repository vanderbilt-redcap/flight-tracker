<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\RePORTER;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/NameMatcher.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/../classes/RePORTER.php");
require_once(dirname(__FILE__)."/../classes/Scholar.php");
require_once(dirname(__FILE__)."/../../../redcap_connect.php");

/**
 * Encode array from latin1 to utf8 recursively
 * @param $dat
 * @return array|string
 */
function convert_from_latin1_to_utf8_recursively($dat)
{
	if (is_string($dat)) {
		return utf8_encode($dat);
	} elseif (is_array($dat)) {
		$ret = [];
		foreach ($dat as $i => $d) $ret[ $i ] = convert_from_latin1_to_utf8_recursively($d);

		return $ret;
	} elseif (is_object($dat)) {
		foreach ($dat as $i => $d) $dat->$i = convert_from_latin1_to_utf8_recursively($d);

		return $dat;
	} else {
		return $dat;
	}
}

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
	    $firstNames = NameMatcher::explodeFirstName($allFirstNames[$recordId]);
	    $myInstitutions = Scholar::explodeInstitutions(REDCapManagement::findField($redcapData, $recordId, "identifier_institution"));
	    $institutions = array_unique(array_merge($universalInstitutions, $myInstitutions));
	    $reporter = new RePORTER($pid, $recordId, $cat);
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
                $row[$field] = convert_from_latin1_to_utf8_recursively($value);
            }
            $upload[] = REDCapManagement::filterForREDCap($row, $metadataFields);
        }
	    if (!empty($upload)) {
	        Application::log("Uploading ". count($upload)." rows from $cat RePORTER for Record $recordId", $pid);
	        Upload::rows($upload, $token, $server);
        }
    }
    REDCapManagement::deduplicateByKey($token, $server, $pid, $records, $applicationField, $prefix, $instrument);

    CareerDev::saveCurrentDate("Last $cat RePORTER Download", $pid);
}

function getSuffix($file) {
    $nodes = preg_split("/\./", $file);
    return $nodes[count($nodes) - 1];
}

function searchForFileWithTimestamp($filename) {
    $suffix = getSuffix($filename);
    $fileWithoutSuffix = preg_replace("/\.$suffix$/", "", $filename);
    $fileRegex = "/$fileWithoutSuffix/";
    $dir = APP_PATH_TEMP;
    $files = REDCapManagement::regexInDirectory($fileRegex, $dir);
    $foundFiles = [];
    foreach ($files as $file) {
        $fileSuffix = getSuffix($file);
        if ($suffix == $fileSuffix) {
            $foundFiles[] = $file;
        }
    }
    $oneHour = 3600;
    $minTimestamp = date("YmdHis", time() + $oneHour);
    if (count($foundFiles) == 1) {
        $file = $foundFiles[0];
        $ts = REDCapManagement::getTimestampOfFile($file);
        if ($ts > $minTimestamp) {
            return $dir.$foundFiles[0];
        }
    } else if (count($foundFiles) > 1) {
        $maxTimestamp = 0;
        $maxFile = "";
        foreach ($foundFiles as $file) {
            $ts = REDCapManagement::getTimestampOfFile($file);
            if (($ts >= $maxTimestamp) && ($ts > $minTimestamp)) {
                $maxTimestamp = $ts;
                $maxFile = $file;
            }
        }
        if ($maxFile) {
            return $dir.$maxFile;
        }
    }
    return FALSE;
}

function readAbstracts($file) {
    $data = [];
    if (file_exists($file)) {
        $fp = fopen($file, "r");
        $headers = fgetcsv($fp);
        while ($line = fgetcsv($fp)) {
            $appId = $line[0];
            $abstract = $line[1];
            if ($appId && $abstract) {
                $data[$appId] = $abstract;
            }
        }
        fclose($fp);
    }
    return $data;
}

function uploadBlankAbstracts($token, $server, $records, $abstractFiles) {
    $blankApplicationIds = [];
    foreach ($records as $recordId) {
        Application::log("Searching for blank abstracts for Record $recordId");
        $fields = ["record_id", "exporter_application_id", "exporter_abstract"];
        $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
        foreach ($redcapData as $row) {
            if ($row['redcap_repeat_instrument'] == "exporter") {
                if ($row['exporter_abstract'] == "") {
                    if (!isset($blankApplicationIds[$row['record_id']])) {
                        $blankApplicationIds[$row['record_id']] = [];
                    }
                    $blankApplicationIds[$row['record_id']][$row['redcap_repeat_instance']] = $row['exporter_application_id'];
                }
            }
        }
    }

    $upload = [];
    $processedItems = [];
    foreach ($abstractFiles as $dataFile => $abstractFile) {
        Application::log("Reading $abstractFile");
        $fp = fopen($abstractFile, "r");
        while ($line = fgetcsv($fp)) {
            foreach ($blankApplicationIds as $recordId => $applicationIds) {
                foreach ($applicationIds as $instance => $applicationId) {
                    if ($line[1] && ($line[0] == $applicationId) && !in_array("$recordId:$instance", $processedItems)) {
                        $upload[] = [
                            "record_id" => $recordId,
                            "redcap_repeat_instrument" => "exporter",
                            "redcap_repeat_instance" => $instance,
                            "exporter_abstract" => $line[1],
                        ];
                        $processedItems[] = "$recordId:$instance";
                    }
                }
            }
        }
        fclose($fp);
    }
    if (!empty($upload)) {
        Application::log("Uploading ".count($upload)." lines");
        Upload::rows($upload, $token, $server);
    }
}
