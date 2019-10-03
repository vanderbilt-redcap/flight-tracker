<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\FlightTrackerExternalModule\Measurement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/".\Vanderbilt\FlightTrackerExternalModule\getTarget().".php");
require_once(dirname(__FILE__)."/../classes/Download.php");


$metadata = Download::metadata($token, $server);
$indexedRedcapData = \Vanderbilt\FlightTrackerExternalModule\getIndexedRedcapData($token, $server, CareerDev::$summaryFields, $_GET['cohort'], $metadata);
$choices = \Vanderbilt\FlightTrackerExternalModule\getChoices($metadata);

$headers = array();
$measurements = array();

array_push($headers, "Demographics");
if ($_GET['cohort']) {
	array_push($headers, "For Cohort ".$_GET['cohort']);
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
	$measurements["$label Fields Reported"] = new Measurement($totals[$var], count($indexedRedcapData));
}

echo makeHTML($headers, $measurements, array(), $_GET['cohort'], $metadata);
