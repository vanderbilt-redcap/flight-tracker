<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\MoneyMeasurement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Dashboard;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\LineGraph;

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

$indexedRedcapData = Download::getIndexedRedcapData($token, $server, CareerDev::$summaryFields, $cohort, Application::getModule());

$yearTotals = [];
for ($year = date("Y"); $year >= 2001; $year--) {
	$yearTotals[$year] = 0;
}
$totalBudget = 0;
$totals = [];
foreach ($indexedRedcapData as $recordId => $rows) {
	$grants = new Grants($token, $server, "empty");
	$grants->setRows($rows);
	foreach ($grants->getGrants("prior") as $grant) {
		$type = $grant->getVariable("type");
		if (!isset($totals[$type])) {
			$totals[$type] = 0;
		}
		$budget = $grant->getVariable("total_budget");
		$totalBudget += (int) $budget;
		foreach ($yearTotals as $year => $yearTotal) {
			$startTs = strtotime($year."-01-01");
			$endTs = strtotime($year."-12-31 23:59:59");
			$yearTotals[$year] += $grant->getTotalCostsForTimespan($startTs, $endTs);
		}
	}
}

$headers[] = "Grants";
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

echo $dashboard->makeHTML($headers, $measurements, [], $cohort);
echo $graph->getImportHTML();
echo $graph->getHTML(800, 600, TRUE);