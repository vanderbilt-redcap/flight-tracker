<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Download;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/classes/Upload.php");
require_once(dirname(__FILE__)."/classes/Download.php");
require_once(dirname(__FILE__)."/CareerDev.php");

$importFile = "import.csv";
if ($_FILES['bulk']) {
	$longImportFile = dirname(__FILE__)."/".$importFile;
	if (verifyFile($_FILES['bulk'], $longImportFile)) {
		$errors = array();
		$upload = array();
		$lines = readCSV($_FILES['bulk']);

		$lastNames = Download::lastnames($token, $server);
		$firstNames = Download::firstnames($token, $server);
		$matchedIndices = array(0);
		foreach ($lastNames as $recordId => $lastName) {
			$firstName = strtolower($firstNames[$recordId]);
			$lastName = strtolower($lastName);
			$matchedLines = array();
			$i = 0;
			foreach ($lines as $line) {
				if (($firstName == strtolower($line[0])) && ($lastName == strtolower($line[1]))) {
					array_push($matchedLines, $line);
					if (!in_array($i, $matchedIndices)) {
						array_push($matchedIndices, $i);
					}
				}
				$i++;
			} 
			if (count($matchedLines) > 0) {
				$redcapData = Download::fieldsForRecords($token, $server,  array("record_id", "custom_last_update"), array($recordId));
				$max = 0;
				foreach ($redcapData as $row) {
					if (($row['redcap_repeat_instrument'] == "custom_grant") && ($row['redcap_repeat_instance'] > $max)) {
						$max = $row['redcap_repeat_instance'];
					}
				}
				$next = $max + 1;
				foreach ($matchedLines as $line) {
					$uploadRow = array(
								"record_id" => $recordId,
								"redcap_repeat_instrument" => "custom_grant",
								"redcap_repeat_instance" => $next,
								"custom_title" => $line[2],
								"custom_number" => $line[3],
								"custom_type" => translateTypeIntoIndex($line[4]),
								"custom_org" => $line[5],
								"custom_recipient_org" => $line[6],
								"custom_role" => translateRoleIntoIndex($line[7]),
								"custom_start" => $line[8],
								"custom_end" => $line[9],
								"custom_costs" => $line[10],
								"custom_last_update" => date("Y-m-d"),
								"custom_grant_complete" => "2",
								);
					array_push($upload, $uploadRow);
					$next++;
				}
			}
		}
		$i = 0;
		$unmatchedLines = array();
		foreach ($lines as $line) {
			if (!in_array($i, $matchedIndices)) {
				array_push($unmatchedLines, $i);
			}
			$i++;
		}
		if (!empty($unmatchedLines)) {
			echo "<div class='red padded'>\n";
			echo "<h4>Unmatched Lines!</h4>\n";
			echo "<p class='centered'>The following lines could not be matched to a record. Please fix and submit again. No records were imported.</p>\n";
			foreach ($unmatchedLines as $i) {
				$line = $lines[$i];
				echo "<p class='centered'>Line ".($i+1).": <code>".implode(", ", $line)."</code></p>\n";
			}
			echo "</div>\n";
		} else {
			$feedback = Upload::rows($upload, $token, $server);
			if ($feedback['error'])	{
				echo "<p class='red padded'>ERROR! ".$feedback['error']."</p>\n";
			} else {
				echo "<p class='green padded'>Upload successful!</p>\n";
			}
		}
	} else {
		echo "<p class='red padded'>ERROR! The file is not in the right format. ".detectFirstError($_FILES['bulk'], $longImportFile)."</p>\n";
	}
} else if ($_POST['submit']) {
	echo "<p class='red padded'>ERROR! The file could not be found!</p>\n";
}
?>

<h1>Import Grants in Bulk</h1>

<div style='width: 800px; margin: 14px auto;' class='green centered padded'>Please import a CSV (comma delimited) with one row per grant. Use <a href='<?= $importFile ?>'>this template</a> to start with. Each line must match the first name and last name (exactly) as specified in the database.</div>
<form method='POST' action='<?= CareerDev::link("bulkImport.php") ?>' enctype='multipart/form-data'>
<p class='centered'><input type='file' name='bulk'></p>
<p class='centered'><input type='submit' name='submit' value='Upload'></p>
</form>

<?php
function verifyFile($fileinfo, $importFile) {
	$error = detectFirstError($fileinfo, $importFile);
	if (!$error) {
		return TRUE;
	} else {
		return FALSE;
	}
}

function detectFirstError($fileinfo, $importFile) {
	if (!$fileinfo) {
		return "No file supplied!";
	}

	$filename = $fileinfo['tmp_name'];
	if (!file_exists($filename)) {
		return "File does not exist!";
	}

	$fp = fopen($importFile, "r");
	$headers = fgetcsv($fp);
	fclose($fp);

	$fp = fopen($filename, "r");

	$firstLine = fgetcsv($fp);
	if (count($headers) != count($firstLine)) {
		return "The number of headers (".count($firstLine).") do not match what's expected (".count($headers).")!";
	}

	for ($i = 0; $i < count($headers); $i++) {
		if ($headers[$i] != $firstLine[$i]) {
			return "The words in the headers (".$firstLine[$i].") do not match what's expected (".$headers[$i].")!";
		}
	}

	$i = 2;
	$expected = 11;
	while ($line = fgetcsv($fp)) {
		if (count($line) != $expected) {
			return "Line $i has ".count($line)." items! ($expected items expected.)";
		}
		if (!inDateFormat($line[8])) {
			return "The start date {$line[8]} is not in YYYY-MM-DD or MM-DD-YYYY format!";
		}
		if (!inDateFormat($line[9])) {
			return "The end date {$line[9]} is not in YYYY-MM-DD or MM-DD-YYYY format!";
		}
		$i++;
	}
	fclose($fp);

	return "";
}

function ensureDateIsYMD($date) {
	if (preg_match("/^\d\d?[\-\/]\d\d?[\-\/]\d\d\d\d$/", $date)) {
		# assume MDY
		$nodes = preg_split("/[\-\/]/", $date);
		if (count($nodes) == 3) {
			return $nodes[2]."-".$nodes[0]."-".$nodes[1];
		}
	}
	if (preg_match("/^\d\d?[\-\/]\d\d?[\-\/]\d\d$/", $date)) {
		# assume MDY
		$nodes = preg_split("/[\-\/]/", $date);
		if (count($nodes) == 3) {
			$year = $nodes[2];
			if ($year > 80) {
				$year += 1900;
			} else {
				$year += 2000;
			}
			return $year."-".$nodes[0]."-".$nodes[1];
		}
	}
	return $date;
}

function inDateFormat($date) {
	if ($date === "") {
		return TRUE;
	}
	if (preg_match("/^\d\d\d\d[\-\/]\d\d?[\-\/]\d\d?$/", $date)) {
		return TRUE;
	}
	if (preg_match("/^\d\d[\-\/]\d\d?[\-\/]\d\d?$/", $date)) {
		return TRUE;
	}
	if (preg_match("/^\d\d?[\-\/]\d\d?[\-\/]\d\d\d\d$/", $date)) {
		return TRUE;
	}
	if (preg_match("/^\d\d?[\-\/]\d\d?[\-\/]\d\d$/", $date)) {
		return TRUE;
	}
	return FALSE;
}

function readCSV($fileinfo) {
	$filename = $fileinfo['tmp_name'];
	if (file_exists($filename)) {
		$fp = fopen($filename, "r");
		$lines = array();
		while ($line = fgetcsv($fp)) {
			$line[8] = ensureDateIsYMD($line[8]);
			$line[9] = ensureDateIsYMD($line[9]);
			array_push($lines, $line);
		}
		fclose($fp);
		return $lines;
	}
	return array();
}

function translateTypeIntoIndex($type) {
	if (is_numeric($type)) {
		return $type;
	} else {
		$awardTypes = getAwardTypes();
		if (isset($awardTypes[$type])) {
			return $awardTypes[$type];
		}
		return "";
	}
}

function translateRoleIntoIndex($role) {
	$choices = array(
			"PI" => 1,
			"Co-PI" => 2,
			"Co-I" => 3,
			"Other" => 4,
			);
	if (isset($choices[$role])) {
		return $choices[$role];
	}
	return "";
}
