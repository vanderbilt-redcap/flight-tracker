<?php

namespace Vanderbilt\CareerDevLibrary;

use function Vanderbilt\FlightTrackerExternalModule\appendCitationLabel;

require_once(__DIR__ . '/ClassLoader.php');


class Portal {

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
            } else {
                error_log("Portal: Skipping $pid");
            }
        }
        return [$matches, $projectTitles];
    }

    public static function getPage($relativeFileLocation, $pid, $getParams = []) {
        $getParams['pid'] = (string) $pid;
        $page = preg_replace("/^\//", "", $relativeFileLocation);
        $pageWithoutPHP = preg_replace("/\.php$/", "", $page);
        $getParams['page'] = $pageWithoutPHP;
        $getParams['prefix'] = Application::getPrefix();
        $getParams['hideHeader'] = "1";
        $getParams['headers'] = "false";
        error_log("getPage ($pid): ".json_encode($getParams));
        $filename = __DIR__."/../".$page;
        error_log("file: ".$filename);

        $oldGet = $_GET;
        $_GET = $getParams;
        ob_start();
        include($filename);
        $html = ob_get_clean();
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

    # 3 words max
    public static function getMenu() {
        $menu = [];
        $menu[] = [
            "action" => "bio",
            "title" => "Your Achievements",     // list of honors, pubs, grants & patents - flagging and can add; encouragement
        ];
        // encouraging message if pubs are blank
        $menu[] = [
            "action" => "scholar_collaborations",
            "title" => "Your Publishing Collaborations",      // social network graph
        ];
        $menu[] = [
            "action" => "pubs_impact",
            "title" => "Your Publishing Impact",      // combined & deduped RCR graph; Altmetric summary & links
        ];
        $menu[] = [
            "action" => "timelines",
            "title" => "Your Grant Timelines",     // CDAs & all grants; encouraging message if blank
        ];
        if (Application::isVanderbilt()) {
            // need to check whether FTs track grant submissions
            $menu[] = [
                "action" => "submissions",
                "title" => "Your Grant Submissions",    // timeline
            ];
        }
        $menu[] = [
            "action" => "group_collaborations",
            "title" => "Group Publishing Collaborations",
        ];
        $menu[] = [
            "action" => "info",
            "title" => "Update Your Information",              // surveys; talk to each other
        ];
        $menu[] = [
            "action" => "honors",
            "title" => "Update Your Honors",             // prior honors + redcap survey; talk to each other
        ];
        $menu[] = [
            "action" => "mma",
            "title" => "Mentoring Portal",             // set up mentor(s); fill out MMAs; talk to each other
        ];
        if (Application::isVanderbilt()) {
            $menu[] = [
                "action" => "connect",
                "title" => "Connect With Colleagues",     // flight connector
            ];
        }
        $menu[] = [
            "action" => "board",
            "title" => "Scholar Bulletin Board",
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
}