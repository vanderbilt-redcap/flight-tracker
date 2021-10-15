<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\NIHTables;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$settingName = NIHTables::getSubsequentGrantsSettingName();

$records = Download::recordIds($token, $server);
$recordId = REDCapManagement::getSanitizedRecord($_GET['record'], $records);
if ($recordId) {
    $settings = Application::getSetting($settingName, $pid);
    if (!$settings) {
        $settings = [];
    }
    if (!isset($settings[$recordId])) {
        $settings[$recordId] = [];
    }

    if (isset($_GET['name'])) {
        $settings[$recordId][] = REDCapManagement::sanitize($_GET['name']);
    } else if (isset($_GET['reset'])) {
        $settings[$recordId] = [];
    } else {
        throw new \Exception("Invalid Setting!");
    }
    CareerDev::saveSetting($settingName, $settings, $pid);
    echo "Saved (".count($settings[$recordId])." hidden)";
} else {
    throw new \Exception("Invalid!");
}