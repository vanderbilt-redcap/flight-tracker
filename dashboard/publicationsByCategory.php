<?php

use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\FlightTrackerExternalModule\Measurement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/".\Vanderbilt\FlightTrackerExternalModule\getTarget().".php");
require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/Citation.php");
require_once(dirname(__FILE__)."/../classes/Download.php");

$headers = array();
$measurements = array();

array_push($headers, "Publications by Category");
if ($_GET['cohort']) {
	array_push($headers, "For Cohort ".$_GET['cohort']);
} 

$metadata = Download::metadata($token, $server);
$indexedRedcapData = \Vanderbilt\FlightTrackerExternalModule\getIndexedRedcapData($token, $server, CareerDev::$smallCitationFields, $_GET['cohort'], $metadata);

$numConfirmedPubs = 0;
$numUnconfirmedPubs = 0;
$notDoneRecords = 0;
$numForCategory = array();
$numForYear = array();
$ts = time();
foreach ($indexedRedcapData as $recordId => $rows) {
	$pubs = new Publications($token, $server, $metadata);
	$pubs->setRows($rows);
	$goodCitations = $pubs->getCitationCollection("Included");
	if ($goodCitations) {
		$confirmedCount = $goodCitations->getCount();
		$numConfirmedPubs += $confirmedCount;
		foreach ($goodCitations->getCitations() as $citation) {
			$cat = $citation->getCategory();
	
			if (!isset($numForCategory[$cat])) {
				$numForCategory[$cat] = 0;
			}
	
			$numForCategory[$cat]++;
		}
	}

	$notDoneCitations = $pubs->getCitationCollection("Not done");
	if ($notDoneCitations) {
		$notDoneCount = $notDoneCitations->getCount();
		$numUnconfirmedPubs += $notDoneCount;
		if ($notDoneCount > 0) {
			$notDoneRecords++;
		}
	}
}

$measurements["Number of Confirmed Publications"] = new Measurement($numConfirmedPubs);
$measurements["Number of Unconfirmed Publications"] = new Measurement($numUnconfirmedPubs);
$measurements["Records with Unconfirmed Publications"] = new Measurement($notDoneRecords);

$categories = Citation::getCategories();
foreach ($categories as $cat) {
	if (!$label) {
		$label = "[BLANK]";
	}
	if ($numForCategory[$cat]) {
		$measurements["Confirmed:<br>".$cat] = new Measurement($numForCategory[$cat], $numConfirmedPubs);
	}
}

echo makeHTML($headers, $measurements, array(), $_GET['cohort'], $metadata);
