<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Measurement;
use \Vanderbilt\CareerDevLibrary\DateMeasurement;
use \Vanderbilt\CareerDevLibrary\MoneyMeasurement;
use \Vanderbilt\CareerDevLibrary\ObservedMeasurement;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/".\Vanderbilt\FlightTrackerExternalModule\getTarget().".php");

$headers = array();
array_push($headers, "Emails");
if ($_GET['cohort']) {
	array_push($headers, "For Cohort ".$_GET['cohort']);
} 

if ($_GET['cohort']) {
	$metadata = Download::metadata($token, $server);
	$records = Download::cohortRecordIds($token, $server, Application::getModule(), $_GET['cohort']);
} else {
    $records = Download::recordIds($token, $server);
    $metadata = [];
}
$redcapData = Download::fieldsForRecords($token, $server, array("record_id", "identifier_email", "followup_date", "followup_complete", "initial_survey_complete"), $records);

$maxFollowupInstance = 1;
$numFollowups = 0;
$numFollowupsInLastYear = 0;
$numScholars = 0;
$numEmailAddresses = 0;
foreach ($redcapData as $row) {
	if ($row['followup_complete'] !== "") {
		$numFollowups++;
		if ($row['redcap_repeat_instance'] > $maxFollowupInstance) {
			$maxFollowupInstance = $row['redcap_repeat_instance'];
		}
		$followupTs = strtotime($row['followup_date']);
		if ($followupTs > time() - 365 * 24 * 3600) {
			$numFollowupsInLastYear++;
		}
	}
	if (($row['initial_survey_complete'] !== "") && ($row['initial_survey_complete'] !== "0")) {
		$numScholars++;
	}
	if ($row['identifier_email'] !== "") {
		$numEmailAddresses++;
	}
}

$measurements = array();
$measurements["Initial Surveys Filled Out"] = new Measurement($numScholars, count($records));
$measurements["Follow-Up Surveys Filled Out"] = new Measurement($numFollowups, $maxFollowupInstance * count($records));
$measurements["Follow-Up Surveys Filled Out In Last Year"] = new Measurement($numFollowupsInLastYear, count($records));
$measurements["Email Addresses Entered"] = new Measurement($numEmailAddresses, count($records));

echo makeHTML($headers, $measurements, array(), $_GET['cohort'], $metadata);
