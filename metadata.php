<?php

use \Vanderbilt\CareerDevLibrary\Download;

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/classes/Download.php");

$filename = dirname(__FILE__)."/metadata.json";
$lastCheckField = "prior_metadata_ts";

if ($_POST['process'] == "check") {
	$ts = $_POST['timestamp'];
	$lastCheckTs = CareerDev::getSetting($lastCheckField);
	if (!$lastCheckTs) {
		$lastCheckTs = 0;
	}

	# check a maximum of once an hour
	if ($ts > $lastCheckTs + 3600) {
		$fp = fopen($filename, "r");
		$json = "";
		while ($line = fgets($fp)) {
			$json .= $line;
		}
		fclose($fp);

		$metadata = array();
		$metadata['file'] = json_decode($json, TRUE);
		$metadata['REDCap'] = Download::metadata($token, $server);

		$fields = array();
		foreach ($metadata as $type => $metadataRows) {
			$fields[$type] = array();
			foreach ($metadataRows as $row) {
				array_push($fields[$type], $row['field_name']);
			}
		}

		$missing = array();
		foreach ($fieldList["file"] as $field) {
			if (!in_array($field, $fieldList["REDCap"])) {
				array_push($missing, $field);
			}
		}

		CareerDev::setSetting($lastCheckField, time());
		if (count($missing) > 0) {
			echo "An upgrade in your Data Dictionary exists. <a href='javascript:;' onclick='installMetadata(".json_encode($missing).");'>Click here to install.</a>";
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
		$metadata['merged'] = mergeMetadata($metadata['REDCap'], $metadata['file'], $fields);
		$feedback = Upload::metadata($metadata['merged'], $token, $server);
		echo json_encode($feedback);
	}
}

function getFields($metadata, $fields) {
	$selectedRows = array();
	foreach ($metadata as $row) {
		if (in_array($row['field_name'], $fields)) {
			array_push($selectedRows, $row);
		}
	}
	return $selectedRows;
}

function mergeMetadata($existingMetadata, $newMetadata, $fields) {
	$selectedRows = getFields($newMetadata, $fields);
	foreach ($selectedRows as $newRow) {
		$prior = "record_id";
		foreach ($newMetadata as $row) {
			if ($row['field_name'] == $newRow['field_name']) {
				break;
			} else {
				$prior = $row['field_name'];
			}
		}
		$tempMetadata = array();
		foreach ($existingMetadata as $row) {
			if (!preg_match("/___delete$/", $row['field_name'])) {
				array_push($tempMetadata, $row);
			}
			if ($prior == $row['field_name']) {
				array_push($tempMetadata, $newRow);
			}
		}
		$existingMetadata = $tempMetadata;
	}
	return $existingMetadata;
}
