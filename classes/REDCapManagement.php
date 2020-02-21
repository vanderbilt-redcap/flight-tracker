<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/Download.php");

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

	public static function getFieldsFromMetadata($metadata) {
		$fields = array();
		foreach ($metadata as $row) {
			array_push($fields, $row['field_name']);
		}
		return $fields;
	}

	public static function getFieldsWithRegEx($metadata, $re, $removeRegex = FALSE) {
		$fields = array();
		foreach ($metadata as $row) {
			if (preg_match($re, $row['field_name'])) {
				if ($removeRegex) {
					$field = preg_replace($re, "", $row['field_name']);
				} else {
					$field = $row['field_name'];
				}
				array_push($fields, $field);
			}
		}
		return $fields;
	}

	public static function getMetadataFieldsToScreen() {
		return array("select_choices_or_calculations", "required_field", "form_name", "identifier", "branching_logic", "section_header", "field_annotation");
	}

	# if present, $fields contains the fields to copy over; if left as an empty array, then it attempts to install all fields
	# $deletionRegEx contains the regular expression that marks fields for deletion
	# places new metadata rows AFTER last match from $existingMetadata
	public static function mergeMetadata($existingMetadata, $newMetadata, $fields = array(), $deletionRegEx = "/___delete$/") {
		$metadataFieldsToCopy = self::getMetadataFieldsToScreen();
		$fieldsToDelete = self::getFieldsWithRegEx($newMetadata, $deletionRegEx, TRUE);

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
							foreach ($metadataFieldsToCopy as $metadataField) {
								$newRow = self::copyMetadataSettingForField($newRow, $newMetadata, $metadataField);
							}
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

	public static function copyMetadataSettingForField($row, $metadata, $rowSetting) {
		foreach ($metadata as $metadataRow) {
			if ($metadataRow['field_name'] == $row['field_name']) {
				$row[$rowSetting] = $metadataRow[$rowSetting];
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

	public function setupRepeatingForms($eventId, $formsAndLabels) {
		$sqlEntries = array();
		foreach ($formsAndLabels as $form => $label) {
			array_push($sqlEntries, "($eventId, '".db_real_escape_string($form)."', '".db_real_escape_string($label)."')");
		}
		if (!empty($sqlEntries)) {
			$sql = "REPLACE INTO redcap_events_repeat (event_id, form_name, custom_repeat_form_label) VALUES".implode(",", $sqlEntries);
			db_query($sql);
		}
	}

	public static function getEventIdForClassical($projectId) {
		$sql = "SELECT DISTINCT(m.event_id) AS event_id FROM redcap_events_metadata AS m INNER JOIN redcap_events_arms AS a ON (a.arm_id = m.arm_id) WHERE a.project_id = '$projectId'";
		$q = db_query($sql);
		if ($row = db_fetch_assoc($q)) {
			return $row['event_id'];
		}
		throw new \Exception("The event_id is not defined. (This should never happen.)");
	}

	public static function getExternalModuleId($prefix) {
		$sql = "SELECT external_module_id FROM redcap_external_modules WHERE directory_prefix = '".db_real_escape_string($prefix)."'";
		$q = db_query($sql);
		if ($row = db_fetch_assoc($q)) {
			return $row['external_module_id'];
		}
		throw new \Exception("The external_module_id is not defined. (This should never happen.)");
	}

	public static function setupSurveys($projectId, $surveysAndLabels) {
		foreach ($surveysAndLabels as $form => $label) {
			$sql = "REPLACE INTO redcap_surveys (project_id, font_family, form_name, title, instructions, acknowledgement, question_by_section, question_auto_numbering, survey_enabled, save_and_return, logo, hide_title, view_results, min_responses_view_results, check_diversity_view_results, end_survey_redirect_url, survey_expiration) VALUES ($projectId, '16', '".db_real_escape_string($form)."', '".db_real_escape_string($label)."', '<p><strong>Please complete the survey below.</strong></p>\r\n<p>Thank you!</p>', '<p><strong>Thank you for taking the survey.</strong></p>\r\n<p>Have a nice day!</p>', 0, 1, 1, 1, NULL, 0, 0, 10, 0, NULL, NULL)";
			db_query($sql);
		}
	}

	public static function getPIDFromToken($token, $server) {
		$projectSettings = Download::getProjectSettings($token, $server);
		if (isset($projectSettings['project_id'])) {
			return $projectSettings['project_id'];
		}
		return "";
	}

	public static function getSpecialFields($type) {
		$fields = array();
		$fields["departments"] = array(
						"summary_primary_dept",
						"override_department1",
						"override_department1_previous",
						"check_primary_dept",
						"check_prev1_primary_dept",
						"check_prev2_primary_dept",
						"check_prev3_primary_dept",
						"check_prev4_primary_dept",
						"check_prev5_primary_dept",
						"followup_primary_dept",
						"followup_prev1_primary_dept",
						"followup_prev2_primary_dept",
						"followup_prev3_primary_dept",
						"followup_prev4_primary_dept",
						"followup_prev5_primary_dept",
						);
		$fields["resources"] = array("resources_resource");
		$fields["institutions"] = array("check_institution", "followup_institution");

		if (isset($fields[$type])) {
			return $fields[$type];
		}
		if ($type == "all") {
			$allFields = array();
			foreach ($fields as $type => $typeFields) {
				$allFields = array_merge($allFields, $typeFields);
			}
			$allFields = array_unique($allFields);
			return $allFields;
		}
		return array();
	}

	public static function isValidToken($token) {
		return (strlen($token) == 32);
	}

}
