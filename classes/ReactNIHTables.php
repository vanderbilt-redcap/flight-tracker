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

    public static function isAuthorized($userid, $userids) {
        return (
            (!empty($userids) && in_array($userid, $userids))
            || SUPER_USER
        );
    }

    public function loadModifications($data, $tableNumber, $nihTables) {
        $post = ["tableNum" => $tableNumber];
        $headers = $nihTables->getHeaders($tableNumber);
        if (in_array($tableNumber, [2, 4])) {
            $queryItems = [];
            foreach ($data['data'] as $row) {
                $queryItems[] = [
                    "record" => $row['record'],
                    "recordInstance" => $row['recordInstance'],
                    "name" => NIHTables::parseName($row[$headers[0]]),
                ];
            }
            $post['queryItems'] = $queryItems;
            $lookupValues = $this->lookupValuesFor2And4($post);
        } else if ($tableNumber == 3) {
            $queryItems = [];
            foreach ($data['data'] as $row) {
                $queryItems[] = [
                    "record" => $row['record'],
                    "recordInstance" => $row['recordInstance'],
                    "awardNo" => $row[$headers[1]],
                ];
            }
            $post['queryItems'] = $queryItems;
            $lookupValues = $this->lookupValuesFor3($post);
        } else {
            throw new \Exception("Invalid table");
        }

        $mods = $this->makeModificationsArray($lookupValues, $data['data'], $headers, $tableNumber);
        $data['data'] = $this->modifyTableData($data['data'], $headers, $mods, $tableNumber);
        return $data;
    }

    private function modifyTableData($data, $headers, $mods, $tableNum) {
        $newTableData = [];
        foreach ($data as $row) {
            $modifiedRow = self::transformIntoNonAssociativeArray($row, $headers);
            $rowTitle = NIHTables::getUniqueIdentifier($modifiedRow, $tableNum);
            if (isset($mods[$rowTitle])) {
                foreach ($headers as $header) {
                    if (isset($mods[$rowTitle][$header]) && $mods[$rowTitle][$header]) {
                        $row[$header] = $mods[$rowTitle][$header];
                    }
                }
            }
            $newTableData[] = $row;
        }
        return $newTableData;
    }

    private static function transformIntoNonAssociativeArray($row, $headers) {
        $modifiedRow = [];
        for ($i=0; $i < count($headers); $i++) {
            $modifiedRow[] = $row[$headers[$i]];
        }
        return $modifiedRow;
    }

    # coordinated with NIHTable -> loadModificationsForCSV in React
    private function makeModificationsArray($lookupValues, $data, $headers, $tableNum) {
        $mods = [];
        foreach ($lookupValues as $recordId => $instanceValues) {
            foreach ($instanceValues as $recordInstance => $dateValues) {
                for ($i = 0; $i < count($data); $i++) {
                    $row = $data[$i];
                    if ($row['recordInstance'] == $recordInstance) {
                        foreach ($dateValues as $date => $colValues) {
                            foreach ($colValues as $col => $val) {
                                if ($val !== $row[$col]) {
                                    $modifiedRow = self::transformIntoNonAssociativeArray($row, $headers);
                                    $rowTitle = NIHTables::getUniqueIdentifier($modifiedRow, $tableNum);
                                    if (!isset($mods[$rowTitle])) {
                                        $mods[$rowTitle] = [];
                                    }
                                    $mods[$rowTitle][$col] = $val;
                                }
                            }
                        }
                    }
                }
            }
        }
        return $mods;
    }

    public function sendVerificationEmail($post, &$nihTables) {
        $email = strtolower(Sanitizer::sanitize($post['email']));
        $scholarEmail = strtolower(Sanitizer::sanitize($post['scholarEmail']));
        if (!REDCapManagement::isEmail($email) || !REDCapManagement::isEmail($scholarEmail)) {
            return ["error" => "Improper email"];
        }
        $name = Sanitizer::sanitize($post['name'] ?? "");
        $savedName = Sanitizer::sanitize($post['savedName'] ?? "");
        $dateOfReport = Sanitizer::sanitize($post['dateOfReport']);
        $subject = Sanitizer::sanitizeWithoutChangingQuotes($post['subject'] ?? "NIH Training Tables");
        $from = Sanitizer::sanitize($post['from']);
        if (!REDCapManagement::isEmail($from)) {
            return ["error" => "Improper from email"];
        }
        $tableNum = Sanitizer::sanitize($post['tableNum']);

        $nihTables->addFaculty([$name], $dateOfReport);
        if ($tableNum == '3') {
            $tables = [3];
        } else {
            $tables = $this->getTablesToEdit();
        }
        $dataRows = [];
        foreach ($tables as $tableNumber) {
            $tableData = $nihTables->getData($tableNumber, $savedName);
            $tableData = $this->loadModifications($tableData, $tableNumber, $nihTables);
            $dataRows[$tableNumber] = $tableData;
        }
        $mssg = "<style>
p,th,td,h1,h2,h3,h4 { font-family: Arial, Helvetica, sans-serif; }
a { font-weight: bold; color: black; text-decoration: underline; }
a.button { font-weight: bold; background-image: linear-gradient(45deg, #fff, #ddd); color: black; text-decoration: none; padding: 5px 20px; text-align: center; font-size: 24px; };
td,th { border: 0; padding: 3px; }
td.odd { background-color: #ffffff; }
td.even { background-color: #eeeeee; }
table { border-collapse: collapse; }
</style>";
        $numRows = 0;
        $numTables = 0;
        # table 3 is made in the React layer
        $tableHTML = "";
        if ($tableNum != 3) {
            foreach ($dataRows as $tableNum => $tableData) {
                if (count($tableData['data']) > 0) {
                    $numRows += count($tableData['data']);
                    $numTables++;
                    $tableHeader = $tableData['title'];
                    $headers = $tableData["headerList"];
                    $tableHTML .= "<h2>$tableHeader</h2>";
                    $tableHTML .= "<table style='text-align: center;'>";
                    $tableHTML .= "<thead><tr>";
                    foreach ($headers as $header) {
                        $tableHTML .= "<th>$header</th>";
                    }
                    $tableHTML .= "</tr></thead>";
                    $tableHTML .= "<tbody>";
                    $i = 0;
                    foreach ($tableData['data'] as $row) {
                        $rowClass = ($i % 2 == 1) ? "odd" : "even";
                        $tableHTML .= "<tr>";
                        foreach ($headers as $header) {
                            $rowHTML = $row[$header];
                            $rowHTML = preg_replace("/<figure class=['\"]left-align['\"]>.+<\/figure>/", "", $rowHTML);
                            $tableHTML .= "<td class='$rowClass'>$rowHTML</td>";
                        }
                        $tableHTML .= "</tr>";
                        $i++;
                    }
                    $tableHTML .= "</tbody>";
                    $tableHTML .= "</table><br/><br/>";
                }
            }
        } else {
            $numTables = 1;
        }
        $stringsToReplace = [
            "[Relevant Table 2 &amp; 4 Rows]",
            "[Relevant Table 3 Rows]",
        ];
        $replacedTables = Sanitizer::sanitizeWithoutStrippingHTML($post['mssg'], FALSE);
        foreach ($stringsToReplace as $str) {
            $replacedTables = str_replace($str, $tableHTML, $replacedTables);
        }
        $mssg .= $replacedTables;
        $data = [];
        if (($numRows > 0) || ($tableNum == 3)) {
            $thisLink = Application::link("this", $this->pid);
            $tableName = ($numTables == 1) ? "table" : "tables";
            $hash = $this->makeEmailHash($scholarEmail, $tables);
            $yesLink = $thisLink."&confirm=".urlencode($scholarEmail)."&hash=".urlencode($hash)."&date=".urlencode($dateOfReport)."&savedName=".urlencode($savedName);
            $noLink = $thisLink."&revise=".urlencode($scholarEmail)."&hash=".urlencode($hash)."&date=".urlencode($dateOfReport)."&savedName=".urlencode($savedName);
            if ($scholarEmail != $email) {
                $noLink .= "&delegate";
                $yesLink .= "&delegate";
            }
            $mssg .= "<h3>Is <u>every entry</u> on the above $tableName correct?</h3>";
            $mssg .= "<p style='margin-top: 0;'>(If you answer No, you will be given a chance to correct or add individual entries.)</p>";
            $spacing = "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
            $mssg .= "<p><a class='button' href='$yesLink'>Yes</a>$spacing<a class='button' href='$noLink'>No</a></p>";
            Application::log("Sending email to $email: ".$mssg, $this->pid);
            \REDCap::email($email, $from, $subject, $mssg);
            $data["mssg"] = "Email with $numTables $tableName sent to $email";
        } else {
            $data["mssg"] = "No data to send for table(s) ".implode(", ", $tables);
        }
        return $data;
    }

    public function savePeople($post, &$nihTables) {
        $savePid = Sanitizer::sanitize($post['pid']);
        $saveRecord = Sanitizer::sanitize($post['record'] ?? "");
        $awardNo = Sanitizer::sanitize($post['awardNo'] ?? "");
        if ($saveRecord) {
            $keyForRow = $saveRecord;
        } else if ($awardNo) {
            $keyForRow = $awardNo;
        } else {
            $keyForRow = "";
        }
        $allPids = Application::getPids();
        $dateOfReport = $post['dateOfReport'] ?? date("Y-m-d");
        $data = [];
        if (in_array($savePid, $allPids)) {
            $saveToken = Application::getSetting("token", $savePid);
            $saveServer = Application::getSetting("server", $savePid);
            if ($saveToken && $saveServer) {
                $records = Download::recordIds($saveToken, $saveServer);
                if (in_array($saveRecord, $records) || ($saveRecord === "")) {
                    $recordInstance = Sanitizer::sanitize($post['recordInstance'] ?? "");
                    $colWithoutSpacesOrHTML = Sanitizer::sanitize($post['column']);
                    $value = Sanitizer::sanitize($post['value']);
                    $tableNum = Sanitizer::sanitize($post['tableNum']);
                    $headers = $nihTables->getHeaders($tableNum);
                    $col = "";
                    foreach ($headers as $header) {
                        $headerWithoutSpacesAndHTML = REDCapManagement::makeHTMLId($header);
                        if ($colWithoutSpacesOrHTML == $headerWithoutSpacesAndHTML) {
                            $col = $header;
                            break;
                        }
                    }
                    if ($col) {
                        $field = NIHTables::makeCountKey($tableNum, $keyForRow);
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
                        $this->migrateOldRecordInstances($settingsByDate, $dateOfReport, $recordInstance);
                        $settingsByDate[$dateOfReport][$recordInstance][$col] = $value;
                        Application::saveSetting($field, $settingsByDate, $savePid);
                        $data["Result"] = "Saved.";
                    } else {
                        $data["error"] = "Could not locate column $colWithoutSpacesOrHTML.";
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

    private function migrateOldRecordInstances(&$settingAry, $dateOfReport, $newRecordInstance) {
        $migratedRecordInstance = FALSE;
        foreach (array_keys($settingAry[$dateOfReport]) as $recInst) {
            if (preg_match("/^$newRecordInstance/", $recInst)) {
                $migratedRecordInstance = $recInst;
            }
        }
        if ($migratedRecordInstance) {
            $settingAry[$dateOfReport][$newRecordInstance] = $settingAry[$dateOfReport][$migratedRecordInstance];
            unset($settingAry[$dateOfReport][$migratedRecordInstance]);
            return TRUE;
        }
        return FALSE;
    }

    public function getSavedTable3Awards($name) {
        $tableNum = 3;
        $field = "Award Number";
        $savedNames = $this->getSavedTableNames();
        if (isset($savedNames[$name]) && in_array($tableNum, $savedNames[$name])) {
            $key = self::makeSaveTableKey($name, $tableNum);
            $data = Application::getSetting($key, $this->pid);
            $seenAwards = [];
            foreach ($data['data'] as $row) {
                $seenAwards[] = $row[$field];
            }
            return $seenAwards;
        }
        return [];
    }

    public function setSavedTableNames($newSavedTableNames) {
        Application::saveSetting($this->allNamesField, $newSavedTableNames, $this->pid);
    }

    public function getSavedTableNames() {
        $allNames = Application::getSetting($this->allNamesField, $this->pid);
        if ($allNames) {
            return $allNames;
        }
        return [];
    }

    public function saveData($nihTables, $tableNum, $tableData, $name, $dateOfReport, $faculty, $grantTitle = "", $grantPI = "") {
        $allNames = $this->getSavedTableNames();
        if (!isset($allNames[$name])) {
            $allNames[$name] = [];
            ksort($allNames);
        }
        if (!in_array($tableNum, $allNames[$name])) {
            $allNames[$name][] = $tableNum;
            Application::saveSetting($this->allNamesField, $allNames, $this->pid);
        }

        for ($i = 0; $i < count($tableData); $i++) {
            foreach ($tableData[$i] as $key => $value) {
                if (($value === "") && ($key !== "record")) {
                    $tableData[$i][$key] = "<span class='action_required'>Not Available</span>";
                }
            }
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
        $awardNo = Sanitizer::sanitize($post['awardNo']);
        $date = Sanitizer::sanitize($post['date']);
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

    public function lookupValuesFor3($post) {
        $tableNum = Sanitizer::sanitize($post['tableNum']);
        $queryItems = Sanitizer::sanitizeArray($post['queryItems'] ?? []);
        $data = [];
        foreach ($queryItems as $ary) {
            $awardNo = $ary['awardNo'] ?? "";
            $recordId = $ary['record'] ?? "";
            $recordInstance = $ary['recordInstance'];
            foreach (Application::getPids() as $pid) {
                if (REDCapManagement::isActiveProject($pid)) {
                    $this->updateDataForRecordInPid($data, $pid, $tableNum,$recordId ?: $awardNo, $recordId, $recordInstance);
                }
            }
        }
        return $data;
    }

    private function updateDataForRecordInPid(&$data, $pid, $tableNum, $key, $recordId, $recordInstance) {
        $field = NIHTables::makeCountKey($tableNum, $key);
        $settingAry = Application::getSetting($field, $pid);
        if (!$settingAry) {
            return;
        }
        $migrationCompleted = FALSE;
        foreach (array_keys($settingAry) as $date) {

            # because of uploading table 2, the email may not be part of the match
            $recordInstanceToMatch = $recordInstance;
            foreach (array_keys($settingAry[$date]) as $settingRecordInstance) {
                if (preg_match("/^$recordInstance/", $settingRecordInstance)) {
                    $recordInstanceToMatch = $settingRecordInstance;
                    break;
                }
            }

            if (isset($settingAry[$date][$recordInstanceToMatch])) {
                self::initializeRecordInstanceData($data, $recordId, $recordInstanceToMatch);
                $data[$recordId][$recordInstanceToMatch][$date] = $settingAry[$date][$recordInstance];
            } else {
                if ($this->migrateOldRecordInstances($settingAry, $date, $recordInstanceToMatch)) {
                    self::initializeRecordInstanceData($data, $recordId, $recordInstanceToMatch);
                    $data[$recordId][$recordInstanceToMatch][$date] = $settingAry[$date][$recordInstanceToMatch];
                    $migrationCompleted = TRUE;
                }
            }
        }
        if ($migrationCompleted) {
            Application::saveSetting($field, $settingAry, $pid);
        }
    }

    private static function initializeRecordInstanceData(&$data, $recordId, $recordInstance) {
        if (!isset($data[$recordId])) {
            $data[$recordId] = [];
        }
        if (!isset($data[$recordId][$recordInstance])) {
            $data[$recordId][$recordInstance] = [];
        }
    }

    public function lookupValuesFor2And4($post) {
        $tableNum = Sanitizer::sanitize($post['tableNum']);
        $queryItems = Sanitizer::sanitizeArray($post['queryItems'] ?? []);
        $data = [];
        list($firstNamesByPid, $lastNamesByPid, $emailsByPid) = NIHTables::getNamesByPid();
        foreach ($queryItems as $ary) {
            $recordId = $ary['record'] ?? "";
            $facultyName = $ary['name'] ?? "";
            $recordInstance = $ary['recordInstance'];
            $matches = NIHTables::findMatchesInAllFlightTrackers($facultyName, $firstNamesByPid, $lastNamesByPid);
            foreach ($matches as $match) {
                list($pid, $matchRecordId) = explode(":", $match);
                $this->updateDataForRecordInPid($data, $pid, $tableNum, $matchRecordId, $recordId, $recordInstance);
            }
        }
        return $data;
    }

    public function getTablesToEdit() {
        return [2, 4];
    }

    private static function transformCheckboxes($data) {
        Application::log(json_encode($data));
        $col = "Names of<br/>Overlapping<br/>Faculty";
        $newData = $data;
        $newData['data'] = [];
        foreach ($data['data'] as $row) {
            if (isset($row[$col])) {
                $row[$col] = preg_replace("/type\s*=\s*[\"']checkbox[\"']/i", "type='checkbox' disabled", $row[$col]);
            }
            $newData['data'][] = $row;
        }
        return $newData;
    }

    public function makeHTMLForNIHTableEdits($dateOfReport, $name, $email, $hash, $tablesToShow, $savedName) {
        $thisUrl = Application::link("this", $this->pid)."&hash=".urlencode($hash)."&email=".urlencode($email);
        $metadata = Download::metadata($this->token, $this->server);
        $nihTables = new NIHTables($this->token, $this->server, $this->pid, $metadata);
        $nihTables->addFaculty([$name], $dateOfReport);
        $today = date("Y-m-d");

        $html = "<h1>An Update for Your NIH Tables is Requested</h1>";
        $html .= "<h2>About $name for Submission on ".REDCapManagement::YMD2MDY($dateOfReport)."</h2>";
        $html .= "<form method='POST' action='$thisUrl'>";
        $html .= Application::generateCSRFTokenHTML();
        $html .= "<input type='hidden' name='dateSubmitted' value='$today' />";
        $fields = [".action_required"];
        foreach ($tablesToShow as $tableNum) {
            $html .= "<h3>Table $tableNum: ".NIHTables::getTableHeader($tableNum)."</h3>";
            if ($tableNum == 3) {
                $data = $this->getSavedTable3Data($savedName);
                $data = self::transformCheckboxes($data);
                $table = NIHTables::makeTable1_4DataIntoHTML($tableNum, $data);
            } else {
                $savedKey = self::makeSaveTableKey($savedName, $tableNum);
                $table = $nihTables->getHTML($tableNum, FALSE, $savedKey, TRUE);
            }
            $html .= $table;
            $html .= "<h4>Do you have any requested changes for this table?<br>Consider addressing any <span class='action_required'>red</span> items,<br>and make sure you click the Submit Changes button.</h4>";
            $html .= "<p class='centered'><textarea id='table_$tableNum' name='table_$tableNum' style='width: 600px; height: 150px;'></textarea></p>";
            $html .= "<br/><br/>";
            $fields[] = "#table_".$tableNum;
        }
        $html .= "<p class='centered'><button>Submit Changes</button></p>";
        $html .= "</form>";
        $html .= REDCapManagement::autoResetTimeHTML($this->pid, $fields);
        return $html;
    }

    private static function makeSaveTableKey($tableName, $tableNum) {
        return "tablename____".$tableName."____".$tableNum;
    }

    private static function identifyNewFacultyNotInData($facultyList, $representedFaculty) {
        $unrepresentedFaculty = [];
        foreach ($facultyList as $name) {
            if (!in_array($name, $representedFaculty)) {
                $unrepresentedFaculty[] = $name;
            }
        }
        return $unrepresentedFaculty;
    }

    private static function removeOldFaculty($data, $facultyList) {
        $newData = $data;
        $newData['data'] = [];
        foreach ($data['data'] as $row) {
            $name = self::stripEmailAndHTML($row["Name"] ?? $row["Faculty Member"] ?? "");
            if (in_array($name, $facultyList)) {
                $newData['data'][] = $row;
            }
        }
        return $newData;
    }

    private static function stripEmailAndHTML($cell) {
        $withoutEmail = preg_replace("/<a [^>]*href\s*=\s*['\"]mailto:[^'^\"]+['\"][^>]*>[^<]+<\/a>/i", "", $cell);
        $withoutBreaks = preg_replace("/<br[ \/]*>/i", "", $withoutEmail);
        $withoutSectionTags = preg_replace("/<\/?section>/i", "", $withoutBreaks);
        return trim($withoutSectionTags);
    }

    public function getProgramEntriesFromTable1($faculty, $table1Pid, $dateOfReport, &$nihTables) {
        $nihTables->addFaculty($faculty, $dateOfReport);
        $depts = $nihTables->getFacultyDepartments();

        $fields = [
            "record_id",
            "name",
            "email",
            "program",
            "population",
            "participating_faculty",
            "total_with_participating_faculty_predocs",
            "total_with_participating_faculty_postdocs",
            "total_with_participating_faculty",
            "last_update",
        ];
        $json = \REDCap::getData($table1Pid, "json", NULL, $fields);
        $redcapData = json_decode($json, TRUE);
        $coachings = [];
        foreach (NIHTables::getLatestTable1Rows($redcapData) as $row) {
            $program = self::getCoreDepartment($row['program']);
            foreach ($depts as $faculty => $deptList) {
                foreach ($deptList as $dept) {
                    if (NameMatcher::matchDepartment($program, $dept)) {
                        $lastUpdate = DateManagement::YMD2MDY($row['last_update']);
                        $name = $row['name'];
                        $email = $row['email'];
                        if (!isset($coachings[$faculty])) {
                            $coachings[$faculty] = [];
                        }
                        if ($row['participating_faculty']) {
                            $numFaculty = self::formatReliability($row['participating_faculty']);
                            $popWords = [];
                            if ($row['population'] == "predocs") {
                                $popWords = ["Predoctorates"];
                            } else if ($row['population'] == "postdocs") {
                                $popWords = ["Postdoctorates"];
                            } else if ($row['population'] == "both") {
                                $popWords = ["Predoctorates", "Postdoctorates"];
                            }
                            if ($numFaculty && !empty($popWords)) {
                                $coachings[$faculty][] = "On $lastUpdate, <a href='mailto:$email'>$name</a> reported that $program had 'Participating Faculty' for ".REDCapManagement::makeConjunction($popWords)." of $numFaculty.";
                            }
                        }
                        if (in_array($row['population'], ["predocs", "both"])) {
                            $popWord = "Predoctorates";
                            $amount = ($row['population'] == "predocs") ? self::formatReliability($row['total_with_participating_faculty']) : self::formatReliability($row['total_with_participating_faculty_predocs']);
                            if ($amount) {
                                $coachings[$faculty][] = "On $lastUpdate, <a href='mailto:$email'>$name</a> reported for $program that had '$popWord with Participating Faculty' of $amount.";
                            }
                        }
                        if (in_array($row['population'], ["postdocs", "both"])) {
                            $popWord = "Postdoctorates";
                            $amount = ($row['population'] == "postdocs") ? self::formatReliability($row['total_with_participating_faculty']) : self::formatReliability($row['total_with_participating_faculty_postdocs']);
                            if ($amount) {
                                $coachings[$faculty][] = "On $lastUpdate, <a href='mailto:$email'>$name</a> reported for $program that had '$popWord with Participating Faculty' of $amount.";
                            }
                        }
                    }
                }
            }
        }
        return ["coaching" => $coachings];
    }

    private static function formatReliability($value) {
        if (is_numeric($value)) {
            return "<strong>$value</strong>";
        }
        if (preg_match("/^(\d+)\[(\d)\]$/", $value, $matches)) {
            $num = $matches[1];
            $reliability = $matches[2];
            return "<strong>$num</strong> ($reliability/4 reliability)";
        }
        return $value;
    }

    private static function getCoreDepartment($program) {
        $program = preg_replace("/<br\/?\s*>/i", " ", $program);
        $program = preg_replace("/[\s\n\r]+/", " ", $program);
        $extraWordsRegex = [
            "/Department of /i",
            "/Program of /i",
            "/ Program/i",
            "/ Department/i",
        ];
        foreach ($extraWordsRegex as $regex) {
            $program = preg_replace($regex, "", $program);
        }
        return $program;
    }

    public function getDataForTable($post, &$nihTables) {
        $tableNum = Sanitizer::sanitize($post['tableNum']);
        $dateOfReport = Sanitizer::sanitize($post['dateOfReport']);
        $savedTableName = $post['savedTableName'] ? Sanitizer::sanitize($post['savedTableName']) : "";
        $allNames = Application::getSetting($this->allNamesField, $this->pid);
        if ($savedTableName && isset($allNames[$savedTableName]) && in_array($tableNum, $allNames[$savedTableName])) {
            $data = Application::getSetting(self::makeSaveTableKey($savedTableName, $tableNum), $this->pid);
            if ($data) {
                $savedFaculty = Application::getSetting(self::makeSaveTableKey($savedTableName, "faculty"), $this->pid);
                $facultyList = Sanitizer::sanitizeArray($post['faculty']);
                $newFaculty = self::identifyNewFacultyNotInData($facultyList, $savedFaculty);
                if (in_array($tableNum, [2, 4]) && !empty($newFaculty)) {
                    $nihTables->addFaculty($newFaculty, $dateOfReport);
                    $newFacultyData = $nihTables->getData($tableNum);
                    $combinedData = self::removeOldFaculty($data, $facultyList);
                    foreach ($newFacultyData["data"] as $row) {
                        $combinedData["data"][] = $row;
                    }
                    $combinedData['source'] = "Combined with New Data";
                    return $combinedData;
                } else {
                    $data['source'] = "Previously Saved";
                }
                return $data;
            }
        }

        if (in_array($tableNum, [1, "1I", "1II", 2, 3, 4])) {
            if (in_array($tableNum, $this->getTablesToEdit())) {
                $facultyList = Sanitizer::sanitizeArray($post['faculty']);
                $nihTables->addFaculty($facultyList, $dateOfReport);
            } else if ($tableNum == 3) {
                $trainingGrants = Sanitizer::sanitize($post['trainingGrants']);
                $nihTables->addTrainingGrants($trainingGrants, $dateOfReport);
            }
            $data = $nihTables->getData($tableNum);
            $data['source'] = "Newly Computed";
            return $data;
        } else {
            return ["error" => "Invalid tableNum $tableNum"];
        }
    }

    public function getConfirmationKey($email, $tables) {
        if ($email && REDCapManagement::isEmail($email)) {
            return "confirmation_date_$email"."_".implode(",", $tables);
        }
        return "";
    }

    public function saveConfirmationTimestamp($email, $tables) {
        if ($key = $this->getConfirmationKey($email, $tables)) {
            Application::saveSetting($key, time(), $this->pid);
            return ["mssg" => "Saved."];
        } else {
            return ["error" => "Invalid email."];
        }
    }

    private function getValidTableOptions() {
        return [
            [3],
            [2, 4],
        ];

    }

    public function verify($requestedHash, $email) {
        if ($email) {
            $emails = preg_split("/\s*[,;]\s*/", $email);
            foreach ($emails as $em) {
                foreach ($this->getValidTableOptions() as $tables) {
                    $emailHash = $this->makeEmailHash($em, $tables);
                    if (($requestedHash == $emailHash) && REDCapManagement::isEmail($em)) {
                        return TRUE;
                    }
                }
            }
        }
        return FALSE;
    }

    public function getInformation($requestedHash, $email) {
        foreach ($this->getValidTableOptions() as $tables) {
            $emailHash = $this->makeEmailHash($email, $tables);
            if ($requestedHash == $emailHash) {
                return [$tables, $emailHash];
            }
        }
        return [[], ""];
    }

    public function getDatesOfLastVerification($post) {
        if (isset($post['emails'])) {
            $emails = Sanitizer::sanitizeArray($post['emails']);
            $tableNum = Sanitizer::sanitize($post['tableNum']);
            if (in_array($tableNum, [2, 4])) {
                $tables = [2, 4];
            } else if ($tableNum == 3) {
                $tables = [3];
            } else {
                $tables = [];
            }
            $data = [];
            if (empty($emails)) {
                $data['error'] = "No emails specified.";
            }

            $keys = Application::getSettingKeys($this->pid);
            foreach ($emails as $email) {
                if (REDCapManagement::isEmail($email)) {
                    $key = $this->getConfirmationKey($email, $tables);
                    if (in_array($key, $keys)) {
                        if ($ts = Application::getSetting($key, $this->pid)) {
                            $data[$email] = date("m-d-Y H:i", $ts);
                        } else {
                            $data[$email] = "";
                        }
                    }
                } else {
                    if ($email) {
                        $data["error"] = "Errors in processing.";
                        $data[$email] = "Invalid email.";
                    } else {
                        $data["error"] = "Errors in processing: No email provided.";
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
        $emailsToLookFor = preg_split("/\s*[,;]\s*/", $email);
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
                foreach ($emails as $recordId => $fieldValue) {
                    $recordEmails = $fieldValue ? preg_split("/\s*[,;]\s*/", $fieldValue) : "";
                    foreach ($recordEmails as $recordEmail) {
                        if ($recordEmail && in_array(strtolower($recordEmail), $emailsToLookFor) && ($userids[$recordId])) {
                            $name = $firstNames[$recordId]." ".$lastNames[$recordId];
                            $aryOfUserids = preg_split("/\s*[,;]\s*/", $userids[$recordId]);
                            foreach ($aryOfUserids as $u) {
                                if ($u && !in_array($u, $allUserids)) {
                                    $allUserids[] = $u;
                                }
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

    public function makeEmailHash($email, $tableNums) {
        if (preg_match("/[,;]/", $email)) {
            $emails = preg_split("/\s*[,;]\s*/", $email);
        } else {
            $emails = [$email];
        }
        foreach ($emails as $email) {
            $key = "email_".$email."_".implode(",", $tableNums);
            $previousValue = Application::getSetting($key, $this->pid);
            if ($previousValue) {
                return $previousValue;
            }
        }
        $email = $emails[0];
        $key = "email_".$email."_".implode(",", $tableNums);
        $hash = substr(md5($this->pid.":".$email.":".implode(",", $tableNums)), 0, 64);
        Application::saveSetting($key, $hash, $this->pid);
        return $hash;
    }

    public function getTable1_4Header() {
        $cssLink = Application::link("/css/career_dev.css", $this->pid);
        return "<link href='$cssLink' rel='stylesheet' />";
    }

    public function makeNotesKey($email, $tables) {
        if ($email && REDCapManagement::isEmail($email)) {
            return "table_notes_$email" . "_" . implode(",", $tables);
        }
        return "";
    }

    # returns array keyed by project header, then by date, then by table
    public function getNotesData($post) {
        $emails = Sanitizer::sanitizeArray($post['emails']);
        if ($post['tableNum']) {
            $tableNums = [Sanitizer::sanitize($post['tableNum'])];
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
                        $key = $this->makeNotesKey($email, $tableNums);
                        if (in_array($key, $keys)) {
                            $name = $this->getNameAssociatedWithEmail($email);
                            $ary = Application::getSetting($key, $pid);   // keyed by date, then by table
                            $tableAry = $this->filterNotesByTables($ary, $tableNums);
                            if (is_array($tableAry) && !empty($tableAry)) {
                                $currToken = Application::getSetting("token", $pid);
                                $currServer = Application::getSetting("server", $pid);
                                $adminEmail = Application::getSetting("admin_email", $pid);
                                if ($currToken && $currServer) {
                                    if (!$projectHeader) {
                                        $projectHeader = $pid.": ".Download::projectTitle($currToken, $currServer);
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

    public function saveNotes($post, $email, $tables) {
        $date = Sanitizer::sanitize($post['dateSubmitted']);
        $notesKey = $this->makeNotesKey($email, $tables);
        $settings = Application::getSetting($notesKey, $this->pid);
        if (!$settings) {
            $settings = [];
        }
        $tableNotes = [];
        $metadata = Download::metadata($this->token, $this->server);
        $nihTables = new NIHTables($this->token, $this->server, $this->pid, $metadata);
        foreach ($tables as $tableNum) {
            $notes = Sanitizer::sanitizeWithoutChangingQuotes($post['table_'.$tableNum]);
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
            $this->saveConfirmationTimestamp($email, $tables);
            return "Data saved. Thank you!";
        } else {
            return "No data to save.";
        }
    }

    public function getSavedTable3Data($name) {
        $tableNum = 3;
        $savedNames = $this->getSavedTableNames();
        if (isset($savedNames[$name]) && in_array($tableNum, $savedNames[$name])) {
            $key = self::makeSaveTableKey($name, $tableNum);
            $data = Application::getSetting($key, $this->pid);
            if ($data === "") {
                return [];
            }
            return $data;
        }
        return [];
    }

    public function lookupTrainingGrantsByInstitutionsInRePORTER($institutions, $metadata, $dateOfReport, $name) {
        if (empty($institutions)) {
            return [];
        }
        $reporterTypes = RePORTER::getTypes();
        $data = [];
        $seenAwards = $name ? $this->getSavedTable3Awards($name) : [];
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
                $baseAwardNumber = Grant::translateToBaseAwardNumber($awardNo);
                if (!in_array($baseAwardNumber, $seenAwards)) {
                    $seenAwards[] = $baseAwardNumber;
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
                    if (!isset($dataByPid[$pid])) {
                        $dataByPid[$pid] = [];
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
        $checkedNames = Sanitizer::sanitizeArray($post['checkedNames']);
        $uncheckedNames = Sanitizer::sanitizeArray($post['uncheckedNames']);
        $awardNo = Sanitizer::sanitize($post['award']);
        $dateOfReport = Sanitizer::sanitize($post['date']);
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
