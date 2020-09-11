<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/Download.php");
require_once(dirname(__FILE__)."/Upload.php");
require_once(dirname(__FILE__)."/../../../redcap_connect.php");

# for datediff
$redcapFile = APP_PATH_DOCROOT.'/ProjectGeneral/math_functions.php';
if (file_exists($redcapFile)) {
    require_once($redcapFile);
}

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
		    $choices[$fieldName] = self::getRowChoices($choicesStr);
		}
		return $choices;
	}

	public static function isWebBrowser() {
	    return isset($_GET['pid']);
    }

	public static function filterOutInvalidFields($metadata, $fields) {
	    $metadataFields = self::getFieldsFromMetadata($metadata);
	    $newFields = array();
	    foreach ($fields as $field) {
	        if (in_array($field, $metadataFields)) {
	            $newFields[] = $field;
            }
        }
	    return $newFields;
    }

    public static function hasInstance($token, $server, $recordId, $instrument, $instance) {
	    $redcapData = Download::formForRecords($token, $server, $instrument, [$recordId]);
	    foreach ($redcapData as $row) {
	        if (($row['redcap_repeat_instrument'] == $instrument) && ($row['redcap_repeat_instance'] == $instance)) {
                return TRUE;
            }
        }
	    return FALSE;
    }

	public static function getRowChoices($choicesStr) {
        $choicePairs = preg_split("/\s*\|\s*/", $choicesStr);
        $choices = array();
        foreach ($choicePairs as $pair) {
            $a = preg_split("/\s*,\s*/", $pair);
            if (count($a) == 2) {
                $choices[$a[0]] = $a[1];
            } else if (count($a) > 2) {
                $a = preg_split("/,/", $pair);
                $b = array();
                for ($i = 1; $i < count($a); $i++) {
                    $b[] = $a[$i];
                }
                $choices[trim($a[0])] = trim(implode(",", $b));
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

	public static function filterMetadataForForm($metadata, $instrument) {
	    $filteredMetadata = [];
	    foreach ($metadata as $row) {
	        if ($row['form_name'] == $instrument) {
	            $filteredMetadata[] = $row;
            }
        }
	    return $filteredMetadata;
    }

	public static function makeConjunction($list) {
	    if (count($list) == 1) {
            return $list[0];
        } else if (count($list) == 2) {
	        return $list[0]." and ".$list[1];
        } else {
	        $lastElem = $list[count($list) - 2].", and ".$list[count($list) - 1];
	        $elems = [];
	        for ($i = 0; $i < count($list) - 2; $i++) {
	            $elems[] = $list[$i];
            }
	        $elems[] = $lastElem;
	        return implode(", ", $elems);
        }
    }

	public static function stripHTML($htmlStr) {
        return preg_replace("/<[^>]+>/", "", $htmlStr);
    }

    public static function formatMangledText($str) {
	    return utf8_decode($str);
    }

	public static function getNormativeRow($rows) {
        foreach ($rows as $row) {
            if (!isset($row['redcap_repeat_instrument']) && !isset($row['redcap_repeat_instance'])) {
                return $row;
            }
            if (($row['redcap_repeat_instrument'] == "") && ($row['redcap_repeat_instance'] == "")) {
                return $row;
            }
        }
        return [];
    }

	public static function getDifferencesInArrays($ary1, $ary2) {
	    if (self::arraysEqual($ary1, $ary2)) {
	        return "";
        }
	    $notes = [];
	    foreach ($ary1 as $key => $value1) {
	        if (!isset($ary2[$key])) {
	            $notes[] = "ary2 missing $key";
	        }
	        if ($ary2[$key] != $value1) {
	            $notes[] = "$key: $value1 vs. ".$ary2[$key];
            }
        }
	    return implode(", ", $notes);
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

    public static function transformFieldsIntoPrefixes($fields) {
	    $prefixes = [];
	    foreach ($fields as $field) {
	        $prefix = self::getPrefix($field);
	        if (!in_array($prefix, $prefixes)) {
	            $prefixes[] = $prefix;
            }
        }
	    return $prefixes;
    }

    public static function MY2YMD($my) {
	    if (!$my) {
	        return "";
        }
        $sep = "-";
	    $nodes = preg_split("/[\-\/]/", $my);
	    if (count($nodes) == 2) {
            return $nodes[1] . $sep . $nodes[0] . $sep . "01";
        } else if (count($nodes) == 3) {
            $mdy = $my;
            return self::MDY2YMD($mdy);
        } else if (count($nodes) == 1) {
	        $year = $nodes[0];
	        if ($year > 1900) {
	            return $year."-01-01";
            } else {
	            throw new \Exception("Invalid year: $year");
            }
        } else {
	        throw new \Exception("Cannot convert MM/YYYY $my");
        }
    }

    public static function getPrefix($field) {
        $nodes = preg_split("/_/", $field);
        if ($nodes[0] == "newman") {
            return $nodes[0]."_".$nodes[1];
        }
	    return $nodes[0];
    }

    public static function getMaxInstance($rows, $instrument, $recordId) {
        $max = 0;
        foreach ($rows as $row) {
            if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == $instrument) && ($row['redcap_repeat_instance'] > $max)) {
                $max = $row['redcap_repeat_instance'];
            }
        }
        return $max;
    }

    public static function getMaxInstanceForEvent($rows, $eventName, $recordId) {
        $max = 0;
        foreach ($rows as $row) {
            if (($row['record_id'] == $recordId) && ($row['redcap_event_name'] == $eventName) && ($row['redcap_repeat_instance'] > $max)) {
                $max = $row['redcap_repeat_instance'];
            }
        }
        return $max;
    }

    public static function makeHTMLId($id) {
        $htmlFriendly = preg_replace("/\s+/", "_", $id);
        $htmlFriendly = preg_replace("/[\"'#<>\~\`\!\@\#\$\%\^\&\*\(\)\=]/", "", $htmlFriendly);
        return $htmlFriendly;
    }

    public static function getRowForFieldFromMetadata($field, $metadata) {
	    foreach ($metadata as $row) {
	        if ($row['field_name'] == $field) {
	            return $row;
            }
        }
	    return array();
    }

    public static function getYear($date) {
        $ts = strtotime($date);
        if ($ts) {
            return date("Y", $ts);
        }
        return "";
    }

    public static function getDayDuration($date1, $date2) {
	    $span = self::getSecondDuration($date1, $date2);
	    $oneDay = 24 * 3600;
	    return $span / $oneDay;
    }

    public static function getSecondDuration($date1, $date2) {
        $ts1 = strtotime($date1);
        $ts2 = strtotime($date2);
        if ($ts1 && $ts2) {
            return abs($ts2 - $ts1);
        } else {
            throw new \Exception("Could not get timestamps from $date1 and $date2");
        }
    }

    public static function getYearDuration($date1, $date2) {
        $span = self::getSecondDuration($date1, $date2);
	    $oneYear = 365 * 24 * 3600;
	    return $span / $oneYear;
    }

    public static function matchAtLeastOneRegex($regexes, $str) {
	    foreach ($regexes as $regex) {
	        if (preg_match($regex, $str))  {
	            return TRUE;
            }
        }
	    return FALSE;
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

	public static function isValidChoice($value, $fieldChoices) {
	    if ($fieldChoices) {
	        return isset($fieldChoices[$value]);
        }
	    # no choices
	    return TRUE;
    }

	public static function getFieldsFromMetadata($metadata, $instrument = FALSE) {
		$fields = array();
		foreach ($metadata as $row) {
		    if ($instrument) {
		        if ($instrument == $row['form_name']) {
                    array_push($fields, $row['field_name']);
                }
            } else {
                array_push($fields, $row['field_name']);
            }
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

	public static function REDCapTsToPHPTs($redcapTs) {
	    $year = substr($redcapTs, 0, 4);
	    $month = substr($redcapTs, 4, 2);
	    $day = substr($redcapTs, 6, 2);
	    return strtotime("$year-$month-$day");
    }

	public static function getNumberOfRows($rows, $instrument) {
	    $numRows = 0;
	    foreach ($rows as $row) {
	        if ($row['redcap_repeat_instrument'] == $instrument) {
	            $numRows++;
            }
        }
	    return $numRows;
    }

	public static function isValidIP($str) {
	    if (preg_match("/^\b(?:(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\.){3}(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\b$/", $str)) {
	        # numeric
            return TRUE;
        }
	    if (preg_match("/^\b\w\.\w+\b$/", $str)) {
	        # word
	        return TRUE;
        }
	    return FALSE;
    }

	public static function applyProxyIfExists(&$ch) {
        $proxyIP = Application::getSetting("proxy-ip");
        $proxyPort = Application::getSetting("proxy-port");
        $proxyUsername = Application::getSetting("proxy-user");
        $proxyPassword = Application::getSetting("proxy-pass");
        if ($proxyIP && $proxyPort && is_numeric($proxyPort)&& $proxyPassword && $proxyUsername) {
            $proxyOpts = [
                CURLOPT_HTTPPROXYTUNNEL => 1,
                CURLOPT_PROXY => $proxyIP,
                CURLOPT_PROXYPORT => $proxyPort,
                CURLOPT_PROXYUSERPWD => "$proxyUsername:$proxyPassword",
            ];
            foreach ($proxyOpts as $opt => $value) {
                curl_setopt($ch, $opt, $value);
            }
        }
    }

    public static function makeChoiceStr($fieldChoices) {
	    $pairs = [];
	    foreach ($fieldChoices as $key => $label) {
	        $pairs[] = "$key, $label";
        }
	    return implode(" | ", $pairs);
    }

	public static function downloadURL($url, $addlOpts = [], $autoRetriesLeft = 3) {
        Application::log("Contacting $url");
        $defaultOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_VERBOSE => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_AUTOREFERER => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_FRESH_CONNECT => 1,
            CURLOPT_TIMEOUT => 120,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        foreach ($defaultOpts as $opt => $value) {
            if (!isset($addlOpts[$opt])) {
                curl_setopt($ch, $opt, $value);
            }
        }
        foreach ($addlOpts as $opt => $value) {
            curl_setopt($ch, $opt, $value);
        }
        self::applyProxyIfExists($ch);
        $data = curl_exec($ch);
        $resp = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if(curl_errno($ch)){
            Application::log(curl_error($ch));
            if ($autoRetriesLeft > 0) {
                sleep(30);
                Application::log("Retrying ($autoRetriesLeft left)...");
                self::downloadURL($url, $addlOpts, $autoRetriesLeft - 1);
            } else {
                throw new \Exception(curl_error($ch));
            }
        }
        curl_close($ch);
        Application::log("Response code $resp; ".strlen($data)." bytes");
        return array($resp, $data);
    }

    public static function stripNickname($firstName) {
        return preg_replace("/\s+\(.+\)/", "", $firstName);
    }

    public static function getMetadataFieldsToScreen() {
		return array("required_field", "form_name", "identifier", "branching_logic", "section_header", "field_annotation");
	}

	public static function findInData($data, $fields) {
        if (!is_array($fields)) {
            $fields = array($fields);
        }

        foreach ($fields as $field) {
            if (preg_match("/^coeus_/", $field) || preg_match("/^coeus2_/", $field)) {
                $values = array();
                foreach ($data as $row) {
                    $instance = $row['redcap_repeat_instance'];
                    if (isset($row[$field]) && ($row[$field] != "")) {
                        $values[$instance] = preg_replace("/'/", "\\'", $row[$field]);;
                    }
                }
                if (!empty($values)) {
                    return $values;
                }
            } else {
                foreach ($data as $row) {
                    if (($row['redcap_repeat_instrument'] == "") && isset($row[$field]) && ($row[$field] != "")) {
                        return preg_replace("/'/", "\\'", $row[$field]);
                    }
                }
            }
        }
        return "";
    }

	public static function findField($redcapData, $recordId, $field, $instrument = FALSE, $instance = FALSE) {
	    foreach ($redcapData as $row) {
	        if ($row['record_id'] == $recordId) {
	            if ($instance && $instrument) {
                    if (($instrument == $row['redcap_repeat_instrument']) && ($instance == $row['redcap_repeat_instance'])) {
                        return $row[$field];
                    }
                } else if ($instrument) {
                    if ($instrument == $row['redcap_repeat_instrument']) {
                        return $row[$field];
                    }
                } else if ($row[$field]) {
                    return $row[$field];
                }
            }
        }
	    return "";
    }

    public static function getParametersAsHiddenInputs($url) {
        $params = self::getParameters($url);
        $html = [];
        foreach ($params as $key => $value) {
            $html[] = "<input type='hidden' name='$key' value='$value'>";
        }
        return implode("\n", $html);
    }

    public static function getParameters($url) {
        $nodes = explode("?", $url);
        $params = [];
        if (count($nodes) > 0) {
            $pairs = explode("&", $nodes[1]);
            foreach ($pairs as $pair) {
                if ($pair) {
                    $pairNodes = explode("=", $pair);
                    if (count($pairNodes) >= 2) {
                        $params[$pairNodes[0]] = $pairNodes[1];
                    } else {
                        $params[$pairNodes[0]] = "";
                    }
                }
            }
        }
        return $params;
    }

    public static function getPage($url) {
	    $nodes = explode("?", $url);
	    return $nodes[0];
    }

    public static function json_encode_with_spaces($data) {
        $str = json_encode($data);
        $str = preg_replace("/,/", ", ", $str);
        return $str;
    }

    public static function removeBlanksFromAry($ary) {
	    $newAry = [];
	    foreach ($ary as $item) {
	        if ($item !== "") {
	            $newAry[] = $item;
            }
        }
	    return $newAry;
    }

    public static function isDate($str) {
	    if (preg_match("/^\d+[\/\-]\d+[\/\-]\d+$/", $str)) {
	        $nodes = preg_split("/[\/\-]/", $str);
	        $earliestYear = 1900;
	        if (count($nodes) == 3) {
                if (($nodes[0] >= $earliestYear) && ($nodes[1] <= 12) && ($nodes[2] <= 31)) {
                    # YMD
                    return TRUE;
                } else if (($nodes[0] <= 12) && ($nodes[1] <= 31) && ($nodes[2] >= $earliestYear)) {
                    # MDY
                    return TRUE;
                } else if (($nodes[0] <= 31) && ($nodes[1] <= 12) && ($nodes[2] >= $earliestYear)) {
                    # DMY
                    return TRUE;
                }
            }
        }
        return FALSE;
    }

    public static function convertDollarsToNumber($value) {
        if (strpos($value, "$") === 0) {
            $value = str_replace("$", "", $value);
            $value = str_replace(",", "", $value);
        }
        return $value;
    }

    public static function convertNumberToDollars($value) {
	    return "$".$value;
    }

    public static function isValidSurvey($pid, $hash) {
	    if ($hash) {
            $sql = "select s.project_id AS project_id from redcap_surveys_participants AS p INNER JOIN redcap_surveys AS s ON s.survey_id = p.survey_id WHERE p.hash ='".db_real_escape_string($hash)."' AND s.project_id='".db_real_escape_string($pid)."'";
            $q = db_query($sql);
            return (db_num_rows($q) > 0);
        }
	    return FALSE;
    }

    public static function getUserNames($username) {
        $sql = "select user_firstname, user_lastname from redcap_user_information WHERE username = '".db_real_escape_string($username)."'";
        $q = db_query($sql);
        if ($row = db_fetch_assoc($q)) {
            return [$row['user_firstname'], $row['user_lastname']];
        }
        return ["", ""];
    }

    public static function fillInLinks($text) {
        return preg_replace(
            '/((https?|ftp):\/\/(\S*?\.\S*?))([\s)\[\]{},;"\':<]|\.\s|$)/i',
            "<a href=\"$1\" target=\"_blank\">$3</a>$4",
            $text
        );
    }
    public static function getUseridsFromREDCap($firstName, $lastName) {
        $firstName = strtolower($firstName);
        $lastName = strtolower($lastName);
        $sql = "select username from redcap_user_information WHERE LOWER(user_firstname) = '".db_real_escape_string($firstName)."' AND LOWER(user_lastname) = '".db_real_escape_string($lastName)."'";
        $q = db_query($sql);
        $userids = [];
        while ($row = db_fetch_assoc($q)) {
            $userids[] = $row['username'];
        }
        return $userids;
    }

    public static function getEmailFromUseridFromREDCap($userid) {
        $sql = "select user_email from redcap_user_information WHERE LOWER(username) = '".db_real_escape_string($userid)."'";
        $q = db_query($sql);
        if ($row = db_fetch_assoc($q)) {
            return $row['user_email'];
        }
        return "";
    }

    public static function getUseridFromREDCap($firstName, $lastName) {
	    $userids = self::getUseridsFromREDCap($firstName, $lastName);
	    if (count($userids) > 0) {
	        return $userids[0];
        }
	    return "";
    }

    public static function deDupREDCapRows($rows, $instrument, $recordId) {
	    $debug = FALSE;
	    $i = 0;
	    $skip = ["record_id", "redcap_repeat_instrument", "redcap_repeat_instance"];
	    $newRows = [];
	    $duplicates = [];
	    if ($debug) {
            Application::log("deDupREDCapRows A: ".count($rows)." rows");
        }
	    foreach ($rows as $row1) {
            $newRows[$i] = $row1;
	        $j = 0;
	        foreach ($rows as $row2) {
                if ($debug) {
                    Application::log("deDupREDCapRows B ($i, $j): ".json_encode($duplicates));
                    Application::log("deDupREDCapRows C ($i, $j, $instrument, $recordId): ".json_encode($row1));
                    Application::log("deDupREDCapRows D ($i, $j, $instrument, $recordId): ".json_encode($row2));
                }
	            if (($i < $j)
                    && !in_array($j, $duplicates) && !in_array($i, $duplicates)
                    && ($row1["redcap_repeat_instrument"] == $instrument) && ($row2["redcap_repeat_instrument"] == $instrument)
                    && ($row1["record_id"] == $recordId) && ($row2["record_id"] == $recordId)) {
                    if ($debug) {
                        Application::log("deDupREDCapRows E ($i, $j)");
                    }
	                $allMatch = TRUE;
	                foreach ($row1 as $field => $value) {
                        if (!in_array($field, $skip) && ($row1[$field] != $row2[$field])) {
                            $allMatch = FALSE;
                            break;
                        }
                    }
	                if ($allMatch) {
                        if ($debug) {
                            Application::log("deDupREDCapRows F ($i, $j)");
                        }
                        $duplicates[] = $j;
                    }
                } else {
                    if ($debug) {
                        Application::log("deDupREDCapRows G ".json_encode($i < $j));
                        Application::log("deDupREDCapRows H ".json_encode(!in_array($j, $duplicates) && !in_array($i, $duplicates)));
                        Application::log("deDupREDCapRows I {$row1["redcap_repeat_instrument"]} {$row2["redcap_repeat_instrument"]} $instrument ".json_encode(($row1["redcap_repeat_instrument"] == $instrument) && ($row2["redcap_repeat_instrument"] == $instrument)));
                        Application::log("deDupREDCapRows J {$row1["record_id"]} {$row2["record_id"]} $recordId ".json_encode(($row1["record_id"] == $recordId) && ($row2["record_id"] == $recordId)));
                    }
                }
	            $j++;
            }
	        $i++;
        }

	    # re-index
	    $i = 1;
	    foreach ($newRows as $idx => $row) {
	        $newRows[$idx]["redcap_repeat_instance"] = $i;
	        $i++;
        }
	    return array_values($newRows);
    }

	# if present, $fields contains the fields to copy over; if left as an empty array, then it attempts to install all fields
	# $deletionRegEx contains the regular expression that marks fields for deletion
	# places new metadata rows AFTER last match from $existingMetadata
	public static function mergeMetadataAndUpload($existingMetadata, $newMetadata, $token, $server, $fields = array(), $deletionRegEx = "/___delete$/") {
		$fieldsToDelete = self::getFieldsWithRegEx($newMetadata, $deletionRegEx, TRUE);

		if (empty($fields)) {
			$selectedRows = $newMetadata;
		} else {
			$selectedRows = self::getRowsForFieldsFromMetadata($fields, $newMetadata);
		}
		foreach ($selectedRows as $newRow) {
			if (!in_array($newRow['field_name'], $fieldsToDelete)) {
				$priorRowField = end($existingMetadata)['field_name'];
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
					    $newRow = self::copyMetadataSettingsForField($newRow, $newMetadata, $upload, $token, $server);

						# delete already existing rows with same field_name
						self::deleteRowsWithFieldName($tempMetadata, $newRow['field_name']);
						array_push($tempMetadata, $newRow);
						$priorNewRowField = $newRow['field_name'];
					}
				}
				$existingMetadata = $tempMetadata;
			}
		}
        $metadataFeedback = Upload::metadata($existingMetadata, $token, $server);
        return $metadataFeedback;
	}

	private static function deleteRowsWithFieldName(&$metadata, $fieldName) {
		$newMetadata = array();
		foreach ($metadata as $row) {
			if ($row['field_name'] != $fieldName) {
				array_push($newMetadata, $row);
			}
		}
		$metadata = $newMetadata;
	}

	private static function isJSON($str) {
	    json_decode($str);
	    return (json_last_error() == JSON_ERROR_NONE);
    }

    public static function copyMetadataSettingsForField($row, $metadata, &$upload, $token, $server) {
        foreach ($metadata as $metadataRow) {
            if ($metadataRow['field_name'] == $row['field_name']) {
                # do not overwrite any settings in associative arrays
                foreach (self::getMetadataFieldsToScreen() as $rowSetting) {
                    if ($rowSetting == "select_choices_or_calculations") {
                        // merge
                        $rowChoices = self::getRowChoices($row[$rowSetting]);
                        $metadataChoices = self::getRowChoices($metadataRow[$rowSetting]);
                        $mergedChoices = $rowChoices;
                        foreach ($metadataChoices as $idx => $label) {
                            if (!isset($mergedChoices[$idx])) {
                                $mergedChoices[$idx] = $label;
                            } else if (isset($mergedChoices[$idx]) && ($mergedChoices[$idx] == $label)) {
                                # both have same idx/label - no big deal
                            } else {
                                # merge conflict => reassign all data values
                                $field = $row['field_name'];
                                $oldIdx = $idx;
                                $newIdx = max(array_keys($mergedChoices)) + 1;
                                Application::log("Merge conflict for field $field: Moving $oldIdx to $newIdx ($label)");

                                $mergedChoices[$newIdx] = $label;
                                $values = Download::oneField($token, $server, $field);
                                $newRows = 0;
                                foreach ($values as $recordId => $value) {
                                    if ($value == $oldIdx) {
                                        if (isset($upload[$recordId])) {
                                            $upload[$recordId][$field] = $newIdx;
                                        } else {
                                            $upload[$recordId] = array("record_id" => $recordId, $field => $newIdx);
                                        }
                                        $newRows++;
                                    }
                                }
                                Application::log("Uploading data $newRows rows for field $field");
                            }
                        }
                        $pairedChoices = array();
                        foreach ($mergedChoices as $idx => $label) {
                            array_push($pairedChoices, "$idx, $label");
                        }
                        $row[$rowSetting] = implode(" | ", $pairedChoices);
                    } else if ($row[$rowSetting] != $metadataRow[$rowSetting]) {
                        if (!self::isJSON($row[$rowSetting]) || ($rowSetting != "field_annotation")) {
                            $row[$rowSetting] = $metadataRow[$rowSetting];
                        }
                    }
                }
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

    public static function prettyMoney($n, $displayCents = TRUE) {
        if ($displayCents) {
            return "\$".self::pretty($n, 2);
        } else {
            return "\$".self::pretty($n, 0);
        }
    }

    public static function pretty($n, $numDecimalPlaces = 3) {
        $s = "";
        $n2 = abs($n);
        $n2int = intval($n2);
        $decimal = $n2 - $n2int;
        while ($n2int > 0) {
            $s1 = ($n2int % 1000);
            $n2int = floor($n2int / 1000);
            if (($s1 < 100) && ($n2int > 0)) {
                if ($s1 < 10) {
                    $s1 = "0".$s1;
                }
                $s1 = "0".$s1;
            }
            if ($s) {
                $s = $s1.",".$s;
            } else {
                $s = $s1;
            }
        }
        if ($decimal && is_int($numDecimalPlaces) && ($numDecimalPlaces > 0)) {
            $decimal = ".".floor($decimal * pow(10, $numDecimalPlaces));
        } else {
            $decimal = "";
        }
        if (!$s) {
            $s = "0";
        }
        if ($n < 0) {
            if (!$decimal) {
                return "-".$s;
            } else {
                return "-".$s.$decimal;
            }
        }
        if (!$decimal) {
            return $s;
        } else {
            return $s.$decimal;
        }
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

	public static function addYears($date, $years) {
	    $ts = strtotime($date);
	    $year = date("Y", $ts);
	    $year += $years;
	    $monthDays = date("-m-d", $ts);
	    return $year.$monthDays;
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

	public static function datetime2Date($datetime) {
	    if (preg_match("/\s/", $datetime)) {
	        $nodes = preg_split("\s+", $datetime);
	        return $nodes[0];
        }
	    # date, not datetime
	    return $datetime;
    }

    public static function filterForREDCap($row, $metadataFields) {
	    $newRow = [];
	    $redcapManagementFields = ["record_id", "redcap_repeat_instrument", "redcap_repeat_instance"];
	    foreach ($row as $field => $value) {
	        if (in_array($field, $metadataFields)
                || preg_match("/_complete$/", $field)
                || in_array($field, $redcapManagementFields)) {
	            $newRow[$field] = $value;
            } else {
	            Application::log("Filtering out ".$field);
            }
        }
	    return $newRow;
    }

	public static function getRowsForRecord($redcapData, $recordId) {
	    $rows = [];
	    foreach ($redcapData as $row) {
	        if ($row['record_id'] == $recordId) {
	            $rows[] = $row;
            }
        }
	    return $rows;
    }

	public static function indexMetadata($metadata) {
	    $indexed = [];
	    foreach ($metadata as $row) {
	        $indexed[$row['field_name']] = $row;
        }
	    return $indexed;
    }

	public static function hasMetadataChanged($oldValue, $newValue, $metadataField) {
	    if ($metadataField == "field_annotation" && self::isJSON($oldValue)) {
	        return FALSE;
        }
	    if (isset($oldValue) && isset($newValue) && ($oldValue != $newValue)) {
            return TRUE;
        }
        return FALSE;
    }

    public static function makeHiddenInputs($params) {
        $items = [];
        foreach ($params as $key => $value) {
            $html = "<input type='hidden' id='$key' name='$key'";
            if ($value !== "") {
                $html .= " value='$value'";
            }
            $html .= ">";
            $items[] = $html;
        }
        return implode("", $items);
    }

    public static function splitURL($fullURL) {
        list($url, $paramList) = explode("?", $fullURL);
        $pairs = explode("&", $paramList);
        $params = [];
        foreach ($pairs as $pair) {
            $items = explode("=", $pair);
            if (count($items) == 2) {
                $params[$items[0]] = urldecode($items[1]);
            } else if (count($items) == 1) {
                $params[$items[0]] = "";
            } else {
                throw new \Exception("This should never happen. A GET parameter has ".count($items)." items.");
            }
        }
        return [$url, $params];
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

	public static function arraysEqual($ary1, $ary2) {
	    if (!isset($ary1) || !isset($ary2)) {
	        return FALSE;
        }
        if (!is_array($ary1) || !is_array($ary2)) {
            return FALSE;
        }
	    foreach ([$ary1 => $ary2, $ary2 => $ary1] as $aryA => $aryB) {
            foreach ($aryA as $key => $valueA) {
                if (!isset($aryB[$key])) {
                    return FALSE;
                }
                $valueB = $aryB[$key];
                if ($valueA !== $valueB) {
                    return FALSE;
                }
            }
        }
	    return TRUE;
    }

	public static function getActiveProjects($pids) {
	    $activeProjects = [];
	    foreach ($pids as $pid) {
	        if (self::isActiveProject($pid)) {
	            $activeProjects[] = $pid;
            }
        }
	    return $activeProjects;
    }

	public static function isActiveProject($pid) {
		$sql = "SELECT date_deleted FROM redcap_projects WHERE project_id = '".db_real_escape_string($pid)."' LIMIT 1";
		$q = db_query($sql);
		if ($row = db_fetch_assoc($q)) {
			if (!$row['date_deleted']) {
				return TRUE;
			}
		}
		return FALSE;
	}

	public static function filterOutVariable($var, $row) {
		$newRow = array();
		foreach ($row as $field => $value) {
			if (($field != $var) && (!preg_match("/^".$var."___/", $field))) {
				$newRow[$field] = $value;
			}
		}
		return $newRow;
	}

	public static function datediff($d1, $d2, $measurement) {
		return datediff($d1, $d2, $measurement);
	}
}
