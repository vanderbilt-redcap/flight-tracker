<?php

use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\FlightTrackerExternalModule\Measurement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/".\Vanderbilt\FlightTrackerExternalModule\getTarget().".php");
require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Links.php");

$headers = array();
$measurements = array();

array_push($headers, "Miscellaneous Metrics for Publications");
if ($_GET['cohort']) {
	array_push($headers, "For Cohort ".$_GET['cohort']);
} 

$metadata = Download::metadata($token, $server);
$indexedRedcapData = \Vanderbilt\FlightTrackerExternalModule\getIndexedRedcapData($token, $server, array_merge(CareerDev::$smallCitationFields, array("citation_rcr", "citation_num_citations")), $_GET['cohort'], $metadata);

$tooltip = array(
		"Relative Citation Ratio" => "The article citation rate (ACR) divided by the expected citation rate (ECR), which is normalized by field (the author's co-citation network) and time (years since publication). Cf. ".\Vanderbilt\FlightTrackerExternalModule\changeTextColorOfLink(Links::makeLink('https://www.doi.org/10.1371/journal.pbio.1002541', "doi"), "white").".",
		);
$metrics = array(
		"Average Relative Citation Ratio (RCR)" => "rcr",
		"Median Relative Citation Ratio (RCR)" => "rcr",
		"Total Citations by Others" => "num_citations",
		"Average Citations by Others" => "num_citations",
		"Median Citations by Others" => "num_citations",
		);

$numForMetric = array();
$ts = time();
foreach ($indexedRedcapData as $recordId => $rows) {
	$pubs = new Publications($token, $server, $metadata);
	$pubs->setRows($rows);
	$goodCitations = $pubs->getCitationCollection("Included");
	if ($goodCitations) {
		foreach ($goodCitations->getCitations() as $citation) {
			if ($citation->getCategory() == "Original Research") {
				foreach ($metrics as $metric => $variable) {
					if (!isset($numForMetric[$metric])) {
						$numForMetric[$metric] = array();
					}

					$val = $citation->getVariable($variable);
					if ($val) {
						array_push($numForMetric[$metric], $val);
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
				$n1 = $ary[floor($cnt / 2)];
				$n2 = $ary[floor($cnt / 2) + 1];
				$median = ($n1 + $n2) / 2;
			} else {
				# odd
				$median = $ary[floor($cnt / 2) + 1];
			}
			$measurements[$metric] = new ObservedMeasurement($median, $cnt);
		} else if (preg_match("/\bAvg\b/i", $metric) || preg_match("/\bAverage\b/i", $metric)) {
			$avg = array_sum($ary) / count($ary);
			$measurements[$metric] = new ObservedMeasurement($avg, count($ary));
		}
	}
}

if (!empty($numForMetric)) {
	echo makeHTML($headers, $measurements, array(), $_GET['cohort'], $metadata);
} else {
	echo "No metrics calculated!";
}
