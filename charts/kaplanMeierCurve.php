<?php

use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;


require_once(dirname(__FILE__)."/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Scholar.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Cohorts.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/../Application.php");

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

if ($_GET['measType']) {
    $measType = $_GET['measType'];
} else {
    $measType = "Both";
}
if ($_GET['measurement']) {
    $meas = $_GET['measurement'];
} else {
    $meas = "months";
}
if ($_GET['k2r'] == "on") {
    $k2rStatus = " checked";
    $giveConversionsEternalLife = TRUE;
} else {
    $k2rStatus = "";
    $giveConversionsEternalLife = FALSE;
}

if ($_GET['activityDelay'] && is_numeric($_GET['activityDelay'])) {
    $activityDelay = $_GET['activityDelay'];
    if ($_GET['cohort']) {
        $records = Download::cohortRecordIds($token, $server, Application::getModule(), $_GET['cohort']);
    } else {
        $records = Download::recordIds($token, $server);
    }
    $metadata = Download::metadata($token, $server);
    $choices = REDCapManagement::getChoices($metadata);
    $resources = transformResources(Download::resources($token, $server, $records));
    if (isset($_GET['test'])) {
        echo "resources: ".json_encode_with_spaces($resources)."<br>";
    }

    $fields = [
        "record_id",
        "summary_first_grant_activity",
        "summary_last_grant_activity",
        "summary_first_pub_activity",
        "summary_last_pub_activity",
        "summary_ever_last_any_k_to_r01_equiv",
        ];
    $timeInResearch = [];
    $convertedRecords = [];
    foreach ($records as $record) {
        $redcapData = Download::fieldsForRecords($token, $server, $fields, [$record]);
        $scholar = new Scholar($token, $server, $metadata, $pid);
        $scholar->setRows($redcapData);
        $span = $scholar->getTimeInResearch($measType, $meas);
        $inactiveMonths = $scholar->getInactiveTimeInResearch($measType, "months");
        if ($span) {
            if (isset($_GET['test'])) {
                echo "Record $record has $span<br>";
            }
            $convStatus = REDCapManagement::findField($redcapData, $record, "summary_ever_last_any_k_to_r01_equiv");
            $hasConverted = in_array($convStatus, [1, 2, 7]);    // count K99/R00 pathway as converted
            if ($hasConverted && $giveConversionsEternalLife) {
                $convertedRecords[] = $record;
            } else {
                $timeInResearch[$record] = ["in" => ceil($span), "inactive_months" => $inactiveMonths];
            }
        } else {
            if (isset($_GET['test'])) {
                echo "No span for $record<br>";
            }
        }
    }
    if (isset($_GET['test'])) {
        echo "timeInResearch: ".REDCapManagement::json_encode_with_spaces($timeInResearch)."<br>";
        echo "convertedRecords (".count($convertedRecords)."): ".REDCapManagement::json_encode_with_spaces($convertedRecords)."<br>";
    }

    $curveData = [];
    $maxLife = 0;
    foreach (array_values($timeInResearch) as $ary) {
        if ($maxLife < $ary['in']) {
            $maxLife = $ary['in'];
        }
    }
    if (isset($_GET['test'])) {
        echo "maxLife: $maxLife<br>";
    }
    if ($maxLife > 0) {
        $groups = ["all" => "All Scholars"];
        foreach ($choices['resources_resource'] as $idx => $label) {
            $groups[$idx] = $label;
        }
        $n = [];
        foreach ($groups as $idx => $label) {
            $curveData[$label] = [];
            $groupRecords = getResourceRecords($idx, $resources, $records);
            if (isset($_GET['test'])) {
                echo "$idx $label has groupRecords (".count($groupRecords)."): ".json_encode_with_spaces($groupRecords)."<br>";
            }
            $baselineConverted = 0;
            $researchRecords = [];
            foreach ($groupRecords as $recordId) {
                if (in_array($recordId, $convertedRecords)) {
                    $baselineConverted++;
                } else {
                    $researchRecords[] = $recordId;
                }
            }
            $n[$label] = $baselineConverted + count($researchRecords);
            $curveData[$label][0] = [
                "numer" => $baselineConverted + count($researchRecords),
                "percent" => 100.0,
            ];
            for ($i = 1; $i < $maxLife; $i++) {
                $curveData[$label][$i] = ["numer" => $baselineConverted, "percent" => 0.0];
            }
            foreach ($researchRecords as $recordId) {
                $ary = $timeInResearch[$recordId];
                $in = $ary["in"];
                $inactiveMonths = $ary["inactive_months"];
                if ($inactiveMonths >= $activityDelay) {
                    for ($i = 0; $i < $in; $i++) {
                        $curveData[$label][$i]["numer"]++;
                    }
                } else {
                    # still active
                    for ($i = 0; $i < $maxLife; $i++) {
                        $curveData[$label][$i]["numer"]++;
                    }
                }
            }
            for ($i = 1; $i < $maxLife; $i++) {
                $curveData[$label][$i]["percent"] = REDCapManagement::pretty(100 * $curveData[$label][$i]["numer"] / $n[$label], 1);
            }
        }
        if (isset($_GET['test'])) {
            echo "curveData[$label]: ".REDCapManagement::json_encode_with_spaces($curveData[$label])."<br>";
        }
    }
} else {
    $activityDelay = 18;
    $k2rStatus = "";
    if (isset($_GET['test'])) {
        echo "No activityDelay<br>";
    }
    $curveData = [];
}

$linePoints = [];
$labels = [];
$totalDataPoints = 0;
foreach ($curveData as $label => $curvePoints) {
    $linePoints[$label] = [];
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
        $linePoints[$label][] = $ary['percent'];
        $totalDataPoints++;
    }
}

$fullURL = Application::link("charts/kaplanMeierCurve.php");
list($url, $params) = REDCapManagement::splitURL($fullURL);

$cohorts = new Cohorts($token, $server, Application::getModule());

echo "<h1>Kaplan-Meier Curve</h1>";
echo "<p class='centered'>Explanation here.</p>";
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

$measurementTypes = ["Publications", "Grants", "Both"];
echo "<p class='centered skinnymargins'>Criteria for Activity: <select name='measType'>";
foreach ($measurementTypes as $measurementType) {
    $sel = "";
    if ($measurementType == $measType) {
        $sel = " selected";
    }
    echo "<option value='$measurementType'$sel>$measurementType</option>";
}
echo "</select></p>";

echo "<p class='centered skinnymargins'>Timespan of Inactivity Permitted in Months <input style='width: 75px;' type='number' name='activityDelay' value='$activityDelay'></p>";
echo "<p class='centered skinnymargins'>Count K&rarr;R Conversion as Full Lifespan <input type='checkbox' name='k2r' $k2rStatus></p>";
echo "<p class='centered skinnymargins'><button>Re-Configure</button></p>";
echo "</form>";

if ($_GET['activityDelay']) {
    if ($totalDataPoints > 0) {
        echo "<canvas class='kaplanMeier' id='lineChart' width='800' height='600' style='width: 800px !important; height: 600px !important;'></canvas>";
        $link = Application::link("js/Chart.min.js");
        $projectTitle = Application::getProjectTitle();
        $datasets = [];
        $i = 0;
        foreach ($linePoints as $label => $linePointValues) {
            if ($n[$label] > 0) {
                $datasets[] = [
                    "label" => $label." (n=".$n[$label].")",
                    "data" => $linePointValues,
                    "fill" => false,
                    "borderColor" => $colors[$i % count($colors)],
                    "backgroundColor" => $colors[$i % count($colors)],
                    "stepped" => true,
                ];
                $i++;
            }
        }
        $labelsJSON = json_encode($labels);
        $datasetsJSON = json_encode($datasets);

        echo "<script src='$link'></script>";
        echo "<script>
let ctx = document.getElementById('lineChart').getContext('2d');
let data = { labels: $labelsJSON, datasets: $datasetsJSON };
const config = {
    type: 'line',
    data: data,
    options: {
        interaction: {
            intersect: false,
            axis: 'x'
        },
        plugins: {
            title: {
                display: true,
                text: (ctx) => '$projectTitle',
            }
        },
        scales: {
            y: {
                title: 'Percent Active',
                beginAtZero: true
            }
        }
    }
};
var lineChart = new Chart(ctx, config);
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

function getResourceRecords($idx, $resources, $recordPool) {
    if ($idx == "all") {
        return $recordPool;
    }
    $records = [];
    foreach ($recordPool as $recordId) {
        if (isset($resources[$recordId]) && in_array($idx, $resources[$recordId])) {
            $records[] = $recordId;
        }
    }
    return $records;
}