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

if (isset($_GET['cohort'])) {
    $cohort = Sanitizer::sanitizeCohort($_GET['cohort']);
} else {
    $cohort = "";
}

$headers = [];
$measurements = [];

$fields = CareerDev::$summaryFields;
if (Grants::areFlagsOn($pid)) {
    $metadata = Download::metadata($token, $server);
    $fields = REDCapManagement::getAllGrantFields($metadata);
}
$indexedRedcapData = Download::getIndexedRedcapData($token, $server, $fields, $cohort, Application::getModule());

$totals = [];
$totalBudget = 0;
foreach ($indexedRedcapData as $recordId => $rows) {
	$grants = new Grants($token, $server, "empty");
	$grants->setRows($rows);
    $grantAry = Grants::areFlagsOn($pid) ? $grants->getGrants("flagged") : $grants->getGrants("prior");
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
	$headers[] = "Grant Budgets";
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

	echo $dashboard->makeHTML($headers, $measurements, [], $cohort, 5);
} else {
	$headers[] = "Grant Budgets";
	echo $dashboard->makeHTML($headers, $measurements, [], $cohort);

}
