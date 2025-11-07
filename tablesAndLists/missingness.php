<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\EmailManager;
use \Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

if (isset($_GET['csv'])) {
	require_once(dirname(__FILE__)."/../small_base.php");
} else {
	require_once(dirname(__FILE__)."/../charts/baseWeb.php");
?>

<style>
.red { color: black; font-weight: bold; }
.green { color: green; font-weight: bold; }
.yellow { color: #cbb600; font-weight: bold; }
.purple { color: purple; font-weight: bold; }
.legend { padding: 16px !important; }
th { background-color: #f8e7b1; border: 1px solid #444444; }
td { box-shadow: 0px 0px 6px 1px #444444 inset; padding: 3px 6px; }
td,th { text-align: center; min-width: 95px; }
h1,h2,h3,h4 { margin-top: 6px; margin-bottom: 6px; text-align: center; }
table { border-collapse: collapse; }
th.name { min-width: 300px; }
</style>

<?php
}

$start = 1;
$pull = 1000;
if (isset($_POST['start'])) {
	$start = Sanitizer::sanitizeInteger($_POST['start']);
}
if (isset($_POST['pull'])) {
	$pull = Sanitizer::sanitizeInteger($_POST['pull']);
}
$allGreen = array();
$skip = array("summary_left_vanderbilt", "summary_survey");
$GLOBALS['allGreen'] = $allGreen;
$GLOBALS['skip'] = $skip;

# returns string
function generateDataColumns($recordData, $requestedFields, $potentialFields) {
	global $allGreen, $skip;
	$cols = array();
	$data = array();

	$recordId = $recordData[0]["record_id"];
	$feederSources = [];

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
	$fieldSources = [];
	foreach ($feederSources as $sourceType => $assocs) {
		$fieldSources[$sourceType] = [];
		foreach ($assocs as $field => $form) {
			$fieldSources[$sourceType][$form] = $field;
		}
	}

	foreach ($requestedFields as $field) {
	    if (!isset($fields[$field])) {
	        $fields[$field] = [];
        }
    }
    $allPotentialFields = [];
	foreach ($potentialFields as $title => $possibleFields) {
	    $allPotentialFields = array_unique(array_merge($allPotentialFields, $possibleFields));
    }

	# find the values
	$values = [];
	$colors = [];
	$colorValues = [];
	foreach ($fields as $field => $sources) {
	    if (in_array($field, $allPotentialFields)) {
	        $siblingPotentialFields = [];
	        foreach ($potentialFields as $title => $possibleFields) {
	            if (in_array($field, $possibleFields)) {
	                $siblingPotentialFields = array_unique(array_merge($siblingPotentialFields, $possibleFields));
                }
            }
	        if (count($siblingPotentialFields) > 0) {
	            $field = $siblingPotentialFields[0];
            }
            $colors[$field] = "red";
            $values[$field] = "Absent";
	        foreach ($siblingPotentialFields as $siblingField) {
                $value = REDCapManagement::findField($recordData, $recordId, $siblingField);
                if (isset($value) && ($value !== "")) {
                    $colors[$field] = "green";
                    $values[$field] = "Present";
                    break;  // inner
                }
            }
        } else if (empty($sources)) {
		    $value = REDCapManagement::findField($recordData, $recordId, $field);
			if (isset($value) && ($value !== "")) {
				$colors[$field] = "green";
				$values[$field] = "Present";
			} else {
				$colors[$field] = "red";
				$values[$field] = "Absent";
			}
		} else {
			$found = false;
			$values[$field] = "";
			foreach ($sources as $source) {
				foreach ($fieldSources[$source] as $form => $sourceField) {
					# most recent are first
					$values[$field] = "Present";
					$colorValue = REDCapManagement::findField($recordData, $recordId, $source);
					if ($colorValue == 1 || in_array($field, $allGreen)) {
						$colors[$field] = "green";
					} else if ($colorValue == 2) {
						$colors[$field] = "purple";
					} else if ($colorValue === '0' || $colorValue === 0) {
						$colors[$field] = "yellow";
					} else {
					    $value = REDCapManagement::findField($recordData, $recordId, $field);
					    if ($value) {
							# manual - no computed sourcetype
							$colors[$field] = "purple";
						} else if (Scholar::isDependentOnAcademia($field)) {
					        $jobCategory = REDCapManagement::findField($recordData, $recordId, "identifier_left_job_category");
							if (Scholar::isOutsideAcademia($jobCategory)) {
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
				$col = "<td class='{$colors[$field]}'>";
				$col .= $date; // . " (".$colorValues[$field].")";
				$col .= "</td>";
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
				$col = "<td class='red'>Missing</td>";
				$data[] = "";
				$nones++;
			}
			$cols[] = $col;
		}
	}

	return [
	        "cols" => $data,
			"text" => implode("", $cols),
			"missingCells" => $nones,
			"missingRecords" => (($nones > 0) ? 1 : 0),
			];
}

$metadata = Download::metadata($token, $server);
$metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);

$records = array();
for ($i = $start; $i < $start + $pull; $i++) {
	$records[] = $i;
}

$fields = [
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
        "summary_disadvantaged" => "Disadvantaged Status",
        "summary_disability" => "Disability Status",
		];
$potentialFields = [
    "Gender Identity" => ["check_gender", "followup_gender", ],
    "Transgender?" => ["check_transgender", "followup_transgender"],
    "Sexual Orientation" => ["check_sexual_orientation", "followup_sexual_orientation"],
];
foreach ($potentialFields as $title => $fieldsToCheck) {
    foreach ($fieldsToCheck as $field) {
        if (in_array($field, $metadataFields)) {
            $fields[$field] = $title;
        }
    }
}

$contactFields = ["check_date", "followup_date"];
$adminFields = ["promotion_date", "custom_last_update", "resources_date", "honor_date"];
$contactFields = REDCapManagement::filterOutInvalidFields($metadata, $contactFields);
$adminFields = REDCapManagement::filterOutInvalidFields($metadata, $adminFields);

$addlFields = [];
foreach ($fields as $field => $title) {
    $addlFields[] = $field;
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
$nameFields = ["record_id", "identifier_last_name", "identifier_first_name", "identifier_email", "identifier_left_date", "identifier_left_job_category"];
$shortSummaryFields = array_unique(array_merge($nameFields, $addlFields, $contactFields, $adminFields));
$filteredSummaryFields = REDCapManagement::filterOutInvalidFields($metadata, $shortSummaryFields);

$lastNames = Download::lastnames($token, $server);
asort($lastNames);
$redcapData = Download::indexREDCapData(Download::getFilteredRedcapData($token, $server, $nameFields, $_GET['cohort'], CareerDev::getPluginModule()));

$cohort = "";
if ($_GET['cohort']) {
    $cohort = REDCapManagement::sanitizeCohort($_GET['cohort']);
}
if (isset($_GET['csv'])) {
	$filename = "missingness.csv";
	header("Pragma: public");
	header("Expires: 0");
	header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
	header("Cache-Control: private",false);
	header("Content-Type: application/octet-stream");
	header("Content-Disposition: attachment; filename=\"$filename\";" );
	header("Content-Transfer-Encoding: binary");

	$fp = fopen('php://output', 'w');
	$headers = ["Record ID", "First Name", "Last Name",];
	foreach ($fields as $field => $title) {
		if (!in_array($field, $skip) && !isset($potentialFields[$title])) {
			$headers[] = $title;
		}
	}
	foreach (array_keys($potentialFields) as $title) {
	    $headers[] = $title;
    }
	fputcsv($fp, $headers);
	foreach ($lastNames as $recordId => $lastName) {
	    foreach ($redcapData[$recordId] as $row) {
            $csvRow = [$recordId, $row['identifier_first_name'], $row['identifier_last_name']];
            $recordData = Download::fieldsForRecords($token, $server, $filteredSummaryFields, [$recordId]);
            $ary = generateDataColumns($recordData, array_keys($fields), $potentialFields);
            $csvRow = array_merge($csvRow, $ary['cols']);
            fputcsv($fp, $csvRow);
        }
    }
	fclose($fp);
	exit();
} else {
	echo "<h1>State of Missing Data</h1>";
	echo "<table style='margin: 10px auto;'><tr><td class='green legend'>Green = Self-Reported</td><td class='yellow legend'>Yellow = Computer-Reported</td><td class='purple legend'>Purple = Manual Entry</td><td class='red legend'>Red = Missing</td></tr></table>";

	$cohorts = new Cohorts($token, $server, CareerDev::getPluginModule());
	$url = CareerDev::link("tablesAndLists/missingness.php");
	echo "<p class='centered'>".$cohorts->makeCohortSelect($cohort, "window.location = \"$url&cohort=\" + encodeURIComponent($(this).val());")."\n";
	$csvUrl = CareerDev::link("/tablesAndLists/missingness.php")."&csv";
	$worksheetUrl = CareerDev::link("/tablesAndLists/missingnessWorksheet.php");
    if ($cohort) {
        $cohortNames = $cohorts->getCohortNames();
        $matchedCohort = FALSE;
        foreach ($cohortNames as $cohortName) {
            if ($cohortName == $cohort) {
                $matchedCohort = $cohortName;
            }
        }
        if ($matchedCohort) {
            $csvUrl .= "&cohort=".$matchedCohort;
            $worksheetUrl .= "&cohort=".$matchedCohort;
        }
    }
	echo "<br>".Links::makeLink($csvUrl, "Export to CSV")."\n";
	echo "<br>".Links::makeLink($worksheetUrl, "View All Missingness Worksheets")."\n";
	echo "</p>\n";

	$headers = "";
	$headers .= "<tr>";
    $headers .= "<th style='position: sticky; top: 0;'><button style='font-size: 12px;' onclick='submitEmailAddresses(); return false;'>Set Up Email</button></th>";
	$headers .= "<th style='position: sticky; top: 0;' class='name'>Name</th>";
	foreach ($fields as $field => $title) {
		if (!in_array($field, $skip) && !isset($potentialFields[$title])) {
			$headers .= "<th style='position: sticky; top: 0;'>$title</th>";
		}
	}
	foreach (array_keys($potentialFields) as $title) {
        $headers .= "<th style='position: sticky; top: 0;'>$title</th>";
    }
    $headers .= "<th style='position: sticky; top: 0;'>Last Admin Update</th>";
	$headers .= "<th style='position: sticky; top: 0;'>Last Survey Contact</th>";
	$headers .= "</tr>";
	$html = "";
    $html .= "<div class='top-horizontal-scroll'><div class='inner-top-horizontal-scroll'></div></div>";
    $html .= "<div class='horizontal-scroll'>";
	$html .= "<table style='margin-left: auto; margin-right: auto;' id='maintable'>";
	$html .= "<thead id='normalHeader'>";
    $html .= $headers;
	$html .= "</thead>";

	$html .= "<tbody>";
	# no repeating instruments
	$missingCells = 0;
	$missingRecords = 0;
	$tableHTML = "";
	$sumFields = [];
	foreach ($lastNames as $recordId => $lastName) {
	    foreach ($redcapData[$recordId] as $row) {
            $recordData = Download::fieldsForRecords($token, $server, $filteredSummaryFields, array($recordId));
            $ary = generateDataColumns($recordData, array_keys($fields), $potentialFields);
            foreach ($ary['cols'] as $key => $item) {
                if (!isset($sumFields[$key])) {
                    $sumFields[$key] = 0;
                }
                if ($item) {
                    $sumFields[$key]++;
                }
            }
            $emailName = EmailManager::makeEmailIntoID($row['identifier_email']);
            $tableHTML .= "<tr>";
            $tableHTML .= "<td onclick='const isChecked = $(this).find(\"input.who_to\").attr(\"checked\"); if (isChecked) { $(this).find(\"input.who_to\").attr(\"checked\", false); } else { $(this).find(\"input.who_to\").attr(\"checked\", true); }'><input class='who_to' name='$emailName' type='checkbox'></td>";
            $tableHTML .= "<th>";
            $tableHTML .= Links::makeSummaryLink($pid, $row['record_id'], $event_id, $row['record_id'] . " " . $row['identifier_first_name'] . " " . $row['identifier_last_name']) . "<br>";
            if ($ary['missingCells'] > 0) {
                $tableHTML .= "<span style='font-size: 12px;'>" . Links::makeLink(CareerDev::link("tablesAndLists/missingnessWorksheet.php") . "&record=" . $row['record_id'], "(Missingness Worksheet)") . "</span><br>";
            }
            if ($row['identifier_left_date']) {
                $tableHTML .= "<div class='red centered'>Left: " . $row['identifier_left_date']."</div>";
            }
            $tableHTML .= "</th>";
            $tableHTML .= $ary['text'];

            $latestAdminDate = findLatestDateForFields($recordData, $recordId, $adminFields, $metadata);
            $latestContactDate = findLatestDateForFields($recordData, $recordId, $contactFields, $metadata);
            foreach ([$latestAdminDate, $latestContactDate] as $latestDate) {
                if ($latestDate) {
                    $class = getColorClassForDate($latestDate);
                    $classStr = "";
                    if ($class) {
                        $classStr = " class='$class'";
                    }
                    $tableHTML .= "<td$classStr>$latestDate</td>";
                } else {
                    $tableHTML .= "<td class='red'>N/A</td>";
                }
            }
            $missingCells += $ary['missingCells'];
            $missingRecords += $ary['missingRecords'];
            $tableHTML .= "</tr>";
        }
	}
	$totalHTML = makeTotalHTML($sumFields, count($lastNames));
	$html .= $totalHTML.$tableHTML.$totalHTML;
	$html .= "</tbody></table>";
	$html .= "</div>";

	$cellNoun = "items";
	$recordNoun = "records";
	if ($missingCells == 1) {
	    $cellNoun = "item";
    }
	if ($missingRecords == 1) {
	    $recordNoun = "record";
    }

	echo "<h4>$missingCells missing $cellNoun across $missingRecords $recordNoun</h4>";
	echo "<p class='centered max-width'>To fill missing data, you can enter it yourself in the Manual Import form, electronically send them a survey, or print off a Missingness Worksheet for them to fill out themselves.</p>";
	echo $html;
}
?>
<script>
$(document).ready(function() {
	$(window).scroll(function() { checkSticky(); });
    setupHorizontalScroll($('.horizontal-scroll table').width());
});
</script>
<?php

function getColorClassForDate($date) {
    $ts = strtotime($date);
    if ($ts) {
        $currYear = date("Y", $ts);
        $currDay = date("d", $ts);
        $currMonth = date("m", $ts);
        if (($currMonth == 2) && ($currDay == 29)) {
            $currMonth = 3;
            $currDay = 1;
        }
        $prevYear = $currYear - 1;
        $oneYearPriorTs = strtotime("$prevYear-$currMonth-$currDay");
        if ($ts >= $oneYearPriorTs) {
            # less than one year old
            return "green";
        } else {
            # more than one year old
            return "yellow";
        }
    }
    return "";
}

function findLatestDateForFields($redcapData, $recordId, $fields, $metadata) {
    $dates = [];
    foreach ($fields as $field) {
        $prefix = REDCapManagement::getPrefix($field);
        $instrument = REDCapManagement::getInstrumentFromPrefix($prefix, $metadata);
        $values = REDCapManagement::findField($redcapData, $recordId, $field, $instrument);
        if (is_array($values)) {
            $dates = array_merge($dates, $values);
        } else if ($values && is_string($values)) {
            $dates[] = $values;
        }
    }
    if (count($dates) == 0) {
        return FALSE;
    } else if (count($dates) == 1) {
        return $dates[0];
    } else {
        $latestDate = REDCapManagement::getLatestDate($dates);
        return $latestDate;
    }
}

function makeTotalHTML($sums, $n) {
    if ($n == 0) {
        return "";
    }
    $html = "";
    $html .= "<tr>";
    $html .= "<td></td>";
    $html .= "<th>Total Present ($n)</th>";
    foreach ($sums as $amount) {
        $rawPercent = $amount * 100 / $n;
        $perc = REDCapManagement::pretty($rawPercent, 1);
        if ($rawPercent >= 90) {
            $percentClass = "green";
        } else if ($rawPercent >= 70) {
            $percentClass = "yellow";
        } else {
            $percentClass = "red";
        }
        $html .= "<td class='$percentClass'>$perc% ($amount)</td>";
    }
    $html .= "</tr>";
    return $html;
}