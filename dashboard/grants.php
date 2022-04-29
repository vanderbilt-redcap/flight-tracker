<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Measurement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Dashboard;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
$dashboard = new Dashboard($pid);
require_once(dirname(__FILE__)."/".$dashboard->getTarget().".php");

if (isset($_GET['cohort'])) {
    $cohort = Sanitizer::sanitizeCohort($_GET['cohort']);
} else {
    $cohort = "";
}

$headers = [];
$measurements = [];

$metadata = Download::metadata($token, $server);
$fields = array_unique(array_merge(CareerDev::$summaryFields, ["identifier_left_date", "identifier_institution"]));
$indexedRedcapData = Download::getIndexedRedcapData($token, $server, $fields, $cohort, Application::getModule());
$names = Download::names($token, $server);

$totals = [];
$totalGrants = 0;
$years = [7, 10, 15];
$conversions = [];
$convertedStatuses = ["Converted while not on K", "Converted while on K"];
foreach ($indexedRedcapData as $recordId => $rows) {
    $name = $names[$recordId];
    $grants = new Grants($token, $server, "empty");
    $grants->setRows($rows);
    foreach ($grants->getGrants("prior") as $grant) {
        $type = $grant->getVariable("type");
        if (!isset($totals[$type])) {
            $totals[$type] = 0;
        }
        $totals[$type]++;
        $totalGrants++;
    }

    $scholar = new Scholar($token, $server, $metadata, $pid);
    $scholar->setRows($rows);
    $conversionStatus = $scholar->isConverted(TRUE, TRUE);
    if (in_array($conversionStatus, $convertedStatuses) || (!$scholar->onK(FALSE, TRUE) && !$scholar->hasLeftInstitution())) {
        foreach ($years as $yearspan) {
            if (!isset($conversions[$yearspan])) {
                $conversions[$yearspan] = ["numer" => [], "denom" => []];
            }
            $year = date("Y") - $yearspan;
            $monthDay = date("-m-d");
            if (($monthDay == "-02-29") && ($year % 4 == 0)) {
                $monthDay = "-03-01";
            }
            $ts = strtotime($year.$monthDay);
            if ($scholar->startedKOnOrAfterTs($ts)) {
                if ($conversionStatus != "Not Eligible") {
                    if (in_array($conversionStatus, $convertedStatuses)) {
                        $conversions[$yearspan]["numer"][] = $recordId;
                    }
                    $conversions[$yearspan]["denom"][] = $recordId;
                }
            }
        }
    }
}

foreach ($conversions as $yearspan => $recordQueues) {
    $meas = new Measurement(count($recordQueues["numer"]), count($recordQueues["denom"]));;
    $meas->setPercentage(TRUE);
    $numer = [];
    $denom = [];
    foreach ($recordQueues["numer"] as $recordId) {
        $numer[] = Links::makeSummaryLink($pid, $recordId, $event_id, $names[$recordId]);
    }
    foreach ($recordQueues["denom"] as $recordId) {
        $denom[] = Links::makeSummaryLink($pid, $recordId, $event_id, $names[$recordId]);
    }
    $meas->setNames($numer, $denom);
    $measurements["K-to-R Conversions over Last $yearspan Years"] = $meas;
}

$headers[] = "Grants";
if ($cohort) {
    $headers[] = "For Cohort " . $cohort;
}

$measurements["Total Number of Career-Defining Grants"] = new Measurement($totalGrants);
foreach ($totals as $type => $total) {
    $measurements["$type Grants"] = new Measurement($total, $totalGrants);
}

echo $dashboard->makeHTML($headers, $measurements, [], $cohort);
