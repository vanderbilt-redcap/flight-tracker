<?php

namespace Vanderbilt\CareerDevLibrary;

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
            $username = Sanitizer::sanitize($_GET['uid']);
            $info = REDCapLookup::getUserInfo($username);
            return [$username, $info['user_firstname'] ?? "", $info['user_lastname'] ?? ""];
        } else {
            return REDCapLookup::getCurrentUserIDAndName();
        }
    }

    public static function getMatches($username, $firstName, $lastName, $pids) {
        $usernameInLC = strtolower($username);
        $matchedRecordsByUserid = [];
        $matchedRecordsByName = [];
        $projectTitles = [];
        foreach ($pids as $pid) {
            $token = Application::getSetting("token", $pid);
            $server = Application::getSetting("server", $pid);
            if ($token && $server) {
                Application::setPid($pid);
                $userids = Download::userids($token, $server);
                $firstNames = Download::firstnames($token, $server);
                $lastNames = Download::lastnames($token, $server);
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
                    $matchedRecordsByUserid[$pid] = $matchedRecordsInProject;
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
                    $matchedRecordsByName[$pid] = $matchedRecordsInProject;
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
        return [$matchedRecordsByName, $matchedRecordsByUserid, $projectTitles];
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

            $pixels = 200;
            $margin = 8;
            $marginWidth = $margin."px";
            $logoWidth = $pixels."px";
            $outsideWidth = (2 * $margin + 2 * $pixels)."px";
            $headerHeight = "85px";

            $nameHTML = $name ? "<h1>Hello $name!</h1>" : "";
            $vumcMessage = Application::isVanderbilt() ? " - [<a href='https://edgeforscholars.vumc.org/' target='_NEW'>Edge for Scholars at Vanderbilt</a>]" : " at Vanderbilt University Medical Center";
            $html = "<p class='centered'>";
            $html .= "<div style='width: 100%; text-align: center;' class='smaller'>A Career Development Resource from [<a href='https://edgeforscholars.org' target='_NEW'>Edge for Scholars</a>]$vumcMessage</div>";
            $html .= "<div style='float:left; width: $logoWidth; margin-left: $marginWidth;'><img src='$logoBase64' style='width: $logoWidth; height: $headerHeight;' alt='Flight Tracker for Scholars' /></div>";
            $html .= "<div style='float: left; width: calc(100% - $outsideWidth); height: $headerHeight; margin-top: $marginWidth; text-align: center;'>$nameHTML</div>";
            $html .= "<div style='float:right; text-align: right; margin-right: $marginWidth; width: $logoWidth;'><a href='$efsLink' target='_NEW'><img src='$efsBase64' style='width: 136px; height: $headerHeight;' alt='Edge for Scholars' /></a></div>";
            $html .= "</p>";
            $html .= "<div style='clear: both'></div>";
            return $html;
        }
        return "";
    }

    public static function getMenu() {
        $menu = [];
        $menu[] = [
            "action" => "testAction",
            "title" => "Test Action 1",
        ];
        $menu[] = [
            "action" => "testAction",
            "title" => "Test Action 2",
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