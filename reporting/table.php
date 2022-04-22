<?php

use \Vanderbilt\CareerDevLibrary\NIHTables;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$tableNum = isset($_GET['table']) ? Sanitizer::sanitize($_GET['table']) : "";
if (!$tableNum || !NIHTables::getTableHeader($tableNum)) {
	die("Could not find $tableNum!");
}
$cohort = isset($_GET['cohort']) ? Sanitizer::sanitizeCohort($_GET['cohort']) : "";
$includeDOI = FALSE;
$htmlForIncludeDOI = "";
if (NIHTables::beginsWith($tableNum, ["5"])) {
    $includeDOI = isset($_GET['includeDOI']) ? Sanitizer::sanitize($_GET['includeDOI']) : FALSE;
    $thisUrl = $_SERVER['REQUEST_URI'];
    if ($includeDOI) {
        $url = preg_replace("/&includeDOI=?\d*/", "", $thisUrl);
        $htmlForIncludeDOI = "<a href='$url'>Turn Off DOIs in Publications</a>";
    } else {
        $htmlForIncludeDOI = "<a href='$thisUrl&includeDOI=1'>Turn On DOIs in Publications</a>";
    }
}

$metadata = Download::metadata($token, $server);
$table = new NIHTables($token, $server, $pid, $metadata);
$cohortStr = "";
if ($_GET['cohort']) {
    $cohortStr = "&cohort=".urlencode($_GET['cohort']);
}

echo "<h1>Table ".NIHTables::formatTableNum($tableNum)."</h1>\n";
echo "<p class='centered'>$htmlForIncludeDOI&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href='".Application::link("reporting/index.php").$cohortStr."'>Back to All Tables</a></p>";
$note = "";
if ($tableNum != "Common Metrics") {
    $note = " Its information must be re-keyed and uploaded through xTRACT.";
}
echo "<p class='centered max-width'>A tool to expedite reporting to the NIH, this table should be considered <b>preliminary</b> and requiring manual verification.$note Try copying and pasting the table into MS Word for further customization.</p>";
echo "<h2>".NIHTables::getTableHeader($tableNum)."</h2>\n";
if ($cohort) {
    echo "<h3>Cohort $cohort</h3>\n";
}
echo $table->getHTML($tableNum, FALSE, $includeDOI);
