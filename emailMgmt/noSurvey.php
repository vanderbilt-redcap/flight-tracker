<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../wrangler/baseSelect.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Scholar.php");
require_once(dirname(__FILE__)."/../classes/Links.php");
require_once(dirname(__FILE__)."/../Application.php");

$surveyFields = array("initial_survey_complete", "followup_complete", "followup_date", "identifier_email", "identifier_last_name", "identifier_first_name", "check_name_first", "record_id", "summary_ever_last_any_k_to_r01_equiv");
$surveyTypes = \Vanderbilt\FlightTrackerExternalModule\getSurveyTypes();
$surveyCompletes = array(
			"Initial Survey" => array("first" => "initial_survey_complete", "second" => "check_name_first"),
			"Follow-Up Survey(s)" => array("first" => "followup_complete", "second" => "followup_date"),
			"Last Sent" => array(),
			);
$records = Download::recordIds($token, $server);
$surveys = array();
$firstNames = Download::firstnames($token, $server);
$lastNames = Download::lastnames($token, $server);
$emails = array();
$queued = array();
$conversionStatus = array();

$metadata = Download::metadata($token, $server);
$choices = Scholar::getChoices($metadata);
$redcapData = Download::fieldsForRecords($token, $server, $surveyFields, $records);

$label = "INSTANCE";
foreach ($redcapData as $row) {
	$recordId = $row['record_id'];
	$dates = array();
	$firstName = "";
	$lastName = "";
	$email = "";
	$enqueuedDate = "";
	$currConversionStatus = "";
	$instancesWithDates = array();

	foreach ($surveyTypes as $name => $field) {
		if ($row[$field]) {
			if (!isset($dates[$name])) {
				$dates[$name] = array();
			}
			array_push($dates[$name], $row[$field]);

			if (!isset($instancesWithDates[$name])) {
				$instancesWithDates[$name] = array();
			}
			array_push($instancesWithDates[$name], $label.$row['redcap_repeat_instance']);
		}
	}
	if ($row['redcap_repeat_instrument'] == "") {
		$lastName = $row['identifier_last_name'];
		$firstName = $row['identifier_first_name'];
		$email = $row['identifier_email'];
		$enqueuedDate = $row['surveys_next_date'];
		$currConversionStatus = $choices['summary_ever_last_any_k_to_r01_equiv'][$row['summary_ever_last_any_k_to_r01_equiv']];
	}

	foreach ($surveyCompletes as $name => $ary) {
		$field = $ary["first"];
		$confirmationField = $ary["second"];
		if (isset($row[$field]) && ($row[$field] !== "") && $row[$confirmationField]) {
			if (!isset($dates[$name])) {
				$dates[$name] = array();
			}
			if (!in_array($label.$row['redcap_repeat_instance'], $instancesWithDates[$name])) {
				$mark = "Filled Out";
				if ($row['redcap_repeat_instance']) {
					$mark = $row['redcap_repeat_instance']." ".$mark;
			}
				array_push($dates[$name], $mark);
			}
		}
	}

	if ($lastName) {
		$firstNames[$recordId] = $firstName;
		$lastNames[$recordId] = $lastName;
	}
	$emails[$recordId] = $email;
	if ($enqueuedDate) {
		$queued[$recordId] = $enqueuedDate;
	}
	$conversionStatus[$recordId] = $currConversionStatus;

	$surveys[$recordId] = array();
	foreach ($dates as $name => $values) {
		if (!empty($values)) {
			$surveys[$recordId][$name] = $values;
		}
	}
}

asort($lastNames);

?>
<script> var allRecords = <?= json_encode($records) ?>; </script>
<script src='<?= Application::link("js/emailMgmt.js") ?>'></script>

<div id='content'>
<h1>List of Nonrespondents</h1>
<p class='centered green padded' style='display: none;' id='note'></p>

<?php
echo "<table class='centered'>\n";

# Headers
echo "<tr class='odd'>\n";
echo "<th>Name</th>\n";
echo "<th>Email</th>\n";
echo "</tr>\n";

# Data
$i = 0;
foreach ($lastNames as $recordId => $lastName) {
	$allEmpty = TRUE;
	foreach ($surveys[$recordId] as $name => $values) {
		if (!empty($values)) {
			$allEmpty = FALSE;
			break;
		}
	}
	if ($allEmpty) {
		$rowClass = (($i % 2) == 0 ? "even" : "odd");
		$firstName = $firstNames[$recordId];
		$email = "";
		if ($emails[$recordId]) {
			$email = "<a href='mailto:".$emails[$recordId]."'>".$emails[$recordId]."</a>";
		}
		echo "<tr class='$rowClass'>\n";
		echo "<td class='padded centered'>".Links::makeProfileLink($pid, $recordId." ".$firstName." ".$lastName, $recordId)."</td>\n";
		echo "<td class='padded centered'>".$email."</td>\n";
		echo "</tr>\n";
		$i++;
	}
}
echo "</table>\n";
echo "</div>\n";
