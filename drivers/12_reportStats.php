<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Download;

require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../classes/Grants.php");
require_once(dirname(__FILE__)."/../classes/Download.php");

# Reports *de-identified, generic stats* back to home office on a weekly basis.
# Reports figures like number of scholars for each project.

function reportStats($token, $server, $pid) {
	$url = "https://redcap.vanderbilt.edu/plugins/career_dev/receiveStats.php";

	# do NOT report details of records; just report: number of records/scholars
	$recordIds = Download::recordIds($token, $server);
	// $numGrants = getTotalNumberOfGrantsAfterCombination($token, $server);
	// $numPubs = getTotalNumberOfConfirmedPublications($token, $server);

	$post = [
        "pid" => $pid,
        "server" => $server,
        "scholars" => count($recordIds),
        "date" => date("Y-m-d"),
        "version" => CareerDev::getVersion(),
        "grant_class" => CareerDev::getSetting("grant_class"),
    ];
			// "grants" => $numGrants,
			// "publications" => $numPubs,

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_VERBOSE, 0);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_AUTOREFERER, true);
	curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
	curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post, '', '&'));
	$output = curl_exec($ch);
	CareerDev::log($output);
	curl_close($ch);
}

function getTotalNumberOfConfirmedPublications($token, $server) {
	$fields = array("citation_include");
	$redcapData = Download::fields($token, $server, $fields);

	$numPubs = 0;
	foreach ($redcapData as $row) {
		if ($row['citation_include'] == "1") {
			$numPubs++;
		}
	}
	return $numPubs;
}

function getTotalNumberOfGrantsAfterCombination($token, $server) {
	$fields = array();
	$prefix = "summary_award_sponsorno";
	for ($i = 1; $i <= MAX_GRANTS; $i++) {
		array_push($fields, $prefix."_".$i);
	}
	$redcapData = Download::fields($token, $server, $fields);

	$numGrants = 0;
	foreach ($redcapData as $row) {
		for ($i = 1; $i <= MAX_GRANTS; $i++) {
			if ($row[$prefix."_".$i]) {
				$numGrants++;
			}
		}
	}
	return $numGrants;
}
