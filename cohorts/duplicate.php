<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$messages = array();
if ($_POST['cohort']) {
    $cohort = REDCapManagement::sanitizeCohort($_POST['cohort']);
} else {
    $cohort = REDCapManagement::sanitizeCohort($_GET['cohort']);
}

$metadata = Download::metadata($token, $server);
$cohorts = new Cohorts($token, $server, $metadata);
$cohortNames = $cohorts->getCohortNames();

if ($_POST['supertoken']) {
    $supertoken = REDCapManagement::sanitize($_POST['supertoken']);
    if (REDCapManagement::isValidSupertoken($supertoken)) {
        CareerDev::saveSetting("supertoken", $supertoken);
    } else {
        throw new \Exception("Invalid supertoken!");
    }
}
if (!$supertoken) {
    $supertoken = CareerDev::getSetting("supertoken");
}

# access supertoken
if ($supertoken && in_array($cohort, $cohortNames)) {
	# create project
	$newProjectToken = "";
	$newProjectEventId = "";
	$newProjectPid = "";

	$projectSetup = array(
				"project_title" => "Flight Tracker - $cohort",
				"purpose" => "4",
				"is_longitudinal" => "1",
				"surveys_enabled" => "1",
				"record_autonumbering_enabled" => "1"
				);
	$data = array(
		'token' => $supertoken,
		'content' => 'project',
		'format' => 'json',
		'data' => json_encode(array($projectSetup))
	);
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $server);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_VERBOSE, 0);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_AUTOREFERER, true);
	curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
	curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
	$newProjectToken = curl_exec($ch);
	curl_close($ch);

	if ($newProjectToken) {
		$data = array(
			'token' => $newProjectToken,
			'content' => 'project',
			'format' => 'json',
			'returnFormat' => 'json'
		);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $server);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
		$output = (string) curl_exec($ch);
		curl_close($ch);
		$projectData = json_decode($output, TRUE);
		$newProjectPid = $projectData['project_id'];

		$sql = "SELECT m.event_id AS event_id FROM redcap_events_metadata AS m INNER JOIN redcap_events_arms AS a ON a.arm_id = m.arm_id WHERE a.project_id = '".db_real_escape_string($newProjectPid)."'"; 
		$q = db_query($sql);
		if ($row = db_fetch_assoc($q)) {
			$newProjectEventId = $row['event_id'];
		}

		$sql = "SELECT form_name, custom_repeat_form_label FROM redcap_events_repeat WHERE event_id = '".db_real_escape_string($event_id)."'";
		$q = db_query($sql);
		$formsAndLabels = array();
		while ($row = db_fetch_assoc($q)) {
			$formsAndLabels[$row['form_name']] = $row['custom_repeat_form_label'];
		}

		$sqlEntries = array();
		foreach ($formsAndLabels as $form => $label) {
			array_push($sqlEntries, "($newProjectEventId, '".db_real_escape_string($form)."', '".db_real_escape_string($label)."')");
		}
		if (!empty($sqlEntries)) {
			$sql = "INSERT INTO redcap_events_repeat (event_id, form_name, custom_repeat_form_label) VALUES".implode(",", $sqlEntries);
			db_query($sql);
			if ($error = db_error()) {
				throw new \Exception("SQL Error: $error<br>$sql");
			}
		}

		$projectData['custom_record_label'] = "[identifier_first_name] [identifier_last_name]";

		$data = array(
			'token' => $newProjectToken,
			'content' => 'project_settings',
			'format' => 'json',
			'data' => json_encode($projectData) 
		);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $server);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
		$output = curl_exec($ch);
		curl_close($ch);
		array_push($messages, "Project Info: $output");

		array_push($messages, "Please enable hooks, individual surveys, and users on the new project!");
		array_push($messages, "\$info['$cohort'] = array('token' => '$newProjectToken', 'server' => '$server', 'pid' => $newProjectPid, 'event_id' => $newProjectEventId, 'name' => '$cohort', 'env' => '$cohort', 'readonly' => '1');");

		$feedback = Upload::metadata($metadata, $newProjectToken, $server);
		array_push($messages, "Metadata: ".json_encode($feedback));

        CareerDev::duplicateAllSettings($pid, $newProjectPid, ["turn_off" => TRUE, "tokenName" => $cohort]);
		# one record at a time, download cohort records; upload to new project
		$feedbackAry = \Vanderbilt\FlightTrackerExternalModule\copyEntireProject($token, $newProjectToken, $server, $metadata, $cohort);
		foreach ($feedbackAry as $feedback) {
			array_push($messages, $feedback);
		}
        echo "<h1>Project Creation Complete</h1>\n";
        echo "<h2>In Project $newProjectPid</h2>\n";
        foreach ($messages as $mssg) {
            if (is_array($mssg)) {
                echo "<p class='green centered'>".json_encode($mssg)."</p>\n";
            } else {
                echo "<p class='green centered'>$mssg</p>\n";
            }
        }
	} else {
		throw new \Exception("No project token!");
	}
} else if (!$supertoken) {
    echo "<h1>Input a Supertoken</h1>";
    $link = CareerDev::link("cohorts/duplicate.php");
    echo "<form action='$link' method='POST'>";
    echo "<p class='centered'><input type='text' name='supertoken' value='' style='width: 200px;'></p>";
    echo "<p class='centered'><button>Go!</button></p>";
    echo "</form>";
} else {
    echo "<h1>Select a Cohort</h1>";
    $link = CareerDev::link("cohorts/duplicate.php");
    echo "<form action='$link' method='POST'>";
    echo "<p class='centered'>".$cohorts->makeCohortSelect("")."</p>";
    echo "<p class='centered'><button>Go!</button></p>";
    echo "</form>";
}
