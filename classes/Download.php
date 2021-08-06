<?php

namespace Vanderbilt\CareerDevLibrary;

# This class handles commonly occuring downloads from the REDCap API.

// require_once(dirname(__FILE__)."/../../../redcap_connect.php");
require_once(__DIR__ . '/ClassLoader.php');

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

	public static function throttleIfNecessary($pid) {
	    if (self::$rateLimitPerMinute === NULL) {
	        $sql = "SELECT * FROM redcap_config WHERE field_name = 'page_hit_threshold_per_minute'";
            $q = db_query($sql);
            if ($error = db_error()) {
                Application::log("ERROR: $error", $pid);
            }
            if ($row = db_fetch_assoc($q)) {
                self::$rateLimitPerMinute = $row['value'];
            }
        }
        if (!self::$rateLimitTs) {
            self::$rateLimitTs = time();
        }
        $thresholdFraction = 0.75;
	    if (self::$rateLimitPerMinute && (self::$rateLimitCounter * $thresholdFraction > self::$rateLimitPerMinute)) {
	        $timespanExpended = time() - self::$rateLimitTs;
	        $sleepTime = 60 - $timespanExpended + 5;
	        Application::log("Sleeping $sleepTime seconds to avoid REDCap's API rate-limiter", $pid);
	        sleep($sleepTime);
	        self::$rateLimitCounter = 0;
	        self::$rateLimitTs = time();
        }
	    if (self::$rateLimitTs < time() - 60) {
	        self::$rateLimitTs = time();
	        self::$rateLimitCounter = 0;
        }
	    self::$rateLimitCounter++;
    }

	public static function getIndexedRedcapData($token, $server, $fields, $cohort = "", $metadataOrModule = array()) {
		$redcapData = self::getFilteredRedcapData($token, $server, $fields, $cohort, $metadataOrModule);
		return self::indexREDCapData($redcapData);
	}

	public static function predocNames($token, $server, $metadataOrModule = [], $cohort = "", $names = []) {
	    if (empty($names)) {
            $names = self::names($token, $server);
        }
		$predocs = array();
		$records = self::recordsWithTrainees($token, $server, array(6));
		if ($cohort) {
            $cohortConfig = self::getCohortConfig($token, $server, $metadataOrModule, $cohort);
            if ($cohortConfig) {
                $filter = new Filter($token, $server, $metadataOrModule);
                $allPredocs = $records;
                $cohortRecords = $filter->getRecords($cohortConfig);
                $records = [];
                foreach ($allPredocs as $recordId) {
                    if (in_array($recordId, $cohortRecords)) {
                        $records[] = $recordId;
                    }
                }
            }
        }
        $records = self::filterByManualInclusion($records, $token, $server, $metadataOrModule);
        foreach ($records as $recordId) {
			$predocs[$recordId] = $names[$recordId];
		}
		return $predocs;
	}

	private static function filterByManualInclusion($records, $token, $server, $metadataOrModule) {
	    if (is_array($metadataOrModule)) {
            $metadata = $metadataOrModule;
        } else {
            $metadata = self::metadata($token, $server);
        }
	    $field = "identifier_table_include";
	    $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
	    if (!in_array($field, $metadataFields)) {
	        return $records;
        }

	    $includeData = self::oneField($token, $server, $field);
	    $includedRecords = [];
	    foreach ($records as $recordId) {
	        if ($includeData[$recordId] == '1') {
	            $includedRecords[] = $recordId;
            }
        }
	    return $includedRecords;
    }

    public static function postdocAppointmentNames($token, $server, $metadataOrModule = [], $cohort = "") {
        $names = self::names($token, $server);
        $postdocTrainees = self::recordsWithTrainees($token, $server, [7]);
        if ($cohort) {
            $cohortConfig = self::getCohortConfig($token, $server, $metadataOrModule, $cohort);
            if ($cohortConfig) {
                $filter = new Filter($token, $server, $metadataOrModule);
                $cohortRecords = $filter->getRecords($cohortConfig);
                $records = [];
                foreach ($postdocTrainees as $recordId) {
                    if (in_array($recordId, $cohortRecords)) {
                        $records[] = $recordId;
                    }
                }
            } else {
                $records = $postdocTrainees;
            }
        } else {
            $records = $postdocTrainees;
        }
        $records = self::filterByManualInclusion($records, $token, $server, $metadataOrModule);
        $postdocs = [];
        foreach ($records as $recordId) {
            $postdocs[$recordId] = $names[$recordId];
        }
        return $postdocs;
	}

    public static function postdocNames($token, $server, $metadataOrModule = [], $cohort = "") {
		$names = self::names($token, $server);
		$predocs = self::predocNames($token, $server, $metadataOrModule, $cohort, $names);
		$postdocs = array();
        if ($cohort) {
            $cohortConfig = self::getCohortConfig($token, $server, $metadataOrModule, $cohort);
            if ($cohortConfig) {
                $filter = new Filter($token, $server, $metadataOrModule);
                $everyone = array_keys($names);
                $cohortRecords = $filter->getRecords($cohortConfig);
                $records = [];
                foreach ($everyone as $recordId) {
                    if (in_array($recordId, $cohortRecords)) {
                        $records[] = $recordId;
                    }
                }
            } else {
                $records = array_keys($names);
            }
        } else {
            $records = array_keys($names);
        }
		foreach ($records as $recordId) {
		    if (!isset($predocs[$recordId])) {
                $postdocs[$recordId] = $names[$recordId];
            }
		}
		return $postdocs;
	}

	public static function redcapVersion($token, $server) {
	    $data = [
	        "token" => $token,
            "content" => "version"
        ];
	    return self::sendToServer($server, $data);
    }

	# returns a hash with recordId => array of mentorUserids
	public static function primaryMentorUserids($token, $server) {
		$mentorUserids = Download::oneField($token, $server, "summary_mentor_userid");
		foreach ($mentorUserids as $recordId => $userid) {
			if ($userid) {
				$recordUserids = preg_split("/\s*[;,]\s*/", $userid);
				$mentorUserids[$recordId] = $recordUserids;
			} else {
				unset($mentorUserids[$recordId]);
			}
		}
		return $mentorUserids;
	}

	# returns array of $recordId => $menteeName
	public static function menteesForMentor($token, $server, $requestedMentorUserid) {
		$mentorUserids = self::primaryMentorUserids($token, $server);
		$names = self::names($token, $server);

		$menteeNames = array();
		foreach ($mentorUserids as $recordId => $mentorUserids) {
			if (in_array($requestedMentorUserid, $mentorUserids)) {
				$menteeNames[$recordId] = $names[$recordId];
			}
		}
		return $menteeNames;
	}

	public static function allMentors($token, $server, $metadata = []) {
	    if (empty($metadata)) {
            $metadata = self::metadata($token, $server);
        }
	    $scholar = new Scholar($token, $server, $metadata, Application::getPID($token));
	    $fields = $scholar->getMentorFields();
	    if (!in_array("record_id", $fields)) {
	        $fields[] = "record_id";
        }
	    $indexedREDCapData = self::indexREDCapData(self::fields($token, $server, $fields));
	    $mentors = [];
	    foreach ($indexedREDCapData as $recordId => $rows) {
            $scholar = new Scholar($token, $server, $metadata, Application::getPID($token));
            $scholar->setRows($rows);
            $mentors[$recordId] = $scholar->getAllMentors();
        }
	    return $mentors;
    }

	# returns a hash with recordId => array of mentorNames
	public static function primaryMentors($token, $server) {
		$mentors = self::oneField($token, $server, "summary_mentor");
		foreach ($mentors as $recordId => $mentor) {
			if ($mentor) {
                $regex = "/\s*;\s*/";
                if (preg_match("/[A-Za-z\.]+\s+[A-Za-z\.]+\s*,\s*[A-Za-z\.]+\s+[A-Za-z\.]+/", $mentor)) {
                    # separating two names - not last name, first name
                    $regex = "/\s*[;,]\s*/";
                }
				$recordMentors = preg_split($regex, $mentor);
				$prettyRecordMentors = array();
				foreach ($recordMentors as $recordMentor) {
                    $recordMentor = NameMatcher::pretty($recordMentor);
					array_push($prettyRecordMentors, $recordMentor);
				}
				$mentors[$recordId] = $prettyRecordMentors;
			} else {
				unset($mentors[$recordId]);
			}
		}
		return $mentors;
	}

	public static function trainingGrants($token, $server, $fields = [], $traineeTypes = [5, 6, 7], $records = [], $metadata = []) {
		if (empty($fields)) {
            if (empty($metadata)) {
                $metadata = self::metadata($token, $server);
            }
			$fields = Application::getCustomFields($metadata);      // default
		}
		if (empty($records)) {
		    $records = self::recordIds($token, $server);
        }
		$requiredFields = array("record_id", "custom_role");
		foreach ($requiredFields as $field) {
			if (!in_array($field, $fields)) {
				throw new \Exception("Could not find required '$field' field in fields!");
			}
		}
		$redcapData = self::fieldsForRecords($token, $server, $fields, $records);
		$filteredData = array();
		foreach ($redcapData as $row) {
			if (in_array($row['custom_role'], $traineeTypes)) {
				array_push($filteredData, $row);
			}
		}
		return $filteredData;
	}

    public static function appointmentsForRecord($token, $server, $record, $traineeTypes = [5, 6, 7], $metadata = []) {
        $redcapData = self::trainingGrants($token, $server, array("record_id", "custom_role"), $traineeTypes, [$record], $metadata);
        $types = [];
        foreach ($redcapData as $row) {
            $roleType = $row['custom_role'];
            if (in_array($row['custom_role'], $traineeTypes) && !in_array($roleType, $types)) {
                $types[] = $roleType;
            }
        }
        return $types;
    }

	public static function recordsWithTrainees($token, $server, $traineeTypes = [5, 6, 7], $metadata = []) {
		$redcapData = self::trainingGrants($token, $server, ["record_id", "custom_role"], $traineeTypes, $metadata);
		$records = array();
		foreach ($redcapData as $row) {
		    $recordId = $row['record_id'];
		    if (!in_array($recordId, $records)) {
                array_push($records, $recordId);
            }
		}
		return $records;
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

	public static function getFilteredRedcapData($token, $server, $fields, $cohort = "", $metadataOrModule = array()) {
		if ($token && $server && $fields && !empty($fields)) {
			if ($cohort) {
				$records = self::cohortRecordIds($token, $server, $metadataOrModule, $cohort);
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
		if (isset($_GET['test'])) {
            Application::log("Download::metadata");
        }
		$pid = Application::getPID($token);
		if (preg_match("/".SERVER_NAME."/", $server) && $pid) {
		    if (!empty($fields)) {
                $json = \REDCap::getDataDictionary($pid, "json", TRUE, $fields);
            } else {
                $json = \REDCap::getDataDictionary($pid, "json");
            }
            return json_decode($json, TRUE);
        } else {
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
	}

	public static function projectTitle($token, $server) {
	    $settings = self::getProjectSettings($token, $server);
	    if ($settings['project_title']) {
	        return $settings['project_title'];
        }
	    return "";
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
		$currServer = SERVER_NAME;
		return (strpos(strtolower($server), strtolower($currServer)) !== FALSE);
	}

	private static function sendToServer($server, $data, $try = 1) {
	    $maxTries = 5;
	    if ($try > $maxTries) {
            return [];
        }
	    if (!$server) {
	        throw new \Exception("No server specified");
        }
	    if ($data['content'] == "record") {
            $pid = Application::getPID($data['token']);
        } else {
	        # no need to check for pid because won't use REDCap::getData
            # this was causing an issue with install.php
	        $pid = FALSE;
        }
        $error = "";
		if ($pid && isset($_GET['pid']) && ($pid == $_GET['pid']) && !isset($data['forms']) && method_exists('\REDCap', 'getData')) {
            $records = $data['records'] ?? NULL;
            $fields = $data['fields'] ?? NULL;
            if (isset($_GET['test'])) {
                $numFields = $fields ? count($fields) : 0;
                $numRecords = $records ? count($records) : 0;
                if (($numFields > 0) && ($numFields <= 5)) {
                    $numFields = json_encode($fields);
                }
                Application::log("sendToServer: ".$pid." REDCap::getData $numFields fields $numRecords records", $pid);
            }
		    $output = \REDCap::getData($pid, "json", $records, $fields);
            $resp = "getData";
            if (isset($_GET['test'])) {
                Application::log("sendToServer: ".$pid." REDCap::getData done", $pid);
            }
		} else {
		    $time1 = microtime();
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
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
			$output = curl_exec($ch);
            $resp = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            self::throttleIfNecessary($pid);
            $time2 = microtime();
            if (isset($_GET['test'])) {
                Application::log("sendToServer: API in ".($time2 - $time1)." seconds");
            }
		}
		if (!$output) {
            Application::log("Retrying because no output");
            usleep(500);
            $redcapData = self::sendToServer($server, $data, $try + 1);
            if (($redcapData === NULL) && ($try == $maxTries)) {
                Application::log("ERROR: ".$output);
                throw new \Exception("$pid: Download returned null from ".$server." ($resp) '$output' error=$error");
            }
            if (isset($redcapData['error']) && !empty($redcapData['error'])) {
                throw new \Exception("Download Exception: ".$redcapData['error']);
            }
            return $redcapData;
        }
		if (REDCapManagement::isJSON($output)) {
            $redcapData = json_decode($output, true);
            if ($redcapData === NULL) {
                Application::log("Retrying because undecipherable output");
                usleep(500);
                $redcapData = self::sendToServer($server, $data, $try + 1);
                if (($redcapData === NULL) && ($try == $maxTries)) {
                    Application::log("ERROR: ".$output);
                    throw new \Exception("$pid: Download returned null from ".$server." ($resp) '$output' error=$error");
                }
            }
            if (isset($redcapData['error']) && !empty($redcapData['error'])) {
                throw new \Exception("Download Exception: ".$redcapData['error']);
            }
            return $redcapData;
        } else {
            return $output;
        }
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
				if ($row[$userIdField]) {
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

	public static function hasField($pid, $field, $instrument) {
	    $json = \REDCap::getDataDictionary($pid, "json", TRUE, NULL, $instrument);
	    $limitedMetadata = json_decode($json, TRUE);
	    $metadataFields = REDCapManagement::getFieldsFromMetadata($limitedMetadata);
	    return in_array($field, $metadataFields);
    }

	public static function userRights($token, $server) {
	    $data = [
	        "token" => $token,
            "content" => "user",
            "format" => "json",
        ];
	    return self::sendToServer($server, $data);
    }

	public static function institutions($token, $server) {
		return Download::oneField($token, $server, "identifier_institution");
	}

	public static function institutionsAsArray($token, $server) {
	    $asStrings = self::institutions($token, $server);
	    $institutions = [];
	    foreach ($asStrings as $recordId => $str) {
	        if ($str) {
                $institutions[$recordId] = preg_split("/\s*[,\/;]\s*/", $str);
            } else {
	            $institutions[$recordId] = [];
            }
        }
	    return $institutions;
    }

	public static function lastnames($token, $server) {
		return Download::oneField($token, $server, "identifier_last_name");
	}

    public static function firstnames($token, $server) {
        return Download::oneField($token, $server, "identifier_first_name");
    }

    public static function ORCIDs($token, $server) {
        return Download::oneField($token, $server, "identifier_orcid");
    }

    public static function middlenames($token, $server) {
		return Download::oneField($token, $server, "identifier_middle");
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

	public static function excludeList($token, $server, $field, $metadata) {
	    $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
	    if (in_array($field, $metadataFields)) {
            $oneFieldData = self::oneField($token, $server, $field);
            $excludeList = [];
            foreach ($oneFieldData as $recordId => $fieldData) {
                if ($fieldData) {
                    $excludeList[$recordId] = preg_split("/\s*[,;]\s*/", $fieldData);
                } else {
                    $excludeList[$recordId] = [];
                }
            }
            return $excludeList;
	    } else {
	        $recordIds = self::recordIds($token, $server);
	        $excludeList = [];
	        foreach ($recordIds as $recordId) {
	            $excludeList[$recordId] = [];
            }
	        return $excludeList;
        }
    }

	public static function doctorateInstitutions($token, $server, $metadata) {
        $pid = Application::getPID($token);
        $scholar = new Scholar($token, $server, $metadata, $pid);
        $choices = REDCapManagement::getChoices($metadata);
        $eligibleRegexes = Scholar::getDoctoralRegexes();

        $allInstitutionFields = $scholar->getAllInstitutionFields();
        $fields = array_unique(array_merge(array("record_id"), array_keys($allInstitutionFields)), array_values($allInstitutionFields));
        $redcapData = Download::fields($token, $server, $fields);

        $institutions = array();
        foreach ($redcapData as $row) {
            foreach ($allInstitutionFields as $institutionField => $degreeField) {
                if ($row[$institutionField] && $row[$degreeField]) {
                    $value = $choices[$degreeField][$row[$degreeField]];
                    foreach ($eligibleRegexes as $regex) {
                        if (preg_match($regex, $value)) {
                            if (!isset($institutions[$row['record_id']])) {
                                $institutions[$row['record_id']] = array();
                            }
                            array_push($institutions[$row['record_id']], $row[$institutionField]);
                            break;
                        }
                    }
                }
            }
        }

        # clean up
        foreach ($institutions as $recordId => $institutionList) {
            $institutions[$recordId] = array_unique($institutionList);
        }

        return $institutions;
    }

    public static function arraysOfFields($token, $server, $fields) {
	    if (count($fields) === 0) {
	        throw new \Exception("Array of Fields is empty");
        }
	    $newFields = array_merge(["record_id"], $fields);
        $data = [
            'token' => $token,
            'content' => 'record',
            'format' => 'json',
            'type' => 'flat',
            'rawOrLabel' => 'raw',
            'fields' => $newFields,
            'rawOrLabelHeaders' => 'raw',
            'exportCheckboxLabel' => 'false',
            'exportSurveyFields' => 'false',
            'exportDataAccessGroups' => 'false',
            'returnFormat' => 'json'
        ];
        $redcapData = self::sendToServer($server, $data);
        $ary = [];
        foreach ($fields as $field) {
            $ary[$field] = [];
            foreach ($redcapData as $row) {
                $ary[$field][$row['record_id']] = $row[$field] ?? "";
            }
        }
        return $ary;
    }

	public static function oneField($token, $server, $field) {
		$data = [
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'rawOrLabel' => 'raw',
			'fields' => ["record_id", $field],
			'rawOrLabelHeaders' => 'raw',
			'exportCheckboxLabel' => 'false',
			'exportSurveyFields' => 'false',
			'exportDataAccessGroups' => 'false',
			'returnFormat' => 'json'
		];
		$redcapData = self::sendToServer($server, $data);
		$ary = [];
		foreach ($redcapData as $row) {
			$ary[$row['record_id']] = $row[$field] ?? "";
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
		if (isset($_GET['test'])) {
            Application::log("Download::recordIds");
        }
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
		    if (!in_array($row['record_id'], $records)) {
                $records[] = $row['record_id'];
            }
		}
		return $records;
	}

	public static function fieldsWithConfig($token, $server, $metadataOrModule, $fields, $cohortConfig) {
		$filter = new Filter($token, $server, $metadataOrModule);
		$records = $filter->getRecords($cohortConfig);
		if (isset($_GET['test'])) {
            Application::log("Download::fieldsWithFilter ".count($records)." records; ".count($fields)." fields");
        }
		return Download::fieldsForRecords($token, $server, $fields, $records);
	}

	public static function fields($token, $server, $fields) {
	    if (empty($fields)) {
	        Application::log("Error! Download::fields blank ".json_encode(debug_backtrace()));
	        return [];
        }
	    if (isset($_GET['test'])) {
            Application::log("Download::fields ".count($fields)." fields");
        }
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
		$returnData = self::sendToServer($server, $data);
		if (count($returnData) == 0) {
            Application::log("ERROR: empty return from data: ".json_encode($returnData));
        }
		return $returnData;
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
	    if (isset($_GET['test'])) {
            Application::log("Download::fieldsForRecords ".count($fields)." fields with ".json_encode($records));
        }
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
		if (isset($_GET['test'])) {
            Application::log("Download::records ".json_encode($records));
        }
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

    public static function sortedfirstnames($token, $server) {
	    $lastNames = self::sortedlastnames($token, $server);
        $firstNames = self::firstnames($token, $server);
        $newFirstNames = [];
        foreach ($lastNames as $recordId => $lastName) {
            $newFirstNames[$recordId] = $firstNames[$recordId];
        }
        return $newFirstNames;
    }

    public static function sortedlastnames($token, $server) {
        $lastNames = self::lastnames($token, $server);
        asort($lastNames);
        return $lastNames;
    }

    private static function getCohortConfig($token, $server, $metadataOrModule, $cohort)
    {
        if ($module = Application::getModule()) {
            $cohorts = new Cohorts($token, $server, $module);
        } else {
            $cohorts = new Cohorts($token, $server, $metadataOrModule);
        }
        $cohortNames = $cohorts->getCohortNames();
        if (in_array($cohort, $cohortNames)) {
            return $cohorts->getCohort($cohort);
        }
        return FALSE;
    }

	public static function cohortRecordIds($token, $server, $metadataOrModule, $cohort) {
		$cohortConfig = self::getCohortConfig($token, $server, $metadataOrModule, $cohort);
        if ($cohortConfig) {
            $redcapData = self::fieldsWithConfig($token, $server, $metadataOrModule, array("record_id"), $cohortConfig);
            $records = array();
            foreach ($redcapData as $row) {
                array_push($records, $row['record_id']);
            }
            return $records;
        }
		return false;
	}

	private static $rateLimitPerMinute = NULL;
	private static $rateLimitCounter = 0;
	private static $rateLimitTs = NULL;
}
