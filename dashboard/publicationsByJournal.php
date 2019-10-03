<?php

use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\FlightTrackerExternalModule\Measurement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/".\Vanderbilt\FlightTrackerExternalModule\getTarget().".php");
require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/Download.php");

$headers = array();
$measurements = array();

array_push($headers, "Publications by Journal<br>(Confirmed Original Research Only)");
if ($_GET['cohort']) {
	array_push($headers, "For Cohort ".$_GET['cohort']);
} 

$metadata = Download::metadata($token, $server);
$indexedRedcapData = \Vanderbilt\FlightTrackerExternalModule\getIndexedRedcapData($token, $server, array_merge(CareerDev::$smallCitationFields, array('citation_journal')), $_GET['cohort'], $metadata);

$numConfirmedPubs = 0;
$numForJournal = array();
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
				$journal = $citation->getVariable("journal");

				if (!isset($numForJournal[$journal])) {
					$numForJournal[$journal] = 0;
				}

				$numForJournal[$journal]++;
			}
		}
	}
}

arsort($numForJournal);
foreach ($numForJournal as $journal => $val) {
	if ($val) {
		$measurements[$journal] = new Measurement($val, $numConfirmedPubs);
	}
}

array_push($headers, \Vanderbilt\FlightTrackerExternalModule\pretty(count($numForJournal))." Journals Represented");

echo makeHTML($headers, $measurements, array(), $_GET['cohort'], $metadata);
