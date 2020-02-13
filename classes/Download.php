<?php

namespace Vanderbilt\CareerDevLibrary;

# This class handles commonly occuring downloads from the REDCap API.

// require_once(dirname(__FILE__)."/../../../redcap_connect.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/Filter.php");
require_once(dirname(__FILE__)."/CohortConfig.php");
require_once(dirname(__FILE__)."/Cohorts.php");

class Download {
	public static function indexREDCapData($redcapData) {
		$indexedRedcapData = array();
		foreach ($redcapData as $row) {
			$recordId = $row['record_id'];
			if (!isset($indexedRedcapData[$recordId])) {
				$indexedRedcapData[$recordId] = array();
			}
			array_push($indexedRedcapData[$recordId], $row);
		}
		return $indexedRedcapData;
	}
	public static function getIndexedRedcapData($token, $server, $fields, $cohort = "", $metadata = array()) {
		$redcapData = self::getFilteredRedcapData($token, $server, $fields, $cohort, $metadata);
		return self::indexREDCapData($redcapData);
	}

	public static function getMaxInstanceForRepeatingForm($token, $server, $formName, $recordId) {
		if ($formName == "") {
			# normative row
			return "";
		}

		$allRepeatingForms = Scholar::getRepeatingForms(Application::getPID($token));
		if (!in_array($formName, $allRepeatingForms)) {
			# not repeating form => on normative row
			return "";
		}

		$recordIds = self::recordIds($token, $server);
		if (!in_array($recordId, $recordIds)) {
			# new record
			return 0;
		}

		$redcapData = self::formForRecords($token, $server, $formName, array($recordId));
		$max = 0;
		foreach ($redcapData as $row) {
			if (isset($row['redcap_repeat_instance']) && isset($row['redcap_repeat_instrument']) && ($row['redcap_repeat_instrument'] == $formName)) {
				if ($row['redcap_repeat_instance'] > $max) {
					$max = $row['redcap_repeat_instance'];
				}
			}
		}
		return $max;
	}

	public static function getFilteredRedcapData($token, $server, $fields, $cohort = "", $metadata = array()) {
		if ($token && $server && $fields && !empty($fields)) {
			if ($cohort) {
				$records = self::cohortRecordIds($token, $server, $metadata, $cohort);
			}
			if (!$records) {
				$records = self::recordIds($token, $server);
			}

			$redcapData = self::fieldsForRecords($token, $server, $fields, $records);
			return $redcapData;
		}
		return array();
	}

	# if $forms is empty, download all forms
	public static function formMetadata($token, $server, $forms = array()) {
		$metadata = self::metadata($token, $server);

		$filtered = array();
		foreach ($metadata as $row) {
			if (empty($forms) || in_array($row['form_name'], $forms)) {
				array_push($filtered, $row);
			}
		}
		return $filtered;
	}

	public static function metadata($token, $server, $fields = array()) {
		Application::log("Download::metadata");
		$data = array(
			'token' => $token,
			'content' => 'metadata',
			'format' => 'json',
			'returnFormat' => 'json'
		);
		if (!empty($fields)) {
			$data['fields'] = $fields;
		}
		$rows = self::sendToServer($server, $data);
		return $rows;
	}

	public static function getProjectSettings($token, $server) {
		$data = array(
			'token' => $token,
			'content' => 'project',
			'format' => 'json',
			'returnFormat' => 'json'
		);
		return self::sendToServer($server, $data);
	}

	public static function isCurrentServer($server) {
		$currServer = $_SERVER['SERVER_NAME'];
		return (strpos(strtolower($server), strtolower($currServer)) !== FALSE);
	}

	private static function sendToServer($server, $data) {
		$pid = Application::getPID($data['token']);
		if (($pid) && ($data['content'] == "record") && !isset($data['forms']) && method_exists('\REDCap', 'getData')) {
			$output = \REDCap::getData($pid, "json", $data['records'], $data['fields']); 
		} else {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $server);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_VERBOSE, 0);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_AUTOREFERER, true);
			curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
			$output = curl_exec($ch);
			curl_close($ch);
		}
		$redcapData = json_decode($output, true);
		if (isset($redcapData['error']) && !empty($redcapData['error'])) {
			throw new \Exception("Download Exception: ".$redcapData['error']);
		}
		return $redcapData;
	}

	public static function userid($token, $server) {
		$data = array(
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'rawOrLabel' => 'raw',
			'fields' => array("record_id", "identifier_userid"),
			'rawOrLabelHeaders' => 'raw',
			'exportCheckboxLabel' => 'false',
			'exportSurveyFields' => 'false',
			'exportDataAccessGroups' => 'false',
			'returnFormat' => 'json'
		);
		$redcapData = self::sendToServer($server, $data);
		$ids = array();
		foreach ($redcapData as $row) {
			if ($row['identifier_userid']) {
				$ids[$row['record_id']] = $row['identifier_userid'];
			}
		}
		return $ids;
	}

	public static function userids($token, $server, $metadata = array()) {
		return self::vunets($token, $server, $metadata);
	}

	public static function vunets($token, $server, $metadata = array()) {
		$possibleFields = array("identifier_vunet", "identifier_userid");
		if (empty($metadata)) {
			$metadata = self::metadata($token, $server);
		}

		$userIdField = "";
		foreach ($possibleFields as $field) {
			foreach ($metadata as $row) {
				if ($row['field_name'] == $field) {
					$userIdField = $field;
					break;  // inner
				}
			}
			if ($userIdField) {
				break; // outer
			}
		}

		if ($userIdField) {
			$data = array(
				'token' => $token,
				'content' => 'record',
				'format' => 'json',
				'type' => 'flat',
				'rawOrLabel' => 'raw',
				'fields' => array("record_id", $userIdField),
				'rawOrLabelHeaders' => 'raw',
				'exportCheckboxLabel' => 'false',
				'exportSurveyFields' => 'false',
				'exportDataAccessGroups' => 'false',
				'returnFormat' => 'json'
			);
			$redcapData = self::sendToServer($server, $data);
			$ids = array();
			foreach ($redcapData as $row) {
				if ($row['identifier_vunet']) {
					$ids[$row['record_id']] = $row[$userIdField];
				}
			}
			return $ids;
		}
		return array();
	}

	public static function formForRecords($token, $server, $formName, $records) {
		$data = array(
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'rawOrLabel' => 'raw',
			'forms' => array($formName),
			'fields' => array("record_id"),
			'records' => $records,
			'rawOrLabelHeaders' => 'raw',
			'exportCheckboxLabel' => 'false',
			'exportSurveyFields' => 'false',
			'exportDataAccessGroups' => 'false',
			'returnFormat' => 'json'
		);
		return self::sendToServer($server, $data);

	}

	public static function institutions($token, $server) {
		return Download::oneField($token, $server, "identifier_institution");
	}

	public static function lastnames($token, $server) {
		return Download::oneField($token, $server, "identifier_last_name");
	}

	public static function firstnames($token, $server) {
		return Download::oneField($token, $server, "identifier_first_name");
	}

	public static function emails($token, $server) {
		return Download::oneField($token, $server, "identifier_email");
	}

	public static function resources($token, $server, $records = array()) {
		if (empty($records)) {
			return Download::fields($token, $server, array("record_id", "resources_date", "resources_resource"));
		} else {
			return Download::fieldsForRecords($token, $server, array("record_id", "resources_date", "resources_resource"), $records);
		}
	}

	private static function oneField($token, $server, $field) {
		$data = array(
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'rawOrLabel' => 'raw',
			'fields' => array("record_id", $field),
			'rawOrLabelHeaders' => 'raw',
			'exportCheckboxLabel' => 'false',
			'exportSurveyFields' => 'false',
			'exportDataAccessGroups' => 'false',
			'returnFormat' => 'json'
		);
		$redcapData = self::sendToServer($server, $data);
		$ary = array();
		foreach ($redcapData as $row) {
			$ary[$row['record_id']] = $row[$field];
		}
		return $ary;
	}

	public static function names($token, $server) {
		$data = array(
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'rawOrLabel' => 'raw',
			'fields' => array("record_id", "identifier_first_name", "identifier_last_name"),
			'rawOrLabelHeaders' => 'raw',
			'exportCheckboxLabel' => 'false',
			'exportSurveyFields' => 'false',
			'exportDataAccessGroups' => 'false',
			'returnFormat' => 'json'
		);
		$redcapData = self::sendToServer($server, $data);
		$ordered = array();
		foreach ($redcapData as $row) {
			$ordered[$row['identifier_last_name'].", ".$row['identifier_first_name']." ".$row['record_id']] = $row;
		}
		ksort($ordered);

		$names = array();
		foreach ($ordered as $key => $row) {
			$names[$row['record_id']] = $row['identifier_first_name']." ".$row['identifier_last_name'];
		}
		return $names;
	}

	public static function recordIds($token, $server) {
		Application::log("Download::recordIds");
		$data = array(
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'rawOrLabel' => 'raw',
			'fields' => array("record_id"),
			'rawOrLabelHeaders' => 'raw',
			'exportCheckboxLabel' => 'false',
			'exportSurveyFields' => 'false',
			'exportDataAccessGroups' => 'false',
			'returnFormat' => 'json'
		);
		$redcapData = self::sendToServer($server, $data);
		$records = array();
		foreach ($redcapData as $row) {
			array_push($records, $row['record_id']);
		}
		return $records;
	}

	public static function fieldsWithConfig($token, $server, $metadata, $fields, $config) {
		$filter = new Filter($token, $server, $metadata);
		$records = $filter->getRecords($config);
		Application::log("Download::fieldsWithFilter ".count($records)." records; ".count($fields)." fields");
		return Download::fieldsForRecords($token, $server, $fields, $records);
	}

	public static function fields($token, $server, $fields) {
		Application::log("Download::fields ".count($fields)." fields");
		$data = array(
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'rawOrLabel' => 'raw',
			'fields' => $fields,
			'rawOrLabelHeaders' => 'raw',
			'exportCheckboxLabel' => 'false',
			'exportSurveyFields' => 'false',
			'exportDataAccessGroups' => 'false',
			'returnFormat' => 'json'
		);
		return self::sendToServer($server, $data);
	}

	public function downloads_test($tester) {
		$records = array("1");
		$fields = array("record_id");
		$token = "C65F37B496A52AE5E044A8D79FDD2A02";
		$server = "https://redcap.vanderbilt.edu/api/";

		$tester->tag("fieldsForRecords");
		$ary = Download::fieldsForRecords($token, $server, $fields, $records);
		$tester->assertNotNull($ary);
		$tester->assertTrue(!empty($ary));

		$tester->tag("records");
		$ary = Download::records($token, $server, $records);
		$tester->assertNotNull($ary);
		$tester->assertTrue(!empty($ary));

		$tester->tag("fields");
		$ary = Download::fields($token, $server, $fields);
		$tester->assertNotNull($ary);
		$tester->assertTrue(!empty($ary));

		$tester->tag("recordIds");
		$ary = Download::recordIds($token, $server);
		$tester->assertNotNull($ary);
		$tester->assertTrue(!empty($ary));

		$tester->tag("metadata");
		$ary = Download::metadata($token, $server);
		$tester->assertNotNull($ary);
		$tester->assertTrue(!empty($ary));
	}

	public static function fieldsForRecords($token, $server, $fields, $records) {
		Application::log("Download::fieldsForRecords ".count($fields)." fields with ".json_encode($records));
		$data = array(
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'rawOrLabel' => 'raw',
			'records' => $records,
			'fields' => $fields,
			'rawOrLabelHeaders' => 'raw',
			'exportCheckboxLabel' => 'false',
			'exportSurveyFields' => 'false',
			'exportDataAccessGroups' => 'false',
			'returnFormat' => 'json'
		);
		return self::sendToServer($server, $data);
	}

	public static function records($token, $server, $records = NULL) {
		if (!isset($records)) {
			# assume recordIds was meant if $records null
			return Download::recordIds($token, $server);
		}
		Application::log("Download::records ".json_encode($records));
		$data = array(
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'rawOrLabel' => 'raw',
			'records' => $records,
			'rawOrLabelHeaders' => 'raw',
			'exportCheckboxLabel' => 'false',
			'exportSurveyFields' => 'false',
			'exportDataAccessGroups' => 'false',
			'returnFormat' => 'json'
		);
		return self::sendToServer($server, $data);
	}

	public static function cohortRecordIds($token, $server, $metadata, $cohort) {
		$filter = new Filter($token, $server, $metadata);
		if ($module = Application::getModule()) {
			$cohorts = new Cohorts($token, $server, $module);
		} else {
			$cohorts = new Cohorts($token, $server, $metadata);
		}
		$cohortNames = $cohorts->getCohortNames();
		if (in_array($cohort, $cohortNames)) {
			$config = $cohorts->getCohort($cohort);
			if ($config) {
				$redcapData = self::fieldsWithConfig($token, $server, $metadata, array("record_id"), $config);
				$records = array();
				foreach ($redcapData as $row) {
					array_push($records, $row['record_id']);
				}
				return $records;
			}
		}
		return false;
	}

	public static function delete($token, $server, $records) {
		$data = array(
			'token' => 'E39DDA74C0E5927E601A866D8B7A6544',
			'action' => 'delete',
			'content' => 'record',
			'records' => $records
		);

		return self::sendToServer($server, $data);
	}
}
