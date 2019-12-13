<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/CareerDev.php");
require_once(dirname(__FILE__)."/classes/Download.php");
require_once(dirname(__FILE__)."/classes/Upload.php");

$filename = dirname(__FILE__)."/metadata.json";
$lastCheckField = "prior_metadata_ts";

if ($_POST['process'] == "check") {
	$ts = $_POST['timestamp'];
	$lastCheckTs = CareerDev::getSetting($lastCheckField);
	if (!$lastCheckTs) {
		$lastCheckTs = 0;
	}

	# check a maximum of once every 5 minutes
	if ($ts > $lastCheckTs + 5 * 60) {
		$fp = fopen($filename, "r");
		$json = "";
		while ($line = fgets($fp)) {
			$json .= $line;
		}
		fclose($fp);

		$metadata = array();
		$metadata['file'] = json_decode($json, TRUE);
		$metadata['REDCap'] = Download::metadata($token, $server);

		$fieldList = array();
		foreach ($metadata as $type => $metadataRows) {
			$fieldList[$type] = array();
			foreach ($metadataRows as $row) {
				array_push($fieldList[$type], $row['field_name']);
			}
		}

		$missing = array();
		$additions = array();
		foreach ($fieldList["file"] as $field) {
			if (!in_array($field, $fieldList["REDCap"])) {
				array_push($missing, $field);
				if (!preg_match("/___delete$/", $field) && !preg_match("/^coeus_/", $field)) {
					array_push($additions, $field);
				}
			}
		}

		CareerDev::setSetting($lastCheckField, time());
		if (count($additions) > 0) {
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

function getFieldsToDelete($metadata) {
	$re = "/___delete$/";

	$fields = array();
	foreach ($metadata as $row) {
		if (preg_match($re, $row['field_name'])) {
			$field = preg_replace($re, "", $row['field_name']);
			array_push($fields, $field);
		}
	}
	return $fields;
}

function mergeMetadata($existingMetadata, $newMetadata, $fields) {
	$fieldsToDelete = getFieldsToDelete($newMetadata);

	$selectedRows = getFields($newMetadata, $fields);
	foreach ($selectedRows as $newRow) {
		if (!in_array($newRow['field_name'], $fieldsToDelete)) {
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
				if (!preg_match("/___delete$/", $row['field_name']) && !in_array($row['field_name'], $fieldsToDelete)) {
					array_push($tempMetadata, $row);
				}
				if (($prior == $row['field_name']) && !preg_match("/___delete$/", $newRow['field_name'])) {
					array_push($tempMetadata, $newRow);
				}
			}
			$existingMetadata = $tempMetadata;
		}
	}
	return $existingMetadata;
}
