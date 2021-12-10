<?php

use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\CronManager;

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

# $allRecordRows is for testing purposes only
function makeSummary($token, $server, $pid, $records, $allRecordRows = array()) {
    if (!is_array($records)) {
        $records = [$records];
    }
    $changedRecords = (count($records) == 1) ? $records : CronManager::getChangedRecords($records, 96, $pid);
    if (!empty($changedRecords)) {
        lock($pid);

        if (!$token || !$server) {
            throw new \Exception("6d makeSummary could not find token '$token' or server '$server'");
        }

        $GLOBALS['selectRecord'] = "";

        $metadata = Download::metadata($token, $server);

        # done in batches of 1 records
        $returnREDCapData = array();
        foreach ($changedRecords as $recordId) {
            $newData = summarizeRecord($token, $server, $pid, $recordId, $metadata, $allRecordRows);
            $returnREDCapData = array_merge($returnREDCapData, $newData);
            gc_collect_cycles();
        }

        unlock($pid);
    }

	CareerDev::saveCurrentDate("Last Summary of Data", $pid);
	if (!empty($allRecordRows)) {
		return mergeNormativeRows($returnREDCapData);
	}
}

function summarizeRecord($token, $server, $pid, $recordId, $metadata, $allRecordRows) {
    $errors = [];
    $returnREDCapData = [];

    $time1 = microtime(TRUE);
    $rows = array();
    $realRecord = FALSE;
    foreach ($allRecordRows as $row) {
        if ($row['record_id'] == $recordId) {
            array_push($rows, $row);
        }
    }
    if (empty($rows)) {
        $rows = Download::records($token, $server, array($recordId));
        $realRecord = TRUE;
    }
    $time2 = microtime(TRUE);
    // CareerDev::log("6d CareerDev downloading $recordId took ".($time2 - $time1));
    // echo "6d CareerDev downloading $recordId took ".($time2 - $time1)."\n";

    $time1 = microtime(TRUE);
    $grants = new Grants($token, $server, $metadata);
    $grants->setRows($rows);
    $grants->compileGrants();
    if ($realRecord) {
        $result = $grants->uploadGrants();
        $myErrors = Upload::isolateErrors($result);
        $errors = array_merge($errors, $myErrors);
    } else {
        $row = $grants->makeUploadRow();
        array_push($returnREDCapData, $row);
    }
    $time2 = microtime(TRUE);
    // CareerDev::log("6d CareerDev processing grants $recordId took ".($time2 - $time1));
    // echo "6d CareerDev processing grants $recordId took ".($time2 - $time1)."\n";

    # update rows with new data
    $time1 = microtime(TRUE);
    $scholar = new Scholar($token, $server, $metadata, $pid);
    $scholar->setGrants($grants);   // save compute time
    if ($realRecord) {
        $scholar->downloadAndSetup($recordId);
    } else {
        $scholar->setRows($rows);
    }
    $scholar->process();
    if ($realRecord) {
        $result = $scholar->upload();
    } else {
        $row = $scholar->makeUploadRow();
        array_push($returnREDCapData, $row);
    }
    $time2 = microtime(TRUE);
    // CareerDev::log("6d CareerDev processing scholar $recordId took ".($time2 - $time1));
    // echo "6d CareerDev processing scholar $recordId took ".($time2 - $time1)."\n";
    $myErrors = Upload::isolateErrors($result);
    $errors = array_merge($errors, $myErrors);

    $time1 = microtime(TRUE);
    $pubs = new Publications($token, $server, $metadata);
    $pubs->setRows($rows);
    if ($realRecord) {
        $result = $pubs->uploadSummary();
    }
    $time2 = microtime(TRUE);
    // CareerDev::log("6d CareerDev processing publications $recordId took ".($time2 - $time1));
    // echo "6d CareerDev processing publications $recordId took ".($time2 - $time1)."\n";
    $myErrors = Upload::isolateErrors($result);
    $errors = array_merge($errors, $myErrors);

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
