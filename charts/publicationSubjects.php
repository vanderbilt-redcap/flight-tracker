<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(__DIR__."/../small_base.php");
require_once(__DIR__."/../classes/Autoload.php");

if (!isset($_GET['hideHeaders'])) {
    $cssLink = Application::link("css/career_dev.css");
    $jsLink = Application::link("js/jquery.min.js");
    echo "<link href='$cssLink' rel='stylesheet' />";
    echo "<script src='$jsLink'></script>";
}

$records = Download::recordIdsByPid($pid);
$recordId = Sanitizer::getSanitizedRecord($_GET['record'] ?? "", $records);
if (!$recordId) {
    die("<h3>Invalid setup!</h3>");
}

$citationFields = [
    "record_id",
    "citation_pmid",
    "citation_include",
    "citation_date",
    "citation_mesh_terms",
];
if (Application::isVanderbilt() && !Application::isLocalhost()) {
    $citationFields[] = "citation_ai_keywords";
}
$citationData = Download::fieldsForRecordsByPid($pid, $citationFields, [$recordId]);

$allTerms = [];
$timestampsByTerm = [];
$fieldsToMonitor = ["citation_mesh_terms", "citation_ai_keywords"];
$numPubs = 0;
foreach ($citationData as $row) {
    if (($row['citation_include'] == "1") && $row['citation_date']) {
        $ts = strtotime($row['citation_date']);
        $pmid = $row['citation_pmid'];
        $numPubs++;
        foreach ($fieldsToMonitor as $field) {
            if (isset($row[$field]) && ($row[$field] != "")) {
                $fieldTerms = preg_split("/\s*;\s*/", $row[$field]);
                foreach ($fieldTerms as $term) {
                    if (!in_array($term, $allTerms)) {
                        $allTerms[] = $term;
                        $timestampsByTerm[$term] = [];
                    }
                    $timestampsByTerm[$term][$pmid] = $ts;
                }
            }
        }
    }
}

if (empty($timestampsByTerm)) {
    die("<h3>No publications have been validated!</h3>");
}

$allMinTs = time();
$allMaxTs = 0;
foreach (array_keys($timestampsByTerm) as $term) {
    asort($timestampsByTerm[$term], SORT_NUMERIC);
    if (!empty($timestampsByTerm[$term])) {
        $minTs = min(array_values($timestampsByTerm[$term]));
        $maxTs = max(array_values($timestampsByTerm[$term]));
        if ($minTs < $allMinTs) {
            $allMinTs = $minTs;
        }
        if ($maxTs > $allMaxTs) {
            $allMaxTs = $maxTs;
        }
    }
}
sortByNumberOfTimestamps($timestampsByTerm);
sortByEarliestDate($timestampsByTerm);

$oneDay = 24 * 3600;
$allMinTs = strtotime(date("Y-01-01", $allMinTs));
$allMaxTs = strtotime(date("Y-12-31", $allMaxTs)) + $oneDay;
$minDate = intval(date("Y", $allMinTs));
$maxDate = intval(date("Y", $allMaxTs));

$timelineBackground = Application::link("img/timelineBackground.png");
$width = 600;
$barHeight = 25;
$intermediateYears = floor(($maxDate - $minDate) / 5);
$yearSpace = $width / ($maxDate - $minDate + 1);
echo "<style>
.barLine { height: $barHeight"."px; position: relative; width: 2px; border-left: 2px solid black; grid-area: 1 / 1; }
.timeBar { height: $barHeight"."px; width: 100%;  background-color: #8dc63f; background-image: url('$timelineBackground'); }
.barTimespan { height: $barHeight"."px; position: relative; background-color: #5764ae; grid-area: 1 / 1; }
.container { display: grid; }
.barHighlight { border-left: 4px solid #f0565d !important; width: 4px !important; }
</style>";
$clickMssg = "Click on a black line to highlight that paper across all timelines.";
if (isset($_GET['hideHeaders'])) {
    echo "<h3>Publication Research Topic Timeline</h3>";
    echo "<p class='centered max-width'>These ".REDCapManagement::pretty($numPubs)." publications on ".REDCapManagement::pretty(count($timestampsByTerm))." topics have been wrangled and accepted as a part of your Flight Tracker record. Each black line represents one-or-more publications at a given time. The blue background represents the time publishing in an area. $clickMssg</p>";
} else {
    echo "<p class='centered max-width'>".REDCapManagement::pretty($numPubs)." Publications on ".REDCapManagement::pretty(count($timestampsByTerm))." Topics. $clickMssg</p>";
}
foreach (["count", "date"] as $changeSortBy) {
    $thisSortBy = ($changeSortBy == "count") ? "Date" : "Count";
    $id = "sortBy".$thisSortBy;
    $otherId = "sortBy".ucfirst($changeSortBy);
    echo "<div id='$id' class='container'>";
    echo "<p class='centered'>Currently, Sorted by $thisSortBy. <button onclick='$(\"#$id\").hide(); $(\"#$otherId\").show();' class='smaller'>Instead, Sort By ".ucfirst($changeSortBy)."</button></p>";
    $i = 1;
    foreach ($timestampsByTerm as $term => $timestamps) {
        $count = REDCapManagement::pretty(count($timestamps))." Article".(count($timestamps) == 1 ? "" : "s");
        $gridArea = "$i / $i";
        echo "<h4 style='margin-bottom: 0;'>$term ($count)</h4>";
        echo "<div class='centered' style='width: $width"."px;'>";
        echo "<div class='timeBar' style='grid-area: $gridArea;'>";
        echo "<div style='display: grid;'>";
        if (count($timestamps) >= 2) {
            $minTs = min(array_values($timestamps));
            $maxTs = max(array_values($timestamps));
            $minPos = ($minTs - $allMinTs) * $width / ($allMaxTs - $allMinTs);
            $maxPos = ($maxTs - $allMinTs) * $width / ($allMaxTs - $allMinTs);
            $intMinPos = round($minPos);
            $intWidth = ceil($maxPos - $minPos);
            echo "<div class='barTimespan' style='left: $intMinPos"."px; width: $intWidth"."px;'></div>";
        }
        foreach ($timestamps as $pmid => $ts) {
            $pos = round(($ts - $allMinTs) * $width / ($allMaxTs - $allMinTs));
            $date = date("m-d-Y", $ts);
            echo "<div class='barLine' style='left: $pos"."px;' data-pmid='$pmid' title='PMID: $pmid on $date'></div>";
        }
        echo "</div>";
        echo "</div>";
        for ($year = $minDate; $year < $maxDate; $year++) {
            $string = "&nbsp;";
            if ((($year - $minDate) % $intermediateYears == 0) && ($year != $maxDate - 1)) {
                $string = "$year";
            }
            echo "<div class='smaller alignLeft' style='width: $yearSpace"."px; float: left;'>$string</div>";
        }
        echo "<div class='smaller alignright' style='width: $yearSpace"."px; float: left;'>$maxDate</div>";
        echo "</div>";
        $i++;
    }
    echo "</div>";
    sortByEarliestDate($timestampsByTerm);
    sortByNumberOfTimestamps($timestampsByTerm);
}
echo "<script>
$(document).ready(() => {
    $('.barLine').click((ev) => {
        const pmid = $(ev.target).attr('data-pmid');
        $('.barLine').removeClass('barHighlight');
        $('.barLine[data-pmid='+pmid+']').addClass('barHighlight');
    });
});
</script>";

function sortByEarliestDate(&$timestampsByTerms) {
    $earliestTimestamps = [];
    foreach ($timestampsByTerms as $term => $timestamps) {
        if (!empty($timestamps)) {
            $earliestTimestamps[$term] = array_values($timestamps)[0];
        }
    }
    asort($earliestTimestamps, SORT_NUMERIC);

    $newTimestampsByTerms = [];
    foreach (array_keys($earliestTimestamps) as $term) {
        $newTimestampsByTerms[$term] = $timestampsByTerms[$term];
    }
    $timestampsByTerms = $newTimestampsByTerms;
}

function sortByNumberOfTimestamps(&$timestampsByTerms) {
    $numTimestamps = [];
    foreach ($timestampsByTerms as $term => $timestamps) {
        $numTimestamps[$term] = count($timestamps);
    }
    arsort($numTimestamps, SORT_NUMERIC);

    $newTimestampsByTerms = [];
    foreach (array_keys($numTimestamps) as $term) {
        $newTimestampsByTerms[$term] = $timestampsByTerms[$term];
    }
    $timestampsByTerms = $newTimestampsByTerms;
}