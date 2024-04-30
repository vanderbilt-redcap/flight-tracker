<?php

namespace Vanderbilt\CareerDevLibrary;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

# used every time that the summaries are recalculated 
# 30 minute runtimes

require_once(__DIR__."/../classes/Autoload.php");
require_once(__DIR__."/../small_base.php");
require_once(__DIR__."/updateInstitution.php");

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
    updateInstitution($token, $server, $pid, $records);
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

        REDCapManagement::cleanUpOldLogs($pid);

        unlock($pid);
    }

	CareerDev::saveCurrentDate("Last Summary of Data", $pid);
}

function summarizeRecord($token, $server, $pid, $recordId, $metadata) {
    $errors = [];
    $returnREDCapData = [];
    $forms = DataDictionaryManagement::getFormsFromMetadata($metadata);

    $time1 = microtime(TRUE);
    $rows = Download::records($token, $server, [$recordId]);
    $time2 = microtime(TRUE);
    if (Application::isVanderbilt()) {
        Application::log("6d CareerDev downloading $recordId took ".REDCapManagement::pretty($time2 - $time1, 3), $pid);
    }

    $time1 = microtime(TRUE);
    $grants = new Grants($token, $server, $metadata);
    $grants->setRows($rows);
    $grants->compileGrants();
    $result = $grants->uploadGrants();
    $myErrors = Upload::isolateErrors($result);
    $errors = array_merge($errors, $myErrors);
    $time2 = microtime(TRUE);
    if (Application::isVanderbilt()) {
        Application::log("6d CareerDev processing grants $recordId took ".REDCapManagement::pretty($time2 - $time1, 3), $pid);
    }

    # update rows with new data
    $time1 = microtime(TRUE);
    $scholar = new Scholar($token, $server, $metadata, $pid);
    $scholar->setGrants($grants);   // save compute time
    $scholar->downloadAndSetup($recordId);
    $scholar->process();
    $result = $scholar->upload();
    $scholar->updatePositionChangeForms();
    $time2 = microtime(TRUE);
    if (Application::isVanderbilt()) {
        Application::log("6d CareerDev processing scholar $recordId took ".REDCapManagement::pretty($time2 - $time1, 3), $pid);
    }

    $myErrors = Upload::isolateErrors($result);
    $errors = array_merge($errors, $myErrors);

    if (in_array("citation", $forms)) {
        $time1 = microtime(TRUE);
        $pubs = new Publications($token, $server, $metadata);
        $pubs->setRows($rows);
        $result = $pubs->uploadSummary();
        $time2 = microtime(TRUE);
        if (Application::isVanderbilt()) {
            Application::log("6d CareerDev processing publications $recordId took ".REDCapManagement::pretty($time2 - $time1, 3), $pid);
        }
        $myErrors = Upload::isolateErrors($result);
        $errors = array_merge($errors, $myErrors);
    }

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
