<?php

use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\DateManagement;
use \Vanderbilt\CareerDevLibrary\URLManagement;

require_once(__DIR__."/../small_base.php");
require_once(__DIR__."/../classes/Autoload.php");

$title = "Make a Table of Grants";
if (!isset($_GET['cohort']) || !$_GET['cohort']) {
    if (isset($_GET['NOAUTH'])) {
        die("Improper access!");
    }
    require_once(__DIR__."/../charts/baseWeb.php");

    $cohorts = new Cohorts($token, $server, Application::getModule());
    $thisLink = Application::link("this");
    $thisUrl = URLManagement::getPage($thisLink);
    echo "<h1>$title</h1>";
    echo "<form method='GET' action='$thisUrl'>";
    echo URLManagement::getParametersAsHiddenInputs($thisLink, ['start', 'end', 'cohort']);
    echo "<p class='centered'><label for='start'>Budget Start Date (optional):</label> <input type='date' name='start' /><br/>";
    echo "<label for='end'>Budget End Date (optional):</label> <input type='date' name='end' /></p>";
    echo "<p class='centered'><input type='radio' name='range' id='start' value='start' checked /><label for='start'> Grant start date must be within this range</label> <input type='radio' name='range' id='within' value='within' checked /><label for='within'> Grant start <strong>and</strong> end date must be within this range</label></p>";
    echo "<p class='centered'>".$cohorts->makeCohortSelect("", "", TRUE)."</p>";
    echo "<p class='centered'><button>Configure</button></p>";
    echo "</form>";

    exit;
}

$range = Sanitizer::sanitize($_GET['range'] ?? "");
$oneYear = 365 * 24 * 3600;
$startDate = Sanitizer::sanitizeDate($_GET['start']) ?: date("Y-m-d", 0);
$endDate = Sanitizer::sanitizeDate($_GET['end']) ?: date("Y-m-d", time() + 10 * $oneYear);
$startTs = strtotime($startDate);
$endTs = strtotime($endDate);
$dates = "";
$rangeText = "";
if ($_GET['start']) {
    if ($_GET['end']) {
        $dates = DateManagement::YMD2MDY($startDate)." - ".DateManagement::YMD2MDY($endDate);
    } else {
        $dates = "After ".DateManagement::YMD2MDY($startDate);
    }
    if ($range == "within") {
        $rangeText = "(Start &amp; End Dates within Range)";
    } else {
        $rangeText = "(Start Date within Range)";
    }
}

if ($_GET['cohort'] !== "all") {
    $cohort = Sanitizer::sanitizeCohort($_GET['cohort'], $pid);
    if ($cohort) {
        $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
    } else {
        $records = Download::recordIds($token, $server);
    }
} else {
    $cohort = "";
    $records = Download::recordIds($token, $server);
}
$metadata = Download::metadata($token, $server);
$grantFields = REDCapManagement::getAllGrantFields($metadata);
$grantFields[] = "summary_calculate_to_import";
$names = Download::names($token, $server);

$headers = [
    "Name",
    "Project Number",
    "Activity Code",
    "Total Budget",
    "Direct Budget",
    "Budget Start",
    "Budget End",
    "PIs",
    "Role on Grant",
    "Sponsor / Funder",
    "Project Title",
    "Project Start",
    "Project End",
];

$ary = [$headers];
$grantType = "deduped";
foreach ($records as $recordId) {
    $redcapData = Download::fieldsForRecords($token, $server, $grantFields, [$recordId]);
    $grants = new Grants($token, $server, $metadata);
    $grants->setRows($redcapData);
    if ($_GET['start']) {
        $grants->compileGrants("Conversion", $startTs, $endTs);
    } else {
        $grants->compileGrants();
    }
    $grantAry = $grants->getGrants($grantType);
    foreach ($grantAry as $grant) {
        $start = $grant->getVariable("start");
        $end = $grant->getVariable("end");
        $grantTs = $start ? strtotime($start) : FALSE;
        $grantEndTs = $end ? strtotime($end) : FALSE;
        $awardNo = $grant->getBaseNumber();
        if (
            $grantTs
            && ($grantTs >= $startTs)
            && ($grantTs <= $endTs)
            && (
                ($range == "start")
                || (
                    ($range == "within")
                    && $grantEndTs
                    && ($grantEndTs >= $startTs)
                    && ($grantEndTs <= $endTs)
                )
                || !$grantEndTs
            )
        ) {
            $row = [];
            $row[] = $names[$recordId] ?? "";
            $row[] = $awardNo;
            $row[] = Grant::getActivityCode($awardNo);
            $row[] = $grant->getVariable("total_budget") ? REDCapManagement::prettyMoney($grant->getVariable("total_budget")) : "";
            $row[] = $grant->getVariable("direct_budget") ? REDCapManagement::prettyMoney($grant->getVariable("direct_budget")) : "";
            $row[] = DateManagement::YMD2MDY($grant->getVariable("start"));
            $row[] = DateManagement::YMD2MDY($grant->getVariable("end"));
            $row[] = implode("; ", $grant->getVariable("pis"));
            $row[] = $grant->getVariable("role");
            $row[] = $grant->getVariable("sponsor");
            $row[] = $grant->getVariable("title");
            $row[] = DateManagement::YMD2MDY($grant->getVariable("project_start"));
            $row[] = DateManagement::YMD2MDY($grant->getVariable("project_end"));
            $ary[] = $row;
        }
    }
}

if (isset($_GET['csv'])) {
    if (isset($_GET['NOAUTH'])) {
        die("Improper access!");
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="grants.csv"');
    $fp = fopen("php://output", "w");
    foreach ($ary as $line) {
        fputcsv($fp, $line);
    }
    fclose($fp);
} else if (isset($_GET['json'])) {
    $myToken = Application::getSetting("grant_table_token", $pid);
    $data = [];
    # The token protects the NOAUTH from nefarious access
    if ($myToken && ($myToken == $_GET['json'])) {
        for ($i = 1; $i < count($ary); $i++) {
            $dataRow = [];
            $row = $ary[$i];
            for ($j = 0; $j < count($headers); $j++) {
                $header = $headers[$j];
                $dataRow[$header] = REDCapManagement::clearUnicode($row[$j]);
            }
            $data[] = $dataRow;
        }
    }
    echo json_encode($data);
} else {
    if (isset($_GET['NOAUTH'])) {
        die("Improper access!");
    }
    require_once(__DIR__."/../charts/baseWeb.php");

    # if this is used widely, a reset token feature might be needed to enhance security
    $myToken = Application::getSetting("grant_table_token", $pid);
    if (!$myToken) {
        $myToken = REDCapManagement::makeHash(16);
        Application::saveSetting("grant_table_token", $pid);
    }

    $thisLink = $_SERVER['REQUEST_URI'] ?? Application::link("this");
    $headers = $ary[0];
    echo "<h1>$title</h1>";
    if ($cohort) {
        echo "<h2>Cohort $cohort</h2>";
    }
    echo $dates ? "<p class='centered'>$dates $rangeText</p>" : "";
    echo "<p class='centered'><a href='$thisLink&csv'>Download as CSV</a><br/>";
    echo "<a href='$thisLink&json=$myToken&NOAUTH'>Access as a JSON (Dynamically Updated)</a></p>";
    echo "<p class='centered max-width'>Note: Budget dates are the dates that we have financial data for. Project dates are the prospective dates of the entire project.</p>";
    echo "<table class='bordered'>";
    echo "<thead><tr>";
    foreach ($headers as $header) {
        if (in_array($header,  ["Budget Start", "Project Start"])) {
            $header = str_replace("Start", "Dates", $header);
            echo "<th style='position: sticky; top: 0; background-color: #d4d4eb; width: 125px;'>$header</th>";
        } else if (in_array($header,  ["Budget End", "Project End"])) {
        } else {
            echo "<th style='position: sticky; top: 0; background-color: #d4d4eb;'>$header</th>";
        }
    }
    echo "</tr></thead><tbody>";
    for ($i = 1; $i < count($ary); $i++) {
        $line = $ary[$i];
        echo "<tr>";
        for ($j = 0; $j < count($line); $j++) {
            $item = $line[$j];
            if ($j == 0) {
                echo "<th>$item</th>";
            } else if (($j == 5) || ($j == 11)) {
                echo "<td>$item - {$line[$j + 1]}</td>";
                $j++;
            } else {
                echo "<td>$item</td>";
            }
        }
        echo "</tr>";
    }
    echo "</tbody></table>";
}