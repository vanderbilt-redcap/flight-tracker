<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\FlightTrackerExternalModule\Measurement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/".\Vanderbilt\FlightTrackerExternalModule\getTarget().".php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Grants.php");

$headers = array();
$measurements = array();

$metadata = Download::metadata($token, $server);
$indexedRedcapData = \Vanderbilt\FlightTrackerExternalModule\getIndexedRedcapData($token, $server, CareerDev::$summaryFields, $_GET['cohort'], $metadata);

$totals = array();
$totalGrants = 0;
foreach ($indexedRedcapData as $recordId => $rows) {
	$grants = new Grants($token, $server, $metadata);
	$grants->setRows($rows);
	foreach ($grants->getGrants("prior") as $grant) {
		$type = $grant->getVariable("type");
		if (!isset($totals[$type])) {
			$totals[$type] = 0;
		}
		$totals[$type]++;
		$totalGrants++;
	} 
}

array_push($headers, "Grants");
if ($_GET['cohort']) {
	array_push($headers, "For Cohort ".$_GET['cohort']);
} 

$measurements["Total Number of Compiled Grants"] = new Measurement($totalGrants);
foreach ($totals as $type => $total) {
	$measurements["$type Grants"] = new Measurement($total, $totalGrants);
}

echo makeHTML($headers, $measurements, array(), $_GET['cohort'], $metadata);
