<?php

namespace Vanderbilt\CareerDevLibrary;

use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(__DIR__ . '/ClassLoader.php');

class DataDictionaryManagement {
    const DELETION_SUFFIX = "___delete";
    const DEPARTMENT_OTHER_VALUE = "999999";
    const OPTION_OTHER_VALUE = "99";
    const DEGREE_OTHER_VALUE = "99";
    const INSTITUTION_OTHER_VALUE = "5";
    const NONE = "NONE";

    public static function getDeletionRegEx() {
        $suffix = self::DELETION_SUFFIX;
        return "/$suffix$/";
    }

    public static function getFileMetadata() {
        $filename = __DIR__."/../metadata.json";
        if (file_exists($filename)) {
            $json = file_get_contents($filename);
            return json_decode($json, TRUE);
        }
        return [];
    }

    public static function addLists($token, $server, $pid, $lists, $installCoeus = FALSE, $metadata = FALSE) {
        if (trim($lists['departments']) == "") {
            $lists['departments'] = "Department";
        }
        if (trim($lists['resources']) == "") {
            $lists['resources'] = "Resource";
        }
        Application::saveSetting("departments", $lists["departments"], $pid);
        Application::saveSetting("resources", $lists["resources"], $pid);
        if (isset($lists['person_role'])) {
            Application::saveSetting("person_role", $lists["person_role"], $pid);
        }
        if (!Application::getSetting("mentoring_resources", $pid)) {
            Application::saveSetting("mentoring_resources", $lists["resources"], $pid);
        }
        $others = [
            "departments" => self::DEPARTMENT_OTHER_VALUE,
            "resources" => FALSE,
            "mentoring" => FALSE,
            "person_role" => self::OPTION_OTHER_VALUE,
        ];

        $files = Application::getMetadataFiles();
        if (!$metadata) {
            $metadata = Download::metadata($token, $server);
            if (count($metadata) < 5) {
                $metadata = [];
                foreach ($files as $file) {
                    $fp = fopen($file, "r");
                    $json = "";
                    while ($line = fgets($fp)) {
                        $json .= $line;
                    }
                    fclose($fp);
                    $newLines = json_decode($json, true);
                    if ($newLines !== NULL) {
                        $metadata = array_merge($metadata, $newLines);
                    } else {
                        throw new \Exception("Could not unpack json: ".json_last_error_msg()." $json");
                    }
                }
            }
        }

        $fields = array();
        $fields["departments"] = REDCapManagement::getSpecialFields("departments", $metadata);
        $fields["resources"] = REDCapManagement::getSpecialFields("resources", $metadata);
        $fields["mentoring"] = REDCapManagement::getSpecialFields("mentoring", $metadata);
        $fields["optional"] = REDCapManagement::getSpecialFields("optional", $metadata);

        $choices = DataDictionaryManagement::getChoices($metadata);
        $redcapLists = [];
        foreach ($fields as $type => $fieldsToChange) {
            if ($type == "optional") {
                foreach ($fieldsToChange as $field) {
                    $settingName = REDCapManagement::turnOptionalFieldIntoSetting($field);
                    $itemText = $lists[$settingName] ?? "";
                    if ($itemText) {
                        $redcapLists[$settingName] = self::makeREDCapList($itemText, self::OPTION_OTHER_VALUE);
                    }
                }
            } else {
                $str = $lists[$type] ?? "";
                $oldItemChoices = [];
                for ($i = 0; $i < count($fieldsToChange); $i++) {
                    $field = $fieldsToChange[$i];
                    $oldItemChoices = $choices[$field];
                    if (!empty($oldItemChoices)) {
                        break;
                    }
                }
                $other = $others[$type];
                $redcapLists[$type] = self::makeREDCapList($str, $other, $oldItemChoices);
            }
        }

        $choiceFieldTypes = ["dropdown", "radio"];
        $institutionFields = REDCapManagement::getSpecialFields("institutions", $metadata);
        $newMetadata = [];
        foreach ($metadata as $row) {
            $isCoeusRow = self::isCoeusMetadataRow($row);
            if ((($installCoeus && $isCoeusRow) || !$isCoeusRow) && !preg_match("/___delete/", $row['field_name'])) {
                foreach ($fields as $type => $relevantFields) {
                    if (in_array($row['field_name'], $relevantFields) && isset($lists[$type]) && $redcapLists[$type]) {
                        if ($type == "optional") {
                            $settingName = REDCapManagement::turnOptionalFieldIntoSetting($row['field_name']);
                            if (isset($redcapLists[$settingName])) {
                                $row['select_choices_or_calculations'] = $redcapLists[$settingName];
                            }
                        } else {
                            $row['select_choices_or_calculations'] = $redcapLists[$type];
                        }
                        break;
                    }
                }
                if (
                    !in_array($row['field_type'], $choiceFieldTypes)
                    || ($row['select_choices_or_calculations'] !== "")
                    || in_array($row['field_name'], $institutionFields)
                ) {
                    $newMetadata[] = $row;
                }
            }
        }
        self::alterInstitutionFields($newMetadata, $pid);

        return Upload::metadata($newMetadata, $token, $server);
    }

    private static function getCoeusForms() {
        return ["coeus", "coeus2", "coeus_submission"];
    }

    private static function isCoeusMetadataRow($row) {
        $forms = self::getCoeusForms();
        foreach ($forms as $form) {
            $prefix = REDCapManagement::getPrefixFromInstrument($form);
            if (!preg_match("/_$/", $prefix)) {
                $prefix .= "_";
            }
            if (preg_match("/^$prefix/", $row['field_name'])) {
                return TRUE;
            }
        }
        return FALSE;
    }

    public static function makeREDCapList($text, $otherItem = FALSE, $oldItemChoices = []) {
        if (!$text) {
            return "";
        }
        $list = explode("\n", $text);
        $newList = array();
        $i = 0;
        $oldChoicesReversed = [];
        foreach ($oldItemChoices as $index => $label) {
            $oldChoicesReversed[$label] = $index;
        }
        $seenIndices = [];

        # preserve old indices
        foreach ($list as $item) {
            $item = trim($item);
            if ($item) {
                if (isset($oldChoicesReversed[$item])) {
                    $index = $oldChoicesReversed[$item];
                    $newList[] = $index.",".$item;
                    $seenIndices[] = $index;
                } else {
                    do {
                        $i++;
                    } while (isset($oldItemChoices[$i]) || ($i == $otherItem));
                    $newList[] = $i.",".$item;
                    $seenIndices[] = $i;
                    $i++;
                }
            }
        }
        if ($otherItem && !in_array($otherItem, $seenIndices)) {
            $newList[] = $otherItem.",Other";
        }
        if (empty($newList)) {
            $newList[] = self::DEPARTMENT_OTHER_VALUE.",No Resource";
        }
        return implode("|", $newList);
    }

    public static function removePrefix($fields, $prefix) {
        if (!preg_match("/_$/", $prefix)) {
            $prefix .= "_";
        }

        $suffixesToSkip = ["_complete", "_include"];
        $newFields = [];
        foreach ($fields as $field) {
            $continue = TRUE;
            foreach ($suffixesToSkip as $suffixToSkip) {
                if (preg_match("/$suffixToSkip$/", $field)) {
                    $continue = FALSE;
                    break;
                }
            }
            if ($continue && preg_match("/^$prefix/", $field)) {
                $newFields[] = preg_replace("/^$prefix/", "", $field);
            }
        }
        return $newFields;
    }

    public static function getDateFields($metadata) {
        $fields = [];
        foreach ($metadata as $row) {
            if (preg_match("/^(date|time)/", $row['text_validation_type_or_show_slider_number'])) {
                $fields[] = $row['field_name'];
            }
        }
        return $fields;
    }

    public static function installMetadataForPids($pids, $files, $deletionRegEx) {
        $returnData = [];
        foreach ($pids as $currPid) {
            $pidToken = Application::getSetting("token", $currPid);
            $pidServer = Application::getSetting("server", $currPid);
            $switches = new FeatureSwitches($pidToken, $pidServer, $currPid);
            $pidEventId = Application::getSetting("event_id", $currPid);
            if ($pidToken && $pidServer && $pidEventId) {
                Application::log("Installing metadata", $currPid);
                $returnData[$currPid] = DataDictionaryManagement::installMetadataFromFiles($files, $pidToken, $pidServer, $currPid, $pidEventId, CareerDev::getRelevantChoices(), $deletionRegEx, $switches->getFormsToExclude());
            }
        }
        return $returnData;
    }

    private static function isNewMetadataMergeLive() {
        return REDCapManagement::versionGreaterThanOrEqualTo(Application::getVersion(), "5.12.2");
    }

    public static function installMetadataFromFiles($files, $token, $server, $pid, $eventId, $newSourceChoices, $deletionRegEx, $excludeForms) {
        $metadata = [];
        $metadata['REDCap'] = Download::metadata($token, $server);
        $metadata['REDCap'] = self::filterOutForms($metadata['REDCap'], $excludeForms);

        if (!self::isNewMetadataMergeLive()) {
            if (isset($_POST['fields'])) {
                $postedFields = Sanitizer::sanitizeArray($_POST['fields']);
            } else {
                list ($missing, $additions, $changed) = self::findChangedFieldsInMetadata($metadata['REDCap'], $files, $deletionRegEx, $newSourceChoices, $excludeForms, $pid);
                $postedFields = $missing;
            }
            if (empty($postedFields)) {
                return ["error" => "Nothing to update"];
            }
        } else {
            $postedFields = [];
        }

        $metadata['file'] = [];
        foreach ($files as $filename) {
            $fp = fopen($filename, "r");
            $json = "";
            while ($line = fgets($fp)) {
                $json .= $line;
            }
            fclose($fp);

            $fileData = json_decode($json, TRUE);
            if ($fileData) {
                $fileData = self::filterOutForms($fileData, $excludeForms);
                $metadata['file'] = array_merge($metadata['file'], $fileData);
            } else {
                throw new \Exception("Could not decode data in file $filename!");
            }
        }
        if (!empty($metadata['file'])) {
            try {
                $mentorLabel = "Primary mentor during your training period";
                $fieldLabels = [];
                foreach ($metadata as $type => $md) {
                    $fieldLabels[$type] = self::getLabels($md);
                }
                $fieldsForMentorLabel = ["check_primary_mentor", "followup_primary_mentor",];
                foreach ($fieldsForMentorLabel as $field) {
                    $metadata['file'] = self::changeFieldLabel($field, $mentorLabel, $metadata['file']);
                    $fileValue = $fieldLabels['file'][$field] ?? "";
                    $redcapValue = $fieldLabels['REDCap'][$field] ?? "";
                    if (($fileValue != $redcapValue) && !self::isNewMetadataMergeLive()) {
                        $postedFields[] = $field;
                    }
                }

                $redcapChoices = self::getChoices($metadata["REDCap"]);
                $newSourceChoiceStr = self::makeChoiceStr($newSourceChoices);
                for ($i = 0; $i < count($metadata['file']); $i++) {
                    $field = $metadata['file'][$i]['field_name'];
                    $isFieldOfSources = (
                        (
                            preg_match("/_source$/", $field)
                            || preg_match("/_source_\d+$/", $field)
                        )
                        && (
                            isset($redcapChoices[$field]["scholars"])
                            || isset($redcapChoices[$field]["nih_reporter"])
                        )
                        && ($field !== "citation_source")
                    );
                    if ($isFieldOfSources) {
                        $metadata['file'][$i]['select_choices_or_calculations'] = $newSourceChoiceStr;
                    }
                }

                if (self::isNewMetadataMergeLive()) {
                    $feedback = self::mergeMetadataAndUploadNew($metadata['REDCap'], $metadata['file'], $token, $server, $pid, $deletionRegEx);
                } else {
                    $metadata["REDCap"] = self::reverseMetadataOrder("initial_import", "init_import_ecommons_id", $metadata["REDCap"] ?? []);
                    $feedback = self::mergeMetadataAndUploadOld($metadata['REDCap'], $metadata['file'], $token, $server, $postedFields, $deletionRegEx);
                }
                $newMetadata = Download::metadata($token, $server);
                $formsAndLabels = self::getRepeatingFormsAndLabels($newMetadata, $token);
                $surveysAndLabels = self::getSurveysAndLabels($newMetadata);
                self::setupRepeatingForms($eventId, $formsAndLabels);   // runs an UPDATE and/or INSERT
                self::setupSurveys($pid, $surveysAndLabels);
                self::convertOldDegreeData($pid);
                return $feedback;
            } catch (\Exception $e) {
                $feedback = ["Exception" => $e->getMessage()];
                $version = Application::getVersion();
                $adminEmail = Application::getSetting("admin_email", $pid);
                $mssg = "<h1>Metadata Upload Error in " . Application::getProgramName() . "</h1>";
                $mssg .= "<p>Server: $server</p>";
                $mssg .= "<p>PID: $pid</p>";
                $mssg .= "<p>Flight Tracker Version: $version</p>";
                $mssg .= "<p>Admin Email: <a href='mailto:$adminEmail'>$adminEmail</a></p>";
                $mssg .= $e->getMessage();
                \REDCap::email("scott.j.pearson@vumc.org", "noreply.flighttracker@vumc.org", Application::getProgramName() . " Metadata Upload Error", $mssg);
                return $feedback;
            }
        }
        return [];
    }

    public static function filterFieldsForPrefix($metadataFields, $prefix) {
        $filteredFields = [];
        foreach ($metadataFields as $field) {
            if (preg_match("/^$prefix/", $field)) {
                $filteredFields[] = $field;
            }
        }
        return $filteredFields;
    }

    public static function setupSurveys($projectId, $surveysAndLabels, $surveyCompletionText = "DEFAULT") {
        $surveyIntroText = '<p><strong>Please complete the survey below.</strong></p><p>Thank you!</p>';
        if ($surveyCompletionText == "DEFAULT") {
            $surveyCompletionText = '<p><strong>Thank you for taking the survey.</strong></p><p>Have a nice day!</p>';
        }
        $module = Application::getModule();
        $sql = "SELECT form_name FROM redcap_surveys WHERE project_id = ?";
        $result = $module->query($sql, [$projectId]);
        $forms = [];
        while ($row = $result->fetch_assoc()) {
            $forms[] = $row['form_name'];
        }
        foreach ($surveysAndLabels as $form => $label) {
            if (in_array($form, $forms)) {
                $sql = "UPDATE redcap_surveys SET title = ?, acknowledgement = ? WHERE project_id = ? AND form_name = ?";
                $module->query($sql, [$label, $surveyCompletionText, $projectId, $form]);
            } else {
                $sql = "INSERT INTO redcap_surveys (project_id, font_family, form_name, title, instructions, acknowledgement, question_by_section, question_auto_numbering, survey_enabled, save_and_return, logo, hide_title, view_results, min_responses_view_results, check_diversity_view_results, end_survey_redirect_url, survey_expiration) VALUES (?, '16', ?, ?, ?, ?, 0, 1, 1, 1, NULL, 0, 0, 10, 0, NULL, NULL)";
                $module->query($sql, [$projectId, $form, $label, $surveyIntroText, $surveyCompletionText]);
            }
        }
    }

    public static function filterOutForms($metadata, $formsToExclude) {
        $newMetadata = [];
        foreach ($metadata as $metadataRow) {
            $formName = $metadataRow['form_name'];
            if (!in_array($formName, $formsToExclude)) {
                $newMetadata[] = $metadataRow;
            }
        }
        return $newMetadata;
    }

    public static function getLabels($metadata) {
        return self::getFieldValues($metadata, "field_label");
    }

    public static function getFieldValues($metadata, $metadataField) {
        $values = [];
        foreach ($metadata as $row) {
            $values[$row['field_name']] = $row[$metadataField];
        }
        return $values;
    }

    private static function convertOldDegreeData($pid) {
        $fields = [
            "check_degree0",
            "check_degree1",
            "check_degree2",
            "check_degree3",
            "check_degree4",
            "check_degree5",
            "followup_degree0",
            "followup_degree",
            "init_import_degree0",
            "init_import_degree1",
            "init_import_degree2",
            "init_import_degree3",
            "init_import_degree4",
            "init_import_degree5",
            "imported_degree",
        ];
        $convert =[
            1 => "md",
            2 => "phd",
            18 => "mdphd",
            3 => "mph",
            4 => "msci",
            5 => "ms",
            11 => "mhs",
            13 => "pharmd",
            15 => "psyd",
            17 => "rn",
            19 => "bs",
            6 => self::DEGREE_OTHER_VALUE,
        ];

        $module = Application::getModule();
        $questionMarks = [];
        while (count($fields) > count($questionMarks)) {
            $questionMarks[] = "?";
        }
        foreach ($convert as $oldValue => $newValue) {
            $params = array_merge([$newValue, $pid, $oldValue], $fields);
            $sql = "UPDATE redcap_data SET value=? WHERE project_id=? AND value=? AND field_name IN (".implode(",", $questionMarks).")";
            Application::log("Running SQL $sql with ".json_encode($params));
            $module->query($sql, $params);
        }
    }

    public static function reverseMetadataOrder($instrument, $desiredFirstField, $metadata) {
        $startI = 0;
        $endI = count($metadata) - 1;
        $started = FALSE;
        $instrumentRows = [];
        for ($i = 0; $i < count($metadata); $i++) {
            if (($metadata[$i]['form_name'] == $instrument) && ($startI === 0)) {
                $startI = $i;
                $started = TRUE;
            }
            if ($started && ($metadata[$i]['form_name'] == $instrument)) {
                $endI = $i;
            }
            if ($metadata[$i]['form_name'] == $instrument) {
                $instrumentRows[] = $metadata[$i];
            }
        }
        if (empty($instrumentRows)) {
            return $metadata;
        }
        if ($metadata[$startI]['field_name'] != $desiredFirstField) {
            $instrumentRows = array_reverse($instrumentRows);
            for ($i = $startI; $i <= $endI; $i++) {
                $metadata[$i] = $instrumentRows[$i - $startI];
            }
        }
        return $metadata;
    }

    public static function setupRepeatingForms($eventId, $formsAndLabels) {
        $module = Application::getModule();
        $sql = "SELECT form_name FROM redcap_events_repeat WHERE event_id = ?";
        $result = $module->query($sql, [$eventId]);
        $repeatingForms = [];
        while ($row = $result->fetch_assoc()) {
            $repeatingForms[] = $row['form_name'];
        }

        $sqlEntries = [];
        $insertValues = [];
        foreach ($formsAndLabels as $form => $label) {
            if (!in_array($form, $repeatingForms)) {
                $insertValues[] = $eventId;
                $insertValues[] = $form;
                $insertValues[] = $label;
                $sqlEntries[] = "(?, ?, ?)";
            }
        }
        if (!empty($sqlEntries)) {
            $sql = "INSERT INTO redcap_events_repeat (event_id, form_name, custom_repeat_form_label) VALUES".implode(",", $sqlEntries);
            $module->query($sql, $insertValues);
        }
    }

    public static function getChoicesForField($pid, $field) {
        $module = Application::getModule();
        $sql = "SELECT element_enum FROM redcap_metadata WHERE project_id = ? AND field_name = ?";
        $q = $module->query($sql, [$pid, $field]);
        if ($row = $q->fetch_assoc()) {
            return self::getRowChoices($row['element_enum'], TRUE);
        }
        return [];
    }

    public static function findChangedFieldsInMetadata($projectMetadata, $files, $deletionRegEx, $sourceChoices, $formsToExclude, $pid) {
        $missing = [];
        $additions = [];
        $changed = [];
        $metadata = [];
        $metadata['REDCap'] = $projectMetadata;
        $genderFieldsToHandleForVanderbilt = ["summary_gender", "summary_gender_source", "check_gender"];
        foreach ($files as $filename) {
            $fp = fopen($filename, "r");
            $json = "";
            while ($line = fgets($fp)) {
                $json .= $line;
            }
            fclose($fp);

            $metadata['file'] = json_decode($json, TRUE);
            $metadata['file'] = self::filterOutForms($metadata['file'], $formsToExclude);
            if (Application::isLocalhost()) {
                $metadata['file'] = self::filterOutForms($metadata['file'], self::getCoeusForms());
            }

            $choices = [];
            foreach ($metadata as $type => $md) {
                $choices[$type] = REDCapManagement::getChoices($md);
            }

            if (!Application::isVanderbilt()) {
                self::insertDeletesForPrefix($metadata['file'], "/^coeus_/");
            }

            $fieldList = [];
            $indexedMetadata = [];
            foreach ($metadata as $type => $metadataRows) {
                $fieldList[$type] = [];
                $indexedMetadata[$type] = [];
                foreach ($metadataRows as $row) {
                    $fieldList[$type][$row['field_name']] = $row['select_choices_or_calculations'];
                    $indexedMetadata[$type][$row['field_name']] = $row;
                }
            }

            $metadataFields = REDCapManagement::getMetadataFieldsToScreen();
            $specialFields = REDCapManagement::getSpecialFields("all", $projectMetadata);
            $optionalFields = REDCapManagement::getSpecialFields("optional", $projectMetadata);
            foreach ($fieldList["file"] as $field => $choiceStr) {
                $isSpecialGenderField = Application::isVanderbilt() && in_array($field, $genderFieldsToHandleForVanderbilt);
                $isFieldOfSources = (
                    (
                        preg_match("/_source$/", $field)
                        || preg_match("/_source_\d+$/", $field)
                    )
                    && isset($choices["REDCap"][$field]["scholars"])
                    && ($field !== "citation_source")
                );
                if (in_array($field, $optionalFields)) {
                    $settingName = REDCapManagement::turnOptionalFieldIntoSetting($field);
                    $optionChoicesAsText = Application::getSetting($settingName, $pid);
                    if (!$optionChoicesAsText) {
                        if (isset($fieldList["REDCap"][$field])) {
                            $missing[] = $field.self::DELETION_SUFFIX;
                            $changed[] = $field." [will be deleted]";
                        }
                    } else {
                        if (isset($fieldList["REDCap"][$field])) {
                            $optionChoiceStr = self::makeREDCapList($optionChoicesAsText, self::OPTION_OTHER_VALUE);
                            if ($fieldList["REDCap"][$field] != $optionChoiceStr) {
                                $changed[] = $field." [updated options]";
                            }
                        } else {
                            $missing[] = $field;
                            $additions[] = $field;
                        }
                    }
                } else if (!in_array($field, $specialFields)) {
                    if (!isset($fieldList["REDCap"][$field])) {
                        $missing[] = $field;
                        if (!preg_match($deletionRegEx, $field)) {
                            $additions[] = $field;
                        }
                    } else if ($isFieldOfSources) {
                        $choices["file"][$field] = $sourceChoices;
                        if (
                            !Application::isVanderbilt()
                            && isset($choices["REDCap"][$field]["coeus"])
                        ) {
                            $missing[] = $field;
                            $changed[] = $field." [removing Vanderbilt choices]";
                        } else if (
                            !REDCapManagement::arraysEqual($choices["REDCap"][$field], $sourceChoices)
                            && !REDCapManagement::arrayAInB($sourceChoices, $choices["REDCap"][$field])
                        ) {
                            $missing[] = $field;
                            $changed[] = $field." [source choices not equal]";
                        }
                    } else if (
                        !empty($choices["file"][$field])
                        && !empty($choices["REDCap"][$field])
                        && !REDCapManagement::arraysEqual($choices["file"][$field], $choices["REDCap"][$field])
                    ) {
                        if (!$isSpecialGenderField) {
                            $missing[] = $field;
                            $changed[] = $field." [choices not equal]";
                        }
                    } else {
                        foreach ($metadataFields as $metadataField) {
                            if (self::hasMetadataChanged($indexedMetadata["REDCap"][$field][$metadataField], $indexedMetadata["file"][$field][$metadataField], $metadataField)) {
                                $missing[] = $field;
                                $changed[] = $field." [$metadataField changed]";
                                break; // metadataFields loop
                            }
                        }
                    }
                }
            }
        }
        return [$missing, $additions, $changed];
    }

    private static function changeFieldLabel($field, $label, $metadata) {
        $i = 0;
        foreach ($metadata as $row) {
            if ($row['field_name'] == $field) {
                $metadata[$i]['field_label'] = $label;
            }
            $i++;
        }
        return $metadata;
    }

    private static function insertDeletesForPrefix(&$metadata, $regExToTurnIntoDeletes) {
        for ($i = 0; $i < count($metadata); $i++) {
            if (preg_match($regExToTurnIntoDeletes, $metadata[$i]['field_name'])) {
                $metadata[$i]['field_name'] .= "___delete";
            }
        }
    }

    public static function getSurveysAndLabels($metadata) {
        $surveysAndLabelsCandidates = [
            "initial_survey" => "Flight Tracker Initial Survey",
            "followup" => "Flight Tracker Followup Survey",
            "mentoring_agreement_evaluations" => "Mentoring Agreement Evaluations",
        ];
        $forms = self::getFormsFromMetadata($metadata);

        $surveysAndLabels = [];
        foreach ($surveysAndLabelsCandidates as $form => $label) {
            if (in_array($form, $forms)) {
                $surveysAndLabels[$form] = $label;
            }
        }
        return $surveysAndLabels;
    }

    private static function getMetadata($token = FALSE) {
        if (!$token) {
            global $token, $server;
        } else {
            $server = FALSE;
        }
        $pid = Application::getPid($token);
        if ($pid) {
            $token = $token ?: Application::getSetting("token", $pid);
            $server = $server ?: Application::getSetting("server", $pid);
            if ($token && $server) {
                return Download::metadata($token, $server);
            }
        }
        return [];
    }

    public static function getGrantTitleFields($metadata = []) {
        $possibleFields = [
            "coeus_title",
            "custom_title",
            "reporter_title",
            "exporter_project_title",
            "coeus2_title",
            "nih_project_title",
        ];

        if (empty($metadata)) {
            $metadata = self::getMetadata();
        }
        return self::screenForFields($metadata, $possibleFields);
    }

    public static function getRepeatingFormsAndLabels($metadata = [], $token = "") {
        $formsAndLabels = [
            "custom_grant" => "[custom_number]",
            "followup" => "",
            "position_change" => "",
            "reporter" => "[reporter_projectnumber]",
            "exporter" => "[exporter_full_project_num]",
            "citation" => "[citation_pmid] [citation_title]",
            "resources" => "[resources_resource]: [resources_date]",
            "honors_and_awards" => "[honor_name]: [honor_date]",
            "manual_degree" => "[imported_degree]",
            "nih_reporter" => "[nih_project_num]",
        ];

        if (empty($metadata)) {
            $metadata = self::getMetadata($token);
        }
        if (count(Application::getPatentFields($metadata)) > 1) {
            $formsAndLabels["patent"] = "[patent_number]: [patent_title]";
        }
        $forms = self::getFormsFromMetadata($metadata);
        if (in_array("mentoring_agreement", $forms)) {
            $formsAndLabels["mentoring_agreement"] = "[mentoring_userid]: [mentoring_phase]";
        }
        if (in_array("mentoring_agreement_evaluations", $forms)) {
            $formsAndLabels["mentoring_agreement_evaluations"] = "[mentoringeval_role]";
        }
        if (in_array("nsf", $forms)) {
            $formsAndLabels["nsf"] = "[nsf_id]";
        }
        if (in_array("ies_grant", $forms)) {
            $formsAndLabels["ies_grant"] = "[ies_awardnum]";
        }
        if (in_array("eric", $forms)) {
            $formsAndLabels["eric"] = "[eric_id]";
        }

        if (Application::isVanderbilt()) {
            $formsAndLabels["ldap"] = "[ldap_vanderbiltpersonjobname]";
            $formsAndLabels["ldapds"] = "[ldapds_cn]";
            $formsAndLabels["coeus2"] = "[coeus2_award_status]: [coeus2_agency_grant_number]";
            $formsAndLabels["coeus"] = "[coeus_sponsor_award_number]";
            $formsAndLabels["coeus_submission"] = "[coeussubmission_proposal_status]: [coeussubmission_sponsor_proposal_number]";
            $formsAndLabels["vera"] = "[vera_award_id]: [vera_direct_sponsor_award_id] ([vera_project_role])";
            $formsAndLabels["vera_submission"] = "[verasubmission_fp_id]";
        }

        return $formsAndLabels;
    }

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

    public static function filterOutInvalidFieldsFromFieldlist($metadataFields, $fields) {
        $newFields = [];
        foreach ($fields as $field) {
            if (in_array($field, $metadataFields)) {
                $newFields[] = $field;
            }
        }
        return $newFields;
    }

    public static function filterOutInvalidFields($metadata, $fields) {
        if (empty($metadata)) {
            global $token, $server;
            $metadataFields = Download::metadataFields($token, $server);
            $metadataForms = Download::metadataForms($token, $server);
        } else {
            $metadataFields = self::getFieldsFromMetadata($metadata);
            $metadataForms = self::getFormsFromMetadata($metadata);
        }
        $newFields = [];
        foreach ($fields as $field) {
            if (in_array($field, $metadataFields)) {
                $newFields[] = $field;
            } else {
                foreach ($metadataForms as $form) {
                    if ($field == $form."_complete") {
                        $newFields[] = $field;
                        break;
                    }
                }
            }
        }
        return $newFields;
    }

    public static function getFormsFromMetadata($metadata) {
        $forms = [];
        foreach ($metadata as $row) {
            $formName = $row['form_name'];
            if (!in_array($formName, $forms)) {
                $forms[] = $formName;
            }
        }
        return $forms;
    }

    public static function getRowForFieldFromMetadata($field, $metadata) {
        foreach ($metadata as $row) {
            if ($row['field_name'] == $field) {
                return $row;
            }
        }
        return [];
    }

    public static function getRowsForFieldsFromMetadata($fields, $metadata) {
        $selectedRows = array();
        foreach ($metadata as $row) {
            if (in_array($row['field_name'], $fields)) {
                $selectedRows[] = $row;
            }
        }
        return $selectedRows;
    }

    public static function getFieldsFromMetadata($metadata, $instrument = FALSE) {
        $fields = array();
        foreach ($metadata as $row) {
            if ($instrument) {
                if ($instrument == $row['form_name']) {
                    $fields[] = $row['field_name'];
                }
            } else {
                $fields[] = $row['field_name'];
            }
        }
        if ($instrument) {
            $completeField = $instrument."_complete";
            if (!in_array($completeField, $fields)) {
                $fields[] = $completeField;
            }
        }
        return $fields;
    }

    public static function getFieldsWithRegEx($metadata, $re, $removeRegex = FALSE) {
        $fields = [];
        foreach ($metadata as $row) {
            if (preg_match($re, $row['field_name'])) {
                if ($removeRegex) {
                    $field = preg_replace($re, "", $row['field_name']);
                } else {
                    $field = $row['field_name'];
                }
                $fields[] = $field;
            }
        }
        return $fields;
    }

    public static function getFieldsOfType($metadata, $fieldType, $validationType = "") {
        $fields = array();
        foreach ($metadata as $row) {
            if ($row['field_type'] == $fieldType) {
                if (!$validationType || ($validationType == $row['text_validation_type_or_show_slider_number'])) {
                    $fields[] = $row['field_name'];
                }
            }
        }
        return $fields;
    }

    public static function getMetadataFieldsToScreen() {
        return ["required_field", "form_name", "identifier", "branching_logic", "section_header", "field_annotation", "text_validation_type_or_show_slider_number", "select_choices_or_calculations"];
    }

    # returns TRUE if and only if fields in $newMetadata after $priorField are fields in $newRows
    public static function atEndOfMetadata($priorField, $newRows, $newMetadata) {
        $newFields = [];
        foreach ($newRows as $row) {
            $newFields[] = $row['field_name'];
        }

        $found = FALSE;
        foreach ($newMetadata as $row) {
            if ($found) {
                if (!in_array($row['field_name'], $newFields)) {
                    return FALSE;
                }
            } else if ($priorField == $row['field_name']) {
                $found = TRUE;
            }
        }
        return TRUE;
    }

    private static function getDeletedFields($metadata, $deletionRegEx) {
        $fields = [];
        foreach ($metadata as $row) {
            if (preg_match($deletionRegEx, $row['field_name'])) {
                $fields[] = preg_replace($deletionRegEx, "", $row['field_name']);
            }
        }
        return $fields;
    }

    # fileMetadata as baseline
    public static function mergeMetadataAndUploadNew($originalMetadata, $fileMetadata, $token, $server, $pid, $deletionRegEx = "/___delete$/") {
        $fieldsToDelete = self::getDeletedFields($fileMetadata, $deletionRegEx);
        $originalFormsAndFields = self::getFormsAndFields($originalMetadata, $deletionRegEx, $fieldsToDelete);
        $fileFormsAndFields = self::getFormsAndFields($fileMetadata, $deletionRegEx, $fieldsToDelete);
        $switches = new FeatureSwitches($token, $server, $pid);
        $formsToSkip = $switches->getFormsToExclude();
        $indexedFileMetadata = self::indexMetadata($fileMetadata);
        $indexedOriginalMetadata = self::indexMetadata($originalMetadata);

        $mergedMetadata = [];
        foreach ($fileFormsAndFields as $form => $fileFields) {
            if (in_array($form, $formsToSkip)) {
                continue;
            } else if (
                !isset($originalFormsAndFields[$form])
                || REDCapManagement::arraysEqual($fileFields, $originalFormsAndFields[$form])
            ) {
                self::addFields($mergedMetadata, $fileMetadata, $fileFields);
            } else {
                $originalFields = $originalFormsAndFields[$form];
                $mergedOrderForForm = self::mergeFields($fileFields, $originalFields);
                foreach ($mergedOrderForForm as $field) {
                    $fileForm = $indexedFileMetadata[$field]["form_name"] ?? self::NONE;

                    # get rid of duplicated fields in renamed forms
                    if (($fileForm == self::NONE) || ($fileForm == $form)) {
                        if (in_array($field, $fileFields)) {
                            $row = $indexedFileMetadata[$field];
                        } else if (in_array($field, $originalFields)) {
                            $row = $indexedOriginalMetadata[$field];
                        } else {
                            throw new \Exception("Field mismatch ($field): This should never happen!");
                        }
                        $mergedMetadata[] = $row;
                    }
                }
            }
        }
        $mergedFieldsBeforeOriginal = self::getFieldsFromMetadata($mergedMetadata);
        foreach ($originalFormsAndFields as $form => $originalFields) {
            if (in_array($form, $formsToSkip)) {
                continue;
            } else if (!isset($fileFormsAndFields[$form])) {
                $fieldsToAdd = [];
                foreach ($originalFields as $field) {
                    if (!in_array($field, $mergedFieldsBeforeOriginal)) {
                        $fieldsToAdd[] = $field;
                    }
                }
                if (!empty($fieldsToAdd)) {
                    self::addFields($mergedMetadata, $originalMetadata, $fieldsToAdd);
                }
            }
        }

        self::preserveSectionHeaders($mergedMetadata, $originalMetadata);
        self::alterOptionalFields($mergedMetadata, $pid);
        self::alterResourcesFields($mergedMetadata, $pid);
        self::alterInstitutionFields($mergedMetadata, $pid);
        self::alterDepartmentsFields($mergedMetadata, $pid);
        return Upload::metadata($mergedMetadata, $token, $server);
    }

    private static function preserveSectionHeaders(&$currentMetadata, $oldMetadata) {
        $indexedOldMetadata = self::indexMetadata($oldMetadata);
        foreach ($currentMetadata as $i => $row) {
            if (
                ($row['section_header'] != "")
                && ($row['section_header'] != $indexedOldMetadata[$row['field_name']]['section_header'])
                && ($indexedOldMetadata[$row['field_name']]['section_header'] !== "")
            ) {
                $currentMetadata[$i]["section_header"] = $indexedOldMetadata[$row['field_name']]['section_header'];
            }
        }
    }

    # in case of major havoc, uses order of baseline and adds new fields at end
    private static function mergeFields($baselineFields, $newFields) {
        $fields = [];

        $baselineIndex = 0;
        $newIndex = 0;
        while ($baselineIndex < count($baselineFields)) {
            $baselineField = $baselineFields[$baselineIndex];
            if ($baselineField == $newFields[$newIndex]) {
                # in sync (normal case) --> advance both pointers
                $newIndex++;
                $fields[] = $baselineField;
                $baselineIndex++;
            } else if (
                in_array($baselineField, $newFields)
                && !in_array($newFields[$newIndex], $baselineFields)
            ) {
                # newFields[newIndex] is not in baselineFields --> catch up then insert
                while (
                    ($newFields[$newIndex] != $baselineField)
                    && !in_array($newFields[$newIndex], $baselineFields)
                    && ($newIndex < count($newFields))
                ) {
                    $fields[] = $newFields[$newIndex];
                    $newIndex++;
                }
                $fields[] = $baselineField;
                $baselineIndex++;
            } else if (
                !in_array($baselineField, $newFields)
                && in_array($newFields[$newIndex], $baselineFields)
            ) {
                # Deleted from newFields --> insert but do not advance newFields
                $fields[] = $baselineField;
                $baselineIndex++;
            } else if (
                in_array($baselineField, $newFields)
                && in_array($newFields[$newIndex], $baselineFields)
            ) {
                # reshuffled --> restore file order
                $fields[] = $baselineField;
                $baselineIndex++;
                # newIndex will be skipped ahead later
            } else if (
                !in_array($baselineField, $newFields)
                && !in_array($newFields[$newIndex], $baselineFields)
            ) {
                # both excluded from other --> add baseline and then new fields until a baseline field is found again
                # possibly a renamed field
                $fields[] = $baselineField;
                $baselineIndex++;
                while (
                    !in_array($newFields[$newIndex], $baselineFields)
                    && ($newIndex < count($newFields))
                ) {
                    $fields[] = $newFields[$newIndex];
                    $newIndex++;
                }
            }
        }
        while ($newIndex < count($newFields)) {
            if (!in_array($newFields[$newIndex], $fields)) {
                $fields[] = $newFields[$newIndex];
            }
            $newIndex++;
        }

        return $fields;
    }

    private static function addFields(&$destMetadata, $sourceMetadata, $fields) {
        foreach ($sourceMetadata as $row) {
            if (in_array($row['field_name'], $fields)) {
                $destMetadata[] = $row;
            }
        }
    }

    # enforces delete
    private static function getFormsAndFields($metadata, $deletionRegEx, $fieldsToDelete = []) {
        $forms = [];
        foreach ($metadata as $row) {
            $formName = $row['form_name'];
            $fieldName = $row['field_name'];
            if (!preg_match($deletionRegEx, $fieldName) && !in_array($fieldName, $fieldsToDelete)) {
                if (!isset($forms[$formName])) {
                    $forms[$formName] = [];
                }
                $forms[$formName][] = $fieldName;
            }
        }
        return $forms;
    }

    # if present, $fields contains the fields to copy over; if left as an empty array, then it attempts to install all fields
    # $deletionRegEx contains the regular expression that marks fields for deletion
    # places new metadata rows AFTER last match from $existingMetadata
    public static function mergeMetadataAndUploadOld($originalMetadata, $newMetadata, $token, $server, $fields = array(), $deletionRegEx = "/___delete$/") {
        $fieldsToDelete = self::getFieldsWithRegEx($newMetadata, $deletionRegEx, TRUE);
        $filteredFields = [];
        foreach ($fields as $field) {
            if (preg_match($deletionRegEx, $field)) {
                $originalField = preg_replace($deletionRegEx, "", $field);
                if (!in_array($originalField, $fieldsToDelete)) {
                    $fieldsToDelete[] = $originalField;
                }
            } else {
                $filteredFields[] = $field;
            }
        }
        $existingMetadata = $originalMetadata;

        if (empty($existingMetadata)) {
            $metadata = [];
            foreach ($newMetadata as $row) {
                if (
                    !preg_match($deletionRegEx, $row['field_name'])
                    && !in_array($row['field_name'], $fieldsToDelete)
                ) {
                    $metadata[] = $row;
                }
            }
            if (empty($metadata)) {
                $pid = Application::getPID($token);
                $eventId = Application::getSetting("event_id", $pid);
                $switches = new FeatureSwitches($token, $server, $pid);
                return self::installMetadataFromFiles(Application::getMetadataFiles(), $token, $server, $pid, $eventId, Application::getRelevantChoices(), $deletionRegEx, $switches->getFormsToExclude());
            } else {
                self::sortByForms($metadata);
                $pid = Application::getPID($token);
                self::alterOptionalFields($metadata, $pid);
                self::alterResourcesFields($metadata, $pid);
                self::alterInstitutionFields($metadata, $pid);
                return Upload::metadata($metadata, $token, $server);
            }
        }

        # List of what to do
        # 1. delete rows/fields
        # 2. update fields
        # 3. add in new fields with existing forms
        # 4. add in new forms
        # 5. exclude requested forms

        if (empty($filteredFields)) {
            $selectedRows = $newMetadata;
        } else {
            $selectedRows = self::getRowsForFieldsFromMetadata($filteredFields, $newMetadata);
        }
        foreach ($selectedRows as $newRow) {
            if (!in_array($newRow['field_name'], $fieldsToDelete)) {
                $existingMetadataFields = self::getFieldsFromMetadata($existingMetadata);
                $priorRowField = end($existingMetadata)['field_name'];
                foreach ($newMetadata as $row) {
                    if ($row['field_name'] == $newRow['field_name']) {
                        break;
                    } else if (
                        !preg_match($deletionRegEx, $row['field_name'])
                        && !in_array($row['field_name'], $fieldsToDelete)
                        && in_array($row['field_name'], $existingMetadataFields)
                    ) {
                        $priorRowField = $row['field_name'];
                    }
                }

                # no longer needed because now allow to finish current form
                // if (self::atEndOfMetadata($priorRowField, $selectedRows, $newMetadata)) {
                // $priorRowField = end($originalMetadata)['field_name'];
                // }
                $tempMetadata = [];
                $priorNewRowField = "";
                for ($i = 0; $i < count($existingMetadata); $i++) {
                    $row = $existingMetadata[$i];
                    if (
                        !preg_match($deletionRegEx, $row['field_name'])
                        && !in_array($row['field_name'], $fieldsToDelete)
                        && ($priorNewRowField != $row['field_name'])
                    ) {
                        $tempMetadata[] = $row;
                    }
                    if (
                        ($priorRowField == $row['field_name'])
                        && !preg_match($deletionRegEx, $newRow['field_name'])
                    ) {
                        $newRow = self::copyMetadataSettingsForField($newRow, $newMetadata, $upload, $token, $server);

                        if ($row['form_name'] != $newRow['form_name']) {
                            # finish current form
                            while (($i+1 < count($existingMetadata)) && ($existingMetadata[$i+1]['form_name'] == $existingMetadata[$i]['form_name'])) {
                                $i++;
                                $tempMetadata[] = $existingMetadata[$i];
                            }
                        }
                        # delete already existing rows with same field_name
                        self::deleteRowsWithFieldName($tempMetadata, $newRow['field_name']);
                        $tempMetadata[] = $newRow;
                        $priorNewRowField = $newRow['field_name'];
                    }
                }
                $existingMetadata = $tempMetadata;
            }
        }
        if (empty($existingMetadata)) {
            # second attempt - allow sort by forms to correct
            $existingMetadata = $originalMetadata;
            if (empty($filteredFields)) {
                $selectedRows = $newMetadata;
            } else {
                $selectedRows = self::getRowsForFieldsFromMetadata($filteredFields, $newMetadata);
            }
            foreach ($selectedRows as $newRow) {
                if (!in_array($newRow['field_name'], $fieldsToDelete)) {
                    $found = FALSE;
                    foreach ($existingMetadata as $i => $existingRow) {
                        if ($existingRow['field_name'] == $newRow['field_name']) {
                            $existingMetadata[$i] = $newRow;
                            $found = TRUE;
                        }
                    }

                    if (!$found && !preg_match($deletionRegEx, $newRow['field_name'])) {
                        $existingMetadata[] = $newRow;
                    }
                }
            }
        }

        self::sortByForms($existingMetadata);
        $pid = Application::getPID($token);
        self::alterOptionalFields($existingMetadata, $pid);
        self::alterResourcesFields($existingMetadata, $pid);
        self::alterInstitutionFields($existingMetadata, $pid);
        self::alterDepartmentsFields($existingMetadata, $pid);
        self::checkForImproperDeletions($existingMetadata, $originalMetadata, $fieldsToDelete, $pid);
        return Upload::metadata($existingMetadata, $token, $server);
    }

    private static function checkForImproperDeletions($proposedMetadata, $originalMetadata, $fieldsToDelete, $pid) {
        $originalMetadataFields = self::getFieldsFromMetadata($originalMetadata);
        $proposedMetadataFields = self::getFieldsFromMetadata($proposedMetadata);
        $missingRows = [];
        foreach ($originalMetadataFields as $field) {
            if (!in_array($field, $proposedMetadataFields) && !in_array($field, $fieldsToDelete)) {
                foreach ($originalMetadata as $row) {
                    if ($row['field_name'] == $field) {
                        $missingRows[] = $row;
                    }
                }
            }
        }
        if (!empty($missingRows)) {
            $version = Application::getVersion();
            $server = Application::getSetting("server", $pid);
            $adminEmail = Application::getSetting("admin_email", $pid);
            $mssg = "<h1>Metadata Upload Error in " . Application::getProgramName() . "</h1>";
            $mssg .= "<p>Server: $server</p>";
            $mssg .= "<p>PID: $pid</p>";
            $mssg .= "<p>Flight Tracker Version: $version</p>";
            $mssg .= "<p>Admin Email: <a href='mailto:$adminEmail'>$adminEmail</a></p>";
            $mssg .= "Upload aborted! Please contact admins. Certain rows were expected yet still missing: ".REDCapManagement::json_encode_with_spaces($missingRows);
            \REDCap::email("scott.j.pearson@vumc.org", "noreply.flighttracker@vumc.org", Application::getProgramName() . " Metadata Upload Error", $mssg);

            $deletionMssg = "";
            if (!empty($fieldsToDelete)) {
                $deletionMssg = "Fields to Delete: ".REDCapManagement::makeConjunction($fieldsToDelete);
            }
            throw new \Exception("An accidental field deletion has occurred and the development team has been notified. Someone will be in contact with you soon to coach you how to proceed. ".$deletionMssg);
        }
    }

    private static function alterOptionalFields(&$metadata, $pid) {
        if ($pid) {
            $metadataFields = self::getFieldsFromMetadata($metadata);
            $relevantFields = REDCapManagement::getSpecialFields("optional", $metadata);
            foreach ($relevantFields as $field) {
                $settingName = REDCapManagement::turnOptionalFieldIntoSetting($field);
                $optionsText = Application::getSetting($settingName, $pid);
                if ($optionsText) {
                    $choiceStr = self::makeREDCapList($optionsText, self::OPTION_OTHER_VALUE);
                    Application::log("Using optional field $field $choiceStr", $pid);
                    self::setSelectStringForFields($metadata, $choiceStr, [$field]);
                } else if (in_array($field, $metadataFields)) {
                    $newMetadata = [];
                    foreach ($metadata as $row) {
                        if ($row['field_name'] != $field) {
                            $newMetadata[] = $row;
                        }
                    }
                    Application::log("Not using optional field $field; shrinking metadata from ".count($metadata)." into ".count($newMetadata), $pid);
                    $metadata = $newMetadata;
                }
            }
        }
    }

    private static function alterInstitutionFields(&$metadata, $pid) {
        if ($pid) {
            $institutions = Application::getInstitutions($pid, FALSE);
            if (empty($institutions)) {
                $institutions = ["Home Institution"];
            }
            $relevantFields = REDCapManagement::getSpecialFields("institutions", $metadata);
            $choiceStr = self::makeREDCapList(implode("\n", $institutions), self::INSTITUTION_OTHER_VALUE);
            self::setSelectStringForFields($metadata, $choiceStr, $relevantFields);
        }
    }

    private static function setSelectStringForFields(&$metadata, $choiceStr, $fields) {
        foreach ($metadata as $i => $row) {
            if (in_array($row['field_name'], $fields)) {
                $metadata[$i]['select_choices_or_calculations'] = $choiceStr;
            }
        }
    }

    private static function alterDepartmentsFields(&$metadata, $pid) {
        if ($pid) {
            $departments = Application::getSetting("departments", $pid);
            if ($departments) {
                $fields = REDCapManagement::getSpecialFields("departments", $metadata);
                $choiceStr = self::makeREDCapList($departments, self::DEPARTMENT_OTHER_VALUE);
                self::setSelectStringForFields($metadata, $choiceStr, $fields);
            }
        }
    }

    private static function alterResourcesFields(&$metadata, $pid) {
        $defaultResourceField = "resources_resource";
        $blankSetup = ["1" => "Resource"];
        $metadataFields = self::getFieldsFromMetadata($metadata);
        $mentoringResourceField = self::getMentoringResourceField($metadataFields);

        $choices = self::getChoices($metadata);
        if (
            !empty($choices[$mentoringResourceField])
            && !empty($choices[$defaultResourceField])
            && self::isInitialSetupForResources($choices[$mentoringResourceField])
        ) {
            if (in_array($defaultResourceField, $metadataFields)) {
                if (Application::isVanderbilt()) {
                    $resourceStr = self::makeChoiceStr(self::getMenteeAgreementVanderbiltResources());
                } else {
                    $resourceStr = self::makeChoiceStr($choices[$defaultResourceField]);
                }
            } else {
                $resourceStr = self::makeChoiceStr($blankSetup);
            }
        } else if (!empty($choices[$defaultResourceField])) {
            $resourceStr = self::makeChoiceStr($choices[$defaultResourceField]);
        } else if (!empty($choices[$mentoringResourceField])) {
            $resourceStr = self::makeChoiceStr($choices[$mentoringResourceField]);
        } else if ($pid) {
            $resourceList = Application::getSetting("resources", $pid);
            if (trim($resourceList)) {
                $resource1DAry = explode("\n", $resourceList);
                $resourcesWithIndex = [];
                $idx = 1;
                foreach ($resource1DAry as $resource) {
                    $resourcesWithIndex[$idx] = $resource;
                    $idx++;
                }
                $resourceStr = self::makeChoiceStr($resourcesWithIndex);
            } else {
                $resourceStr = self::makeChoiceStr($blankSetup);
            }
        } else {
            $resourceStr = self::makeChoiceStr($blankSetup);
        }

        if (!$resourceStr) {
            throw new \Exception("Could not put together a resource string! '".Application::getSetting("resources", $pid)."'");
        }

        $fieldsToModify = [$mentoringResourceField, $defaultResourceField];
        self::setSelectStringForFields($metadata, $resourceStr, $fieldsToModify);
    }

    public static function getMenteeAgreementVanderbiltResources() {
        $resources = [
            "Career Development Funding Opportunities",
            "CTSA Studio Program",
            "Edge Seminars",
            "Edge for Scholars Grant Repository",
            "Edge for Scholars Grant Review Program",
            "Edge for Scholars Grant Writing Workshop",
            "Edge for Scholars Manuscript Sprint Program",
            "<a href='https://edgeforscholars.org' target='_NEW'>Edgeforscholars.org</a>",
            "Elliot Newman Society",
            "Kaizen Program for Rigor and Reproducibility Training",
            "Translational Nexus Pathways",
        ];

        $indexedResources = [];
        foreach ($resources as $i => $resource) {
            $indexedResources[$i+1] = $resource;
        }
        return $indexedResources;
    }

    private static function sortByForms(&$metadata) {
        $forms = [];
        foreach ($metadata as $row) {
            $form = $row['form_name'];
            if (!in_array($form, $forms)) {
                $forms[] = $form;
            }
        }

        $newMetadata = [];
        foreach ($forms as $form) {
            foreach ($metadata as $row) {
                if ($row['form_name'] == $form) {
                    $newMetadata[] = $row;
                }
            }
        }

        if (count($newMetadata) != count($metadata)) {
            throw new \Exception("Improper sort!");
        }
        $firstFieldName = "record_id";
        if (empty($newMetadata)) {
            throw new \Exception("New metadata empty from forms ".json_encode($forms)." and metadata ".json_encode($metadata));
        } else if ($newMetadata[0]['field_name'] !== $firstFieldName) {
            throw new \Exception("First field is ".$newMetadata[0]['field_name'].", not $firstFieldName!");
        } else {
            $metadata = $newMetadata;
        }
    }

    private static function deleteRowsWithFieldName(&$metadata, $fieldName) {
        $newMetadata = array();
        foreach ($metadata as $row) {
            if ($row['field_name'] != $fieldName) {
                $newMetadata[] = $row;
            }
        }
        $metadata = $newMetadata;
    }

    public static function getRowChoices($choicesStr, $directFromDB = FALSE) {
        if ($directFromDB) {
            $regex = "/\s*\\\\n\s*/";
        } else {
            $regex = "/\s*\|\s*/";
        }
        $choicePairs = preg_split($regex, $choicesStr);
        $choices = [];
        foreach ($choicePairs as $pair) {
            $a = preg_split("/\s*,\s*/", $pair);
            if (count($a) == 2) {
                $choices[$a[0]] = $a[1];
            } else if (count($a) > 2) {
                $a = explode(",", $pair);
                $b = [];
                for ($i = 1; $i < count($a); $i++) {
                    $b[] = $a[$i];
                }
                $choices[trim($a[0])] = trim(implode(",", $b));
            }
        }
        return $choices;
    }

    public static function copyMetadataSettingsForField($row, $metadata, &$upload, $token, $server) {
        foreach ($metadata as $metadataRow) {
            if ($metadataRow['field_name'] == $row['field_name']) {
                # do not overwrite any settings in associative arrays
                $field = $row['field_name'];
                foreach (self::getMetadataFieldsToScreen() as $rowSetting) {
                    if ($rowSetting == "select_choices_or_calculations") {
                        $rowChoices = self::getRowChoices($row[$rowSetting]);
                        if (!preg_match("/_source$/", $field)) {
                            // merge
                            $rowKeys = array_keys($rowChoices);
                            $metadataChoices = self::getRowChoices($metadataRow[$rowSetting]);
                            $metadataKeys = array_keys($metadataChoices);
                            $mergedChoices = $rowChoices;
                            foreach ($metadataChoices as $idx => $label) {
                                if (!isset($mergedChoices[$idx])) {
                                    $mergedChoices[$idx] = $label;
                                } else if ($mergedChoices[$idx] == $label) {
                                    # both have same idx/label - no big deal
                                } else {
                                    # merge conflict => reassign all data values
                                    $oldIdx = $idx;

                                    $numericMergedChoicesKeys = [];
                                    foreach (array_keys($mergedChoices) as $key) {
                                        if (is_numeric($key)) {
                                            $numericMergedChoicesKeys[] = $key;
                                        }
                                    }

                                    if (!empty($numericMergedChoicesKeys)) {
                                        $newIdx = max($numericMergedChoicesKeys) + 1;
                                    } else {
                                        $newIdx = 1;
                                    }
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
                            if (REDCapManagement::arrayOrdersEqual($rowKeys, $metadataKeys)) {
                                $row[$rowSetting] = self::makeChoiceStr($mergedChoices);
                            } else {
                                $reorderedMergedChoices = [];
                                foreach ($metadataKeys as $idx) {
                                    if (isset($mergedChoices[$idx])) {
                                        $label = $mergedChoices[$idx];
                                        $reorderedMergedChoices[$idx] = $label;
                                    } else {
                                        throw new \Exception("Error: Cannot find index!");
                                    }
                                }
                                foreach ($mergedChoices as $idx => $label) {
                                    if (!isset($reorderedMergedChoices[$idx])) {
                                        $reorderedMergedChoices[$idx] = $label;
                                    }
                                }
                                $row[$rowSetting] = self::makeChoiceStr($reorderedMergedChoices);
                            }
                        } else {
                            // a source field
                            $row[$rowSetting] = self::makeChoiceStr($rowChoices);
                        }
                    } else if ($row[$rowSetting] != $metadataRow[$rowSetting]) {
                        // not select_choices_or_calculations
                        if (!REDCapManagement::isJSON($row[$rowSetting]) || ($rowSetting != "field_annotation")) {
                            $row[$rowSetting] = $metadataRow[$rowSetting];
                        }
                    }
                }
                break;
            }
        }
        return $row;
    }

    public static function makeChoiceStr($fieldChoices) {
        $pairs = [];
        foreach ($fieldChoices as $key => $label) {
            $pairs[] = "$key, $label";
        }
        return implode(" | ", $pairs);
    }

    public static function getMentoringResourceField($metadataFields) {
        $resourceField = "mentoring_local_resource";
        $newFieldName = $resourceField."s";
        if (in_array($newFieldName, $metadataFields)) {
            return $newFieldName;
        } else {
            return $resourceField;
        }
    }

    public static function isInitialSetupForResources($resourceFieldChoices) {
        if (!$resourceFieldChoices) {
            return TRUE;
        }
        if (is_string($resourceFieldChoices)) {
            $resourceFieldChoices = DataDictionaryManagement::getRowChoices($resourceFieldChoices);
        }
        $keys = array_keys($resourceFieldChoices);
        $values = array_values($resourceFieldChoices);
        return (
            (count($resourceFieldChoices) == 1)
            && ($keys[0] == "1")
            && ($values[0] == "Institutional Resources Here")
        );
    }

    public static function getRepeatingForms($pid) {
        $module = Application::getModule();
        if (!method_exists($module, "query")) {
            require_once(dirname(__FILE__)."/../../../redcap_connect.php");
        }

        $sql = "SELECT DISTINCT(r.form_name) AS form_name FROM redcap_events_metadata AS m INNER JOIN redcap_events_arms AS a ON (a.arm_id = m.arm_id) INNER JOIN redcap_events_repeat AS r ON (m.event_id = r.event_id) WHERE a.project_id = ?";
        $q = $module->query($sql, [$pid]);
        $repeatingForms = array();
        while ($row = $q->fetch_assoc()) {
            $repeatingForms[] = $row['form_name'];
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

    public static function getSurveys($pid, $metadata = []) {
        $module = Application::getModule();
        if (!method_exists($module, "query")) {
            require_once(dirname(__FILE__)."/../../../redcap_connect.php");
        }

        $sql = "SELECT form_name, title FROM redcap_surveys WHERE project_id = ?";
        $q = $module->query($sql, [$pid]);

        if (!empty($metadata)) {
            $instrumentNames = REDCapManagement::getFormsFromMetadata($metadata);
            $currentInstruments = [];
            foreach ($instrumentNames as $instrumentName) {
                $currentInstruments[$instrumentName] = self::translateFormToName($instrumentName);
            }
        } else {
            $currentInstruments = \REDCap::getInstrumentNames();
        }

        $forms = array();
        while ($row = $q->fetch_assoc()) {
            # filter out surveys which aren't live
            if (isset($currentInstruments[$row['form_name']])) {
                $forms[$row['form_name']] = $row['title'];
            }
        }

        return $forms;
    }

    public static function translateFormToName($instrument) {
        $instrument = str_replace("_", " ", $instrument);
        return ucwords($instrument);
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

    public static function getPrefix($field) {
        $nodes = explode("_", $field);
        if ($nodes[0] == "newman") {
            return $nodes[0]."_".$nodes[1];
        }
        return $nodes[0];
    }

    public static function allFieldsValid($row, $metadataFields) {
        $skip = ["/^redcap_repeat_instrument$/", "/^redcap_repeat_instance$/", "/_complete$/"];
        foreach ($row as $field => $value) {
            $found = FALSE;
            foreach ($skip as $regex) {
                if (preg_match($regex, $field)) {
                    $found = TRUE;
                    break;
                }
            }
            if (!$found && !in_array($field, $metadataFields)) {
                return FALSE;
            }
        }
        return TRUE;
    }

    public static function prefix2CompleteField($prefix) {
        $prefix = preg_replace("/_$/", "", $prefix);
        if ($prefix == "promotion") {
            return "position_change_complete";
        } else if ($prefix == "check") {
            return "initial_survey_complete";
        } else if ($prefix == "custom") {
            return "custom_grant_complete";
        } else if ($prefix == "imported_degree") {
            return "manual_degree_complete";
        } else if ($prefix == "coeussubmission") {
            return "coeus_submission_complete";
        } else if ($prefix == "verasubmission") {
            return "vera_submission_complete";
        } else if ($prefix == "mentoring") {
            return "mentoring_agreement_complete";
        } else if ($prefix == "mentoringeval") {
            return "mentoring_agreement_evaluations_complete";
        } else if ($prefix == "vfrs") {
            return "pre_screening_survey_complete";
        } else if ($prefix == "honor") {
            return "honors_and_awards_complete";
        }
        return "";
    }

    public static function indexMetadata($metadata) {
        $indexed = [];
        foreach ($metadata as $row) {
            $indexed[$row['field_name']] = $row;
        }
        return $indexed;
    }

    public static function hasMetadataChanged($oldValue, $newValue, $metadataField) {
        if ($metadataField == "field_annotation" && REDCapManagement::isJSON($oldValue)) {
            return FALSE;
        }
        if (!isset($oldValue) || !isset($newValue)) {
            return FALSE;
        }
        if (
            ($metadataField == "select_choices_or_calculations")
            && self::isChoiceString($oldValue)
            && self::isChoiceString($newValue)
        ) {
            $oldChoices = self::getRowChoices($oldValue);
            $newChoices = self::getRowChoices($newValue);
            return !REDCapManagement::arraysEqual($oldChoices, $newChoices);
        }
        $oldValue = trim($oldValue);
        $newValue = trim($newValue);
        return ($oldValue != $newValue);
    }

    public static function isChoiceString($str, $directFromDatabase = FALSE) {
        if ($directFromDatabase) {
            $regex = "/\s*\\\\n\s*/";
        } else {
            $regex = "/\s*\|\s*/";
        }
        return preg_match($regex, $str);
    }

    public static function screenForFields($metadata, $possibleFields) {
        $metadataFields = self::getFieldsFromMetadata($metadata);
        $fields = ["record_id"];
        foreach ($possibleFields as $field) {
            if (in_array($field, $metadataFields)) {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    public static function getFieldsUnderSection($metadata, $sectionHeader) {
        $fields = [];
        $inHeader = FALSE;
        foreach ($metadata as $row) {
            if ($row['section_header'] == $sectionHeader) {
                $inHeader = TRUE;
            }
            if ($inHeader) {
                $fields[] = $row['field_name'];
            }
            if ($inHeader && $row['section_header'] && ($row['section_header'] != $sectionHeader)) {
                $inHeader = FALSE;
            }
        }
        return $fields;
    }

    public static function isMetadataFilled($metadata) {
        if (count($metadata) < 10) {
            return FALSE;
        }
        if ($metadata[0]['field_name'] != "record_id") {
            return FALSE;
        }
        return TRUE;
    }

    public static function isCompletionField($field) {
        return preg_match("/_complete$/", $field);
    }
}