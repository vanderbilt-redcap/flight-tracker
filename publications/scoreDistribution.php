<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\Altmetric;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\BarChart;
use Vanderbilt\CareerDevLibrary\LineGraph;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\DateManagement;
use Vanderbilt\CareerDevLibrary\Sanitizer;
use Vanderbilt\CareerDevLibrary\Citation;
use Vanderbilt\CareerDevLibrary\Cohorts;
use Vanderbilt\CareerDevLibrary\NameMatcher;
use Vanderbilt\CareerDevLibrary\Publications;
use Vanderbilt\CareerDevLibrary\iCite;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$thresholdRCR = isset($_GET['thresholdRCR']) ? Sanitizer::sanitizeNumber($_GET['thresholdRCR']) : 2.0;
$highImpactRCR = isset($_GET['highImpactRCR']) ? Sanitizer::sanitizeNumber($_GET['highImpactRCR']) : 8.0;
$startDate = Publications::adjudicateStartDate($_GET['limitPubs'] ?? "", $_GET['start'] ?? "");
$endDate = Sanitizer::sanitizeDate($_GET['end'] ?? "");
$startTs = DateManagement::isDate($startDate) ? strtotime($startDate) : 0;
$oneYear = 365 * 24 * 3600;
$endTs = DateManagement::isDate($endDate) ? strtotime($endDate) : time() + $oneYear;
$cohort = isset($_GET['cohort']) ? Sanitizer::sanitizeCohort($_GET['cohort']) : "";

$fields = [
	"record_id",
	"citation_include",
	"citation_rcr",
	"citation_ts",
];
if (Altmetric::isActive()) {
	$fields[] = "citation_altmetric_score";
}
$coauthorshipsOnly = false;
if (isset($_GET['coauthorships']) && ($_GET['coauthorships'] == "on")) {
	$fields[] = "citation_authors";
	$coauthorshipsOnly = true;
}

$metadata = Download::metadata($token, $server);
$fieldLabels = REDCapManagement::getLabels($metadata);
$firstNames = Download::firstnames($token, $server);
$lastNames = Download::lastnames($token, $server);
if (isset($_GET['record'])) {
	$allRecords = Download::recordIds($token, $server);
	$records = [Sanitizer::getSanitizedRecord($_GET['record'], $allRecords)];
	$thresholdRCR = 0;
} elseif ($cohort) {
	$records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else {
	$records = Download::recordIds($token, $server);
}
$relevantNames = [];
foreach ($records as $recordId) {
	$relevantNames[] = ["firstName" => $firstNames[$recordId], "lastName" => $lastNames[$recordId]];
}
$redcapData = Download::fieldsForRecords($token, $server, $fields, $records);

$metrics = [
	"citation_rcr" => "Relative Citation Ratio",
];
if (Altmetric::isActive()) {
	$metrics["citation_altmetric_score"] = "Altmetric Score";
}
$dist = [];
$byYear = [];
$highImpactRCRsByYear = [];
foreach (array_keys($metrics) as $field) {
	$dist[$field] = [];
	$byYear[$field] = [];
	foreach ($redcapData as $row) {
		if (
			!$coauthorshipsOnly
			|| (getNumNameMatches($relevantNames, $row['citation_authors']) >= 2)
		) {
			if (
				$row[$field]
				&& inTimespan($row, $startTs, $endTs)
				&& ($row['citation_include'] == "1")
			) {
				$dist[$field][$row['record_id'] . ":" . $row['redcap_repeat_instance']] = $row[$field];
				if ($row['citation_ts']) {
					$year = DateManagement::getYear($row['citation_ts']);
					if (!isset($byYear[$field][$year])) {
						$byYear[$field][$year] = [];
					}
					$byYear[$field][$year][] = $row[$field];

					if (($field == "citation_rcr") && ($row[$field] >= $highImpactRCR)) {
						if (!isset($highImpactRCRsByYear[$year])) {
							$highImpactRCRsByYear[$year] = 0;
						}
						$highImpactRCRsByYear[$year]++;
					}
				}
			}
		}
	}
}

$averagesByYear = [];
foreach ($byYear as $field => $yearsWithValues) {
	$averagesByYear[$field] = ["mean" => [], "median" => []];
	foreach ($yearsWithValues as $year => $scores) {
		sort($scores, SORT_NUMERIC);
		if (isset($_GET['test'])) {
			echo "Year $year (n=".count($scores)."): ".implode(", ", $scores)."<br/>";
		}
		$averagesByYear[$field]["mean"][$year] = avg($scores);
		$averagesByYear[$field]["median"][$year] = REDCapManagement::getMedian($scores);
	}
	if (!empty($yearsWithValues)) {
		for ($year = min(array_keys($yearsWithValues)); $year <= max(array_keys($yearsWithValues)); $year++) {
			if (!isset($averagesByYear[$field]["mean"][$year])) {
				$averagesByYear[$field]["mean"][$year] = 0;
				$averagesByYear[$field]["median"][$year] = 0;
			}
		}
	}
	ksort($averagesByYear[$field]["mean"], SORT_NUMERIC);
	ksort($averagesByYear[$field]["median"], SORT_NUMERIC);
}
if (!empty($highImpactRCRsByYear)) {
	for ($year = min(array_keys($highImpactRCRsByYear)); $year <= max(array_keys($highImpactRCRsByYear)); $year++) {
		if (!isset($highImpactRCRsByYear[$year])) {
			$highImpactRCRsByYear[$year] = 0;
		}
	}
	ksort($highImpactRCRsByYear, SORT_NUMERIC);
}

$recordsToDownload = [];
$foundList = [];
foreach ($dist['citation_rcr'] as $location => $rcr) {
	if ($rcr >= $thresholdRCR) {
		list($recordId, $instance) = explode(":", $location);
		if (!in_array($recordId, $recordsToDownload)) {
			$recordsToDownload[] = $recordId;
		}
		$foundList[] = $location;
	}
}
$pertinentCitations = [];
if (!empty($foundList)) {
	$citationFields = Application::getCitationFields($metadata);
	$pmidsUsed = [];
	$citationData = Download::fieldsForRecords($token, $server, $citationFields, $recordsToDownload);
	foreach ($citationData as $row) {
		$recordId = $row['record_id'];
		$instance = $row['redcap_repeat_instance'];
		$pmid = $row['citation_pmid'];
		if (
			inTimespan($row, $startTs, $endTs)
			&& $pmid
			&& !in_array($pmid, $pmidsUsed)
			&& in_array("$recordId:$instance", $foundList)
		) {
			# concern: multiple names might be used
			# to turn on, use $relevantNames, but my experience is that too many false matches are made
			$citation = new Citation($token, $server, $recordId, $instance, $row);
			$rcr = $row['citation_rcr'];
			if (Altmetric::isActive()) {
				$altmetricScore = $row['citation_altmetric_score'] ? "Altmetric Score: ".$row['citation_altmetric_score']."." : "";
			} else {
				$altmetricScore = "";
			}
			if (!isset($pertinentCitations[$rcr])) {
				$pertinentCitations[$rcr] = [];
			}
			if (isset($_GET['bold'])) {
				$pertinentCitations[$rcr][] = "<p style='text-align: left;'>".$citation->getImage("left").$citation->getCitationWithLink(false, true, $relevantNames)." RCR: $rcr. $altmetricScore</p>";
			} else {
				$recordName = [NameMatcher::formatName($firstNames[$recordId] ?? "", "", $lastNames[$recordId] ?? "")];
				$pertinentCitations[$rcr][] = "<p style='text-align: left;'>".$citation->getImage("left").$citation->getCitationWithLink(false, true, $recordName)." RCR: $rcr. $altmetricScore</p>";
			}
			$pmidsUsed[] = $pmid;
		}
	}
}
krsort($pertinentCitations);
$sortedCitations = [];
foreach ($pertinentCitations as $rcr => $cits) {
	foreach ($cits as $cit) {
		$sortedCitations[] = $cit;
	}
}

if (!isset($_GET['hideHeader'])) {
	echo "<h1>Publication Impact Measures</h1>";
	$link = Application::link("this");
	$baseLink = REDCapManagement::splitURL($link)[0];
	echo "<form action='$baseLink' method='GET'>";
	echo REDCapManagement::getParametersAsHiddenInputs($link);
	if (isset($_GET['limitPubs'])) {
		$limitYear = Sanitizer::sanitizeInteger($_GET['limitPubs']);
		echo "<input type='hidden' name='limitPubs' value='$limitYear' />";
	}
	$cohorts = new Cohorts($token, $server, Application::getModule());
	echo "<p class='centered'>" . $cohorts->makeCohortSelect($cohort) . "</p>";
	echo Publications::makeLimitButton();
	echo "<p class='centered'>";
	echo "<label for='start'>Start Date (optional)</label>: <input type='date' id='start' name='start' value='$startDate' style='width: 150px;'>";
	echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<label for='end'>End Date (optional)</label>: <input type='date' id='end' name='end' value='$endDate' style='width: 150px;'>";
	echo "</p>";
	echo "<p class='centered'><label for='thresholdRCR'>Threshold Relative Citation Ratio (RCR) for List</label>: <input type='number' id='thresholdRCR' name='thresholdRCR' value='$thresholdRCR' style='width: 100px;'></p>";
	echo "<p class='centered'><label for='highImpactRCR'>High-Impact Relative Citation Ratio (RCR) for Histogram</label>: <input type='number' id='highImpactRCR' name='highImpactRCR' value='$highImpactRCR' style='width: 100px;'></p>";
	$coauthorshipsChecked = $coauthorshipsOnly ? "checked" : "";
	echo "<p class='centered'><input type='checkbox' id='coauthorships' name='coauthorships' $coauthorshipsChecked /> <label for='coauthorships'>Show Only Coauthorships</label></p>";
	echo "<p class='centered'><button>Re-Configure</button></p>";
	echo "</form>";
}

$colorWheel = Application::getApplicationColors(["1.0"], true);
if (isset($_GET['test'])) {
	echo "Colors: ".json_encode($colorWheel)."<br/>";
}
$i = 0;
foreach ($dist as $field => $values) {
	$label = $fieldLabels[$field];
	$dataPoints = array_values($values);
	sort($dataPoints);
	list($cols, $colLabels) = buildDistribution($dataPoints, $field);
	$colorIdx = $i % count($colorWheel);
	$color = $colorWheel[$colorIdx];
	$median = REDCapManagement::getMedian($dataPoints);
	$n = REDCapManagement::pretty(count($dataPoints));

	echo "<h2>$label</h2>";
	echo "<h4>Median: $median (n = $n)</h4>";
	$barChart = new BarChart($cols, $colLabels, $field."_".$pid);
	if ($i == 0) {
		foreach ($barChart->getJSLocations() as $loc) {
			echo "<script src='$loc'></script>";
		}
		foreach ($barChart->getCSSLocations() as $loc) {
			echo "<link rel='stylesheet' href='$loc'>";
		}
	}
	$barChart->setDataLabel("Number of Papers in Range");
	$barChart->setColor($color);
	$barChart->setXAxisLabel(REDCapManagement::stripHTML($label));
	$barChart->setYAxisLabel("Number of Articles");
	$barChart->showLegend(false);
	echo "<div class='centered max-width'>".$barChart->getHTML(800, 500, false)."</div>";

	echo "<h4>{$metrics[$field]}s Over Time</h4>";
	$lineChart = new LineGraph(array_values($averagesByYear[$field]["mean"]), array_keys($averagesByYear[$field]["mean"]), "mean_line_graph_".$i);
	$lineChart->setColor($colorWheel[2]);
	$lineChart->setDataLabel("Mean ".$metrics[$field]);
	$lineChart->setXAxisLabel("Year of Publication");
	$lineChart->setYAxisLabel("Average ".$metrics[$field]);
	$lineChart->addDataset($averagesByYear[$field]["median"], $colorWheel[3], "Median ".$metrics[$field]);
	echo "<div class='centered max-width'>".$lineChart->getHTML(800, 500, false)."</div>";

	if ($field == "citation_rcr") {
		if (empty($highImpactRCRsByYear)) {
			echo "<p class='centered max-width'>No high-impact (&gt; $highImpactRCR) publications have been identified.</p>";
		} else {
			echo "<h4>Number of High-Impact RCRs (&gt; $highImpactRCR) Over Time</h4>";
			$highImpactBar = new BarChart(array_values($highImpactRCRsByYear), array_keys($highImpactRCRsByYear), "high-impact-rcrs");
			$highImpactBar->setDataLabel("Papers with RCR > ".$highImpactRCR);
			$highImpactBar->setColor($color);
			$highImpactBar->setXAxisLabel("Year");
			$highImpactBar->setYAxisLabel("Number of High-Impact Papers (> $highImpactRCR)");
			$highImpactBar->showLegend(false);
			echo "<div class='centered max-width'>".$highImpactBar->getHTML(800, 500, false)."</div>";
		}
	}

	$i++;
}

if (!isset($_GET['hideHeader'])) {
	if ($thresholdRCR > 0) {
		echo "<h2>High-Performing Citations (Above RCR of $thresholdRCR, Count of ".REDCapManagement::pretty(count($sortedCitations)).")</h2>";
	} else {
		echo "<h3>Citations (Count of ".REDCapManagement::pretty(count($sortedCitations)).")</h3>";
	}
	echo "<div class='max-width centered'>".implode("", $sortedCitations)."</div>";
}

function buildDistribution($values, $field) {
	if (empty($values)) {
		return [[], []];
	}
	$low = floor(min($values));
	$high = ceil(max($values));

	$cols = [];
	$colLabels = [];
	$i = 0;
	$numBars = iCite::THRESHOLD_SCORE;
	if ($field == "citation_rcr") {
		$step = 1;
		$decimals = ".0";
	} elseif ($field == "citation_altmetric_score") {
		$step = 15;
		$decimals = "";
	} else {
		throw new \Exception("Invalid field $field");
	}
	$singlePointEnd = $numBars * $step;
	for ($start = 0; $start < $singlePointEnd; $start += $step) {
		$end = $start + $step;

		$cols[$i] = 0;
		$colLabels[$i] = "[".$start.$decimals.", ".$end.$decimals.")";

		foreach ($values as $val) {
			if (($val >= $start) && ($val < $end)) {
				$cols[$i]++;
			}
		}
		$i++;
	}
	if ($high >= $singlePointEnd) {
		$colLabels[$i] = ">= $singlePointEnd$decimals";
		$cols[$i] = 0;
		foreach ($values as $val) {
			if ($val >= $singlePointEnd) {
				$cols[$i]++;
			}
		}
		$i++;
	}
	return [$cols, $colLabels];
}

function inTimespan($row, $startTs, $endTs) {
	if (!$row['citation_ts']) {
		return false;
	}
	$ts = strtotime($row['citation_ts']);
	return (($ts >= $startTs) && ($ts <= $endTs));
}

function getNumNameMatches($relevantNames, $authorList) {
	$authors = Citation::splitAuthorList($authorList);
	$numMatches = [];
	foreach ($authors as $author) {
		list($authorFirst, $authorLast) = NameMatcher::splitName($author, 2, false, false);
		foreach ($relevantNames as $nameAry) {
			if (NameMatcher::matchByInitials($authorLast, $authorFirst, $nameAry['lastName'], $nameAry['firstName'])) {
				$numMatches[] = $author;
			}
		}
	}
	return count($numMatches);
}
