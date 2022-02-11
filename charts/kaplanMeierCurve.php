<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

define('ALL_SCHOLARS_LABEL', 'All Scholars');

require_once(dirname(__FILE__)."/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

define("CENSORED_DATA_LABEL", "CENSORED DATA");
$maxKGrants = 4;
define("NUM_GRANTS_TO_PULL", $maxKGrants < Grants::$MAX_GRANTS ? $maxKGrants : Grants::$MAX_GRANTS);


$colors = array_merge(["rgba(0, 0, 0, 1)"], Application::getApplicationColors(["1.0", "0.6", "0.2"]));

$showRealGraph = ($_GET['measType'] && $_GET['measurement']);
$firstTime = FALSE;
if (isset($_GET['measType'])) {
    $measType = $_GET['measType'];
} else {
    $firstTime = TRUE;
    $measType = "Both";
}
$startDateSource = isset($_GET['startDateSource']) ? $_GET['startDateSource'] : "end_last_any_k";
if (isset($_GET['measurement'])) {
    $meas = $_GET['measurement'];
    if ($meas == "years") {
        $measUnit = "y";
    } else {
        $measUnit = "M";
    }
} else {
    $meas = "years";
    $measUnit = "y";
}
if ((isset($_GET['showAllResources']) && ($_GET['showAllResources'] == "on")) || $firstTime) {
    $showAllResources = TRUE;
    $showAllResourcesText = " checked";
} else {
    $showAllResources = FALSE;
    $showAllResourcesText = "";
}

$cohort = "";
$maxLife = 0;
$n = [];
$serialTimes = [];
$names = [];
$statusAtSerialTime = [];    // event or censored
$resourcesUsedIdx = [];
$groups = [];
$cohortTitle = "";
if ($showRealGraph) {
    if ($_GET['cohort']) {
        $cohort = REDCapManagement::sanitizeCohort($_GET['cohort']);
        $cohortTitle = " (Cohort $cohort)";
        $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
    } else {
        $records = Download::recordIds($token, $server);
    }
    $names = Download::names($token, $server);
    $metadata = Download::metadata($token, $server);
    $choices = REDCapManagement::getChoices($metadata);

    $fields = [
        "record_id",
        "summary_first_grant_activity",
        "summary_last_grant_activity",
        "summary_first_pub_activity",
        "summary_last_pub_activity",
        "summary_ever_last_any_k_to_r01_equiv",
        "summary_first_r01_or_equiv",
        "summary_last_any_k",
        "resources_resource",
        ];
    for ($i = 1; $i <= NUM_GRANTS_TO_PULL; $i++) {
        $fields[] = "summary_award_type_".$i;
    }
    $startDates = [];
    $endDates = [];
    $indexedREDCapData = Download::indexREDCapData(Download::fields($token, $server, $fields));
    foreach ($records as $record) {
        $redcapData = $indexedREDCapData[$record];
        $earliestStartDate = findEarliestStartDate($redcapData, $record, $startDateSource);
        if ($earliestStartDate) {
            $startDates[$record] = $earliestStartDate;
        } else {
            continue;    // skip record
        }
        $conversionStatusIdx = REDCapManagement::findField($redcapData, $record, "summary_ever_last_any_k_to_r01_equiv");
        if (in_array($conversionStatusIdx, [7])) {
            # K99/R00
            continue;
        }

        $conversionDate = REDCapManagement::findField($redcapData, $record, "summary_first_r01_or_equiv");

        if (in_array($conversionStatusIdx, [1, 2])) {
            $endDates[$record] = $conversionDate;
            $statusAtSerialTime[$record] = "event";
        } else {
            $endDates[$record] = findLatestEndDate($redcapData, $record, $measType);
            $statusAtSerialTime[$record] = "censored";
        }
        $serialTimes[$record] = REDCapManagement::datediff($startDates[$record], $endDates[$record], $measUnit);
        $resourcesUsedIdx[$record] = ["all"];
        foreach (REDCapManagement::findAllFields($redcapData, $record, "resources_resource") as $idx) {
            if (!in_array($idx, $resourcesUsedIdx[$record])) {
                $resourcesUsedIdx[$record][] = $idx;
            }
        }
    }

    $curveData = [];
    $maxLife = !empty($serialTimes) ? max(array_values($serialTimes)) : 0;
    if (isset($_GET['test'])) {
        echo "maxLife: $maxLife<br>";
    }
    $curveData = [];
    if ($maxLife > 0) {
        $groups = ["all" => ALL_SCHOLARS_LABEL];
        if ($showAllResources) {
            foreach ($choices['resources_resource'] as $idx => $label) {
                $groups[$idx] = $label;
            }
        }
        if (isset($_GET['test'])) {
            echo "groups: ".json_encode($groups)."<br>";
        }
        foreach ($groups as $idx => $label) {
            $curveData[$label] = [];
            if (isset($_GET['test'])) {
                echo "Examining $idx $label<br>";
            }
            $groupRecords = getResourceRecords($idx, $resourcesUsedIdx);
            $n[$label] = count($groupRecords);
            $curveData[$label][0] = [
                "numer" => count($groupRecords),
                "denom" => count($groupRecords),
                "percent" => 100.0,
                "pretty_percent" => 0.0,
                "censored" => 0,
                "events" => 0,
                "this_fraction" => 1.0,
            ];
            for ($i = 1; $i <= $maxLife; $i++) {
                $numCensoredInTimespan = 0;
                $numEventsInTimespan = 0;
                $startI = $i - 1;
                foreach ($groupRecords as $record) {
                    if (($serialTimes[$record] >= $startI) && ($serialTimes[$record] < $i)) {
                        if ($statusAtSerialTime[$record] == "event") {
                            $numEventsInTimespan++;
                        } else if ($statusAtSerialTime[$record] == "censored") {
                            $numCensoredInTimespan++;
                        } else {
                            throw new \Exception("Record $record has an invalid status (".$statusAtSerialTime[$record].") with a serial time of ".$serialTimes[$record]);
                        }
                    }
                }
                $startNumer = $curveData[$label][$startI]["numer"] ?? 0;
                $startPerc = $curveData[$label][$startI]["percent"] ?? 0;
                $curveData[$label][$i] = [];
                $curveData[$label][$i]["censored"] = $numCensoredInTimespan;
                $curveData[$label][$i]["events"] = $numEventsInTimespan;
                $curveData[$label][$i]["denom"] = $startNumer - $numCensoredInTimespan;
                $curveData[$label][$i]["numer"] = $curveData[$label][$i]["denom"] - $numEventsInTimespan;
                $curveData[$label][$i]["this_fraction"] = ($curveData[$label][$i]["denom"] > 0) ? $curveData[$label][$i]["numer"] / $curveData[$label][$i]["denom"] : 0;
                $curveData[$label][$i]["percent"] = $startPerc * $curveData[$label][$i]["this_fraction"];
                $curveData[$label][$i]["pretty_percent"] = REDCapManagement::pretty(100.0 - $curveData[$label][$i]["percent"], 1);
            }
        }
    }
} else {
    $curveData = [];
}

$hazardData = [];
$step = 1;
foreach ($curveData as $label => $rows) {
    if ($label == ALL_SCHOLARS_LABEL) {
        $hazardData[$label] = [];
        for ($start = 0; $start < count($rows) - 1; $start++) {
            $end = $start + $step;
            $idx = $start + $step / 2;
            if (isset($rows[$end]['pretty_percent']) && isset($rows[$start]['pretty_percent'])) {
                $dS = REDCapManagement::pretty($rows[$end]["pretty_percent"] - $rows[$start]["pretty_percent"], 1) / $step;
                if (isset($_GET['test'])) {
                    echo "Index: $idx has $dS<br>";
                }
                $hazardData[$label]["$idx"] = $dS;
            }
        }
    }
}

if (isset($_GET['test'])) {
    echo "survivalData: ".json_encode($curveData)."<br><br>";
    echo "hazardData: ".json_encode($hazardData)."<br><br>";
}

$survivalLinePoints = [];
$survivalLabels = [];
$totalDataPoints = 0;
$censored = [];
foreach ($curveData as $label => $curvePoints) {
    $survivalLinePoints[$label] = [];
    $censored[$label] = [];
    foreach ($curvePoints as $cnt => $ary) {
        if (count($survivalLabels) < $maxLife) {
            $survivalLabels[] = makeXAxisLabel($cnt, $meas);
        }
        if (isset($ary['pretty_percent']) && isset($ary['censored'])) {
            $survivalLinePoints[$label][] = $ary['pretty_percent'];
            if ($ary['censored'] > 0) {
                $censored[$label][] = $ary['pretty_percent'];
            } else {
                $censored[$label][] = 0;
            }
            $totalDataPoints++;
        }
    }
}

$fullURL = Application::link("charts/kaplanMeierCurve.php");
list($url, $params) = REDCapManagement::splitURL($fullURL);

$cohorts = new Cohorts($token, $server, Application::getModule());

echo "<h1>Kaplan-Meier Conversion Success Curve</h1>";
echo "<p class='centered max-width'>A <a href='https://www.ncbi.nlm.nih.gov/pmc/articles/PMC3932959/'>Kaplan-Meier survival plot</a> is used in epidemiology to track deaths over time due to a disease. It's a good way to track the effectiveness of a treatment. In Career Development, deaths are not tracked, but rather whether someone converts from K to R (event), is lost to follow-up (censored), or is still active (censored). This curve will hopefully allow you to gauge the effectiveness of scholarship-promoting efforts.</p>";
echo "<form action='$url' method='GET'>";
echo REDCapManagement::makeHiddenInputs($params);
echo "<p class='centered skinnymargins'>".$cohorts->makeCohortSelect($cohort)."</p>";

// $measurements = ["days", "months", "years"];
$measurements = ["months", "years"];
echo "<p class='centered skinnymargins'>Measurement Granularity: <select name='measurement'>";
foreach ($measurements as $measurement) {
    $sel = "";
    if ($measurement == $meas) {
        $sel = " selected";
    }
    echo "<option value='$measurement'$sel>$measurement</option>";
}
echo "</select></p>";

$measurementTypes = ["Publications" => "Publishing Publications", "Grants" => "Receiving Grant Awards", "Both" => "Both Grant and Publication Activity"];
echo "<p class='centered skinnymargins'>Test for Continued Activity: <select name='measType'>";
foreach ($measurementTypes as $measurementType => $measurementLabel) {
    $sel = "";
    if ($measurementType == $measType) {
        $sel = " selected";
    }
    echo "<option value='$measurementType'$sel>$measurementLabel</option>";
}
echo "</select></p>";
echo "<p class='centered skinnymargins'><input type='checkbox' name='showAllResources' id='showAllResources' $showAllResourcesText> <label for='showAllResources'>Show All Resources</label></p>";

$startDateOptions = [
    "end_last_any_k" => "End of Last K",
    "first_any" => "Either First Grant or First Publication Activity",
    "first_grant" => "First Grant Activity",
    "first_publication" => "First Publication Activity",
    ];
echo "<p class='centered skinnymargins'>Start Date: <select name='startDateSource'>";
foreach ($startDateOptions as $val => $label) {
    $sel = "";
    if ($startDateSource == $val) {
        $sel = " selected";
    }
    echo "<option value='$val'$sel>$label</option>";
}
echo "</select></p>";

echo "<p class='centered skinnymargins'><button>Re-Configure</button></p>";
echo "</form>";

$plots = [
    "survival" => ["title" => "Kaplan-Meier Success Curve", "yAxisTitle" => "Percent Converting from K to R"],
    "hazard" => ["title" => "Rate of Conversion Success (Equivalent of Hazard Plot)", "yAxisTitle" => "Rate of Change in Conversion Percent Per Unit Time (dS/dT)"],
    ];
$labelsJSON = [];
$datasetsJSON = [];
if ($showRealGraph) {
    if ($totalDataPoints > 0) {
        foreach ($plots as $id => $titles) {
            echo "<h2>".$titles['title']."</h2>";
            echo "<canvas class='kaplanMeier' id='$id' width='800' height='600' style='width: 800px !important; height: 600px !important;'></canvas>";
        }
        $projectTitle = Application::getProjectTitle();

        $survivalDatasets = [];
        $i = 0;
        $blankColor = "rgba(0, 0, 0, 0.0)";
        $colorByLabel = [];
        foreach ($survivalLinePoints as $label => $linePointValues) {
            if ($n[$label] > 0) {
                $colorByLabel[$label] = $colors[$i % count($colors)];
                $survivalDatasets[] = [
                    "label" => makeDatasetLabel($label, $n[$label]),
                    "data" => $linePointValues,
                    "fill" => false,
                    "borderColor" => $colorByLabel[$label],
                    "backgroundColor" => $colorByLabel[$label],
                    "stepped" => true,
                ];

                $censoredColors = [];
                foreach ($censored[$label] as $percentCensored) {
                    if ($percentCensored) {
                        $censoredColors[] = $colorByLabel[$label];
                    } else {
                        $censoredColors[] = $blankColor;
                    }
                }

                $survivalDatasets[] = [
                    "label" => CENSORED_DATA_LABEL,
                    "data" => $censored[$label],
                    "fill" => false,
                    "borderColor" => $blankColor,
                    "backgroundColor" => $censoredColors,
                    "pointRadius" => 4,
                ];
                $i++;
            }
        }

        $hazardDatasets = [];
        $hazardLabels = [];
        foreach ($hazardData as $label => $points) {
            if ($n[$label] > 0) {
                foreach (array_keys($points) as $x) {
                    if (count($hazardLabels) < $maxLife) {
                        $hazardLabels[] = makeXAxisLabel($x, $meas);
                    }
                }
                $hazardDatasets[] = [
                    "label" => makeDatasetLabel($label, $n[$label]),
                    "data" => array_values($points),
                    "fill" => false,
                    "borderColor" => $colorByLabel[$label],
                    "tension" => 0.1,
                ];
            }
        }

        $labelsJSON["survival"] = json_encode($survivalLabels);
        $datasetsJSON["survival"] = json_encode($survivalDatasets);
        $labelsJSON["hazard"] = json_encode($hazardLabels);
        $datasetsJSON["hazard"] = json_encode($hazardDatasets);

        $outlierLink = Application::link("charts/kaplanMeierOutliers.php");
        echo "<p class='centered'><a href='$outlierLink' target='_blank'>Check for Outliers (Computationally Expensive)</a></p>";

        echo "<h2>Source Data</h2>";
        echo "<table class='centered bordered'>";
        echo "<thead><tr><th>Record</th><th>Serial Time</th><th>Status</th>";
        if ($showAllResources) {
            echo "<th>Resources Used</th>";
        }
        echo "</tr></thead>";
        echo "<tbody>";
        foreach ($serialTimes as $recordId => $serialTime) {
            echo "<tr>";
            echo "<td>$recordId ({$names[$recordId]})</td>";
            echo "<td>".REDCapManagement::pretty($serialTime, 2)."</td>";
            echo "<td>{$statusAtSerialTime[$recordId]}</td>";
            if ($showAllResources) {
                $resources = [];
                foreach ($resourcesUsedIdx[$recordId] as $resourceIdx) {
                    $resources[] = $groups[$resourceIdx];
                }
                echo "<td>".implode(", ", $resources)."</td>";
            }
            echo "</tr>";
        }
        echo "</tbody>";
        echo "</table>";

        $link = Application::link("js/Chart.min.js");
        echo "<script src='$link'></script>";

        $chartLineJS = "";
        $initJS = "";
        foreach (array_keys($plots) as $id) {
            $reverseBool = "false";
            $yAxisTitle = $plots[$id]['yAxisTitle'];
            $chartLineJS .= "ctx['$id'] = document.getElementById('$id').getContext('2d');\n";
            $chartLineJS .= "data['$id'] = { labels: {$labelsJSON[$id]}, datasets: {$datasetsJSON[$id]} };\n";
            $initJS .= "    redrawChart(ctx['$id'], data['$id'], '$id', '$yAxisTitle', $reverseBool);\n";
        }

        echo "<script>
let ctx = {};
let data = {};
$chartLineJS
let lineCharts = {};
$(document).ready(function() {
    $initJS
});

function redrawChart(ctx, data, id, yAxisTitle, reverseBool) {
    const config = {
        type: 'line',
        data: data,
        options: {
            radius: 0,
            interaction: {
                intersect: false,
                axis: 'x'
            },
            plugins: {
                legend: {
                    labels: {
                        generateLabels: function(chart) {
                            var labels = Chart.defaults.plugins.legend.labels.generateLabels(chart);
                            var newLabels = [];
                            for (var key in labels) {
                                if (labels[key].text != '".CENSORED_DATA_LABEL."') {
                                    newLabels.push(labels[key]);
                                }
                            }
                            return newLabels;
                        }
                    }
                },
                title: {
                    display: true,
                    text: (ctx) => '$projectTitle$cohortTitle',
                    text: (ctx) => '$projectTitle$cohortTitle',
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            if (context.dataset.label == '".CENSORED_DATA_LABEL."') {
                                return '';
                            }
                            return context.dataset.label+': '+context.parsed.y;
                        }
                    }
                }
            },
            scales: {
                y: {
                    title: {
                        text: yAxisTitle,
                        display: true
                    },
                    reverse: reverseBool,
                    suggestedMin: 0.0,
                    beginAtZero: true
                }
            }
        }
    };
    if (yAxisTitle == 'Percent Converting from K to R') {
        config.options.scales.y.suggestedMax = 100.0;
    }
    lineCharts[id] = new Chart(ctx, config);
}
</script>";
    } else {
        echo "<p class='centered'>No data exist for this plot</p>";
    }
}

function transformResources($redcapData) {
    $resources = [];
    foreach ($redcapData as $row) {
        if (($row['redcap_repeat_instrument'] == "resources") && $row['resources_resource']) {
            if (!isset($resources[$row['record_id']])) {
                $resources[$row['record_id']] = [];
            }
            if (!in_array($row['resources_resource'], $resources[$row['record_id']])) {
                $resources[$row['record_id']][] = $row['resources_resource'];
            }
        }
    }
    return $resources;
}

function getResourceRecords($idx, $resources) {
    if ($idx == "all") {
        return array_keys($resources);
    }
    $records = [];
    foreach ($resources as $recordId => $listOfResources) {
        if (in_array($idx, $listOfResources)) {
            $records[] = $recordId;
        }
    }
    return $records;
}

function findLastKType($data, $recordId) {
    $kTypes = [1, 2, 3, 4];
    for ($i = 1; $i <= NUM_GRANTS_TO_PULL; $i++) {
        $grantType = REDCapManagement::findField($data, $recordId, "summary_award_type_".$i);
        if (in_array($grantType, $kTypes)) {
            return $grantType;
        }
    }
    return FALSE;
}

function findEarliestStartDate($data, $recordId, $startDateSource) {
    $grantDate = REDCapManagement::findField($data, $recordId, "summary_first_grant_activity");
    $pubDate = REDCapManagement::findField($data, $recordId, "summary_first_pub_activity");
    $lastAnyKStart = REDCapManagement::findField($data, $recordId, "summary_last_any_k");
    $lastKType = findLastKType($data, $recordId);
    if ($startDateSource == "first_grant") {
        return $grantDate;
    } else if ($startDateSource == "first_publication") {
        return $pubDate;
    } else if ($startDateSource == "end_last_any_k") {
        if ($lastKType && $lastAnyKStart && REDCapManagement::isDate($lastAnyKStart)) {
            if ($lastKType == 1) {
                $years = Application::getInternalKLength();
            } else if ($lastKType == 2) {
                $years = Application::getK12KL2Length();
            } else if (in_array($lastKType, [3, 4])) {
                $years = Application::getIndividualKLength();
            } else {
                throw new \Exception("Invalid K Type $lastKType!");
            }
            return REDCapManagement::addYears($lastAnyKStart, $years);
        }
    } else if ($startDateSource == "first_any") {
        if (!$grantDate) {
            return $pubDate;
        }
        if (!$pubDate) {
            return $grantDate;
        }
        if (REDCapManagement::dateCompare($grantDate, "<", $pubDate)) {
            return $grantDate;
        } else {
            return $pubDate;
        }
    }
    return "";
}

function findLatestEndDate($data, $recordId, $measType) {
    $grantDate = REDCapManagement::findField($data, $recordId, "summary_last_grant_activity");
    $pubDate = REDCapManagement::findField($data, $recordId, "summary_last_pub_activity");
    if ($measType == "Grants") {
        return $grantDate;
    } else if ($measType == "Publications") {
        return $pubDate;
    } else if ($measType == "Both") {
        if (!$grantDate) {
            return $pubDate;
        }
        if (!$pubDate) {
            return $grantDate;
        }
        if (REDCapManagement::dateCompare($grantDate, ">", $pubDate)) {
            return $grantDate;
        } else {
            return $pubDate;
        }
    }
    return "";
}

function makeXAxisLabel($cnt, $meas) {
    if (!is_integer($cnt)) {
        $cnt = floor($cnt)."-".ceil($cnt);
    }
    if ($meas == "days") {
        return "Day $cnt";
    } else if ($meas == "months") {
        return "Month $cnt";
    } else if ($meas == "years") {
        return "Year $cnt";
    } else {
        throw new \Exception("Improper measurement $meas");
    }
}

function makeDatasetLabel($label, $n) {
    return $label." (n=".$n.")";
}