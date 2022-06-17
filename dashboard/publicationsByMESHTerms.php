<?php

use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\ObservedMeasurement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Dashboard;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
$dashboard = new Dashboard($pid);
require_once(dirname(__FILE__)."/".$dashboard->getTarget().".php");

$headers = [];
$measurements = [];

$headers[] = "Publications by MESH Terms<br>(Confirmed Original Research Only)";
if (isset($_GET['cohort'])) {
    $cohort = Sanitizer::sanitizeCohort($_GET['cohort']);
    $headers[] = "For Cohort " . $cohort;
} else {
    $cohort = "";
}

$indexedRedcapData = Download::getIndexedRedcapData($token, $server, DataDictionaryManagement::filterOutInvalidFields([], array_merge(CareerDev::$smallCitationFields, ["citation_mesh_terms"])), $cohort, Application::getModule());

$numConfirmedPubs = 0;
$numForMESHTerms = [];
$ts = time();
foreach ($indexedRedcapData as $recordId => $rows) {
	$pubs = new Publications($token, $server, []);
	$pubs->setRows($rows);
	$goodCitations = $pubs->getCitationCollection("Included");
	if ($goodCitations) {
		$confirmedCount = $goodCitations->getCount();
		$numConfirmedPubs += $confirmedCount;
		foreach ($goodCitations->getCitations() as $citation) {
			if ($citation->isResearchArticle() && ($citation->getVariable("data_source") == "citation")) {
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

echo $dashboard->makeHTML($headers, $measurements, [], $cohort);
