<?php

use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\CronManager;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;

# used every time that the summaries are recalculated 
# 30 minute runtimes

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");
if (!defined("NOAUTH")) {
    define("NOAUTH", true);
}
require_once(dirname(__FILE__)."/../../../redcap_connect.php");

function getLockFile($pid) {
	return CareerDev::getLockFile($pid);
}

function lock($pid) {
	$lockFile = getLockFile($pid);

	$lockStart = time();
	if (file_exists($lockFile)) {
		throw new \Exception("Script is locked ".$lockFile);
	}
	$fp = fopen($lockFile, "w");
	fwrite($fp, date("Y-m-d h:m:s", $lockStart));
	fclose($fp);
}

function unlock($pid) {
	$lockFile = getLockFile($pid);
	# close the lockFile
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

function makeSummary($token, $server, $pid, $records, $runAllRecords = FALSE) {
    if (!is_array($records)) {
        $records = [$records];
        $runAllRecords = TRUE;
    }
    if ($runAllRecords) {
        $changedRecords = $records;
    } else {
        $changedRecords = (count($records) == 1) ? $records : CronManager::getChangedRecords($records, 96, $pid);
    }
    if (!empty($changedRecords)) {
        lock($pid);

        if (!$token || !$server) {
            throw new \Exception("6d makeSummary could not find token '$token' or server '$server'");
        }

        $GLOBALS['selectRecord'] = "";

        $metadata = Download::metadata($token, $server);

        # done in batches of 1 records
        foreach ($changedRecords as $recordId) {
            summarizeRecord($token, $server, $pid, $recordId, $metadata);
            gc_collect_cycles();
        }

        unlock($pid);
    }

	CareerDev::saveCurrentDate("Last Summary of Data", $pid);
}

function summarizeRecord($token, $server, $pid, $recordId, $metadata) {
    $errors = [];
    $returnREDCapData = [];

    $time1 = microtime(TRUE);
    $rows = Download::records($token, $server, array($recordId));
    $time2 = microtime(TRUE);
    // CareerDev::log("6d CareerDev downloading $recordId took ".($time2 - $time1));
    // echo "6d CareerDev downloading $recordId took ".($time2 - $time1)."\n";

    $time1 = microtime(TRUE);
    $grants = new Grants($token, $server, $metadata);
    $grants->setRows($rows);
    $grants->compileGrants();
    $result = $grants->uploadGrants();
    $myErrors = Upload::isolateErrors($result);
    $errors = array_merge($errors, $myErrors);
    $time2 = microtime(TRUE);
    // CareerDev::log("6d CareerDev processing grants $recordId took ".($time2 - $time1));
    // echo "6d CareerDev processing grants $recordId took ".($time2 - $time1)."\n";

    # update rows with new data
    $time1 = microtime(TRUE);
    $scholar = new Scholar($token, $server, $metadata, $pid);
    $scholar->setGrants($grants);   // save compute time
    $scholar->downloadAndSetup($recordId);
    $scholar->process();
    $result = $scholar->upload();
    $time2 = microtime(TRUE);
    // CareerDev::log("6d CareerDev processing scholar $recordId took ".($time2 - $time1));
    // echo "6d CareerDev processing scholar $recordId took ".($time2 - $time1)."\n";
    $myErrors = Upload::isolateErrors($result);
    $errors = array_merge($errors, $myErrors);

    $time1 = microtime(TRUE);
    $pubs = new Publications($token, $server, $metadata);
    $pubs->setRows($rows);
    $result = $pubs->uploadSummary();
    $time2 = microtime(TRUE);
    // CareerDev::log("6d CareerDev processing publications $recordId took ".($time2 - $time1));
    // echo "6d CareerDev processing publications $recordId took ".($time2 - $time1)."\n";
    $myErrors = Upload::isolateErrors($result);
    $errors = array_merge($errors, $myErrors);

    uploadPositionChangesFromSurveys($token, $server, $recordId, $metadata);

    if (!empty($errors)) {
        throw new \Exception("Errors in record $recordId!\n".implode("\n", $errors));
    }
    return $returnREDCapData;
}

function mergeNormativeRows($unmerged) {
	$newData = array();
	$normativeRows = array();
	$pk = "record_id";
	$repeatFields = array("redcap_repeat_instrument", "redcap_repeat_instance");
	foreach ($unmerged as $row) {
		if ($row['redcap_repeat_instrument'] != "") {
			array_push($newData, $row);
		} else {
			$recordId = FALSE;
			foreach ($row as $field => $value) {
				if ($field == $pk) {
					$recordId = $row[$pk]; 
				}
			}
			if (!$recordId) {
				throw new \Exception("Row does not have $pk: ".json_encode($row));
			}
			if (!isset($normativeRows[$recordId])) {
				$normativeRows[$recordId] = array();
				foreach ($repeatFields as $repeatField) {
					$normativeRows[$recordId][$repeatField] = "";
				}
			}

			foreach ($row as $field => $value) {
				if ($value && !in_array($field, $repeatFields)) {
					if (!isset($normativeRow[$field])) {
						$normativeRows[$recordId][$field] = $value;
					} else {
						throw new \Exception("$field is repeated with values in normativeRow!");
					}
				}
			}
		}
	}
	$newData = array_merge(array_values($normativeRows), $newData);
	return $newData;
}

function uploadPositionChangesFromSurveys($token, $server, $recordId, $metadata) {
    $choices = REDCapManagement::getChoices($metadata);
    $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
    $positionFields = [];
    $checkFields = [];
    $followupFields = [];
    $initImportFields = [];
    foreach ($metadataFields as $field) {
        if (preg_match("/^promotion_/", $field) || ($field == "position_change_complete")) {
            $positionFields[] = $field;
        } else if (preg_match("/^check_/", $field) || ($field == "initial_survey_complete")) {
            $checkFields[] = $field;
        } else if (preg_match("/^followup_/", $field) || ($field == "followup_complete")) {
            $followupFields[] = $field;
        } else if (preg_match("/^init_import_/", $field) || ($field == "initial_import_complete")) {
            $initImportFields[] = $field;
        }
    }
    if (empty($positionFields) || empty($checkFields) || empty($followupFields)) {
        return;
    }
    $positionFields[] = "record_id";
    $checkFields[] = "record_id";
    $followupFields[] = "record_id";
    $initImportFields[] = "record_id";

    $positionData = Download::fieldsForRecords($token, $server, $positionFields, [$recordId]);
    $checkData = Download::fieldsForRecords($token, $server, $checkFields, [$recordId]);
    $followupData = Download::fieldsForRecords($token, $server, $followupFields, [$recordId]);
    $initImportData = Download::fieldsForRecords($token, $server, $initImportFields, [$recordId]);

    $maxInstance = REDCapManagement::getMaxInstance($positionData, "position_change", $recordId);
    // Application::log("$recordId has maxInstance $maxInstance");

    $initialSurveyPrefixes = ["check", "check_prev1", "check_prev2", "check_prev3", "check_prev4", "check_prev5"];
    $initImportPrefixes = $initialSurveyPrefixes;
    for ($i = 0; $i < count($initImportPrefixes); $i++) {
        $initImportPrefixes[$i] = preg_replace("/^check/", "init_import", $initImportPrefixes[$i]);
    }
    getDataForPrefixAndPossiblyUpload($token, $server, $checkData[0], $choices, $initialSurveyPrefixes, $recordId, $positionData, $maxInstance);
    getDataForPrefixAndPossiblyUpload($token, $server, $initImportData[0], $choices, $initImportPrefixes, $recordId, $positionData, $maxInstance);

    $followupSurveyPrefixes = ["followup", "followup_prev1", "followup_prev2", "followup_prev3", "followup_prev4", "followup_prev5"];
    foreach ($followupData as $row) {
        if ($row['redcap_repeat_instrument'] == "followup") {
            getDataForPrefixAndPossiblyUpload($token, $server, $row, $choices, $followupSurveyPrefixes, $recordId, $positionData, $maxInstance);
        }
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

