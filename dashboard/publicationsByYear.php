<?php

use \Vanderbilt\CareerDevLibrary\Publications;
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

$headers = array();
$measurements = array();
$lines = array();

array_push($headers, "Publications by Year<br>(Confirmed Original Research Only)");
if (isset($_GET['cohort'])) {
    $cohort = REDCapManagement::sanitizeCohort($_GET['cohort']);
    array_push($headers, "For Cohort ".$cohort);
} else {
    $cohort = "";
}

$metadata = Download::metadata($token, $server);
$indexedRedcapData = \Vanderbilt\FlightTrackerExternalModule\getIndexedRedcapData($token, $server, array_merge(CareerDev::$smallCitationFields, array("citation_year")), $cohort, $metadata);

$numForYear = array();
$numPubs = 0;
foreach ($indexedRedcapData as $recordId => $rows) {
	$pubs = new Publications($token, $server, $metadata);
	$pubs->setRows($rows);
	$goodCitations = $pubs->getCitationCollection("Included");
	$confirmedCount = $goodCitations->getCount();
	$numPubs += $confirmedCount;
	foreach ($goodCitations->getCitations() as $citation) {
		if ($citation->getCategory() == "Original Research") {
			$ts = $citation->getTimestamp();
			$tsYear = "";
			if ($ts) {
				$tsYear = date("Y", $ts);
				if ($tsYear) {
					if (!isset($numForYear[$tsYear])) {
						$numForYear[$tsYear] = 0;
					}
					$numForYear[$tsYear]++;
				}
			}
		}
	}
}

$measurements["Number of Confirmed Publications"] = new Measurement($numPubs);

krsort($numForYear);
$line = array();
foreach ($numForYear as $year => $count) {
	$measurements["Confirmed for ".$year] = new Measurement($count, $numPubs);
	$line[$year] = $count;
}
$lines["Total Publications"] = $line;

echo makeHTML($headers, $measurements, $lines, $cohort, $metadata);
