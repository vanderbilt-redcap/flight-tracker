<?php

use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../classes/Grants.php");
require_once(dirname(__FILE__)."/../classes/Grant.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");
require_once(APP_PATH_DOCROOT."Classes/System.php");

\System::increaseMaxExecTime(3600);   // 1 hour
if ($_POST['date'] && REDCapManagement::isDate($_POST['date'])) {
    $ts = strtotime($_POST['date']);
} else {
    $ts = time();
}

$metadata = Download::metadata($token, $server);
$names = Download::names($token, $server);
$choices = REDCapManagement::getChoices($metadata);
$recordsByDept = [];
$records = Download::recordIds($token, $server);
$departments = Download::oneField($token, $server, "summary_primary_dept");
foreach ($records as $recordId) {
    $deptIdx = $departments[$recordId];
    if (isset($choices["summary_primary_dept"][$deptIdx])) {
        $dept = $choices["summary_primary_dept"][$deptIdx];
    } else {
        $dept = "Unspecified";
    }
    if (!isset($recordsByDept[$dept])) {
        $recordsByDept[$dept] = [];
    }
    $recordsByDept[$dept][] = $recordId;
}

$headers = [
    "numFaculty" => "Number of Faculty",
    "numNIHGrants" => "Number of NIH Grants (Active)",
    "dollarsNIHDirect" => "Direct Dollars of NIH Grants (Active)",
    "dollarsNIHTotal" => "Total Dollars of NIH Grants (Active)",
    "numFederalGrants" => "Number of Federal Grants (Active)",
    "dollarsFederalDirect" => "Direct Dollars of Federal Grants (Active)",
    "dollarsFederalTotal" => "Total Dollars of Federal Grants (Active)",
    "numAllGrants" => "Number of All Grants (Active)",
    "dollarsAllDirect" => "Direct Dollars of All Grants (Active)",
    "dollarsAllTotal" => "Total Dollars of All Grants (Active)",
];
$table = [];
foreach ($recordsByDept as $dept => $records) {
    $table[$dept] = [];
    foreach (["NIH", "Federal", "All"] as $type) {
        $table[$dept]['num'.$type.'Grants'] = 0;
        $table[$dept]['dollars'.$type.'Direct'] = 0.0;
        $table[$dept]['dollars'.$type.'Total'] = 0.0;
    }
    $table[$dept]['grantsSeenDirect'] = [];
    $table[$dept]['grantsSeenTotal'] = [];
}
$table['Total'] = [];
 foreach (array_keys($headers) as $key) {
    $table['Total'][$key] = 0;
}
$table["Total"]['grantsSeenDirect']= [];
$table["Total"]['grantsSeenTotal']= [];
$table["Total"]['numFaculty'] = 0;

$figures = [];
$notes = ["No-Cost Extensions are not represented in these data."];
$d = array_values($recordsByDept);
foreach ($recordsByDept as $dept => $records) {
    $table[$dept]['numFaculty'] = count($records);
    $table["Total"]['numFaculty'] += count($records);
    foreach ($records as $recordId) {
        $redcapData = Download::records($token, $server, [$recordId]);
        $grants = new Grants($token, $server, $metadata);
        $grants->setRows($redcapData);
        $grants->compileGrants();
        foreach ($grants->getGrants("all_pis") as $grant) {
            $baseAwardNumber = $grant->getBaseAwardNumber();
            $figureRow = ["Record" => $recordId, "Name" => $names[$recordId], "Grant" => $baseAwardNumber];
            if (($grant->getActiveBudgetAtTime($redcapData, "Total", $ts) > 0) && ($grant->getActiveBudgetAtTime($redcapData, "Direct", $ts) == 0.0)) {
                $notes[] = "No direct budget for Record $recordId ".$baseAwardNumber;
            }
            foreach (["Direct", "Total"] as $type) {
                $dollars = $grant->getActiveBudgetAtTime($redcapData, $type, $ts);
                $figureRow[$type." Active Dollars"] = REDCapManagement::prettyMoney($dollars);
                $is = ["NIH" => $grant->isNIH(), "Federal" => $grant->isFederal(), ];
                foreach ([$dept, "Total"] as $tableRowName) {
                    if (!in_array($baseAwardNumber, $table[$tableRowName]['grantsSeen'.$type])) {
                        if ($dollars > 0) {
                            if ($type == "Total") {    // count only once
                                foreach ($is as $class => $b) {
                                    if ($b) {
                                        $table[$tableRowName]['num'.$class.'Grants']++;
                                    }
                                }
                                $table[$tableRowName]['numAllGrants']++;
                            }
                            foreach ($is as $class => $b) {
                                if ($b) {
                                    $table[$tableRowName]['dollars' . $class . $type] += $dollars;
                                }
                            }
                            $table[$tableRowName]['dollarsAll' . $type] += $dollars;
                        }
                        $table[$tableRowName]['grantsSeen'.$type][] = $baseAwardNumber;
                    }
                }
            }
            $figures[] = $figureRow;
        }
    }
}
$currChosenDate = date("Y-m-d", $ts);
$mdyCurrDate = date("m-d-Y", $ts);
$link = Application::link("financial/activeBudget.php");
echo "<h1>Active Grants in Project at $mdyCurrDate</h1>";
echo "<form action='$link' method='POST'>";
echo "<p class='centered'>Active on <input type='date' name='date' value='$currChosenDate'></p>";
echo "</form>";

if (!empty($notes)) {
    echo "<p class='centered red'>".implode("<br>", $notes)."</p>";
} else {
    echo "<p class='centered'>No errors detected.</p>";
}

echo "<table class='centered max-width bordered'>";
echo "<thead>";
echo "<tr>";
echo "<th>Group</th>";
foreach ($headers as $key => $header) {
    echo "<th>$header</th>";
}
echo "</tr>";
echo "</thead>";
echo "<tbody>";
foreach ($table as $dept => $data) {
    echo "<tr>";
    echo "<th>$dept</th>";
    foreach (array_keys($headers) as $key) {
        if (preg_match("/dollars/i", $key)) {
            echo "<td>" . REDCapManagement::prettyMoney($data[$key]) . "</td>";
        } else {
            echo "<td>" . REDCapManagement::pretty($data[$key]) . "</td>";
        }
    }
    echo "</tr>";
}
echo "</tbody>";
echo "</table>";

if (isset($_GET['test']) || isset($_GET['testTable'])) {
    echo "<br><br><br>";
    echo "<h2>Details...</h2>";
    echo "<table class='min-width centered bordered'>";
    $first = TRUE;
    $figureHeaders = [];
    foreach ($figures as $figureRow) {
        if ($first) {
            $first = FALSE;
            echo "<thead>";
            echo "<tr>";
            $figureHeaders = array_keys($figureRow);
            foreach ($figureHeaders as $header) {
                echo "<th>$header</th>";
            }
            echo "</tr>";
            echo "</thead>";
            echo "<tbody>";
        }
        echo "<tr>";
        foreach ($figureHeaders as $header) {
            echo "<td>{$figureRow[$header]}</td>";
        }
        echo "</tr>";
    }
    echo "</tbody></table>";
}
