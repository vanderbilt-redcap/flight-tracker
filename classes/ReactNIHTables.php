<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/ClassLoader.php");

class ReactNIHTables {
    public function __construct($token, $server, $pid, $module = NULL) {
        $this->token = $token;
        $this->server = $server;
        $this->pid = $pid;
        if ($module) {
            $this->module = $module;
        } else {
            $this->module = Application::getModule();
        }
    }

    public static function transformToCamelCase($data, $keysNotToTransform = []) {
        $newData = [];
        foreach ($data as $row) {
            $newRow = [];
            foreach ($row as $field => $value) {
                if (!in_array($field, $keysNotToTransform)) {
                    $newRow[$field] = ucwords(strtolower($value));
                } else {
                    $newRow[$field] = $value;
                }
            }
            $newData[] = $newRow;
        }
        return $newData;
    }
    
    public function getTablesPrefix() {
        return "tables2-4";
    }

    public function makeScholarList($header, $id) {
        $id = REDCapManagement::makeHTMLId($id);
        $prefix = $this->getTablesPrefix();
        $setting = $prefix."_".$id;
        $value = Application::getSetting($setting, $this->pid);

        $html = "";
        $html .= "<h3>$header</h3>";
        $html .= "<p class='centered'><textarea name='$id' id='$id' style='width: 300px; height: 450px;'>$value</textarea></p>";
        return $html;
    }

    public function sendVerificationEmail($post, &$nihTables, $adminEmail) {
        $email = strtolower(Sanitizer::sanitize($post['email']));
        if (!REDCapManagement::isEmail($email)) {
            return ["error" => "Improper email"];
        }
        $name = Sanitizer::sanitize($post['name']);
        $dateOfReport = Sanitizer::sanitize($post['dateOfReport']);
        $pi = Sanitizer::sanitize($post['pi'] ?? "");
        $grantTitle = Sanitizer::sanitize($post['grantTitle'] ?? "");

        $grantIdentifyingInfo = "";
        if ($pi && $grantTitle) {
            $grantIdentifyingInfo = " ($grantTitle / $pi)";
        } else if ($pi) {
            $grantIdentifyingInfo = " ($pi)";
        } else if ($grantTitle) {
            $grantIdentifyingInfo = " ($grantTitle)";
        }

        $nihTables->addFaculty([$name], $dateOfReport);
        $tables = $this->getTablesToEdit();
        $dataRows = [];
        foreach ($tables as $tableNum) {
            $dataRows[$tableNum] = $nihTables->getData($tableNum);
        }
        $mssg = "<style>
p,th,td,h1,h2,h3,h4 { font-family: Arial, Helvetica, sans-serif; }
a { font-weight: bold; background-image: linear-gradient(45deg, #fff, #ddd); color: black; text-decoration: none; padding: 5px 20px; text-align: center; font-size: 24px; };
td,th { border: 0; padding: 3px; }
td.odd { background-color: #ffffff; }
td.even { background-color: #eeeeee; }
table { border-collapse: collapse; }
</style>";
        $mssg .= "<p>Dear $name,</p>";
        $mssg .= "<p>A training grant you are affiliated with$grantIdentifyingInfo is preparing its formal review to the NIH. Can you please verify or correct the following information we have for you?</p>";
        $mssg .= "<p>This information will be made part of an automated system for better reporting. Be aware that for efficiency's sake, this information will be securely stored in a database and be made accessible to others at ".Application::getInstitution($this->pid).".</p>";
        $mssg .= "<p>Thanks!</p>";
        $numRows = 0;
        $numTables = 0;
        foreach ($dataRows as $tableNum => $tableData) {
            if (count($tableData['data']) > 0) {
                $numRows += count($tableData['data']);
                $numTables++;
                $tableHeader = $tableData['title'];
                $headers = $tableData["headerList"];
                $mssg .= "<h2>$tableHeader</h2>";
                $mssg .= "<table style='text-align: center;'>";
                $mssg .= "<thead><tr>";
                foreach ($headers as $header) {
                    $mssg .= "<th>$header</th>";
                }
                $mssg .= "</tr></thead>";
                $mssg .= "<tbody>";
                $i = 0;
                foreach ($tableData['data'] as $row) {
                    $rowClass = ($i % 2 == 1) ? "odd" : "even";
                    $mssg .= "<tr>";
                    foreach ($headers as $header) {
                        $mssg .= "<td class='$rowClass'>".$row[$header]."</td>";
                    }
                    $mssg .= "</tr>";
                    $i++;
                }
                $mssg .= "</tbody>";
                $mssg .= "</table><br><br>";
            }
        }
        $data = [];
        if ($numRows > 0) {
            $thisLink = Application::link("this", $this->pid);
            $tableName = ($numTables == 1) ? "table" : "tables";
            $hash = $this->makeEmailHash($email);
            $yesLink = $thisLink."&confirm=".urlencode($email)."&hash=".urlencode($hash)."&date=".urlencode($dateOfReport);
            $noLink = $thisLink."&revise=".urlencode($email)."&hash=".urlencode($hash)."&date=".urlencode($dateOfReport);
            $mssg .= "<h3>Is <u>every entry</u> on the above $tableName correct?</h3>";
            $mssg .= "<p style='margin-top: 0;'>(If you answer No, you will be given a chance to correct or add individual entries.)</p>";
            $spacing = "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
            $mssg .= "<p><a href='$yesLink'>Yes</a>$spacing<a href='$noLink'>No</a></p>";
            $subject = "NIH Training Tables";
            // TODO \REDCap::email($email, $adminEmail, $subject, $mssg);
            \REDCap::email("scott.j.pearson@vumc.org", $adminEmail, "$email: $subject", $mssg);
            $data["mssg"] = "Email with $numTables $tableName sent to $email";
        } else {
            $data["mssg"] = "No data to send.";
        }
        return $data;
    }

    public function savePeople($post, &$nihTables) {
        $savePid = REDCapManagement::sanitize($post['pid']);
        $saveRecord = REDCapManagement::sanitize($post['record']);
        $allPids = Application::getPids();
        $dateOfReport = $post['dateOfReport'] ?? date("Y-m-d");
        $data = [];
        if (in_array($savePid, $allPids)) {
            $saveToken = Application::getSetting("token", $savePid);
            $saveServer = Application::getSetting("server", $savePid);
            if ($saveToken && $saveServer) {
                $records = Download::recordIds($saveToken, $saveServer);
                if (in_array($saveRecord, $records) || ($saveRecord === "")) {
                    $recordInstance = REDCapManagement::sanitize($post['recordInstance']);
                    $colWithoutSpacesOrHTML = REDCapManagement::sanitize($post['column']);
                    $value = REDCapManagement::sanitize($post['value']);
                    $tableNum = REDCapManagement::sanitize($post['tableNum']);
                    $headers = $nihTables->getHeaders($tableNum);
                    $col = "";
                    foreach ($headers as $header) {
                        $headerWithoutHTML = preg_replace("/<[^>]+>/", "", $header);
                        $headerWithoutSpacesAndHTML = preg_replace("/[\s#,\'\"\.\(\)]+/", "", $headerWithoutHTML);
                        if ($colWithoutSpacesOrHTML == $headerWithoutSpacesAndHTML) {
                            $col = $header;
                            break;
                        }
                    }
                    if ($col) {
                        $field = NIHTables::makeCountKey($tableNum, $saveRecord);
                        $settingsByDate = Application::getSetting($field, $savePid);
                        if (!$settingsByDate || !is_array($settingsByDate)) {
                            $settingsByDate = [];
                        }
                        if (!isset($settingsByDate[$dateOfReport])) {
                            $settingsByDate[$dateOfReport] = [];
                        }
                        if (!isset($settingsByDate[$dateOfReport][$recordInstance])) {
                            $settingsByDate[$dateOfReport][$recordInstance] = [];
                        }
                        $settingsByDate[$dateOfReport][$recordInstance][$col] = $value;
                        Application::saveSetting($field, $settingsByDate, $savePid);
                        $data["Result"] = "Saved.";
                    } else {
                        $data["error"] = "Could not locate column.";
                    }
                } else {
                    $data["error"] = "Could not find record";
                }
            } else {
                $data["error"] = "Could not find token";
            }
        } else {
            $data["error"] = "Could not find project";
        }
        return $data;
    }

    public function getSavedTableNames() {
        $allNames = Application::getSetting($this->allNamesField, $this->pid);
        if ($allNames) {
            return $allNames;
        }
        return [];
    }

    public function saveData($nihTables, $tableNum, $tableData, $name, $dateOfReport, $faculty, $grantTitle = "", $grantPI = "") {
        $allNames = Application::getSetting($this->allNamesField, $this->pid);
        if (!isset($allNames[$name])) {
            $allNames[$name] = [];
            ksort($allNames);
        }
        if (!in_array($tableNum, $allNames[$name])) {
            $allNames[$name][] = $tableNum;
            Application::saveSetting($this->allNamesField, $allNames, $this->pid);
        }
        $data = [
            "data" => $tableData,
            "headerList" => $nihTables->getHeaders($tableNum),
            "title" => NIHTables::getTableHeader($tableNum),
        ];
        Application::saveSetting(self::makeSaveTableKey($name, $tableNum), $data);
        Application::saveSetting(self::makeSaveTableKey($name, "date"), $dateOfReport);
        Application::saveSetting(self::makeSaveTableKey($name, "faculty"), $faculty);
        Application::saveSetting(self::makeSaveTableKey($name, "grantTitle"), $grantTitle);
        Application::saveSetting(self::makeSaveTableKey($name, "grantPI"), $grantPI);
        return ["Result" => "Saved."];
    }

    public function getProjectInfo($name) {
        return [
            "date" => Application::getSetting(self::makeSaveTableKey($name, "date") ?? date("Y-m-d")),
            "faculty" => Application::getSetting(self::makeSaveTableKey($name, "faculty") ?? []),
            "grantPI" => Application::getSetting(self::makeSaveTableKey($name, "grantPI") ?? ""),
            "grantTitle" => Application::getSetting(self::makeSaveTableKey($name, "grantTitle") ?? ""),
        ];
    }

    public function lookupRePORTER($post, $metadata, $dateOfReport) {
        $awardNo = REDCapManagement::sanitize($post['awardNo']);
        $date = REDCapManagement::sanitize($post['date']);
        $data = [];
        $reporterTypes = RePORTER::getTypes();
        if ($awardNo && $date) {
            foreach ($reporterTypes as $type) {
                $reporter = new RePORTER($this->pid, 1, $type);
                $awardData = $reporter->searchAward($awardNo);
                $newData = $this->processAwardData($awardData, $reporter, $metadata, $dateOfReport);
                if (!empty($newData)) {
                    $data = array_merge($data, $newData);
                    break;
                }
            }
        } else {
            $data["error"] = "No date or award number: POST: ".json_encode($post);
        }
        if (empty($data)) {
            $data["error"] = "No Award Data for ".implode(", ", $reporterTypes);
        }
        return $data;
    }

    public function processAwardData($awardData, $reporter, $metadata, $dateOfReport = "now", $shortenedVersion = FALSE) {
        $data = [];
        if (!empty($awardData)) {
            $maxInstance = 0;
            $grantsToFilterOut = [];
            $rows = $reporter->getUploadRows($maxInstance, $grantsToFilterOut);
            $grants = new Grants($this->token, $this->server, $metadata);
            $grants->setRows($rows);
            $grants->compileGrants("All");
            foreach ($grants->getGrants("latest") as $grant) {
                $startDate = $grant->getVariable("project_start");
                $endDate = $grant->getVariable("project_end");

                if ($this->isCurrent($startDate, $endDate, $dateOfReport)) {
                    $projectPeriod = REDCapManagement::YMD2MY($startDate)." - ".REDCapManagement::YMD2MY($endDate);
                    $pi = $grant->getVariable("person_name");
                    $title = $grant->getVariable("title");
                    if (!$title) {
                        $title = NIHTables::$NA;
                    }
                    $dataRow = [
                        "Grant Title" => $title,
                        "Award Number" => $grant->getNumber(),
                        "Project<br/>Period" => $projectPeriod,
                        "PD/PI" => $pi,
                    ];
                    if (!$shortenedVersion) {
                        $dataRow["Number of<br/>Predoctoral<br/>Positions"] = NIHTables::$NA;
                        $dataRow["Number of<br/>Postdoctoral<br/>Positions"] = NIHTables::$NA;
                        $dataRow["Number of<br/>Short-Term<br/>Positions"] = NIHTables::$NA;
                        $dataRow["Number of<br/>Participating<br/>Faculty (Number<br> Overlapping)"] = NIHTables::$NA;
                        $dataRow["Names of<br/>Overlapping<br/>Faculty"] = NIHTables::$NA;
                    }
                    $data[] = $dataRow;
                }
            }
        }
        return $data;
    }

    public function isCurrent($startDate, $endDate, $currDate = "now") {
        if ($startDate && $endDate) {
            $startTs = strtotime($startDate);
            $endTs = strtotime($endDate);
            if ($currDate == "now") {
                $currTs = time();
            } else {
                $currTs = strtotime($currDate);
            }
            if ($startTs && $endTs && $currTs) {
                return (($startTs <= $currTs) && ($endTs >= $currTs));
            }
        }
        return FALSE;
    }

    public function lookupValues($post) {
        $tableNum = REDCapManagement::sanitize($post['tableNum']);
        $queryItems = REDCapManagement::sanitizeArray($post['queryItems'] ?? []);
        $data = [];
        $today = date("Y-m-d");
        foreach ($queryItems as $ary) {
            $recordId = $ary['record'];
            $recordInstance = $ary['recordInstance'];
            $field = NIHTables::makeCountKey($tableNum, $recordId);
            $settingAry = Application::getSetting($field, $this->pid);
            if ($settingAry && isset($settingAry[$today][$recordInstance])) {
                if (!isset($data[$recordId])) {
                    $data[$recordId] = [];
                }
                $data[$recordId][$recordInstance] = $settingAry[$today][$recordInstance];
            }
        }
        return $data;
    }

    public function getTablesToEdit() {
        return [2, 4];
    }

    public function makeHTMLForNIHTableEdits($dateOfReport, $name, $email, $hash) {
        $tablesToShow = $this->getTablesToEdit();
        $thisUrl = Application::link("this", $this->pid)."&hash=".urlencode($hash)."&email=".urlencode($email);
        $metadata = Download::metadata($this->token, $this->server);
        $nihTables = new NIHTables($this->token, $this->server, $metadata);
        $nihTables->addFaculty([$name], $dateOfReport);
        $today = date("Y-m-d");

        $html = "<h1>An Update for Your NIH Tables is Requested</h1>";
        $html .= "<h2>About $name for Submission on ".REDCapManagement::YMD2MDY($dateOfReport)."</h2>";
        $html .= "<form method='POST' action='$thisUrl'>";
        $html .= Application::generateCSRFTokenHTML();
        $html .= "<input type='hidden' name='dateSubmitted' value='$today' />";
        foreach ($tablesToShow as $tableNum) {
            $html .= "<h3>Table $tableNum: ".NIHTables::getTableHeader($tableNum)."</h3>";
            $table = $nihTables->getHTML($tableNum);
            $html .= $table;
            $html .= "<h4>Do you have any requested changes for this table?<br>Please address any <span class='action_required'>red</span> items,<br>and make sure you click the Submit Changes button.</h4>";
            $html .= "<p class='centered'><textarea name='table_$tableNum' style='width: 600px; height: 150px;'></textarea></p>";
            $html .= "<br><br>";
        }
        $html .= "<p class='centered'><button>Submit Changes</button></p>";
        $html .= "</form>";
        return $html;
    }

    private static function makeSaveTableKey($tableName, $tableNum) {
        return "tablename____".$tableName."____".$tableNum;
    }

    public function getDataForTable($post, &$nihTables) {
        $tableNum = REDCapManagement::sanitize($post['tableNum']);
        $savedTableName = $post['savedTableName'] ? REDCapManagement::sanitize($post['savedTableName']) : "";
        $allNames = Application::getSetting($this->allNamesField, $this->pid);
        if ($savedTableName && isset($allNames[$savedTableName]) && in_array($tableNum, $allNames[$savedTableName])) {
            $data = Application::getSetting(self::makeSaveTableKey($savedTableName, $tableNum), $this->pid);
            $data['source'] = "Previously Saved";
            if ($data) {
                return $data;
            }
        }

        $dateOfReport = REDCapManagement::sanitize($post['dateOfReport']);
        if (in_array($tableNum, [1, "1I", "1II", 2, 3, 4])) {
            if (in_array($tableNum, $this->getTablesToEdit())) {
                $facultyList = REDCapManagement::sanitizeArray($post['faculty']);
                $nihTables->addFaculty($facultyList, $dateOfReport);
            } else if ($tableNum == 3) {
                $trainingGrants = REDCapManagement::sanitize($post['trainingGrants']);
                $nihTables->addTrainingGrants($trainingGrants, $dateOfReport);
            }
            $data = $nihTables->getData($tableNum);
            $data['source'] = "Newly Computed";
            return $data;
        } else {
            return ["error" => "Invalid tableNum $tableNum"];
        }
    }

    public function getConfirmationKey($email) {
        if ($email && REDCapManagement::isEmail($email)) {
            return "confirmation_date_$email";
        }
        return "";
    }

    public function saveConfirmationTimestamp($email) {
        if ($key = $this->getConfirmationKey($email)) {
            Application::saveSetting($key, time(), $this->pid);
            return ["mssg" => "Saved."];
        } else {
            return ["error" => "Invalid email."];
        }
    }

    public function getDatesOfLastVerification($post) {
        if (isset($post['emails'])) {
            $emails = REDCapManagement::sanitizeArray($post['emails']);
            $data = [];
            if (empty($emails)) {
                $data['error'] = "No emails specified.";
            }

            $keys = Application::getSettingKeys($this->pid);
            foreach ($emails as $email) {
                if (REDCapManagement::isEmail($email)) {
                    $key = $this->getConfirmationKey($email);
                    if (in_array($key, $keys)) {
                        if ($ts = Application::getSetting($key, $this->pid)) {
                            $data[$email] = date("m-d-Y H:i", $ts);
                        } else {
                            $data[$email] = "";
                        }
                    }
                } else {
                    $data["error"] = "Errors in process.";
                    if ($email) {
                        $data[$email] = "Invalid email.";
                    } else {
                        $data[$email] = "No email provided.";
                    }
                }
            }
        } else {
            $data = ["error" => "No emails requested."];
        }
        return $data;
    }

    public function getNameAssociatedWithEmail($email) {
        $email = strtolower($email);
        foreach (Application::getPids() as $pid) {
            if (REDCapManagement::isActiveProject($pid)) {
                $emails = Download::fastField($pid, "identifier_email");
                $firstNames = Download::fastField($pid, "identifier_first_name");
                $lastNames = Download::fastField($pid, "identifier_last_name");
                foreach ($emails as $recordId => $recordEmail) {
                    if ($recordEmail && (strtolower($recordEmail) == $email) && $firstNames[$recordId] && $lastNames[$recordId]) {
                        return $firstNames[$recordId]." ".$lastNames[$recordId];
                    }
                }
            }
        }
        return "";
    }

    public function getUseridsAndNameAssociatedWithEmail($email) {
        $email = strtolower($email);
        $allUserids = [];
        $name = "";
        foreach (Application::getPids() as $pid) {
            if (REDCapManagement::isActiveProject($pid)) {
                $emails = Download::fastField($pid, "identifier_email");
                if (Application::isPluginProject()) {
                    $useridField = "identifier_vunet";
                } else {
                    $useridField = "identifier_userid";
                }
                $userids = Download::fastField($pid, $useridField);
                $firstNames = Download::fastField($pid, "identifier_first_name");
                $lastNames = Download::fastField($pid, "identifier_last_name");
                foreach ($emails as $recordId => $recordEmail) {
                    if ($recordEmail && (strtolower($recordEmail) == $email) && ($userids[$recordId])) {
                        $name = $firstNames[$recordId]." ".$lastNames[$recordId];
                        $aryOfUserids = preg_split("/\s*[,;]\s*/", $userids[$recordId]);
                        foreach ($aryOfUserids as $u) {
                            if (!in_array($u, $allUserids)) {
                                $allUserids[] = $u;
                            }
                        }
                    }
                }
            }
        }
        return [$allUserids, $name];
    }

    public function hasUseridsAssociatedWithEmail($email) {
        $email = strtolower($email);
        foreach (Application::getPids() as $pid) {
            if (REDCapManagement::isActiveProject($pid)) {
                $emails = Download::fastField($pid, "identifier_email");
                if (Application::isPluginProject()) {
                    $useridField = "identifier_vunet";
                } else {
                    $useridField = "identifier_userid";
                }
                $userids = Download::fastField($pid, $useridField);
                foreach ($emails as $recordId => $recordEmail) {
                    if ($recordEmail && (strtolower($recordEmail) == $email) && ($userids[$recordId])) {
                        return TRUE;
                    }
                }
            }
        }
        return FALSE;
    }

    public function makeEmailHash($email) {
        $key = "email_".$email;
        $previousValue = Application::getSetting($key, $this->pid);
        if ($previousValue) {
            return $previousValue;
        }

        $hash = substr(md5($this->pid.":".$email), 0, 64);
        Application::saveSetting($key, $hash, $this->pid);
        return $hash;
    }

    public function getTable1_4Header() {
        $cssLink = Application::link("/css/career_dev.css", $this->pid);
        return "<link href='$cssLink' rel='stylesheet' />";
    }

    public function makeNotesKey($email) {
        return "table_notes_$email";
    }

    # returns array keyed by project header, then by date, then by table
    public function getNotesData($post) {
        $emails = REDCapManagement::sanitizeArray($post['emails']);
        if ($post['tableNum']) {
            $tableNums = [REDCapManagement::sanitize($post['tableNum'])];
        } else {
            $tableNums = $this->getTablesToEdit();
        }
        $allNotes = [];
        foreach (Application::getPids() as $pid) {
            if (REDCapManagement::isActiveProject($pid)) {
                $keys = Application::getSettingKeys($pid);
                $projectHeader = FALSE;
                foreach ($emails as $email) {
                    if (REDCapManagement::isEmail($email)) {
                        $key = $this->makeNotesKey($email);
                        if (in_array($key, $keys)) {
                            $name = $this->getNameAssociatedWithEmail($email);
                            $ary = Application::getSetting($key, $pid);   // keyed by date, then by table
                            $tableAry = $this->filterNotesByTables($ary, $tableNums);
                            if (is_array($tableAry) && !empty($tableAry)) {
                                $token = Application::getSetting("token", $pid);
                                $server = Application::getSetting("server", $pid);
                                $adminEmail = Application::getSetting("admin_email", $pid);
                                if ($token && $server) {
                                    if (!$projectHeader) {
                                        $projectHeader = $pid.": ".Download::projectTitle($token, $server);
                                        if ($adminEmail) {
                                            $projectHeader = "<a href='mailto:$adminEmail'>$projectHeader</a>";
                                        }
                                    }
                                    if (!isset($allNotes[$name])) {
                                        $allNotes[$name] = [];
                                    }
                                    if (!isset($allNotes[$name][$projectHeader])) {
                                        $allNotes[$name][$projectHeader] = [];
                                    }
                                    $allNotes[$name][$projectHeader] = $tableAry;
                                }
                            }
                        }
                    }
                }
            }
        }
        return $allNotes;
    }

    public function filterNotesByTables($ary, $tableNums) {
        $returnAry = [];
        foreach ($ary as $date => $notes) {
            foreach ($notes as $tableNum => $note) {
                if (in_array($tableNum, $tableNums)) {
                    if (!isset($returnAry[$date])) {
                        $returnAry[$date] = [];
                    }
                    $returnAry[$date][$tableNum] = $note;
                }
            }
        }
        return $returnAry;
    }

    public function saveNotes($post, $email) {
        $date = REDCapManagement::sanitize($post['dateSubmitted']);
        $notesKey = $this->makeNotesKey($email);
        $settings = Application::getSetting($notesKey, $this->pid);
        if (!$settings) {
            $settings = [];
        }
        $tableNotes = [];
        $metadata = Download::metadata($this->token, $this->server);
        $nihTables = new NIHTables($this->token, $this->server, $metadata);
        foreach ($this->getTablesToEdit() as $tableNum) {
            $notes = REDCapManagement::sanitizeWithoutChangingQuotes($post['table_'.$tableNum]);
            $notes = preg_replace("/[\n\r]{2}/", "<br>", $notes);
            $notes = preg_replace("/[\n\r]/", "<br>", $notes);
            $headers = $nihTables->getHeaders($tableNum);
            $matchesHeader = FALSE;
            $leadingRegex = "/^table_".$tableNum."___/";
            foreach ($post as $postKey => $value) {
                if (!$matchesHeader && preg_match($leadingRegex, $postKey) && ($value !== "")) {
                    $id = preg_replace($leadingRegex, "", $postKey);
                    foreach ($headers as $header) {
                        $headerId = REDCapManagement::makeHTMLId($header);
                        if ($id == $headerId) {
                            $matchesHeader = TRUE;
                            break;
                        }
                    }
                }
            }
            if ($matchesHeader) {
                $notes .= "<br>";
            }
            foreach ($post as $postKey => $value) {
                if (preg_match($leadingRegex, $postKey) && ($value !== "")) {
                    $id = preg_replace($leadingRegex, "", $postKey);
                    foreach ($headers as $header) {
                        $headerId = REDCapManagement::makeHTMLId($header);
                        if ($id == $headerId) {
                            $header = preg_replace("/<br>/", " ", $header);
                            $notes .= "<br>".$header.": ".$value;
                            break;
                        }
                    }
                }
            }
            if ($notes) {
                $tableNotes[$tableNum] = $notes;
            }
        }
        if (!empty($tableNotes)) {
            $settings[$date] = $tableNotes;
            Application::saveSetting($notesKey, $settings, $this->pid);
            $this->saveConfirmationTimestamp($email);
            return "Data saved. Thank you!";
        } else {
            return "No data to save.";
        }
    }

    public function lookupTrainingGrantsByInstitutionsInRePORTER($institutions, $metadata, $dateOfReport) {
        if (empty($institutions)) {
            return [];
        }
        $reporterTypes = RePORTER::getTypes();
        $data = [];
        $seenAwards = [];
        $trainingGrantAwardTypes = [
            "TL1",
            "R25",
            "T32",
            "T31",
            "K12",
            "KL2",
        ];
        foreach ($reporterTypes as $type) {
            $reporter = new RePORTER($this->pid, 1, $type);
            $awardData = $reporter->searchInstitutionsAndGrantTypes($institutions, $trainingGrantAwardTypes);
            $newData = $this->processAwardData($awardData, $reporter, $metadata, $dateOfReport, TRUE);
            foreach ($newData as $row) {
                $awardNo = $row["Award Number"];
                if (!in_array($awardNo, $seenAwards)) {
                    $seenAwards[] = $awardNo;
                    $data[] = $row;
                }
            }
        }
        return $data;
    }

    public function lookupFacultyInRePORTER($faculty, $metadata, $dateOfReport) {
        if (empty($faculty)) {
            return [];
        }
        $reporterTypes = RePORTER::getTypes();
        $institutions = Application::getInstitutions($this->pid);
        $data = [];
        foreach ($faculty as $name) {
            foreach ($reporterTypes as $type) {
                $reporter = new RePORTER($this->pid, 1, $type);
                $awardData = $reporter->searchPI($name, $institutions);
                $newData = $this->processAwardData($awardData, $reporter, $metadata, $dateOfReport, TRUE);
                if (!empty($newData)) {
                    $data = array_merge($data, $newData);
                    break; // inner
                }
            }
        }
        return $data;
    }

    public function findMatches($faculty, $dateOfReport, $nihTables) {
        $nihTables->addFaculty($faculty, $dateOfReport);
        return $nihTables->getFacultyMatches();
    }

    public function lookupOverlappingFaculty($awards) {
        $dataByPid = [];
        foreach (Application::getPids() as $pid) {
            if (REDCapManagement::isActiveProject($pid)) {
                foreach ($awards as $awardNo) {
                    $key = $this->makeOverlappingKey($awardNo);
                    $valueAry = Application::getSetting($key, $pid);
                    if (!$valueAry) {
                        $valueAry = [];
                    }
                    $dataByPid[$pid][$awardNo] = $valueAry;
                }
            }
        }
        return $dataByPid;
    }

    public function makeOverlappingKey($awardNo) {
        $baseAwardNo = Grant::translateToBaseAwardNumber($awardNo);
        return $baseAwardNo."___overlapping";
    }

    public function saveOverlappingFaculty($post) {
        $checkedNames = REDCapManagement::sanitizeArray($post['checkedNames']);
        $uncheckedNames = REDCapManagement::sanitizeArray($post['uncheckedNames']);
        $awardNo = REDCapManagement::sanitize($post['award']);
        $dateOfReport = REDCapManagement::sanitize($post['date']);
        $key = $this->makeOverlappingKey($awardNo);
        $valueAry = Application::getSetting($key, $this->pid);
        if (!$valueAry) {
            $valueAry = [];
        }
        $valueAry[$dateOfReport] = [
            "checkedNames" => $checkedNames,
            "uncheckedNames" => $uncheckedNames,
        ];
        Application::saveSetting($key, $valueAry, $this->pid);
        return ["Result" => "Saved."];
    }

    protected $token = "";
    protected $server = "";
    protected $pid = "";
    protected $module = NULL;
    protected $allNamesField = "all_names";
}
