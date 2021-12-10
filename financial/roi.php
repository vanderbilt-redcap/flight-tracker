<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(APP_PATH_DOCROOT."Classes/System.php");

\System::increaseMaxExecTime(28800);   // 8 hours

if ($_GET['cohort']) {
    $cohort = REDCapManagement::sanitize($_GET['cohort']);
    $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else {
    $cohort = "all";
    $records = Download::records($token, $server);
}
$metadata = Download::metadata($token, $server);
$fields = REDCapManagement::getMinimalGrantFields($metadata);
$grantsDirectDollars = getBlankGrantArray();
$grantsTotalDollars = getBlankGrantArray();
foreach ($records as $recordId) {
    $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
    $grants = new Grants($token, $server, $metadata);
    $grants->setRows($redcapData);
    $grants->compileGrants();
    foreach ($grants->getGrants("all_pis") as $grant) {
        $direct = $grant->getVariable("direct_budget") ?? 0;
        $total = $grant->getVariable("budget") ?? 0;
        $grantClass = $grant->getVariable("type");
        $grantClasses = [
            "Internal K" => ["Internal K"],
            "K12/KL2" => ["K12/KL2"],
            "Individual K" => ["Individual K"],
            "K Equivalent" => ["K Equivalent"],
            "Internal K Sources" => ["Internal K", "K12/KL2"],
            "All K Sources" => ["Internal K", "K12/KL2", "Individual K", "K Equivalent"],
            "Non-K Sources" => "all",
        ];
        foreach ($grantClasses as $className => $classTypes) {
            if (($classTypes == "all") || in_array($grantClass, $classTypes)) {
                if ($direct) {
                    if (!isset($grantsDirectDollars[$className][$recordId])) {
                        $grantsDirectDollars[$className][$recordId] = [];
                    }
                    $grantsDirectDollars[$className][$recordId][] = $direct;
                }
                if ($total) {
                    if (!isset($grantsTotalDollars[$className][$recordId])) {
                        $grantsTotalDollars[$className][$recordId] = [];
                    }
                    $grantsTotalDollars[$className][$recordId][] = $total;
                }
            }
        }
    }
}

$foldIncreaseDirect = getBlankGrantArray();
$foldIncreaseTotal = getBlankGrantArray();
$textDirect = getBlankGrantArray();
$textTotal = getBlankGrantArray();
$totalClassName = "Non-K Sources";
$naValue = "N/A";
foreach (array_keys(getBlankGrantArray()) as $className) {
    if ($className != $totalClassName) {
        $classDirectDollars = sumAmounts($grantsDirectDollars[$className]);
        $classTotalDollars = sumAmounts($grantsTotalDollars[$className]);
        $classDirectCounts = countEntries($grantsDirectDollars[$className]);
        $classTotalCounts = countEntries($grantsTotalDollars[$className]);
        $classDirectRecords = getRecords($grantsDirectDollars[$className]);
        $classTotalRecords = getRecords($grantsTotalDollars[$className]);
        $totalDirectDollars = sumAmounts($grantsDirectDollars[$totalClassName], $classDirectRecords);
        $totalTotalDollars = sumAmounts($grantsTotalDollars[$totalClassName], $classTotalRecords);

        $foldIncreaseTotal[$className] = (($totalTotalDollars > 0) && ($classTotalDollars > 0)) ? $classTotalDollars / $totalTotalDollars : $naValue;
        $foldIncreaseDirect[$className] = (($totalDirectDollars > 0) && ($classDirectDollars > 0)) ? $classDirectDollars / $totalDirectDollars : $naValue;
        $textDirect[$className] = "Based on $classDirectCounts grants across $classDirectRecords scholars.<br/>(" . REDCapManagement::prettyMoney($classDirectDollars) . " / " . REDCapManagement::prettyMoney($totalDirectDollars) . ")";
        $textTotal[$className] = "Based on $classTotalCounts grants across $classTotalRecords scholars.<br/>(" . REDCapManagement::prettyMoney($classTotalDollars) . " / " . REDCapManagement::prettyMoney($totalTotalDollars) . ")";
    }
}

$cohorts = new Cohorts($token, $server, Application::getModule());
$thisLink = Application::link("this");
$onChangeJS = "if ($(this).val()) { window.location.href = \"$thisLink&cohort=\"+$(this).val(); } else { window.location.href = \"$thisLink\"; }";
$html = "";
$html .= "<style>
.finalNumber { font-size: 40px; font-weight: bold; }
</style>";
$html .= "<h1>Financial Return on Investment</h1>";
$html .= "<p class='centered'>Only includes grants in which the scholar is a PI or a Co-PI. To calculate, this page requires that dollar figures be assigned to all relevant Internal K and K12/KL2 grants.</p>";
$html .= "<p class='centered'>".$cohorts->makeCohortSelect($cohort, $onChangeJS, TRUE)."</p>";
$html .= "<table class='centered max-width'>";
foreach (array_keys(getBlankGrantArray()) as $className) {
    if ($className != $totalClassName) {
        $increaseDirect = REDCapManagement::pretty($foldIncreaseDirect[$className], 1);
        $increaseTotal = REDCapManagement::pretty($foldIncreaseTotal[$className], 1);
        $html .= "<tr><td colspan='2'><h3>$className</h3></td></tr>";
        $html .= "<tr>";
        $html .= "<td><h4>Direct Dollars</h4><p class='centered'><span class='finalNumber'>$increaseDirect</span><br/>-fold increase</p><p class='centered smaller'>{$textDirect[$className]}</p></td>";
        $html .= "<td><h4>Total Dollars</h4><p class='centered'><span class='finalNumber'>$increaseTotal</span><br/>-fold increase</p><p class='centered smaller'>{$textTotal[$className]}</p></td>";
        $html .= "</tr>";
    }
}
$html .= "</table>";

function sumAmounts($classAry, $records = "all") {
    $total = 0;
    foreach ($classAry as $recordId => $amounts) {
        if (($records == "all") || in_array($recordId, $records)) {
            $total += array_sum($amounts);
        }
    }
    return $total;
}

function countEntries($classAry) {
    $total = 0;
    foreach (array_values($classAry) as $amounts) {
        $total += count($amounts);
    }
    return $total;
}

function getRecords($classAry) {
    return array_keys($classAry);
}

function getBlankGrantArray() {
    return [
        "Internal K" => [],
        "K12/KL2" => [],
        "Individual K" => [],
        "K Equivalent" => [],
        "Internal K Sources" => [],
        "All K Sources" => [],
        "Non-K Sources" => [],
    ];
}