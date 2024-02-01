<?php

namespace Vanderbilt\CareerDevLibrary;

use \ExternalModules\ExternalModules;
use function Vanderbilt\FlightTrackerExternalModule\json_encode_with_spaces;

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
			$indexedRedcapData[$recordId][] = $row;
		}
		return $indexedRedcapData;
	}

	public static function getInstanceRow($pid, $prefix, $instrument, $recordId, $instance) {
        $module = Application::getModule();
        $dataTable = Application::getDataTable($pid);
	    $instanceClause = ($instance == 1) ? "instance IS NULL" : "instance = ?";
	    $sql = "SELECT field_name, value
                    FROM $dataTable
                    WHERE project_id = ?
                        AND field_name LIKE ?
                        AND record = ?
                        AND $instanceClause";
        $params = [$pid, "$prefix%", $recordId];
        if ($instance > 1) {
            $params[] = $instance;
        }
        if ($module) {
            $q = $module->query($sql, $params);
        } else {
            $q = db_query($sql, $params);
        }

        $returnRow = ["record_id" => $recordId, "redcap_repeat_instrument" => $instrument, "redcap_repeat_instance" => $instance];
	    while ($row = $q->fetch_assoc()) {
            $returnRow[Sanitizer::sanitize($row['field_name'])] = Sanitizer::sanitizeWithoutChangingQuotes($row['value']);
        }
        return $returnRow;
    }

	public static function throttleIfNecessary($pid) {
	    if (self::$rateLimitPerMinute === NULL) {
            $module = Application::getModule();
	        $sql = "SELECT * FROM redcap_config WHERE field_name = 'page_hit_threshold_per_minute'";
            if ($module) {
                $q = $module->query($sql, []);
            } else {
                $q = db_query($sql);
            }
            if ($row = $q->fetch_assoc()) {
                self::$rateLimitPerMinute = Sanitizer::sanitizeInteger($row['value']);
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

	public static function predocNames($token, $server, $metadata = [], $cohort = "", $names = []) {
	    if (empty($names)) {
            $names = self::names($token, $server);
        }
		$predocs = array();
		$records = self::recordsWithTrainees($token, $server, array(6));
		if ($cohort) {
            $cohortConfig = self::getCohortConfig($token, $server, Application::getModule(), $cohort);
            if ($cohortConfig) {
                $filter = new Filter($token, $server, $metadata);
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
        $records = self::filterByManualInclusion($records, $token, $server, $metadata);
        foreach ($records as $recordId) {
			$predocs[$recordId] = $names[$recordId];
		}
		return $predocs;
	}

	private static function filterByManualInclusion($records, $token, $server, $metadata) {
	    if (!is_array($metadata) || empty($metadata)) {
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

    public static function postdocAppointmentNames($token, $server, $metadata, $cohort = "", $filterByManualInclusion = TRUE) {
        $names = self::names($token, $server);
        $postdocTrainees = self::recordsWithTrainees($token, $server, [7]);
        if ($cohort) {
            $cohortConfig = self::getCohortConfig($token, $server, Application::getModule(), $cohort);
            if ($cohortConfig) {
                $filter = new Filter($token, $server, $metadata);
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
        if ($filterByManualInclusion) {
            $records = self::filterByManualInclusion($records, $token, $server, $metadata);
        }
        $postdocs = [];
        foreach ($records as $recordId) {
            $postdocs[$recordId] = $names[$recordId];
        }
        return $postdocs;
	}

    public static function postdocNames($token, $server, $metadata = [], $cohort = "") {
        return self::postdocAppointmentNames($token, $server, $metadata, $cohort, FALSE);
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
	public static function menteesForMentor($token, $server, $requestedMentorUserid, $mentorUserids = []) {
        if (empty($mentorUserids)) {
            $mentorUserids = self::primaryMentorUserids($token, $server);
        }

		$menteeNames = array();
		foreach ($mentorUserids as $recordId => $userids) {
			if (in_array($requestedMentorUserid, $userids)) {
				$menteeNames[$recordId] = Download::fullName($token, $server, $recordId);
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

    public static function citationFields($token, $server) {
        $metadataFields = self::metadataFields($token, $server);
        $allCitationFields = array_unique(array_merge(['record_id'], DataDictionaryManagement::filterFieldsForPrefix($metadataFields, "citation_")));
        $relevantCitationFields = [];
        foreach ($allCitationFields as $field) {
            if (($field == "record_id") || in_array($field, Application::$citationFields)) {
                $relevantCitationFields[] = $field;
            }
        }
        return $relevantCitationFields;
    }

	# returns a hash with recordId => array of mentorNames
	public static function primaryMentors($token, $server) {
		$mentors = self::oneField($token, $server, "summary_mentor");
		foreach ($mentors as $recordId => $mentor) {
			if ($mentor) {
                $regex = "/\s*[;\/]\s*/";
                if (preg_match("/[A-Za-z\.]+\s+[A-Za-z\.]+\s*,\s*[A-Za-z\.]+\s+[A-Za-z\.]+/", $mentor)) {
                    # separating two names - not last name, first name
                    $regex = "/\s*[;,\/]\s*/";
                }
				$recordMentors = preg_split($regex, $mentor);
				$prettyRecordMentors = array();
				foreach ($recordMentors as $recordMentor) {
                    $recordMentor = NameMatcher::pretty($recordMentor);
					$prettyRecordMentors[] = $recordMentor;
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
				$filteredData[] = $row;
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

		$allRepeatingForms = DataDictionaryManagement::getRepeatingForms(Application::getPID($token));
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
			} else {
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

    public static function metadataForms($token, $server) {
        $pid = Application::getPID($token);
        if ($pid && self::isCurrentServer($server)) {
            $module = Application::getModule();
            $sql = "SELECT DISTINCT(form_name) FROM redcap_metadata WHERE project_id = ? ORDER BY field_order";
            if ($module) {
                $q = $module->query($sql, [$pid]);
            } else {
                $q = db_query($sql, [$pid]);
            }
            $forms = [];
            while ($row = $q->fetch_assoc()) {
                $forms[] = Sanitizer::sanitize($row['form_name']);
            }
            return $forms;
        } else {
            $metadata = self::metadata($token, $server);
            return DataDictionaryManagement::getFormsFromMetadata($metadata);
        }
    }

    public static function metadataFieldsByPid($pid) {
        $module = Application::getModule();
        $sql = "SELECT field_name FROM redcap_metadata WHERE project_id = ? ORDER BY field_order";
        if ($module) {
            $q = $module->query($sql, [$pid]);
        } else {
            $q = db_query($sql, [$pid]);
        }
        $fields = [];
        while ($row = $q->fetch_assoc()) {
            $fields[] = Sanitizer::sanitize($row['field_name']);
        }
        return $fields;
    }

    public static function metadataFieldsByPidWithPrefix($pid, $prefix) {
        $module = Application::getModule();
        $prefix = str_replace("_", "\_", $prefix);  // _ is a wildcard in MySQL lingo
        $sql = "SELECT field_name FROM redcap_metadata WHERE project_id = ? AND field_name LIKE ? ORDER BY field_order";
        $q = $module->query($sql, [$pid, "$prefix%"]);
        $fields = [];
        while ($row = $q->fetch_assoc()) {
            $fields[] = Sanitizer::sanitize($row['field_name']);
        }
        return $fields;
    }

    public static function metadataFields($token, $server, $prefix = "") {
        $pid = Application::getPID($token);
        if ($pid && self::isCurrentServer($server)) {
            if ($prefix) {
                return self::metadataFieldsByPidWithPrefix($pid, $prefix);
            } else {
                return self::metadataFieldsByPid($pid);
            }
        } else {
            $metadata = self::metadata($token, $server);
            return DataDictionaryManagement::getFieldsFromMetadata($metadata);
        }
    }

	public static function metadata($token, $server, $fields = [])
    {
        $pid = Application::getPID($token);
        $cachedMetadata = self::getCachedMetadata($pid, $fields);
        if (isset($_GET['testPSU'])) {
            echo "cachedMetadata: ".count($cachedMetadata)." entries<br/>";
        }
        if (!empty($cachedMetadata)) {
            return $cachedMetadata;
        }
        if ($pid && Application::isServer($server)) {
            $metadata = self::metadataByPid($pid, $fields);
            if (isset($_GET['testPSU'])) {
                echo "metadataByPid: ".count($metadata)." entries<br/>";
            }
            return $metadata;
        } else {
            $method = "API";
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
            if (isset($_GET['test'])) {
                Application::log("Download::metadata $method returning " . count($rows) . " rows", $pid);
            }
            if (isset($_GET['testPSU'])) {
                echo "metadata by API: ".count($rows)." entries<br/>";
            }
            if (empty($fields)) {
                if (isset($_GET['testPSU'])) {
                    echo "caching metadata by API<br/>";
                }
                $_SESSION['metadata'.$pid] = $rows;
                $_SESSION['lastMetadata'.$pid] = time();
            }
            return Sanitizer::sanitizeArray($rows, FALSE, FALSE);
        }
    }

    private static function getCachedMetadata($pid, $fields) {
        if (
            $pid
            && isset($_SESSION['lastMetadata'.$pid])
            && empty($fields)
        ) {
            $metadataKey = 'metadata'.$pid;
            $timestampKey = 'lastMetadata'.$pid;
            $cachedMetadata = $_SESSION[$metadataKey] ?? [];
            $ts = $_SESSION[$timestampKey] ?? 0;
            $currTs = time();
            $fiveMinutes = 5 * 60;
            if (
                ($currTs - $ts >= $fiveMinutes)
                && ($currTs > $ts)
                && !empty($cachedMetadata)
            ) {
                if (isset($_GET['test'])) {
                    Application::log("Download::metadata returning _SESSION: ".count($cachedMetadata)." rows", $pid);
                }
                return $cachedMetadata;
            }
        }
        return [];
    }

    public static function metadataByPid($pid, $fields = []) {
        if (isset($_GET['test']) || isset($_GET['testPSU'])) {
            Application::log("Download::metadataByPid", $pid);
            echo "Download::metadataByPid<br/>";
        }
        $cachedMetadata = self::getCachedMetadata($pid, $fields);
        if (!empty($cachedMetadata)) {
            if (empty($fields)) {
                return $cachedMetadata;
            } else {
                return DataDictionaryManagement::getRowsForFieldsFromMetadata($fields, $cachedMetadata);
            }
        }
        $method = "REDCap";
        if (!empty($fields)) {
            $json = \REDCap::getDataDictionary($pid, "json", TRUE, $fields);
        } else {
            $json = \REDCap::getDataDictionary($pid, "json");
        }
        $rows = json_decode($json, TRUE);
        if (isset($_GET['test']) || isset($_GET['testPSU'])) {
            Application::log("Download::metadata $method returning " . count($rows) . " rows", $pid);
            echo "Download::metadata $method returning " . count($rows) . " rows<br/>";
            echo "JSON: $json<br/>";
            if (count($rows) === 0) {
                Application::log($json, $pid);
            }
        }
        if (empty($fields)) {
            $_SESSION['metadata' . $pid] = $rows;
            $_SESSION['lastMetadata' . $pid] = time();
        }
        return Sanitizer::sanitizeArray($rows, FALSE, FALSE);
    }

	public static function shortProjectTitle($token, $server) {
	    $title = self::projectTitle($token, $server);
        $shortTitle = trim(preg_replace("/Flight Tracker\s?(-\s)?/i", "", $title));
        $shortTitle = str_replace("(", "[", $shortTitle);
        $shortTitle = str_replace(")", "]", $shortTitle);
        return $shortTitle;
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

    public static function alumniAssociations($token, $server) {
        $metadataFields = self::metadataFields($token, $server);
        $links = [];
        $middleTrunk = "alumni_assoc_";
        $numAlumniFields = 5;
        if (in_array("check_alumni_assoc1_", $metadataFields)) {
            $prefices = ["check_", "followup_", "init_import_"];
            $fields = ["record_id"];
            foreach ($prefices as $prefix) {
                for ($i = 1; $i <= $numAlumniFields; $i++) {
                    $fields[] = $prefix.$middleTrunk.$i;
                }
            }
            $redcapData = self::fields($token, $server, $fields);
            foreach ($redcapData as $row) {
                $recordId = $row['record_id'];
                foreach ($prefices as $prefix) {
                    for ($i = 1; $i <= $numAlumniFields; $i++) {
                        $field = $prefix . $middleTrunk . $i;
                        if ($row[$field]) {
                            $url = URLManagement::makeURL($row[$field]);
                            if ($url) {
                                if (!isset($links[$recordId])) {
                                    $links[$recordId] = [];
                                }
                                $links[$recordId][] = $url;
                            }
                        }
                    }
                }
            }
        }
        return $links;
    }

    public static function fileAsBase64($pid, $field, $recordId, $instance = 1) {
        $module = Application::getModule();
        $dataTable = Application::getDataTable($pid);
        # assume classical project
        if ($instance != 1) {
            $sql = "SELECT value FROM $dataTable WHERE project_id = ? AND field_name = ? AND record = ? AND instance = ?";
            $params = [$pid, $field, $recordId, $instance];
        } else {
            $sql = "SELECT value FROM $dataTable WHERE project_id = ? AND field_name = ? AND record = ? AND instance IS NULL";
            $params = [$pid, $field, $recordId];
        }
        if ($module) {
            $result = $module->query($sql, $params);
        } else {
            $result = db_query($sql, $params);
        }
        if ($row = $result->fetch_assoc()) {
            $edocId = Sanitizer::sanitizeInteger($row['value']);
            if ($edocId) {
                return FileManagement::getEdocBase64($edocId);
            }
        }
        return "";
    }

    # assume classical project
    public static function oneFieldForRecordByPid($pid, $field, $recordId) {
        $module = Application::getModule();
        $dataTable = Application::getDataTable($pid);
        $sql = "SELECT instance, value FROM $dataTable WHERE project_id = ? AND record = ? AND field_name = ?";
        $params = [$pid, $recordId, $field];
        $result = $module->query($sql, $params);
        if ($result->num_rows == 1) {
            $row = $result->fetch_assoc();
            return $row['value'] ?? "";
        } else if ($result->num_rows > 1) {
            $resultsByInstance = [];
            while ($row = $result->fetch_assoc()) {
                $instance = Sanitizer::sanitizeInteger($row['instance'] ?? 1);
                $resultsByInstance[$instance] = Sanitizer::sanitizeWithoutChangingQuotes($row['value']);
            }
            return $resultsByInstance;
        }
        return "";
    }

    public static function getDataByPid($pid, $fields, $records) {
        if (REDCapManagement::versionGreaterThanOrEqualTo(REDCAP_VERSION, "12.5.2")) {
            $redcapData = \REDCap::getData($pid, "json-array", $records, $fields);
        } else {
            $json = \REDCap::getData($pid, "json", $records, $fields);
            $redcapData = json_decode($json, true);
        }
        return Sanitizer::sanitizeREDCapData($redcapData);
    }

	private static function sendToServer($server, $data) {
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
        if (isset($data['token']) && isset($data['fields']) && !empty($data['fields'])) {
            $data['fields'] = self::replaceUseridField($data['fields'], $data['token'], $server);
        }
		if ($pid && isset($_GET['pid']) && ($pid == $_GET['pid']) && !isset($data['forms']) && method_exists('\REDCap', 'getData')) {
            $records = $data['records'] ?? NULL;
            $fields = $data['fields'] ?? NULL;
            return self::fieldsForRecordsByPid($pid, $fields, $records);
		} else {
            return self::callAPI($server, $data, $pid);
        }
	}

    private static function callAPI($server, $data, $pid, $try = 1) {
        $maxTries = 5;
        if ($try > $maxTries) {
            return [];
        }
        $server = Sanitizer::sanitizeURL($server);
        if (!$server) {
            throw new \Exception("Invalid URL");
        }
        $time1 = microtime(TRUE);
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
        $output = curl_exec($ch);
        $redcapData = json_decode((string) $output, true);
        $resp = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        self::throttleIfNecessary($pid);
        $time2 = microtime(TRUE);
        if (isset($_GET['test'])) {
            Application::log("sendToServer: API in ".($time2 - $time1)." seconds with ".count($redcapData)." rows");
        }
        if (!$output) {
            Application::log("Retrying because no output", $pid);
            usleep(500000);
            $redcapData = self::callAPI($server, $data, $pid, $try + 1);
            if (($redcapData === NULL) && ($try >= $maxTries)) {
                Application::log("ERROR: Null output", $pid);
                throw new \Exception("$pid: Download returned null from ".$server." ($resp) '$output' error=$error");
            }
            if (isset($redcapData['error']) && !empty($redcapData['error'])) {
                throw new \Exception("Download Exception $pid: ".$redcapData['error']);
            }
            return $redcapData;
        }
        if ($redcapData === NULL) {
            Application::log("Retrying because undecipherable output: ".$data['content'], $pid);
            usleep(500000);
            $redcapData = self::callAPI($server, $data, $pid, $try + 1);
            if (($redcapData === NULL) && ($try >= $maxTries)) {
                Application::log("ERROR: ".$output, $pid);
                throw new \Exception("$pid: Download returned null from ".$server." ($resp) '$output' error=$error");
            }
        }
        if (isset($redcapData['error']) && !empty($redcapData['error'])) {
            throw new \Exception("Download Exception $pid: ".$redcapData['error']);
        }
        for ($i = 0; $i < count($redcapData); $i++) {
            if (isset($redcapData[$i]["record_id"])) {
                $redcapData[$i]["record_id"] = Sanitizer::sanitize($redcapData[$i]["record_id"]);
            }
        }
        $redcapData = Sanitizer::sanitizeREDCapData($redcapData);
        self::handleLargeJSONs($redcapData, $pid);
        return $redcapData;
    }

    private static function handleLargeJSONs(&$redcapData, $pid) {
        foreach (array_keys($redcapData) as $i) {
            $recordId = $redcapData[$i]["record_id"] ?? "";
            foreach ($redcapData[$i] as $field => $value) {
                $key = $field."___".$recordId;
                if ((strpos($field, "summary_calculate_") !== FALSE) && ($value == $key)) {
                    $redcapData[$i][$field] = Application::getSetting($key, $pid);
                }
            }
        }
    }

    public static function maxInstance($pid, $testField, $recordId) {
        $module = Application::getModule();
        $dataTable = Application::getDataTable($pid);
        $sql = "SELECT instance FROM $dataTable WHERE project_id = ? AND field_name = ? AND record = ?";
        $result = $module->query($sql, [$pid, $testField, $recordId]);
        $max = 0;
        while ($row = $result->fetch_assoc()) {
            $instance = $row['instance'] ?? 1;
            if ($instance > $max) {
                $max = $instance;
            }
        }
        return $max;
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

    public static function singleUserid($pid, $recordId) {
        $module = Application::getModule();
        $dataTable = Application::getDataTable($pid);
        foreach(["identifier_userid", "identifier_vunet"] as $useridField) {
            $sql = "SELECT value FROM $dataTable WHERE project_id = ? AND record = ? AND field_name = ?";
            $params = [$pid, $recordId, $useridField];
            $result = $module->query($sql, $params);
            if ($row = $result->fetch_assoc()) {
                return $row['value'];
            }
        }
        return "";
    }

	public static function userids($token, $server) {
		return self::vunets($token, $server);
	}

    private static function replaceUseridField($fields, $token, $server) {
        $possibleFields = self::getUseridFields();
        $overlap = array_intersect($possibleFields, $fields);
        if (empty($overlap)) {
            return $fields;
        } else {
            $useridField = self::getUseridField($token, $server);
            $newFields = [];
            foreach ($fields as $field) {
                if (in_array($field, $possibleFields)) {
                    $newFields[] = $useridField;
                } else {
                    $newFields[] = $field;
                }
            }
            return $newFields;
        }
    }

    private static function getUseridFields() {
        return ["identifier_vunet", "identifier_userid"];
    }

    public static function getUseridFieldByPid($pid) {
        $metadataFields = self::metadataFieldsByPid($pid);
        return self::getUseridFieldHelper($metadataFields);
    }

    private static function getUseridFieldHelper($metadataFields) {
        $possibleFields = self::getUseridFields();
        foreach ($possibleFields as $possibleField) {
            if (in_array($possibleField, $metadataFields)) {
                return $possibleField;
            }
        }
        return "";
    }

    public static function getUseridField($token, $server) {
        $metadataFields = self::metadataFields($token, $server);
        return self::getUseridFieldHelper($metadataFields);
    }

	public static function vunets($token, $server) {
        if (method_exists("\Vanderbilt\CareerDevLibrary\Application", "isPluginProject") && Application::isPluginProject()) {
            $userIdField = "identifier_vunet";
        } else {
            $userIdField = "identifier_userid";
        }

		if ($userIdField != "") {
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
        return self::formsForRecords($token, $server, [$formName], $records);
	}

    public static function formsForRecords($token, $server, $forms, $records) {
        $data = array(
            'token' => $token,
            'content' => 'record',
            'format' => 'json',
            'type' => 'flat',
            'rawOrLabel' => 'raw',
            'forms' => $forms,
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

    public static function getSingleValue($pid, $recordId, $field, $instance = NULL) {
        $module = Application::getModule();
        $dataTable = Application::getDataTable($pid);
        $sql = "SELECT value FROM $dataTable WHERE project_id = ? AND field_name = ? AND record = ?";
        $params = [$pid, $field, $recordId];
        if (isset($instance) && ($instance != 1)) {
            $sql .= " AND instance = ?";
            $params[] = $instance;
        } else {
            $sql .= " AND instance IS NULL";
        }
        $result = $module->query($sql, $params);
        if ($row = $result->fetch_assoc()) {
            return $row['value'];
        }
        return NULL;
    }

    public static function email($token, $server, $recordId) {
        $pid = Application::getPID($token);
        $value = self::getSingleValue($pid, $recordId, "identifier_email");
        if ($value === NULL) {
            $redcapData = self::fieldsForRecords($token, $server, ["identifier_email"], [$recordId]);
            if (!$redcapData) {
                return "";
            }
            return $redcapData[0]["identifier_email"];
        } else {
            return $value;
        }
    }

    public static function threeNamePartsAndUserid($token, $server, $recordId) {
        $usernameField = self::getUseridField($token, $server);
        $redcapData = self::fieldsForRecords($token, $server, ["record_id", "identifier_first_name", "identifier_middle", "identifier_last_name", $usernameField], [$recordId]);
        if (!$redcapData) {
            return ["", "", ""];
        }
        $row = $redcapData[0];
        $fn = $row['identifier_first_name'];
        $middle = $row['identifier_middle'];
        $ln = $row['identifier_last_name'];
        $username = $row[$usernameField];
        return [trim($fn), trim($middle), trim($ln), trim($username)];
    }

    public static function fullName($token, $server, $recordId) {
        $pid = Application::getPID($token);
        return self::fullNameByPid($pid, $recordId);
    }

    public static function fullNameByPid($pid, $recordId) {
        $module = Application::getModule();
        $dataTable = Application::getDataTable($pid);
        $sql = "SELECT d1.value AS first_name, d2.value AS middle_name, d3.value AS last_name FROM $dataTable AS d1 INNER JOIN $dataTable AS d2 ON (d1.project_id = d2.project_id AND d1.field_name = d2.field_name AND d1.record = d2.record) INNER JOIN $dataTable AS d3 ON (d1.project_id = d3.project_id AND d1.field_name = d3.field_name AND d1.record = d3.record) WHERE d1.project_id = ? AND d1.record = ? AND d1.field_name = ? AND d2.field_name = ? AND d3.field_name = ?";
        $params = [$pid, $recordId, "identifier_first_name", "identifier_middle", "identifier_last_name"];
        $result = $module->query($sql, $params);
        if ($row = $result->fetch_assoc()) {
            return NameMatcher::formatName($row['first_name'], $row['middle_name'], $row['last_name']);
        }

        $redcapData = self::fieldsForRecordsByPid($pid, ["record_id", "identifier_first_name", "identifier_middle", "identifier_last_name"], [$recordId]);
        if (!$redcapData) {
            return "";
        }
        $row = $redcapData[0];
        $fn = $row['identifier_first_name'];
        $middle = $row['identifier_middle'];
        $ln = $row['identifier_last_name'];
        return NameMatcher::formatName($fn, $middle, $ln);
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

	public static function excludeList($token, $server, $field, $metadataFields) {
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
        $fields = array_unique(array_merge(["record_id"], array_keys($allInstitutionFields), array_values($allInstitutionFields)));
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

    private static function dataForOneField($token, $server, $field, $recordIdField = "record_id") {
        $data = [
            'token' => $token,
            'content' => 'record',
            'format' => 'json',
            'type' => 'flat',
            'rawOrLabel' => 'raw',
            'fields' => [$recordIdField, $field],
            'rawOrLabelHeaders' => 'raw',
            'exportCheckboxLabel' => 'false',
            'exportSurveyFields' => 'false',
            'exportDataAccessGroups' => 'false',
            'returnFormat' => 'json'
        ];
        return self::sendToServer($server, $data);
    }

    public static function oneFieldWithInstances($token, $server, $field) {
        $redcapData = self::dataForOneField($token, $server, $field);
        $ary = [];
        foreach ($redcapData as $row) {
            if (isset($row['redcap_repeat_instance']) && $row[$field]) {
                if (!isset($ary[$row['record_id']])) {
                    $ary[$row['record_id']] = [];
                }
                $ary[$row['record_id']][$row['redcap_repeat_instance']] = $row[$field];
            }
        }
        return $ary;
    }

	public static function oneField($token, $server, $field, $recordIdField = "record_id") {
	    $redcapData = self::dataForOneField($token, $server, $field, $recordIdField);
		$ary = [];
		foreach ($redcapData as $row) {
			$ary[$row[$recordIdField]] = $row[$field] ?? "";
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
			'fields' => array("record_id", "identifier_first_name", "identifier_middle", "identifier_last_name"),
			'rawOrLabelHeaders' => 'raw',
			'exportCheckboxLabel' => 'false',
			'exportSurveyFields' => 'false',
			'exportDataAccessGroups' => 'false',
			'returnFormat' => 'json'
		);
		$redcapData = self::sendToServer($server, $data);
		$ordered = array();
		foreach ($redcapData as $row) {
            # case insensitive
			$ordered[strtoupper($row['identifier_last_name'].", ".$row['identifier_first_name']." ".$row['record_id'])] = $row;
		}
		ksort($ordered);

		$names = array();
		foreach ($ordered as $key => $row) {
			$names[$row['record_id']] = NameMatcher::formatName($row['identifier_first_name'], $row['identifier_middle'], $row['identifier_last_name']);
		}
		return $names;
	}

    public static function recordsWithDownloadActive($token, $server, $recordIdField = "record_id") {
        $stopField = "identifier_stop_collection";
        $data = array(
            'token' => $token,
            'content' => 'record',
            'format' => 'json',
            'type' => 'flat',
            'rawOrLabel' => 'raw',
            'fields' => [$recordIdField, $stopField],
            'rawOrLabelHeaders' => 'raw',
            'exportCheckboxLabel' => 'false',
            'exportSurveyFields' => 'false',
            'exportDataAccessGroups' => 'false',
            'returnFormat' => 'json'
        );
        $redcapData = self::sendToServer($server, $data);
        $records = array();
        foreach ($redcapData as $row) {
            if (!in_array($row[$recordIdField], $records) && ($row[$stopField] != "1")) {
                $records[] = $row[$recordIdField];
            }
        }
        return $records;
    }

    public static function recordIdsByPid($pid) {
        $module = Application::getModule();
        $dataTable = Application::getDataTable($pid);
        $sql = "SELECT DISTINCT(record) AS record
                    FROM $dataTable
                    WHERE project_id = ?
                    ORDER BY record;";
        if ($module) {
            $q = $module->query($sql, [$pid]);
        } else {
            $q = db_query($sql, [$pid]);
        }
        $records = [];
        while ($row = $q->fetch_assoc()) {
            $records[] = Sanitizer::sanitize($row['record']);
        }
        sort($records, SORT_NUMERIC);
        return $records;
    }

	public static function recordIds($token, $server, $recordIdField = "record_id") {
		if (isset($_GET['test'])) {
            Application::log("Download::recordIds");
        }
		$data = array(
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'rawOrLabel' => 'raw',
			'fields' => [$recordIdField],
			'rawOrLabelHeaders' => 'raw',
			'exportCheckboxLabel' => 'false',
			'exportSurveyFields' => 'false',
			'exportDataAccessGroups' => 'false',
			'returnFormat' => 'json'
		);
		$redcapData = self::sendToServer($server, $data);
        return self::filterOutRecords($redcapData, $recordIdField);
	}

    private static function filterOutRecords($redcapData, $recordIdField) {
        $records = array();
        foreach ($redcapData as $row) {
            if (isset($row[$recordIdField]) && !in_array($row[$recordIdField], $records)) {
                $records[] = $row[$recordIdField];
            }
        }
        return $records;
    }

	public static function fastField($pid, $field) {
	    $values = [];

        $dataTable = Application::getDataTable($pid);
        $module = Application::getModule();
	    $sql = "SELECT record, value, instance
                    FROM $dataTable
                    WHERE project_id = ?
                        AND field_name= ?";
        if ($module) {
            $q = $module->query($sql, [$pid, $field]);
        } else {
            $q = db_query($sql, [$pid, $field]);
        }

	    $hasMultipleInstances = FALSE;
	    while ($row = $q->fetch_assoc()) {
	        if ($row['instance']) {
	            $hasMultipleInstances = TRUE;
	            $instance = Sanitizer::sanitizeInteger($row['instance']);
            } else {
	            $instance = 1;
            }
	        $recordId = Sanitizer::sanitize($row['record']);
	        $value = Sanitizer::sanitizeWithoutChangingQuotes($row['value']);
	        if (!isset($values[$recordId])) {
	            $values[$recordId] = [];
            }
	        $values[$recordId][$instance] = $value;
        }

        if (!$hasMultipleInstances) {
            foreach ($values as $recordId => $instanceValues) {
                $values[$recordId] = $values[$recordId][1];
            }
        }

	    return $values;
    }

	public static function fieldsWithConfig($token, $server, $metadata, $fields, $cohortConfig) {
		$filter = new Filter($token, $server, $metadata);
		$records = $filter->getRecords($cohortConfig);
		if (isset($_GET['test'])) {
            Application::log("Download::fieldsWithFilter ".count($records)." records; ".count($fields)." fields");
        }
		return Download::fieldsForRecords($token, $server, $fields, $records);
	}

	public static function fields($token, $server, $fields) {
	    if (empty($fields)) {
	        throw new \Exception("Error! Download::fields blank!");
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

    public static function edocIDs($token, $server, $field) {
        $records = self::recordIds($token, $server);
        $edocs = [];
        foreach ($records as $recordId) {
            $edocs[$recordId] = "";
        }

        $module = Application::getModule();
        $pid = Application::getPID($token);
        $dataTable = Application::getDataTable($pid);
        $sql = "SELECT record, value FROM $dataTable WHERE project_id = ? AND field_name = ?";
        if ($module) {
            $result = $module->query($sql, [$pid, $field]);
        } else {
            $result = db_query($sql, [$pid, $field]);
        }
        while ($row = $result->fetch_assoc()) {
            if (in_array($row['record'], $records)) {
                $edocs[Sanitizer::sanitize($row['record'])] = Sanitizer::sanitizeInteger($row['value']);
            }
        }
        return $edocs;
    }

    public static function nonBlankFileFieldsFromProjects($pids, $name, $fileField) {
        list($first, $last) = NameMatcher::splitName($name, 2);
        $values = [];
        foreach ($pids as $currPid) {
            $currToken = Application::getSetting("token", $currPid);
            $currServer = Application::getSetting("server", $currPid);
            if ($currToken && $currServer) {
                $metadataFields = self::metadataFields($currToken, $currServer);
                if (in_array($fileField, $metadataFields)) {
                    $firstNames = self::firstnames($currToken, $currServer);
                    $lastNames = self::lastnames($currToken, $currServer);
                    $fieldValues = self::edocIDs($currToken, $currServer, $fileField);
                    foreach ($firstNames as $recordId => $currFirstName) {
                        $currLastName = $lastNames[$recordId] ?? "";
                        if (($fieldValues[$recordId] !== "") && NameMatcher::matchName($first, $last, $currFirstName, $currLastName)) {
                            $values[$currPid.":".$recordId] = $fieldValues[$recordId];
                        }
                    }
                }
            }
        }

        return $values;
    }

    public static function fieldsForRecordAndInstances($token, $server, $fields, $record, $instrument, $instances) {
        if (empty($instances)) {
            return [];
        }
        if (self::isCurrentServer($server)) {
            $pid = Application::getPID($token);
            if (Application::isPluginProject()) {
                $query = \ExternalModules\ExternalModules::createQuery();
            } else {
                $module = Application::getModule();
                $query = $module->createQuery();
            }

            $modifiedInstances = [];
            $questionMarks = [];
            $hasInstanceNull = FALSE;
            foreach ($instances as $instance) {
                if ($instance == 1) {
                    $hasInstanceNull = TRUE;
                } else {
                    $modifiedInstances[] = $instance;
                    $questionMarks[] = "?";
                }
            }
            $dataTable = Application::getDataTable($pid);
            $sql = "SELECT `field_name`, `instance`, `value` FROM $dataTable WHERE project_id = ?";
            $query->add($sql, $pid);
            $query->add("and record = ?", $record);
            $nullInstanceStr = "";
            if ($hasInstanceNull) {
                $nullInstanceStr = "instance IS NULL or";
            }
            if (!empty($questionMarks)) {
                $query->add("and ($nullInstanceStr instance IN (".implode(",", $questionMarks)."))", $modifiedInstances);
            } else {
                $query->add("and (instance IS NULL)", $modifiedInstances);
            }

            $questionMarks = [];
            while (count($questionMarks) < count($fields)) {
                $questionMarks[] = "?";
            }
            $query->add("and field_name IN (".implode(",", $questionMarks).")", $fields);

            $result = $query->execute();
            $recordDataByInstance = [];
            while ($row = $result->fetch_assoc()) {
                $field = $row['field_name'];
                $instance = $row['instance'] ?: 1;
                $value = $row['value'] ?? "";
                if (!isset($recordDataByInstance[$instance])) {
                    $recordDataByInstance[$instance] = [
                        "record_id" => $record,
                        "redcap_repeat_instrument" => $instrument,
                        "redcap_repeat_instance" => $instance,
                    ];
                    foreach ($fields as $requestedField) {
                        $recordDataByInstance[$instance][$requestedField] = "";
                    }
                }
                if ($field) {
                    $recordDataByInstance[$instance][$field] = $value;
                }
            }
            ksort($recordDataByInstance);
            return array_values($recordDataByInstance);
        } else {
            return self::fieldsForRecords($token, $server, $fields, [$record]);
        }
    }

    public static function fieldsForRecordsByPid($pid, $fields, $records, $try = 1) {
        $maxTries = 2;
        if (isset($_GET['test'])) {
            $numFields = $fields ? count($fields) : 0;
            $numRecords = $records ? count($records) : 0;
            if (($numFields > 0) && ($numFields <= 5)) {
                $numFields = json_encode($fields);
            }
            Application::log("fieldsForRecordsByPid: ".$pid." REDCap::getData $numFields fields $numRecords records", $pid);
        }
        $redcapData = self::getDataByPid($pid, $fields, $records);
        if (REDCapManagement::versionGreaterThanOrEqualTo(REDCAP_VERSION, "12.5.2")) {
            $output = "Done";    // to turn off retry
            $method = "getData-array";
        } else {
            $output = json_encode($redcapData);
            $method = "getData";
        }
        if (isset($_GET['test'])) {
            Application::log("fieldsForRecordsByPid=: ".$pid." $method done with ".count($redcapData)." rows", $pid);
        }
        if (!$output) {
            Application::log("Retrying because no output");
            usleep(500000);
            $redcapData = self::fieldsForRecordsByPid($pid, $fields, $records, $try + 1);
            if (($redcapData === NULL) && ($try == $maxTries)) {
                Application::log("ERROR: ".$output);
                throw new \Exception("$pid: Download returned null '$output'");
            }
            if (isset($redcapData['error']) && !empty($redcapData['error'])) {
                throw new \Exception("Download Exception $pid: ".$redcapData['error']);
            }
            return $redcapData;
        }
        if (isset($redcapData['error']) && !empty($redcapData['error'])) {
            throw new \Exception("Download Exception $pid: ".$redcapData['error']);
        }
        for ($i = 0; $i < count($redcapData); $i++) {
            if (isset($redcapData[$i]["record_id"])) {
                $redcapData[$i]["record_id"] = Sanitizer::sanitize($redcapData[$i]["record_id"]);
            }
        }
        self::handleLargeJSONs($redcapData, $pid);
        return $redcapData;
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
