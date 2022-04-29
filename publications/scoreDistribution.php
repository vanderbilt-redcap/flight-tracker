<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\BarChart;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Citation;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$thresholdRCR = $_GET['thresholdRCR'] ? REDCapManagement::sanitize($_GET['thresholdRCR']) : 2.0;

$fields = [
    "record_id",
    "citation_rcr",
    "citation_altmetric_score",
];

$metadata = Download::metadata($token, $server);
$fieldLabels = REDCapManagement::getLabels($metadata);
$redcapData = Download::fields($token, $server, $fields);

$dist = [];
$skip = ["record_id", "redcap_repeat_instrument", "redcap_repeat_instance"];
foreach ($fields as $field) {
    if (!in_array($field, $skip)) {
        $dist[$field] = [];
        foreach ($redcapData as $row) {
            if ($row[$field]) {
                $dist[$field][$row['record_id'].":".$row['redcap_repeat_instance']] = $row[$field];
            }
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
    $lastNames = Download::lastnames($token, $server);
    $firstNames = Download::firstnames($token, $server);
    $citationFields = Application::getCitationFields($metadata);
    $pmidsUsed = [];
    $citationData = Download::fieldsForRecords($token, $server, $citationFields, $recordsToDownload);
    foreach ($citationData as $row) {
        $recordId = $row['record_id'];
        $instance = $row['redcap_repeat_instance'];
        $pmid = $row['citation_pmid'];
        if ($pmid && !in_array($pmid, $pmidsUsed) && in_array("$recordId:$instance", $foundList)) {
            # do not bold name because multiple names might be used
            $citation = new Citation($token, $server, $recordId, $instance, $row);
            $rcr = $row['citation_rcr'];
            $altmetricScore = $row['citation_altmetric_score'] ? "Altmetric Score: ".$row['citation_altmetric_score']."." : "";
            if (!isset($pertinentCitations[$rcr])) {
                $pertinentCitations[$rcr] = [];
            }
            $pertinentCitations[$rcr][] = "<p style='text-align: left;'>".$citation->getImage("left").$citation->getCitationWithLink(FALSE, TRUE)." RCR: $rcr. $altmetricScore</p>";
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
echo "<p class='centered'>";
echo "<label for='thresholdRCR'>Threshold Relative Citation Ratio (RCR) for List</label>: <input type='number' id='thresholdRCR' name='thresholdRCR' value='$thresholdRCR' style='width: 100px;'><br>";
echo "<button>Re-Configure</button>";
echo "</p>";
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
    $low = floor(min($values));
    $high = ceil(max($values));

    $cols = [];
    $colLabels = [];
    $i = 0;
    $numBars = 8;
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
