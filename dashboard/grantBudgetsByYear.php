<?php

use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Grants;
use Vanderbilt\CareerDevLibrary\MoneyMeasurement;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;
use Vanderbilt\CareerDevLibrary\Sanitizer;
use Vanderbilt\CareerDevLibrary\Dashboard;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\LineGraph;
use Vanderbilt\CareerDevLibrary\REDCapManagement;

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
} elseif ($grantType == "prior") {
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

$yearTotals = [];
for ($year = date("Y"); $year >= 2001; $year--) {
	$yearTotals["$year"] = 0;
}
$totalBudget = 0;
$totals = [];
foreach ($records as $recordId) {
	$rows = Download::fieldsForRecordsByPid($pid, $fields, [$recordId]);
	$grants = new Grants($token, $server, "empty");
	$grants->setRows($rows);
	$grants->compileGrants();
	$grantAry = Grants::areFlagsOn($pid) ? $grants->getGrants("flagged") : $grants->getGrants($grantType);
	foreach ($grantAry as $grant) {
		$type = $grant->getVariable("type");
		if (!isset($totals[$type])) {
			$totals[$type] = 0;
		}
		$budget = $grant->getVariable("total_budget");
		$totalBudget += (int) $budget;
		foreach ($yearTotals as $year => $yearTotal) {
			$startTs = strtotime($year."-01-01");
			$endTs = strtotime($year."-12-31 23:59:59");
			$yearTotals["$year"] += $grant->getTotalCostsForTimespan($startTs, $endTs);
		}
	}
}

if (Grants::areFlagsOn($pid)) {
	$headers[] = "Yearly Budgets for Flagged Grants";
} elseif ($grantType == "prior") {
	$headers[] = "Yearly Budgets for Career-Defining Grants";
} elseif ($grantType == "deduped") {
	$headers[] = "Yearly Budgets for All Grants";
} elseif ($grantType == "all_pis") {
	$headers[] = "Yearly Budgets for PI/Co-PI Grants";
} else {
	$headers[] = "Yearly Budgets for Grants";
}
if ($cohort) {
	$headers[] = "For Cohort " . $cohort;
}

$measurements["Total Budget"] = new MoneyMeasurement($totalBudget);
foreach ($yearTotals as $year => $total) {
	$measurements["Grant Budgets in $year"] = new MoneyMeasurement($total, $totalBudget);
}

ksort($yearTotals);
$graph = new LineGraph(array_values($yearTotals), array_keys($yearTotals), "line_graph");
$graph->setXAxisLabel("Year");
$graph->setYAxisLabel("Dollars per Year");

echo $dashboard->makeHTML($headers, $measurements, [], $cohort, 4, $grantType);
echo $graph->getImportHTML();
echo $graph->getHTML(800, 600, true);
