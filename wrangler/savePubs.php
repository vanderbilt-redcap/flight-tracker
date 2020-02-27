<?php

use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\CitationCollection;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/Citation.php");

if (isset($_POST['finalized'])) {
	$newFinalized = json_decode($_POST['finalized']);
	$newOmissions = json_decode($_POST['omissions']);
	$recordId = $_POST['record_id'];

	$redcapData = Download::fieldsForRecords($token, $server, CareerDev::$citationFields, array($recordId));

	$includedPMIDs = array();
	foreach ($redcapData as $row) {
		if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "citation")) {
			if ($row['citation_include'] == "1") {
				array_push($includedPMIDs, $row['citation_pmid']);
			}
		}
	}
	$upload = array();
	$toProcess = array("1" => $newFinalized, "0" => $newOmissions);
	foreach ($toProcess as $val => $ary) {
		foreach ($ary as $pmid) {
			$matched = FALSE;
			foreach ($redcapData as $row) {
				if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "citation")) {
					if (($pmid == $row['citation_pmid']) && !in_array($pmid, $includedPMIDs)) {
						$uploadRow = array(
									"record_id" => $recordId,
									"redcap_repeat_instrument" => "citation",
									"redcap_repeat_instance" => $row['redcap_repeat_instance'],
									"citation_include" => $val,
									);
						array_push($includedPMIDs, $pmid);
						array_push($upload, $uploadRow);
						$matched = TRUE;
						break;
					}
				}
			}
			if (!$matched) {
				# new citation
				$maxInstance = Citation::findMaxInstance($token, $server, $recordId, $redcapData);
				$maxInstance++;
				$uploadRows = Publications::getCitationsFromPubMed(array($pmid), "manual", $recordId, $maxInstance);
				array_push($includedPMIDs, $pmid);
				foreach ($uploadRows as $uploadRow) {
					array_push($upload, $uploadRow);
				}
			}
		}
	}
	if (!empty($upload)) {
		$feedback = Upload::rows($upload, $token, $server);
		echo json_encode($feedback);
	} else {
		$data = array("error" => "You don't have any new citations enqueued to change!");
		echo json_encode($data);
	}
} else if (isset($_POST['pmid'])) {
	$pmid = $_POST['pmid'];
	$recordId = $_POST['record_id'];

	$maxInstance = Citation::findMaxInstance($token, $server, $recordId);
	$maxInstance++;
	$upload = Publications::getCitationsFromPubMed(array($pmid), "manual", $recordId, $maxInstance); 

	if (!empty($upload)) {
		$feedback = Upload::rows($upload, $token, $server);
		echo json_encode($feedback);
	} else {
		echo json_encode(array("error" => "Upload queue empty!"));
	}
} else {
	$data = array("error" => "You don't have any input! This should never happen.");
	echo json_encode($data);
}
