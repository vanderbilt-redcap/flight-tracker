<?php

use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\CitationCollection;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/Citation.php");
require_once(dirname(__FILE__)."/../classes/Download.php");

if ($instrument == "identifiers") {
	$sql = "SELECT field_name FROM redcap_data WHERE project_id = ".db_real_escape_string($project_id)." AND record = '".db_real_escape_string($record)."' AND field_name LIKE '%_complete'";
	$q = db_query($sql);
	if (db_num_rows($q) == 1) {
		if ($row = db_fetch_assoc($q)) {
			if ($row['field_name'] == "identifiers_complete") {
				# new record => only identifiers form filled out
				queueUpInitialEmail($record);
			}
		}
	}
} else if (in_array($instrument, array("followup", "initial_survey"))) {
	$pubFields = array();
	if ($instrument == "followup") {
		$pubFields = array(
					"followup_accepted_pubs" => "Final",
					"followup_not_associated_pubs" => "Omit",
					"followup_not_addressed_pubs" => "Not Done",
					);
	} else if ($instrument == "initial_survey") {
		$pubFields = array(
					"check_accepted_pubs" => "Final",
					"check_not_associated_pubs" => "Omit",
					"check_not_addressed_pubs" => "Not Done",
					);
	}

	$redcapData = array();
	if (!empty($pubFields)) {
		$fields = array_merge(Application::$citationFields, $pubFields);
		$json = \REDCap::getData($project_id, "json", array($record), $fields);
		$redcapData = json_decode($json, TRUE);
	}

	$normativeRow = array();
	if ($instrument == "followup") {
		foreach ($redcapData as $row) {
			if (($row['redcap_repeat_instrument'] == "followup") && ($row['redcap_repeat_instance'] == $repeat_instance)) {
				$normativeRow = $row;
				break;
			}
		}
	} else if ($instrument == "initial_survey") {
		foreach ($redcapData as $row) {
			if ($row['redcap_repeat_instrument'] == "") {
				$normativeRow = $row;
				break;
			}
		}
	}

	$metadata = Download::metadata($token, $server);
	$pubs = new Publications($token, $server, $metadata);
	$pubs->setRows($redcapData);

	$functions = array(
				"Final" => "includePub",
				"Omit" => "omit",
				"Not Done" => "stageForReview",
				);
	foreach ($pubFields as $pubField => $citCollName) {
		$citColls[$citCollName] = $pubs->getCitationCollection($citCollName);
	}
	foreach ($pubFields as $pubField => $citCollName1) {
		if ($row[$pubField]) {
			$currIds = json_decode($row[$pubField], TRUE);
	
			# add and change; never delete
			foreach ($currIds as $currPmid) {
				$status = "New";
				foreach ($citColls as $citCollName2 => $citColl) {
					if ($citColl->has($currPmid)) {
						if ($citCollName2 == $citCollName1) {
							$status = "Keep";
						} else {
							$status = "Move";
						}
						break;
					}
				}
	
				$func = $functions[$citCollName1];
				switch($status) {
					case "New":
						$instance = Citation::findMaxInstance($token, $server, $record, $redcapData);
						$citation = new Citation($token, $server, $record, $instance);
						$citation->$func();              // writes to REDCap
						$citColls[$citCollName1]->addCitation($citation);
						break;
					case "Move":
						$citation = $citColls[$citCollName1]->getCitation($currPmid);
						$citation->$func();             // writes to REDCap
	
						# these calls affect future calculations, but do not write them to REDCap
						$citColls[$citCollName2]->addCitation($citation);
						$citColls[$citCollName1]->removePMID($currPmid);
						break;
					case "Keep":
						break;
					default:
						break;
				}
			}
		}
	}
}
