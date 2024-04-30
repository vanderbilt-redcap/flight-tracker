<?php

use \Vanderbilt\CareerDevLibrary\NIHTables;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

Application::increaseProcessingMax(1);

$tableNum = isset($_GET['table']) ? Sanitizer::sanitize($_GET['table']) : "";
if (!$tableNum || !NIHTables::getTableHeader($tableNum)) {
	die("Could not find $tableNum!");
}
$cohort = isset($_GET['cohort']) ? Sanitizer::sanitizeCohort($_GET['cohort']) : "";
$includeDOI = FALSE;
$htmlForIncludeDOI = "";
if (NIHTables::beginsWith($tableNum, ["5"])) {
    $includeDOI = isset($_GET['includeDOI']) ? Sanitizer::sanitize($_GET['includeDOI']) : FALSE;
    $thisUrl = Application::link("this");
    if ($includeDOI) {
        $url = preg_replace("/&includeDOI=?\d*/", "", $thisUrl);
        $htmlForIncludeDOI = "<a href='$url'>Turn Off DOIs in Publications</a>";
    } else {
        $htmlForIncludeDOI = "<a href='$thisUrl&includeDOI=1'>Turn On DOIs in Publications</a>";
    }
}

$metadata = Download::metadata($token, $server);
$table = new NIHTables($token, $server, $pid, $metadata);
$cohortStr = isset($_GET['cohort']) ? "&cohort=".urlencode(Sanitizer::sanitizeCohort($_GET['cohort'])) : "";

echo "<h1>Table ".NIHTables::formatTableNum($tableNum)."</h1>\n";
echo "<p class='centered'>$htmlForIncludeDOI&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href='".Application::link("reporting/index.php").$cohortStr."'>Back to All Tables</a></p>";
echo "<p class='centered max-width'>To download this table, copy the entire table and paste into your word processor in a blank document.</p>";
$note = "";
if ($tableNum != "Common Metrics") {
    $note = " Its information must be re-keyed and uploaded through xTRACT.";
}
echo "<p class='centered max-width'>A tool to expedite reporting to the NIH, this table should be considered <b>preliminary</b> and requiring manual verification.$note Try copying and pasting the table into MS Word for further customization.</p>";
echo "<p class='centered max-width'>Your grant may be based on the default lengths of Internal K and K12/KL2 grants, configurable in the General menu --> Configure Application.</p>";

$customGrantTypes = NIHTables::getTrainingTypesForGrantClass();
$choices = DataDictionaryManagement::getChoices($metadata);
$acceptableGrantTypeNames = [];
foreach ($customGrantTypes as $type) {
    $acceptableGrantTypeNames[] = $choices["custom_type"][$type];
}
echo "<p class='centered max-width'>Because your Grant Class is $grantClass, only custom grants with these types will be shown: ".implode(", ", $acceptableGrantTypeNames).". You can change the Grant Class in the General menu --> Configure Application.</p>";

echo "<h2>".NIHTables::getTableHeader($tableNum)."</h2>\n";
if ($cohort) {
    echo "<h3>Cohort $cohort</h3>\n";
}
echo $table->getHTML($tableNum, FALSE, $includeDOI);
