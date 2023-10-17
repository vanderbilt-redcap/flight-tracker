<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \ExternalModules\ExternalModules;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

$table1Pid = Application::getTable1PID();
if ($table1Pid) {
    require_once(dirname(__FILE__)."/../charts/baseWeb.php");
    die("Project already set up!");
}
$supertoken = Application::getSetting("supertoken", $pid);
$table1Token = Application::getTable1Token();
$projectSetup = [
    "is_longitudinal" => 0,
    "surveys_enabled" => 1,
    "purpose" => 4,
    "record_autonumbering_enabled" => 0,
    "project_title" => Application::getTable1Title(),
];
if (REDCapManagement::isValidToken($table1Token)) {
    try {
        Upload::projectSettings($projectSetup, $table1Token, $server);
    } catch (\Exception $e) {
        require_once(dirname(__FILE__)."/../charts/baseWeb.php");
        $link = Application::link("reporting/table1.php");
        echo "<p class='centered max-width red'>Error: ".$e->getMessage()."</p>";
        echo "<p class='centered max-width'>You may need to reset your API token on a new project. <a href='$link'>You can do so here</a>.</p>";
        exit;
    }
} else if (REDCapManagement::isValidSupertoken($supertoken)) {
    $table1Token = Upload::createProject($supertoken, $server, $projectSetup, $pid);
    Application::saveSetting("table1Token", $table1Token, $pid);
} else {
    require_once(dirname(__FILE__)."/../charts/baseWeb.php");
    die("<p class='centered max-width red'>No supertoken provided!</p>");
}
$projectInfo = Download::getProjectSettings($table1Token, $server);
$table1Pid = $projectInfo['project_id'] ?? "";
$table1EventId = REDCapManagement::getEventIdForClassical($table1Pid);
if ($table1Pid) {
    Application::saveSystemSetting("table1Pid", $table1Pid);
} else {
    require_once(dirname(__FILE__)."/../charts/baseWeb.php");
    die("<p class='centered max-width red'>Could not generate a new project!</p>");
}

if (!Application::isPluginProject($pid)) {
    $module = Application::getModule();
    $prefix = Application::getPrefix();
    $version = ExternalModules::getModuleVersionByPrefix($prefix);
    ExternalModules::enableForProject($prefix, $version, $table1Pid);

    $formsAndLabels = [
        "table_1_rows" => "[program] ([population]) on [last_update]",
    ];
    DataDictionaryManagement::setupRepeatingForms($table1EventId, $formsAndLabels);
}

$file = __DIR__."/metadata.table1.json";
if (file_exists($file)) {
    $fp = fopen($file, "r");
    $json = "";
    while ($line = fgets($fp)) {
        $json .= $line;
    }
    fclose($fp);

    $table1Metadata = json_decode($json, TRUE);
    if ($table1Metadata) {
        Upload::metadata($table1Metadata, $table1Token, $server);
        $surveyInfo = [
                "table_1_rows" => "NIH Training Table 1 Rows",
        ];
        DataDictionaryManagement::setupSurveys($table1Pid, $surveyInfo);
        $surveyLink = REDCapManagement::getPublicSurveyLink($pid, array_keys($surveyInfo)[0]);
        if ($surveyLink) {
            DataDictionaryManagement::setupSurveys($table1Pid, $surveyInfo, "<p><strong>Thank you for taking the survey.</strong> Want to take another? <a href='$surveyLink'>Click here</a>.</p><p>Have a nice day!</p>");
        }
    } else {
        require_once(dirname(__FILE__)."/../charts/baseWeb.php");
        die("<p class='centered max-width red'>Could not decode metadata!</p>");
    }
} else {
    require_once(dirname(__FILE__)."/../charts/baseWeb.php");
    die("<p class='centered max-width red'>Could not find metadata file!</p>");
}

$link = Application::link("reporting/table1.php");
header("Location: $link");