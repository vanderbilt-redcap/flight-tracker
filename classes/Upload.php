<?php

namespace Vanderbilt\CareerDevLibrary;


# This class handles commonly occuring downloads from the REDCap API.

// require_once(dirname(__FILE__)."/../../../redcap_connect.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/Download.php");

class Upload {
	public static function metadata($metadata, $token, $server) {
		if (!is_array($metadata)) {
			error_log("Upload::metadata: first parameter should be array");
			die();
		}
		if (!$token || !$server) {
			throw new \Exception("No token or server supplied!");
		}
		$data = array(
			'token' => $token,
			'content' => 'metadata',
			'format' => 'json',
			'data' => json_encode($metadata),
			'returnFormat' => 'json'
		);
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

		$feedback = json_decode($output, TRUE);
		self::testFeedback($feedback, $metadata);

		error_log("Upload::metadata returning $output");
		return $feedback;
	}

	private static function testFeedback($feedback, $rows) {
		if (isset($feedback['error']) && $feedback['error']) {
			throw new \Exception($feedback['error']."\n".json_encode($rows));
		}
		if (isset($feedback['errors']) && $feedback['errors']) {
			throw new \Exception(implode("; ", $feedback['errors'])."\n".json_encode($rows));
		}
		return TRUE;
	}

	public static function projectSettings($settings, $token, $server) {
		if (!$token || !$server) {
			throw new \Exception("No token or server supplied!");
		}
		$data = array(
			'token' => $token,
			'content' => 'project_settings',
			'format' => 'json',
			'data' => json_encode($settings),
			'returnFormat' => 'json'
			);
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

		$feedback = json_decode($output, TRUE);
		self::testFeedback($feedback, $redcapData);

		error_log("Upload::projectSettings returning $output");
		return $feedback;
	}

	public static function resource($recordId, $value, $token, $server, $date = "AUTOFILL") {
		$redcapData = Download::resources($token, $server, $recordId); 
		$maxInstance = 0;
		if ($date == "AUTOFILL") {
			$date = date("Y-m-d");
		}
		foreach ($redcapData as $row) {
			if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "resources")) {
				$instance = $row['redcap_repeat_instance'];
				$maxInstance = ($instance > $maxInstance) ? $instance : $maxInstance;
			}
		}
		$maxInstance++;

		$uploadRow = array(
					"record_id" => $recordId,
					"redcap_repeat_instrument" => "resources",
					"redcap_repeat_instance" => $maxInstance,
					"resources_date" => $date,
					"resources_resource" => $value,
					"resources_complete" => "2",
					);
		return self::oneRow($uploadRow, $token, $server);
	}

	# returns an array of the errors from the upload result
	public static function isolateErrors($result) {
		if (is_array($result) && $result['errors']) {
			return $result['errors'];
		} else {
			$result = json_decode($result, true);
			if ($result && $result['errors']) {
				return $result['errors'];
			} else {
				return array();
			}
		}
	}

	public static function oneRow($row, $token, $server) {
		if (!is_array($row)) {
			error_log("Upload::oneRow: first parameter should be array");
			die();
		}
		return self::rows(array($row), $token, $server);
	}

	public static function rowsAsync($rows, $token, $server) {
		# disabled
		self::rows($rows, $token, $server);
	}

	private static function getRowLimit() {
		return 100;
	}

	private static function combineFeedback($priorFeedback, $currFeedback) {
		foreach ($currFeedback as $key => $value) {
			if (!isset($priorFeedback[$key]) || !$priorFeedback[$key]) {
				$priorFeedback[$key] = $value;
			} else if (is_numeric($value) && is_numeric($priorFeedback[$key])) {
				$priorFeedback[$key] = $priorFeedback[$key] + $value;
			}
		}
		return $priorFeedback;
	}

	public static function rows($rows, $token, $server) {
		if (!is_array($rows)) {
			error_log("Upload::rows: first parameter should be array");
			echo "Upload::rows: first parameter should be array!\n";
			die();
		}
		if (strlen($token) != 32) {
			error_log("Upload::rows: second parameter should be token");
			echo "Upload::rows: second parameter should be token\n";
			die();
		}
		if (empty($rows)) {
			error_log("WARNING! Upload::rows input is empty!");
			echo "WARNING! Upload::rows input is empty!\n";
			return "";
		}
		if (!$token || !$server) {
			throw new \Exception("No token or server supplied!");
		}
		error_log("Upload::rows uploading ".count($rows)." rows");
		if (count($rows) > self::getRowLimit()) {
			$rowsOfRows = array();
			$i = 0;
			while ($i < count($rows)) {
				$currRows = array();
				$j = $i;
				while (($j < $i + self::getRowLimit()) && ($j < count($rows))) {
					array_push($currRows, $rows[$j]);
					$j++;
				}
				if (!empty($currRows)) {
					array_push($rowsOfRows, $currRows);
				}
				$i += self::getRowLimit();
			}
		} else {
			$rowsOfRows = array($rows);
		}

		$allFeedback = array();
		$pid = Application::getPID($token);
		foreach ($rowsOfRows as $rows) {
			$data = array(
				'token' => $token,
				'content' => 'record',
				'format' => 'json',
				'type' => 'flat',
				'overwriteBehavior' => 'overwrite',
				'data' => json_encode($rows),
				'returnContent' => 'count',
				'returnFormat' => 'json'
				);
			if ($pid && method_exists('\REDCap', 'saveData')) {
				$method = "saveData";
				$time2 = microtime(TRUE);
				$feedback = \REDCap::saveData($pid, "json", $data['data'], $data['overwriteBehavior']);
				$time3 = microtime(TRUE);
				$output = json_encode($feedback);
			} else {
				$method = "API";
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
				$time2 = microtime(TRUE);
				$output = curl_exec($ch);
				curl_close($ch);
				$time3 = microtime(TRUE);
				$feedback = json_decode($output, true);
			}
			error_log("Upload::rows $method returning $output in ".($time3 - $time2)." seconds");
			self::testFeedback($feedback, $rows);
			$allFeedback = self::combineFeedback($allFeedback, $feedback);
		}
		return $allFeedback;
	}
}
