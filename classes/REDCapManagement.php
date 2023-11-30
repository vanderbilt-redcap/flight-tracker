<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class REDCapManagement {
	public static function getChoices($metadata) {
	    return DataDictionaryManagement::getChoices($metadata);
	}

	public static function isWebBrowser() {
	    return isset($_GET['pid']);
    }

    public static function compressArray(&$ary) {
        $ary = array_values($ary);
    }

    public static function isInProduction($pid) {
        $module = Application::getModule();
        if ($module) {
            $sql = "SELECT status FROM redcap_projects WHERE project_id = ?";
            $q = $module->query($sql, [$pid]);
            if ($row = $q->fetch_assoc()) {
                Application::log("inProd? ".json_encode($row));
                if ($row['status'] === 1) {
                    return TRUE;
                } else {
                    return FALSE;
                }
            }
        }
        return FALSE;
    }

    public static function setToDevelopment($pid) {
        $module = Application::getModule();
        $sql = "UPDATE redcap_projects SET status = 0, production_time = NULL WHERE project_id = ?";
        $module->query($sql, [$pid]);
    }

    public static function isAssoc($ary) {
        if (empty($ary)) {
            return FALSE;
        }
        return array_keys($ary) !== range(0, count($ary) - 1);
    }

    public static function makeArrayOneType($oneDimensionalArray, $dataType) {
        $newArray = [];
        $dataType = strtolower($dataType);
        if (!in_array($dataType, ["int", "string"])) {
            throw new \Exception("Invalid data type $dataType! Please use int or string.");
        }
        foreach ($oneDimensionalArray as $item) {
            if ($dataType == "int") {
                $newArray[] = (int) $item;
            } else if ($dataType == "string") {
                $newArray[] = (string) $item;
            }
        }
        return $newArray;
    }

    public static function getFormsFromMetadata($metadata) {
	    return DataDictionaryManagement::getFormsFromMetadata($metadata);
    }

    public static function getWeekNumInYear($ts = FALSE) {
	    return DateManagement::getWeekNumInYear($ts);
    }

    public static function getWeekNumInMonth($ts = FALSE) {
        return DateManagement::getWeekNumInMonth($ts);
    }

    public static function makeSafeFilename($filename) {
	    return FileManagement::makeSafeFilename($filename);
    }

	public static function filterOutInvalidFields($metadata, $fields) {
	    return DataDictionaryManagement::filterOutInvalidFields($metadata, $fields);
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
	    return DataDictionaryManagement::getRowChoices($choicesStr);
    }

    public static function getRepeatingForms($pid) {
	    return DataDictionaryManagement::getRepeatingForms($pid);
	}

	public static function filterMetadataForForm($metadata, $instrument) {
	    return DataDictionaryManagement::filterMetadataForForm($metadata, $instrument);
    }

    public static function getAllGrantFields($metadata) {
        $fields = ["record_id"];
        $forms = ["exporter", "nih_reporter", "reporter", "nsf", "vera", "coeus", "custom_grant", "coeus2", "ies_grant"];
        foreach ($forms as $form) {
            $fields = array_unique(array_merge($fields, REDCapManagement::getFieldsFromMetadata($metadata, $form)));
        }
        return $fields;
    }

    public static function getAllGrantFieldsFromFieldlist($metadataFields) {
        $fields = ["record_id"];
        $prefixes = ["exporter_", "nih_", "reporter_", "nsf_", "vera_", "coeus_", "custom_", "coeus2_", "ies_"];
        foreach ($prefixes as $prefix) {
            foreach ($metadataFields as $field) {
                if (preg_match("/^$prefix/", $field)) {
                    $fields[] = $field;
                }
            }
        }
        return array_unique($fields);
    }

    public static function makeConjunction($list, $conjugation = "and") {
        if (count($list) == 0) {
            return "";
        } else if (count($list) == 1) {
            return $list[0];
        } else if (count($list) == 2) {
	        return $list[0]." $conjugation ".$list[1];
        } else {
	        $lastElem = $list[count($list) - 2].", $conjugation ".$list[count($list) - 1];
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

	public static function getSurveys($pid, $metadata = []) {
	    return DataDictionaryManagement::getSurveys($pid, $metadata);
	}

	public static function getReporterDateInYMD($dt) {
        return DateManagement::getReporterDateInYMD($dt);
    }

    public static function getCurrentFY($type, $ts = "current") {
        if ($ts == "current") {
            $ts = time();
        }
        $y = date("Y", $ts);
        $month = date("m", $ts);
	    if ($type == "Federal") {
	        if ($month >= 10) {
	            $y++;
            }
        } else if (in_array($type, ["Academic", "Medical"])) {
            if ($month >= 7) {
                $y++;
            }
        }
        return $y;
    }

    public static function totalValuesInArray($ary) {
        $total = 0;
        foreach ($ary as $key => $value) {
            if (!is_array($value)) {
                $total++;
            }
        }
        foreach ($ary as $key => $value) {
            if (is_array($value)) {
                $total += self::totalValuesInArray($value);
            }
        }
        return $total;
    }

    public static function getInstrumentFromPrefix($prefix, $metadata) {
	    $forms = self::getFormsFromMetadata($metadata);
	    $prefix = preg_replace("/_$/", "", $prefix);
	    foreach ($forms as $instrument) {
	        $currPrefix = self::getPrefixFromInstrument($instrument);
	        if ($prefix == $currPrefix) {
	            return $instrument;
            }
        }
	    return "";
    }

	public static function getPrefixFromInstrument($instrument) {
        if ($instrument == "initial_survey") {
            $prefix = "check";
        } else if ($instrument == "initial_import") {
            $prefix = "init_import";
        } else if ($instrument == "followup") {
            $prefix = "followup";
        } else if ($instrument == "identifiers") {
            $prefix = "identifier";
        } else if ($instrument == "manual") {
            $prefix = "override";
        } else if ($instrument == "manual_import") {
            $prefix = "imported";
        } else if ($instrument == "manual_degree") {
            $prefix = "imported";
        } else if ($instrument == "summary") {
            $prefix = "summary";
        } else if ($instrument == "demographics") {
            $prefix = "newman_demographics";
        } else if ($instrument == "data") {
            $prefix = "newman_data";
        } else if ($instrument == "sheet2") {
            $prefix = "newman_sheet2";
        } else if ($instrument == "nonrespondents") {
            $prefix = "newman_nonrespondents";
        } else if ($instrument == "new_2017") {
            $prefix = "newman_new";
        } else if ($instrument == "spreadsheet") {
            $prefix = "spreadsheet";
        } else if ($instrument == "vfrs") {
            $prefix = "vfrs";
        } else if ($instrument == "coeus") {
            $prefix = "coeus";
        } else if ($instrument == "coeus2") {
            $prefix = "coeus2";
        } else if ($instrument == "custom_grant") {
            $prefix = "custom";
        } else if ($instrument == "reporter") {
            $prefix = "reporter";
        } else if ($instrument == "exporter") {
            $prefix = "exporter";
        } else if ($instrument == "nih_reporter") {
            $prefix = "nih";
        } else if ($instrument == "nsf") {
            $prefix = "nsf";
        } else if ($instrument == "ies_grant") {
            $prefix = "ies";
        } else if ($instrument == "eric") {
            $prefix = "eric";
        } else if ($instrument == "citation") {
            $prefix = "citation";
        } else if ($instrument == "resources") {
            $prefix = "resources";
        } else if ($instrument == "honors_and_awards") {
            $prefix = "honor";
        } else if ($instrument == "ldap") {
            $prefix = "ldap";
        } else if ($instrument == "ldapds") {
            $prefix = "ldapds";
        } else if ($instrument == "workday") {
            $prefix = "workday";
        } else if ($instrument == "coeus_submission") {
            $prefix = "coeussubmission";
        } else if ($instrument == "vera") {
            $prefix = "vera";
        } else if ($instrument == "vera_submission") {
            $prefix = "verasubmission";
        } else if ($instrument == "position_change") {
            $prefix = "promotion";
        } else if ($instrument == "exclude_lists") {
            $prefix = "exclude";
        } else if ($instrument == "patent") {
            $prefix = "patent";
        } else {
            $prefix = "";
        }
        return $prefix;
    }

	public static function dateCompare($d1, $op, $d2) {
        return DateManagement::dateCompare($d1, $op, $d2);
    }

    public static function convertInstrumentNameToPrefix($instrument, $metadata) {
        foreach ($metadata as $row) {
            if ($row['form_name'] == $instrument) {
                $nodes = explode("_", $row['field_name']);
                if ($nodes[0] == "newman") {
                    return $nodes[0]."_".$nodes[1];
                }
                return $nodes[0];
            }
        }
        return "";
    }

    public static function translateFormToName($instrument) {
	    return DataDictionaryManagement::translateFormToName($instrument);
    }

    public static function transformFieldsIntoPrefixes($fields) {
	    return DataDictionaryManagement::transformFieldsIntoPrefixes($fields);
    }

    public static function isMY($str) {
        return DateManagement::isMY($str);
    }

    public static function MY2YMD($my) {
	    return DateManagement::MY2YMD($my);
    }

    public static function getPrefix($field) {
	    return DataDictionaryManagement::getPrefix($field);
    }

    public static function getInstances($rows, $instrument, $recordId) {
        $instances = [];
        foreach ($rows as $row) {
            if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == $instrument) && !in_array($row['redcap_repeat_instance'], $instances)) {
                $instances[] = (int) $row['redcap_repeat_instance'];
            }
        }
        return $instances;
    }

    public static function getMaxInstance($rows, $instrument, $recordId) {
	    $instances = self::getInstances($rows, $instrument, $recordId);
        return empty($instances) ? "0" : max($instances);
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

    # Coordinated with React's MainGroup.js makeHTMLId
    public static function makeHTMLId($id) {
        $htmlFriendly = preg_replace("/[\s\-]+/", "_", $id);
        $htmlFriendly = preg_replace("/<[^>]+>/", "", $htmlFriendly);
        $htmlFriendly = preg_replace("/[\:\+\"\/\[\]'#<>\~\`\!\@\#\$\%\^\&\*\(\)\=\;\?\.\,]/", "", $htmlFriendly);
        $htmlFriendly = Sanitizer::sanitizeOutput($htmlFriendly);
        return $htmlFriendly;
    }

    public static function makeHash($length) {
        $byteAmount = (int) floor($length / 2);
        if ($byteAmount > 0) {
            return bin2hex(random_bytes($byteAmount));
        }
        return "";
    }

    public static function getUseridsFromCoeus2($collaborators) {
        $userids = [];
        $nodes = preg_split("/\s*,\s*/", $collaborators);
        foreach ($nodes as $node) {
            if (preg_match("/^([\w\s]+)\s\((.+)\)$/", $node, $matches)) {
                $first = $matches[1];
                $second = $matches[2];
                if (preg_match("/;/", $second)) {
                    $pair = preg_split("/\s*;\s*/", $second);
                    $userid = $pair[0];
                } else {
                    $userid = $first;
                }
                $userids[] = $userid;
            }
        }
        return $userids;
    }

    public static function getRowForFieldFromMetadata($field, $metadata) {
	    return DataDictionaryManagement::getRowForFieldFromMetadata($field, $metadata);
    }

    public static function getYear($date) {
	    return DateManagement::getYear($date);
    }

    public static function getDayDuration($date1, $date2) {
	    return DateManagement::getDayDuration($date1, $date2);
    }

    public static function getSecondDuration($date1, $date2) {
	    return DateManagement::getSecondDuration($date1, $date2);
    }

    public static function getYearDuration($date1, $date2) {
	    return DateManagement::getYearDuration($date1, $date2);
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
	    return DataDictionaryManagement::getRowsForFieldsFromMetadata($fields, $metadata);
	}

	public static function isValidChoice($value, $fieldChoices) {
	    if ($fieldChoices) {
	        return isset($fieldChoices[$value]);
        }
	    # no choices
	    return TRUE;
    }

    # Fields to add:
    # - award number
    # - budget start date
    # - budget end date
    # - project start date
    # - project end date
    # - direct funding
    # - total funding
    # - [fields that are associated with role in GrantFactory.php]
    public static function getMinimalGrantFields($metadata) {
        $allFields = [
            "record_id",
            "nih_project_num", "nih_project_start_date", "nih_project_end_date", "nih_award_notice_date", "nih_award_amount", "nih_agency_ic_fundings", "nih_principal_investigators",
            "reporter_totalcostamount", "reporter_budgetstartdate", "reporter_budgetenddate", "reporter_projectstartdate", "reporter_projectenddate", "reporter_projectnumber", "reporter_otherpis", "reporter_contactpi",
            "coeus2_role", "coeus2_award_status", "coeus2_agency_grant_number", "coeus2_current_period_start", "coeus2_current_period_end", "coeus2_current_period_total_funding", "coeus2_current_period_direct_funding",
            "coeus_pi_flag", "coeus_sponsor_award_number", "coeus_total_cost_budget_period", "coeus_direct_cost_budget_period", "coeus_budget_start_date", "coeus_budget_end_date", "coeus_project_start_date", "coeus_project_end_date",
            "exporter_total_cost", "exporter_total_cost_sub_project", "exporter_pi_names", "exporter_full_project_num", "exporter_budget_start", "exporter_budget_end", "exporter_project_start", "exporter_project_end", "exporter_direct_cost_amt",
            "newman_demographics_first_individual_k_date",
            "newman_demographics_first_individual_k_date_note",
            "newman_demographics_first_individual_k_end_date",
            "newman_demographics_first_individual_k_year",
            "newman_demographics_degree_to_first_individual_k",
            "newman_demographics_age_at_first_k",
            "newman_demographics_first_r_date",
            "newman_demographics_first_r_date_note",
            "newman_demographics_first_r_end_date",
            "newman_data_current_program_funding_newman",
            "newman_data_previous_program_funding_newman",
            "newman_data_individual_k_start",
            "newman_data_individual_k_start_note",
            "newman_data_previous_nih_grant_funding_newman",
            "newman_data_nih_current",
            "newman_data_pending_nih_funding_ecommons",
            "newman_data_withdrawn_grants",
            "newman_data_date_first_institutional_k_award_newman",
            "newman_data_date_first_institutional_k_award_newman_note",
            "newman_data_individual_k_score",
            "newman_data_r01_start",
            "newman_data_r01_start_note",
            "newman_data_r01_score",
            "newman_sheet2_current_program_funding_2",
            "newman_sheet2_previous_program_funding_2",
            "newman_sheet2_institutional_k_start",
            "newman_sheet2_institutional_k_start_note",
            "newman_sheet2_previous_funding",
            "newman_sheet2_current_funding",
            "newman_sheet2_pending_grant",
            "newman_sheet2_withdrawn_grant",
            "newman_sheet2_noninstitutional_start",
            "newman_sheet2_noninstitutional_start_note",
            "newman_sheet2_individual_k_score",
            "newman_sheet2_first_r01_date",
            "newman_sheet2_first_r01_date_note",
            "newman_sheet2_first_r01_score",
            "newman_new_current_program_funding",
            "newman_new_first_institutional_k_award",
            "newman_new_current_nih_funding",
            "newman_new_first_individual_k_award",
        ];
        $allFields = array_unique(array_merge($allFields, Application::$customFields));
        return self::screenForFields($metadata, $allFields);
    }

    public static function getGrantNumberFields($metadata) {
        $candidateFields = [
            "custom_number",
            "reporter_projectnumber",
            "exporter_full_project_num",
            "nih_project_num",
            "coeus_award_no",
        ];
        for ($i=1; $i <= Grants::$MAX_GRANTS; $i++) {
            $candidateFields[] = "summary_award_sponsorno_".$i;
            $candidateFields[] = "check_grant".$i."_number";
            $candidateFields[] = "followup_grant".$i."_number";
            $candidateFields[] = "init_import_grant".$i."_number";
        }
        return self::screenForFields($metadata, $candidateFields);
    }

	public static function getFieldsFromMetadata($metadata, $instrument = FALSE) {
	    return DataDictionaryManagement::getFieldsFromMetadata($metadata, $instrument);
	}

	public static function getFieldsWithRegEx($metadata, $re, $removeRegex = FALSE) {
        return DataDictionaryManagement::getFieldsWithRegEx($metadata, $re, $removeRegex);
	}

	public static function REDCapTsToPHPTs($redcapTs) {
	    return DateManagement::REDCapTsToPHPTs($redcapTs);
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
	    return URLManagement::isValidIP($str);
    }

	public static function applyProxyIfExists(&$ch, $pid) {
	    URLManagement::applyProxyIfExists($ch, $pid);
    }

    public static function makeChoiceStr($fieldChoices) {
	    return DataDictionaryManagement::makeChoiceStr($fieldChoices);
    }

    public static function getRow($rows, $recordId, $instrument, $instance = 1) {
	    foreach ($rows as $row) {
	        if (($row['record_id'] == $recordId) &&
                ($row['redcap_repeat_instrument'] == $instrument)
                && ($row['redcap_repeat_instance'] == $instance)) {
	            return $row;
            }
        }
	    return [];
    }

    public static function hasValue($value) {
	    return (isset($value) && ($value !== ""));
    }

    public static function downloadURLWithPOST($url, $postdata = [], $pid = NULL, $addlOpts = [], $autoRetriesLeft = 3, $longRetriesLeft = 2) {
        return URLManagement::downloadURLWithPOST($url, $postdata, $pid, $addlOpts, $autoRetriesLeft, $longRetriesLeft);
    }

    public static function isValidURL($url) {
	    return filter_var($url, FILTER_VALIDATE_URL);
    }

    # many curses to Microsoft's autocorrect!!
    public static function changeSlantedQuotes($str) {
        $str = str_replace("“", "\"", $str);
        $str = str_replace("”", "\"", $str);
        $str = str_replace("’", "'", $str);
        return $str;
    }

    public static function changeSlantedQuotesInArray($ary) {
        foreach ($ary as $key => $value) {
            if (is_array($value)) {
                $ary[$key] = self::changeSlantedQuotesInArray($value);
            } else if (is_string($value)) {
                $ary[$key] = self::changeSlantedQuotes($value);
            } else {
                $ary[$key] = $value;
            }
        }
        return $ary;
    }

    public static function isGoodURL($url) {
        return URLManagement::isGoodURL($url);
    }

    public static function versionGreaterThanOrEqualTo($version1, $version2) {
        return version_compare($version1, $version2, ">=");
    }

    public static function getFieldsOfType($metadata, $fieldType, $validationType = "") {
	    return DataDictionaryManagement::getFieldsOfType($metadata, $fieldType, $validationType);
    }

	public static function downloadURL($url, $pid = NULL, $addlOpts = [], $autoRetriesLeft = 3) {
	    return URLManagement::downloadURL($url, $pid, $addlOpts, $autoRetriesLeft);
    }

    public static function stripNickname($firstName) {
        return preg_replace("/\s+\(.+\)/", "", $firstName);
    }

    public static function getMetadataFieldsToScreen() {
	    return DataDictionaryManagement::getMetadataFieldsToScreen();
	}

	public static function findInData($data, $fields, $oneValuePreferred = TRUE) {
        if (!is_array($fields)) {
            $fields = [$fields];
        }

        foreach ($fields as $field) {
            foreach ($data as $row) {
                if (($row['redcap_repeat_instrument'] == "") && isset($row[$field]) && ($row[$field] != "")) {
                    return preg_replace("/'/", "\\'", $row[$field]);
                }
            }

            $values = [];
            foreach ($data as $row) {
                $instance = $row['redcap_repeat_instance'];
                if (isset($row[$field]) && ($row[$field] != "")) {
                    $value = preg_replace("/'/", "\\'", $row[$field]);
                    if ($oneValuePreferred) {
                        return $value;
                    } else {
                        $values[$instance] = $value;
                    }
                }
            }
            if (!empty($values)) {
                return $values;
            }
        }
        return "";
    }

    public static function getFileSuffix($file) {
        return FileManagement::getFileSuffix($file);
    }

    public static function removeMoneyFormatting($amount) {
	    if (($amount === "") || is_numeric($amount)) {
	        return $amount;
        }
	    $amount = preg_replace("/^\\\$/", "", $amount);
	    $amount = str_replace(",", "", $amount);
	    return $amount;
    }

    public static function findAllFields($redcapData, $recordId, $field, $withInstances = FALSE) {
	    $values = [];
        foreach ($redcapData as $row) {
            if (($row['record_id'] == $recordId) && isset($row[$field]) && self::hasValue($row[$field])) {
                if ($withInstances) {
                    $values[$row['redcap_repeat_instance']] = $row[$field];
                } else {
                    $values[] = $row[$field];
                }
            }
        }
        return $values;
    }

    public static function trimSpecialChars($strOrAry) {
        $regex = '/[\x00-\x1F\x80-\xFF]/';
        if (is_string($strOrAry)) {
            return preg_replace($regex, "", $strOrAry);
        } else if (is_array($strOrAry)) {
            foreach ($strOrAry as $key => $value) {
                $strOrAry[$key] = preg_replace($regex, "", $strOrAry[$key]);
            }
            return $strOrAry;
        } else {
            throw new \Exception("Invalid: not string or array!");
        }
    }

	public static function findField($redcapData, $recordId, $field, $repeatingInstrument = FALSE, $instance = FALSE) {
        $values = [];
	    foreach ($redcapData as $row) {
	        if ($row['record_id'] == $recordId) {
	            if ($instance && $repeatingInstrument) {
                    if (($repeatingInstrument == $row['redcap_repeat_instrument']) && ($instance == $row['redcap_repeat_instance'])) {
                        return $row[$field];
                    }
                } else if ($repeatingInstrument) {
                    if ($repeatingInstrument == $row['redcap_repeat_instrument']) {
                        $values[] = $row[$field];
                    }
                } else if (isset($row[$field]) && self::hasValue($row[$field])) {
                    return $row[$field];
                } else {
	                foreach ($row as $rowField => $rowValue) {
	                    if (preg_match("/___/", $rowField)) {
	                        $a = preg_split("/___/", $rowField);
	                        if (($a[0] == $field) && $rowValue) {
	                            $values[] = $a[1];
                            }
                        }
                    }
                }
            }
        }
	    if (!empty($values)) {
	        if (count($values) == 1) {
	            return $values[0];
            } else {
                return $values;
            }
        }
	    return "";
    }

    public static function getLatestDate($dates) {
        return DateManagement::getLatestDate($dates);
    }

    public static function getParametersAsHiddenInputs($url) {
	    return URLManagement::getParametersAsHiddenInputs($url);
    }

    public static function getParameters($url) {
	    return URLManagement::getParameters($url);
    }

    public static function sanitizeJSON($str) {
	    return Sanitizer::sanitizeJSON($str);
    }

    public static function sanitizeWithoutChangingQuotes($str) {
	    return Sanitizer::sanitizeWithoutChangingQuotes($str);
    }

    public static function sanitizeArray($ary, $stripHTML = TRUE) {
	    return Sanitizer::sanitizeArray($ary, $stripHTML);
    }

    public static function sanitizeWithoutStrippingHTML($str, $encodeQuotes = TRUE) {
	    return Sanitizer::sanitizeWithoutStrippingHTML($str, $encodeQuotes);
    }

    public static function sanitizeCohort($cohortName) {
	    return Sanitizer::sanitizeCohort($cohortName);
    }

    public static function sanitize($origStr) {
	    return Sanitizer::sanitize($origStr);
    }

    public static function getToken($pid, $username = "") {
        $module = Application::getModule();
        if ($username) {
            $sql = "SELECT r.api_token AS api_token FROM redcap_user_rights AS r INNER JOIN redcap_user_information AS i ON (i.username = r.username) WHERE r.project_id = ? AND r.username = ? AND i.user_suspended_time IS NULL AND (i.user_expiration IS NULL OR i.user_expiration > NOW()) AND (r.expiration IS NULL OR r.expiration > NOW())";
            $q = $module->query($sql, [$pid, $username]);
            if ($row = $q->fetch_assoc()) {
                return $row['api_token'] ?? "";
            }
            return "";
        } else {
            $sql = "SELECT r.api_token AS api_token FROM redcap_user_rights AS r INNER JOIN redcap_user_information AS i ON (i.username = r.username) WHERE r.project_id = ? AND i.user_suspended_time IS NULL AND (i.user_expiration IS NULL OR i.user_expiration > NOW()) AND (r.expiration IS NULL OR r.expiration > NOW())";
            $q = $module->query($sql, [$pid]);
            $tokens = [];
            while ($row = $q->fetch_assoc()) {
                if ($row['api_token']) {
                    $tokens[] = $row['api_token'];
                }
            }
            return $tokens;
        }
    }

    public static function autoResetTimeHTML($pid, $fields) {
        $url = APP_PATH_WEBROOT."ProjectGeneral/keep_alive.php?pid=".$pid;
        $minsToDelay = 10;
        $fieldSelector = implode(",", $fields);

        $html = "
    <script>
    $(document).ready(function() {
        setTimeout(function() {
            $('$fieldSelector').bind('keyup mousemove click', function(){
                $(this).unbind('keyup mousemove click');
                $.post('$url', {'redcap_csrf_token': getCSRFToken()}, function(data) {
                });
            });
        }, $minsToDelay * 60000);
    });
    </script>";
        return $html;
    }



    public static function isEmail($str) {
        return filter_var($str, FILTER_VALIDATE_EMAIL);
    }

    public static function isEmailOrEmails($str) {
        $emails = preg_split("/\s*[,;]\s*/", $str);
        foreach ($emails as $email) {
            if (!self::isEmail($email)) {
                return FALSE;
            }
        }
        return TRUE;
    }

    # requestedRecord is from GET/POST
    public static function getSanitizedRecord($requestedRecord, $records) {
	    return Sanitizer::getSanitizedRecord($requestedRecord, $records);
    }

    public static function getPage($url) {
        return URLManagement::getPage($url);
    }

    public static function json_encode_with_spaces($data) {
        $str = json_encode($data);
        $str = Sanitizer::sanitizeJSON($str);
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

    public static function isDMY($str) {
	    return DateManagement::isDMY($str);
    }

    public static function isMDY($str) {
	    return DateManagement::isMDY($str);
    }

    public static function isYMD($str) {
        return DateManagement::isYMD($str);
    }

    public static function isDate($str) {
        return DateManagement::isDate($str);
    }

    public static function convertDollarsToNumber($value) {
        if ((strpos($value, "$") === 0)
            || (substr($value, 0, 2) == "-$")) {
            $value = str_replace("$", "", $value);
            $value = str_replace(",", "", $value);
        }
        return $value;
    }

    public static function convertNumberToDollars($value) {
	    return "$".$value;
    }

    public static function excludeAcronyms($list) {
	    $newList = [];
	    foreach ($list as $item) {
	        if (!preg_match("/^[A-Z\.]+$/", $item) || (strlen($item) > 5)) {
	            $newList[] = $item;
            }
        }
	    return $newList;
    }

    public static function makeSaveDiv($type, $atBottomOfPage = FALSE, $mimeType = "image/png") {
	    $extraDiv = $atBottomOfPage ? "saveDivAtBottom" : "";
	    if ($mimeType == "image/png") {
            $canvasFunc = "canvas2PNG";
            $suffix = "png";
        } else if ($mimeType == "image/jpeg") {
	        $canvasFunc = "canvas2JPEG";
	        $suffix = "jpg";
        } else {
	        throw new \Exception("Mime Type unsupported: $mimeType");
        }
	    if ($type == "svg") {
            $europaWOFFLocation = Application::link("fonts/EuropaNuovaRegular.woff");
            return "<div class='alignright $extraDiv'><button class='smallest' onclick='const svg = $(this).parent().parent().find(\\\"svg\\\").prop(\\\"outerHTML\\\"); svg2Image(svg, 0, \\\"#ffffff\\\", $canvasFunc, \\\"europa\\\", \\\"$europaWOFFLocation\\\"); return false;'>Save</button></div>";
        } else if ($type == "canvas") {
            return "<div class='alignright $extraDiv'><button class='smallest' onclick='const c = $(this).parent().parent().find(\\\"canvas\\\").get(0); const dataurl = $canvasFunc(c); forceDownloadUrl(dataurl, \\\"chart.$suffix\\\"); return false;'>Save</button></div>";
        } else {
	        throw new \Exception("Invalid type: $type");
        }
    }

    public static function isValidSurvey($pid, $hash) {
	    if ($hash) {
            $module = Application::getModule();
            $sql = "select s.project_id AS project_id from redcap_surveys_participants AS p INNER JOIN redcap_surveys AS s ON s.survey_id = p.survey_id WHERE p.hash =? AND s.project_id=?";
            $q = $module->query($sql, [$hash, $pid]);
            return ($q->num_rows > 0);
        }
	    return FALSE;
    }

    public static function cleanupDirectory($dir, $regex) {
	    FileManagement::cleanupDirectory($dir, $regex);
    }

    public static function regexInDirectory($regex, $dir) {
	    return FileManagement::regexInDirectory($regex, $dir);
    }

    public static function deduplicateByKeys($token, $server, $pid, $records, $fields, $prefix, $instrument) {
	    Application::log("Deduplicating $prefix/$instrument by keys: ".json_encode($fields)." for records ".json_encode($records), $pid);
        if (!empty($fields)) {
            $fieldsToDownload = array_unique(array_merge(["record_id"], $fields));
            foreach ($records as $recordId) {
                $redcapData = Download::fieldsForRecords($token, $server, $fieldsToDownload, [$recordId]);
                $values = [];
                $instancesToDelete = [];
                foreach ($redcapData as $row) {
                    if ($row['redcap_repeat_instrument'] == $instrument) {
                        $fieldValues = [];
                        $notBlank = FALSE;
                        foreach ($fields as $field) {
                            $fieldValues[] = $row[$field];
                            if ($row[$field]) {
                                $notBlank = TRUE;
                            }
                        }
                        $encodedFieldValues = implode("|", $fieldValues);
                        if (!in_array($encodedFieldValues, $values)) {
                            if ($notBlank) {
                                $values[] = $encodedFieldValues;
                            }
                        } else {
                            // duplicate => delete
                            $instancesToDelete[] = $row['redcap_repeat_instance'];
                        }
                    }
                }
                if (!empty($instancesToDelete)) {
                    Application::log("Removing instances ".implode(", ", $instancesToDelete)." from record $recordId because duplicates");
                    Upload::deleteFormInstances($token, $server, $pid, $prefix, $recordId, $instancesToDelete);
                }
            }
        }
    }

    public static function deduplicateByKey($token, $server, $pid, $records, $field, $prefix, $instrument) {
	    self::deduplicateByKeys($token, $server, $pid, $records, [$field], $prefix, $instrument);
    }

    public static function getTimestampOfFile($file) {
	    return FileManagement::getTimestampOfFile($file);
    }

    public static function copyTempFileToTimestamp($file, $timespanInSeconds) {
	    return FileManagement::copyTempFileToTimestamp($file, $timespanInSeconds);
    }

    public static function getDesignUseridsForProject($pid) {
        $module = Application::getModule();
	    $sql = "SELECT username FROM redcap_user_rights WHERE project_id = ? AND design = '1'";
	    $q = $module->query($sql, [$pid]);
	    $userids = [];
	    while ($row = $q->fetch_assoc()) {
	        if ($row['username']) {
	            $userids[] = $row['username'];
            }
        }
	    return $userids;
    }

    public static function getUserNames($username) {
        $module = Application::getModule();
        $sql = "select user_firstname, user_lastname from redcap_user_information WHERE username = ?";
        $q = $module->query($sql, [$username]);
        if ($row = $q->fetch_assoc()) {
            return [$row['user_firstname'], $row['user_lastname']];
        }
        return ["", ""];
    }

    public static function fillInLinks($text) {
	    return URLManagement::fillInLinks($text);
    }

    public static function getUseridsFromREDCap($firstName, $lastName) {
	    $lookup = new REDCapLookup($firstName, $lastName);
	    $uidsAndNames = $lookup->getUidsAndNames();
	    return array_keys($uidsAndNames);
    }

    public static function getEmailFromUseridFromREDCap($userid) {
        $module = Application::getModule();
        $sql = "select user_email from redcap_user_information WHERE LOWER(username) = ?";
        $q = $module->query($sql, [$userid]);
        if ($row = $q->fetch_assoc()) {
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

    # returns TRUE if and only if fields in $newMetadata after $priorField are fields in $newRows
    private static function atEndOfMetadata($priorField, $newRows, $newMetadata) {
	    return DataDictionaryManagement::atEndOfMetadata($priorField, $newRows, $newMetadata);
    }

    /**
     * Encode array from latin1 to utf8 recursively
     */
    public static function convert_from_latin1_to_utf8_recursively($dat) {
        if (is_string($dat)) {
            return utf8_encode($dat);
        } elseif (is_array($dat)) {
            $ret = [];
            foreach ($dat as $i => $d) $ret[ $i ] = self::convert_from_latin1_to_utf8_recursively($d);

            return $ret;
        } elseif (is_object($dat)) {
            foreach ($dat as $i => $d) $dat->$i = self::convert_from_latin1_to_utf8_recursively($d);

            return $dat;
        } else {
            return $dat;
        }
    }

	public static function isArrayBlank($ary) {
	    foreach ($ary as $item) {
	        if ($item) {
	            return FALSE;
            }
        }
	    return TRUE;
    }

	public static function isJSON($str) {
        if (json_decode($str)) {
            return TRUE;
        }
	    return FALSE;
    }

    public static function arrayAInB($aryA, $aryB) {
        foreach ($aryA as $key => $val) {
            if (!isset($aryB[$key]) || ($aryB[$key] !== $val)) {
                return FALSE;
            }
        }
        return TRUE;
    }

    public static function copyMetadataSettingsForField($row, $metadata, &$upload, $token, $server) {
        return DataDictionaryManagement::copyMetadataSettingsForField($row, $metadata, $upload, $token, $server);
    }

    public static function isOracleDate($d) {
        return DateManagement::isOracleDate($d);
    }

    # includes _complete's
    public static function getFieldsWithData($rows, $recordId) {
        $fieldsWithData = [];
        $skip = ["redcap_repeat_instrument", "redcap_repeat_instance"];
        foreach ($rows as $row) {
            foreach ($row as $field => $value) {
                if ($value && !in_array($field, $skip) && !in_array($field, $fieldsWithData)) {
                    $fieldsWithData[] = $field;
                }
            }
        }
        return $fieldsWithData;
    }

    public static function oracleDate2YMD($d) {
        return DateManagement::oracleDate2YMD($d);
    }

    public static function YMD2MDY($ymd) {
        return DateManagement::YMD2MDY($ymd);
    }

    public static function prettyMoney($n, $displayCents = TRUE) {
        if ($displayCents) {
            return "\$".self::pretty($n, 2);
        } else {
            return "\$".self::pretty($n, 0);
        }
    }

    public static function allFieldsValid($row, $metadataFields) {
        return DataDictionaryManagement::allFieldsValid($row, $metadataFields);
    }

    public static function getNextRecord($record, $token, $server) {
        $records = Download::recordIds($token, $server);
        $i = 0;
        foreach ($records as $rec) {
            if ($rec == $record) {
                if ($i + 1 < count($records)) {
                    return $records[$i + 1];
                } else {
                    return $records[0];
                }
            }
            $i++;
        }
        return "";
    }

    public static function pretty($n, $numDecimalPlaces = 3, $useCommas = TRUE) {
	    if (!is_numeric($n)) {
	        return $n;
        }
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
            $decPart = floor($decimal * pow(10, $numDecimalPlaces));
            $paddedDecPart = self::padInteger($decPart, $numDecimalPlaces);
            $decimal = ".".$paddedDecPart;
        } else {
            $decimal = "";
        }
        if (!$s) {
            $s = "0";
        }
        if (!$useCommas) {
            $s = str_replace(",", "", (string) $s);
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

    public static function padInteger($num, $numDecimalPlaces) {
        if (!is_numeric($num)) {
            return $num;
        }
        $decimal = (int) $num;
        for ($i = 1; $i < $numDecimalPlaces; $i++){
            $decimalPlaceValue = pow(10, ($numDecimalPlaces - $i));
            if ($decimal < $decimalPlaceValue) {
                $decimal = "0".$decimal;
            }
        }
        return $decimal;
    }

    public static function isArrayNumeric($nodes) {
        foreach ($nodes as $node) {
            if (!is_numeric($node)) {
                return FALSE;
            }
        }
        return TRUE;
    }

    public static function MDY2YMD($mdy) {
        return DateManagement::MDY2YMD($mdy);
    }

    public static function DMY2YMD($dmy) {
        return DateManagement::DMY2YMD($dmy);
    }

    public static function addMonths($date, $months) {
        return DateManagement::addMonths($date, $months);
    }

    public static function addYears($date, $years) {
        return DateManagement::addYears($date, $years);
    }

	public static function getLabels($metadata) {
        return DataDictionaryManagement::getLabels($metadata);
	}

	public static function YMD2MY($ymd) {
        return DateManagement::YMD2MY($ymd);
    }

    public static function stddev($ary) {
	    $stats = new Stats($ary);
	    return $stats->stddev();
    }

    public static function stripMY($str) {
        return DateManagement::stripMY($str);
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

    public static function reverseArray($ary) {
        $reverse = [];
        foreach ($ary as $type => $val) {
            $reverse[$val] = $type;
        }
        return $reverse;
    }

    public static function datetime2Date($datetime) {
        return DateManagement::datetime2Date($datetime);
    }

    public static function prefix2CompleteField($prefix) {
        return DataDictionaryManagement::prefix2CompleteField($prefix);
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
        return DataDictionaryManagement::indexMetadata($metadata);
    }

	public static function hasMetadataChanged($oldValue, $newValue, $metadataField) {
        return DataDictionaryManagement::hasMetadataChanged($oldValue, $newValue, $metadataField);
    }

    public static function makeHiddenInputs($params) {
        return URLManagement::makeHiddenInputs($params);
    }

    public static function splitURL($fullURL) {
        return URLManagement::splitURL($fullURL);
    }

    public static function screenForFields($metadata, $possibleFields) {
        return DataDictionaryManagement::screenForFields($metadata, $possibleFields);
    }

    public static function dedup1DArray($ary) {
	    $newAry = [];
	    foreach ($ary as $item) {
	        if (!in_array($item, $newAry)) {
	            $newAry[] = $item;
            }
        }
	    return $newAry;
    }

    public static function MDY2LongDate($mdy) {
        return DateManagement::MDY2LongDate($mdy);
    }

    public static function YMD2LongDate($ymd) {
        return DateManagement::YMD2LongDate($ymd);
    }

    public static function getFieldsUnderSection($metadata, $sectionHeader) {
        return DataDictionaryManagement::getFieldsUnderSection($metadata, $sectionHeader);
    }

    public static function setupRepeatingForms($eventId, $formsAndLabels) {
        DataDictionaryManagement::setupRepeatingForms($eventId, $formsAndLabels);
	}

	public static function outputAsCSV($csvData, $filename) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="'.$filename.'";');
        $fp = fopen("php://output", "w");
        foreach ($csvData as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);
    }

	public static function getEventIdForClassical($projectId) {
        $module = Application::getModule();
		$sql = "SELECT DISTINCT(m.event_id) AS event_id FROM redcap_events_metadata AS m INNER JOIN redcap_events_arms AS a ON (a.arm_id = m.arm_id) WHERE a.project_id = ?";
		$q = $module->query($sql, [$projectId]);
		if ($row = $q->fetch_assoc()) {
			return $row['event_id'];
		}
		throw new \Exception("The event_id is not defined. (This should never happen.)");
	}

	public static function getExternalModuleId($prefix) {
        $module = Application::getModule();
		$sql = "SELECT external_module_id FROM redcap_external_modules WHERE directory_prefix = ?";
		$q = $module->query($sql, [$prefix]);
		if ($row = $q->fetch_assoc()) {
			return $row['external_module_id'];
		}
		throw new \Exception("The external_module_id is not defined. (This should never happen.)");
	}

	public static function setupSurveys($projectId, $surveysAndLabels) {
        DataDictionaryManagement::setupSurveys($projectId, $surveysAndLabels);
	}

	public static function getPIDFromToken($token, $server) {
		$projectSettings = Download::getProjectSettings($token, $server);
		if (isset($projectSettings['project_id'])) {
			return $projectSettings['project_id'];
		}
        $module = Application::getModule();
        if ($module && self::isValidToken($token)) {
            $sql = "SELECT project_id FROM redcap_user_rights WHERE api_token = ?";
            $result = $module->query($sql, [$token]);
            if ($row = $result->fetch_assoc()) {
                return $row['project_id'];
            }
        }
		throw new \Exception("Could not get project-id from project settings: ".self::json_encode_with_spaces($projectSettings));
	}

	public static function getSpecialFields($type, $metadata) {
        $metadataFields = DataDictionaryManagement::getFieldsFromMetadata($metadata);
		$fields = [];
		$fields["departments"] = [
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
            "init_import_primary_dept",
            "init_import_prev1_primary_dept",
            "init_import_prev2_primary_dept",
            "init_import_prev3_primary_dept",
            "init_import_prev4_primary_dept",
            "init_import_prev5_primary_dept",
        ];
		$fields["resources"] = [
		    "resources_resource",
            ];
        $fields["mentoring"] = [
            DataDictionaryManagement::getMentoringResourceField($metadataFields),
        ];
		$fields["institutions"] = ["check_institution", "init_import_institution", "followup_institution"];
        $fields["optional"] = ["identifier_person_role"];

		if (isset($fields[$type])) {
			return $fields[$type];
		}
		if ($type == "all") {
			$allFields = array();
			foreach ($fields as $type => $typeFields) {
				$allFields = array_merge($allFields, $typeFields);
			}
			$allFields = array_unique($allFields);
            return DataDictionaryManagement::filterOutInvalidFields($metadata, $allFields);
		}
		return array();
	}

    public static function getOptionalFields() {
        return self::getSpecialFields("optional", []);
    }

    public static function getOptionalSettings() {
        $fileMetadata = DataDictionaryManagement::getFileMetadata();
        $fields = self::getOptionalFields();
        $settings = [];
        foreach ($fields as $field) {
            $setting = self::turnOptionalFieldIntoSetting($field);
            if ($setting) {
                $row = DataDictionaryManagement::getRowForFieldFromMetadata($field, $fileMetadata);
                $settings[$setting] = $row['field_label'] ?? "Optional Field";
            }
        }
        return $settings;
    }

    public static function turnOptionalFieldIntoSetting($field) {
        $prefix = self::getPrefix($field);
        if (!preg_match("/_$/", $prefix)) {
            $prefix .= "_";
        }
        return str_replace($prefix, "", $field);
    }

	public static function getFileNameForEdoc($edocId) {
        return FileManagement::getFileNameForEdoc($edocId);
    }

	public static function exactInArray($item, $ary) {
        $item = (string) $item;
	    foreach ($ary as $a) {
            $a = (string) $a;
	        if ($item === $a) {
	            return TRUE;
            }
        }
	    return FALSE;
    }

    /**
     * from https://www.ozzu.com/snippets/608141/convert-curly-quotes-to-regular-quotes-in-php
     * Convert curly/smart quotes to regular quotes
     *
     * This function will convert Windows-1252, CP-1252, and other UTF-8 single and double quotes to regular quotes,
     * otherwise known as Unicode character U+0022 quotion mark (") and U+0027 apostrophe (') which typically do not have
     * any sort of encoding issues that the others run into.
     *
     * @param string $text The text that contains curly quotes
     * @return string Normalized text using regular quotes
     */
    private static function convertCurlyQuotes($text): string
    {
        $quoteMapping = [
            // U+0082⇒U+201A single low-9 quotation mark
            "\xC2\x82"     => "'",

            // U+0084⇒U+201E double low-9 quotation mark
            "\xC2\x84"     => '"',

            // U+008B⇒U+2039 single left-pointing angle quotation mark
            "\xC2\x8B"     => "'",

            // U+0091⇒U+2018 left single quotation mark
            "\xC2\x91"     => "'",

            // U+0092⇒U+2019 right single quotation mark
            "\xC2\x92"     => "'",

            // U+0093⇒U+201C left double quotation mark
            "\xC2\x93"     => '"',

            // U+0094⇒U+201D right double quotation mark
            "\xC2\x94"     => '"',

            // U+009B⇒U+203A single right-pointing angle quotation mark
            "\xC2\x9B"     => "'",

            // U+00AB left-pointing double angle quotation mark
            "\xC2\xAB"     => '"',

            // U+00BB right-pointing double angle quotation mark
            "\xC2\xBB"     => '"',

            // U+2018 left single quotation mark
            "\xE2\x80\x98" => "'",

            // U+2019 right single quotation mark
            "\xE2\x80\x99" => "'",

            // U+201A single low-9 quotation mark
            "\xE2\x80\x9A" => "'",

            // U+201B single high-reversed-9 quotation mark
            "\xE2\x80\x9B" => "'",

            // U+201C left double quotation mark
            "\xE2\x80\x9C" => '"',

            // U+201D right double quotation mark
            "\xE2\x80\x9D" => '"',

            // U+201E double low-9 quotation mark
            "\xE2\x80\x9E" => '"',

            // U+201F double high-reversed-9 quotation mark
            "\xE2\x80\x9F" => '"',

            // U+2039 single left-pointing angle quotation mark
            "\xE2\x80\xB9" => "'",

            // U+203A single right-pointing angle quotation mark
            "\xE2\x80\xBA" => "'",

            // HTML left double quote
            "&ldquo;"      => '"',

            // HTML right double quote
            "&rdquo;"      => '"',

            // HTML left sinqle quote
            "&lsquo;"      => "'",

            // HTML right single quote
            "&rsquo;"      => "'",
        ];

        return strtr(html_entity_decode($text, ENT_QUOTES, "UTF-8"), $quoteMapping);
    }

    public static function clearUnicodeInArray($ary) {
        $newAry = [];
        foreach ($ary as $key => $value) {
            $key = self::clearUnicode($key);
            if (is_array($value)) {
                $value = self::clearUnicodeInArray($value);
            } else {
                $value = self::clearUnicode($value);
            }
            $newAry[$key] = $value;
        }
        return $newAry;
    }

    public static function clearUnicode($str) {
        $str = self::convertCurlyQuotes($str);
        # exclude newline \x0A
        return preg_replace('/[\x00-\x09\x0B-\x1F\x80-\xFF]/', '', $str);
    }

    public static function isMetadataFilled($metadata) {
        return DataDictionaryManagement::isMetadataFilled($metadata);
    }

    public static function getPublicSurveyLink($pid, $firstSurveyInstrument, $tryAgain = TRUE) {
        $module = Application::getModule();
        if (method_exists($module, "getPublicSurveyUrl")) {
            $url = $module->getPublicSurveyUrl($pid) ?? "";
            if ($url) {
                return $url;
            }
        } else {
            $sql = "SELECT p.hash AS hash FROM redcap_surveys_participants AS p INNER JOIN redcap_surveys AS s ON s.survey_id = p.survey_id WHERE s.project_id = ? AND p.participant_email IS NULL ORDER BY p.participant_id LIMIT 1";
            $q = $module->query($sql, [$pid]);
            if ($row = $q->fetch_assoc()) {
                $hash = $row['hash'];
                if ($hash) {
                    return APP_PATH_SURVEY_FULL."?s=$hash";
                }
            }
        }
        if ($tryAgain) {
            $sql = "SELECT survey_id FROM redcap_surveys WHERE form_name = ? AND project_id = ?";
            $params = [$firstSurveyInstrument, $pid];
            $q = $module->query($sql, $params);
            $surveyId = "";
            if ($row = $q->fetch_assoc()) {
                $surveyId = $row['survey_id'];
            }
            if ($surveyId) {
                require_once(APP_PATH_DOCROOT."Classes/Survey.php");
                $eventId = self::getEventIdForClassical($pid);
                $hash = \Survey::setHash($surveyId, NULL, $eventId, NULL, TRUE);
                if ($hash) {
                    return APP_PATH_SURVEY_FULL."?s=$hash";
                }
            }
        }
        return "";
    }

    public static function isValidSupertoken($supertoken) {
        return (strlen($supertoken) == 64);
    }

    public static function userHasAccess($username, $pid) {
        $users = self::getUsersForProject($pid);
        return in_array($username, $users);
    }

    public static function getUsersForProject($pid) {
        require_once(APP_PATH_DOCROOT."Classes/User.php");
        $usersWithNames = \User::getProjectUsernames([], FALSE, $pid);
        return array_keys($usersWithNames);
    }

    public static function isActiveUser($user) {
        $module = Application::getModule();
        if ($module) {
            $sql = "SELECT user_suspended_time, user_expiration FROM redcap_user_information WHERE username = ?";
            $q = $module->query($sql, [$user]);
            $currTs = time();
            if ($row = $q->fetch_assoc()) {
                $suspendTime = $row['user_suspended_time'];
                $expiry = $row['user_expiration'];
                if ($suspendTime) {
                    $ts = strtotime($suspendTime);
                    if ($ts < $currTs) {
                        return FALSE;
                    }
                }
                if ($expiry) {
                    $ts = strtotime($expiry);
                    return ($ts >= $currTs);
                } else {
                    return TRUE;
                }
            }
        }
        return FALSE;
    }

	public static function isValidToken($token) {
        if (!$token) {
            return FALSE;
        }
		return (strlen($token) == 32) && preg_match("/^[\dA-F]{32}$/", $token);
	}

	# checks order
    # uses ===
	public static function arrayOrdersEqual($ary1, $ary2) {
        if (!self::arraysEqual($ary1, $ary2)) {
            return FALSE;
        }
        $keys1 = array_keys($ary1);
        $keys2 = array_keys($ary2);
        for ($i = 0; $i < count($keys1); $i++) {
            $key1 = $keys1[$i] ?? "";
            $key2 = $keys2[$i] ?? "";
            if (($key1 !== $key2) && ($ary1[$key1] !== $ary2[$key2])) {
                return FALSE;
            }
        }
        return TRUE;
    }

    # does NOT check order
    # uses ===
	public static function arraysEqual($ary1, $ary2) {
	    if (!isset($ary1) || !isset($ary2)) {
	        return FALSE;
        }
        if (!is_array($ary1) || !is_array($ary2)) {
            return FALSE;
        }
        if (count($ary1) != count($ary2)) {
            return FALSE;
        }
	    foreach ([[$ary1, $ary2], [$ary2, $ary1]] as $arys) {
            foreach ($arys[0] as $key => $value0) {
                if (!isset($arys[1][$key])) {
                    return FALSE;
                }
                $value1 = $arys[1][$key];
                if ($value0 !== $value1) {
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

    public static function isCompletionField($field) {
        return DataDictionaryManagement::isCompletionField($field);
    }

	public static function isActiveProject($pid) {
        if (!$pid) {
            return FALSE;
        }
        $module = Application::getModule();
		$sql = "SELECT date_deleted, completed_time FROM redcap_projects WHERE project_id = ? LIMIT 1";
		$q = $module->query($sql, [$pid]);
		if ($row = $q->fetch_assoc()) {
			if (!$row['date_deleted'] && !$row['completed_time']) {
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

    public static function datediff($d1, $d2, $unit=null, $returnSigned=false, $returnSigned2=false) {
        return DateManagement::datediff($d1, $d2, $unit, $returnSigned, $returnSigned2);
    }

    public static function getMedian($ary) {
        $stats = new Stats($ary);
        return $stats->median();
    }
}
