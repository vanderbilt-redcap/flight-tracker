<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Cohorts;

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

if (Application::isVanderbilt()) {
    $resourceAffiliations = [
        "Edge for Scholars" => [
            "Shut Up and Write",
            "Shut up and Write",
            "Manuscript Sprint",
            "Science of Writing",
            "Grants Repository - Have Access",
            "Grants Repository - Shared",
            "Grants Repository - Accessed After 1/1/2021",
            "Grants Repository - Accessed",
            "Grant Pacing",
            "Grant Pacing Workshop",
            "Edge Reviews",
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
    $headers = "<tr>";
    $headers .= "<td></td>";
    foreach ($resourceAffiliations as $group => $resourceNames) {
        $numInGroup = 0;
        foreach ($resourceNames as $resource) {
            $idx = $reverseResourceChoices[$resource] ?? "";
            if ($idx !== "") {
                $newResourceChoices[$idx] = $resource;
                $numInGroup++;
            }
        }
        $headers .= "<th class='blue centered blackBorder' colspan='$numInGroup'>$group</th>";
    }
    if (count($resourceChoices) > count($newResourceChoices)) {
        $numInGroup = count($resourceChoices) - count($newResourceChoices);
        $group = "Others";
        foreach ($resourceChoices as $idx => $label) {
            if (!isset($newResourceChoices[$idx])) {
                $newResourceChoices[$idx] = $label;
            }
        }
        if ($numInGroup > 0) {
            $headers .= "<th class='blue centered blackBorder' colspan='$numInGroup'>$group</th>";
        }
    }
    $headers .= "</tr>";

    $resourceChoices = $newResourceChoices;
}
$headers .= "<tr>";
$headers .= "<td></td>";
foreach ($resourceChoices as $idx => $label) {
    $headers .= "<th class='centered light_grey blackBorder'>$label</th>";
}
$headers .= "</tr>";

echo "<h1>Scholar Resource Use in Career Development</h1>";
$cohorts = new Cohorts($token, $server, Application::getModule());
$thisLink = Application::link("this");
echo "<p class='centered'>".$cohorts->makeCohortSelect($cohort, "location.href = \"$thisLink&cohort=\"+$(this).val();")."</p>";

echo "<table class='centered bigShadow' style='max-width: 1200px;'>";
echo "<thead>$headers</thead>";
echo "<tbody>";
foreach ($records as $recordId) {
    $name = $names[$recordId] ?? "";
    echo "<tr>";
    echo "<th class='light_grey blackBorder left-align'>$name</th>";
    $recordResources = $resources[$recordId] ?? [];
    foreach ($resourceChoices as $idx => $label) {
        $checks = [];
        foreach ($recordResources as $instance => $resourceIdx) {
            if ($idx == $resourceIdx) {
                $checks[] = "<span class='bolded greentext greenTextShadow'>&check;</span>";
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
echo "<br/>";