<?php

use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

# used every time that the summaries are recalculated 
# 30 minute runtimes

require_once(dirname(__FILE__)."/../classes/Scholar.php");
require_once(dirname(__FILE__)."/../classes/Grants.php");
require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
define("NOAUTH", true);
require_once(dirname(__FILE__)."/../../../redcap_connect.php");

function getLockFile() {
	global $pid;
	return APP_PATH_TEMP."6_makeSummary.$pid.lock";
}

function lock() {
	$lockFile = getLockFile();

	$lockStart = time();
	if (file_exists($lockFile)) {
		throw new \Exception("Script is locked ".$lockFile);
	}
	$fp = fopen($lockFile, "w");
	fwrite($fp, date("Y-m-d h:m:s"));
	fclose($fp);
}

function unlock() {
	$lockFile = getLockFile();
	# close the lockFile
	unlink($lockFile);
}

function makeSummary($token, $server, $pid, $selectRecord = "") {
	lock();

	$GLOBALS['selectRecord'] = "";

	$metadata = Download::metadata($token, $server);
	error_log("6d CareerDev downloaded metadata: ".count($metadata));

	# done in batches of 1 records
	if (!$selectRecord) {
		$records = Download::recordIds($token, $server);
	} else {
		$records = array($selectRecord);
	}
	$errors = array();
	foreach ($records as $recordId) {
		$time1 = microtime(TRUE);
		$rows = Download::records($token, $server, array($recordId));
		$time2 = microtime(TRUE);
		// error_log("6d CareerDev downloading $recordId took ".($time2 - $time1));
		// echo "6d CareerDev downloading $recordId took ".($time2 - $time1)."\n";
	
		$time1 = microtime(TRUE);
		$grants = new Grants($token, $server, $metadata);
		$grants->setRows($rows);
		$grants->compileGrants();
		$result = $grants->uploadGrants();
		$time2 = microtime(TRUE);
		// error_log("6d CareerDev processing grants $recordId took ".($time2 - $time1));
		// echo "6d CareerDev processing grants $recordId took ".($time2 - $time1)."\n";
		$myErrors = Upload::isolateErrors($result);
		$errors = array_merge($errors, $myErrors);
	
		# update rows with new data
		$time1 = microtime(TRUE);
		$scholar = new Scholar($token, $server, $metadata, $pid);
		$scholar->downloadAndSetup($recordId);
		$scholar->setGrants($grants);   // save compute time
		$scholar->process();
		$result = $scholar->upload();
		$time2 = microtime(TRUE);
		// error_log("6d CareerDev processing scholar $recordId took ".($time2 - $time1));
		// echo "6d CareerDev processing scholar $recordId took ".($time2 - $time1)."\n";
		$myErrors = Upload::isolateErrors($result);
		$errors = array_merge($errors, $myErrors);

		$time1 = microtime(TRUE);
		$pubs = new Publications($token, $server, $metadata);
		$pubs->setRows($rows);
		$result = $pubs->uploadSummary();
		$time2 = microtime(TRUE);
		// error_log("6d CareerDev processing publications $recordId took ".($time2 - $time1));
		// echo "6d CareerDev processing publications $recordId took ".($time2 - $time1)."\n";
		$myErrors = Upload::isolateErrors($result);
		$errors = array_merge($errors, $myErrors);

		if (!empty($errors)) {
			throw new Exception("Errors in record $recordId!\n".implode("\n", $errors));
		}
	}

	unlock();

	CareerDev::saveCurrentDate("Last Summary of Data");
}
