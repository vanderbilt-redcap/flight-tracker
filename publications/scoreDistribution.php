<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\BarChart;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\DateManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\iCite;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$thresholdRCR = $_GET['thresholdRCR'] ? Sanitizer::sanitize($_GET['thresholdRCR']) : 2.0;
$startDate = Publications::adjudicateStartDate($_GET['limitPubs'] ?? "", $_GET['start'] ?? "");
$endDate = Sanitizer::sanitizeDate($_GET['end'] ?? "");
$startTs = DateManagement::isDate($startDate) ? strtotime($startDate) : 0;
$oneYear = 365 * 24 * 3600;
$endTs = DateManagement::isDate($endDate) ? strtotime($endDate) : time() + $oneYear;
$cohort = $_GET['cohort'] ? Sanitizer::sanitizeCohort($_GET['cohort']) : "";

$fields = [
    "record_id",
    "citation_include",
    "citation_rcr",
    "citation_altmetric_score",
    "citation_day",
    "citation_month",
    "citation_year",
];

$metadata = Download::metadata($token, $server);
$fieldLabels = REDCapManagement::getLabels($metadata);
$firstNames = Download::firstnames($token, $server);
$lastNames = Download::lastnames($token, $server);
if ($cohort) {
    $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else {
    $records = Download::recordIds($token, $server);
}
$relevantNames = [];
foreach ($records as $recordId) {
    $relevantNames[] = ["firstName" => $firstNames[$recordId], "lastName" => $lastNames[$recordId]];
}
$redcapData = Download::fieldsForRecords($token, $server, $fields, $records);

$dist = [];
$skip = ["record_id", "redcap_repeat_instrument", "redcap_repeat_instance"];
foreach (["citation_rcr", "citation_altmetric_score"] as $field) {
    $dist[$field] = [];
    foreach ($redcapData as $row) {
        if (
            $row[$field]
            && inTimespan($row, $startTs, $endTs)
            && ($row['citation_include'] == "1")
        ) {
            $dist[$field][$row['record_id'].":".$row['redcap_repeat_instance']] = $row[$field];
        }
    }
}

$recordsToDownload = [];
$foundList = [];
foreach ($dist['citation_rcr'] as $location => $rcr) {
    if ($rcr > $thresholdRCR) {
        list($recordId, $instance) = preg_split("/:/", $location);
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
            $altmetricScore = $row['citation_altmetric_score'] ? "Altmetric Score: ".$row['citation_altmetric_score']."." : "";
            if (!isset($pertinentCitations[$rcr])) {
                $pertinentCitations[$rcr] = [];
            }
            if (isset($_GET['bold'])) {
                $pertinentCitations[$rcr][] = "<p style='text-align: left;'>".$citation->getImage("left").$citation->getCitationWithLink(FALSE, TRUE, $relevantNames)." RCR: $rcr. $altmetricScore</p>";
            } else {
                $recordName = [NameMatcher::formatName($firstNames[$recordId] ?? "", "", $lastNames[$recordId] ?? "")];
                $pertinentCitations[$rcr][] = "<p style='text-align: left;'>".$citation->getImage("left").$citation->getCitationWithLink(FALSE, TRUE, $recordName)." RCR: $rcr. $altmetricScore</p>";
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
echo "<p class='centered'>".$cohorts->makeCohortSelect($cohort)."</p>";
echo Publications::makeLimitButton();
echo "<p class='centered'>";
echo "<label for='start'>Start Date (optional)</label>: <input type='date' id='start' name='start' value='$startDate' style='width: 150px;'>";
echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<label for='end'>End Date (optional)</label>: <input type='date' id='end' name='end' value='$endDate' style='width: 150px;'>";
echo "</p>";
echo "<p class='centered'><label for='thresholdRCR'>Threshold Relative Citation Ratio (RCR) for List</label>: <input type='number' id='thresholdRCR' name='thresholdRCR' value='$thresholdRCR' style='width: 100px;'></p>";
echo "<p class='centered'><button>Re-Configure</button></p>";
echo "</form>";

$colorWheel = Application::getApplicationColors();
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
    $barChart = new BarChart($cols, $colLabels, $field);
    if ($i == 0) {
        $jsLocs = $barChart->getJSLocations();
        $cssLocs = $barChart->getCSSLocations();
        foreach ($jsLocs as $loc) {
            echo "<script src='$loc'></script>";
        }
        foreach ($cssLocs as $loc) {
            echo "<link rel='stylesheet' href='$loc'>";
        }
    }
    $barChart->setColor($color);
    $barChart->setXAxisLabel(REDCapManagement::stripHTML($label));
    $barChart->setYAxisLabel("Number of Articles");
    $barChart->showLegend(FALSE);
    echo "<div class='centered max-width'>".$barChart->getHTML(800, 500, FALSE)."</div>";
    $i++;
}

echo "<h2>High-Performing Citations (Above RCR of $thresholdRCR, Count of ".REDCapManagement::pretty(count($sortedCitations)).")</h2>";
echo "<div class='max-width centered'>".implode("", $sortedCitations)."</div>";

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
    } else if ($field == "citation_altmetric_score") {
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
    $year = $row['citation_year'];
    if (!$year) {
        return FALSE;
    }
    $month = $row['citation_month'] ?: "01";
    $day = $row['citation_day'] ?: "01";
    $ts = strtotime("$year-$month-$day");
    return (($ts >= $startTs) && ($ts <= $endTs));
}