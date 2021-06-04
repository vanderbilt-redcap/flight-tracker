<?php

# used every time that the summaries are recalculated 
# 15-30 minute runtimes

$sendEmail = false;
$screen = true;
$br = "\n";
if (isset($_GET['pid']) || (isset($argv[1]) && ($argv[1] == "prod_cron"))) {
	$br = "<br>";
	$sendEmail = true;
	$screen = false;
}

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");
define("NOAUTH", true);
require_once(dirname(__FILE__)."/../../../redcap_connect.php");
$lockFile = APP_PATH_TEMP."6_makeSummary.lock";
$lockStart = time();
if (file_exists($lockFile)) {
	$mssg = "Another instance is already running.";
	\REDCap::email($victrEmail, "no-reply@vanderbilt.edu", "CareerDev makeSummary script die", $mssg);
	die($mssg);
}
$fp = fopen($lockFile, "w");
fwrite($fp, date("Y-m-d h:m:s"));
fclose($fp);

# if this is specified as the 2nd command-line argument, it will only run for one record
$selectRecord = "";
if (isset($argv[2])) {
	$selectRecord = $argv[2];
}
$GLOBALS['selectRecord'] = $selectRecord;

error_log("SERVER: ".$server);
error_log("TOKEN: ".$token);
error_log("PID: ".$pid);
if ($selectRecord) {
	error_log("RECORD: ".$selectRecord);
}

if (!$selectRecord) {
	$metadata = Download::metadata($token, $server);
	error_log("6c CareerDev downloaded metadata: ".count($metadata));
}

# done in batches of 10 records
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
	error_log("6c CareerDev downloading $recordId took ".($time2 - $time1));

	$time1 = microtime(TRUE);
	$grants = new Grants($token, $server, $metadata);
	$grants->setRows($rows);
	$grants->compileGrants();
	$result = $grants->uploadGrants();
	$time2 = microtime(TRUE);
	error_log("6c CareerDev processing grants $recordId took ".($time2 - $time1));
	$myErrors = Upload::isolateErrors($result);
	$errors = array_merge($errors, $myErrors);

	$time1 = microtime(TRUE);
	$summaryGrants = new SummaryGrants($token, $server);
	$summaryGrants->setGrants($recordId, $grants, $metadata);
	$summaryGrants->process();
	$result = $summaryGrants->upload();
	$time2 = microtime(TRUE);
	error_log("6c CareerDev summarizing grants $recordId took ".($time2 - $time1));
	$myErrors = Upload::isolateErrors($result);
	$errors = array_merge($errors, $myErrors);

	# update rows with new data
	$time1 = microtime(TRUE);
	$scholar = new Scholar($token, $server);
	$scholar->downloadAndSetup($recordId);
	$scholar->setGrants($grants);   // save compute time
	$scholar->process();
	$result = $scholar->upload();
	$time2 = microtime(TRUE);
	error_log("6c CareerDev processing scholar $recordId took ".($time2 - $time1));
	$myErrors = Upload::isolateErrors($result);
	$errors = array_merge($errors, $myErrors);
}

$successMessage = "";
if ($screen) {
	if (empty($errors)) {
		$successMessage = "SUCCESS$br$br";
	} else {
		$successMessage = "ERRORS$br$br<ul>";
		foreach ($errors as $error) {
			$successMessage .= "<li>$error";
		}
		$successMessage .= "</ul>";
	}
	echo $successMesssage;
 	echo $echoToScreen;
} else {
	echo $successMesssage;
}
if ($sendEmail) {
	\REDCap::email($victrEmail, "no-reply@vanderbilt.edu", "CareerDev makeSummary script run", $successMessage.$br.$br.$echoToScreen);
}

# close the lockFile
unlink($lockFile);

CareerDev::saveCurrentDate("Last Email Blast", $pid);
