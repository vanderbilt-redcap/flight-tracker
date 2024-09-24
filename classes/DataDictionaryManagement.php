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
    const ALUMNI_OTHER_VALUE = "999999";
    const HONORACTIVITY_OTHER_VALUE = '999999';
    const HONORACTIVITY_SPECIAL_FIELDS = [
        "activityhonor_committee_name_other" => [
            "activityhonor_committee_name",
            "surveyactivityhonor_committee_name",
            "check_honor1_committee_name",
            "check_honor2_committee_name",
            "check_honor3_committee_name",
            "check_honor4_committee_name",
            "check_honor5_committee_name",
            "followup_honor1_committee_name",
            "followup_honor2_committee_name",
            "followup_honor3_committee_name",
            "followup_honor4_committee_name",
            "followup_honor5_committee_name",
        ],
        "activityhonor_name" => [
            "activityhonor_local_name",
            "surveyactivityhonor_local_name",
        ],
        "surveyactivityhonor_committee_name_other" => [
            "activityhonor_committee_name",
            "surveyactivityhonor_committee_name",
            "check_honor1_committee_name",
            "check_honor2_committee_name",
            "check_honor3_committee_name",
            "check_honor4_committee_name",
            "check_honor5_committee_name",
            "followup_honor1_committee_name",
            "followup_honor2_committee_name",
            "followup_honor3_committee_name",
            "followup_honor4_committee_name",
            "followup_honor5_committee_name",
        ],
        "surveyactivityhonor_name" => [
            "activityhonor_local_name",
            "surveyactivityhonor_local_name",
        ],
    ];

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

    private static function readMetadataFile($file) {
        if (file_exists($file)) {
            $fp = fopen($file, "r");
            $json = "";
            while ($line = fgets($fp)) {
                $json .= $line;
            }
            fclose($fp);
            return json_decode($json, true) ?? [];
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
        if (isset($lists['program_roles'])) {
            Application::saveSetting("program_roles", $lists["program_roles"], $pid);
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
                    $newLines = self::readMetadataFile($file);
                    if ($newLines !== NULL) {
                        $metadata = array_merge($metadata, $newLines);
                    } else {
                        throw new \Exception("Could not unpack json in $file: ".json_last_error_msg());
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
        $allDataUploads = [];
        foreach ($fields as $type => $fieldsToChange) {
            $redcapLists[$type] = "";
            if ($type == "optional") {
                foreach ($fieldsToChange as $field) {
                    $settingName = REDCapManagement::turnOptionalFieldIntoSetting($field);
                    $itemText = $lists[$settingName] ?? "";
                    if ($itemText) {
                        $oldItemChoices = $choices[$field] ?? [];
                        list($redcapLists[$settingName], $upload) = self::makeREDCapList($itemText, self::getOptionalOtherItem($field), $oldItemChoices, $pid, $field);
                        $allDataUploads = array_merge($allDataUploads, $upload);
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
                $other = $others[$type] ?? FALSE;
                list($redcapLists[$type], $upload) = self::makeREDCapList($str, $other, $oldItemChoices);
            }
        }

        $choiceFieldTypes = ["dropdown", "radio", "checkbox"];
        $institutionFields = REDCapManagement::getSpecialFields("institutions", $metadata);
        $newMetadata = [];
        foreach ($metadata as $row) {
            $isCoeusRow = self::isCoeusMetadataRow($row);
            if ((($installCoeus && $isCoeusRow) || !$isCoeusRow) && !preg_match("/___delete/", $row['field_name'])) {
                foreach ($fields as $type => $relevantFields) {
                    if (in_array($row['field_name'], $relevantFields) && ($type !== "") && isset($lists[$type]) && isset($redcapLists[$type])) {
                        if ($type == "optional") {
                            $settingName = REDCapManagement::turnOptionalFieldIntoSetting($row['field_name']);
                            if (isset($redcapLists[$settingName])) {
                                $row['select_choices_or_calculations'] = $redcapLists[$settingName];
                            }
                        } else if (is_string($redcapLists[$type])) {
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
        $upload = self::alterOptionalFields($newMetadata, $pid);
        $feedback = Upload::metadata($newMetadata, $token, $server);
        if (!empty($upload)) {
            Upload::rowsByPid($upload, $pid);
        }
        return $feedback;
    }

    private static function getOptionalOtherItem($field) {
        if (preg_match("/person_role/", $field)) {
            return self::OPTION_OTHER_VALUE;
        }
        return FALSE;
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

    # returns [new REDCap list, list of items to adjust with an upload]
    public static function makeREDCapList($text, $otherItem = FALSE, $oldItemChoices = [], $pid = "", $field = "") {
        $upload = [];
        if (!$text) {
            return ["", $upload];
        }
        $list = explode("\n", $text);
        $newList = array();
        $i = 1;    // starting at 0 causes troubles with matching cohort logic
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
                    if ($index == 0) {
                        // old bug
                        $max = 0;
                        foreach (array_keys($oldItemChoices) as $idx) {
                            if (is_numeric($idx) && ($idx > $max)) {
                                $max = $idx;
                            }
                        }
                        $index = $max + 1;

                        # convert data from 0 to $index
                        if ($field && $pid) {
                            $previousValues = Download::oneFieldByPid($pid, $field);
                            foreach ($previousValues as $recordId => $previousValue) {
                                if ($previousValue === "0") {
                                    $upload[] = [
                                        "record_id" => $recordId,
                                        $field => $index,
                                    ];
                                }
                            }
                        }
                    }
                    $newList[] = $index.",".$item;
                    $seenIndices[] = $index;
                } else {
                    while (isset($oldItemChoices[$i]) || ($i == $otherItem)) {
                        $i++;
                    }
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
        return [implode("|", $newList), $upload];
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

    public static function installAllMetadataForNecessaryPids($pids) {
        $files = Application::getMetadataFiles();
        $deletionRegEx = self::getDeletionRegEx();
        $pidsToRun = [];
        foreach (REDCapManagement::getActiveProjects($pids) as $requestedPid) {
            $requestedToken = Application::getSetting("token", $requestedPid);
            $requestedServer = Application::getSetting("server", $requestedPid);
            if ($requestedToken && $requestedServer) {
                $requestedMetadata = Download::metadata($requestedToken, $requestedServer);
                $switches = new FeatureSwitches($requestedToken, $requestedServer, $requestedPid);
                list ($missing, $additions, $changed) = self::findChangedFieldsInMetadata($requestedMetadata, $files, $deletionRegEx, CareerDev::getRelevantChoices(), $switches->getFormsToExclude(), $requestedPid);
                if (count($additions) + count($changed) > 0) {
                    $pidsToRun[] = $requestedPid;
                }
            }
        }
        return self::installMetadataForPids($pidsToRun, $files, $deletionRegEx);
    }

    public static function getInstrumentDescriptions($instrument = "all") {
        $ary = [
            "initial_survey" => "A one-time survey completed by scholars. It is pre-filled with any prior data points and takes 20-30 minutes to complete. Use the Scholars menu &rarr; Configure an Email page to send. It contains demographic information, education history, job history, publications &amp; grants.",
            "initial_import" => "A copy of the Initial Survey. Administrators complete this to pre-fill items for the Initial Survey.",
            "followup" => "A regular update survey completed by scholars. It is pre-filled with any prior data points and takes 10-15 minutes to complete. This form is repeating and can be filled out more than once. Use the Scholars menu &rarr; Configure an Email page to send. It contains updates for education history, job history, publications &amp; grants (note: not demographics).",
            "identifiers" => "Identifying information is stored here, like the scholar’s name, email, ORCID id &amp; dates in the program. Some values are automatically updated from other instruments.",
            "manual" => "Information about scholar demographics uploaded by a spreadsheet is stored here, input by an administrator. There is some overlap with the Initial Import form. The Initial Import form is the preferred way, but inputting data in this form is also accepted.",
            "manual_import" => "Stores information uploaded by a spreadsheet about scholar demographics, input by an administrator. There is some overlap with the Initial Import form. The Initial Import form is the preferred way, but inputting data in this form is also accepted.",
            "manual_degree" => "A repeating instrument used to understand the scholar's degrees and compiles its results onto the Summary instrument; stores any degrees awarded not noted by scholars in the surveys.",
            "summary" => "Contains the computer's best guess as to what's true from all the other instruments. It also tells you where it's pulling the data from. It also stores a summary of career-defining grant awards to show career progress. It is automatically overwritten weekly, so changing it directly is useless.",
            "vfrs" => "These intake surveys for VFRS funding from Vanderbilt supply demographic information about some scholars.",
            "coeus" => "COEUS has information about VUMC's awarded grants and all Vanderbilt awards before the split.",
            "coeus2" => "StarBRITE supplied some historical grant information from the grants-management system, but the COEUS instrument should be preferred. These data are kept for historical completeness, but are no longer being pulled.",
            "custom_grant" => "You can add awarded and submitted grants not captured by federal feeds and appointments to training grants here. For non-Vanderbilt projects, this instrument is the only way to capture non-federal funding-like foundation awards.",
            "reporter" => "Captures grant data from the now-defunct Federal RePORTER. New data are no longer captured.  Data are retained for historical completeness.",
            "exporter" => "Captured grant data from the NIH ExPORTER, which has now been replaced by the NIH RePORTER instrument. New data are no longer captured, but data are retained for historical completeness.",
            "nih_reporter" => "Stores grant data from the NIH RePORTER's API, which includes funding awards from the NIH since the mid-1980s.",
            "nsf" => "Stores grant data from the NSF Grants' API, which includes grants from the NSF and the Department of Defense.",
            "ies_grant" => "Stores grant data from the Institute of Education Sciences, which includes grants from the Deparment of Education.",
            "eric" => "Includes publication data from ERIC, the Department of Education's publications search engine.",
            "citation" => "Includes publication data from PubMed and related bibliometrics from iCite and Altmetric.",
            "resources" => "Stores data about scholar use of institutional resources. Preferred way is to enter the data via Flight Tracker's Resources menu.",
            "old_honors_and_awards" => "Stores historical data about Honors and Awards, but has been replaced by the Honors Awards and Activities instrument. To convert information, visit the Wrangle menu &rarr; Convert Honors &amp; Awards page.",
            "honors_and_awards" => "Stores historical data about Honors and Awards, but has been replaced by the Honors Awards and Activities instrument. To convert information, visit the Wrangle menu &rarr; Convert Honors &amp; Awards page.",
            "honors_awards_and_activities" => "Stores information about Honors, Awards, and Activities. It is meant to be filled out by an administrator. Scholars can add information directly via a corresponding, identical survey.",
            "honors_awards_and_activities_survey" => "Stores information about Honors, Awards, and Activities. It's also available via the Scholar Portal and can be sent out for scholars to fill out via the Scholars menu &rarr; Configure an Email page.",
            "ldap" => "Stores Vanderbilt data about scholars, like email, department, and user-id, and has been replaced by the LDAP-DS instrument and is no longer receiving new data. Data are retained for historical completeness.",
            "ldapds" => "Stores current Vanderbilt data about scholars, like email, department, and user-id.",
            "workday" => "Stores descriptive data about VUMC employees (only) from Workday. Data not stored about those solely employed by Vanderbilt University.",
            "coeus_submission" => "Stores information about grant submissions at VUMC and all Vanderbilt submissions before the split.",
            "vera" => "Stores information from VERA about grant awards at Vanderbilt University after the split.",
            "vera_submission" => "Stores information from VERA about grant submissions at Vanderbilt University after the split.",
            "position_change" => "A repeating instrument: stores information about promotions and employment changes over time. Critically, it stores information about outside institutions that a scholar might have been a part of. Its values are also used to produce NIH Training Table 8.",
            "exclude_lists" => "Stores information about Flight Tracker's Exclude Lists. Normally, these values are entered from data wranglers and do not need to be modified manually.",
            "patent" => "Stores patent information from Patents View from the US Patent &amp; Trademark Office.",
            "mentoring_agreement" => "Stores entries from the mentee &amp; mentor as they fill out mentee-mentor agreements;entered via the Mentors menu.",
            "mentoring_agreement_evaluations" => "Stores an optional evaluation of the mentee-mentor agreement. If desired, it must be sent out via the Scholars menu &rarr; Configure an Email page.",
            "mstp_individual_development_plan_idp" => "A survey that stores MSTP-specific IDP information that can be sent as a REDCap survey. To distribute, see the Mentors menu &rarr; Configure IDP Reviewers for Classes page.",
            "mstp_mentee_mentor_agreement" => "Stores data for MSTP-specific mentee-mentor agreements which can be distributed via the Mentors menu.",
        ];
        if ($instrument == "all") {
            return $ary;
        } else {
            return $ary[$instrument] ?? "";
        }
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
                $surveysAndLabels = self::getSurveysAndLabels($newMetadata, $pid);
                self::setupRepeatingForms($eventId, $formsAndLabels);   // runs an UPDATE and/or INSERT
                self::setupSurveys($pid, $surveysAndLabels);
                self::convertOldDegreeData($pid);
                return $feedback;
            } catch (\Exception $e) {
                $configurationLink = Application::link("config.php", $pid);
                $feedback = ["Exception" => $e->getMessage()."<p>Note that both the Departments and Resources fields must have some information specified (i.e., non-blank) in the <a href='$configurationLink'>Configure Application page</a>.</p>"];
                $version = Application::getVersion();
                $adminEmail = Application::getSetting("admin_email", $pid);
                $mssg = "<h1>Metadata Upload Error in " . Application::getProgramName() . "</h1>";
                $mssg .= "<p>Server: $server</p>";
                $mssg .= "<p>PID: $pid</p>";
                $mssg .= "<p>Flight Tracker Version: $version</p>";
                $mssg .= "<p>Admin Email: <a href='mailto:$adminEmail'>$adminEmail</a></p>";
                $mssg .= $e->getMessage();
                $mssg .= "<br/><br/>".$e->getTraceAsString();
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

    private static function getSurveyRepeatButtonText($form) {
        if ($form == "honors_awards_and_activities_survey") {
            return "Add Another Honor or Activity";
        }
        return "";
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
            $repeatButtonText = self::getSurveyRepeatButtonText($form);
            if (in_array($form, $forms)) {
                $params = [$label, $surveyCompletionText, 1, 1];
                if ($repeatButtonText) {
                    $repeatSurveyValue = "1";
                    $repeatButtonValue = "?";
                    $params[] = $repeatSurveyValue;
                    $params[] = $repeatButtonText;
                } else {
                    $repeatSurveyValue = "0";
                    $repeatButtonValue = "NULL";
                    $params[] = $repeatSurveyValue;
                }
                $params[] = $projectId;
                $params[] = $form;
                $sql = "UPDATE redcap_surveys SET title = ?, acknowledgement = ?, save_and_return_code_bypass = ?, edit_completed_response = ?, repeat_survey_enabled = ?, repeat_survey_btn_text = $repeatButtonValue WHERE project_id = ? AND form_name = ?";
                $module->query($sql, $params);
            } else {
                $params = [$projectId, $form, $label, $surveyIntroText, $surveyCompletionText];
                if ($repeatButtonText) {
                    $repeatSurveyValue = "1";
                    $repeatButtonValue = "?";
                    $params[] = $repeatSurveyValue;
                    $params[] = $repeatButtonText;
                } else {
                    $repeatSurveyValue = "0";
                    $repeatButtonValue = "NULL";
                    $params[] = $repeatSurveyValue;
                }
                $sql = "INSERT INTO redcap_surveys (project_id, font_family, form_name, title, instructions, acknowledgement, question_by_section, question_auto_numbering, survey_enabled, save_and_return, save_and_return_code_bypass, edit_completed_response, logo, hide_title, view_results, min_responses_view_results, check_diversity_view_results, end_survey_redirect_url, survey_expiration, repeat_survey_enabled, repeat_survey_btn_text) VALUES (?, '16', ?, ?, ?, ?, 0, 1, 1, 1, 1, 1, NULL, 0, 0, 10, 0, NULL, NULL, ?, $repeatButtonValue)";
                $module->query($sql, $params);
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
            $dataTable = Application::getDataTable($pid);
            $sql = "UPDATE $dataTable SET value=? WHERE project_id=? AND value=? AND field_name IN (".implode(",", $questionMarks).")";
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

    public static function getRepeatingFormsAndLabelsForProject($eventId) {
        $module = Application::getModule();
        $sql = "SELECT form_name, custom_repeat_form_label FROM redcap_events_repeat WHERE event_id = ?";
        $result = $module->query($sql, [$eventId]);
        $repeatingForms = [];
        while ($row = $result->fetch_assoc()) {
            $repeatingForms[$row['form_name']] = $row['custom_repeat_form_label'] ?? "";
            # a NULL value will fail an isset() statement in self::setupRepeatingForms()
        }
        return $repeatingForms;
    }

    public static function setupRepeatingForms($eventId, $formsAndLabels) {
        $module = Application::getModule();
        $repeatingForms = self::getRepeatingFormsAndLabelsForProject($eventId);
        $sqlEntries = [];
        $insertValues = [];
        foreach ($formsAndLabels as $form => $label) {
            if (!isset($repeatingForms[$form])) {
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

    public static function getChoicesForFields($pid, $fields) {
        if (empty($fields)) {
            return [];
        }
        $questionMarks = [];
        for ($i = 0; $i < count($fields); $i++) {
            $questionMarks[] = "?";
        }
        $sqlArray = "(".implode(",", $questionMarks).")";
        $module = Application::getModule();
        $sql = "SELECT field_name, element_enum FROM redcap_metadata WHERE project_id = ? AND field_name IN $sqlArray";
        $q = $module->query($sql, array_merge([$pid], $fields));
        $choices = [];
        while ($row = $q->fetch_assoc()) {
            $choices[$row['field_name']] = self::getRowChoices($row['element_enum'], TRUE);
        }
        return $choices;
    }

    public static function getChoicesForField($pid, $field) {
        $choices = self::getChoicesForFields($pid, [$field]);
        return $choices[$field] ?? [];
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
            if (!$metadata['file']) {
                throw new \Exception("Could not decode JSON: ".json_last_error_msg());
            }
            $metadata['file'] = self::filterOutForms($metadata['file'], $formsToExclude);
            if (Application::isLocalhost()) {
                $metadata['file'] = self::filterOutForms($metadata['file'], self::getCoeusForms());
            }
            if (MMAHelper::canConfigureCustomAgreement($pid)) {
                $metadata['file'] = self::filterOutForms($metadata['file'], ["mentoring_agreement"]);
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

            $metadataFields = self::getMetadataFieldsToScreen();
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
                    } else if (isset($fieldList["REDCap"][$field])) {
                        list($optionChoiceStr, $upload) = self::makeREDCapList($optionChoicesAsText, self::getOptionalOtherItem($field));
                        if ($fieldList["REDCap"][$field] != $optionChoiceStr) {
                            $changed[] = $field." [updated options]";
                        }
                    } else {
                        $missing[] = $field;
                        $additions[] = $field;
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
                        if (!$isSpecialGenderField && !self::isAlumniAssociationField($field)) {
                            $missing[] = $field;
                            $changed[] = $field." [choices not equal]";
                        }
                    } else {
                        foreach ($metadataFields as $metadataField) {
                            if (($field == "record_id") && ($metadataField == "form_name")) {
                                # allow for record_id to be on another form first, like a public survey
                            } else if (
                                self::hasMetadataChanged($indexedMetadata["REDCap"][$field][$metadataField], $indexedMetadata["file"][$field][$metadataField], $metadataField)
                            ) {
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

    public static function getDefaultSurveysAndLabels($pid) {
        $defaultSurveys = [
            "initial_survey" => "Flight Tracker Initial Survey",
            "followup" => "Flight Tracker Followup Survey",
            "mentoring_agreement_evaluations" => "Mentoring Agreement Evaluations",
            "honors_awards_and_activities_survey" => "Flight Tracker Honors, Awards & Activities Survey",
        ];
        if (Application::isMSTP($pid)) {
            $defaultSurveys["mstp_individual_development_plan_idp"] = "MSTP Individual Development Plan IDP";
        }
        return $defaultSurveys;
    }

    public static function getSurveysAndLabels($metadata, $pid) {
        $surveysAndLabelsCandidates = self::getDefaultSurveysAndLabels($pid);
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
            "old_honors_and_awards" => "[honor_name]: [honor_date]",
            "honors_awards_and_activities" => "[activityhonor_name]: [activityhonor_datetime]",
            "honors_awards_and_activities_survey" => "[surveyactivityhonor_name]: [surveyactivityhonor_datetime]",
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
            $formsAndLabels["workday"] = "";
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

    public static function filterOutInvalidFieldsByFields($metadataFields, $metadataForms, $fields) {
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

    public static function filterOutInvalidFields($metadata, $fields) {
        if (empty($metadata)) {
            global $token, $server;
            $metadataFields = Download::metadataFields($token, $server);
            $metadataForms = Download::metadataForms($token, $server);
        } else {
            $metadataFields = self::getFieldsFromMetadata($metadata);
            $metadataForms = self::getFormsFromMetadata($metadata);
        }
        return self::filterOutInvalidFieldsByFields($metadataFields, $metadataForms, $fields);
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
        if ($instrument && !empty($fields)) {
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
        return ["required_field", "form_name", "identifier", "branching_logic", "field_annotation", "text_validation_type_or_show_slider_number", "select_choices_or_calculations"];
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
            } else if (MMAHelper::canConfigureCustomAgreement($pid) && ($form == "mentoring_agreement")) {
                $originalMentoringFields = self::getFieldsFromMetadata($originalMetadata, $form);
                self::addFields($mergedMetadata, $originalMetadata, $originalMentoringFields);
            } else {
                $originalFields = $originalFormsAndFields[$form];
                $mergedOrderForForm = self::mergeFields($fileFields, $originalFields);
                $firstIndex = count($mergedMetadata);
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
                self::eliminateDuplicateSectionHeadersOnForm($mergedMetadata, $firstIndex, $mergedOrderForForm, $fileFields, $indexedFileMetadata);
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
        $upload = self::alterOptionalFields($mergedMetadata, $pid);
        self::alterResourcesFields($mergedMetadata, $pid);
        self::alterInstitutionFields($mergedMetadata, $pid);
        self::alterDepartmentsFields($mergedMetadata, $pid);
        self::updateAlumniAssociations($mergedMetadata, $originalMetadata, $pid);
        unset($_SESSION['metadata'.$pid]);
        $feedback = Upload::metadata($mergedMetadata, $token, $server);
        if (!empty($upload)) {
            Upload::rowsByPid($upload, $pid);
        }
        return $feedback;
    }

    public static function updateAlumniAssociations(&$metadata, $originalMetadata, $pid) {
        $alumniURL = "https://redcap.vumc.org/plugins/career_dev/getAlumniAssociations.php?NOAUTH";
        list($resp, $alumniJSON) = URLManagement::downloadURL($alumniURL, $pid);
        $indexedOriginalMetadata = self::indexMetadata($originalMetadata);
        $nativeAlumniAssociations = json_decode($alumniJSON ?: "[]", TRUE);
        $alumniAssociations = [];
        foreach ($nativeAlumniAssociations as $url => $group) {
            $index = preg_replace("/[^a-zA-z0-9_]/", "", $url);
            $alumniAssociations[$index] = "$group ($url)";
        }
        $alumniAssociations[self::ALUMNI_OTHER_VALUE] = "Other";
        $choiceStr = self::makeChoiceStr($alumniAssociations);
        if (($resp == 200) && $alumniJSON && REDCapManagement::isJSON($alumniJSON) && $choiceStr) {
            foreach ($metadata as $i => $row) {
                if (self::isAlumniAssociationField($row['field_name']) && ($row['field_type'] == "dropdown")) {
                    $row['select_choices_or_calculations'] = $choiceStr;
                    $metadata[$i] = $row;
                }
            }
        } else {
            # keep old values
            foreach ($metadata as $i => $row) {
                if (self::isAlumniAssociationField($row['field_name']) && ($row['field_type'] == "dropdown")) {
                    $row['select_choices_or_calculations'] = $indexedOriginalMetadata[$row['field_name']]["select_choices_or_calculations"];
                    $metadata[$i] = $row;
                }
            }
        }
    }

    private static function isAlumniAssociationField($fieldName) {
        return preg_match("/alumni_assoc_\d$/", $fieldName);
    }

    private static function eliminateDuplicateSectionHeadersOnForm(&$mergedMetadata, $firstIndexForForm, $mergedOrderForForm, $fileFields, $indexedFileMetadata){
        $seenFields = [];
        foreach ($mergedOrderForForm as $mergedFieldIndex => $mergedFieldName) {
            $outerMergedMetadataIndex = $firstIndexForForm + $mergedFieldIndex;
            $seenFields[] = $mergedFieldName;
            $foundDuplicateSectionHeader = FALSE;
            foreach ($fileFields as $fileField) {
                if (
                    ($mergedFieldName != $fileField)
                    && !in_array($fileField, $seenFields)
                    && ($mergedMetadata[$outerMergedMetadataIndex]['section_header'] !== "")
                    && ($mergedMetadata[$outerMergedMetadataIndex]['section_header'] == $indexedFileMetadata[$fileField]['section_header'])
                ) {
                    $foundDuplicateSectionHeader = TRUE;
                    # duplicate section headers on same form ==> Remove second one
                    foreach ($mergedMetadata as $innerMergedMetadataIndex => $row) {
                        if ($row['field_name'] == $fileField) {
                            $mergedMetadata[$innerMergedMetadataIndex]['section_header'] = "";
                            break;
                        }
                    }
                }
                if ($foundDuplicateSectionHeader) {
                    break;
                }
            }
        }
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
                if ($baselineField == $newFields[$newIndex] ?? "") {
                    $newIndex++;
                }
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
                $upload = self::alterOptionalFields($metadata, $pid);
                self::alterResourcesFields($metadata, $pid);
                self::alterInstitutionFields($metadata, $pid);
                unset($_SESSION['metadata'.$pid]);
                $feedback = Upload::metadata($metadata, $token, $server);
                if (!empty($upload)) {
                    Upload::rowsByPid($upload, $pid);
                }
                return $feedback;
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
        $upload = self::alterOptionalFields($existingMetadata, $pid);
        self::alterResourcesFields($existingMetadata, $pid);
        self::alterInstitutionFields($existingMetadata, $pid);
        self::alterDepartmentsFields($existingMetadata, $pid);
        self::checkForImproperDeletions($existingMetadata, $originalMetadata, $fieldsToDelete, $pid);
        unset($_SESSION['metadata'.$pid]);
        $feedback = Upload::metadata($existingMetadata, $token, $server);
        if (!empty($upload)) {
            Upload::rowsByPid($upload, $pid);
        }
        return $feedback;
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

    private static function insertNewOptionalRow(&$metadata, $field) {
        $files = Application::getMetadataFiles();
        foreach ($files as $file) {
            $fileMetadata = self::readMetadataFile($file);
            $indexedFileMetadata = self::indexMetadata($fileMetadata);
            if (isset($indexedFileMetadata[$field])) {
                $newRow = $indexedFileMetadata[$field];
                $fieldsInOrder = array_keys($indexedFileMetadata);
                $prevField = $fieldsInOrder[0];
                foreach ($fieldsInOrder as $orderedField) {
                    if ($orderedField == $field) {
                        break;
                    } else {
                        $prevField = $orderedField;
                    }
                }
                $newMetadata = [];
                foreach ($metadata as $row) {
                    $newMetadata[] = $row;
                    if ($row['field_name'] == $prevField) {
                        $newMetadata[] = $newRow;
                    }
                }
                $metadata = $newMetadata;
            }
        }
    }

    private static function alterOptionalFields(&$metadata, $pid) {
        $allUpload = [];
        if ($pid) {
            $metadataFields = self::getFieldsFromMetadata($metadata);
            $relevantFields = REDCapManagement::getSpecialFields("optional", $metadata);
            $priorChoices = DataDictionaryManagement::getChoices($metadata);
            foreach ($relevantFields as $field) {
                $settingName = REDCapManagement::turnOptionalFieldIntoSetting($field);
                $optionsText = Application::getSetting($settingName, $pid);
                if ($optionsText) {
                    $priorFieldChoices = $priorChoices[$field] ?? [];
                    list($choiceStr, $upload) = self::makeREDCapList($optionsText, self::getOptionalOtherItem($field), $priorFieldChoices, $pid, $field);
                    $allUpload = array_merge($allUpload, $upload);
                    Application::log("Using optional field $field $choiceStr", $pid);
                    if (!in_array($field, $metadataFields)) {
                        self::insertNewOptionalRow($metadata, $field);
                    }
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
        return $allUpload;
    }

    private static function alterInstitutionFields(&$metadata, $pid) {
        if ($pid) {
            $institutions = Application::getInstitutions($pid, FALSE);
            if (empty($institutions)) {
                $institutions = ["Home Institution"];
            }
            $relevantFields = REDCapManagement::getSpecialFields("institutions", $metadata);
            list($choiceStr, $upload) = self::makeREDCapList(implode("\n", $institutions), self::INSTITUTION_OTHER_VALUE);
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
                list($choiceStr, $upload) = self::makeREDCapList($departments, self::DEPARTMENT_OTHER_VALUE);
                self::setSelectStringForFields($metadata, $choiceStr, $fields);
            }
        }
    }

    # gets from the database
    # if database value doesn't exist, supplies default for Vanderbilt and blank for everyone else
    private static function getSavedResourceChoiceStr($blankSetup, $pid) {
        $resourceList = Application::getSetting("resources", $pid);
        if (trim($resourceList)) {
            $resource1DAry = explode("\n", trim($resourceList));
            $resourcesWithIndex = [];
            $idx = 1;
            foreach ($resource1DAry as $resource) {
                $resourcesWithIndex[$idx] = $resource;
                $idx++;
            }
            $resourceStr = self::makeChoiceStr($resourcesWithIndex);
        } else if (Application::isVanderbilt()) {
            $resourceStr = self::makeChoiceStr(self::getMenteeAgreementVanderbiltResources());
        } else {
            $resourceStr = self::makeChoiceStr($blankSetup);
        }
        return $resourceStr;
    }

    # restores the resources field's choices to a working value
    # metadata won't upload without them
    # have to be careful that good values aren't overwritten
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
                } else if (!self::isInitialSetupForResources($choices[$defaultResourceField])) {
                    $resourceStr = self::makeChoiceStr($choices[$defaultResourceField]);
                } else {
                    $resourceStr = self::getSavedResourceChoiceStr($blankSetup, $pid);
                }
            } else {
                $resourceStr = self::makeChoiceStr($blankSetup);
            }
        } else if ($pid) {
            $resourceStr = self::getSavedResourceChoiceStr($blankSetup, $pid);
        } else if (!empty($choices[$defaultResourceField])) {
            $resourceStr = self::makeChoiceStr($choices[$defaultResourceField]);
        } else if (!empty($choices[$mentoringResourceField])) {
            $resourceStr = self::makeChoiceStr($choices[$mentoringResourceField]);
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
        if (!method_exists($module, "query") || !class_exists("\REDCap")) {
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
            $currentInstruments = \REDCap::getInstrumentNames(NULL, $pid);
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
        $hash = [
            "promotion" => "position_change_complete",
            "check" =>"initial_survey_complete",
            "custom" => "custom_grant_complete",
            "imported_degree" => "manual_degree_complete",
            "coeussubmission" => "coeus_submission_complete",
            "verasubmission" => "vera_submission_complete",
            "mentoring" => "mentoring_agreement_complete",
            "mentoringeval" => "mentoring_agreement_evaluations_complete",
            "vfrs" => "pre_screening_survey_complete",
            "honor" => "old_honors_and_awards_complete",
            "activityhonor" => "honors_awards_and_activities",
            "surveyactivityhonor" => "honors_awards_and_activities_survey_complete",
        ];
        return $hash[$prefix] ?? "";
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

    public static function isMetadataFieldsFilled($metadataFields) {
        if (count($metadataFields) < 10) {
            return FALSE;
        }
        if ($metadataFields[0] != "record_id") {
            return FALSE;
        }
        return TRUE;
    }

    public static function isCompletionField($field) {
        return preg_match("/_complete$/", $field);
    }
}