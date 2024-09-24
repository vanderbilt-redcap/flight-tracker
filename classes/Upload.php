<?php

namespace Vanderbilt\CareerDevLibrary;

# This class handles commonly occuring downloads from the REDCap API.

// require_once(dirname(__FILE__)."/../../../redcap_connect.php");
require_once(__DIR__ . '/ClassLoader.php');

class Upload
{
    const MAX_RETRIES = 3;

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

    public static function userRights($userRights, $token, $server) {
        $pid = Application::getPID($token);
        $data = [
            "token" => $token,
            "content" => "user",
            "format" => "json",
            "data" => json_encode($userRights),
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $server);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch,CURLOPT_HTTPHEADER,array("Expect:"));
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
        URLManagement::applyProxyIfExists($ch, $pid);
        $output = (string) curl_exec($ch);
        $feedback = json_decode($output, TRUE);
        self::testFeedback($feedback, $output, $ch);
        curl_close($ch);
        return $feedback;
    }

    public static function createProject($supertoken, $server, $projectSetup, $pid) {
        $data = [
            'token' => $supertoken,
            'content' => 'project',
            'format' => 'json',
            'data' => json_encode([$projectSetup]),
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $server);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_VERBOSE, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
        curl_setopt($ch,CURLOPT_HTTPHEADER,array("Expect:"));
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
        URLManagement::applyProxyIfExists($ch, $pid);
        $newProjectToken = curl_exec($ch);
        curl_close($ch);
        if (REDCapManagement::isValidToken($newProjectToken)) {
            return $newProjectToken;
        } else {
            Application::log("Invalid project creation: $newProjectToken");
        }
    }

    public static function deleteField($token, $server, $pid, $field, $recordId, $instance = NULL) {
        $records = Download::recordIds($token, $server);
        if (Download::isCurrentServer($server)) {
            if (in_array($recordId, $records)) {
                $params = [$pid, $recordId, $field];
                $instanceClause = "";
                if ($instance) {
                    if ($instance == 1) {
                        $instanceClause = " AND instance IS NULL";
                    } else {
                        $instanceClause = " AND instance = ?";
                        $params[] = $instance;
                    }
                }
                $dataTable = Application::getDataTable($pid);
                $sql = "DELETE FROM $dataTable WHERE project_id = ? AND record = ? AND field_name = ?".$instanceClause;
                Application::log("Running SQL $sql with ".json_encode($params));
                $module = Application::getModule();
                $q = $module->query($sql, $params);
                Application::log("SQL: " . $q->affected_rows . " rows affected");
            } else {
                throw new \Exception("Could not find record!");
            }
        } else {
            throw new \Exception("Wrong server");
        }
    }

    public static function copyRecord($token, $server, $oldRecordId, $newRecordId) {
        $oldRecordData = Download::records($token, $server, [$oldRecordId]);
        $newRecordData = [];
        foreach ($oldRecordData as $row) {
            $row['record_id'] = $newRecordId;
            $newRecordData[] = $row;
        }
        return self::rows($newRecordData, $token, $server);
    }

    public static function deleteFormInstances($token, $server, $pid, $prefix, $recordId, $instances)
    {
        if (method_exists("\REDCap", "deleteRecord")) {
            $completeField = DataDictionaryManagement::prefix2CompleteField($prefix);
            if ($completeField) {
                $instrument = preg_replace("/_complete$/", "", $completeField);
                foreach ($instances as $instance) {
                    \REDCap::deleteRecord($pid, $recordId, NULL, NULL, $instrument, $instance);
                }
            } else {
                self::deleteBySQL($token, $server, $pid, $prefix, $recordId, $instances);
            }
        } else {
            self::deleteBySQL($token, $server, $pid, $prefix, $recordId, $instances);
        }
    }

    public static function deleteBySQL($token, $server, $pid, $prefix, $recordId, $instances) {
        $records = Download::recordIds($token, $server);
        $batchSize = 10;
        $dataTable = Application::getDataTable($pid);
        if (Download::isCurrentServer($server)) {
            if (in_array($recordId, $records)) {
                if (!preg_match("/_$/", $prefix)) {
                    $prefix .= "_";
                }
                $completeField = DataDictionaryManagement::prefix2CompleteField($prefix);
                $metadataFields = Download::metadataFields($token, $server);
                $fieldsToDelete = [];
                foreach ($metadataFields as $field) {
                    if (preg_match("/^$prefix/", $field)) {
                        $fieldsToDelete[] = $field;
                    }
                }
                if ($completeField) {
                    $fieldsToDelete[] = $completeField;
                }
                $fieldQuestionMarks = [];
                while (count($fieldsToDelete) > count($fieldQuestionMarks)) {
                    $fieldQuestionMarks[] = "?";
                }
                if (!empty($instances) && !empty($fieldsToDelete)) {
                    Application::log("Instances not empty", $pid);
                    $module = Application::getModule();
                    for ($i = 0; $i < count($instances); $i += $batchSize) {
                        $batchInstances = [];
                        for ($j = $i; ($j < $i + $batchSize) && ($j < count($instances)); $j++) {
                            $batchInstances[] = $instances[$j];
                        }
                        $instanceClause = "";
                        $params = array_merge([$pid, $recordId], $fieldsToDelete);
                        if (!empty($batchInstances)) {
                            $addOnInstanceClause =  "";
                            if (in_array(1, $batchInstances) || in_array("", $batchInstances)) {
                                $addOnInstanceClause = " OR instance IS NULL";
                                $filteredInstances = [];
                                foreach ($batchInstances as $instance) {
                                    if (!in_array($instance, ["", 1])) {
                                        $filteredInstances[] = $instance;
                                    }
                                }
                            } else {
                                $filteredInstances = $batchInstances;
                            }
                            $questionMarks = [];
                            while (count($filteredInstances) > count($questionMarks)) {
                                $questionMarks[] = "?";
                            }
                            if (!empty($filteredInstances)) {
                                $instanceClause = " AND (instance IN (".implode(",", $questionMarks).")".$addOnInstanceClause.")";
                                $params = array_merge($params, $filteredInstances);
                            } else if ($addOnInstanceClause) {
                                $instanceClause = " AND instance IS NULL";
                            }
                        }

                        $whereClause = "WHERE project_id = ? AND record = ? AND field_name IN (".implode(",", $fieldQuestionMarks).")".$instanceClause;
                        do {

                            $startTs = time();
                            $sql = "DELETE FROM $dataTable ".$whereClause." LIMIT 1000";
                            Application::log("Running SQL $sql with ".json_encode($params));
                            $module->query($sql, $params);
                            $numSecs = time() - $startTs;
                            Application::log("In $numSecs seconds, ran SQL $sql with ".json_encode($params), $pid);

                            $sql = "SELECT record FROM $dataTable ".$whereClause." LIMIT 1";
                            $q = $module->query($sql, $params);
                        } while ($q->num_rows > 0);
                    }
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
        $dataTable = Application::getDataTable($pid);
        if (Download::isCurrentServer($server)) {
            if (in_array($recordId, $records)) {
                if ($instance !== NULL) {
                    self::deleteFormInstances($token, $server, $pid, $prefix, $recordId, [$instance]);
                } else {
                    $module = Application::getModule();
                    $params = [$pid, $recordId, "$prefix%"];
                    $sql = "DELETE FROM $dataTable WHERE project_id = ? AND record = ? AND field_name LIKE ?";
                    Application::log("Running SQL $sql with ".json_encode($params));
                    $q = $module->query($sql, $params);
                    Application::log("SQL: " . $q->affected_rows . " rows affected");
                }
            } else {
                throw new \Exception("Could not find record $recordId!");
            }
        } else {
            throw new \Exception("Wrong server");
        }
    }

    public static function deleteRecords($token, $server, $records) {
        Application::log("Deleting ".count($records)." records");
        if (!empty($records)) {
            $pid = Application::getPID($token);
            if ($pid && Download::isCurrentServer($server) && method_exists("\REDCap", "deleteRecord")) {
                $feedback = [];
                foreach ($records as $recordId) {
                    $feedback[] = \REDCap::deleteRecord($pid, $recordId);
                }
                return $feedback;
            } else {
                $server = Sanitizer::sanitizeURL($server);
                if (!$server) {
                    throw new \Exception("Invalid URL");
                }
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
                curl_setopt($ch,CURLOPT_HTTPHEADER,array("Expect:"));
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
                URLManagement::applyProxyIfExists($ch, $pid);
                $output = (string) curl_exec($ch);
                $feedback = json_decode($output, TRUE);
                self::testFeedback($feedback, $output, $ch, [], $pid);
                curl_close($ch);
                Application::log("Deleted ".$output);

                return $feedback;
            }
        }
        return array();
    }

    // avoids design rights and development mode issues
    public static function metadataNoAPI($metadata, $pid) {
        if (!is_array($metadata)) {
            Application::log("Upload::metadataNoAPI: first parameter should be array", $pid);
            die();
        }
        if (!Sanitizer::sanitizePid($pid)) {
            throw new \Exception("Invalid pid");
        }

        require_once(APP_PATH_DOCROOT."Classes/MetaData.php");
        require_once(APP_PATH_DOCROOT."Classes/Logging.php");

        self::adaptToUTF8($metadata);

        if (!defined("PROJECT_ID")) {
            define("PROJECT_ID", $pid);    // to satisfy Metadata::error_checking
        }
        \MetaData::createDataDictionarySnapshot();

        // Save a flat item-based metadata array
        $dd_array = \MetaData::convertFlatMetadataToDDarray($metadata);
        list ($errors, $warnings) = \MetaData::error_checking($dd_array, FALSE, FALSE);
        if (!empty($errors)) {
            throw new \Exception("Input errors: ".strip_tags(implode("\n", $errors)));
        }

        $errors = \MetaData::save_metadata($dd_array, FALSE, TRUE, $pid);
        $count = count($dd_array['A']);

        $_SESSION['metadata'.$pid] = [];
        $_SESSION['lastMetadata'.$pid] = 0;

        // Return any errors found when attempting to commit
        if (!empty($errors)) {
            throw new \Exception("Upload errors: ".strip_tags(implode("\n", $errors)));
        } else {
            \Logging::logEvent("", "redcap_metadata", "MANAGE", $pid, "project_id = " . $pid, "Upload data dictionary");

            return $count;
        }

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
        $server = Sanitizer::sanitizeURL($server);
        if (!$server) {
            throw new \Exception("Invalid URL");
        }

        $pid = Application::getPID($token);
        if ($pid) {
            return self::metadataNoAPI($metadata, $pid);
        }
        if (REDCapManagement::isInProduction($pid)) {
            REDCapManagement::setToDevelopment($pid);
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch,CURLOPT_HTTPHEADER,array("Expect:"));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
        URLManagement::applyProxyIfExists($ch, $pid);
		$output = (string) curl_exec($ch);
        $feedback = json_decode($output, TRUE);
        self::testFeedback($feedback, $output, $ch, $metadata, $pid);
		curl_close($ch);

        $pid = Application::getPID($token);

        if ($pid) {
            $_SESSION['metadata'.$pid] = [];
            $_SESSION['lastMetadata'.$pid] = 0;
        }

		self::$useAPIOnly[$token] = TRUE;
		Application::log("Upload::metadata returning $output", $pid);
		return $feedback;
	}

	private static function testFeedback($feedback, $originalText, $curlHandle, $rows = array(), $pid = NULL) {
        if (!$feedback && ($curlHandle !== FALSE)) {
            $returnCode = curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($curlHandle);
            if ($returnCode != 200) {
                throw new \Exception("Upload error (non-JSON): $originalText [$returnCode] - $curlError");
            }
        } else if (isset($feedback['error']) && $feedback['error']) {
            Application::log("Upload error: ".$feedback['error'], $pid);
            if (preg_match("/Each multiple choice field \(radio, drop-down, checkbox, etc\.\) must have choices listed in column F, but the following\s+cells have choices missing: ([F\d\s,]+)/", $feedback['error'], $matches)) {
                $cells = explode(", ", $matches[1]);
                $displayRows = [];
                foreach ($cells as $cell) {
                    $cell = trim($cell);
                    $lineNum = (int) str_replace("F", "", $cell);
                    $displayRows[] = $rows[$lineNum - 2];
                }
                throw new \Exception("Error: ".$feedback['error']."\n".json_encode($displayRows));
            } else {
                throw new \Exception("Error: ".$feedback['error']."\n".json_encode($rows));
            }
		}
		if (isset($feedback['errors']) && $feedback['errors']) {
		    if (is_array($feedback['errors'])) {
                Application::log("Upload errors: ".implode("; ", $feedback['errors']));
                $errors = $feedback['errors'];
                foreach ($errors as $i => $error) {
                    $ary = str_getcsv($error);
                    $errors[$i] = "<strong>Record {$ary[0]}</strong><br/>Field: {$ary[1]}<br/>Wrong Value: '{$ary[2]}'<br/>Error: {$ary[3]}";
                }
                throw new \Exception("<h2>".count($errors)." Errors</h2><p>".implode("</p>", $errors)."\n".json_encode($rows));
            } else {
                Application::log("Upload errors: ".$feedback['errors']);
                throw new \Exception("Errors: ".$feedback['errors']."\n".json_encode($rows));
            }
		}
		return TRUE;
	}

	public static function file($pid, $record, $field, $originalBase64, $filename, $instance = 1) {
        $base64 = preg_replace("/^data:.+?;base64,\s?/", "", $originalBase64);
        $base64 = str_replace(" ", "+", $base64);
        $contents = base64_decode($base64);
        if ($contents) {
            $file = [];
            $fullFilename = REDCapManagement::makeSafeFilename(substr(sha1((string) rand()), 0, 6)."_".$filename);
            $file['tmp_name'] = APP_PATH_TEMP.$fullFilename;
            $file['size'] = strlen($contents);
            $file['name'] = $filename;

            file_put_contents($file['tmp_name'], $contents);

            $module = Application::getModule();
            $sql = "SELECT m.event_id AS event_id FROM redcap_events_arms AS a INNER JOIN redcap_events_metadata AS m ON a.arm_id = m.arm_id WHERE a.project_id = ?";
            $q = $module->query($sql, [$pid]);
            if ($row = $q->fetch_assoc($q)) {
                $event_id = $row['event_id'];
            } else {
                return ["error" => "Could not locate event_id!"];
            }

            require_once(APP_PATH_DOCROOT."Classes/Files.php");
            $docId = \Files::uploadFile($file, $pid);

            $whereClause = "project_id = ? AND event_id = ? AND record = ? AND field_name = ? AND instance = ?";

            $dataTable = Application::getDataTable($pid);
            $params = [$pid, $event_id, $record, $field, $instance];
            $sql = "SELECT `value` FROM $dataTable WHERE $whereClause";
            $result = $module->query($sql, $params);
            if ($result->num_rows > 0) {
                $params = [$docId, $pid, $event_id, $record, $field, $instance];
                $sql = "UPDATE $dataTable SET `value` = ? WHERE $whereClause";
            } else {
                $params = [$pid, $event_id, $record, $field, $docId, $instance];
                $sql = "INSERT INTO $dataTable (project_id, event_id, record, field_name, `value`, `instance`)
                        VALUES (?, ?, ?, ?, ?, ?)";
            }

            $module->query($sql, $params);
            return ["doc_id" => $docId];
        } else {
            return ["error" => "Could not decode base64"];
        }
    }

    public static function projectSettingsNotAPI($settings, $pid) {
        require_once(APP_PATH_DOCROOT."Classes/Project.php");
        require_once(APP_PATH_DOCROOT."Classes/Logging.php");

        $Proj = new \Project($pid);
        \Logging::logEvent("", "redcap_projects", "MANAGE", $pid, "project_id = " . $pid, "Import project information");

        $project_fields = \Project::getAttributesApiExportProjectInfo();
        foreach ($project_fields as $key => $hdr)
        {
            if (isset($settings[$hdr]))
            {
                $Proj->project[$key] = $settings[$hdr];
            }
        }
        $Proj->setProjectValues();
        return count($settings);
    }

	public static function projectSettings($settings, $token, $server, $useAPIOnly = FALSE) {
		if (!$token || !$server) {
			throw new \Exception("No token or server supplied!");
		}
        if (!$useAPIOnly) {
            $pid = Application::getPID($token);
            if ($pid) {
                return self::projectSettingsNotAPI($settings, $pid);
            }
        }
        $server = Sanitizer::sanitizeURL($server);
        if (!$server) {
            throw new \Exception("Invalid URL");
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
        curl_setopt($ch, CURLOPT_HTTPHEADER,array("Expect:"));
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $pid = isset($data['token']) ? Application::getPID($data['token']) : "";
        URLManagement::applyProxyIfExists($ch, $pid);
        $output = (string) curl_exec($ch);
        $feedback = json_decode($output, TRUE);
        self::testFeedback($feedback, $output, $ch, $settings);
        curl_close($ch);

		self::$useAPIOnly[$token] = TRUE;
		if (isset($_GET['test'])) {
            Application::log("Upload::projectSettings returning $output");
        }
		return $feedback;
	}

	public static function resource($recordId, $value, $token, $server, $date = "AUTOFILL", $grant = "") {
		$redcapData = Download::resources($token, $server, $recordId);
        $metadataFields = Download::metadataFields($token, $server);
		if ($date == "AUTOFILL") {
			$date = date("Y-m-d");
		}
        $maxInstance = REDCapManagement::getMaxInstance($redcapData, "resources", $recordId);
		$maxInstance++;

		$uploadRow = [
            "record_id" => $recordId,
            "redcap_repeat_instrument" => "resources",
            "redcap_repeat_instance" => $maxInstance,
            "resources_date" => $date,
            "resources_resource" => $value,
            "resources_complete" => "2",
        ];
        if ($grant && in_array("resources_grant", $metadataFields)) {
            $uploadRow["resources_grant"] = $grant;
        }

		return self::oneRow($uploadRow, $token, $server);
	}

	# returns an array of the errors from the upload result
	public static function isolateErrors($result) {
		if (is_array($result) && $result['errors']) {
			return $result['errors'];
		} else if (is_string($result)) {
			$result = json_decode($result, true);
			if ($result && $result['errors']) {
				return $result['errors'];
			} else {
				return array();
			}
		}
		return [];
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

    private static function filterOutDescriptiveFields(array &$rows, $pid): void {
        if (!$pid) {
            return;
        }
        $descriptiveFields = Download::metadataFieldsByPidOfType($pid, "descriptive");
        foreach ($rows as &$row) {
            foreach ($row as $field => $value) {
                if (in_array($field, $descriptiveFields)) {
                    unset($row[$field]);
                }
            }
        }
    }

	public static function rowsByPid($rows, $pid) {
        if (!self::checkRows($rows)) {
            return "";
        }
        self::filterOutDescriptiveFields($rows, $pid);
        if (!is_numeric($pid)) {
            Application::log("Non-numeric pid $pid");
            echo "Non-numeric pid $pid\n";
            die();
        }
        if (REDCapManagement::versionGreaterThanOrEqualTo(REDCAP_VERSION, "12.5.2")) {
            $feedback = \REDCap::saveData($pid, "json-array", $rows, "overwrite");
        } else {
            $feedback = \REDCap::saveData($pid, "json", json_encode($rows), "overwrite");
        }
        $output = json_encode($feedback);
        self::testFeedback($feedback, $output, FALSE, [], $pid);
        return $feedback;
    }

    private static function checkRows($rows) {
        if (!is_array($rows)) {
            $rows = REDCapManagement::sanitize($rows);
            Application::log("Upload::rows: first parameter should be array (= '$rows')");
            echo "Upload::rows: first parameter should be array (= '$rows')!\n";
            die();
        }
        if (empty($rows)) {
            Application::log("WARNING! Upload::rows input is empty!");
            echo "WARNING! Upload::rows input is empty!\n";
            return "";
        }
        return TRUE;
    }

	public static function rows($rows, $token, $server, $retryCount = 0) {
        if (!self::checkRows($rows)) {
            if (isset($_GET['test'])) {
                echo "Failed test<br>";
            }
            return "";
        }
        if (isset($_GET['test'])) {
            echo "Passed test: ".count($rows)."<br>";
        }
		if (!REDCapManagement::isValidToken($token)) {
			Application::log("Upload::rows: second parameter should be token (= '$token')");
			echo "Upload::rows: second parameter should be token (= '$token')\n";
			die();
		}
		if (!$token || !$server) {
			throw new \Exception("No token or server supplied!");
		}
        $pid = Application::getPID($token);
        self::filterOutDescriptiveFields($rows, $pid);
        self::adaptToUTF8($rows);
		if (isset($_GET['test'])) {
            Application::log("Upload::rows uploading ".count($rows)." rows");
        }
		if (count($rows) > self::getRowLimit()) {
			$rowsOfRows = array();
			$i = 0;
			while ($i < count($rows)) {
				$currRows = array();
				$j = $i;
				while (($j < $i + self::getRowLimit()) && ($j < count($rows))) {
					$currRows[] = $rows[$j];
					$j++;
				}
				if (!empty($currRows)) {
					$rowsOfRows[] = $currRows;
				}
				$i += self::getRowLimit();
			}
		} else {
			$rowsOfRows = array($rows);
		}

        foreach (array_keys($rowsOfRows) as $i) {
            self::handleLargeJSONs($rowsOfRows[$i], $pid);
        }

		$saveDataEligible = ($pid && $_GET['pid']  && ($pid == $_GET['pid']) && method_exists('\REDCap', 'saveData'));
		if (isset(self::$useAPIOnly[$token]) && self::$useAPIOnly[$token]) {
			$saveDataEligible = FALSE;
		}

        $method = "";
		$allFeedback = array();
		foreach ($rowsOfRows as $rows) {
            $method = "";
            $feedback = [];
			$data = array(
				'token' => $token,
				'content' => 'record',
				'format' => 'json',
				'type' => 'flat',
				'overwriteBehavior' => 'overwrite',
				'returnContent' => 'count',
				'returnFormat' => 'json'
				);
			$runAPI = TRUE;
			$output = "";
			$time2 = FALSE;
			$time3 = FALSE;
			if ($saveDataEligible) {
				$method = "saveData";
				$time2 = microtime(TRUE);
				if (method_exists("\\REDCap", "saveData")) {
                    if (REDCapManagement::versionGreaterThanOrEqualTo(REDCAP_VERSION, "12.5.2")) {
                        $feedback = \REDCap::saveData($pid, "json-array", $rows, $data['overwriteBehavior']);
                    } else {
                        $feedback = \REDCap::saveData($pid, "json", json_encode($rows), $data['overwriteBehavior']);
                    }
                    $time3 = microtime(TRUE);
                    $output = json_encode($feedback);
                    self::testFeedback($feedback, $output, NULL, [], $pid);
                    $runAPI = FALSE;
                }
			}
			if ($runAPI) {
                $server = Sanitizer::sanitizeURL($server);
                if (!$server) {
                    throw new \Exception("Invalid URL");
                }
                $data['data'] = json_encode($rows);
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
                curl_setopt($ch, CURLOPT_HTTPHEADER,array("Expect:"));
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
                URLManagement::applyProxyIfExists($ch, $pid);
                $time2 = microtime(TRUE);
				$output = (string) curl_exec($ch);
                $feedback = json_decode($output, true);
                curl_close($ch);
                $time3 = microtime(TRUE);

                try {
                    self::testFeedback($feedback, $output, $ch, $rows, $pid);
                } catch (\Exception $e) {
                    if ($retryCount < self::MAX_RETRIES) {
                        $retryCount++;
                        if ($retryCount == self::MAX_RETRIES) {
                            sleep(60);
                        } else {
                            sleep(5);
                        }
                        $feedback = self::rows($rows, $token, $server, $retryCount);
                    } else {
                        throw new \Exception($e->getMessage());
                    }
                }
                Download::throttleIfNecessary($pid);
			}
			if (isset($_GET['test']) && $time3 && $time2) {
                if ($method == "saveData") {
                    Application::log("Upload::rows $method for pid $pid returning $output in ".($time3 - $time2)." seconds");
                } else {
                    Application::log("Upload::rows $method returning $output in ".($time3 - $time2)." seconds");
                }
            }
			if (!empty($feedback)) {
                $allFeedback = self::combineFeedback($allFeedback, $feedback);
            }
		}
		Application::log($method." (pid $pid): ".REDCapManagement::json_encode_with_spaces($allFeedback), $pid);
		return $allFeedback;
	}

    private static function handleLargeJSONs(&$rows, $pid) {
        $maxLength = 65000;
        foreach (array_keys($rows) as $i) {
            foreach ($rows[$i] as $field => $value) {
                if ((strpos($field, "summary_calculate_") !== FALSE) && strlen($value) > $maxLength) {
                    $recordId = $rows[$i]["record_id"];
                    $key = $field."___".$recordId;
                    Application::saveSetting($key, $value, $pid);
                    $rows[$i][$field] = $key;
                }
            }
        }
    }

    # note: do not use this with VERIFYPEER - too many servers use self-signed certificates
	public static function isProductionServer($pid) {
        if (method_exists("\Vanderbilt\CareerDevLibrary\Application", "isTestServer")) {
            return !Application::isTestServer($pid);
        }
        return FALSE;
    }

    public static function makeIds($rows) {
        $ids = [];
        $sep = ":";
        foreach ($rows as $row) {
            $instrument = $row['redcap_repeat_instrument'] ?? "";
            $recordId = $row['record_id'];
            $instance = $row['redcap_repeat_instance'] ?? "";
            if (!$instance && !$instrument) {
                $ids[] = $recordId.$sep."NORMATIVE";
            } else {
                $ids[] = $recordId.$sep.$instrument.$sep.$instance;
            }
        }
        return $ids;
    }

	# returns array($upload, $errors, $newCounts)
	public static function prepareFromCSV($headers, $lines, $token, $server, $pid, $metadata = array()) {
		if (empty($metadata)) {
			$metadata = Download::metadata($token, $server);
		}
		$choices = DataDictionaryManagement::getChoices($metadata);
        $dateFields = DataDictionaryManagement::getDateFields($metadata);

		$errors = array();
		$upload = array();
		$newCounts = array("new" => 0, "existing" => 0);

		$headerPrefices = array();
		if ($headers[0] == "record_id") {
			$headerPrefices[] = "record_id";
		} else if (($headers[0] == "identifier_last_name") && ($headers[1] == "identifier_first_name")) {
			$headerPrefices[] = "identifier_last_name";
			$headerPrefices[] = "identifier_first_name";
		} else if (($headers[0] == "identifier_first_name") && ($headers[1] == "identifier_last_name")) {
			$headerPrefices[] = "identifier_first_name";
			$headerPrefices[] = "identifier_last_name";
		} else {
			$errors[] = "The first column's header must be record_id, identifier_first_name, or identifier_last_name. If the first column is a name field, the second field must be the other name field (identifier_last_name or identifier_first_name). Your first two columns are: {$headers[0]} and {$headers[1]}.";
		}

		$invalidHeaders = self::getInvalidHeaders($headers, $metadata);
		if (!empty($invalidHeaders)) {
			$errors[] = "The following fields either are not valid fields or are duplicates: ".implode(", ", $invalidHeaders);
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

				$maxInstance = FALSE;
				$records = Download::recordIds($token, $server);
				foreach ($lines as $i => $line) {
					if (self::isValidCSVLine($headers, $line)) {
						list($recordId, $newErrors) = self::getRecordIdForCSVLine($headers, $line, $i, $token, $server, $records);
						$errors = array_merge($errors, $newErrors);
						if ($recordId) {
							$newCounts['existing']++;
                            $isNewRecord = FALSE;
						} else {
							$newCounts['new']++;
							$recordId = $max + 1;
							$max++;
                            $isNewRecord = TRUE;
						}
	
						$uploadRow = array("record_id" => $recordId);
						$formName = $repeatForms[0];
						if ($formName === "") {
                            $maxInstance = "";
                        } else {
						    if (!$maxInstance) {
                                $maxInstance = Download::getMaxInstanceForRepeatingForm($token, $server, $formName, $recordId);
                            }
							$maxInstance++;
						}
						$uploadRow['redcap_repeat_instrument'] = $formName;
						$uploadRow['redcap_repeat_instance'] = $maxInstance;

						if (count($headers) == count($line)) {
							$j = 0;
							foreach ($headers as $header) {
								if (!in_array($header, $headerPrefices)) {
								    if (isset($choices[$header])) {
                                        if (isset($choices[$header][$line[$j]])) {
                                            $uploadRow[$header] = $line[$j];
                                        } else if (DateManagement::isNumericalMonth($line[$j])) {
                                            $intMonth = (int)$line[$j];
                                            $strMonth = "0$intMonth";
                                            if ($intMonth && isset($choices[$header][$intMonth])) {
                                                $uploadRow[$header] = $intMonth;
                                            } else if ($intMonth && isset($choices[$header][$strMonth])) {
                                                $uploadRow[$header] = $strMonth;
                                            }
                                        } else if (preg_match("/^\d+-\w{3}$/", $line[$j])) {
                                            # Excel saves this for the current year
                                            # assume that they did not last save last year
                                            $year = date("Y");
                                            list($day, $monthStr) = explode("-", $line[$j]);
                                            $month = DateManagement::getMonthNumber($monthStr);
                                            $uploadRow[$header] = "$year-$month-$day";
                                        } else {
                                            foreach ($choices[$header] as $idx => $val) {
                                                if ($val == $line[$j]) {
                                                    $uploadRow[$header] = $idx;
                                                    break;
                                                }
                                            }
                                            if (!isset($uploadRow[$header])) {
                                                $lowerVal = strtolower($line[$j]);
                                                foreach ($choices[$header] as $idx => $val) {
                                                    if ($lowerVal == strtolower($val)) {
                                                        $uploadRow[$header] = $idx;
                                                        break;
                                                    }
                                                }
                                            }
                                            if (!isset($uploadRow[$header])) {
                                                # invalid value
                                                $uploadRow[$header] = $line[$j];
                                            }
                                        }
                                    } else if (in_array($header, $dateFields)) {
                                        if (DateManagement::isYear($line[$j])) {
                                            $uploadRow[$header] = $line[$j]."-07-01";
                                        } else if (DateManagement::isDate($line[$j])) {
                                            $uploadRow[$header] = $line[$j];
                                        } else {
                                            # invalid
                                            $uploadRow[$header] = $line[$j];
                                        }
                                    } else {
                                        $uploadRow[$header] = $line[$j];
                                    }
								} else if (!isset($uploadRow[$header]) && $isNewRecord) {
                                    $uploadRow[$header] = $line[$j];
                                }
								$j++;
							}
							$upload[] = $uploadRow;
						} else {
							$errors[] = "On data line $i, the number of elements does not equal the number of headers (" . count($headers) . ")";
						}
					}    // no else because errors are already supplied
				}
			} else if (count($repeatForms) == 0) {
				$errors[] = "No data are specified in the table.";
			} else {
				$errors[] = "More than one repeating form is specified on the same row: " . implode(", ", $repeatForms);
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
                            if (!in_array("", $repeatFormsInList)) {
                                array_push($repeatFormsInList, "");   // normative row
                            }
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
        $metadataFields = DataDictionaryManagement::getFieldsFromMetadata($metadata);
		foreach ($headers as $header) {
            if (preg_match("/___/", $header)) {
                $nodes = preg_split("/___/", $header);
                $fieldName = $nodes[0];
            } else {
                $fieldName = $header;
            }

			if (!in_array($fieldName, $metadataFields)) {
				$invalid[] = $header." (invalid)";
			} else if (!in_array($header, $found)) {
				$found[] = $header;
			} else {
				# duplicate
				$invalid[] = $header." (Duplicate)";
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

	public static function getRecordIdForCSVLine($headers, $line, $i, $token, $server, $records = []) {
        if (empty($records)) {
            $records = Download::recordIds($token, $server);
        }
		$recordId = FALSE;
		$errors = array();
		if (($headers[0] == "identifier_last_name") && ($headers[1] == "identifier_first_name")) {
			if ($line[0] && $line[1]) {
				$recordId = NameMatcher::matchName($line[1], $line[0], $token, $server);
			} else {
				$errors[] = "On data line $i, you must supply a first and last name.";
			}
		} else if (($headers[1] == "identifier_last_name") && ($headers[0] == "identifier_first_name")) {
			if ($line[0] && $line[1]) {
				$recordId = NameMatcher::matchName($line[0], $line[1], $token, $server);
			} else {
				$errors[] = "On data line $i, you must supply a first and last name.";
			}
		} else if ($headers[0] == "record_id") {
			if (is_numeric($line[0])) {
			    if (REDCapManagement::exactInArray($line[0], $records)) {
                    $recordId = $line[0];
                } else {
			        $errors[] = "On data line $i, your record {$line[0]} is not matching an existing record. Only existing records are supported.";
                }
			} else {
				$errors[] = "On data line $i, the record id must be numeric. It is {$line[0]}.";
			}
		} else {
			$errors[] = "Headers not valid: {$headers[0]}, {$headers[1]}";
		}
		return [$recordId, $errors];
	}

	private static $useAPIOnly = array();
}
