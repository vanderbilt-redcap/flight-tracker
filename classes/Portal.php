<?php

namespace Vanderbilt\CareerDevLibrary;

use ZipStream\File;
use function Vanderbilt\FlightTrackerExternalModule\appendCitationLabel;

require_once(__DIR__ . '/ClassLoader.php');


class Portal {
    const NONE = "NONE";
    const NUM_POSTS_PER_SETTING = 10;
    const DATETIME_FORMAT = "Y-m-d h:I:s";
    const BOARD_PREFIX = "board_";

    public function __construct($currPid, $recordId, $name, $projectTitle, $allPids) {
        $this->pid = $currPid;
        $this->pidRecords = Download::recordIdsByPid($this->pid);
        $this->recordId = $recordId;
        $this->allPids = $allPids;
        $this->name = $name;
        $this->projectTitle = $projectTitle;
        $this->today = date("Y-m-d");
        list($this->username, $this->firstName, $this->lastName) = self::getCurrentUserIDAndName();
        $this->driverURL = Application::link("portal/driver.php").(isset($_GET['uid']) ? "&uid=".$this->username : "");
        $this->mmaURL = Application::link("portal/mmaDriver.php").(isset($_GET['uid']) ? "&uid=".$this->username : "");
        if (!$this->verifyRequest()) {
            throw new \Exception("Unverified Access.");
        }
    }

    public static function getTestNames() {
        return [
            "bastarja" => ["Julie", "Bastarache"],
            "edwardt5" => ["Todd", "Edwards"],
            "austine" => ["Eric", "Austin"],
        ];
    }

    public static function getCurrentUserIDAndName() {
        $version = Application::getVersion();
        if (
            Application::isVanderbilt()
            && REDCapManagement::versionGreaterThanOrEqualTo("6.0.0", $version)
            && isset($_GET['uid'])
        ) {
            # TODO pre release
            $testNames = self::getTestNames();
            if (in_array($_GET['uid'], $testNames)) {
                return [$_GET['uid'], $testNames[$_GET['uid']][0], $testNames[$_GET['uid']][1]];
            } else {
                $username = Sanitizer::sanitize($_GET['uid']);
                $info = REDCapLookup::getUserInfo($username);
                return [$username, $info['user_firstname'] ?? "", $info['user_lastname'] ?? ""];
            }
        } else {
            return REDCapLookup::getCurrentUserIDAndName();
        }
    }

    private static function downloadAndSave($setting, $func, $token, $server, $pid) {
        $data = Download::$func($token, $server);
        Application::saveSetting($setting, $data, $pid);
        return $data;
    }

    public static function getMatchesForUserid($username, $firstName, $lastName, $pids) {
        $usernameInLC = strtolower($username);
        $matches = [];
        $projectTitles = [];
        foreach ($pids as $pid) {
            $token = Application::getSetting("token", $pid);
            $server = Application::getSetting("server", $pid);
            $turnOffSet = Application::getSetting("turn_off", $pid);
            if ($token && $server && !$turnOffSet && REDCapManagement::isActiveProject($pid)) {
                Application::setPid($pid);
                $userids = Application::getSetting("userids", $pid) ?: self::downloadAndSave("userids", "userids", $token, $server, $pid);
                $firstNames = Application::getSetting("first_names", $pid) ?: self::downloadAndSave("first_names", "firstnames", $token, $server, $pid);
                $lastNames = Application::getSetting("last_names", $pid) ?: self::downloadAndSave("last_names", "lastnames", $token, $server, $pid);
                $foundMatch = FALSE;

                $matchedRecordsInProject = [];
                foreach ($userids as $recordId => $userid) {
                    if (
                        ($userid !== "")
                        && (strtolower($userid) == $usernameInLC)
                    ) {
                        $fn = $firstNames[$recordId] ?? "";
                        $ln = $lastNames[$recordId] ?? "";
                        $name = self::makeName($fn, $ln);
                        if (!$name) {
                            $name = $userid;
                        }
                        $matchedRecordsInProject[$recordId] = $name;
                    }
                }
                if (!empty($matchedRecordsInProject)) {
                    $matches[$pid] = $matchedRecordsInProject;
                    $foundMatch = TRUE;
                }

                $matchedRecordsInProject = [];
                foreach ($lastNames as $recordId => $ln) {
                    $fn = $firstNames[$recordId] ?? "";
                    if (NameMatcher::matchName($firstName, $lastName, $fn, $ln)) {
                        $name = self::makeName($fn, $ln);
                        if ($name) {
                            $matchedRecordsInProject[$recordId] = $name;
                        }
                    }
                }
                if (!empty($matchedRecordsInProject)) {
                    $matches[$pid] = $matchedRecordsInProject;
                    $foundMatch = TRUE;
                }
                if ($foundMatch) {
                    $projectTitles[$pid] = Download::shortProjectTitle($token, $server);
                }
                Application::unsetPid();
            }
        }
        return [$matches, $projectTitles, self::getPhotoInMatches($matches)];
    }

    public function getMatchesManually($requestedPids) {
        if (!empty($requestedPids)) {
            $myPids = [];
            foreach ($requestedPids as $pid) {
                if (in_array($pid, $this->allPids)) {
                    $myPids[] = $pid;
                }
            }
        } else {
            $myPids = $this->allPids;
        }
        list($matches, $projectTitles, $photoBase64) = self::getMatchesForUserid($this->username, $this->firstName, $this->lastName, $myPids);
        $this->mergeWithStoredData(["matches" => $matches, "projectTitles" => $projectTitles], $myPids);
        return [$matches, $projectTitles, $photoBase64];
    }

    private function mergeWithStoredData($data, $myPids) {
        $storedData = $this->getStoredData();
        $storedDate = $storedData['date'] ?? "";
        if ($storedDate == $this->today) {
            $hasMerged = TRUE;
            foreach ($data as $key => $pidValues) {
                if (!isset($storedData[$key])) {
                    $storedData[$key] = $pidValues;
                } else {
                    foreach ($pidValues as $pid => $value) {
                        $storedData[$key][$pid] = $value;
                    }
                }
            }
        } else {
            $hasMerged = FALSE;
            $storedData = $data;
            $storedData['date'] = date("Y-m-d");
        }
        if ($hasMerged && ($this->allPids[count($this->allPids) - 1] == $myPids[count($myPids) - 1])) {
            $storedData['done'] = TRUE;
        } else {
            $storedData['done'] = FALSE;
        }
        Application::saveSystemSetting($this->username, $storedData);
    }

    public function getStoredMatches() {
        return $this->getStoredData()['matches'] ?? [];
    }

    public function getPage($relativeFileLocation, $getParams = []) {
        $getParams['pid'] = (string) $this->pid;
        $page = preg_replace("/^\//", "", $relativeFileLocation);
        $pageWithoutPHP = preg_replace("/\.php$/", "", $page);
        $getParams['page'] = $pageWithoutPHP;
        $getParams['prefix'] = Application::getPrefix();
        $getParams['hideHeader'] = "1";
        $getParams['hideHeaders'] = "1";
        $getParams['headers'] = "false";
        $filename = __DIR__."/../".$page;

        $oldGet = $_GET;
        $_GET = $getParams;
        ob_start();
        include($filename);
        $html = ob_get_clean();
        $html = preg_replace("/<h1>.+?<\/h1>/i", "", $html);
        $html = str_replace("<h4>", "<h5>", $html);
        $html = str_replace("</h4>", "</h5>", $html);
        $html = str_replace("<h3>", "<h4>", $html);
        $html = str_replace("</h3>", "</h4>", $html);
        $html = str_replace("<h2>", "<h4>", $html);
        $html = str_replace("</h2>", "</h4>", $html);
        $_GET = $oldGet;
        return $html;
    }


    public static function makeName($fn, $ln) {
        if ($fn && $ln) {
            return NameMatcher::formatName($fn, "", $ln);
        } else if ($fn) {
            return $fn;
        } else if ($ln) {
            return $ln;
        } else {
            return "";
        }
    }

    public static function getLogo($name = "") {
        $logoFilename = __DIR__."/../mentor/img/logo.jpg";
        $efsFilename = __DIR__."/../img/efs_small.png";
        if (file_exists($logoFilename) && file_exists($efsFilename)) {
            $logoBase64 = FileManagement::getBase64OfFile($logoFilename, "image/jpeg");
            $efsBase64 = FileManagement::getBase64OfFile($efsFilename, "image/png");
            if (Application::isVanderbilt()) {
                $efsLink = "https://edgeforscholars.vumc.org";
            } else {
                $efsLink = "https://edgeforscholars.org";
            }

            $margin = 8;
            $marginWidth = $margin."px";

            $nameHTML = $name ? "<h1>Hello $name!</h1>" : "";
            $vumcMessage = Application::isVanderbilt() ? " - [<a href='https://edgeforscholars.vumc.org/' target='_blank'>Edge for Scholars at Vanderbilt</a>]" : " at Vanderbilt University Medical Center";
            $html = "<p class='centered'>";
            $html .= "<div style='width: 100%; text-align: center;' class='smaller'>A Career Development Resource from [<a href='https://edgeforscholars.org' target='_blank'>Edge for Scholars</a>]$vumcMessage</div>";
            $html .= "<div style='float:left; margin-left: $marginWidth;' class='responsiveHeader'><a href='https://redcap.link/flight_tracker' target='_blank'><img src='$logoBase64' class='responsiveHeader' alt='Flight Tracker for Scholars' /></a></div>";
            $html .= "<div class='centerHeader' style='float: left; text-align: center;'>$nameHTML</div>";
            $html .= "<div style='float:right; text-align: right; margin-right: $marginWidth;' class='responsiveHeader'><a href='$efsLink' target='_blank'><img src='$efsBase64' class='efsHeader' alt='Edge for Scholars' /></a></div>";
            $html .= "</p>";
            $html .= "<div style='clear: both'></div>";
            return $html;
        }
        return "";
    }

    private static function getPhotoInMatches($matches) {
        $targetField = "identifier_picture";
        foreach ($matches as $pid => $recordsAndNames) {
            $fields = Download::metadataFieldsByPid($pid);
            if (in_array($targetField, $fields)) {
                foreach (array_keys($recordsAndNames) as $recordId) {
                    $base64 = Download::fileAsBase64($pid, $targetField, $recordId);
                    if ($base64) {
                        return $base64;
                    }
                }
            }
        }
        return "";
    }

    public function getPhoto() {
        $validMatches = [];
        foreach ($this->getStoredMatches() as $pid => $recordsAndNames) {
            if (in_array($pid, $this->allPids)) {
                $validMatches[$pid] = $recordsAndNames;
            }
        }
        return self::getPhotoInMatches($validMatches);
    }

    # 3 words max
    public function getMenu() {
        $menu = [];
        $menu["Your Info"] = [];
        $menu["Your Graphs"] = [];
        $menu["Your Network"] = [];

        $menu["Your Info"][] = [
            "action" => "view",
            "title" => "View",     // list of honors, pubs, grants & patents - flagging and can add; encouragement
        ];
        $menu["Your Info"][] = [
            "action" => "survey",
            "title" => "Update",     // list of honors, pubs, grants & patents - flagging and can add; encouragement
        ];
        $menu["Your Info"][] = [
            "action" => "honors",
            "title" => "Honors &amp; Awards",     // new survey
        ];
        $menu["Your Info"][] = [
            "action" => "resources",
            "title" => "Resource Use",     // info to connect to a CTSA resource
        ];
        $menu["Your Info"][] = [
            "action" => "photo",
            "title" => "Photos",     // should search all projects; also, should display
        ];
        // TODO ORCID Bio Link in Your Info

        // TODO encouraging message if pubs/grants are blank
        $menu["Your Graphs"][] = [
            "action" => "scholar_collaborations",
            "title" => "Publishing Collaborations",      // social network graph
        ];
        $menu["Your Graphs"][] = [
            "action" => "pubs_impact",
            "title" => "Publishing Impact",      // combined & deduped RCR graph; Altmetric summary & links
        ];
        $menu["Your Graphs"][] = [
            "action" => "timelines",
            "title" => "Grant &amp; Publication Timelines",     // Pubs & all grants; encouraging message if blank
        ];
        $menu["Your Graphs"][] = [
            "action" => "group_collaborations",
            "title" => "Group Publishing Collaborations (Computationally Expensive)",
        ];
        $menu["Your Graphs"][] = [
            "action" => "grant_funding",
            "title" => "Grant Funding by Year",
        ];

        $menu["Your Network"][] = [
            "action" => "mentoring",
            "title" => "Mentoring Portal",             // set up mentor(s); fill out MMAs; talk to each other
        ];
        if (Application::isVanderbilt()) {
            $menu["Your Network"][] = [
                "action" => "connect",
                "title" => "Connect With Colleagues",     // flight connector
            ];

            $menu["Your Network"][] = [
                "action" => "resource_map",
                "title" => "Resource Map",     // flight map
            ];

            # Newman Society success figures: externally launch career_dev/newmanFigures
            $menu["Your Network"][] = [
                "action" => "stats",
                "title" => "Newman Society Statistics",
            ];
        }
        $menu["Your Network"][] = [
            "action" => "board",
            "title" => "Bulletin Board",
        ];
        return $menu;
    }

    public function getStoredData() {
        $storedData = Application::getSystemSetting($this->username);
        $storedDate = $storedData['date'] ?? "";
        $isDone = $storedData['done'] ?? FALSE;
        if (!empty($storedData) && ($storedDate === $this->today) && $isDone) {
            unset($storedData['date']);
            unset($storedData['done']);
            if (!Application::isLocalhost()) {
                foreach ($storedData['matches'] ?? [] as $matchPid => $recordsAndNames) {
                    if (!REDCapManagement::isActiveProject($matchPid)) {
                        unset($storedData["matches"][$matchPid]);
                    }
                }
                return $storedData;
            }
        } else if (!empty($storedData) && ($storedDate !== $this->today)) {
            Application::saveSystemSetting($this->username, "");
        }
        return [];
    }

    private function verifyRequest() {
        if ($this->pid && !in_array($this->pid, $this->allPids)) {
            return FALSE;
        }
        if ($this->recordId && !in_array($this->recordId, $this->pidRecords)) {
            return FALSE;
        }
        # TODO More - username or name goes with pid/recordId?
        return TRUE;
    }

    public static function getHeaders() {
        $html = "<title>Flight Tracker: Scholar Portal</title>";
        $html .= Application::getImportHTML();
        return $html;
    }

    public function uploadPhoto($filename, $mimeType) {
        $matches = $this->getStoredMatches();
        $extension = FileManagement::getMimeSuffix($mimeType);
        $base64 = FileManagement::getBase64OfFile($filename, $mimeType);
        $oneUploadSuccessful = FALSE;
        foreach ($matches as $pid => $recordsAndNames) {
            foreach ($recordsAndNames as $recordId => $name) {
                $newFilename = FileManagement::makeSafeFilename(strtolower(REDCapManagement::makeHTMLId($name).".".$extension));
                $result = Upload::file($pid, $recordId, "identifier_picture", $base64, $newFilename);
                if (isset($result['error']) && $oneUploadSuccessful) {
                    throw new \Exception("Partially uploaded.".$result['error']);
                } else if (isset($result['error'])) {
                    throw new \Exception($result['error']);
                } else {
                    $oneUploadSuccessful = TRUE;
                }
            }
        }
        if ($oneUploadSuccessful) {
            return $base64;
        } else {
            return "";
        }
    }

    public function getModifyPhotoPage() {
        $base64 = $this->getPhoto();
        if ($base64) {
            $html = "<h3>Replace Your Photo</h3>";
        } else {
            $html = "<h3>Add a Photo</h3>";
        }
        $html .= "<form action='{$this->driverURL}' method='POST' enctype='multipart/form-data' id='photoForm'>";
        $html .= "<input type='hidden' name='action' value='upload_photo' />";
        $html .= "<p class='centered'><label for='photoFile'>Photo:</label> <input type='file' id='photoFile' name='photoFile' onchange='portal.validateFile(this);' /><br/>";
        $html .= "<button onclick='portal.uploadPhoto(\"#photoForm\"); return false;'>Upload</button></p>";
        $html .= "</form>";
        return $html;
    }

    public function makeMentoringPortal() {
        $redcapData = Download::getDataByPid($this->pid, ["record_id", "summary_mentor", "summary_mentor_userid"], [$this->recordId]);
        $mentorList = REDCapManagement::findField($redcapData, $this->recordId, "summary_mentor");
        $mentorUseridList = REDCapManagement::findField($redcapData, $this->recordId, "summary_mentor_userid");
        $mentors = $mentorList ? preg_split("/\s*[,;\/]\s*/", $mentorList) : [];
        $mentorUserids = $mentorUseridList ? preg_split("/\s*[,;]\s*/", $mentorUseridList) : [];

        $mssg = "<p class='centered max-width'>Your do not have a mentor set up. Would you like to add a Mentor?</p>";
        $html = "<h3>Your Mentoring Portal for {$this->projectTitle}</h3>";
        $html .= "<div id='searchResults'></div>";
        if (Application::isMSTP($this->pid)) {
            $html .= "<h4>Coming Soon</h4>";
        } else if (empty($mentors) && empty($mentorUserids)) {
            $html .= $this->makeMentorSetup($mssg);
        } else if (empty($mentorUserids)) {
            $i = 1;
            foreach ($mentors as $mentorName) {
                list($firstName, $lastName) = NameMatcher::splitName($mentorName, 2);
                $lookup = new REDCapLookup($firstName, $lastName);
                $uidsAndNames = $lookup->getUidsAndNames(TRUE);
                $html .= $this->processUidsAndNames($mentorName, $uidsAndNames, $i, "<p class='centered max-width'>This mentor does not match any REDCap users. Would you like to add another mentor?</p>");
                $i++;
            }
        } else if (empty($mentors)) {
            # User ID but no name; This should not happen without a configuration error
            $mentorNames = [];
            foreach ($mentorUserids as $mentorUserid) {
                $info = REDCapLookup::getUserInfo($mentorUserid);
                if (!empty($info)) {
                    $mentorNames[] = $info['user_firstname']." ".$info['user_lastname'];
                }
            }
            if (!empty($mentorNames)) {
                $mentors = $mentorNames;
                $mentorList = implode(", ", $mentors);
                $uploadRow = ["record_id" => $this->recordId, "summary_mentor" => $mentorList];
                Upload::rowsByPid([$uploadRow], $this->pid);
                $html .= $this->makeLiveMentorPortal($mentors, $mentorUserids);
            } else {
                $html .= $this->makeMentorSetup($mssg);
            }
        } else {
            $html .= $this->makeLiveMentorPortal($mentors, $mentorUserids);
        }
        return $html;
    }

    private function makeNewPostHTML() {
        $html = "<h4>Make a New Post</h4>";
        $html .= "<p><textarea id='newPost'></textarea></p>";
        $html .= "<p><button onclick='portal.submitPost(\"#newPost\"); return false;'>Submit Post</button></p>";
        return $html;
    }

    public function getInstitutionBulletinBoard() {
        $posts = $this->getBoardPosts();
        $html = "<h3>Institutional Bulletin Board</h3>";
        $html .= $this->makeNewPostHTML();
        $html .= "<h4>Existing Posts from Your Colleagues</h4>";
        $html .= "<div id='posts'>";
        if (empty($posts)) {
            $html .= "<p>Nothing has been posted yet.</p>";   // text also in portal.js
        } else {
            $rows = [];
            foreach ($posts as $post) {
                if (!empty($post)) {
                    $rows[] = $this->formatPost($post);
                }
            }
            $html .= implode("", $rows);
        }
        $html .= "</div>";

        return $html;
    }

    # returns boolean if an entry was deleted
    public function deletePost($user, $datetime) {
        if (!$user || !$datetime) {
            return FALSE;
        }
        $prefixIndex = 0;
        $prefix = self::BOARD_PREFIX;
        do {
            $prefixIndex++;
            $result = Application::getSystemSetting($prefix . $prefixIndex);
            if (is_array($result)) {
                foreach ($result as $i => $post) {
                    if (
                        ($post['username'] == $user)
                        && ($post['date'] == $datetime)
                        && self::canDelete($post['username'])
                    ) {
                        $newResult = [];
                        foreach ($result as $j => $post2) {
                            if ($j !== $i) {
                                $newResult[] = $post2;
                            } else {
                                $newResult[] = [];
                            }
                        }
                        Application::saveSystemSetting($prefix.$prefixIndex, $newResult);
                        return TRUE;
                    }
                }
            }
        } while ($result !== "");
        return FALSE;
    }

    private function formatPost($post) {
        $user = $post['username'];
        $lookup = new REDCapLookupByUserid($user);
        $testNames = self::getTestNames();
        if (isset($testNames[$user])) {
            $name = self::makeName($testNames[$user][0], $testNames[$user][1]);
        } else {
            $name = $lookup->getName();
        }
        $email = $lookup->getEmail();
        $date = $_POST['date'] ?? date(self::DATETIME_FORMAT);
        $mssg = $post['message'];
        $storedData = Application::getSystemSetting($user) ?: [];
        $matches = $storedData['matches'] ?? [];
        if (!empty($matches) && !$name) {
            $found = FALSE;
            foreach ($matches as $pid => $recordsAndNames) {
                foreach ($recordsAndNames as $recordId => $n) {
                    if ($n) {
                        $name = $n;
                        $found = TRUE;
                        break;
                    }
                }
                if ($found) {
                    break;
                }
            }
        } else if (!$name) {
            $name = $user;
        }
        $photo = $this->getPhoto();
        if (!$email) {
            $email = $this->getEmail();
        }
        return $this->makePostHTML($name, $user, $email, $date, $mssg, $photo);
    }

    private function makePostHTML($name, $user, $email, $datetime, $mssg, $photoBase64) {
        $longDate = DateManagement::datetime2LongDate($datetime);
        $photoHTML = $photoBase64 ? "<img src='$photoBase64' class='photo' alt='$name' /><br/>" : "";
        $deleteButton = self::canDelete($user) ? " <button onclick='portal.deletePost(\"#posts\", \"$user\", \"$datetime\"); return false;'>Delete Post</button>" : "";
        $emailHTML = $email ? " (<a href='mailto:$email'>$email</a>)" : "";
        $html = "<p>$photoHTML<strong>$name</strong> at ".$longDate.$emailHTML.$deleteButton."</p>";
        $lines = preg_split("/[\n\r]+/", $mssg);
        $postHTML = implode("</p><p class='alignLeft'>", $lines);
        if (!$postHTML) {
            return "";
        }
        $html .= "<div class='centered max-width-600 post'><p class='alignLeft'>".$postHTML."</p></div>";
        return $html;
    }

    public static function canDelete($postUser) {
        return Application::isSuperUser() || ($postUser == Application::getUsername());
    }

    private function getEmail() {
        foreach ($this->getStoredMatches() as $pid => $recordsAndNames) {
            if (in_array($pid, $this->allPids)) {
                foreach (array_keys($recordsAndNames) as $recordId) {
                    $email = Download::oneFieldForRecordByPid($pid, "identifier_email", $recordId);
                    if ($email) {
                        return $email;
                    } else {
                        $personalEmail = Download::oneFieldForRecordByPid($pid, "identifier_personal_email", $recordId);
                        if ($personalEmail) {
                            return $personalEmail;
                        }
                    }
                }
            }
        }
        return "";
    }

    public function addPost($postText) {
        $post = [
            "message" => $postText,
            "username" => $this->username,
            "date" => date(self::DATETIME_FORMAT),
        ];

        $i = 0;
        $prefix = self::BOARD_PREFIX;
        do {
            $i++;
            $result = Application::getSystemSetting($prefix . $i);
            if (is_array($result) && (count($result) < self::NUM_POSTS_PER_SETTING)) {
                $result[] = $post;
                Application::saveSystemSetting($prefix.$i, $result);
                return $this->formatPost($post);
            }
        } while (isset($result) && ($result !== ""));

        Application::saveSystemSetting($prefix.$i, [$post]);
        return $this->formatPost($post);
    }

    private function getBoardPosts() {
        $posts = [];
        $i = 0;
        $prefix = self::BOARD_PREFIX;
        do {
            $i++;
            $result = Application::getSystemSetting($prefix.$i);
            if (is_array($result)) {
                foreach ($result as $item) {
                    if (!empty($item)) {
                        $posts[] = $item;
                    }
                }
            }
        } while ($result !== "");
        return array_reverse($posts);
    }

    public function processUidsAndNames($mentorName, $uidsAndNames, $i, $priorMessage = "") {
        $html = "<h4>$mentorName</h4>";
        if (empty($uidsAndNames)) {
            $html .= $this->makeMentorSetup($priorMessage, $i);
        } else {
            $html .= $this->makeConfirmationTable($mentorName, $uidsAndNames, $i);
        }
        return $html;
    }

    private function makeConfirmationTable($mentorName, $uidsAndNames, $index) {
        $html = "<table class='centered max-width'><tbody>";
        if (count($uidsAndNames) == 1) {
            $radioName = "mentor_$index"."_yn";
            $html .= "<tr>";
            foreach ($uidsAndNames as $uid => $name) {
                $html .= "<td>User-id: $uid ($name)</td>";
                $html .= "<td>Is this the correct user?";
                $html .= "<br/><input type='radio' name='$radioName' id='mentor_$index"."_yes' value='1' /> <label for='mentor_$index"."_yes'>Yes</label>";
                $html .= "<br/><input type='radio' name='$radioName' id='mentor_$index"."_no' value='0' /> <label for='mentor_$index"."_no'>No</label>";
                $html .= "</td>";
            }
            $html .= "</tr>";
        } else {
            $radioName = "mentor_$index"."_multi";
            $html .= "<tr>";
            $html .= "<td style='max-width: 200px;'>Which of these are mentor $mentorName?</td>";
            $html .= "<td style='text-align: left;'>";
            $lines = [];
            $lines[] = "<input type='radio' name='$radioName' id='mentor_$index"."_".self::NONE."' value='".self::NONE."' /> <label for='mentor_$index"."_".self::NONE."'>None of the below</label>";
            foreach ($uidsAndNames as $uid => $name) {
                $lines[] = "<input type='radio' name='$radioName' id='mentor_$index"."_$uid' value='$uid' /> <label for='mentor_$index"."_$uid'>$uid ($name)</label>";
            }
            $html .= implode("<br/>", $lines);
            $html .= "</td>";
            $html .= "</tr>";
        }
        $html .= "</tbody></table>";
        $html .= "<p class='centered max-width'><button onclick='portal.selectMentors(\"{$this->driverURL}\", \"{$this->pid}\", \"{$this->recordId}\", \"$mentorName\", \"[name=$radioName]\"); return false;'>Make Selection</button></p>";
        return $html;
    }

    private function makeMentorSetup($priorMessage = "", $index = NULL) {
        $suffix = isset($index) ? "_".$index : "";
        $html = "";
        if ($priorMessage) {
            $html .= $priorMessage;
        }
        $html .= "<p class='centered max-width'><input type='text' id='mentor$suffix' name='mentor$suffix' placeholder='Mentor Name' /></p>";

        $lines = [];
        $lines[] = "<button onclick='portal.searchForMentor(\"{$this->mmaURL}\", \"{$this->pid}\", \"{$this->recordId}\", \"#mentor$suffix\"); return false;'>Search If They Have REDCap Access</button>";
        $lines[] = "";
        $lines[] = "<strong>-OR-</strong>";
        $lines[] = "";
        $lines[] = "<label for='mentor_userid$suffix'>Input the Mentor's User ID for Accessing REDCap</label>";
        $lines[] = "<input type='text' id='mentor_userid$suffix' name='mentor_userid$suffix' placeholder=\"Mentor's User ID\" />";
        $lines[] = "<button onclick='portal.submitNameAndUserid(\"{$this->mmaURL}\", \"{$this->pid}\", \"{$this->recordId}\", \"#mentor$suffix\", \"#mentor_userid$suffix\"); return false;'>Submit Name &amp; User ID</button>";

        $html .= "<p class='centered max-width'>".implode("<br/>", $lines)."</p>";
        return $html;
    }

    private function makeLiveMentorPortal($mentors, $mentorUserids) {
        $html = "<p class='centered max-width'>";
        if ((count($mentors) == 1) && (count($mentorUserids) == 1)) {
            $mentorUseridList = implode(", ", $mentorUserids);
            $html .= "Your mentor is {$mentors[0]} ($mentorUseridList).";
        } else if (count($mentors) == count($mentorUserids)) {
            $names = [];
            foreach ($mentors as $i => $mentor) {
                $userid = $mentorUserids[$i];
                $names[] = "$mentor ($userid)";
            }
            $html .= "Your mentors are ".REDCapManagement::makeConjunction($names).".";
        } else {
            $html .= "Your mentors are ".REDCapManagement::makeConjunction($mentors).". The following user-id(s) provide them access: ".REDCapManagement::makeConjunction($mentorUserids).".";
        }
        $html .= "</p>";

        $mmaURL = Application::getMenteeAgreementLink($this->pid);
        $html .= "<h4>".Links::makeLink($mmaURL, "Access Your Mentee-Mentor Agreement for ".$this->projectTitle, TRUE)."</h4>";
        return $html;
    }

    protected $pid;
    protected $recordId;
    protected $name;
    protected $projectTitle;
    protected $allPids;
    protected $driverURL;
    protected $username;
    protected $lastName;
    protected $firstName;
    protected $today;
    protected $mmaURL;
    protected $pidRecords;
}
