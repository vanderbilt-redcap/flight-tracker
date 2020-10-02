<?php

use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\FlightTrackerExternalModule\Measurement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\FlightTrackerExternalModule\ObservedMeasurement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/".\Vanderbilt\FlightTrackerExternalModule\getTarget().".php");
require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/Download.php");

$headers = array();
$measurements = array();

array_push($headers, "Publications by Publication Type<br>(Confirmed Original Research Only)");
if ($_GET['cohort']) {
	array_push($headers, "For Cohort ".$_GET['cohort']);
} 

$metadata = Download::metadata($token, $server);
$indexedRedcapData = \Vanderbilt\FlightTrackerExternalModule\getIndexedRedcapData($token, $server, array_merge(CareerDev::$smallCitationFields, array("citation_pub_types")), $_GET['cohort'], $metadata);

$numConfirmedPubs = 0;
$numForPubType = array();
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

echo makeHTML($headers, $measurements, array(), $_GET['cohort'], $metadata);
