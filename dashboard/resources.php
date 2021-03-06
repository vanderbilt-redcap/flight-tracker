<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\FlightTrackerExternalModule\Measurement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/".\Vanderbilt\FlightTrackerExternalModule\getTarget().".php");
require_once(dirname(__FILE__)."/../classes/Download.php");

$headers = array();
array_push($headers, "Resources");
if ($_GET['cohort']) {
	array_push($headers, "For Cohort ".$_GET['cohort']);
} 

$metadata = Download::metadata($token, $server);
if ($_GET['cohort']) {
	$records = Download::cohortRecordIds($token, $server, $metadata, $_GET['cohort']);
}
if (!$records) {
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

echo makeHTML($headers, $measurements, array(), $_GET['cohort'], $metadata);
