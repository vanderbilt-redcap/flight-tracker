<?php

use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;
use \ExternalModules\ExternalModules;
use \Vanderbilt\CareerDevLibrary\MMAHelper;

require_once(dirname(__FILE__)."/preliminary.php");
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once dirname(__FILE__)."/../CareerDev.php";

$validREDCapUsers = MMAHelper::getREDCapUsers($pid);
if (Application::isExternalModule()) {
    # 11/11/2021 - I don't think this is needed any longer, so I'm turning it off; probably can delete
    // if (($pid == 127616) && Application::isVanderbilt()) {
        // $thisUrl = Application::link("this");
        // $thisUrl = str_replace("project_id=127616", "project_id=117692", $thisUrl);
        // $thisUrl = str_replace("pid=127616", "pid=117692", $thisUrl);
        // header("Location: $thisUrl");
    // }
    $module = Application::getModule();
    $username = $module->getUsername();
    if (DEBUG && isset($_GET['uid'])) {
        $username = REDCapManagement::sanitize($_GET['uid']);
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
        if (($pid == 101785) && !isset($_GET['test'])) {
            # due to an error by Arnita in sending out the original link
            $thisUrl = Application::link("this");
            $thisUrl = preg_replace("/project_id=101785/", "project_id=117692", $thisUrl);
            header("Location: $thisUrl");
        } else {
            die("Access Denied.");
        }
    }
} else {
    $username = Application::getUsername();
    if (DEBUG && isset($_GET['uid'])) {
        $username = REDCapManagement::sanitize($_GET['uid']);
        $isSuperuser = FALSE;
    } else {
        $isSuperuser = defined('SUPER_USER') && (SUPER_USER == '1');
    }

    if (
        !MMAHelper::hasMentorAgreementRightsForPlugin($pid, $username)
        && !$isSuperuser
        && !in_array($username, $validREDCapUsers)
    ) {
        die("Access Denied.");
    }
}