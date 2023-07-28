<?php

namespace Vanderbilt\CareerDevLibrary;

use Vanderbilt\FlightTrackerExternalModule\CareerDev;
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
        $this->module = Application::getModule();
        if ($this->pid) {
            $this->token = Application::getSetting("token", $this->pid);
            $this->server = Application::getSetting("server", $this->pid);
        }
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

    public static function isLive() {
        return Application::isLocalhost() || REDCapManagement::versionGreaterThanOrEqualTo("6.0.0", Application::getVersion());
    }

    public static function getLink() {
        return Application::getScholarPortalLink();
    }

    public static function getCurrentUserIDAndName() {
        if (
            Application::isVanderbilt()
            && self::isLive()
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
            $vumcMessage = Application::isVanderbilt() ? " - [<a href='https://edgeforscholars.vumc.org/'>Edge for Scholars at Vanderbilt</a>]" : " at Vanderbilt University Medical Center";
            $html = "<p class='centered'>";
            $html .= "<div style='width: 100%; text-align: center;' class='smaller'>A Career Development Resource from [<a href='https://edgeforscholars.org'>Edge for Scholars</a>]$vumcMessage</div>";
            $html .= "<div style='float:left; margin-left: $marginWidth;' class='responsiveHeader'><a href='https://redcap.link/flight_tracker'><img src='$logoBase64' class='responsiveHeader' alt='Flight Tracker for Scholars' /></a></div>";
            $html .= "<div class='centerHeader' style='float: left; text-align: center;'>$nameHTML</div>";
            $html .= "<div style='float:right; text-align: right; margin-right: $marginWidth;' class='responsiveHeader'><a href='$efsLink'><img src='$efsBase64' class='efsHeader' alt='Edge for Scholars' /></a></div>";
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
            "title" => "View Profile",
        ];
        $menu["Your Info"][] = [
            "action" => "survey",
            "title" => "Update Surveys",
        ];
        if ($this->doesHonorsSurveyExist()) {
            $menu["Your Info"][] = [
                "action" => "honors",
                "title" => "Submit Honors &amp; Awards",
            ];
        }
        if ($this->viewResources()) {
            $menu["Your Info"][] = [
                "action" => "resources",
                "title" => "Using Resources",
            ];
        }
        $menu["Your Info"][] = [
            "action" => "photo",
            "title" => "Your Photo",     // should search all projects; also, should display
        ];
        // TODO ORCID Bio Link in Your Info

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
            # TODO - phased release
            // $menu["Your Network"][] = [
            //    "action" => "connect",
            //     "title" => "Connect With Colleagues",     // flight connector
            // ];

            // $menu["Your Network"][] = [
            //    "action" => "resource_map",
            //    "title" => "Resource Map",     // flight map
            // ];

            # Newman Society success figures: externally launch career_dev/newmanFigures
            // $menu["Your Network"][] = [
            //    "action" => "stats",
            //    "title" => "Newman Society Statistics",
            // ];
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

        if ($this->token && $this->server) {
            $useridField = Download::getUseridField($this->token, $this->server);
            $redcapData = Download::fieldsForRecords($this->token, $this->server, ["record_id", "identifier_first_name", "identifier_middle", "identifier_last_name", $useridField], [$this->recordId]);
            $normativeRow = REDCapManagement::getNormativeRow($redcapData);
            if (
                !NameMatcher::matchName($this->firstName, $this->lastName, $normativeRow["identifier_first_name"], $normativeRow["identifier_last_name"])
                && (strtolower($this->username) != $normativeRow[$useridField])
            ) {
                return FALSE;
            }
        }
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
        $html .= self::makePortalButton("portal.uploadPhoto(\"#photoForm\");", "Upload");
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
        $html .= "<p>".self::makePortalButton("portal.submitPost(\"#newPost\");", "Submit Post")."</p>";
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
                        && self::canDelete($post['username'], $this->pid)
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

    private static function getNameAndEmailFromUserid($user) {
        $lookup = new REDCapLookupByUserid($user);
        $testNames = self::getTestNames();
        if (isset($testNames[$user])) {
            $name = self::makeName($testNames[$user][0], $testNames[$user][1]);
        } else {
            $name = $lookup->getName();
        }
        $email = $lookup->getEmail();
        return [$name, $email];
    }

    private function formatPost($post) {
        $user = $post['username'];
        $date = $_POST['date'] ?? date(self::DATETIME_FORMAT);
        $mssg = $post['message'];
        list($name, $email) = self::getNameAndEmailFromUserid($user);
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
        $deleteButton = self::canDelete($user, $this->pid) ? " ".self::makePortalButton("portal.deletePost(\"#posts\", \"$user\", \"$datetime\");", "Delete Post") : "";
        $emailHTML = $email ? " (".Links::makeMailtoLink($email, $email).")" : "";
        $html = "<p>$photoHTML<strong>$name</strong> at ".$longDate.$emailHTML.$deleteButton."</p>";
        $lines = preg_split("/[\n\r]+/", $mssg);
        $postHTML = implode("</p><p class='alignLeft'>", $lines);
        if (!$postHTML) {
            return "";
        }
        $html .= "<div class='centered max-width-600 post'><p class='alignLeft'>".$postHTML."</p></div>";
        return $html;
    }

    public static function canDelete($postUser, $pid) {
        return (
            ($postUser == Application::getUsername())
            || self::isAdmin($postUser, $pid)
        );
    }

    private static function isAdmin($postUser, $pid) {
        if (Application::isSuperUser()) {
            return TRUE;
        }
        $permittedUsers = [];
        if (Application::isVanderbilt()) {
            $permittedUsers[] = "pearsosj";
            $permittedUsers[] = "heltonre";
        }

        if ($pid) {
            $adminEmailList = Application::getSetting("admin_email", $pid);
            $adminEmails = $adminEmailList ? preg_split("/\s*,\s*/", $adminEmailList) : [];
        } else {
            $adminEmails = [];
        }
        $monitorEmailList = Application::getSystemSetting("bulletin_board_monitor");
        $monitorEmails = $monitorEmailList ? preg_split("/\s*,\s*/", $monitorEmailList) : [];
        $emails = array_unique(array_merge($adminEmails, $monitorEmails));

        foreach ($emails as $email) {
            if ($email) {
                $lookup = new REDCapLookupByEmail($email);
                $userid = strtolower($lookup->getUserid());
                if (!in_array($userid, $permittedUsers)) {
                    $permittedUsers[] = $userid;
                }
            }
        }


        $users = Application::getProjectUsers($pid);
        foreach ($users as $userid) {
            $userid = strtolower($userid);
            if (!in_array($userid, $permittedUsers)) {
                $permittedUsers[] = $userid;
            }
        }

        return in_array(strtolower($postUser), $permittedUsers);
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

    public function getScholarlyProducts() {
        $conversionStatusField = "summary_ever_last_any_k_to_r01_equiv";
        $metadata = Download::metadata($this->token, $this->server);
        $metadataFields = DataDictionaryManagement::getFieldsFromMetadata($metadata);
        $grantFields = REDCapManagement::getAllGrantFieldsFromFieldlist($metadataFields);
        $patentFields = ["patent_number", "patent_include"];
        $allCitationFields = DataDictionaryManagement::filterFieldsForPrefix($metadataFields, "citation");
        $altmetricFields = DataDictionaryManagement::filterFieldsForPrefix($allCitationFields, "citation_altmetric");
        $relevantCitationFields = [];
        foreach ($allCitationFields as $field) {
            if (!in_array($field, $altmetricFields) || ($field == "citation_altmetric_score")) {
                $relevantCitationFields[] = $field;
            }
        }
        $fields = DataDictionaryManagement::filterOutInvalidFields($metadata, array_unique(array_merge(["record_id", $conversionStatusField], $grantFields, $relevantCitationFields, $patentFields)));

        $redcapData = Download::fieldsForRecords($this->token, $this->server, $fields, [$this->recordId]);
        $pubs = new Publications($this->token, $this->server, $metadata);
        $pubs->setRows($redcapData);
        $grants = new Grants($this->token, $this->server, $metadata);
        $grants->setRows($redcapData);
        $grants->compileGrants();
        $patents = new Patents($this->recordId, $this->pid, $this->firstName, $this->lastName);
        $patents->setRows($redcapData);

        $rcrs = [];
        $altmetricScores = [];
        foreach ($pubs->getCitations() as $citation) {
            $rcr = $citation->getVariable("rcr");
            if ($rcr) {
                $rcrs[] = $rcr;
            }
            $altmetricScore = $citation->getVariable("altmetric_score");
            if ($altmetricScore) {
                $altmetricScores[] = $altmetricScore;
            }
        }

        $rcrLink = "https://icite.od.nih.gov/";
        $entries = [];
        if ($pubs->getCitationCount() > 0) {
            $entries["Number of Publications"] = $pubs->getCitationCount();
            if (count($rcrs) > 0) {
                $entries["Average ".Links::makeLink($rcrLink, "Relative Citation Ratio (RCR)")] = REDCapManagement::pretty(array_sum($rcrs) / count($rcrs), 2);
                if (count($rcrs) > 1) {
                    $entries["RCR Range (n=".count($rcrs).")"] = REDCapManagement::pretty(min($rcrs), 1)." - ".REDCapManagement::pretty(max($rcrs), 1);
                }
            }
            if (count($altmetricScores) > 1) {
                $entries["Range of Altmetric Scores (n=".count($altmetricScores).")"] = REDCapManagement::pretty(min($altmetricScores), 1)." - ".REDCapManagement::pretty(max($altmetricScores), 1);
            } else if (count($altmetricScores) == 1) {
                $entries["Altmetric Score (n=1)"] = $altmetricScores[0];
            }
        } else {
            $entries["Publications"] = "We see that you're just getting started... ".REDCapManagement::json_encode_with_spaces($relevantCitationFields);
        }

        $conversionStatusValue = REDCapManagement::findField($redcapData, $this->recordId, $conversionStatusField);
        if ($conversionStatusValue) {
            $conversionChoices = DataDictionaryManagement::getChoicesForField($this->pid, $conversionStatusField);
            $statusText = $conversionChoices[$conversionStatusValue] ?? $conversionStatusValue;
            $convertedStatuses = [
                "Converted Any K to R01-or-Equivalent While on K",
                "Converted Any K to R01-or-Equivalent in While on K",
                "Converted Any K to R01-or-Equivalent not While on K",
                "Converted Any K to R01-or-Equivalent Not While on K",
            ];
            if (in_array($statusText, $convertedStatuses)) {
                $statusText = "Converted K-or-Equivalent to R01-or-Equivalent";
            }
            $entries["Conversion Status"] = $statusText;
        }

        if ($grants->getCount("all") > 0) {
            $js = "portal.takeAction(\"grant_funding\", \"Grant Funding by Year\");";
            $budgetCaveat = "<span class='smaller'>Budgets often are not precise. See <a href='javascript:;' onclick='$js'>graph by year</a>.</span>";
            $entries["Total Number of Grants"] = $grants->getCount("all");
            $totalBudgets = [];
            $directBudgets = [];
            foreach ($grants->getGrants("all") as $grant) {
                $grantTotalBudget = $grant->getVariable("total_budget");
                $grantDirectBudget = $grant->getVariable("direct_budget");
                if ($grantTotalBudget) {
                    $totalBudgets[] = $grantTotalBudget;
                }
                if ($grantDirectBudget) {
                    $directBudgets[] = $grantDirectBudget;
                }
            }

            if (count($totalBudgets) == count($directBudgets)) {
                $entries["Combined Direct Budgets<br/>$budgetCaveat"] = REDCapManagement::prettyMoney(array_sum($directBudgets), FALSE);
            }
            $entries["Combined Total Budgets<br/>$budgetCaveat"] = REDCapManagement::prettyMoney(array_sum($totalBudgets), FALSE);
        }

        if ($patents->getCount() > 0) {
            $entries["Number of Patents"] = $patents->getCount();
        }

        $htmlRows = [];
        foreach ($entries as $label => $value) {
            if ($value) {
                if (count($htmlRows) % 2 == 0) {
                    $portalClass = "portalEven";
                } else {
                    $portalClass = "portalOdd";
                }
                $htmlRows[] = "<tr class='$portalClass'><th>$label</th><td>$value</td></tr>";
            }
        }

        $html = "<h4>Scholarly Products</h4>";
        if (empty($htmlRows)) {
            $html .= "<p class='centered'>It looks like you are just getting started... Good luck!</p>";
        } else {
            $html .= "<table class='centered max-width'><tbody>";
            $html .= implode("", $htmlRows);
            $html .= "</tbody></table>";
        }
        return $html;
    }

    public function getDemographics() {
        $metadataFields = Download::metadataFieldsByPid($this->pid);
        $summaryFields = array_unique(array_merge(["record_id"], DataDictionaryManagement::filterFieldsForPrefix($metadataFields, "summary")));
        $awardFields = DataDictionaryManagement::filterFieldsForPrefix($metadataFields, "summary_award");
        $fields = [];
        foreach ($summaryFields as $field) {
            if (!in_array($field, $awardFields)) {
                $fields[] = $field;
            }
        }

        $redcapData = Download::fieldsForRecords($this->token, $this->server, $fields, [$this->recordId]);
        $row = REDCapManagement::getNormativeRow($redcapData);
        $summaryMetadata = Download::metadata($this->token, $this->server, $fields);
        $summaryChoices = DataDictionaryManagement::getChoices($summaryMetadata);
        $checkboxFields = DataDictionaryManagement::getFieldsOfType($summaryMetadata,  "checkboxes");

        $disadvUrl= "https://grants.nih.gov/grants/guide/notice-files/NOT-OD-20-031.html";
        $adaUrl = "https://www.ada.gov/pubs/adastatute08.htm#12102";
        $urmUrl = "https://grants.nih.gov/grants/guide/notice-files/NOT-OD-20-031.html";
        $entries = [
            "Primary Mentor(s)" => "summary_mentor",
            "Degrees" => "summary_all_degrees",
            "Primary Department" => "summary_primary_dept",
            "Gender Identity (NIH Categories)" => "summary_gender",
            "Race &amp; Ethnicity" => "summary_race_ethnicity",
            "Date of Birth" => "summary_dob",
            "Under-Represented Minority Status<br/>(".Links::makeLink($urmUrl, "Federal Definition").")" => "summary_urm",
            "Citizenship Status" => "summary_citizenship",
            "Disadvantaged Status<br/>(".Links::makeLink($disadvUrl, "Federal Definition").")" => "summary_disadvantaged",
            "Disability Status<br/>(".Links::makeLink($adaUrl, "Federal Definition").")" => "summary_disability",
            "Current Academic Division" => "summary_division",
            "Current Academic Rank" => "summary_current_rank",
            "Start of Training" => "summary_training_start",
            "End of Training" => "summary_training_end",
            "Tenure Status" => "summary_current_tenure",
        ];

        $htmlRows = [];
        foreach ($entries as $label => $field) {
            $value = "";
            if (in_array($field, $checkboxFields)) {
                $checkedIndices = [];
                foreach ($row as $f => $v) {
                    if ($v && preg_match("/^$field"."___/", $f)) {
                        $checkedIndices = str_replace($field."___", "", $f);
                    }
                }

                $checkedLabels = [];
                foreach ($checkedIndices as $index) {
                    if (isset($summaryChoices[$field][$index])) {
                        $checkedLabels[] = $summaryChoices[$field][$index];
                    } else {
                        $checkedLabels[] = $index;
                    }
                }
                if (!empty($checkedLabels)) {
                    $value = REDCapManagement::makeConjunction($checkedLabels);
                }
            } else if ($row[$field]) {
                if (isset($summaryChoices[$field])) {
                    $value = $summaryChoices[$field][$row[$field]] ?? $row[$field];
                } else if (DateManagement::isDate($row[$field])) {
                    $value = DateManagement::YMD2LongDate($row[$field]);
                } else {
                    $value = $row[$field];
                }
            }
            if ($value) {
                if (count($htmlRows) % 2 == 0) {
                    $portalClass = "portalEven";
                } else {
                    $portalClass = "portalOdd";
                }
                $htmlRows[] = "<tr class='$portalClass'><th>$label</th><td>$value</td></tr>";
            }
        }

        $html = "<h4>Demographics</h4>";
        if (empty($htmlRows)) {
            $html .= "<p class='centered'>We don't have any information on you!<br/>".self::makePortalButton("portal.takeAction(\"survey\", \"Update a Survey\");", "Submit an Initial Survey")."</p>";
        } else {
            $html .= "<table class='centered max-width'><tbody>";
            $html .= implode("", $htmlRows);
            $html .= "</tbody></table>";
        }
        return $html;
    }

    public function getHonorsAndAwards() {
        $instrument = "honors_and_awards";
        $prefix = REDCapManagement::getPrefixFromInstrument($instrument);
        $metadataFields = Download::metadataFieldsByPid($this->pid);
        $fields = array_unique(array_merge(["record_id"], DataDictionaryManagement::filterFieldsForPrefix($metadataFields, $prefix)));
        $html = "<h4>Honors &amp; Awards</h4>";
        $redcapData = Download::fieldsForRecords($this->token, $this->server, $fields, [$this->recordId]);
        $maxInstance = REDCapManagement::getMaxInstance($redcapData, $instrument, $this->recordId);
        $honorsText = $this->doesHonorsSurveyExist() ? " <a href='javascript:;' onclick='portal.takeAction(\"honors\", \"Submit Honors &amp; Awards\");'>Share some here</a>." : "";
        if ($maxInstance == 0) {
            $html .= "<p class='centered'>None have yet been entered.$honorsText Aim high!</p>";
        } else {
            $instancesByDate = [];
            foreach ($redcapData as $row) {
                if ($row['redcap_repeat_instrument'] == $instrument) {
                    $instance = $row['redcap_repeat_instance'];
                    $date = $row['honor_date'] ?: date("Y-m-d");
                    $key = strtotime($date) + REDCapManagement::padInteger($instance, 6);
                    $instancesByDate[$key] = $instance;
                }
            }
            krsort($instancesByDate);

            $typeChoices = DataDictionaryManagement::getChoicesForField($this->pid, "honor_type");
            foreach (array_values($instancesByDate) as $instance) {
                $row = REDCapManagement::getRow($redcapData, $this->recordId, $instrument, $instance);
                $name = $row['honor_name'];
                $org = $row['honor_org'];
                $type = $row['honor_type'] ? $typeChoices[$row['honor_type']] : "";
                $exclusivity = $row['honor_exclusivity'];
                $date = $row['honor_date'] ? " on ".DateManagement::YMD2LongDate($row['honor_date']) : "";
                $notes = preg_replace("/[\n\r]+/", "<br/>", $row['honor_notes']);

                $html .= "<p class='centered'><strong>$name$date</strong>";
                if ($org) {
                    $html .= "<br/>from $org";
                }
                if ($type) {
                    $html .= "<br/>Type of Award: $type";
                }
                if ($exclusivity) {
                    $html .= "<br/>Exclusivity: $exclusivity";
                }
                if ($notes) {
                    $html .= "<div class='alignLeft max-width-600 centered'>$notes</div>";
                }
                $html .= "</p>";
            }
        }
        if ($this->doesHonorsSurveyExist()) {
            $html .= "<p class='centered'><a href='javascript:;' onclick='portal.takeAction(\"honors\", \"Honors &amp; Awards\");' class='portalButton'>Update Your Honors</a>";
        }
        return $html;
    }

    public function viewProfile() {
        $html = "<p class='portalDescription'>These data have been automatically collected by Flight Tracker and may contain errors. The demographic data can be updated by filling out an Initial Survey. Grant and publication information can be updated by a Followup survey. Surveys are available under <a href=javascript:;' onclick='portal.takeAction(\"survey\", \"Update Surveys\");'>Update Your Info</a>.</p>";
        $html .= "<h3>Flight Tracker Profile for {$this->name}</h3>";
        $html .= $this->getDemographics();
        $html .= "<hr/>";
        $html .= $this->getScholarlyProducts();
        if ($this->doesHonorsSurveyExist()) {
            $html .= "<hr/>";
            $html .= $this->getHonorsAndAwards();
        }
        return $html;
    }

    public function reopenSurvey($instrument, $instance = 1) {
        $sql = "DELETE r.* FROM redcap_surveys_response AS r INNER JOIN redcap_surveys_participants AS p ON p.participant_id = r.participant_id INNER JOIN redcap_surveys AS s ON p.survey_id = s.survey_id WHERE r.record=? AND r.instance=? AND s.project_id=? AND s.form_name=?";
        $this->module->query($sql, [$this->recordId, $instance, $this->pid, $instrument]);
        return \REDCap::getSurveyLink($this->recordId, $instrument, "", 1, $this->pid)."&resetDate";
    }

    public function viewResources() {
        if (Application::isVanderbilt()) {
            $link = "https://edgeforscholars.vumc.org/";
        } else {
            $link = Application::getSetting("mentee_agreement_link", $this->pid);
        }
        if ($link && URLManagement::isValidURL($link)) {
            return "<h4>".Links::makeLink($link, "Click here to explore available resources")."</h4>";
        } else {
            return "";
        }
    }

    public function viewResourcesOld() {
        $resources = DataDictionaryManagement::getChoicesForField($this->pid, "resources_resource");
        if (empty($resources)) {
            # this should not happen
            return "<h4>No Resources are Set Up for This Project</h4>";
        }

        $adminEmail = Application::getSetting("admin_email", $this->pid);
        $html = "<h4>Project Resources</h4>";
        $html .= "<p class='portalDescription'>Several resources are supplied by your institution to advance your career development. A list of them is below.</p>";
        $html .= "<p class='centered'>".implode("<br/>", array_values($resources))."</p>";
        $html .= "<p class='centered'>Please contact ".Links::makeMailtoLink($adminEmail, "this project's administrator", "Institutional Resources from Flight Tracker")." to learn more about how to access these resources.</p>";

        return $html;
    }

    private function doesHonorsSurveyExist() {
        $metadataFields = Download::metadataFieldsByPid($this->pid);
        $testField = "honorssurvey_honor";
        return in_array($testField, $metadataFields);
    }

    public function getHonorsSurvey() {
        $form = "honors_survey";
        $testField = "honorssurvey_honor";
        $description = "Have you just accomplished something great and want to share? Congratulations!";
        if ($this->doesHonorsSurveyExist()) {
            if ($this->token && $this->server && $this->recordId) {
                $fields = ["record_id", $testField];
                $redcapData = Download::fieldsForRecords($this->token, $this->server, $fields, [$this->recordId]);
                $maxInstance = REDCapManagement::getMaxInstance($redcapData, $form, $this->recordId);

                $newLink = \REDCap::getSurveyLink($this->recordId, $form, "", $maxInstance + 1, $this->pid);
                $linkHTML = Links::makeLink($newLink, "Share Your New Honor", FALSE, 'portalButton');
                $description.= " Please fill out this short REDCap survey to share with your project's administrative staff.";
                return "<h4>Honors &amp; Awards</h4><p class='portalDescription'>$description</p><p>$linkHTML</p>";
            }
        }
        $adminEmail = Application::getSetting("admin_email", $this->pid);
        if ($adminEmail) {
            $description .= " Please email ".Links::makeMailtoLink($adminEmail, "this project's administrator", "An Honor for Flight Tracker")." with the good news.";
        }
        return "<p class='portalDescription'>$description</p><h4>Survey Not Yet Available</h4>";
    }

    public function getFlightTrackerSurveys() {
        if ($this->token && $this->server && $this->recordId) {
            $html = "<h3>Flight Tracker Surveys for This Project</h3>";
            $html .= $this->getInitialSurveyHTML();
            $html .= "<hr/>";
            $html .= $this->getFollowupHTML();
            return $html;
        } else {
            return "<h3>Not Available</h3>";
        }
    }

    private function getInitialSurveyHTML()
    {
        $fields = ["record_id", "check_name_last", "check_date"];
        $redcapData = Download::fieldsForRecords($this->token, $this->server, $fields, [$this->recordId]);
        $normativeRow = REDCapManagement::getNormativeRow($redcapData);

        if (!$normativeRow['check_name_last']) {
            $latestDate = "Never";
            $text = "Fill out a new survey";
            $newLink = \REDCap::getSurveyLink($this->recordId, "initial_survey", "", 1, $this->pid);
            $linkHTML = Links::makeLink($newLink, $text, FALSE, "portalButton");
        } else {
            if ($normativeRow['check_date']) {
                $ymd = $normativeRow['check_date'];
                $latestDate = DateManagement::YMD2LongDate($ymd);
                $text = "Update your survey";
            } else {
                $latestDate = "Unknown";
                $text = "Update your survey";
            }
            $linkHTML = "<a href='javascript:;' onclick='portal.reopenSurvey(\"initial_survey\", \"{$this->pid}\", \"{$this->recordId}\");' class='portalButton'>$text</a>";
        }

        if (Application::isVanderbilt()) {
            $portalDescription = "This one-time survey allows you to submit demographic information, educational history, and information about your grants &amp; publications. This information is extremely helpful to Vanderbilt as we write applications for career-development funding. demographic information is only ever used in aggregate.<br/><br/>This survey takes an estimated 20-30 minutes to complete. Any information we've gathered from other sources is pre-filled for you to check. This survey will be shared with other Flight Trackers that track you (see list at bottom of the page).";
        } else {
            $portalDescription = "This one-time survey allows you to submit demographic information, educational history, and information about your grants &amp; publications.<br/><br/>This survey takes an estimated 20-30 minutes to complete. Any information gathered from other sources is pre-filled for you to check. This survey will be shared with other Flight Trackers that track you (see list at bottom of the page).";
        }

        return "<h4>Initial Survey</h4>
<p class='portalDescription'>$portalDescription</p>
<p><strong>Date Completed</strong>: $latestDate<br/>$linkHTML</p>";
    }

    private function getFollowupHTML() {
        $fields = ["record_id", "followup_name_last", "followup_date"];
        $redcapData = Download::fieldsForRecords($this->token, $this->server, $fields, [$this->recordId]);
        $maxFollowupInstance = REDCapManagement::getMaxInstance($redcapData, "followup", $this->recordId);

        $latestTs = 0;
        foreach ($redcapData as $row) {
            if ($row['followup_date']) {
                $ts = strtotime($row['followup_date']);
                if ($ts && ($ts > $latestTs)) {
                    $latestTs = $ts;
                }
            }
        }
        if ($maxFollowupInstance == 0) {
            $latestDate = "Never";
        } else if ($latestTs) {
            $ymd = date("Y-m-d", $latestTs);
            $latestDate = DateManagement::YMD2LongDate($ymd);
        } else {
            $latestDate = "Unknown";
        }
        $newLink = \REDCap::getSurveyLink($this->recordId, "followup", "", $maxFollowupInstance + 1, $this->pid);
        $linkHTML = Links::makeLink($newLink, "Fill out a new survey", FALSE, 'portalButton');
        return "<h4>Regular Follow Up Survey</h4>
<p class='portalDescription'>This periodic survey requests only infromation about your professional successes in the near past and takes about 10-15 minutes to complete. This survey may also be shared with other Flight Trackers in which you are tracked (see list at the bottom of the page).</p>
<p><strong>Last Updated</strong>: $latestDate<br/>$linkHTML</p>";
    }

    public function addPost($postText) {
        $post = [
            "message" => $postText,
            "username" => $this->username,
            "date" => date(self::DATETIME_FORMAT),
        ];
        $formattedPost = $this->formatPost($post);
        list($name, $email) = self::getNameAndEmailFromUserid($this->username);
        $portalLink = Application::link("portal/index.php");
        $intro = "<h2>From $name (".Links::makeMailtoLink($email, $email).")</h2><p>".Links::makeLink($portalLink, "Click Here to Access the Scholar Portal")."</p>";

        if (Application::isVanderbilt()) {
            $to = Application::isLocalhost() ? "scott.j.pearson@vumc.org" : "rebecca.helton@vumc.org,scott.j.pearson@vumc.org";
        } else {
            $to = Application::getSystemSetting("bulletin_board_monitor");
        }
        if ($to) {
            \REDCap::email($to, "noreply.flighttracker@vumc.org", "Flight Tracker - New Bulletin Board Post", $intro.$formattedPost);
        }

        $i = 0;
        $prefix = self::BOARD_PREFIX;
        do {
            $i++;
            $result = Application::getSystemSetting($prefix . $i);
            if (is_array($result) && (count($result) < self::NUM_POSTS_PER_SETTING)) {
                $result[] = $post;
                Application::saveSystemSetting($prefix.$i, $result);
                return $formattedPost;
            }
        } while (isset($result) && ($result !== ""));

        Application::saveSystemSetting($prefix.$i, [$post]);


        return $formattedPost;
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
        $html .= "<p class='centered max-width'>".self::makePortalButton("portal.selectMentors(\"{$this->driverURL}\", \"{$this->pid}\", \"{$this->recordId}\", \"$mentorName\", \"[name=$radioName]\");", "Make Selection")."</p>";
        return $html;
    }

    private static function makePortalButton($js, $text) {
        $js = str_replace("'", "\"", $js);
        return "<a href='javascript:;' class='portalButton' onclick='$js'>$text</a>";
    }

    private function makeMentorSetup($priorMessage = "", $index = NULL) {
        $suffix = isset($index) ? "_".$index : "";
        $html = "";
        if ($priorMessage) {
            $html .= $priorMessage;
        }
        $html .= "<p class='centered max-width'><input type='text' id='mentor$suffix' name='mentor$suffix' placeholder='Mentor Name' /></p>";

        $lines = [];
        $lines[] = self::makePortalButton("portal.searchForMentor(\"{$this->mmaURL}\", \"{$this->pid}\", \"{$this->recordId}\", \"#mentor$suffix\");", "Search If They Have REDCap Access");
        $lines[] = "";
        $lines[] = "<strong>-OR-</strong>";
        $lines[] = "";
        $lines[] = "<label for='mentor_userid$suffix'>Input the Mentor's User ID for Accessing REDCap</label>";
        $lines[] = "<input type='text' id='mentor_userid$suffix' name='mentor_userid$suffix' placeholder=\"Mentor's User ID\" />";
        $lines[] = self::makePortalButton("portal.submitNameAndUserid(\"{$this->mmaURL}\", \"{$this->pid}\", \"{$this->recordId}\", \"#mentor$suffix\", \"#mentor_userid$suffix\");", "Submit Name &amp; User ID");

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
        $html .= "<h4>".Links::makeLink($mmaURL, "Access Your Mentee-Mentor Agreement for ".$this->projectTitle, FALSE)."</h4>";
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
    protected $module;
    protected $pidRecords;
    protected $token;
    protected $server;
}
