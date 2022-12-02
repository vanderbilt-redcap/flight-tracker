<?php

use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\ObservedMeasurement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Dashboard;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;


require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
$dashboard = new Dashboard($pid);
require_once(dirname(__FILE__)."/".$dashboard->getTarget().".php");

$headers = [];
$measurements = [];

$headers[] = "Publications by Publication Type<br>(Confirmed Original Research Only)";
if (isset($_GET['cohort'])) {
    $cohort = Sanitizer::sanitizeCohort($_GET['cohort']);
    $headers[] = "For Cohort " . $cohort;
} else {
    $cohort = "";
}
$headers[] = Publications::makeLimitButton();

$indexedRedcapData = Download::getIndexedRedcapData($token, $server, DataDictionaryManagement::filterOutInvalidFields([], array_unique(array_merge(CareerDev::$smallCitationFields, ["citation_pub_types"]))), $cohort, Application::getModule());

$numConfirmedPubs = 0;
$numForPubType = [];
$ts = time();
foreach ($indexedRedcapData as $recordId => $rows) {
	$pubs = new Publications($token, $server, []);
	$pubs->setRows($rows);
	$goodCitations = $pubs->getCitationCollection("Included");
	if ($goodCitations) {
		$confirmedCount = $goodCitations->getCount();
		$numConfirmedPubs += $confirmedCount;
		foreach ($goodCitations->getCitations() as $citation) {
			if ($citation->isResearchArticle()) {
				$pubTypes = $citation->getPubTypes();
				foreach ($pubTypes as $pubType) {
					if (!isset($numForPubType[$pubType])) {
						$numForPubType[$pubType] = 0;
					}
	
					$numForPubType[$pubType]++;
				}
			}
		}
	}
}

arsort($numForPubType);

foreach ($numForPubType as $pubType => $cnt) {
	$measurements[$pubType] = new ObservedMeasurement($cnt, $numConfirmedPubs);
}

echo $dashboard->makeHTML($headers, $measurements, [], $cohort);
