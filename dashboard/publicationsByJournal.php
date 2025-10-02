<?php

use Vanderbilt\CareerDevLibrary\Publications;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Measurement;
use Vanderbilt\CareerDevLibrary\DateMeasurement;
use Vanderbilt\CareerDevLibrary\MoneyMeasurement;
use Vanderbilt\CareerDevLibrary\ObservedMeasurement;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Dashboard;
use Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
$dashboard = new Dashboard($pid);
require_once(dirname(__FILE__)."/".$dashboard->getTarget().".php");

$headers = [];
$measurements = [];

$headers[] = "Publications by Journal<br>(Confirmed Original Research Only)";
if (isset($_GET['cohort'])) {
	$cohort = REDCapManagement::sanitizeCohort($_GET['cohort']);
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

$indexedRedcapData = Download::getIndexedRedcapData($token, $server, DataDictionaryManagement::filterOutInvalidFields([], array_unique(array_merge(CareerDev::$smallCitationFields, ['citation_journal', 'citation_ts', "eric_source"]))), $cohort, Application::getModule());

$numConfirmedPubs = 0;
$numForJournal = [];
$ts = time();
foreach ($indexedRedcapData as $recordId => $rows) {
	$pubs = new Publications($token, $server, []);
	$pubs->setRows($rows);
	$goodCitations = $pubs->getCitationCollection("Included");
	if ($goodCitations) {
		$confirmedCount = $goodCitations->getCount();
		foreach ($goodCitations->getCitations() as $citation) {
			if ($citation->getTimestamp() >= $thresholdTs) {
				$numConfirmedPubs++;
				if ($citation->isResearchArticle()) {
					$field = ($citation->getVariable("data_source") == "citation") ? "journal" : "source";
					$journal = $citation->getVariable($field);

					if (!isset($numForJournal[$journal])) {
						$numForJournal[$journal] = 0;
					}

					$numForJournal[$journal]++;
				}
			}
		}
	}
}

arsort($numForJournal);
foreach ($numForJournal as $journal => $val) {
	if ($val) {
		$measurements[$journal] = new Measurement($val, $numConfirmedPubs);
	}
}

$headers[] = REDCapManagement::pretty(count($numForJournal)) . " Journals Represented";

echo $dashboard->makeHTML($headers, $measurements, [], $cohort);
