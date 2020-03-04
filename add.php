<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\NameMatcher;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/CareerDev.php");
require_once(dirname(__FILE__)."/classes/Download.php");
require_once(dirname(__FILE__)."/classes/Upload.php");
require_once(dirname(__FILE__)."/classes/NameMatcher.php");

if (isset($_POST['newnames']) || isset($_FILES['csv'])) {
	# get starting record_id
	$maxRecordId = 0;
	$recordIds = Download::recordIds($token, $server);
	foreach ($recordIds as $recordId) {
		if ($recordId > $maxRecordId) {
			$maxRecordId = $recordId;
		}
	}
	$recordId = $maxRecordId + 1;

	$lines = array();
	if (isset($_FILES['csv'])) {
		if (is_uploaded_file($_FILES['csv']['tmp_name'])) {
			$fp = fopen($_FILES['csv']['tmp_name'], "rb");
			if (!$fp) {
				echo "Cannot find file.";
			}
			$i = 0;
			while ($line = fgetcsv($fp)) {
				if ($i > 0) {
					$lines[] = $line;
				}
				$i++;
			}
			fclose($fp);
		}
	} else {
		$rows = explode("\n", $_POST['newnames']);
		$upload = array();
		$emails = array();
		$names = array();
		foreach ($rows as $row) {
			if ($row) {
				$nodes = preg_split("/\s*[,\t]\s*/", $row);
				if (count($nodes) == 6) {
					$lines[] = $nodes;
				} else {
					$mssg = "The line [$row] does not contain the necessary 6 columns. No data has been added. Please try again.";
					header("Location: ".CareerDev::link("add.php")."&mssg=".urlencode($mssg));
				}
			}
		}
	}
	list($upload, $emails) = processLines($lines, $recordId, $token, $server);
	$feedback = array();
	if (!empty($upload)) {
		$feedback = Upload::rows($upload, $token, $server);
	} else {
		$mssg = "No data specified.";
		header("Location: ".CareerDev::link("add.php")."&mssg=".urlencode($mssg));
	}
	if (isset($feedback['error'])) {
		$mssg = "People <b>not added</b>". $feedback['error'];
		header("Location: ".CareerDev::link("add.php")."&mssg=".urlencode($mssg));
	}
	if (isset($_GET['mssg'])) {
		echo "<p class='red centered'>{$_GET['mssg']}</p>";
	}

	$timespan = 3;
	echo "<h1>Adding New Scholars or Modifying Existing Scholars</h1>";
	echo "<div style='margin: 0 auto; max-width: 800px'>";
	echo "<p class='centered'>".count($upload).(count($upload) == 1 ? " person" : " people")." added/modified.</p>";
	echo "<p class='centered'>Going to Flight Tracker Central in ".$timespan." seconds...</p>";
	echo "<script>\n";
	echo "$(document).ready(function() {\n";
	echo "\tsetTimeout(function() {\n";
	echo "\t\twindow.location.href='".CareerDev::link("index.php")."';\n";
	echo "\t}, ".floor($timespan * 1000).");\n";
	echo "});\n";
	echo "</script>\n";
	echo "</div>";
} else {                //////////////////// default setup
	if (isset($_GET['mssg'])) {
		echo "<p class='red centered'><b>{$_GET['mssg']}</b></p>";
	}
	echo "<p class='centered'>".CareerDev::makeLogo()."</p>\n";
?>
	<style>
	button { font-size: 20px; color: white; background-color: black; }
	</style>

	<h1>Adding New Scholars or Modifying Existing Scholars for <?= PROGRAM_NAME ?></h1>
	<div style='margin: 0 auto; max-width: 800px;'>
		<p class='centered' id='prompt'><a href='javascript:;' onclick="$('#explanations').show(); $('#prompt').hide();">Click to show detailed instructions</a></p>
		<div id='explanations' style='display: none;'>
			<p>The <b>FirstName</b> is the given name for a person.</p>
			<p>The <b>PreferredName</b> is a name <i>different from the FirstName</i> by which the person should be called. This is a nickname that might or might not be used in the publication or grant literature.</p>
			<p>The <b>Middle</b> is either an initial or a full name.</p>
			<p>The <b>LastName</b> should contain any prior last names [e.g., maiden names] that publications or grants might be listed under. To achieve this, please supply a hyphenated name [e.g., Martin-Smith] or the prior last name in parentheses [e.g., Smith (Martin)].</p>
			<p>The <b>Email</b> addresses you enter will be sent a REDCap survey to fill out with demographic information.</p>
			<p>The <b>Institution</b> should be a short name, but not initials. For instance, Vanderbilt University Medical Center is Vanderbilt, but not VUMC. This is the institution's name that PubMed and the Federal and NIH RePORTERs will match the name on.</p>
			<p><b>--OR--</b> you can supply a Microsoft Excel CSV below.</p>
		</div>
		<form method='POST' action='<?= CareerDev::link("add.php") ?>'><p>
			<b>Please enter</b>:<br>
			<i>FirstName, PreferredName, Middle, LastName, Email, Institution:</i><br>
			<textarea style='width: 600px; height: 300px;' name='newnames'></textarea><br>
			<button>Process Names</button>
		</p></form>
		<p><b>--OR--</b> supply a CSV Spreadsheet with the specified fields in <a href='<?= CareerDev::link("newFaculty.php") ?>'>this example</a>.</p>
		<form enctype='multipart/form-data' method='POST' action='<?= CareerDev::link("add.php") ?>'><p>
			<input type="hidden" name="MAX_FILE_SIZE" value="3000000" />
			CSV Upload: <input type='file' name='csv'><br>
			<button>Process File</button>
		</p></form>
	</div>
<?php
}

function processLines($lines, $nextRecordId, $token, $server) {
	$upload = array();
	$lineNum = 1;
	foreach ($lines as $nodes) {
		if (count($nodes) >= 6) {
			$firstName = $nodes[0];
			$middle = $nodes[2];
			$lastName = $nodes[3];
			$preferred = $nodes[1];
			$recordId = NameMatcher::matchName($firstName, $lastName, $token, $server); 
			if (!$recordId) {
				#new
				$recordId = $nextRecordId;
				$nextRecordId++;
			}
			if ($preferred && ($preferred != $firstName)) {
				$firstName .= " (".$preferred.")";
			}
			$email = trim($nodes[4]);
			if ($email) {
				$emails[$recordId] = $email;
			}
			$names[$recordId] = $preferred." ".$lastName;
			$institution = trim($nodes[5]);
			$uploadRow = array("record_id" => $recordId, "identifier_institution" => $institution, "identifier_middle" => $middle, "identifier_first_name" => $firstName, "identifier_last_name" => $lastName, "identifier_email" => $email);
			if (count($nodes) >= 13) {
				if (preg_match("/female/i", $nodes[6])) {
					$gender = 1;
				} else if (preg_match("/^male/i", $nodes[6])) {
					$gender = 2;
				} else if ($nodes[6] == "") {
					$gender = "";
				} else {
					echo "<p>The gender column contains an invalid value ({$nodes[6]}). Import not successful.</p>";
					throw new \Exception("The gender column contains an invalid value ({$nodes[6]}). Import not successful.");
				}
				if ($nodes[7]) {
					$dobNodes = preg_split("/[\-\/]/", $nodes[7]);
					# assume MDY
					if (count($dobNodes) == 3) {
						if ($dobNodes[2] < 100) {
							if ($dobNodes[2] > 20) {
								$dobNodes[2] += 1900;
							} else {
								$dobNodes[2] += 2000;
							}
						}
						$dob = $dobNodes[2]."-".$dobNodes[0]."-".$dobNodes[1];
					} else {
						echo "<p>The date-of-birth column contains an invalid value ({$nodes[7]}). Import not successful.</p>";
						throw new \Exception("The date-of-birth column contains an invalid value ({$nodes[7]}). Import not successful.");
					}
				} else {
					$dob = "";
				}
				if (preg_match("/American Indian or Alaska Native/i", $nodes[8])) {
					$race = 1;
				} else if (preg_match("/Asian/i", $nodes[8])) {
					$race = 2;
				} else if (preg_match("/Native Hawaiian or Other Pacific Islander/i", $nodes[8])) {
					$race = 3;
				} else if (preg_match("/Black or African American/i", $nodes[8])) {
					$race = 4;
				} else if (preg_match("/White/i", $nodes[8])) {
					$race = 5;
				} else if (preg_match("/More Than One Race/i", $nodes[8])) {
					$race = 6;
				} else if (preg_match("/Other/i", $nodes[8])) {
					$race = 7;
				} else if ($nodes[8] == "") {
					$race = "";
				} else {
					echo "<p>The race column contains an invalid value ({$nodes[8]}). Import not successful.</p>";
					throw new \Exception("The race column contains an invalid value ({$nodes[8]}). Import not successful.");
				}
				if (preg_match("/Non-Hispanic/i", $nodes[9])) {
					$ethnicity = 2;
				} else if (preg_match("/Hispanic/i", $nodes[9])) {
					$ethnicity = 1;
				} else if ($nodes[9] == "") {
					$ethnicity = "";
				} else {
					echo "<p>The ethnicity column contains an invalid value ({$nodes[9]}). Import not successful.</p>";
					throw new \Exception("The ethnicity column contains an invalid value ({$nodes[9]}). Import not successful.");
				}
				if (preg_match("/Prefer Not To Answer/i", $nodes[10])) {
					$disadvantaged = 3;
				} else if (preg_match("/N/i", $nodes[10])) {
					$disadvantaged = 2;
				} else if (preg_match("/Y/i", $nodes[10])) {
					$disadvantaged = 1;
				} else if ($nodes[10] == "") {
					$disadvantaged = "";
				} else {
					echo "<p>The disadvantaged column contains an invalid value ({$nodes[10]}). Import not successful.</p>";
					throw new \Exception("The disadvantaged column contains an invalid value ({$nodes[10]}). Import not successful.");
				}
				if (preg_match("/N/i", $nodes[11])) {
					$disabled = 2;
				} else if (preg_match("/Y/i", $nodes[11])) {
					$disabled = 1;
				} else {
					$disabled = "";
				}
				if (preg_match("/US born/i", $nodes[12])) {
					$citizenship = 1;
				} else if (preg_match("/Acquired US/i", $nodes[12])) {
					$citizenship = 2;
				} else if (preg_match("/Permanent US Residency/i", $nodes[12])) {
					$citizenship = 3;
				} else if (preg_match("/Temporary Visa/i", $nodes[12])) {
					$citizenship = 4;
				} else if ($nodes[12] == "") {
					$citizenship = "";
				} else {
					echo "<p>The citizenship column contains an invalid value ({$nodes[12]}). Import not successful.</p>";
					throw new \Exception("The citizenship column contains an invalid value ({$nodes[12]}). Import not successful.");
				}
				if ($nodes[13]) {
					$mentor = $nodes[13];
				} else {
					$mentor = "";
				}
				$uploadRow["imported_dob"] = $dob;
				$uploadRow["imported_gender"] = $gender;
				$uploadRow["imported_race"] = $race;
				$uploadRow["imported_ethnicity"] = $ethnicity;
				$uploadRow["imported_disadvantaged"] = $disadvantaged;
				$uploadRow["imported_disabled"] = $disabled;
				$uploadRow["imported_citizenship"] = $citizenship;
				$uploadRow["imported_mentor"] = $mentor;
			}
			$upload[] = $uploadRow;
		}
		$lineNum++;
	}
	return array($upload, $emails);
}
