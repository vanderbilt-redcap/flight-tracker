<?php

use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;


require_once(dirname(__FILE__)."/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

define("CENSORED_DATA_LABEL", "CENSORED DATA");

$alphas = ["1.0", "0.6", "0.2"];
$colors = ["rgba(0, 0, 0, 1)"];
foreach ($alphas as $alpha) {
    # Flight Tracker RGBs
    $colors[] = "rgba(240, 86, 93, $alpha)";
    $colors[] = "rgba(141, 198, 63, $alpha)";
    $colors[] = "rgba(87, 100, 174, $alpha)";
    $colors[] = "rgba(247, 151, 33, $alpha)";
    $colors[] = "rgba(145, 148, 201, $alpha)";
}

$showRealGraph = ($_GET['measType'] && $_GET['measurement']);
$firstTime = FALSE;
if ($_GET['measType']) {
    $measType = $_GET['measType'];
} else {
    $firstTime = TRUE;
    $measType = "Both";
}
if ($_GET['measurement']) {
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
if (($_GET['showAllResources'] == "on") || $firstTime) {
    $showAllResources = TRUE;
    $showAllResourcesText = " checked";
} else {
    $showAllResources = FALSE;
    $showAllResourcesText = "";
}

if ($showRealGraph) {
    if ($_GET['cohort']) {
        $cohortTitle = " (Cohort ".$_GET['cohort'].")";
        $records = Download::cohortRecordIds($token, $server, Application::getModule(), $_GET['cohort']);
    } else {
        $cohortTitle = "";
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
        "resources_resource",
        ];
    $startDates = [];
    $endDates = [];
    $serialTimes = [];
    $statusAtSerialTime = [];    // event or censored
    $resourcesUsedIdx = [];
    $indexedREDCapData = Download::indexREDCapData(Download::fields($token, $server, $fields));
    foreach ($records as $record) {
        $redcapData = $indexedREDCapData[$record];
        $earliestStartDate = findEarliestStartDate($redcapData, $record, $measType);
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
    $maxLife = max(array_values($serialTimes));
    if (isset($_GET['test'])) {
        echo "maxLife: $maxLife<br>";
    }
    $curveData = [];
    if ($maxLife > 0) {
        $groups = ["all" => "All Scholars"];
        if ($showAllResources) {
            foreach ($choices['resources_resource'] as $idx => $label) {
                $groups[$idx] = $label;
            }
        }
        if (isset($_GET['test'])) {
            echo "groups: ".json_encode($groups)."<br>";
        }
        $n = [];
        foreach ($groups as $idx => $label) {
            $curveData[$label] = [];
            if (isset($_GET['test'])) {
                echo "Examining $idx $label<br>";
            }
            $groupRecords = getResourceRecords($idx, $resourcesUsedIdx);
            if (isset($_GET['test'])) {
                echo "$idx $label has groupRecords (".count($groupRecords)."): ".json_encode_with_spaces($groupRecords)."<br>";
            }
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
                $curveData[$label][$i] = [];
                $curveData[$label][$i]["censored"] = $numCensoredInTimespan;
                $curveData[$label][$i]["events"] = $numEventsInTimespan;
                $curveData[$label][$i]["denom"] = $curveData[$label][$startI]["numer"] - $numCensoredInTimespan;
                $curveData[$label][$i]["numer"] = $curveData[$label][$i]["denom"] - $numEventsInTimespan;
                $curveData[$label][$i]["this_fraction"] = $curveData[$label][$i]["numer"] / $curveData[$label][$i]["denom"];
                $curveData[$label][$i]["percent"] = $curveData[$label][$startI]["percent"] * $curveData[$label][$i]["this_fraction"];
                $curveData[$label][$i]["pretty_percent"] = REDCapManagement::pretty(100.0 - $curveData[$label][$i]["percent"], 1);
            }
        }
        if (isset($_GET['test'])) {
            foreach ($curveData as $label => $lines) {
                foreach ($lines as $i => $line) {
                    echo "curveData[$label][$i]: ".REDCapManagement::json_encode_with_spaces($line)."<br>";
                }
            }
        }
    }
} else {
    $curveData = [];
}

$linePoints = [];
$labels = [];
$totalDataPoints = 0;
$censored = [];
foreach ($curveData as $label => $curvePoints) {
    $linePoints[$label] = [];
    $censored[$label] = [];
    foreach ($curvePoints as $cnt => $ary) {
        if (count($labels) < $maxLife) {
            if ($meas == "days") {
                $labels[] = "Day $cnt";
            } else if ($meas == "months") {
                $labels[] = "Month $cnt";
            } else if ($meas == "years") {
                $labels[] = "Year $cnt";
            } else {
                throw new \Exception("Improper measurement $meas");
            }
        }
        $linePoints[$label][] = $ary['pretty_percent'];
        if ($ary['censored'] > 0) {
            $censored[$label][] = $ary['pretty_percent'];
        } else {
            $censored[$label][] = 0;
        }
        $totalDataPoints++;
    }
}

$fullURL = Application::link("charts/kaplanMeierCurve.php");
list($url, $params) = REDCapManagement::splitURL($fullURL);

$cohorts = new Cohorts($token, $server, Application::getModule());

echo "<h1>Kaplan-Meier Conversion Curve</h1>";
echo "<p class='centered max-width'>A <a href='https://www.ncbi.nlm.nih.gov/pmc/articles/PMC3932959/'>Kaplan-Meier survival plot</a> is used in epidemiology to track deaths over time due to a disease. It's a good way to track the effectiveness of a treatment. In Career Development, deaths are not tracked, but rather whether someone converts from K to R (event), is lost to follow-up (censored), or is still active (censored). This curve will hopefully allow you to gauge the effectiveness of scholarship-promoting efforts.</p>";
echo "<form action='$url' method='GET'>";
echo REDCapManagement::makeHiddenInputs($params);
echo "<p class='centered skinnymargins'>".$cohorts->makeCohortSelect($_GET['cohort'])."</p>";

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

echo "<p class='centered skinnymargins'><button>Re-Configure</button></p>";
echo "</form>";

if ($showRealGraph) {
    if ($totalDataPoints > 0) {
        echo "<canvas class='kaplanMeier' id='lineChart' width='800' height='600' style='width: 800px !important; height: 600px !important;'></canvas>";
        $link = Application::link("js/Chart.min.js");
        $projectTitle = Application::getProjectTitle();
        $datasets = [];
        $i = 0;
        $blankColor = "rgba(0, 0, 0, 0.0)";
        foreach ($linePoints as $label => $linePointValues) {
            if ($n[$label] > 0) {
                $color = $colors[$i % count($colors)];
                $datasets[] = [
                    "label" => $label." (n=".$n[$label].")",
                    "data" => $linePointValues,
                    "fill" => false,
                    "borderColor" => $color,
                    "backgroundColor" => $color,
                    "stepped" => true,
                ];

                $censoredColors = [];
                foreach ($censored[$label] as $percentCensored) {
                    if ($percentCensored) {
                        $censoredColors[] = $color;
                    } else {
                        $censoredColors[] = $blankColor;
                    }
                }

                $datasets[] = [
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

        $labelsJSON = json_encode($labels);
        $datasetsJSON = json_encode($datasets);

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

        echo "<script src='$link'></script>";
        echo "<script>
let ctx = document.getElementById('lineChart').getContext('2d');
let data = { labels: $labelsJSON, datasets: $datasetsJSON };
let lineChart = null;
$(document).ready(function() {
    redrawChart(ctx, data);
});
</script>
<script>
function redrawChart(ctx, data) {
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
                        text: 'Percent Converting from K to R',
                        display: true
                    },
                    reverse: true,
                    suggestedMin: 0.0,
                    suggestedMax: 100.0,
                    beginAtZero: true
                }
            }
        }
    };
    lineChart = new Chart(ctx, config);
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

function findEarliestStartDate($data, $recordId, $measType) {
    $grantDate = REDCapManagement::findField($data, $recordId, "summary_first_grant_activity");
    $pubDate = REDCapManagement::findField($data, $recordId, "summary_first_pub_activity");
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

