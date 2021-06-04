<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$recordsIncluded = [];
foreach ($_POST as $key => $value) {
    if ($value && preg_match("/^record_/", $key)) {
        $recordId = preg_replace("/^record_/", "", $key);
        $recordsIncluded[] = $recordId;
    }
}
$name = "";
if ($_POST['cohort']) {
    $name = $_POST['cohort'];
}
if (!empty($recordsIncluded) && $name) {
    $config = ["records" => $recordsIncluded];
    $metadata = Download::metadata($token, $server);
    $cohorts = new Cohorts($token, $server, $metadata);
    $cohorts->addCohort($name, $config);
}

echo "<h1>Hand-Pick a Cohort</h1>\n";
echo "<form action='".Application::link("cohorts/pickCohort.php")."' method='POST'>\n";
echo "<p class='centered'>Cohort Name: <input type='text' id='cohort' name='cohort' value='$name'></p>";
echo "<p class='centered'><button>Add Cohort</button></p>";
$checkboxes = [];
$names = Download::names($token, $server);
foreach ($names as $recordId => $name) {
    $id = "record_$recordId";
    $checked = "";
    if (in_array($recordId, $recordsIncluded)) {
        $checked = "checked";
    }
    $link = Links::makeRecordHomeLink($pid, $recordId, $name);
    $checkboxes[] = "<input type='checkbox' id='$id' name='$id' $checked> $recordId: $link";
}
echo "<p style='margin: 0 auto; max-width: 300px;'>".implode("<br>", $checkboxes)."</p>";
echo "</form>\n";