<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Links;
use Vanderbilt\CareerDevLibrary\HonorsAwardsActivities;
use Vanderbilt\CareerDevLibrary\DateManagement;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\Sanitizer;
use Vanderbilt\CareerDevLibrary\Upload;

require_once(__DIR__."/../small_base.php");
require_once(__DIR__."/../classes/Autoload.php");

$records = Download::recordIdsByPid($pid);
$thisUrl = Application::link("this");

if (isset($_POST['action'])) {
	$data = [];
	$recordId = Sanitizer::getSanitizedRecord($_POST['record'] ?? "", $records);
	$action = Sanitizer::sanitize($_POST['action']);
	$canUpload = Sanitizer::sanitize($_POST['upload'] ?? "");
	$prefix = "activityhonor";
	$instrument = "honors_awards_and_activities";
	$endFields = [
		$prefix."_org" => "Organization",
		$prefix."_award_year" => "Year or Start Year",
		$prefix."_exclusivity" => "Exclusivity or Notoriety, if relevant",
	];
	$presentationFields = [
		$prefix."_coauthors" => "Co-Authors (including scholar)",
		$prefix."_pres_title" => "Presentation/Poster Title",
		$prefix."_activity_location" => "City, State, Country",
		$prefix."_activity" => "Name of Conference/Activity",
	];
	$dateFields = [
		$prefix."_activity_start" => "Start Date",
		$prefix."_activity_end" => "End Date",
	];
	$committeeFields = [
		$prefix."_committee_name_other" => "Committee Name",
	];
	$leadershipFields = [
		$prefix."_leadership_role" => "Title of Leadership Role",
	];

	$startHTML = "<p><input class='honorField' type='text' id='$prefix"."_name' name='$prefix"."_name' placeholder='Name of Honor or Activity' /></p>";
	$endHTML = [makeRadioHTML("Level of Activity", $prefix."_activity_realm", $pid)];
	addActivityTextHTML($endHTML, $endFields);
	$endHTML[] = "<p><label for='$prefix"."_notes'>Notes:</label><br/><textarea id='$prefix"."_notes' name='$prefix"."_notes' class='honorField' style='height: 100px;'></textarea></p>";
	$endHTML[] = "<p class='centered'><button onclick='createActivityHTML(\"$thisUrl\", $(this).parent().parent().parent().parent(), \"$recordId\", \"$action\", $(this).parent().parent().position().top, true); return false;'>Add REDCap</button></p>";

	if ($canUpload && ($recordId !== "")) {
		# TODO Test upload
		$testFields = ["record_id", "activityhonor_name"];
		$redcapData = Download::fieldsForRecordsByPid($pid, $testFields, [$recordId]);
		$maxInstance = REDCapManagement::getMaxInstance($redcapData, $instrument, $recordId);
		$uploadRow = [
			"record_id" => $recordId,
			"redcap_repeat_instrument" => $instrument,
			"redcap_repeat_instance" => $maxInstance + 1,
			"activityhonor_datetime" => date("Y-m-d H:i"),
			"activityhonor_userid" => Application::getUsername(),
			$instrument."_complete" => "2",
		];
		foreach ($_POST as $field => $value) {
			$value = Sanitizer::sanitizeWithoutChangingQuotes($value);
			if (
				preg_match("/^$prefix/", (string) $field)
				&& ($value !== "")
			) {
				$uploadRow[$field] = $value;

				$additionalField = "";
				if ($field == $prefix."_name") {
					$additionalField = $prefix . "_local_name";    // new standardized dropdown field
				} elseif ($field == $prefix."_committee_name") {
					$additionalField = $prefix . "_committee_name";    // overwrite value because dropdown
				}
				if ($additionalField) {
					$fieldChoices = DataDictionaryManagement::getChoicesForField($pid, $additionalField);
					$uploadRow[$additionalField] = HonorsAwardsActivities::OTHER_VALUE;
					foreach ($fieldChoices as $index => $label) {
						if ($label == $value) {
							$uploadRow[$additionalField] = $index;
							break;
						}
					}
				}
				if (
					($field == $prefix."_committee_name")
					&& (($uploadRow[$field] ?? "") == HonorsAwardsActivities::OTHER_VALUE)
				) {
					$uploadRow[$prefix."_committee_name_other"] = $value;
				}
			}
		}
		if (count($uploadRow) > 6) {
			try {
				Upload::rowsByPid([$uploadRow], $pid);
			} catch (\Exception $e) {
				$data['error'] = $e->getMessage();
			}
		} else {
			$data['error'] = "No data to upload!";
		}
	}
	if (isset($data['error'])) {
	} elseif ($recordId === "") {
		$data['error'] = "Invalid record.";
	} elseif ($action == "markAsDone") {
		$instance = Sanitizer::sanitizeInteger($_POST['instance'] ?? "");
		$instrument = Sanitizer::sanitize($_POST['instrument'] ?? "");
		$prefix = REDCapManagement::getPrefixFromInstrument($instrument);
		if ($prefix == "honor") {
			$field = $prefix."_imported";
		} else {
			$field = $prefix."_honor_imported";
		}
		if (
			($instance || ($instrument == "initial_survey"))
			&& in_array($field, HonorsAwardsActivities::OLD_HONOR_FIELDS)
		) {
			$row = [ "record_id" => $recordId ];
			if ($instrument == "initial_survey") {
				$row["redcap_repeat_instance"] = "";
				$row["redcap_repeat_instrument"] = "";
			} else {
				$row["redcap_repeat_instance"] = "$instance";
				$row["redcap_repeat_instrument"] = $instrument;
			}
			$row[$field] = "1";
			try {
				Upload::rowsByPid([$row], $pid);
				$data['result'] = "Done!";
			} catch (\Exception $e) {
				$data['error'] = $e->getMessage();
			}
		} elseif (!$instance) {
			$data['error'] = "No instance.";
		} else {
			$data['error'] = "Improper field.";
		}
	} elseif ($action == "createHonor") {
		$html = [makeRadioHTML("Type of Honor or Activity", $prefix."_type", $pid, "1")];
		$data['html'] = $startHTML.implode("", $html).implode("", $endHTML);
	} elseif ($action == "createAbstract") {
		$html = [makeRadioHTML("Type of Honor or Activity", $prefix."_type", $pid, "3")];
		addActivityTextHTML($html, $presentationFields);
		foreach ($dateFields as $dateField => $label) {
			$html[] = "<p><label for='$dateField'>$label: </label><input type='date' id='$dateField' name='$dateField' /></p>";
		}
		$data['html'] = $startHTML.implode("", $html).implode("", $endHTML);
	} elseif ($action == "createPresentation") {
		$html = [makeRadioHTML("Type of Honor or Activity", $prefix."_type", $pid, "4")];
		foreach ($dateFields as $dateField => $label) {
			$html[] = "<p><label for='$dateField'>$label: </label><input type='date' id='$dateField' name='$dateField' /></p>";
		}
		addActivityTextHTML($html, $presentationFields);
		$data['html'] = $startHTML.implode("", $html).implode("", $endHTML);
	} elseif ($action == "createInternalCommittee") {
		$html = [makeRadioHTML("Type of Honor or Activity", $prefix."_type", $pid, "6")];
		addActivityTextHTML($html, $committeeFields);
		$html[] = makeRadioHTML("Nature of Committee", $prefix."_committee_nature", $pid, "1");
		$html[] = makeRadioHTML("Role in Committee", $prefix."_committee_role", $pid);
		$data['html'] = $startHTML.implode("", $html).implode("", $endHTML);
	} elseif ($action == "createExternalCommittee") {
		$html = [makeRadioHTML("Type of Honor or Activity", $prefix."_type", $pid, "6")];
		addActivityTextHTML($html, $committeeFields);
		$html[] = makeRadioHTML("Nature of Committee", $prefix."_committee_nature", $pid, "2");
		$html[] = makeRadioHTML("Role in Committee", $prefix."_committee_role", $pid);
		$data['html'] = $startHTML.implode("", $html).implode("", $endHTML);
	} elseif ($action == "createLeadershipRole") {
		$html = [makeRadioHTML("Type of Honor or Activity", $prefix."_type", $pid, "7")];
		addActivityTextHTML($html, $leadershipFields);
		$data['html'] = $startHTML.implode("", $html).implode("", $endHTML);
	} else {
		$data['error'] = "Invalid action.";
	}
	echo json_encode($data);
	exit;
}

require_once(__DIR__."/../charts/baseWeb.php");

$startDate = Sanitizer::sanitizeDate($_GET['date'] ?? "");
$startTs = $startDate ? strtotime($startDate) : false;
$surveyPrefixes = ["check" => "", "followup" => "followup"];
$surveySuffixes = [
	"_honors_awards" => ["label" => "Honors &amp; Awards", "action" => "createHonor"],
	"_abstracts" => ["label" => "Abstracts", "action" => "createAbstract"],
	"_presentations" => ["label" => "Presentations", "action" => "createPresentation"],
		"_internal_committees" => ["label" => "Internal Committees", "action" => "createInternalCommittee"],
	"_external_committees" => ["label" => "External Committees", "action" => "createExternalCommittee"],
	"_internal_leadership" => ["label" => "Internal Leadership Roles", "action" => "createLeadershipRole"],
];
$honorFields = [
	"honor_name" => "Honor Name",
	"honor_org" => "Organization",
	"honor_type" => "Honor Type",
	"honor_exclusivity" => "Exclusivity",
	"honor_date" => "Date Given",
	"honor_notes" => "Notes",
];
$metadataFields = Download::metadataFieldsByPid($pid);
$matchedInfo = [];
$names = Download::namesByPid($pid);
if (in_array("honor_imported", $metadataFields)) {
	foreach ($records as $recordId) {
		$redcapData = Download::fieldsForRecordsByPid($pid, HonorsAwardsActivities::OLD_HONOR_FIELDS, [$recordId]);
		foreach ($redcapData as $row) {
			$instance = $row['redcap_repeat_instance'];
			foreach ($surveyPrefixes as $prefix => $instrument) {
				if (
					($row['redcap_repeat_instrument'] == $instrument)
					&& ($row[$prefix."_honor_imported"] != "1")
				) {
					$canContinue = true;
					if ($row[$prefix."_date"] && $startTs) {
						$rowTs = strtotime($row[$prefix."_date"]);
						$canContinue = ($rowTs >= $startTs);
					}
					if ($canContinue) {
						$readableFormName = "UNKNOWN";
						if ($instrument === "") {
							$readableFormName = "Initial Survey";
						} elseif ($instrument == "followup") {
							$readableFormName = "Followup Survey";
						}
						$match = [
							"Date Entered/Updated" => DateManagement::YMD2MDY($row[$prefix."_date"]) ?: "No Date Specified",
							"Form" => $readableFormName,
						];
						foreach ($surveySuffixes as $suffix => $details) {
							$label = $details['label'];
							$action = $details['action'];
							$value = $row[$prefix.$suffix] ?? "";
							if ($value) {
								$match[$label." ACTION:$action"] = preg_replace("/[\n\r]+/", "<br/>", trim($value));
							}
						}
						if (count($match) > 2) {
							if (!isset($matchedInfo["$recordId:$instance"])) {
								$matchedInfo["$recordId:$instance"] = [];
							}
							$matchedInfo["$recordId:$instance"][] = $match;
						}
					}
				}
			}
			if (in_array($row['redcap_repeat_instrument'], ["honors_and_awards", "old_honors_and_awards"])) {
				$canContinue = true;
				if ($row["honor_last_update"] && $startTs) {
					$rowTs = strtotime($row["honor_last_update"]);
					$canContinue = ($rowTs >= $startTs);
				} elseif ($row["honor_created"] && $startTs) {
					$rowTs = strtotime($row["honor_created"]);
					$canContinue = ($rowTs >= $startTs);
				}
				if ($row["honor_imported"] != "1") {
					$canContinue = false;
				}
				if ($canContinue) {
					$date = DateManagement::YMD2MDY($row["honor_last_update"] ?: $row["honor_created"]) ?: "No Date Specified";
					$match = [
						"Date Entered/Updated" => $date,
						"Form" => "Old Honors and Awards",
					];
					foreach ($honorFields as $field => $label) {
						$value = $row[$field] ?? "";
						if ($value) {
							$match[$label] = preg_replace("/[\n\r]+/", "<br/>", trim($value));
						}
					}
					if (count($match) > 2) {
						if (!isset($matchedInfo["$recordId:$instance"])) {
							$matchedInfo["$recordId:$instance"] = [];
						}
						$matchedInfo["$recordId:$instance"][] = $match;
					}
				}
			}
		}
	}
}

echo "<h1>Convert Honors &amp; Awards to a New Format</h1>";
$thisUrl = Application::link("this");
$baseUrl = explode("?", $thisUrl)[0];
echo "<form action='$baseUrl' method='GET'>";
echo REDCapManagement::getParametersAsHiddenInputs($thisUrl);
echo "<p class='centered'><label for='date'>Filter For Results After Date: </label><br/><input type='date' id='date' name='date' value='$startDate' /></p>";
echo "<p class='centered'><button>Change Date</button></p>";
echo "</form>";
if (!in_array("honor_imported", $metadataFields)) {
	$homeUrl = Application::link("index.php");
	echo "<p class='centered max-width'>You need to update your Data Dictionary before proceeding. Please visit the <a href='$homeUrl'>Flight Tracker Home Page</a> to do so.</p>";
} elseif (empty($matchedInfo)) {
	echo "<p class='centered max-width'>No data to convert have been added. No action needed.</p>";
} else {
	echo "<h2>".count($matchedInfo)." Records Affected</h2>";
	echo "<table class='max-width-1000 centered bordered'><thead>";
	echo "<tr class='stickyGrey'><th>Record</th><th>Previous Values</th><th>Transition to New Format<div class='smaller unbolded'>(Honors, Awards, and Activities)</div></th></tr>";
	echo "</thead><tbody>";
	foreach ($matchedInfo as $licensePlate => $matches) {
		list($recordId, $instance) = explode(":", $licensePlate);
		$name = $names[$recordId] ?? "";
		$linkedName = Links::makeRecordHomeLink($pid, $recordId, "$recordId: $name");
		$initialLabels = ["Date Entered/Updated", "Form"];
		foreach ($matches as $match) {
			echo "<tr><th>$linkedName</th>";
			$items = [];
			$initialItems = [];
			foreach ($initialLabels as $label) {
				$value = $match[$label] ?? "";
				if ($value) {
					$initialItems[] = "<strong>$label</strong>: $value";
				}
			}
			if ($match["Form"] == "Initial Survey") {
				$instrument = "initial_survey";
			} elseif ($match["Form"] == "Followup Survey") {
				$instrument = "followup";
			} else {
				$instrument = "old_honors_and_awards";
			}
			if (!empty($initialItems)) {
				$items[] = "<div class='inputDataForm padded'>".implode("<br/>", $initialItems)."</div>";
			}
			foreach ($match as $label => $value) {
				if (!in_array($label, $initialLabels)) {
					$button = "";
					if (preg_match("/ACTION:(.+)$/", $label, $matches)) {
						$func = $matches[1];
						$label = str_replace(" ".$matches[0], "", $label);
						$button = " <button onclick='createActivityHTML(\"$thisUrl\", $(this).parent().parent().parent(), \"$recordId\", \"$func\", $(this).parent().position().top, false); return false;' class='smaller orange'>Reformat to Add</button>";
					}
					if (strlen($value) < 30) {
						$items[] = "<div class='inputDataForm padded'><strong>$label</strong>:$button $value</div>";
					} else {
						$items[] = "<div class='inputDataForm padded'><strong>$label</strong>:$button<div class='scrollable' style='max-height: 400px !important;'>$value</div></div>";
					}
				}
			}
			$doneButton = "<p class='centered'><button class='orange' onclick='markHonorAsDone(\"$thisUrl\", \"$recordId\", \"$instrument\", \"$instance\", $(this).parent().parent().parent()); return false;'>Mark As Done &amp; Don't Show Anymore</button></button></p>";
			echo "<td class='alignLeft alignTop padded'>".implode("<br/>", $items).$doneButton."</td>";
			echo "<td class='dataForm'></td>";
			echo "</tr>";
		}
	}
	echo "</tbody></table>";
}

function makeRadioHTML($fieldLabel, $field, $pid, $defaultIndex = false) {
	$fieldChoices = DataDictionaryManagement::getChoicesForField($pid, $field);
	$labelHTML = "<label for='$field'><strong>$fieldLabel</strong>:</label><br/>";
	$radioHTML = [];
	foreach ($fieldChoices as $index => $label) {
		$checked = "";
		if (($defaultIndex !== false) && ($index == $defaultIndex)) {
			$checked = "checked";
		}
		$id = $field."___".$index;
		$radioHTML[] = "<input type='radio' name='$field' id='$id' value='1' $checked /><label for='$id'> $label</label>";
	}
	return "<p class='alignLeft honorField'>".$labelHTML.implode("<br/>", $radioHTML)."</p>";
}

function addActivityTextHTML(&$htmlAry, $fields) {
	foreach ($fields as $field => $label) {
		$htmlAry[] = "<p><input type='text' class='honorField' id='$field' name='$field' placeholder='$label' /></p>";
	}
}
