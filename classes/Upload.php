<?php

namespace Vanderbilt\CareerDevLibrary;


# This class handles commonly occuring downloads from the REDCap API.

// require_once(dirname(__FILE__)."/../../../redcap_connect.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/Download.php");
require_once(dirname(__FILE__)."/NameMatcher.php");

class Upload
{
    public static function adaptToUTF8(&$ary)
    {
        if (!json_encode($ary)) {
            if (json_last_error() == JSON_ERROR_UTF8) {
                $ary = self::utf8ize($ary);
            } else {
                throw new \Exception("Error in JSON processing: " . json_last_error_msg());
            }
        }
    }

    public static function utf8ize($mixed)
    {
        if (is_array($mixed)) {
            foreach ($mixed as $key => $value) {
                $mixed[$key] = self::utf8ize($value);
            }
        } else if (is_string($mixed)) {
            return utf8_encode($mixed);
        }
        return $mixed;
    }

    public static function deleteField($token, $server, $pid, $field, $recordId, $instance = NULL) {
        $records = Download::recordIds($token, $server);
        if (Download::isCurrentServer($server)) {
            if (in_array($recordId, $records)) {
                $instanceClause = "";
                if ($instance) {
                    if ($instance == 1) {
                        $instanceClause = " AND instance IS NULL";
                    } else {
                        $instanceClause = " AND instance = '$instance'";
                    }
                }
                $sql = "DELETE FROM redcap_data WHERE project_id = '$pid' AND record = '$recordId' AND field_name = '$field'".$instanceClause;
                Application::log("Running SQL $sql");
                $q = db_query($sql);
                if ($error = db_error()) {
                    Application::log("SQL ERROR: " . $error);
                    throw new \Exception($error);
                } else {
                    Application::log("SQL: " . $q->affected_rows . " rows affected");
                }
            } else {
                throw new \Exception("Could not find record!");
            }
        } else {
            throw new \Exception("Wrong server");
        }
    }

        public static function deleteForm($token, $server, $pid, $prefix, $recordId, $instance = NULL) {
        $records = Download::recordIds($token, $server);
        if (Download::isCurrentServer($server)) {
            if (in_array($recordId, $records)) {
                if (!preg_match("/_$/", $prefix)) {
                    $prefix .= "_";
                }
                $instanceClause = "";
                if ($instance) {
                    if ($instance == 1) {
                        $instanceClause = " AND instance IS NULL";
                    } else {
                        $instanceClause = " AND instance = '$instance'";
                    }
                }
                $sql = "DELETE FROM redcap_data WHERE project_id = '$pid' AND record = '$recordId' AND field_name LIKE '$prefix%'".$instanceClause;
                Application::log("Running SQL $sql");
                $q = db_query($sql);
                if ($error = db_error()) {
                    Application::log("SQL ERROR: " . $error);
                    throw new \Exception($error);
                } else {
                    Application::log("SQL: " . $q->affected_rows . " rows affected");
                }
            } else {
                throw new \Exception("Could not find record!");
            }
        } else {
            throw new \Exception("Wrong server");
        }
    }

    public static function deleteRecords($token, $server, $records) {
        Application::log("Deleting ".count($records)." records");
        if (!empty($records)) {
            $data = array(
                'token' => $token,
                'action' => 'delete',
                'content' => 'record',
                'records' => $records
            );
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $server);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_VERBOSE, 0);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_AUTOREFERER, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
            $output = curl_exec($ch);
            curl_close($ch);
            Application::log("Deleted ".$output);

            $feedback = json_decode($output, TRUE);
            self::testFeedback($feedback);

            return $feedback;
        }
        return array();
    }

public static function metadata($metadata, $token, $server) {
		self::adaptToUTF8($metadata);
		if (!is_array($metadata)) {
			Application::log("Upload::metadata: first parameter should be array");
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

		self::$useAPIOnly[$token] = TRUE;
		Application::log("Upload::metadata returning $output");
		return $feedback;
	}

	private static function testFeedback($feedback, $rows = array()) {
		if (isset($feedback['error']) && $feedback['error']) {
            Application::log("Upload error: ".$feedback['error']);
			throw new \Exception($feedback['error']."\n".json_encode($rows));
		}
		if (isset($feedback['errors']) && $feedback['errors']) {
		    Application::log("Upload errors: ".implode("; ", $feedback['errors']));
			throw new \Exception(implode("; ", $feedback['errors'])."\n".json_encode($rows));
		}
		return TRUE;
	}

	public static function projectSettings($settings, $token, $server) {
		if (!$token || !$server) {
			throw new \Exception("No token or server supplied!");
		}
        self::adaptToUTF8($settings);
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
		self::testFeedback($feedback, $settings);

		self::$useAPIOnly[$token] = TRUE;
		Application::log("Upload::projectSettings returning $output");
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
			Application::log("Upload::oneRow: first parameter should be array");
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
			Application::log("Upload::rows: first parameter should be array (= '$rows')");
			echo "Upload::rows: first parameter should be array (= '$rows')!\n";
			die();
		}
		if (strlen($token) != 32) {
			Application::log("Upload::rows: second parameter should be token (= '$token')");
			echo "Upload::rows: second parameter should be token (= '$token')\n";
			die();
		}
		if (empty($rows)) {
			Application::log("WARNING! Upload::rows input is empty!");
			echo "WARNING! Upload::rows input is empty!\n";
			return "";
		}
		if (!$token || !$server) {
			throw new \Exception("No token or server supplied!");
		}
        self::adaptToUTF8($rows);
        Application::log("Upload::rows uploading ".count($rows)." rows");
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

		$pid = Application::getPID($token);
		$saveDataEligible = ($pid && $_GET['pid']  && ($pid == $_GET['pid']) && method_exists('\REDCap', 'saveData'));
		if (isset(self::$useAPIOnly[$token]) && self::$useAPIOnly[$token]) {
			$saveDataEligible = FALSE;
		}

		$allFeedback = array();
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
			if ($saveDataEligible) {
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
			if ($method == "saveData") {
				Application::log("Upload::rows $method for pid $pid returning $output in ".($time3 - $time2)." seconds");
			} else {
				Application::log("Upload::rows $method returning $output in ".($time3 - $time2)." seconds");
			}
			self::testFeedback($feedback, $rows);
			$allFeedback = self::combineFeedback($allFeedback, $feedback);
		}
		return $allFeedback;
	}

	# returns array($upload, $errors, $newCounts)
	public static function prepareFromCSV($headers, $lines, $token, $server, $pid, $metadata = array()) {
		if (empty($metadata)) {
			$metadata = Download::metadata($token, $server);
		}

		$errors = array();
		$upload = array();
		$newCounts = array("new" => 0, "existing" => 0);

		$headerPrefices = array();
		if ($headers[0] == "record_id") {
			array_push($headerPrefices, "record_id");
		} else if (($headers[0] == "identifier_last_name") && ($headers[1] == "identifier_first_name")) {
			array_push($headerPrefices, "identifier_last_name");
			array_push($headerPrefices, "identifier_first_name");
		} else if (($headers[0] == "identifier_first_name") && ($headers[1] == "identifier_last_name")) {
			array_push($headerPrefices, "identifier_first_name");
			array_push($headerPrefices, "identifier_last_name");
		} else {
			array_push($errors, "The first column's header must be record_id, identifier_first_name, or identifier_last_name. If the first column is a name field, the second field must be the other name field (identifier_last_name or identifier_first_name). Your first two columns are: {$headers[0]} and {$headers[1]}.");
		}

		$invalidHeaders = self::getInvalidHeaders($headers, $metadata);
		if (!empty($invalidHeaders)) {
			array_push($errors, "The following fields either are not valid fields or are duplicates: ".implode(", ", $invalidHeaders));
		} else {
			$repeatForms = self::getRepeatFormsFromList($headers, $headerPrefices, $metadata, $pid);
			if (count($repeatForms) == 1) {
				$recordIds = Download::recordIds($token, $server);
				$max = 0;
				foreach ($recordIds as $recordId) {
					if ($recordId > $max) {
						$max = $recordId;
					}
				}
	
				foreach ($lines as $i => $line) {
					if (self::isValidCSVLine($headers, $line)) {
						list($recordId, $newErrors) = self::getRecordIdForCSVLine($headers, $line, $i, $token, $server);
						$errors = array_merge($errors, $newErrors);
						if ($recordId) {
							$newCounts['existing']++;
						} else {
							$newCounts['new']++;
							$recordId = $max + 1;
							$max++;
						}
	
						$uploadRow = array("record_id" => $recordId);
						$formName = $repeatForms[0];
						$maxInstance = "";
						if ($formName != "") {
							$maxInstance = Download::getMaxInstanceForRepeatingForm($token, $server, $formName, $recordId); 
							$maxInstance++;
						}
						$uploadRow['redcap_repeat_instrument'] = $formName;
						$uploadRow['redcap_repeat_instance'] = $maxInstance;

						if (count($headers) == count($line)) {
							$j = 0;
							foreach ($headers as $header) {
								if (!in_array($header, $headerPrefices)) {
									$uploadRow[$header] = $line[$j];
								}
								$j++;
							}
							array_push($upload, $uploadRow);
						} else {
							array_push($errors, "On data line $i, the number of elements does not equal the number of headers (".count($headers).")");
						}
					}    // no else because errors are already supplied
				}
			} else if (count($repeatForms) == 0) {
				array_push($errors, "No data are specified in the table.");
			} else {
				array_push($errors, "More than one repeating form is specified on the same row: ".implode(", ", $repeatForms));
			}
		}
		return array($upload, $errors, $newCounts);
	}

	private static function getRepeatFormsFromList($headers, $headerPrefices, $metadata, $pid) {
		$repeatFormsInList = array();
		$allRepeatForms = Scholar::getRepeatingForms($pid);
		foreach ($headers as $header) {
			if (!in_array($header, $headerPrefices)) {
				foreach ($metadata as $row) {
					if ($row['field_name'] == $header) {
						if (in_array($row['form_name'], $allRepeatForms)) {
							if (!in_array($row['form_name'], $repeatFormsInList)) {
								array_push($repeatFormsInList, $row['form_name']);
							}
						} else {
							array_push($repeatFormsInList, "");   // normative row
						}
					}
				}
			}
		}
		return $repeatFormsInList;
	}

	private static function getInvalidHeaders($headers, $metadata) {
		$invalid = array();
		$found = array();
		foreach ($headers as $header) {
			$foundHeader = FALSE;
			foreach ($metadata as $row) {
				if ($row['field_name'] == $header) {
					$foundHeader = TRUE;
					break;
				}
			}
			if (!$foundHeader) {
				array_push($invalid, $header);
			} else if (!in_array($header, $found)) {
				array_push($found, $header);
			} else {
				# duplicate
				array_push($invalid, $header);
			}
		}
		return $invalid;
	}

	public static function isValidCSVLine($headers, $line) {
		if (($headers[0] == "identifier_last_name") && ($headers[1] == "identifier_first_name")) {
			if ($line[0] && $line[1]) {
				return TRUE;
			}
		} else if (($headers[1] == "identifier_last_name") && ($headers[0] == "identifier_first_name")) {
			if ($line[0] && $line[1]) {
				return TRUE;
			}
		} else if ($headers[0] == "record_id") {
			if ($line[0]) {
				return TRUE;
			}
		}
		return FALSE;
	}

	public static function getRecordIdForCSVLine($headers, $line, $i, $token, $server) {
		$recordId = FALSE;
		$errors = array();
		if (($headers[0] == "identifier_last_name") && ($headers[1] == "identifier_first_name")) {
			if ($line[0] && $line[1]) {
				$recordId = NameMatcher::matchName($line[1], $line[0], $token, $server);
			} else {
				array_push($errors, "On data line $i, you must supply a first and last name.");
			}
		} else if (($headers[1] == "identifier_last_name") && ($headers[0] == "identifier_first_name")) {
			if ($line[0] && $line[1]) {
				$recordId = NameMatcher::matchName($line[0], $line[1], $token, $server);
			} else {
				array_push($errors, "On data line $i, you must supply a first and last name.");
			}
		} else if ($headers[0] == "record_id") {
			if (is_numeric($line[0])) {
				$recordId = $line[0];
			} else {
				array_push($errors, "On data line $i, the record id must be numeric. It is {$line[0]}.");
			}
		} else {
			array_push($errors, "Headers not valid: {$headers[0]}, {$headers[1]}");
		}
		return array($recordId, $errors);
	}

	private static $useAPIOnly = array();
}
