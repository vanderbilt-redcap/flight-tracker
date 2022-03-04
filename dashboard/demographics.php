<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Measurement;
use \Vanderbilt\CareerDevLibrary\DateMeasurement;
use \Vanderbilt\CareerDevLibrary\MoneyMeasurement;
use \Vanderbilt\CareerDevLibrary\ObservedMeasurement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/".\Vanderbilt\FlightTrackerExternalModule\getTarget().".php");

if (isset($_GET['cohort'])) {
    $cohort = REDCapManagement::sanitizeCohort($_GET['cohort']);
} else {
    $cohort = "";
}
$metadata = Download::metadata($token, $server);
$indexedRedcapData = \Vanderbilt\FlightTrackerExternalModule\getIndexedRedcapData($token, $server, CareerDev::$summaryFields, $cohort, $metadata);
$choices = \Vanderbilt\FlightTrackerExternalModule\getChoices($metadata);

$headers = array();
$measurements = array();

array_push($headers, "Demographics");
if ($cohort) {
    array_push($headers, "For Cohort ".$cohort);
}

$variables = array(
			"Graduate Degrees" => "summary_degrees",
			"Gender" => "summary_gender",
			"Race/Ethnicity" => "summary_race_ethnicity",
			"Primary Department" => "summary_primary_dept",
			"Date of Birth" => "summary_dob",
			"Citizenship" => "summary_citizenship",
			"Academic Rank" => "summary_current_rank",
			);

$totals = array();
foreach ($indexedRedcapData as $recordId => $rows) {
	foreach ($variables as $label => $var) {
		foreach ($rows as $row) {
			if ($row[$var]) {
				if (!isset($totals[$var])) {
					$totals[$var] = 0;
				}
				$totals[$var]++;
			}
		}
	}
}

foreach ($variables as $label => $var) {
	$measurements["$label Fields Reported"] = new Measurement($totals[$var] ?? 0, count($indexedRedcapData));
}

echo makeHTML($headers, $measurements, array(), $cohort, $metadata);
