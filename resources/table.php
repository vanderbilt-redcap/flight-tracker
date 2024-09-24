<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\BarChart;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$resourceChoices = DataDictionaryManagement::getChoicesForField($pid, "resources_resource");

if (isset($_GET['cohort'])) {
    $cohort = Sanitizer::sanitizeCohort($_GET['cohort']);
    $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else {
    $cohort = "";
    $records = Download::recordIds($token, $server);
}
$names = Download::names($token, $server);
$resources = Download::oneFieldWithInstances($token, $server, "resources_resource");

$headers = "";
if (Application::isVanderbilt()) {
    $resourceAffiliations = [
        "Edge for Scholars" => [
            "Shut Up and Write",
            "Shut up and Write",
            "Manuscript Sprint",
            "Science of Writing",
            "EFS Online Grant-Writing Workshop",
            "Grants Repository - Have Access",
            "Grants Repository - Shared",
            "Grants Repository - Accessed After 1/1/2021",
            "Grants Repository - Accessed",
            "Grant Pacing",
            "Grant Pacing Workshop",
            "Edge Reviews",
            "Edge Seminars (Monthly)",
            "Monthly Newman Series",
        ],
        "VICTR" => [
            "Studio",
            "Community Engage Studio",
            "Pathways Studio",
            "Pilot Funding",
        ],
    ];

    $reverseResourceChoices = REDCapManagement::reverseArray($resourceChoices);
    $newResourceChoices = [];
    $headers .= "<tr>";
    $headers .= "<td></td>";
    $resourcesInOrder = [];
    foreach ($resourceAffiliations as $group => $resourceNames) {
        $numInGroup = 0;
        foreach ($resourceNames as $resource) {
            $idx = $reverseResourceChoices[$resource] ?? "";
            if ($idx !== "") {
                $newResourceChoices[$idx] = $resource;
                $resourcesInOrder[] = $idx;
                $numInGroup++;
            }
        }
        if ($numInGroup > 0) {
            $headers .= "<th class='blue centered blackBorder' colspan='$numInGroup'>$group</th>";
        }
    }
    if (count($resourceChoices) > count($newResourceChoices)) {
        $numInGroup = count($resourceChoices) - count($newResourceChoices);
        $group = "Others";
        foreach ($resourceChoices as $idx => $label) {
            if (!isset($newResourceChoices[$idx])) {
                $newResourceChoices[$idx] = $label;
                $resourcesInOrder[] = $idx;
            }
        }
        if ($numInGroup > 0) {
            $headers .= "<th class='blue centered blackBorder' colspan='$numInGroup'>$group</th>";
        }
    }
    $headers .= "</tr>";

    $resourceChoices = $newResourceChoices;
} else {
    $resourcesInOrder = array_keys($resourceChoices);
}
$headers .= "<tr>";
$headers .= "<td></td>";
foreach ($resourcesInOrder as $idx) {
    $label = $resourceChoices[$idx];
    $headers .= "<th class='centered light_grey blackBorder'>$label</th>";
}
$headers .= "</tr>";

$totalResourcesUsed = [];
$distinctResourcesUsed = [];
foreach ($records as $recordId) {
    $recordResources = $resources[$recordId] ?? [];
    $total = 0;
    $distinct = [];
    foreach ($resourcesInOrder as $idx) {
        $label = $resourceChoices[$idx];
        $dates = [];
        foreach ($recordResources as $instance => $resourceIdx) {
            if ($idx == $resourceIdx) {
                $total++;
                if (!in_array($idx, $distinct)) {
                    $distinct[] = $idx;
                }
            }
        }
    }
    $numDistinct = count($distinct);
    if (!isset($totalResourcesUsed[$total])) {
        $totalResourcesUsed[$total] = 0;
    }
    if (!isset($distinctResourcesUsed[$numDistinct])) {
        $distinctResourcesUsed[$numDistinct] = 0;
    }
    $totalResourcesUsed[$total]++;
    $distinctResourcesUsed[$numDistinct]++;
}
if (!empty($totalResourcesUsed) && !empty($distinctResourcesUsed)) {
    for ($i = 0; $i <= max(array_keys($totalResourcesUsed)); $i++) {
        if (!isset($totalResourcesUsed[$i])) {
            $totalResourcesUsed[$i] = 0;
        }
    }
    for ($i = 0; $i <= max(array_keys($distinctResourcesUsed)); $i++) {
        if (!isset($distinctResourcesUsed[$i])) {
            $distinctResourcesUsed[$i] = 0;
        }
    }
}
ksort($totalResourcesUsed, SORT_NUMERIC);
ksort($distinctResourcesUsed, SORT_NUMERIC);

$totalBarChart = new BarChart(array_values($totalResourcesUsed), array_keys($totalResourcesUsed),  "total_resources");
$totalBarChart->showLegend(FALSE);
$totalBarChart->setXAxisLabel("Number of Total Resources Used");
$totalBarChart->setYAxisLabel("Number of Scholars");
$distinctBarChart = new BarChart(array_values($distinctResourcesUsed), array_keys($distinctResourcesUsed), "distinct_resources");
$distinctBarChart->showLegend(FALSE);
$distinctBarChart->setXAxisLabel("Number of Distinct Resources Used");
$distinctBarChart->setYAxisLabel("Number of Scholars");


echo $totalBarChart->getImportHTML();
echo "<h1>Scholar Resource Use in Career Development</h1>";
$cohorts = new Cohorts($token, $server, Application::getModule());
$thisLink = Application::link("this");
echo "<p class='centered'>".$cohorts->makeCohortSelect($cohort, "location.href = \"$thisLink&cohort=\"+$(this).val();")."</p>";

$width = 800;
$height = 500;
echo "<h2>Number of Total Resources Used by Scholars</h2>";
echo $totalBarChart->getHTML($width, $height);
echo "<h2>Number of Distinct Resources Used by Scholars</h2>";
echo $distinctBarChart->getHTML($width, $height);

echo "<h2>Table of Resources Used by Scholars</h2>";
echo "<table class='centered bigShadow' style='max-width: 1200px;'>";
echo "<thead>$headers</thead>";
echo "<tbody>";
$numChecks = [];
foreach ($records as $recordId) {
    $name = Links::makeRecordHomeLink($pid, $recordId, $names[$recordId]) ?? "";
    echo "<tr>";
    echo "<th class='light_grey blackBorder left-align'>$name</th>";
    $recordResources = $resources[$recordId] ?? [];
    $numChecks[$recordId] = 0;
    foreach ($resourcesInOrder as $idx) {
        $label = $resourceChoices[$idx];
        $checks = [];
        foreach ($recordResources as $instance => $resourceIdx) {
            if ($idx == $resourceIdx) {
                $checks[] = "<span class='bolded greentext greenTextShadow'>&check;</span>";
                $numChecks[$recordId]++;
            }
        }
        if (empty($checks)) {
            echo "<td class='light_grey centered greyTopAndBottom'>&nbsp;</td>";
        } else {
            echo "<td class='centered really_light_green bigger blackBorder' title='$label'>".implode(" ", $checks)."</td>";
        }
    }
    echo "</tr>";
}
echo "</tbody></table>";
echo "<br/><br/>";

arsort($numChecks);

echo "<h2>Most Active Scholars</h2>";
echo "<table class='centered bigShadow bordered' style='max-width: 800px;'>";
echo "<thead><tr>";
echo "<th>Name</th>";
echo "<th>Resources Used</th>";
echo "</tr></thead>";
echo "<tbody>";
foreach ($numChecks as $recordId => $cnt) {
    if ($cnt > 0) {
        $name = Links::makeRecordHomeLink($pid, $recordId, $names[$recordId]) ?? "";
        echo "<tr>";
        echo "<th>$name</th>";
        echo "<td>$cnt</td>";
        echo "</tr>";
    }
}
echo "</tbody>";
echo "</table>";

echo "<br/>";