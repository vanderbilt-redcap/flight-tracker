<?php

use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Measurement;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;
use Vanderbilt\CareerDevLibrary\Dashboard;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
$dashboard = new Dashboard($pid);
require_once(dirname(__FILE__)."/".$dashboard->getTarget().".php");

if (isset($_GET['cohort'])) {
	$cohort = Sanitizer::sanitizeCohort($_GET['cohort']);
} else {
	$cohort = "";
}
$indexedRedcapData = Download::getIndexedRedcapData($token, $server, CareerDev::$summaryFields, $cohort, Application::getModule());

$headers = [];
$measurements = [];

$headers[] = "Demographics";
if ($cohort) {
	$headers[] = "For Cohort " . $cohort;
}

$variables = [
	"Graduate Degrees" => "summary_degrees",
	"Gender" => "summary_gender",
	"Race/Ethnicity" => "summary_race_ethnicity",
	"Primary Department" => "summary_primary_dept",
	"Date of Birth" => "summary_dob",
	"Citizenship" => "summary_citizenship",
	"Academic Rank" => "summary_current_rank",
];

$totals = [];
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

echo $dashboard->makeHTML($headers, $measurements, [], $cohort);
