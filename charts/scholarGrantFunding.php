<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\LineGraph;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Grants;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$allRecords = Download::recordIds($token, $server);
if (isset($_GET['record'])) {
    $record = Sanitizer::getSanitizedRecord($_GET['record'], $allRecords);
    if ($record) {
        $records = [$record];
    } else {
        $records = [];
    }
} else if (count($allRecords) > 0) {
    $records = [$allRecords[0]];
} else {
    $records = [];
}
$metadata = Download::metadata($token, $server);
$grantFields = REDCapManagement::getMinimalGrantFields($metadata);
if (!in_array("record_id", $grantFields)) {
    $grantFields[] = "record_id";
}

$budgetField = "total_budget";
$dollarsByYear = [];
foreach ($records as $recordId) {
    $redcapData = Download::fieldsForRecords($token, $server, $grantFields, [$recordId]);
    $grants = new Grants($token, $server, $metadata);
    $grants->setRows($redcapData);
    $grants->compileGrants();
    if (Grants::areFlagsOn($pid)) {
        $grantAry = $grants->getGrants("flagged");
    } else {
        $grantAry = $grants->getGrants("all_pis");
    }
    foreach ($grantAry as $grant) {
        $budget = $grant->getVariable($budgetField);
        $budget = (int) str_replace("$", "", $budget);
        if ($budget) {
            $startTs = strtotime($grant->getVariable("start"));
            $endDate = $grant->getVariable("end");
            $endTs = $endDate ? strtotime($endDate) : time();
            $totalTimespan = $endTs - $startTs;
            $startYear = (int) date("Y", $startTs);
            $endYear = (int) date("Y", $endTs);
            if ($startYear && ($startYear == $endYear)) {
                if (!isset($dollarsByYear[$startYear])) {
                    $dollarsByYear[$startYear] = 0;
                }
                $dollarsByYear[$startYear] += $budget;
            } else if ($startYear) {
                $startYearEndTs = strtotime(($startYear + 1)."-01-01");
                $endYearStartTs = strtotime("$endYear-01-01");
                $secsInStartYear = $startYearEndTs - $startTs;
                $secsInEndYear = $endTs - $endYearStartTs;
                $startYearBudget = round($secsInStartYear * $budget / $totalTimespan);
                $endYearBudget = round($secsInEndYear * $budget / $totalTimespan);
                if (!isset($dollarsByYear[$startYear])) {
                    $dollarsByYear[$startYear] = 0;
                }
                $dollarsByYear[$startYear] += $startYearBudget;
                if (!isset($dollarsByYear[$endYear])) {
                    $dollarsByYear[$endYear] = 0;
                }
                $dollarsByYear[$endYear] += $endYearBudget;
                if ($endYear - $startYear > 1) {
                    # spans 3+ years
                    for ($year = $startYear + 1; $year <= $endYear - 1; $year++) {
                        $yearStartTs = strtotime($year."-01-01");
                        $yearEndTs = strtotime(($year + 1)."-01-01");
                        $secsInYear = $yearEndTs - $yearStartTs;
                        $yearBudget = round($secsInYear * $budget / $totalTimespan);
                        if (!isset($dollarsByYear[$year])) {
                            $dollarsByYear[$year] = 0;
                        }
                        $dollarsByYear[$year] += $yearBudget;
                    }
                }
            }
        }
    }
}
if (!empty($dollarsByYear)) {
    ksort($dollarsByYear);
    $years = array_keys($dollarsByYear);
    for ($year = $years[0]; $year <= $years[count($years) - 1]; $year++) {
        if (!isset($dollarsByYear[$year])) {
            $dollarsByYear[$year] = 0;
        }
    }
    ksort($dollarsByYear);
}

$cssLink = Application::link("/css/career_dev.css");
echo "<link href='$cssLink' type='text/css' />";
if (empty($dollarsByYear)) {
    echo "<h3>No Grant Funding to Display</h3>";
} else if (count($dollarsByYear) == 1) {
    $year = array_keys($dollarsByYear)[0];
    $dollars = $dollarsByYear[$year];
    echo "<h3>You have one year represented: In $year, you brought in ".REDCapManagement::prettyMoney($dollars)." in grants.</h3>";
} else {
    $graph = new LineGraph(array_values($dollarsByYear), array_keys($dollarsByYear), "scholarDollars_".$pid);
    echo $graph->getImportHTML();
    $graph->setXAxisLabel("Year");
    $graph->setYAxisLabel("Dollars per Year");
    $graph->setColor("#5764ae");
    echo $graph->getHTML(800, 600);
}
