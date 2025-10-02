<?php

namespace Vanderbilt\CareerDevLibrary;

require_once dirname(__FILE__)."/preliminary.php";
require_once dirname(__FILE__)."/../small_base.php";
require_once dirname(__FILE__)."/base.php";
require_once(dirname(__FILE__)."/../classes/Autoload.php");

require_once dirname(__FILE__).'/_header.php';

if ($_REQUEST['uid'] && MMAHelper::getMMADebug()) {
	$userid2 = Sanitizer::sanitize($_REQUEST['uid']);
	$uidString = "&uid=$userid2";
	$spoofing = MMAHelper::makeSpoofingNotice($userid2);
} else {
	$userid2 = $hash ?: Application::getUsername();
	$uidString = $hash ? "&hash=".$hash : "";
	$spoofing = "";
}
$phase = Sanitizer::sanitize($_GET['phase'] ?? "");


$menteeRecordId = false;
if (isset($_GET['menteeRecord'])) {
	$records = Download::recordIdsByPid($pid);
	$menteeRecordId = Sanitizer::getSanitizedRecord($_GET['menteeRecord'], $records);
	list($myMentees, $myMentors) = MMAHelper::getMenteesAndMentors($menteeRecordId, $userid2, $token, $server);
} else {
	throw new \Exception("You must specify a mentee record!");
}

$menteeName = Download::fullName($token, $server, $menteeRecordId);
$metadataFields = Download::metadataFieldsByPidWithPrefix($pid, MMAHelper::PREFIX);
$metadata = MMAHelper::getMetadata($pid, $metadataFields);
$allMetadataForms = Download::metadataForms($token, $server);
$choices = DataDictionaryManagement::getChoices($metadata);
$notesFields = MMAHelper::getNotesFields($metadataFields);

list($firstName, $lastName) = MMAHelper::getNameFromREDCap($userid2, $token, $server);
$otherMentors = REDCapManagement::makeConjunction($myMentors["name"] ?? []);
$otherMentees = REDCapManagement::makeConjunction($myMentees["name"] ?? []);

$fields = array_unique(array_merge(["record_id", "mentoring_userid", "mentoring_last_update", "mentoring_panel_names", "mentoring_userid"], $metadataFields));
$redcapData = Download::fieldsForRecordsByPid($pid, $fields, [$menteeRecordId]);
if ($_REQUEST['instance']) {
	$currInstance = Sanitizer::sanitizeInteger($_REQUEST['instance']);
} elseif ($hash) {
	$currInstance = 1;
} else {
	$maxInstance = REDCapManagement::getMaxInstance($redcapData, MMAHelper::INSTRUMENT, $menteeRecordId);
	$currInstance = $maxInstance + 1;
}
$dateToRemind = MMAHelper::getDateToRemind($redcapData, $menteeRecordId, $currInstance);
$menteeUsernames = MMAHelper::getMenteeUserids(Download::singleUserid($pid, $menteeRecordId));
$menteeInstance = false;
if ($hash) {
	$menteeInstance = 1;
} else {
	foreach ($menteeUsernames as $menteeUsername) {
		$menteeInstance = MMAHelper::getMaxInstanceForUserid($redcapData, $menteeRecordId, $menteeUsername);
		if ($menteeInstance) {
			break;
		}
	}
}
$surveysAvailableToPrefill = MMAHelper::getMySurveys($userid2, $token, $server, $menteeRecordId, $currInstance);
list($priorNotes, $instances) = $menteeInstance ? MMAHelper::makePriorNotesAndInstances($redcapData, $notesFields, $menteeRecordId, $menteeInstance) : [[], []];
$currInstanceRow = [];
$currInstanceRow = REDCapManagement::getRow($redcapData, $menteeRecordId, MMAHelper::INSTRUMENT, $currInstance);
$menteeInstanceRow = $menteeInstance ? REDCapManagement::getRow($redcapData, $menteeRecordId, MMAHelper::INSTRUMENT, $menteeInstance) : [];
$customQuestions = MMAHelper::getCustomQuestions($pid, $myMentors);
list($customQuestionMetadata, $customNotesFields) = MMAHelper::getMetadataForCustomQuestions($customQuestions); //Configuration metadata
$notesFields = array_merge($notesFields, $customNotesFields);
$metadata = array_merge($metadata, $customQuestionMetadata);
$menteeCustomQuestionResponses = array_merge(MMAHelper::getCustomQuestionData($pid, $menteeRecordId, $menteeInstance, false), MMAHelper::getCustomQuestionData($pid, $menteeRecordId, $menteeInstance, true));
$mentorCustomQuestionResponses = array_merge(MMAHelper::getCustomQuestionData($pid, $menteeRecordId, $currInstance, false), MMAHelper::getCustomQuestionData($pid, $menteeRecordId, $currInstance, true));
$completeURL = Application::link("mentor/index_complete.php").$uidString."&menteeRecord=$menteeRecordId&instance=$currInstance";
$metadata = MMAHelper::removeCustomQuestionFieldsFromMetadata($metadata);
?>
<form id="tsurvey" name="tsurvey">
<input type="hidden" class="form-hidden-data" name="mentoring_start" id="mentoring_start" value="<?= date("Y-m-d H:i:s") ?>">
<input type="hidden" class="form-hidden-data" name="mentoring_phase" id="mentoring_phase" value="<?= $phase ?>">
<section class="bg-light">
    <div class="container">
        <div class="row">
            <div class="col-lg-12">


                <h2 style="color: #727272;">Hi, <?= $firstName ?: "Unknown Name" ?>!</h2>
                <?= $spoofing ?>

                <?= MMAHelper::makeSurveyHTML($menteeName, "mentee", $menteeRecordId, $metadata) ?>

            </div>

        </div>

        <div class="row">
            <div class="col-lg-12 tdata">
                <h4 class="centered">Please fill out the checklist below during dialogue with your mentee. The mentee's responses have been pre-filled.</h4>

                    <?php
					if (!empty($surveysAvailableToPrefill)) {
						echo MMAHelper::makePrefillHTML($surveysAvailableToPrefill, $uidString);
					}

$htmlRows = [];
$sections = [];
$tableNum = 1;
$i = 0;
$skipFieldTypes = ["file", "text"];
$agreementSigned = MMAHelper::agreementSigned($redcapData, $menteeRecordId, $currInstance);
$skipFields = ["mentoring_phase", "mentoring_custom_question_json", "mentoring_custom_question_readable", "mentoring_custom_question_json_admin", "mentoring_custom_question_readable_admin"];
$hasAnyAnswers = false;
foreach ($metadata as $row) {
	$field = $row['field_name'];
	$isCustomQuestion = $row['form_name'] === "custom_questions";
	if ($isCustomQuestion && $row['field_type'] !== "yesno") {
		$choiceTemp = explode("|", $row['select_choices_or_calculations']);
		$choiceTemp2 = [];
		foreach ($choiceTemp as $choice) {
			$temp = explode(",", trim($choice));
			$choiceTemp2[$temp[0]] = trim($temp[1]);
		}
		$choices[$row['field_name']] = $choiceTemp2;
	}
	if ($isCustomQuestion && $row['field_type'] == "yesno") {
		$choices[$row['field_name']] = ["1" => "Yes", "0" => "No"];
	}
	if (
		$row['section_header']
		&& !in_array($row['field_type'], $skipFieldTypes)
		&& !in_array($field, $skipFields)
	) {
		list($sec_header, $sectionDescription) = MMAHelper::parseSectionHeader($row['section_header']);
		$sections[$tableNum] = $sec_header;
		$encodedSection = REDCapManagement::makeHTMLId($row['section_header']);

		if ($tableNum > 1) {
			$htmlRows[] = "</tbody></table>";
		}
		$tableId = "quest$tableNum";
		$hasAnswers = MMAHelper::hasDataInSection($metadata, $row['section_header'], $menteeRecordId, $menteeInstance, MMAHelper::INSTRUMENT, $menteeInstanceRow);
		if ($hasAnswers) {
			$hasAnyAnswers = true;
			$displayTable = "";
		} else {
			$displayTable = "display: none;";
		}
		$htmlRows[] = "<table id='$tableId' class='table $encodedSection' style='margin-left: 0; $displayTable'>";
		if ($row['field_type'] != "descriptive") {
			$htmlRows[] = '<thead>';
			$htmlRows[] = '<tr>';
			$htmlRows[] = '<th style="text-align: left;" scope="col"></th>';
			$htmlRows[] = '<th style="text-align: center; border-right: 0px;" scope="col"></th>';
			$htmlRows[] = '<th style="text-align: center;" scope="col"></th>';
			$htmlRows[] = '<th style="text-align: center;" scope="col"></th>';
			$htmlRows[] = '</tr>';
			$htmlRows[] = '<tr>';
			$htmlRows[] = '<th style="text-align: left;" scope="col">question</th>';
			$htmlRows[] = '<th style="text-align: center;" scope="col">mentor responses</th>';
			$htmlRows[] = '<th style="text-align: center;" scope="col">latest note<br>(click for full conversation)</th>';
			$htmlRows[] = '<th style="text-align: center;" scope="col">mentee responses</th>';
			$htmlRows[] = '</tr>';
			$htmlRows[] = '</thead>';
		}
		$htmlRows[] = '<tbody>';

		$tableNum++;
	}
	if (
		!in_array($field, $notesFields)
		&& !in_array($row['field_type'], $skipFieldTypes)
		&& !preg_match("/@HIDDEN/", $row['field_annotation'])
		&& !in_array($field, $skipFields)
	) {
		$i++;
		$prefices = ["radio" => "exampleRadiosh", "checkbox" => "exampleChecksh", "notes" => "exampleTextareash"];
		if ($row['field_type'] == "yesno") {
			$row['field_type'] = "radio";
		}
		if ($row['field_type'] == "textarea") {
			$row['field_type'] = "notes";
		}
		$menteeFieldValues = [];
		$mentorFieldValues = [];
		if (in_array($row['field_type'], ["radio", "notes"])) {
			if ($isCustomQuestion) {
				$menteeValue = $menteeCustomQuestionResponses[$row['field_name']];
				$mentorValue = $mentorCustomQuestionResponses[$row['field_name']];
			} else {
				$menteeValue = $menteeInstance ? REDCapManagement::findField([$menteeInstanceRow], $menteeRecordId, $field, MMAHelper::INSTRUMENT, $menteeInstance) : "";
				$mentorValue = REDCapManagement::findField([$currInstanceRow], $menteeRecordId, $field, MMAHelper::INSTRUMENT, $currInstance);
			}
			if ($menteeValue !== "") {
				$menteeFieldValues = [$menteeValue];
			}
			if ($mentorValue !== "") {
				$mentorFieldValues = [$mentorValue];
			}
		} elseif ($row['field_type'] == "checkbox") {
			foreach ($choices[$field] as $index => $label) {
				if ($isCustomQuestion) {
					$menteeValue = (int) $menteeCustomQuestionResponses[$row['field_name']][$index];
					$mentorValue = (int) $mentorCustomQuestionResponses[$row['field_name']][$index];
				} else {
					$menteeValue = $menteeInstance ? REDCapManagement::findField([$menteeInstanceRow], $menteeRecordId, $field."___".$index, MMAHelper::INSTRUMENT, $menteeInstance) : "";
					$mentorValue = REDCapManagement::findField([$currInstanceRow], $menteeRecordId, $field."___".$index, MMAHelper::INSTRUMENT, $currInstance);
				}
				if ($menteeValue == "1") {
					$menteeFieldValues[] = $index;
				}
				if ($mentorValue == "1") {
					$mentorFieldValues[] = $index;
				}
			}
		}
		$specs = [
			"mentor" => ["values" => $mentorFieldValues, "suffix" => "", "colClass" => "thementor", "status" => "", ],
			"mentee" => ["values" => $menteeFieldValues, "suffix" => "_menteeanswer", "colClass" => "thementee", "status" => "disabled", ],
		];
		if (MMAHelper::fieldValuesAgree($mentorFieldValues, $menteeFieldValues) || ($row['field_type'] == "notes")) {
			$status = "agree";
		} else {
			$status = "disagree";
		}
		if ($agreementSigned) {
			$statusClass = "";
		} else {
			$statusClass = " class='$status'";
		}
		if ($row['field_type'] == "descriptive") {
			$htmlRows[] = "<tr id='$field-tr'$statusClass>";
			$htmlRows[] = '<td colspan="4">'.MMAHelper::pipeIfApplicable($token, $server, $row['field_label'], $menteeRecordId, $currInstance, $userid2).'</td>';
			$htmlRows[] = "</tr>";
		} else {
			$htmlRows[] = "<tr id='$field-tr'$statusClass>";
			$htmlRows[] = '<th scope="row">'.MMAHelper::pipeIfApplicable($token, $server, $row['field_label'], $menteeRecordId, $currInstance, $userid2).'</th>';
			$prefix = $prefices[$row['field_type']];
			foreach ($specs as $key => $spec) {
				$suffix = "";
				if (in_array($row['field_type'], ["checkbox", "radio"])) {
					$htmlRows[] = "<td class='{$spec['colClass']}'>";
					foreach ($choices[$field] as $index => $label) {
						$name = $prefix.$field.$spec['suffix'];
						$id = $name."___".$index;
						$selected = "";
						if ($spec['values'] !== [0 => null] && in_array($index, $spec['values'])) {
							$selected = "checked";
						}
						if ($row['field_type'] == "checkbox") {
							$name .= "[]";
						}
						$htmlRows[] = '<div class="form-check"><input class="form-check-input" onclick="doMMABranching();" type="'.$row['field_type'].'" name="'.$name.'" id="'.$id.'" value="'.$index.'" '.$selected.' '.$spec['status'].'><label class="form-check-label" for="'.$id.'">'.$label.'</label></div>';
					}
					$htmlRows[] = '</td>';
				} elseif (($row['field_type'] == "notes") && ($key == "mentor")) {
					$name = $prefix.$field.$spec['suffix'];
					$id = $name;
					if ($isCustomQuestion) {
						$mentorValue = $mentorCustomQuestionResponses[$row['field_name']];
						$menteeValue = $menteeCustomQuestionResponses[$row['field_name']];
					} else {
						$mentorValue = $spec['values'][0];
						$menteeValue = $menteeInstance ? REDCapManagement::findField($redcapData, $menteeRecordId, $field, MMAHelper::INSTRUMENT, $menteeInstance) : "";
					}

					if ($mentorValue) {
						$value = $mentorValue;
					} else {
						$value = $menteeValue;
					}
					if ($isCustomQuestion) {
						$class = "custom-question-textarea";
					} else {
						$class = "";
					}

					$htmlRows[] = "<td class='{$spec['colClass']}' colspan='3'>";
					$htmlRows[] = '<div class="form-check" style="height: 100px;"><textarea class="' . $class .' form-check-input" name="'.$name.'" id="'.$id.'">'.$value.'</textarea></div>';
					$htmlRows[] = '</td>';
				}
				if ($key == "mentor" && !$isCustomQuestion) {
					$htmlRows[] = MMAHelper::makeNotesHTML($field, [$menteeInstanceRow], $menteeRecordId, $menteeInstance, $notesFields);
				} elseif ($key == "mentor") {
					$htmlRows[] = "<td></td>";
				}
			}
		}
	}
	$htmlRows[] = '</tr>';
}
$htmlRows[] = "</tbody></table>";
echo implode("\n", $htmlRows);

if (!$hasAnyAnswers) {
	echo "<p style='text-align: center'>Your mentee provided no answers to their questions.</p>";
}
?>
            </div>
        </div>
    </div>
</section>
</form>

<?php
if (!$hash) {
	echo "<p style='text-align: center;'>Saving will enqueue an automated email to follow up, to be sent on ".REDCapManagement::MDY2LongDate($dateToRemind).".</p>";
}
?>

<p style="text-align: center;"><button type="button" class="btn btn-info" onclick="saveagreement(function() { window.location='<?= $completeURL ?>'; });">save, view &amp; sign final agreement</button></p
<p style="height: 200px"></p>
<div class="fauxcomment" style="display: none;"></div>

<?php
$commentJS = MMAHelper::makeCommentJS($userid2, $menteeRecordId, $menteeInstance, $currInstance, $priorNotes, $menteeName, $dateToRemind, false, in_array("mentoring_agreement_evaluations", $allMetadataForms), $pid);
$isAgreementSigned = MMAHelper::agreementSigned($redcapData, $menteeRecordId, $currInstance);
echo MMAHelper::getMentorHead($menteeRecordId, $currInstance, $uidString, $userid2, $sections, $commentJS, $isAgreementSigned);
include dirname(__FILE__).'/_footer.php';
