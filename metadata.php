<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/CareerDev.php");
require_once(dirname(__FILE__)."/classes/Download.php");
require_once(dirname(__FILE__)."/classes/Upload.php");
require_once(dirname(__FILE__)."/classes/REDCapManagement.php");

$filename = dirname(__FILE__)."/metadata.json";
$lastCheckField = "prior_metadata_ts";
$deletionRegEx = "/___delete$/";

if ($_POST['process'] == "check") {
	$ts = $_POST['timestamp'];
	$lastCheckTs = CareerDev::getSetting($lastCheckField);
	if (!$lastCheckTs) {
		$lastCheckTs = 0;
	}

	# check a maximum of once every 30 seconds 
	if ($ts > $lastCheckTs + 30) {
		$fp = fopen($filename, "r");
		$json = "";
		while ($line = fgets($fp)) {
			$json .= $line;
		}
		fclose($fp);

		$metadata = array();
		$metadata['file'] = json_decode($json, TRUE);
		$metadata['REDCap'] = Download::metadata($token, $server);

		$choices = array();
		foreach ($metadata as $type => $md) {
			$choices[$type] = REDCapManagement::getChoices($md);
		}

		$fieldList = array();
		foreach ($metadata as $type => $metadataRows) {
			$fieldList[$type] = array();
			foreach ($metadataRows as $row) {
				$fieldList[$type][$row['field_name']] = $row['select_choices_or_calculations'];
			}
		}

		$missing = array();
		$additions = array();
		$changedChoices = array();
		foreach ($fieldList["file"] as $field => $choiceStr) {
			if (!isset($fieldList["REDCap"][$field])) {
				array_push($missing, $field);
				if (!preg_match($deletionRegEx, $field) && !preg_match("/^coeus_/", $field)) {
					array_push($additions, $field);
				}
			} else if ($choiceStr && $choices["REDCap"][$field] && $choices["file"][$field] && $fieldList["REDCap"][$field] && ($choiceStr != $fieldList["REDCap"][$field])) {
				array_push($missing, $field);
				array_push($changedChoices, $field);
			}
		}

		CareerDev::setSetting($lastCheckField, time());
		if (count($additions) + count($changedChoices) > 0) {
			echo "<script>var missing = ".json_encode($missing).";</script>\n";
			echo "An upgrade in your Data Dictionary exists. <a href='javascript:;' onclick='installMetadata(missing);'>Click here to install.</a>";
		}
	}
} else if ($_POST['process'] == "install") {
	$fields = $_POST['fields'];
	$fp = fopen($filename, "r");
	$json = "";
	while ($line = fgets($fp)) {
		$json .= $line;
	}
	fclose($fp);

	$metadata = array();
	$metadata['file'] = json_decode($json, TRUE);
	$metadata['REDCap'] = Download::metadata($token, $server);
	if ($metadata['file']) {
		$metadata['merged'] = REDCapManagement::mergeMetadata($metadata['REDCap'], $metadata['file'], $fields, $deletionRegEx);
		$feedback = Upload::metadata($metadata['merged'], $token, $server);
		echo json_encode($feedback);
	}
}
