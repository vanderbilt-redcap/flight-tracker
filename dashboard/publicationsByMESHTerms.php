<?php

use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Measurement;
use \Vanderbilt\CareerDevLibrary\DateMeasurement;
use \Vanderbilt\CareerDevLibrary\MoneyMeasurement;
use \Vanderbilt\CareerDevLibrary\ObservedMeasurement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/".\Vanderbilt\FlightTrackerExternalModule\getTarget().".php");

$headers = array();
$measurements = array();

array_push($headers, "Publications by MESH Terms<br>(Confirmed Original Research Only)");
if ($_GET['cohort']) {
	array_push($headers, "For Cohort ".$_GET['cohort']);
} 

$metadata = Download::metadata($token, $server);
$indexedRedcapData = \Vanderbilt\FlightTrackerExternalModule\getIndexedRedcapData($token, $server, array_merge(CareerDev::$smallCitationFields, array("citation_mesh_terms")), $_GET['cohort'], $metadata);

$numConfirmedPubs = 0;
$numForMESHTerms = array();
$ts = time();
foreach ($indexedRedcapData as $recordId => $rows) {
	$pubs = new Publications($token, $server, $metadata);
	$pubs->setRows($rows);
	$goodCitations = $pubs->getCitationCollection("Included");
	if ($goodCitations) {
		$confirmedCount = $goodCitations->getCount();
		$numConfirmedPubs += $confirmedCount;
		foreach ($goodCitations->getCitations() as $citation) {
			if ($citation->getCategory() == "Original Research") {
				$meshTerms = $citation->getMESHTerms();
				foreach ($meshTerms as $meshTerm) {
					if (!isset($numForMESHTerms[$meshTerm])) {
						$numForMESHTerms[$meshTerm] = 0;
					}
	
					$numForMESHTerms[$meshTerm]++;
				}
			}
		}
	}
}

arsort($numForMESHTerms);

foreach ($numForMESHTerms as $meshTerm => $cnt) {
	$measurements[$meshTerm] = new ObservedMeasurement($cnt, $numConfirmedPubs);
}

echo makeHTML($headers, $measurements, array(), $_GET['cohort'], $metadata);
