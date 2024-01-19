<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\MoneyMeasurement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Dashboard;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Stats;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
$dashboard = new Dashboard($pid);
require_once(dirname(__FILE__)."/".$dashboard->getTarget().".php");

$cohort = Sanitizer::sanitizeCohort($_GET['cohort'] ?? "");
$grantType = Grants::areFlagsOn($pid) ? "" : Sanitizer::sanitize($_GET['grantType'] ?? "prior");

$headers = [];
$measurements = [];

if (Grants::areFlagsOn($pid)) {
    $metadata = Download::metadataByPid($pid);
    $fields = REDCapManagement::getAllGrantFields($metadata);
} else if ($grantType == "prior") {
    $fields = CareerDev::$summaryFields;
} else {
    $metadata = Download::metadataByPid($pid);
    $fields = REDCapManagement::getAllGrantFields($metadata);
}
if ($cohort) {
    $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else {
    $records = Download::recordIdsByPid($pid);
}

$totals = [];
$totalBudget = 0;
foreach ($records as $recordId) {
    $rows = Download::fieldsForRecordsByPid($pid, $fields, [$recordId]);
	$grants = new Grants($token, $server, "empty");
	$grants->setRows($rows);
    $grants->compileGrants();
    $grantAry = Grants::areFlagsOn($pid) ? $grants->getGrants("flagged") : $grants->getGrants($grantType);
    foreach ($grantAry as $grant) {
		$type = $grant->getVariable("type");
		if (!isset($totals[$type])) {
			$totals[$type] = [];
		}
		$budget = $grant->getVariable("total_budget");
		if ($budget) {
			$totals[$type][] = $budget;
			$totalBudget += $budget;
		}
	}
}


if (!empty($totals)) {
    if (Grants::areFlagsOn($pid)) {
        $headers[] = "Budgets for Flagged Grants";
    } else if ($grantType == "prior") {
        $headers[] = "Budgets for Career-Defining Grants";
    } else if ($grantType == "deduped") {
        $headers[] = "Budgets for All Grants";
    } else if ($grantType == "all_pis") {
        $headers[] = "Budgets for PI/Co-PI Grants";
    } else {
        $headers[] = "Grant Budgets";
    }
    if ($cohort) {
        $headers[] = "For Cohort " . $cohort;
    }
    foreach ($totals as $type => $budgets) {
        $stats = new Stats($budgets);
		$measurements["$type Total Grant Budgets"] = new MoneyMeasurement(array_sum($budgets), $totalBudget);
		$measurements["$type Grant Mean Budgets"] = new MoneyMeasurement($stats->mean());
		$measurements["$type Grant Median Budgets"] = new MoneyMeasurement($stats->median());
		$measurements["$type Grant Q1 Budgets"] = new MoneyMeasurement($stats->getQuartile(1));
		$measurements["$type Grant Q3 Budgets"] = new MoneyMeasurement($stats->getQuartile(3));
	}

	echo $dashboard->makeHTML($headers, $measurements, [], $cohort, 5, $grantType);
} else {
	$headers[] = "Grant Budgets";
	echo $dashboard->makeHTML($headers, $measurements, [], $cohort, 4, $grantType);

}
