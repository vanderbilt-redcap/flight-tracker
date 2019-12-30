<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Download;

require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../classes/Links.php");
require_once(dirname(__FILE__)."/../classes/Download.php");

if (isset($_GET['csv'])) {
	require_once(dirname(__FILE__)."/../small_base.php");
} else {
	require_once(dirname(__FILE__)."/../charts/baseWeb.php");
?>

<style>
.red { color: red; font-weight: bold; }
.green { color: green; font-weight: bold; }
.yellow { color: #cbb600; font-weight: bold; }
.purple { color: purple; font-weight: bold; }
th { background-color: #c7b783; }
td { background-color: white; }
td,th { text-align: center; border: 1px solid #888888; min-width: 95px; }
h1,h2,h3,h4 { margin-top: 6px; margin-bottom: 6px; text-align: center; }
table { border-collapse: collapse; }
th.name { min-width: 300px; }
</style>

<?php
}

$start = 1;
$pull = 1000;
if (isset($_POST['start'])) {
	$start = $_POST['start'];
}
if (isset($_POST['pull'])) {
	$pull = $_POST['pull'];
}
$allGreen = array();
$skip = array("summary_left_vanderbilt", "summary_survey");
$_GLOBALS['allGreen'] = $allGreen;
$_GLOBALS['skip'] = $skip;

# returns string
function generateDataColumns($recordData) {
	global $pid;
	global $allGreen, $skip;
	$cols = array();
	$data = array();

	$row = $recordData[0];

	# fields and their types of sources
	$fields = array(
			"identifier_email" => array("identifier_email_sourcetype"),
			"summary_survey" => array(),
			"summary_left_vanderbilt" => array("summary_left_vanderbilt_sourcetype"),
			"summary_degrees" => array(),
			"summary_gender" => array("summary_gender_sourcetype"),
			"summary_race_ethnicity" => array("summary_race_sourcetype", "summary_ethnicity_sourcetype"),
			"summary_dob" => array("summary_dob_sourcetype"),
			"summary_citizenship" => array("summary_citizenship_sourcetype"),
			"summary_current_division" => array("summary_current_division_sourcetype"),
			"summary_current_rank" => array("summary_current_rank_sourcetype"),
			"summary_current_start" => array("summary_current_start_sourcetype"),
			"summary_current_tenure" => array("summary_current_tenure_sourcetype"),
			);

	# copied from 6_makeSummary.php
	$feederSources["identifier_email_sourcetype"] =	array(
								"override_email" => "override",
								"check_email" => "scholars",
								"vfrs_email" => "vfrs",
								"newman_data_email" => "data",
								"newman_sheet2_project_email" => "sheet2",
								);
	$feederSources["summary_left_vanderbilt_sourcetype"] =	array(
									"SCHOLARS" => "scholars",
									);
	$feederSources["summary_gender_sourcetype"] =	array(
								"override_gender" => "override",
								"check_gender" => "scholars",
								"vfrs_gender" => "vfrs",
								"newman_new_gender" => "new2017",
								"newman_demographics_gender" => "demographics",
								"newman_data_gender" => "data",
								"newman_nonrespondents_gender" => "nonrespondents",
							);
	$feederSources["summary_race_sourcetype"] = array(
								"override_race" => "override",
								"check_race" => "scholars",
								"vfrs_race" => "vfrs",
								"newman_new_race" => "new2017",
								"newman_demographics_race" => "demographics",
								"newman_data_race" => "data",
								"newman_nonrespondents_race" => "nonrespondents",
								);
	$feederSources["summary_ethnicity_sourcetype"] = array(
								"override_ethnicity" => "override",
								"check_ethnicity" => "scholars",
								"vfrs_ethnicity" => "vfrs",
								"newman_new_ethnicity" => "new2017",
								"newman_demographics_ethnicity" => "demographics",
								"newman_data_ethnicity" => "data",
								"newman_nonrespondents_ethnicity" => "nonrespondents",
								);
	$feederSources["summary_dob_sourcetype"] = array(
								"check_date_of_birth" => "scholars",
								"vfrs_date_of_birth" => "vfrs",
								"newman_new_date_of_birth" => "new2017",
								"newman_demographics_date_of_birth" => "demographics",
								"newman_data_date_of_birth" => "data",
								"newman_nonrespondents_date_of_birth" => "nonrespondents",
								);
	$feederSources["summary_citizenship_sourcetype"] = array( 
								"check_citizenship" => "scholars",
								);
	$feederSources["summary_current_division_sourcetype"] = array(
								"check_division" => "scholars",
								);
	$feederSources["summary_current_rank_sourcetype"] = array(
								"override_rank" => "override",
								"check_academic_rank" => "scholars",
								"newman_new_rank" => "new2017",
								);
	$feederSources["summary_current_start_sourcetype"] = array(
									"check_academic_rank_dt" => "scholars",
									);
	$feederSources["summary_current_tenure_sourcetype"] = array(
								"check_tenure_status" => "scholars",
								);

	# flip the fields
	$fieldSources = array();
	foreach ($feederSources as $sourceType => $assocs) {
		$fieldSources[$sourceType] = array();
		foreach ($assocs as $field => $form) {
			$fieldSources[$sourceType][$form] = $field;
		}
	}

	# find the values
	$values = array();
	$colors = array();
	$tooltips = array();
	$colorValues = array();
	foreach ($fields as $field => $sources) {
		if (empty($sources)) {
			if ($row[$field]) {
				$colors[$field] = "green";
				$tooltips[$field] = "";
				$values[$field] = "Present";
			} else {
				$colors[$field] = "red";
				$tooltips[$field] = "";
				$values[$field] = "Absent";
			}
		} else {
			$found = false;
			$values[$field] = "";
			foreach ($sources as $source) {
				foreach ($fieldSources[$source] as $form => $sourceField) {
					# most recent are first
					$values[$field] = "Present";
					$tooltips[$field] = $sourceField;
					$colorValue = $row[$source];
					if ($colorValue == 1 || in_array($field, $allGreen)) {
						$colors[$field] = "green";
					} else if ($colorValue == 2) {
						$colors[$field] = "purple";
					} else if ($colorValue === '0' || $colorValue === 0) {
						$colors[$field] = "yellow";
					} else {
						$tooltips[$field] = "";
						if ($row[$field]) {
							# manual - no computed sourcetype
							$colors[$field] = "purple";
						} else if (Scholar::isDependentOnAcademia($field)) {
							if (Scholar::isOutsideAcademe($row['identifier_left_job_category'])) {
								$values[$field] = "N/A";
								$colors[$field] = "grey";
							} else {
								$values[$field] = "Absent";
								$colors[$field] = "red";
							}
						} else {
							$values[$field] = "Absent";
							$colors[$field] = "red";
						}
					}
					$colorValues[$field] = $source." ".$colorValue;
					$found = true;
					break;
				}
				if ($found) {
					break;
				}
			}
		}
	}

	# format the output
	$nones = 0;
	foreach ($values as $field => $date) {
		if (!in_array($field, $skip)) {
			if ($date != "Absent") {
				$col = "<td title='{$tooltips[$field]}'><span class='{$colors[$field]}'>";
				$col .= $date; // . " (".$colorValues[$field].")";
				$col .= "</span></td>";
				switch($colors[$field]) {
					case "green":
						$data[] = "Self-Reported";
						break;
					case "purple":
						$data[] = "Manual";
						break;
					case "yellow":
						$data[] = "Computer";
						break;
					case "red":
					default:
						$data[] = "";
						break;
				}
			} else {
				$col = "<td><span class='red'>Missing</span></td>";
				$data[] = "";
				$nones++;
			}
			$cols[] = $col;
		}
	}

	return array(
			"cols" => $data,
			"text" => implode("", $cols),
			"missingCells" => $nones,
			"missingRecords" => (($nones > 0) ? 1 : 0),
			);
}

$metadata = Download::metadata($token, $server);

$records = array();
for ($i = $start; $i < $start + $pull; $i++) {
	$records[] = $i;
}

$fields = array(
		"identifier_email" => "Email",
		"summary_survey" => "Survey?",
		"summary_left_vanderbilt" => "Left VU/VUMC",
		"summary_degrees" => "Degrees",
		"summary_gender" => "Gender",
		"summary_race_ethnicity" => "Race / Ethnicity",
		"summary_dob" => "DOB",
		"summary_citizenship" => "Citizen?",
		"summary_current_division" => "Division",
		"summary_current_rank" => "Rank",
		"summary_current_start" => "Position Start",
		"summary_current_tenure" => "Tenure",
		);
$addlFields = array();
foreach ($fields as $field => $title) {
	array_push($addlFields, $field);
	if ($field == "summary_race_ethnicity") {
		$field = "summary_race";
		$addlFields[] = $field."_sourcetype";
		$addlFields[] = $field."_source";
		$field = "summary_ethnicity";
		$addlFields[] = $field."_sourcetype";
		$addlFields[] = $field."_source";
	} else {
		$addlFields[] = $field."_sourcetype";
		$addlFields[] = $field."_source";
	}
}
$nameFields = array("record_id", "identifier_last_name", "identifier_first_name", "identifier_left_date", "identifier_left_job_category");
$shortSummaryFields = array_unique(array_merge($nameFields, $addlFields));
$filteredSummaryFields = \Vanderbilt\FlightTrackerExternalModule\filterFields($shortSummaryFields, $metadata);

$redcapData = \Vanderbilt\FlightTrackerExternalModule\alphabetizeREDCapData(Download::getFilteredRedcapData($token, $server, $nameFields, $_GET['cohort'], $metadata));

if (isset($_GET['csv'])) {
	$cohort = "";
	if ($_GET['cohort']) {
		$cohort = "_".$_GET['cohort'];
	}
	$filename = "missingness".$cohort.".csv";
	header("Pragma: public");
	header("Expires: 0");
	header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
	header("Cache-Control: private",false);
	header("Content-Type: application/octet-stream");
	header("Content-Disposition: attachment; filename=\"$filename\";" );
	header("Content-Transfer-Encoding: binary");

	$fp = fopen('php://output', 'w');
	$headers = array("Record ID", "First Name", "Last Name",);
	foreach ($fields as $field => $title) {
		if (!in_array($field, $skip)) {
			$headers[] = $title;
		}
	}
	fputcsv($fp, $headers);
	foreach ($redcapData as $row) {
		$recordId = $row['record_id'];
		$csvRow = array($recordId, $row['identifier_first_name'], $row['identifier_last_name']);
		$recordData = Download::fieldsForRecords($token, $server, $filteredSummaryFields, array($recordId));
		$ary = generateDataColumns($recordData);
		$csvRow = array_merge($csvRow, $ary['cols']);
		fputcsv($fp, $csvRow);
	}
	fclose($fp);
	exit();
} else {
	echo "<h1>State of Missing Data</h1>";
	$spacing = "&nbsp;&nbsp;&nbsp;&nbsp;";
	echo "<h4><span class='green'>Green = Self-Reported</span>$spacing<span class='yellow'>Yellow = Computer-Reported</span>$spacing<span class='purple'>Purple = Manual Entry</span>$spacing<span class='red'>Red = Missing</span></h4>";

	echo "<p class='centered'>".\Vanderbilt\FlightTrackerExternalModule\getCohortSelect($token, $server, $pid, $metadata)."\n";
	$csvUrl = CareerDev::link("/tablesAndLists/missingness.php")."&csv";
	$worksheetUrl = CareerDev::link("/tablesAndLists/missingnessWorksheet.php");
	if ($_GET['cohort']) {
		$csvUrl .= "&cohort=".$_GET['cohort'];
		$worksheetUrl .= "&cohort=".$_GET['cohort'];
	}
	echo "<br>".Links::makeLink($csvUrl, "Export to CSV")."\n";
	echo "<br>".Links::makeLink($worksheetUrl, "View All Missingness Worksheets")."\n";
	echo "</p>\n";

	$headers = "";
	$headers .= "<tr><th class='name'>Name</th>";
	foreach ($fields as $field => $title) {
		if (!in_array($field, $skip)) {
			$headers .= "<th>$title</th>";
		}
	}
	$headers .= "</tr>";
	$html = "";
	$html .= "<table style='margin-left: auto; margin-right: auto; display: none;' class='sticky' id='stickyHeader'>";
	$html .= "<thead>";
	$html .= $headers;
	$html .= "</thead>";
	$html .= "</table>";
	$html .= "<table style='margin-left: auto; margin-right: auto;' id='maintable'>";
	$html .= "<thead id='normalHeader'>";
	$html .= $headers;
	$html .= "</thead>";

	$html .= "<tbody>";
	# no repeating instruments
	$missingCells = 0;
	$missingRecords = 0;
	foreach ($redcapData as $row) {
		$recordId = $row['record_id'];
		$recordData = Download::fieldsForRecords($token, $server, $filteredSummaryFields, array($recordId));
		$ary = generateDataColumns($recordData);
		$html .= "<tr>";
		$html .= "<th>";
		$html .= Links::makeSummaryLink($pid, $row['record_id'], $event_id, $row['record_id']." ".$row['identifier_first_name']." ".$row['identifier_last_name'])."<br>";
		if ($ary['missingCells'] > 0) {
			$html .= "<span style='font-size: 12px;'>".Links::makeLink(CareerDev::link("tablesAndLists/missingnessWorksheet.php")."&record=".$row['record_id'], "(Missingness Worksheet)")."</span>";
		}
		if ($row['identifier_left_date']) {
			$html .= "<br>Left: ".$row['identifier_left_date'];
		}
		$html .= "</th>";
		$html .= $ary['text'];
		$missingCells += $ary['missingCells'];
		$missingRecords += $ary['missingRecords'];
		$html .= "</tr>";
	}
	$html .= "</tbody></table>";

	echo "<h4>$missingCells missing items across $missingRecords records</h4>";
	echo $html;
}
?>
<script>
$(document).ready(function() {
	$(window).scroll(function() { checkSticky(); });
});
</script>
