<?php

use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Cohorts;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Links;
use Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$recordsIncluded = [];
foreach ($_POST as $key => $value) {
	$key = Sanitizer::sanitize($key);
	if ($value && preg_match("/^record_/", $key)) {
		$recordId = preg_replace("/^record_/", "", $key);
		$recordsIncluded[] = $recordId;
	}
}
$name = "";
$mssg = "";
if ($_POST['cohort']) {
	$name = Sanitizer::sanitize($_POST['cohort']);    // do not sanitize a cohort because it is not an existing cohort
	$mssg = "<p class='green centered'>New Cohort $name Added</p>";
}
$cohorts = new Cohorts($token, $server, Application::getModule());
if (!empty($recordsIncluded) && $name) {
	$config = ["records" => $recordsIncluded];
	$cohorts->addCohort($name, $config);
}
if (isset($_GET['cohort'])) {
	$name = Sanitizer::sanitizeCohort($_GET['cohort'], $pid);
	if ($name) {
		$recordsIncluded = $cohorts->getCohort($name)->getManualRecords();
	}
}

$link = Application::link("cohorts/pickCohort.php");
echo "<p class='centered'>Modify an Existing Hand-Picked Cohort:<br/>".$cohorts->makeHandPickCohortSelect($name, "location.href = \"$link&cohort=\"+encodeURIComponent($(this).val());")."</p>";
echo $mssg;
echo "<h1>Hand-Pick a Cohort</h1>\n";
echo "<form action='$link' method='POST'>\n";
echo Application::generateCSRFTokenHTML();
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
