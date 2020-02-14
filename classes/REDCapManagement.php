<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../Application.php");

class REDCapManagement {
	public static function getChoices($metadata) {
		$choicesStrs = array();
		$multis = array("checkbox", "dropdown", "radio");
		foreach ($metadata as $row) {
			if (in_array($row['field_type'], $multis) && $row['select_choices_or_calculations']) {
				$choicesStrs[$row['field_name']] = $row['select_choices_or_calculations'];
			} else if ($row['field_type'] == "yesno") {
				$choicesStrs[$row['field_name']] = "0,No|1,Yes";
			} else if ($row['field_type'] == "truefalse") {
				$choicesStrs[$row['field_name']] = "0,False|1,True";
			}
		}
		$choices = array();
		foreach ($choicesStrs as $fieldName => $choicesStr) {
			$choicePairs = preg_split("/\s*\|\s*/", $choicesStr);
			$choices[$fieldName] = array();
			foreach ($choicePairs as $pair) {
				$a = preg_split("/\s*,\s*/", $pair);
				if (count($a) == 2) {
					$choices[$fieldName][$a[0]] = $a[1];
				} else if (count($a) > 2) {
					$a = preg_split("/,/", $pair);
					$b = array();
					for ($i = 1; $i < count($a); $i++) {
						$b[] = $a[$i];
					}
					$choices[$fieldName][trim($a[0])] = implode(",", $b);
				}
			}
		}
		return $choices;
	}

	public static function getRepeatingForms($pid) {
		if (!function_exists("db_query")) {
			require_once(dirname(__FILE__)."/../../../redcap_connect.php");
		}

		$sql = "SELECT DISTINCT(r.form_name) AS form_name FROM redcap_events_metadata AS m INNER JOIN redcap_events_arms AS a ON (a.arm_id = m.arm_id) INNER JOIN redcap_events_repeat AS r ON (m.event_id = r.event_id) WHERE a.project_id = '$pid'";
		$q = db_query($sql);
		if ($error = db_error()) {
			Application::log("ERROR: ".$error);
			throw new \Exception("ERROR: ".$error);
		}
		$repeatingForms = array();
		while ($row = db_fetch_assoc($q)) {
			array_push($repeatingForms, $row['form_name']);
		}
		return $repeatingForms;
	}

	public static function getSurveys($pid) {
		if (!function_exists("db_query")) {
			require_once(dirname(__FILE__)."/../../../redcap_connect.php");
		}

		$sql = "SELECT form_name, title FROM redcap_surveys WHERE project_id = '".$pid."'";
		$q = db_query($sql);
		if ($error = db_error()) {
			Application::log("ERROR: ".$error);
			throw new \Exception("ERROR: ".$error);
		}

		$currentInstruments = \REDCap::getInstrumentNames();

		$forms = array();
		while ($row = db_fetch_assoc($q)) {
			# filter out surveys which aren't live
			if (isset($currentInstruments[$row['form_name']])) {
				$forms[$row['form_name']] = $row['title'];
			}
		}
		return $forms;
	}

	public static function getRowsForFieldsFromMetadata($fields, $metadata) {
		$selectedRows = array();
		foreach ($metadata as $row) {
			if (in_array($row['field_name'], $fields)) {
				array_push($selectedRows, $row);
			}
		}
		return $selectedRows;
	}

	public static function getFieldsWithRegEx($metadata, $re) {
		$fields = array();
		foreach ($metadata as $row) {
			if (preg_match($re, $row['field_name'])) {
				$field = preg_replace($re, "", $row['field_name']);
				array_push($fields, $field);
			}
		}
		return $fields;
	}

	# if present, $fields contains the fields to copy over; if left as an empty array, then it attempts to install all fields
	# $deletionRegEx contains the regular expression that marks fields for deletion
	# places new metadata rows AFTER last match from $existingMetadata
	public static function mergeMetadata($existingMetadata, $newMetadata, $fields = array(), $deletionRegEx = "/___delete$/") {
		$fieldsToDelete = self::getFieldsWithRegEx($newMetadata, $deletionRegEx);

		if (empty($fields)) {
			$selectedRows = $newMetadata;
		} else {
			$selectedRows = self::getRowsForFieldsFromMetadata($fields, $newMetadata);
		}
		$newChoices = self::getChoices($newMetadata);
		foreach ($selectedRows as $newRow) {
			if (!in_array($newRow['field_name'], $fieldsToDelete)) {
				$priorRowField = "record_id";
				foreach ($newMetadata as $row) {
					if ($row['field_name'] == $newRow['field_name']) {
						break;
					} else {
						$priorRowField = $row['field_name'];
					}
				}
				$tempMetadata = array();
				$priorNewRowField = "";
				foreach ($existingMetadata as $row) {
					if (!preg_match($deletionRegEx, $row['field_name']) && !in_array($row['field_name'], $fieldsToDelete)) {
						if ($priorNewRowField != $row['field_name']) {
							array_push($tempMetadata, $row);
						}
					}
					if (($priorRowField == $row['field_name']) && !preg_match($deletionRegEx, $newRow['field_name'])) {
						if ($newChoices[$newRow['field_name']]) {
							$newRow = self::copyChoiceStrForField($newRow, $newMetadata);
						}
						array_push($tempMetadata, $newRow);
						$priorNewRowField = $newRow['field_name'];
					}
				}
				$existingMetadata = $tempMetadata;
			}
		}
		return $existingMetadata;
	}

	public static function copyChoiceStrForField($row, $metadata) {
		foreach ($metadata as $metadataRow) {
			if ($metadataRow['field_name'] == $row['field_name']) {
				$row['select_choices_or_calculations'] = $metadataRow['select_choices_or_calculations'];
				break;
			}
		}
		return $row;
	}

	public static function YMD2MDY($ymd) {
		$nodes = preg_split("/[\/\-]/", $ymd);
		if (count($nodes) == 3) {
			$year = $nodes[0];
			$month = $nodes[1];
			$day = $nodes[2];
			return $month."-".$day."-".$year;
		}
		return "";
	}

	public static function MDY2YMD($mdy) {
		$nodes = preg_split("/[\/\-]/", $mdy);
		if (count($nodes) == 3) {
			$month = $nodes[0];
			$day = $nodes[1];
			$year = $nodes[2];
			if ($year < 100) {
				if ($year > 30) {
					$year += 1900;
				} else {
					$year += 2000;
				}
			}
			return $year."-".$month."-".$day;
		}
		return "";
	}

	public static function getLabels($metadata) {
		$labels = array();
		foreach ($metadata as $row) {
			$labels[$row['field_name']] = $row['field_label'];
		}
		return $labels;
	}

	public static function indexREDCapData($redcapDataFromJSON) {
		$indexedRedcapData = array();
		foreach ($redcapDataFromJSON as $row) {
			$recordId = $row['record_id'];
			if (!isset($indexedRedcapData[$recordId])) {
				$indexedRedcapData[$recordId] = array();
			}
			array_push($indexedRedcapData[$recordId], $row);
		}
		return $indexedRedcapData;
	}
}
