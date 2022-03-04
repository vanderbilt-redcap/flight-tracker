<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Measurement;
use \Vanderbilt\CareerDevLibrary\DateMeasurement;
use \Vanderbilt\CareerDevLibrary\MoneyMeasurement;
use \Vanderbilt\CareerDevLibrary\ObservedMeasurement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/".\Vanderbilt\FlightTrackerExternalModule\getTarget().".php");

if (isset($_GET['cohort'])) {
    $cohort = REDCapManagement::sanitizeCohort($_GET['cohort']);
} else {
    $cohort = "";
}

$headers = array();
$measurements = array();

$metadata = Download::metadata($token, $server);
$indexedRedcapData = \Vanderbilt\FlightTrackerExternalModule\getIndexedRedcapData($token, $server, CareerDev::$summaryFields, $cohort, $metadata);

$totals = array();
$totalBudget = 0;
foreach ($indexedRedcapData as $recordId => $rows) {
	$grants = new Grants($token, $server, $metadata);
	$grants->setRows($rows);
	foreach ($grants->getGrants("prior") as $grant) {
		$type = $grant->getVariable("type");
		if (!isset($totals[$type])) {
			$totals[$type] = array();
		}
		$budget = $grant->getVariable("total_budget");
		if ($budget) {
			array_push($totals[$type], $budget);
			$totalBudget += $budget;
		}
	}
}


if (!empty($totals)) {
	array_push($headers, "Grant Budgets");
    if ($cohort) {
        array_push($headers, "For Cohort ".$cohort);
    }
    foreach ($totals as $type => $budgets) {
		$measurements["$type Total Grant Budgets"] = new MoneyMeasurement(array_sum($budgets), $totalBudget);
		$measurements["$type Grant Mean Budgets"] = new MoneyMeasurement(\Vanderbilt\FlightTrackerExternalModule\avg($budgets));
		$measurements["$type Grant Median Budgets"] = new MoneyMeasurement(\Vanderbilt\FlightTrackerExternalModule\getMedian($budgets));
		$measurements["$type Grant Q1 Budgets"] = new MoneyMeasurement(\Vanderbilt\FlightTrackerExternalModule\quartile($budgets, 1));
		$measurements["$type Grant Q3 Budgets"] = new MoneyMeasurement(\Vanderbilt\FlightTrackerExternalModule\quartile($budgets, 3));
	}

	echo makeHTML($headers, $measurements, array(), $cohort, $metadata, 5);
} else {
	array_push($headers, "Grant Budgets");
	echo makeHTML($headers, $measurements, array(), $cohort);

}
