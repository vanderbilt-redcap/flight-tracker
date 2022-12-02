<?php

use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Dashboard;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Measurement;
use \Vanderbilt\CareerDevLibrary\ObservedMeasurement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
$dashboard = new Dashboard($pid);
require_once(dirname(__FILE__)."/".$dashboard->getTarget().".php");

$headers = [];
$measurements = [];

$headers[] = "Miscellaneous Metrics for Publications";
if (isset($_GET['cohort'])) {
    $cohort = Sanitizer::sanitizeCohort($_GET['cohort']);
    $headers[] = "For Cohort " . $cohort;
} else {
    $cohort = "";
}
$headers[] = Publications::makeLimitButton();

$indexedRedcapData = Download::getIndexedRedcapData($token, $server, DataDictionaryManagement::filterOutInvalidFields([], array_unique(array_merge(CareerDev::$smallCitationFields, ["record_id", "citation_rcr", "citation_authors", "citation_year", "citation_month", "citation_day", "citation_num_citations", "summary_training_start", "summary_training_end"]))), $cohort, Application::getModule());

$tooltip = [
		"Relative Citation Ratio" => "The article citation rate (ACR) divided by the expected citation rate (ECR), which is normalized by field (the author's co-citation network) and time (years since publication). Cf. ".Links::changeTextColorOfLink(Links::makeLink('https://www.doi.org/10.1371/journal.pbio.1002541', "doi"), "white").".",
		];
$metrics = [
		"Average Relative Citation Ratio (RCR)" => "rcr",
		"Median Relative Citation Ratio (RCR)" => "rcr",
		"Total Citations by Others" => "num_citations",
		"Average Citations by Others" => "num_citations",
		"Median Citations by Others" => "num_citations",
		];

$authorPos = [
    "first" => ["training" => [], "total" => []],
    "last" => ["training" => [], "total" => []],
    "papers" => ["training" => [], "total" => []],
];
$type = "Included";
$numForMetric = [];
$ts = time();
$numUnconfirmedPubs = 0;
foreach ($indexedRedcapData as $recordId => $rows) {
	$pubs = new Publications($token, $server, []);
	$pubs->setRows($rows);
	$goodCitations = $pubs->getCitationCollection($type);
    $trainingStartDate = REDCapManagement::findField($rows, $recordId, "summary_training_start");
    $trainingEndDate = REDCapManagement::findField($rows, $recordId, "summary_training_end");
    $authorPos["first"]["total"][] = $pubs->getNumberFirstAuthors(NULL, NULL, FALSE);
    $authorPos["last"]["total"][] = $pubs->getNumberLastAuthors(NULL, NULL, FALSE);
    $authorPos["papers"]["total"][] = $pubs->getCitationCount($type);
    if ($trainingStartDate) {
        $startTs = strtotime($trainingStartDate);
        if ($trainingEndDate) {
            $endTs = strtotime($trainingEndDate);
            $authorPos["first"]["training"][] = $pubs->getNumberFirstAuthors($startTs, $endTs, FALSE);
            $authorPos["last"]["training"][] = $pubs->getNumberLastAuthors($startTs, $endTs, FALSE);
            $authorPos["papers"]["training"][] = count($pubs->getSortedCitationsInTimespan($startTs, $endTs, $type));
        } else {
            $authorPos["first"]["training"][] = $pubs->getNumberFirstAuthors($startTs, NULL, FALSE);
            $authorPos["last"]["training"][] = $pubs->getNumberLastAuthors($startTs, NULL, FALSE);
            $authorPos["papers"]["training"][] = count($pubs->getSortedCitationsInTimespan($startTs, FALSE, $type));
        }
    }
	if ($goodCitations) {
		foreach ($goodCitations->getCitations() as $citation) {
			if ($citation->isResearchArticle()) {
				foreach ($metrics as $metric => $variable) {
					if (!isset($numForMetric[$metric])) {
						$numForMetric[$metric] = [];
					}

					$val = $citation->getVariable($variable);
					if ($val) {
						$numForMetric[$metric][] = $val;
					}
				}
			}
		}
	}

	$notDoneCitations = $pubs->getCitationCollection("Not done");
	if ($notDoneCitations) {
		$numUnconfirmedPubs += $notDoneCitations->getCount();
	}
}

ksort($numForMetric);

foreach ($numForMetric as $origMetric => $ary) {
	if (!empty($ary)) {
		$metric = $origMetric;
		foreach ($tooltip as $text => $definition) {
			$metric = str_replace($text, "<span class='tooltip'>$text<span class='tooltiptext'>$definition</span></span>", $metric);
		}
		
		if (preg_match("/\bSum\b/i", $metric) || preg_match("/\bTotal\b/i", $metric)) {
			$measurements[$metric] = new ObservedMeasurement(array_sum($ary), count($ary));
		} else if (preg_match("/\bMedian\b/i", $metric)) {
			$cnt = count($ary);
			sort($ary);
			if ($cnt % 2 == 0) {
				# even
				$n1 = $ary[(int) floor($cnt / 2)];
				$n2 = $ary[(int) floor($cnt / 2) + 1];
				$median = ($n1 + $n2) / 2;
			} else {
				# odd
				$median = $ary[(int) floor($cnt / 2) + 1];
			}
			$measurements[$metric] = new ObservedMeasurement($median, $cnt);
		} else if (preg_match("/\bAvg\b/i", $metric) || preg_match("/\bAverage\b/i", $metric)) {
			$avg = array_sum($ary) / count($ary);
			$measurements[$metric] = new ObservedMeasurement($avg, count($ary));
		}
	}
}

$measurements["Number of First Authors in Training"] = new Measurement(array_sum($authorPos['first']['training']), array_sum($authorPos['papers']['training']));
$measurements["Number of Last Authors in Training"] = new Measurement(array_sum($authorPos['last']['training']), array_sum($authorPos['papers']['training']));
$measurements["Number of First Authors Total"] = new Measurement(array_sum($authorPos['first']['total']), array_sum($authorPos['papers']['total']));
$measurements["Number of Last Authors Total"] = new Measurement(array_sum($authorPos['last']['total']), array_sum($authorPos['papers']['total']));

if (!empty($numForMetric)) {
	echo $dashboard->makeHTML($headers, $measurements, [], $cohort);
} else {
	echo "No metrics calculated!";
}
