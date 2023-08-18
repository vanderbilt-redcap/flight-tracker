<?php

namespace Vanderbilt\CareerDevLibrary;

use PhpOffice\PhpSpreadsheet\Shared\Date;

require_once(__DIR__ . '/ClassLoader.php');

class CelebrationsEmail {
    const CONFIG_SETTING = "celebrations_config";
    const EMAIL_SETTING = "email_highlights_to";
    const OLD_FREQUENCY_SETTING = "highlights_frequency";
    const OLD_GRANTS_SETTING = "requested_grants";
    const OLD_SCOPE_SETTING = "highlights_scholar_scope";
    const PMID_SETTING = "high_performing_pmids";
    const RECENT_YEARS = 5;
    const JOURNAL_PROJECT = 168378;
    const VALID_GRANT_STATUSES = ["New", "Renewal", "Revision"];


    public function __construct($token, $server, $pid, $metadata = NULL) {
        $this->pid = $pid;
        if (!REDCapManagement::isActiveProject($this->pid)) {
            throw new \Exception("Invalid project!");
        }
        $this->config = Application::getSetting(self::CONFIG_SETTING, $this->pid) ?: [];
        $this->email = Application::getSetting(self::EMAIL_SETTING, $this->pid);
        $this->token = $token;
        $this->server = $server;
        $this->citationRecordsAndInstances = [];
        $this->grantRecordsAndInstances = [];
        $this->allPMIDsIdentified = [];
        $this->scholarsIdentifiedForPubs = [];
        $this->pmidsIdentified = [];

        $this->twitterHandles = Download::oneField($this->token, $this->server, "identifier_twitter");
        $this->linkedInHandles = Download::oneField($this->token, $this->server, "identifier_linkedin");
        $this->activePids = Application::getPids();
        $this->grantInstrumentsAndPrefices = [
            "nih_reporter" => "nih",
            "nsf" => "nsf",
            "coeus" => "coeus",
            "vera" => "vera",
            "custom_grant" => "custom",
            "ies_grant" => "ies",
        ];

        if (!$this->email) {
            return;
        }
        if ($metadata === NULL) {
            $this->metadata = Download::metadata($this->token, $this->server);
        } else {
            $this->metadata = $metadata;
        }
        if (empty($this->config)) {
            $scholarScope = Application::getSetting(self::OLD_SCOPE_SETTING, $this->pid) ?: "all";
            $grants = Application::getSetting(self::OLD_GRANTS_SETTING, $this->pid) ?: "";
            $frequency = Application::getSetting(self::OLD_FREQUENCY_SETTING, $this->pid) ?: "weekly";

            $this->config["New Grants"] = [
                "who" => $scholarScope,
                "when" => $frequency,
                "what" => "Grants",
                "scope" => "New",
            ];
            $this->config["All New Publications"] = [
                "who" => $scholarScope,
                "when" => $frequency,
                "what" => "Publications",
                "scope" => "All",
                "grants" => $grants,
            ];
            $this->config["New High-Impact Publications"] = [
                "who" => $scholarScope,
                "when" => $frequency,
                "what" => "Publications",
                "scope" => "High-Impact",
                "grants" => $grants,
            ];
            $this->saveConfig();
        }
    }

    public function hasEmail($frequency) {
        foreach ($this->config as $name => $setting) {
            if ($setting['when'] == $frequency) {
                return TRUE;
            }
        }
        return FALSE;
    }

    public function getEmail() {
        return $this->email;
    }

    public function saveEmail($email) {
        if ($email === "") {
            $this->email = $email;
            Application::saveSetting(self::EMAIL_SETTING, "", $this->pid);
        } else if (REDCapManagement::isEmailOrEmails($email)) {
            $this->email = $email;
            Application::saveSetting(self::EMAIL_SETTING, $email, $this->pid);
        }
    }

    # return list($numDaysToHighlight, $numDaysWithoutWarning)
    private function getNumDays($frequency) {
        if ($frequency == "weekly") {
            return [14, 7];
        } else if ($frequency == "monthly") {
            return [60, 31];
        }
        return [0, 0];
    }

    private function getRelevantFields() {
        $fields = ["record_id", "citation_authors", "citation_pmid", "citation_ts", "citation_include", "citation_rcr", "citation_altmetric_score",];
        foreach(array_values($this->grantInstrumentsAndPrefices) as $prefix) {
            $fields[] = $prefix."_last_update";
            $fields[] = $prefix."_created";
        }
        $fields = array_unique($fields);
        return DataDictionaryManagement::filterOutInvalidFields($this->metadata, $fields);
    }

    private function process() {
        $fields = $this->getRelevantFields();
        $this->scholarsIdentifiedForPubs = [];
        $allHighPerformingPMIDs = Application::getSetting(self::PMID_SETTING, $this->pid) ?: [];
        self::resetSettings();

        $records = Download::recordIds($this->token, $this->server);
        foreach ($this->config as $name => $setting) {
            $scholarScope = $setting["who"];
            $frequency = $setting["when"];
            list($thresholdTs, $warningTs) = $this->getThresholds($frequency);
            $highPerformingPMIDS = [];
            foreach ($allHighPerformingPMIDs as $date => $pmids) {
                $ts = strtotime($date);
                if ($ts >= $thresholdTs) {
                    $highPerformingPMIDS = array_unique(array_merge($highPerformingPMIDS, $pmids));
                }
            }

            $filteredRecords = $records;
            $this->filterForScholarScope($filteredRecords, $scholarScope);
            $this->filterForInstitution($filteredRecords);
            $validFields = DataDictionaryManagement::filterOutInvalidFields($this->metadata, $fields);
            $redcapData = Download::fields($this->token, $this->server, $validFields);
            $names = Download::names($this->token, $this->server);

            $this->allPMIDsIdentified[$name] = [];
            $this->scholarsIdentifiedForPubs[$name] = [];
            $this->pmidsIdentified[$name] = [];
            foreach ($redcapData as $row) {
                $recordId = $row['record_id'];
                if (
                    in_array($recordId, $filteredRecords)
                    && ($row['redcap_repeat_instrument'] == "citation")
                ) {
                    $instance = $row['redcap_repeat_instance'];
                    $dateOfPublication = $row['citation_ts'] ?? "";
                    $pmid = $row['citation_pmid'];
                    if (
                        ($row['citation_include'] !== "0")
                        && DateManagement::isDate($dateOfPublication)
                        && (strtotime($dateOfPublication) > $thresholdTs)
                        && (
                            NameMatcher::isFirstAuthor($names[$recordId], $row['citation_authors'])
                            || NameMatcher::isLastAuthor($names[$recordId], $row['citation_authors'])
                        )
                    ) {
                        if (!isset($this->allPMIDsIdentified[$name][$pmid])) {
                            $this->allPMIDsIdentified[$name][$pmid] = [];
                        }
                        $this->allPMIDsIdentified[$name][$pmid][] = $recordId;
                        $this->enrollNewInstance("citationRecordsAndInstances", $name, $recordId, $instance);
                    }

                    if (
                        ($row['citation_include'] !== "0")
                        && in_array($pmid, $highPerformingPMIDS)
                    ) {
                        if (!isset($this->scholarsIdentifiedForPubs[$name][$recordId])) {
                            $this->scholarsIdentifiedForPubs[$name][$recordId] = [];
                        }
                        $this->scholarsIdentifiedForPubs[$name][$recordId][] = $instance;
                        if (!isset($this->pmidsIdentified[$name][$pmid])) {
                            $this->pmidsIdentified[$name][$pmid] = [];
                        }
                        $this->pmidsIdentified[$name][$pmid][] = $recordId;
                    }
                } else if (
                    isset($this->grantInstrumentsAndPrefices[$row['redcap_repeat_instrument']])
                    && in_array($recordId, $filteredRecords)
                ) {
                    $instrument = $row['redcap_repeat_instrument'];
                    $prefix = $this->grantInstrumentsAndPrefices[$instrument];
                    $instance = $row['redcap_repeat_instance'];
                    $lastUpdate = $row[$prefix.'_last_update'];
                    $dateCreated = $row[$prefix.'_created'] ?? "";
                    if (
                        (
                            $dateCreated
                            && (strtotime($dateCreated) > $warningTs)
                        )
                        || (
                            !$dateCreated
                            && $lastUpdate
                            && (strtotime($lastUpdate) > $warningTs)
                        )
                    ) {
                        $this->enrollNewInstance("grantRecordsAndInstances", $name, $recordId, $instance, $instrument);
                    }
                }
            }
        }
    }

    private function processCitations($redcapData, $warningTs, $currBios, $requestedGrants, $pmidsIdentified) {
        $emails = Download::emails($this->token, $this->server);
        $names = Download::names($this->token, $this->server);
        $htmlRows = [];
        $translate = Citation::getJournalTranslations();
        foreach ($redcapData as $row) {
            $recordId = $row['record_id'];
            if (
                ($row['redcap_repeat_instrument'] == "citation")
                && isset($this->citationRecordsAndInstances[$recordId])
                && in_array($row['redcap_repeat_instance'], $this->citationRecordsAndInstances[$recordId])
            ) {
                $pmid = $row['citation_pmid'];
                $altmetric = $row['citation_altmetric_details_url'] ? " <a href='{$row['citation_altmetric_details_url']}'>Altmetric</a>" : "";
                $matchedNames = [];
                $namesWithLink = [];
                $handles = [];
                $journalHTML = "<div><i>".$row['citation_journal']."</i>";
                $journal = $row['citation_journal'];
                $journalFullName = $translate[$journal] ?? "";
                if ($journalFullName) {
                    $journalHTML .= " - $journalFullName";
                }
                if (Application::isVanderbilt() && !Application::isLocalhost()) {
                    $journalPid = self::JOURNAL_PROJECT;
                    $journalData = \REDCap::getData($journalPid, "json-array");
                    $journalHandles = [];
                    $journalInLC = trim(strtolower($journal));
                    $journalFullNameInLC = trim(strtolower($journalFullName));
                    foreach ($journalData as $journalRow) {
                        if (
                            ($journalInLC == trim(strtolower($journalRow['abbreviation'])))
                            || ($journalInLC == trim(strtolower($journalRow['name'])))
                            || ($journalFullNameInLC == trim(strtolower($journalRow['abbreviation'])))
                            || ($journalFullNameInLC == trim(strtolower($journalRow['name'])))
                        ) {
                            $journalHandles[] = $journalRow['handle'];
                        }
                    }
                    $journalHTML = empty($journalHandles) ? $journalHTML." (<a href='https://redcap.vanderbilt.edu/surveys/?s=D94RMNA3AT94CXTP'>add new journal handle?</a>)" : $journalHTML." (".implode(", ", $journalHandles).")";
                }
                $journalHTML .= "</div>";
                foreach ($pmidsIdentified[$pmid] as $matchRecordId) {
                    foreach ([$this->twitterHandles, $this->linkedInHandles] as $handleData) {
                        if ($handleData[$matchRecordId]) {
                            $handles = array_unique(array_merge($handles, preg_split("/\s*,\s*/", $handleData[$matchRecordId])));
                        }
                    }
                    if (
                        isset($names[$matchRecordId])
                        && $names[$matchRecordId]
                        && !in_array($names[$matchRecordId], $matchedNames)
                    ) {
                        $name = $names[$matchRecordId];
                        if (isset($emails[$matchRecordId])) {
                            $email = $emails[$matchRecordId];
                            $nameWithLink = "$name <a href='mailto:$email'>$email</a>";
                        } else {
                            $nameWithLink = $name;
                        }
                        $matchedNames[] = $name;
                        $namesWithLink[] = $nameWithLink;
                    }
                }
                $scholarProfile = " ".Links::makeProfileLink($this->pid, "Scholar Profile", $recordId);
                $citation = new Citation($this->token, $this->server, $recordId, $row['redcap_repeat_instance'], $row, $this->metadata);
                $citationStr = $citation->getCitationWithLink().$altmetric.$scholarProfile;
                $handleHTML = empty($handles) ? "" : "<div>Individual Handles: ".implode(", ", $handles)."</div>";

                $warningHTML = "";
                if (strtotime($row['citation_ts']) < $warningTs) {
                    $warningHTML = "<div class='redtext'><strong>This citation may have been included on the last email!</strong></div>";
                }

                $pictureHTML = "";
                foreach ($matchedNames as $matchedName) {
                    $edocs = Download::nonBlankFileFieldsFromProjects($this->activePids, $matchedName, "identifier_picture");
                    if (!empty($edocs)) {
                        foreach ($edocs as $source => $edocId) {
                            $base64 = FileManagement::getEdocBase64($edocId);
                            $pictureHTML .= "<div><img src='$base64' alt='$matchedName' style='max-width: 300px; max-height: 300px; width: auto; height: auto;' /> $matchedName</div>";
                        }
                    }
                }
                if ($pictureHTML === "") {
                    $pictureHTML = "<div>".Links::makeUploadPictureLink($this->pid, "Upload Picture", $recordId)."</div>";
                }

                $bio = $currBios[$recordId] ? $currBios[$recordId]."<br/>" : "";
                $citedGrants = $citation->getGrantBaseAwardNumbers();

                $include = empty($requestedGrants);
                foreach ($requestedGrants as $grant) {
                    if (in_array($grant, $citedGrants)) {
                        $include = TRUE;
                    }
                }
                if ($include) {
                    $htmlRows[] = "<h3>".implode(", ", $namesWithLink)."</h3>$bio$warningHTML<p>$citationStr</p>$handleHTML$journalHTML$pictureHTML<hr/>";
                }
            }
        }
        return $htmlRows;
    }

    private function presentGrantDataInHTML($dataByName, $longDate) {
        if (empty($dataByName)) {
            return "<p>No new grants are present since $longDate.</p>";
        }
        $html = "";
        foreach ($dataByName as $personName => $rows) {
            list($first, $last) = NameMatcher::splitName($personName);
            $formattedName = NameMatcher::formatName($first, "", $last);
            $numRows = count($rows);
            $handles = [];
            $email = "";
            foreach ($rows as $row) {
                if (!$email && $row['email']) {
                    $email = $row['email'];
                }
                if ($row['twitter'] && !in_array($row['twitter'], $handles)) {
                    $handles[] = $row['twitter'];
                }
                if ($row['linkedIn']) {
                    $link = "<a href='{$row['linkedIn']}'>LinkedIn</a>";
                    if (!in_array($link, $handles)) {
                        $handles[] = $link;
                    }
                }
            }

            $formattedNameWithLink = $formattedName;
            if ($email) {
                $formattedNameWithLink = "$formattedName <a href='mailto:$email'>$email</a>";
            }

            $html .= "<h3>$formattedNameWithLink ($numRows) ".implode(", ", $handles)."</h3>";
            if ((count($rows) > 0) && isset($rows[0]['bio']) && $rows[0]['bio']) {
                $html .= "<p>".$rows[0]['bio']."</p>";
            }
            foreach ($rows as $row) {
                $budgetInfo = $row['totalBudget'] ? "<br/>For {$row['totalBudget']} total budget" : "";
                $institution = $row['institution'] ? "<br/>Awarded to {$row['institution']}" : "";
                $projectLink = Links::makeRecordHomeLink($row['pid'], $row['recordId'], $row['projectName']." Record ".$row['recordId']);
                $typeInfo = ($row['type'] != "N/A") ? " - ".$row['type'] : "";
                $lastUpdate = DateManagement::YMD2MDY($row['lastUpdate']);
                $pictures = "";
                foreach ($row['pictures'] ?? [] as $base64) {
                    $pictures .= "<br/><img src='$base64' alt='$formattedName' />";
                }
                $html .= "<p><strong>{$row['awardNo']} - {$row['role']}$typeInfo</strong><br/>From {$row['sponsor']}$institution$budgetInfo<br/>Budget Period: {$row['budgetDates']}<br/>Project Period: {$row['projectDates']}<br/>Title: {$row['title']}<br/>{$row['link']}<br/>$projectLink<br/>Last Updated: $lastUpdate$pictures</p>";
            }
            $html .= "<hr/>";
        }
        return $html;
    }

    private function enrollNewInstance($variable, $name, $recordId, $instance, $instrument = "") {
        $previous = $this->$variable;
        if (!isset($previous[$name])) {
            $previous[$name] = [];
        }
        if ($instrument) {
            if (!isset($previous[$name][$instrument])) {
                $previous[$name][$instrument] = [];
            }
            if (!isset($previous[$name][$instrument][$recordId])) {
                $previous[$name][$instrument][$recordId] = [];
            }
            $previous[$name][$instrument][$recordId][] = $instance;
        } else {
            if (!isset($previous[$name][$recordId])) {
                $previous[$name][$recordId] = [];
            }
            $previous[$name][$recordId][] = $instance;
        }
        $this->$variable = $previous;
    }


    private function makeBio($recordId, $userid, $currDepartments, $currRanks, $alumniAssocLinks, $resources, $currChoices) {
        $bioData = [];
        $foundLDAP = FALSE;
        if (Application::isVanderbilt() && $userid && !Application::isLocalhost()) {
            list($department, $rank) = LDAP::getDepartmentAndRank($userid);
            if ($department && $rank) {
                $bioData[] = "Department: ".$department;
                $bioData[] = "Academic Rank: ".$rank;
                $foundLDAP = TRUE;
            }
        }
        if (!empty($alumniAssocLinks)) {
            $links = [];
            foreach ($alumniAssocLinks as $url) {
                $domain = URLManagement::getDomain($url);
                $links[] = Links::makeLink($url, $domain);
            }
            $bioData[] = "Alumni Associations: ".implode(", ", $links);
        }
        if (!empty($resources)) {
            $resourcesUsed = [];
            foreach ($resources as $instance => $resourceIndex) {
                $label = $currChoices["resources_resource"][$resourceIndex];
                if (!in_array($label, $resourcesUsed)) {
                    $resourcesUsed[] = $label;
                }
            }
            $bioData[] = "Resources Used: ".implode(", ", $resourcesUsed);
        }
        if (!$foundLDAP) {
            $departmentValue = $currDepartments[$recordId] ?? "";
            $department = $currChoices["summary_primary_dept"][$departmentValue] ?? "";
            if ($department) {
                $bioData[] = "Department: ".$department;
            }
            $rankValue = $currRanks[$recordId] ?? "";
            $rank = $currChoices["summary_current_rank"][$rankValue] ?? "";
            if ($rank) {
                $bioData[] = "Academic Rank: ".$rank;
            }
        }
        return implode("; ", $bioData);
    }

    private function resetSettings() {
        Application::saveSetting(self::PMID_SETTING, [], $this->pid);
    }

    private function filterForScholarScope(&$records, $scholarScope) {
        if ($scholarScope == "all") {
            return;
        } else {
            $fields = [
                "record_id",
                "identifier_end_of_training",
            ];
            $redcapData = Download::fieldsForRecords($this->token, $this->server, $fields, $records);
            if ($scholarScope == "current") {
                $func = "isCurrentScholar";
            } else if ($scholarScope == "recent") {
                $func = "isCurrentOrRecent";
            } else if ($scholarScope == "alumni") {
                $func = "isAlumni";
            } else {
                throw new \Exception("Invalid Scholar Scope $scholarScope!");
            }
            $filteredRecords = [];
            foreach ($redcapData as $row) {
                if (self::$func($row)) {
                    $filteredRecords[] = $row['record_id'];
                }
            }
            $records = $filteredRecords;
        }
    }

    private static function isCurrentScholar($row){
        $thresholdTs = time();
        $endOfTraining = $row['identifier_end_of_training'] ?? "";
        return (!$endOfTraining || (strtotime($endOfTraining) >= $thresholdTs));
    }

    private static function isRecentScholar($row) {
        $recentYears = self::RECENT_YEARS;
        $thresholdTs = strtotime("-$recentYears year");
        $endOfTraining = $row['identifier_end_of_training'] ?? "";
        return ($endOfTraining && (strtotime($endOfTraining) > $thresholdTs));
    }

    private static function isCurrentOrRecent($row) {
        return self::isCurrentScholar($row) || self::isRecentScholar($row);
    }

    private static function isAlumni($row) {
        $thresholdTs = time();
        $endOfTraining = $row['identifier_end_of_training'] ?? "";
        return ($endOfTraining && (strtotime($endOfTraining) <= $thresholdTs));
    }

    private function filterForInstitution(&$records) {
        $filteredRecords = [];
        $institutionFields = [
            "record_id",
            "identifier_institution",
            "identifier_left_date",
        ];
        $institutionData = Download::fieldsForRecords($this->token, $this->server, $institutionFields, $records);
        foreach ($institutionData as $row) {
            $recordId = $row['record_id'];
            $scholar = new Scholar($this->token, $this->server, $this->metadata, $this->pid);
            $scholar->setRows([$row]);
            if (!$scholar->hasLeftInstitution()) {
                $filteredRecords[] = $recordId;
            }
        }
        $records = $filteredRecords;
    }


    private function getThresholds($frequency) {
        list($numDaysToHighlight, $numDaysWithoutWarning) = $this->getNumDays($frequency);
        $oneDay = 24 * 3600;
        $thresholdTs = time() - $numDaysToHighlight * $oneDay;
        $warningTs = time() - $numDaysWithoutWarning * $oneDay;
        return [$thresholdTs, $warningTs];
    }

    private function makeGrantData($name) {
        $dataByName = [];
        $currProjectName = Download::projectTitle($this->token, $this->server);
        $currResources = Download::oneFieldWithInstances($this->token, $this->server, "resources_resource");
        $currChoices = DataDictionaryManagement::getChoices($this->metadata);
        $currDepartments = Download::oneField($this->token, $this->server, "summary_primary_dept");
        $currRanks = Download::oneField($this->token, $this->server, "summary_current_rank");
        $currUserids = Download::userids($this->token, $this->server);
        $lexicalTranslator = new GrantLexicalTranslator($this->token, $this->server, Application::getModule(), $this->pid);

        $grantFields = REDCapManagement::getAllGrantFields($this->metadata);
        $allRequestedRecords = [];
        foreach ($this->grantRecordsAndInstances[$name] as $instrument => $recordsAndInstances) {
            $currRecords = array_keys($recordsAndInstances);
            $allRequestedRecords = array_unique(array_merge($currRecords, $allRequestedRecords));
        }
        $redcapData = Download::fieldsForRecords($this->token, $this->server, $grantFields, $allRequestedRecords);
        $lastNames = Download::lastnames($this->token, $this->server);
        $firstNames = Download::firstnames($this->token, $this->server);
        foreach ($this->grantRecordsAndInstances[$name] as $instrument => $recordsAndInstances) {
            foreach ($redcapData as $row) {
                $recordId = $row['record_id'];
                if (
                    isset($recordsAndInstances[$recordId])
                    && isset($lastNames[$recordId])
                    && ($lastNames[$recordId] !== "")
                    && in_array($row['redcap_repeat_instance'], $recordsAndInstances[$recordId])
                    && ($row['redcap_repeat_instrument'] == $instrument)
                ) {
                    $lastName = $lastNames[$recordId];
                    $firstName = $firstNames[$recordId] ?? "";
                    $formattedName = NameMatcher::formatName($firstName, "", $lastName);
                    $nameToList = $lastName.($firstName ? ", ".$firstName : "");
                    $grantFactories = GrantFactory::createFactoriesForRow($row, $formattedName, $lexicalTranslator, $this->metadata, $this->token, $this->server, $redcapData, "Awarded");
                    $currTs = time();
                    foreach ($grantFactories as $gf) {
                        $gf->processRow($row, $redcapData);
                        $grants = $gf->getGrants();
                        foreach ($grants as $grant) {
                            $awardNo = $grant->getVariable("original_award_number") ?: $grant->getNumber();
                            $applicationType = Grant::getApplicationType($awardNo);
                            $budgetDate = $grant->getVariable("start") ?: $grant->getVariable("end") ?: date("Y-m-d", 0);
                            $projectDate = $grant->getVariable("project_start") ?: $grant->getVariable("project_end") ?: date("Y-m-d", 0);
                            if (
                                (
                                    (strtotime($budgetDate) > $currTs)
                                    || (strtotime($projectDate) > $currTs)
                                )
                                && in_array($applicationType, array_merge([""], self::VALID_GRANT_STATUSES))
                            ) {
                                $dataRow = [];
                                $dataRow['pid'] = $this->pid;
                                $dataRow['projectName'] = $currProjectName;
                                $dataRow['recordId'] = $recordId;
                                $dataRow['name'] = $formattedName;
                                $dataRow['email'] = $emails[$recordId] ?? "";
                                $dataRow['awardNo'] = $awardNo;
                                $dataRow['institution'] = $grant->getVariable("institution");
                                $dataRow['type'] = $grant->getVariable("type");
                                $dataRow['budgetDates'] = DateManagement::YMD2MDY($grant->getVariable("start"))." - ".DateManagement::YMD2MDY($grant->getVariable("end"));
                                $dataRow['projectDates'] = DateManagement::YMD2MDY($grant->getVariable("project_start"))." - ".DateManagement::YMD2MDY($grant->getVariable("project_end"));
                                $dataRow['link'] = $grant->getVariable("link");
                                $dataRow['title'] = $grant->getVariable("title");
                                $dataRow['role'] = $grant->getVariable("role");
                                $dataRow['totalBudget'] = REDCapManagement::prettyMoney($grant->getVariable("budget"));
                                $dataRow['sponsor'] = $grant->getVariable("sponsor");
                                $dataRow['instrument'] = $instrument;
                                $dataRow['lastUpdate'] = $grant->getVariable("last_update");
                                $dataRow['twitter'] = $this->twitterHandles[$recordId] ?? "";
                                $dataRow['linkedIn'] = $this->linkedInHandles[$recordId] ?? "";
                                $dataRow['bio'] = $this->makeBio($recordId, $currUserids[$recordId] ?? "", $currDepartments, $currRanks, $alumniAssociations[$recordId] ?? [], $currResources[$recordId] ?? [], $currChoices);
                                $edocs = Download::nonBlankFileFieldsFromProjects($this->activePids, $formattedName, "identifier_picture");
                                if (!empty($edocs)) {
                                    $row['pictures'] = [];
                                    foreach ($edocs as $source => $edocId) {
                                        if (is_numeric($edocId)) {
                                            $row['pictures'][] = FileManagement::getEdocBase64($edocId);
                                        } else {
                                            throw new \Exception("Invalid EDoc ID $edocId in project $source");
                                        }
                                    }
                                }
                                if (in_array($dataRow['role'], ["PI", "Co-PI"])) {
                                    if (!isset($dataByName[$nameToList])) {
                                        $dataByName[$nameToList] = [];
                                    }
                                    $dataByName[$nameToList][] = $dataRow;
                                }
                            }
                        }
                    }
                }
            }
        }
        return $dataByName;
    }

    private static function getScholarTitle($scholarScope) {
        if ($scholarScope == "all") {
            return "All Scholars";
        } else if ($scholarScope == "current") {
            return "Current Scholars";
        } else if ($scholarScope == "recent") {
            return "Recent Graduates";
        } else if ($scholarScope == "alumni") {
            return "Alumni";
        } else {
            return "";
        }
    }

    public function getEmailHTML($frequency) {
        if (!$this->hasEmail($frequency)) {
            return "";
        }
        $this->process();
        $ftLogoBase64 = FileManagement::getBase64OfFile(__DIR__."/../img/flight_tracker_logo_medium_white_bg.png", "image/png");
        $projectInfo = Links::makeProjectHomeLink($this->pid, Download::projectTitle($this->token, $this->server));
        if (!Application::isVanderbilt() || ($this->pid !== NEWMAN_SOCIETY_PROJECT)) {
            $configureLink = Application::link("index.php", $this->pid)."#Celebrations_Email";
            $configureInfo = "<br/>".Links::makeLink($configureLink, "Configure Celebrations Email");
        } else {
            $configureInfo = "";
        }

        $html = "<style>
.redtext { color: #f0565d; }
h1 { background-color: #8dc63f; }
h2 { background-color: #d4d4eb; }
h3 { background-color: #e5f1d5; }
a { color: #5764ae; }
</style>";
        $html .= "<p><img src='$ftLogoBase64' alt='Flight Tracker for Scholars' /></p>";
        $html .= "<h1>Flight Tracker Celebrations Email</h1>";
        $html .= "<p>$projectInfo$configureInfo</p>";

        foreach ($this->config as $settingName => $setting) {
            $scholarTitle = self::getScholarTitle($setting["who"]);
            $settingFrequency = $setting["when"];
            if ($settingFrequency != $frequency) {
                continue;
            }
            $dataType = $setting["what"];
            $dataScope = $setting["scope"];
            $associatedGrants = $setting["grants"] ?? "";

            list($thresholdTs, $warningTs) = $this->getThresholds($frequency);
            $thresholdDateYMD = date("Y-m-d", $thresholdTs);
            $thresholdDateMDY = DateManagement::YMD2MDY($thresholdDateYMD);
            $longThresholdDate = DateManagement::YMD2LongDate($thresholdDateYMD);

            if (($dataType == "Grants") && ($dataScope == "New")) {
                $statuses = array_merge(self::VALID_GRANT_STATUSES, ["Unknown"]);
                $appTypes = REDCapManagement::makeConjunction($statuses, "or");
                $html .= "<h2>$settingName for $scholarTitle<br/>Grant Awards After $thresholdDateMDY</h2>";
                if (!empty($this->grantRecordsAndInstances)) {
                    $dataByName = $this->makeGrantData($settingName);
                    $html .= "<p>Application Types: $appTypes<br/><a href='https://www.era.nih.gov/files/Deciphering_NIH_Application.pdf'>Deciphering NIH Grant Numbers</a></p>";
                    $html .= $this->presentGrantDataInHTML($dataByName, $longThresholdDate);
                } else {
                    $html .= "<p>No new grants have been downloaded since $longThresholdDate, for the following Application Types: $appTypes</p>";
                }
            } else if ($dataType == "Publications") {
                if (!empty($this->scholarsIdentifiedForPubs[$settingName]) || !empty($this->citationRecordsAndInstances[$settingName])) {
                    $requestedGrants = $associatedGrants ? preg_split("/\s*[,;]\s*/", $associatedGrants) : [];
                    for ($i = 0; $i < count($requestedGrants); $i++) {
                        $requestedGrants[$i] = Grant::translateToBaseAwardNumber($requestedGrants[$i]);
                    }
                    $alumniAssociations = Download::alumniAssociations($this->token, $this->server);
                    $currChoices = DataDictionaryManagement::getChoices($this->metadata);
                    $currDepartments = Download::oneField($this->token, $this->server, "summary_primary_dept");
                    $currRanks = Download::oneField($this->token, $this->server, "summary_current_rank");
                    $currUserids = Download::userids($this->token, $this->server);
                    $currResources = Download::oneFieldWithInstances($this->token, $this->server, "resources_resource");
                    $citationFields = DataDictionaryManagement::getFieldsFromMetadata($this->metadata, "citation");
                    $citationFields[] = "record_id";

                    $caveat = !empty($requestedGrants) ? " associated with your requested grants (".REDCapManagement::makeConjunction($requestedGrants).")" : "";
                    $bios = [];
                    if ($dataScope == "All") {
                        $redcapData = [];
                        foreach ($this->citationRecordsAndInstances[$settingName] as $recordId => $instances) {
                            $recordData = Download::fieldsForRecordAndInstances($this->token, $this->server, $citationFields, $recordId, "citation", $instances);
                            $redcapData = array_merge($redcapData, $recordData);
                            $bios[$recordId] = $this->makeBio($recordId, $currUserids[$recordId] ?? "", $currDepartments, $currRanks, $alumniAssociations[$recordId] ?? [], $currResources[$recordId] ?? [], $currChoices);
                        }
                        $htmlRows = $this->processCitations($redcapData, $warningTs, $bios, $requestedGrants, $this->allPMIDsIdentified[$settingName]);
                        if (empty($htmlRows)) {
                            $htmlRows[] = "<p>No new publications$caveat have been published since $longThresholdDate.</p>";
                        }
                        $html .= "<h2>$settingName for $scholarTitle<br/>All Publications After $thresholdDateMDY</h2>";
                        if (!empty($requestedGrants)) {
                            $html .= "<p>For grants ".REDCapManagement::makeConjunction($requestedGrants)."</p>";
                        }
                        $html .= implode("", $htmlRows);
                    } else if ($dataScope == "High-Impact") {
                        $performanceREDCapData = [];
                        foreach ($this->scholarsIdentifiedForPubs[$settingName] as $recordId => $instances) {
                            $recordData = Download::fieldsForRecordAndInstances($this->token, $this->server, $citationFields, $recordId, "citation", $instances);
                            $performanceREDCapData = array_merge($performanceREDCapData, $recordData);
                            if (!isset($bios[$recordId])) {
                                $bios[$recordId] = $this->makeBio($recordId, $currUserids[$recordId] ?? "", $currDepartments, $currRanks, $alumniAssociations[$recordId] ?? [], $currResources[$recordId] ?? [], $currChoices);
                            }
                        }
                        $performanceRows = $this->processCitations($performanceREDCapData, $warningTs, $bios, $requestedGrants, $this->pmidsIdentified[$settingName]);
                        if (empty($performanceRows)) {
                            $performanceRows[] = "<p>No new publications$caveat have been designated high-performing since $thresholdDateMDY</p>";
                        }

                        $html .= "<h2>$settingName for $scholarTitle<br/>Publications With Newly Altmetric &gt; " . Altmetric::THRESHOLD_SCORE . " or RCR &gt; " . iCite::THRESHOLD_SCORE . "</h2>";
                        if (!empty($requestedGrants)) {
                            $html .= "<p>For grants ".REDCapManagement::makeConjunction($requestedGrants)."</p>";
                        }
                        $html .= implode("", $performanceRows);
                    }
                }
            }
        }
        return $html;
    }

    private function getUrl() {
        return Application::link("configEmail.php", $this->pid);
    }

    public function getConfigurationHTML() {
        $frequencyOptions = [
            "<option value='weekly' selected>Weekly</option>",
            "<option value='monthly'>Monthly</option>",
        ];
        $contentOptions = [
            "<option value='new_grants' selected>New Grants</option>",
            "<option value='all_pubs'>All New Publications</option>",
            "<option value='high_impact_pubs'>New High-Impact Publications</option>",
        ];
        $scholarScopeOptions = [
            "<option value='all' selected>All Scholars</option>",
            "<option value='current'>Current Trainees</option>",
            "<option value='recent'>Current Trainees & Recent Graduates</option>",
            "<option value='alumni'>Alumni</option>",
        ];
        $submitUrl = $this->getUrl();
        $configurationOptions = [
            "<label class='smaller bolded' for='setting_name'>Setting Name: </label><input type='text' id='setting_name' style='width: 200px; font-size: 14px;' placeholder='Email Setting Name' />",
            "<label class='smaller bolded' for='when'>Frequency: </label><select id='when' class='smaller'>".implode("", $frequencyOptions)."</select>",
            "<label class='smaller bolded' for='who'>Group: </label><select id='who' class='smaller'>".implode("", $scholarScopeOptions)."</select>",
            "<label class='smaller bolded' for='what'>Content: </label><select id='what' class='smaller'>".implode("", $contentOptions)."</select>",
            "<label class='smaller bolded' for='grants'>Restrict Pubs to Cited Grants: </label><input type='text' id='grants' style='width: 150px; font-size: 14px;' placeholder='Grant(s) Cited' />",
            "<span class='smaller'>(Leave blank for no restrictions.)</span>",
            "<button class='smaller' onclick='addCelebrationsSetting(\"$submitUrl\", $(\"#setting_name\").val(), $(\"#when option:selected\").val(), $(\"#who option:selected\").val(), $(\"#what option:selected\").val(), $(\"#grants\").val()); return false;'>Add Setting</button>",
        ];

        $html = "<div class='padded'>";
        $html .= "<p class='nomargin centered'><label for='celebrations_email' class='smaller bolded'>Comma-Separated Receipient Email Address(es) </label><input id='celebrations_email' type='text' value='{$this->email}' placeholder='Recipient Email(s)' style='width: 200px; font-size: 14px;' /> <button onclick='changeCelebrationsEmail(\"$submitUrl\", $(\"#celebrations_email\").val()); return false;'>Update Email</button></p>";
        $displayCSS = $this->email ? "" : " display: none;";
        $html .= "<table id='celebration_config' style='width: 100%; $displayCSS'><tbody><tr><td class='padded' style='width: 66%; text-align: center;'>";
        $html .= "<p>".implode("<br/>", $configurationOptions)."</p>";
        $html .= "</td><td class='padded' style='width: 33%; text-align: center;'>";
        $html .= "<p><strong>Scheduled Emails</strong></p>";
        $configDisplays = $this->makeConfigDisplays();
        if (empty($configDisplays)) {
            $html .= "<div>None Configured</div>";
        } else {
            $html .= implode("", $configDisplays);
        }
        $html .= "</td></tr></tbody></table>";
        $html .= "</div>";
        return $html;
    }

    private function makeConfigDisplays() {
        $submitUrl = $this->getUrl();
        $displays = [];
        foreach ($this->config as $name => $setting) {
            $id = REDCapManagement::makeHTMLId($name);
            $settingLabels = [];
            foreach ($setting as $item => $value) {
                if ($value === "") {
                    $value = "[Any Grant]";
                }
                $settingLabels[] = "<strong>".ucfirst($item)."</strong>: $value";
            }
            $settingLabels[] = "<button onclick='deleteCelebrationsSetting(\"$submitUrl\", \"$name\"); return false;' class='smaller'>Delete</button>";

            $html = "<div title='Click to Show' class='bolded' style='cursor: pointer; margin-top: 8px; margin-bottom: 8px; border-radius: 4px; background-color: #AAA;' onclick='$(\"#$id\").show();'>$name</div>";
            $html .= "<div id='$id' style='display: none; text-align: center;' class='smaller'>";
            $html .= implode("<br/>", $settingLabels);
            $html .= "</div>";
            $displays[] = $html;
        }
        return $displays;
    }

    public function deleteConfiguration($name) {
        if (isset($this->config[$name])) {
            unset($this->config[$name]);
            $this->saveConfig();
        }
    }

    public function addOrModifyConfiguration($name, $setting) {
        $this->config[$name] = $setting;
        $this->saveConfig();
    }

    private function saveConfig() {
        Application::saveSetting(self::CONFIG_SETTING, $this->config, $this->pid);
    }

    protected $pid;
    protected $config;
    protected $frequency;
    protected $email;
    protected $token;
    protected $server;
    protected $metadata;
    protected $grantRecordsAndInstances;
    protected $citationRecordsAndInstances;
    protected $scholarsIdentifiedForPubs;
    protected $grantInstrumentsAndPrefices;
    protected $twitterHandles;
    protected $linkedInHandles;
    protected $allPMIDsIdentified;
    protected $pmidsIdentified;
    protected $activePids;
}
