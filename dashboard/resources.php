<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Measurement;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Dashboard;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
$dashboard = new Dashboard($pid);
require_once(dirname(__FILE__)."/".$dashboard->getTarget().".php");

$headers = [];
$headers[] = "Resources";
if (isset($_GET['cohort'])) {
    $cohort = Sanitizer::sanitizeCohort($_GET['cohort']);
    $headers[] = "For Cohort " . $cohort;
} else {
    $cohort = "";
}

if ($cohort) {
	$records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else {
    $records = Download::recordIds($token, $server);
}

$resourceField = "resources_resource";
$redcapData = Download::fieldsForRecords($token, $server, ["record_id", $resourceField], $records);
$resourceChoices = DataDictionaryManagement::getChoicesForField($pid, $resourceField);

$counts = [];
foreach ($resourceChoices as $value => $label) {
	if (!isset($counts[$value])) {
		$counts[$value] = 0;
	}
	foreach ($redcapData as $row) {
		if ($row[$resourceField] == $value) {
			$counts[$value]++;
		}
	}
}

$measurements = [];
foreach ($resourceChoices as $value => $label) {
	$measurements["Number Attended (".$label.")"] = new Measurement($counts[$value], count($records));
}

echo $dashboard->makeHTML($headers, $measurements, [], $cohort);
