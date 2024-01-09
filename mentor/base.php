<?php

namespace Vanderbilt\CareerDevLibrary;

use \ExternalModules\ExternalModules;

require_once(dirname(__FILE__)."/preliminary.php");
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$hash = "";
$hashRecordId = "";
$isNewHash = FALSE;
if (Application::getProgramName() == "Flight Tracker Mentee-Mentor Agreements") {
    $currPage = Sanitizer::sanitize($_GET['page']);
    if (isset($_GET['hash']) && MMAHelper::isValidHash($_GET['hash'])) {
        $proposedHash = Sanitizer::sanitize($_GET['hash']);
    } else if (isset($_REQUEST['userid']) && MMAHelper::isValidHash($_REQUEST['userid'])) {
        $proposedHash = Sanitizer::sanitize($_REQUEST['userid']);
    } else if ($_GET['hash'] == NEW_HASH_DESIGNATION) {
        $proposedHash = NEW_HASH_DESIGNATION;
    } else {
        $proposedHash = "";
    }
    $isNewHash = ($proposedHash == NEW_HASH_DESIGNATION);
    if (
        !in_array($currPage, ["mentor/intro", "mentor/index", "mentor/createHash"])
        || (
            in_array($currPage, ["mentor/index", "mentor/createHash"])
            && !$isNewHash
        )
    ) {
        $records = Download::recordIds($token, $server);
        $proposedRecordId = isset($_GET['menteeRecord']) ? Sanitizer::getSanitizedRecord($_GET['menteeRecord'], $records) : "";
        $res = MMAHelper::validateHash($proposedHash, $token, $server, $proposedRecordId);
        $hashRecordId = $res['record'];
        $hash = $res['hash'];
        if (
            !$hashRecordId
            || !$hash
            || ($hash != $proposedHash)
            || (
                $proposedRecordId
                && ($proposedRecordId != $hashRecordId)
            )
        ) {
            die("Access Denied. Are you receiving the message in error? Please contact <a href='mailto:scott.j.pearson@vumc.org'>the Flight Tracker help team</a> and explain your situation.");
        }
    }
} else {
    $validREDCapUsers = MMAHelper::getREDCapUsers($pid);
    if (Application::isExternalModule()) {
        $module = Application::getModule();
        $username = $module->getUsername();
        if (MMAHelper::getMMADebug() && isset($_GET['uid'])) {
            $username = Sanitizer::sanitize($_GET['uid']);
            $isSuperuser = FALSE;
        } else {
            $isSuperuser = ExternalModules::isSuperUser();
        }
        if (!$module) {
            die("No module.");
        }

        if (
            !$module->hasMentorAgreementRights($pid, $username)
            && !$isSuperuser
            && !in_array($username, $validREDCapUsers)
        ) {
            die("Access Denied.");
        }
    } else {
        $username = Application::getUsername();
        if (MMAHelper::getMMADebug() && isset($_GET['uid'])) {
            $username = Sanitizer::sanitize($_GET['uid']);
            $isSuperuser = FALSE;
        } else {
            $isSuperuser = defined('SUPER_USER') && (\SUPER_USER == '1');
        }

        if (
            !MMAHelper::hasMentorAgreementRightsForPlugin($pid, $username)
            && !$isSuperuser
            && !in_array($username, $validREDCapUsers)
        ) {
            die("Access Denied.");
        }
    }
}
