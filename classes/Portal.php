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

    public static function getCurrentUserIDAndName() {
        $version = Application::getVersion();
        if (
            Application::isVanderbilt()
            && REDCapManagement::versionGreaterThanOrEqualTo("6.0.0", $version)
            && isset($_GET['uid'])
        ) {
            # pre release
            if ($_GET['uid'] == "bastarja") {
                return ["bastarja", "Julie", "Bastarache"];
            } else if ($_GET['uid'] == "edwardt5") {
                return ["edwardt5", "Todd", "Edwards"];
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

    public static function getMatches($username, $firstName, $lastName, $pids) {
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
        return [$matches, $projectTitles, self::getPhoto($matches)];
    }

    public static function getPage($relativeFileLocation, $pid, $getParams = []) {
        $getParams['pid'] = (string) $pid;
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
            return "$fn $ln";
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

    public static function getPhoto($allMatches) {
        $targetField = "identifier_picture";
        if ($allMatches) {
            foreach ($allMatches as $pid => $recordsAndNames) {
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
        }
        return "";
    }

    # 3 words max
    public static function getMenu() {
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
                "action" => "resources",
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

    public static function verifyMatches($matches, $username, $firstName, $lastName) {
        return TRUE;    // TODO
    }

    public static function getHeaders() {
        $html = "<title>Flight Tracker: Scholar Portal</title>";
        $html .= Application::getImportHTML();
        return $html;
    }

    # TODO Uploads to all projects - should it only upload to ones without an existing photo? Am checking with Arnita...
    public static function uploadPhoto($filename, $mimeType, $matches) {
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

    public static function getModifyPhotoPage($allMatches) {
        $base64 = self::getPhoto($allMatches);
        if ($base64) {
            $html = "<h3>Replace Your Photo</h3>";
        } else {
            $html = "<h3>Add a Photo</h3>";
        }
        $driverLink = Application::link("portal/driver.php").(isset($_GET['uid']) ? "&uid=".$_GET['uid'] : "");
        $html .= "<form action='$driverLink' method='POST' enctype='multipart/form-data' id='photoForm'>";
        $html .= "<input type='hidden' name='action' value='upload_photo' />";
        $html .= "<p class='centered'><label for='photoFile'>Photo:</label> <input type='file' id='photoFile' name='photoFile' onchange='portal.validateFile(this);' /><br/>";
        $html .= "<button onclick='portal.uploadPhoto(\"#photoForm\"); return false;'>Upload</button></p>";
        $html .= "</form>";
        return $html;
    }

    public static function makeMentoringPortal($pid, $recordId, $projectTitle, $driverURL) {
        $redcapData = Download::getDataByPid($pid, ["record_id", "summary_mentor", "summary_mentor_userid"], [$recordId]);
        $mentorList = REDCapManagement::findField($redcapData, $recordId, "summary_mentor");
        $mentorUseridList = REDCapManagement::findField($redcapData, $recordId, "summary_mentor_userid");
        $mentors = $mentorList ? preg_split("/\s*[,;\/]\s*/", $mentorList) : [];
        $mentorUserids = $mentorUseridList ? preg_split("/\s*[,;]\s*/", $mentorUseridList) : [];

        $mssg = "<p class='centered max-width'>Your do not have a mentor set up. Would you like to add a Mentor?</p>";
        $html = "<h3>Your Mentoring Portal for $projectTitle</h3>";
        $html .= "<div id='searchResults'></div>";
        if (Application::isMSTP($pid)) {
            $html .= "<h4>Coming Soon</h4>";
        } else if (empty($mentors) && empty($mentorUserids)) {
            $html .= self::makeMentorSetup($driverURL, $pid, $recordId, $mssg);
        } else if (empty($mentorUserids)) {
            $i = 1;
            foreach ($mentors as $mentorName) {
                list($firstName, $lastName) = NameMatcher::splitName($mentorName, 2);
                $lookup = new REDCapLookup($firstName, $lastName);
                $uidsAndNames = $lookup->getUidsAndNames(TRUE);
                $html .= self::processUidsAndNames($driverURL, $mentorName, $pid, $recordId, $uidsAndNames, $i, "<p class='centered max-width'>This mentor does not match any REDCap users. Would you like to add another mentor?</p>");
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
                $uploadRow = ["record_id" => $recordId, "summary_mentor" => $mentorList];
                Upload::rowsByPid([$uploadRow], $pid);
                $html .= self::makeLiveMentorPortal($mentors, $mentorUserids, $pid, $projectTitle);
            } else {
                $html .= self::makeMentorSetup($driverURL, $pid, $recordId, $mssg);
            }
        } else {
            $html .= self::makeLiveMentorPortal($mentors, $mentorUserids, $pid, $projectTitle);
        }
        return $html;
    }

    private static function makeNewPostHTML() {
        $html = "<h4>Make a New Post</h4>";
        $html .= "<p><textarea id='newPost'></textarea></p>";
        $html .= "<p><button onclick='portal.submitPost(\"#newPost\");'>Submit Post</button></p>";
        return $html;
    }

    public static function getInstitutionBulletinBoard() {
        $posts = self::getBoardPosts();
        $html = "<h3>Institutional Bulletin Board</h3>";
        $html .= self::makeNewPostHTML();
        $html .= "<h4>Existing Posts from Your Colleagues</h4>";
        $html .= "<div id='posts'>";
        if (empty($posts)) {
            $html .= "<p>Nothing has been posted yet.</p>";   // text also in portal.js
        } else {
            $rows = [];
            foreach ($posts as $post) {
                if (!empty($post)) {
                    $rows[] = self::formatPost($post);
                }
            }
            $html .= implode("<hr/>", $rows);
        }
        $html .= "</div>";

        return $html;
    }

    # returns boolean if an entry was deleted
    public static function deletePost($username, $datetime) {
        $i = 0;
        $prefix = self::BOARD_PREFIX;
        do {
            $i++;
            $result = Application::getSystemSetting($prefix . $i);
            if (is_array($result)) {
                foreach ($result as $i => $post) {
                    if (($post['username'] == $username) && ($post['date'] == $datetime)) {
                        $newResult = [];
                        foreach ($result as $j => $post2) {
                            if ($j !== $i) {
                                $newResult[] = $post2;
                            } else {
                                $newResult[] = [];
                            }
                        }
                        Application::saveSystemSetting($prefix.$i, $newResult);
                        return TRUE;
                    }
                }
            }
        } while ($result !== "");
        return FALSE;
    }

    private static function formatPost($post) {
        $user = $post['username'];
        $lookup = new REDCapLookupByUserid($user);
        $name = $lookup->getName();
        $email = $lookup->getEmail();
        $date = DateManagement::datetime2LongDateTime($post['date'] ?? date(self::DATETIME_FORMAT));
        $mssg = $post['message'];
        $storedData = Application::getSystemSetting($user) ?: [];
        $matches = $storedData['matches'] ?? [];
        if (!empty($matches) && !$name) {
            foreach ($matches as $pid => $recordsAndNames) {
                foreach ($recordsAndNames as $recordId => $n) {
                    if ($n) {
                        $name = $n;
                        break;
                    }
                }
                if ($name) {
                    break;
                }
            }
        } else if (!$name) {
            $name = $user;
        }
        $photo = self::getPhoto($matches);
        if (!$email) {
            $email = self::getEmail($matches);
        }
        return self::makePostHTML($name, $email, $date, $mssg, $photo);
    }

    private static function makePostHTML($name, $email, $date, $mssg, $photoBase64) {
        $photoHTML = $photoBase64 ? "<img src='$photoBase64' class='photo' alt='$name' /><br/>" : "";
        $html = "<p>$photoHTML<strong>$name</strong> at $date (<a href='mailto:$email'>$email</a>)</p>";
        $html .= "<p>$mssg</p>";
        return $html;
    }

    private static function getEmail($allMatches) {
        foreach ($allMatches as $pid => $recordsAndNames) {
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
        return "";
    }

    public static function addPost($postText, $username) {
        $post = [
            "message" => $postText,
            "username" => $username,
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
                return self::formatPost($post);
            }
        } while ($result !== "");

        Application::saveSystemSetting($prefix.$i, [$post]);
        return self::formatPost($post);
    }

    private static function getBoardPosts() {
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
        return $posts;
    }

    public static function processUidsAndNames($driverURL, $mentorName, $pid, $recordId, $uidsAndNames, $i, $priorMessage = "") {
        $html = "<h4>$mentorName</h4>";
        if (empty($uidsAndNames)) {
            $html .= self::makeMentorSetup($driverURL, $pid, $recordId, $priorMessage, $i);
        } else {
            $html .= self::makeConfirmationTable($driverURL, $mentorName, $pid, $recordId, $uidsAndNames, $i);
        }
        return $html;
    }

    private static function makeConfirmationTable($url, $mentorName, $pid, $recordId, $uidsAndNames, $index) {
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
        $html .= "<p class='centered max-width'><button onclick='portal.selectMentors(\"$url\", \"$pid\", \"$recordId\", \"$mentorName\", \"[name=$radioName]\"); return false;'>Make Selection</button></p>";
        return $html;
    }

    private static function makeMentorSetup($url, $pid, $recordId, $priorMessage = "", $index = NULL) {
        $suffix = isset($index) ? "_".$index : "";
        $html = "";
        if ($priorMessage) {
            $html .= $priorMessage;
        }
        $html .= "<p class='centered max-width'><input type='text' id='mentor$suffix' name='mentor$suffix' placeholder='Mentor Name' /></p>";

        $lines = [];
        $lines[] = "<button onclick='portal.searchForMentor(\"$url\", \"$pid\", \"$recordId\", \"#mentor$suffix\"); return false;'>Search If They Have REDCap Access</button>";
        $lines[] = "";
        $lines[] = "<strong>-OR-</strong>";
        $lines[] = "";
        $lines[] = "<label for='mentor_userid$suffix'>Input the Mentor's User ID for Accessing REDCap</label>";
        $lines[] = "<input type='text' id='mentor_userid$suffix' name='mentor_userid$suffix' placeholder=\"Mentor's User ID\" />";
        $lines[] = "<button onclick='portal.submitNameAndUserid(\"$url\", \"$pid\", \"$recordId\", \"#mentor$suffix\", \"#mentor_userid$suffix\"); return false;'>Submit Name &amp; User ID</button>";

        $html .= "<p class='centered max-width'>".implode("<br/>", $lines)."</p>";
        return $html;
    }

    private static function makeLiveMentorPortal($mentors, $mentorUserids, $pid, $projectTitle) {
        $html = "<p class='centered max-width'>";
        if (count($mentors) == 1) {
            $mentorUseridList = implode(", ", $mentorUserids);
            $html .= "Your mentor is {$mentors[0]}, and they have access to REDCap through the user-id $mentorUseridList.";
        } else {
            $html .= "Your mentors are ".REDCapManagement::makeConjunction($mentors).". The following user-id(s) provide them access: ".REDCapManagement::makeConjunction($mentorUserids).".";
        }
        $html .= "</p>";

        $mmaURL = Application::getMenteeAgreementLink($pid);
        $html .= "<h4>".Links::makeLink($mmaURL, "Access Your Mentee-Mentor Agreement for $projectTitle", TRUE)."</h4>";
        return $html;
    }
}
