<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\Patents;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Measurement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Dashboard;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
$dashboard = new Dashboard($pid);
require_once(dirname(__FILE__)."/".$dashboard->getTarget().".php");

define("FOLLOWUP_LOST", "Followup Lost to Other Institution");
define("PATENTS_INCLUDED", "Number of Patents");

if (isset($_GET['cohort'])) {
    $cohort = Sanitizer::sanitizeCohort($_GET['cohort']);
} else {
    $cohort = "";
}


$headers = [];
$measurements = [];

$metadata = Download::metadata($token, $server);
$fields = array_unique(array_merge(
    CareerDev::$summaryFields,
    Application::getPatentFields($metadata)
));
$indexedRedcapData = Download::getIndexedRedcapData($token, $server, $fields, $cohort, Application::getModule());

# total number of scholars
# Overall conversion ratio
# breakdown of converted

$convertedStatuses = ["Converted while not on K", "Converted while on K"];
$ineligibleStatuses = ["Not Eligible"];
$convertedTotals = [FOLLOWUP_LOST => 0, PATENTS_INCLUDED => 0];
$atInst = 0;
$eligible = 0;
foreach ($indexedRedcapData as $recordId => $rows) {
	$scholar = new Scholar($token, $server, $metadata);
	$scholar->setRows($rows);
	$conv = $scholar->isConverted();
	if (!isset($convertedTotals[$conv])) {
		$convertedTotals[$conv] = 0;
	}

	$employment = $scholar->getEmploymentStatus();
	if (preg_match("/Left/", $employment)) {
		if (in_array($conv, $convertedStatuses)) {
			$convertedTotals[$conv]++;
			$eligible++;
		} else {
			$convertedTotals[FOLLOWUP_LOST]++;
		}
	} else {
		$atInst++;
		$convertedTotals[$conv]++;
		if (!in_array($conv, $ineligibleStatuses)) {
			$eligible++;
		}
	}

	$patents = new Patents($recordId, $pid);
	$patents->setRows($rows);
	$convertedTotals[PATENTS_INCLUDED] += $patents->getCount();
}

$lexicon = [
		"Converted while on K" => "Converted<br>while on K",
		"Converted while not on K" => "Converted<br>while not on K",
		"Not Converted" => "Not Converted (".Scholar::getKLength("Internal")." years for Internal K;<br>".Scholar::getKLength("External")." years for External K)",
		"Not Eligible" => "Not Eligible (includes Bridge Awards &amp; those on K-Grants)",
		FOLLOWUP_LOST => "Followup Lost to Other Institution (Not Eligible)",
        PATENTS_INCLUDED => "Number of Wrangled Patents",
		];
$headers[] = "Overall Summary";
if ($cohort) {
	$headers[] = "For Cohort " . $cohort;
}

// not accurate: $measurements["Number at ".INSTITUTION] = new Measurement($atInst, count($indexedRedcapData));
$measurements["Converted (Overall)"] = new Measurement(($convertedTotals["Converted while on K"] ?? 0) + ($convertedTotals["Converted while not on K"] ?? 0), $eligible);
foreach ($lexicon as $conv => $text) {
	$total = $convertedTotals[$conv] ?? 0;
	if ($conv == FOLLOWUP_LOST) {
        $measurements[$text] = new Measurement($total, count($indexedRedcapData));
    } else if ($conv == PATENTS_INCLUDED) {
	    if (CareerDev::has("patent")) {
            $measurements[$text] = new Measurement($total);
        }
	} else if (in_array($conv, $ineligibleStatuses)) {
		$measurements[$text] = new Measurement($total, count($indexedRedcapData));
	} else {
		$measurements[$text] = new Measurement($total, $eligible);
	}
}

echo $dashboard->makeHTML($headers, $measurements, [], $cohort);
