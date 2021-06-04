<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Measurement;
use \Vanderbilt\CareerDevLibrary\DateMeasurement;
use \Vanderbilt\CareerDevLibrary\MoneyMeasurement;
use \Vanderbilt\CareerDevLibrary\ObservedMeasurement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/".\Vanderbilt\FlightTrackerExternalModule\getTarget().".php");

$headers = array();
$measurements = array();

$metadata = Download::metadata($token, $server);
$indexedRedcapData = \Vanderbilt\FlightTrackerExternalModule\getIndexedRedcapData($token, $server, CareerDev::$summaryFields, $_GET['cohort'], $metadata);

$yearTotals = array();
for ($year = date("Y"); $year >= 2001; $year--) {
	$yearTotals[$year] = 0;
}
$totalBudget = 0;
foreach ($indexedRedcapData as $recordId => $rows) {
	$grants = new Grants($token, $server, $metadata);
	$grants->setRows($rows);
	foreach ($grants->getGrants("prior") as $grant) {
		$type = $grant->getVariable("type");
		if (!isset($totals[$type])) {
			$totals[$type] = 0;
		}
		$budget = $grant->getVariable("total_budget");
		$totalBudget += $budget;
		foreach ($yearTotals as $year => $yearTotal) {
			$startTs = strtotime($year."-01-01");
			$endTs = strtotime($year."-12-31 23:59:59");
			$yearTotals[$year] += $grant->getTotalCostsForTimespan($startTs, $endTs);
		}
	}
}

array_push($headers, "Grants");
if ($_GET['cohort']) {
	array_push($headers, "For Cohort ".$_GET['cohort']);
} 

$measurements["Total Budget"] = new MoneyMeasurement($totalBudget);
foreach ($yearTotals as $year => $total) {
	$measurements["Grant Budgets in $year"] = new MoneyMeasurement($total, $totalBudget);
}

echo makeHTML($headers, $measurements, array(), $_GET['cohort'], $metadata);
