<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\Patents;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\FlightTrackerExternalModule\Measurement;
use \Vanderbilt\FlightTrackerExternalModule\MoneyMeasurement;
use \Vanderbilt\FlightTrackerExternalModule\ObservedMeasurement;
use \Vanderbilt\FlightTrackerExternalModule\DateMeasurement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/".\Vanderbilt\FlightTrackerExternalModule\getTarget().".php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Scholar.php");
require_once(dirname(__FILE__)."/../classes/Patents.php");

define("FOLLOWUP_LOST", "Followup Lost to Other Institution");
define("PATENTS_INCLUDED", "Number of Patents");
$headers = [];
$measurements = [];

$metadata = Download::metadata($token, $server);
$fields = array_unique(array_merge(
    CareerDev::$summaryFields,
    Application::getPatentFields($metadata),
));
$indexedRedcapData = Download::getIndexedRedcapData($token, $server, $fields, $_GET['cohort'], $metadata);

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

$lexicon = array(
		"Converted while on K" => "Converted<br>while on K",
		"Converted while not on K" => "Converted<br>while not on K",
		"Not Converted" => "Not Converted (".Scholar::getKLength("Internal")." years for Internal K;<br>".Scholar::getKLength("External")." years for External K)",
		"Not Eligible" => "Not Eligible (includes K99/R00s &amp; those on K-Grants)",
		FOLLOWUP_LOST => "Followup Lost to Other Institution (Not Eligible)",
        PATENTS_INCLUDED => "Number of Wrangled Patents",
		);
array_push($headers, "Overall Summary");
if ($_GET['cohort']) {
	array_push($headers, "For Cohort ".$_GET['cohort']);
} 

// not accurate: $measurements["Number at ".INSTITUTION] = new Measurement($atInst, count($indexedRedcapData));
$measurements["Converted (Overall)"] = new Measurement($convertedTotals["Converted while on K"] + $convertedTotals["Converted while not on K"], $eligible);
foreach ($lexicon as $conv => $text) {
	$total = $convertedTotals[$conv];
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

echo makeHTML($headers, $measurements, array(), $_GET['cohort'], $metadata);
