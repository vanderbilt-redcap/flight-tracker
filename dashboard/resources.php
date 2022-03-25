<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Measurement;
use \Vanderbilt\CareerDevLibrary\DateMeasurement;
use \Vanderbilt\CareerDevLibrary\MoneyMeasurement;
use \Vanderbilt\CareerDevLibrary\ObservedMeasurement;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/".\Vanderbilt\FlightTrackerExternalModule\getTarget().".php");

$headers = array();
array_push($headers, "Resources");
if (isset($_GET['cohort'])) {
    $cohort = REDCapManagement::sanitizeCohort($_GET['cohort']);
    array_push($headers, "For Cohort ".$cohort);
} else {
    $cohort = "";
}

$metadata = Download::metadata($token, $server);
if ($cohort) {
	$records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else {
    $records = Download::recordIds($token, $server);
}

$resourceField = "resources_resource";
$redcapData = Download::fieldsForRecords($token, $server, array("record_id", $resourceField), $records);
$choices = \Vanderbilt\FlightTrackerExternalModule\getChoices($metadata);

$counts = array();
foreach ($choices[$resourceField] as $value => $label) {
	if (!isset($counts[$value])) {
		$counts[$value] = 0;
	}
	foreach ($redcapData as $row) {
		if ($row[$resourceField] == $value) {
			$counts[$value]++;
		}
	}
}

$measurements = array();
foreach ($choices[$resourceField] as $value => $label) {
	$measurements["Number Attended (".$label.")"] = new Measurement($counts[$value], count($records));
}

echo makeHTML($headers, $measurements, array(), $cohort, $metadata);
