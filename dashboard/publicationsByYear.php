<?php

use Vanderbilt\CareerDevLibrary\Publications;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Measurement;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;
use Vanderbilt\CareerDevLibrary\Dashboard;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Sanitizer;
use Vanderbilt\CareerDevLibrary\DataDictionaryManagement;

require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
$dashboard = new Dashboard($pid);
require_once(dirname(__FILE__)."/".$dashboard->getTarget().".php");

$headers = [];
$measurements = [];
$lines = [];

$headers[] = "Publications by Year<br>(Confirmed Original Research Only)";
if (isset($_GET['cohort'])) {
	$cohort = Sanitizer::sanitizeCohort($_GET['cohort']);
	$headers[] = "For Cohort " . $cohort;
} else {
	$cohort = "";
}
$headers[] = Publications::makeLimitButton();

$thresholdTs = -100000;
if (isset($_GET['limitPubs'])) {
	$thresholdYear = Sanitizer::sanitizeInteger($_GET['limitPubs']);
	$thresholdTs = strtotime("$thresholdYear-01-01");
}

$indexedRedcapData = Download::getIndexedRedcapData($token, $server, DataDictionaryManagement::filterOutInvalidFields([], array_unique(array_merge(CareerDev::$smallCitationFields, ["citation_year", "citation_ts"]))), $cohort, Application::getModule());

$numForYear = [];
$numPubs = 0;
foreach ($indexedRedcapData as $recordId => $rows) {
	$pubs = new Publications($token, $server, []);
	$pubs->setRows($rows);
	$goodCitations = $pubs->getCitationCollection("Included");
	foreach ($goodCitations->getCitations() as $citation) {
		if ($citation->getTimestamp() >= $thresholdTs) {
			$numPubs++;
			if ($citation->isResearchArticle()) {
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
}

$measurements["Number of Confirmed Publications"] = new Measurement($numPubs);

krsort($numForYear);
$line = [];
foreach ($numForYear as $year => $count) {
	$measurements["Confirmed for ".$year] = new Measurement($count, $numPubs);
	$line[$year] = $count;
}
$lines["Total Publications"] = $line;

echo $dashboard->makeHTML($headers, $measurements, $lines, $cohort);
