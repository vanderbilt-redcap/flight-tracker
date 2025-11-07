<?php

namespace Vanderbilt\CareerDevLibrary;

require_once dirname(__FILE__)."/preliminary.php";
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$recordId = REDCapManagement::sanitize($_REQUEST['record']);
$value = REDCapManagement::sanitize($_REQUEST['value']);
$fieldName = REDCapManagement::sanitize($_REQUEST['field_name']);
$instance = REDCapManagement::sanitize($_REQUEST['instance']);
$type = REDCapManagement::sanitize($_REQUEST['type']);
$userid = MMAHelper::isValidHash($_REQUEST['userid']) ? "" : REDCapManagement::sanitize($_REQUEST['userid']);
$start = REDCapManagement::sanitize($_REQUEST['start']);
$phase = REDCapManagement::sanitize($_REQUEST['phase']);
$end = date("Y-m-d H:i:s");
$instrument = "mentoring_agreement";
$pid = REDCapManagement::sanitize($_GET['pid']);
list($myMentees, $myMentors) = MMAHelper::getMenteesAndMentors($recordId, $userid, $token, $server);
if (preg_match("/^".MMAHelper::CUSTOM_QUESTIONS_SOURCE_KEY."/", $fieldName)) {
	$saveField = "_admin";
	$adminField = true;
} else {
	$saveField = "";
	$adminField = false;
}
$currentCustomResponses = MMAHelper::getCustomQuestionData($pid, $recordId, $instance, $adminField);

if (MMAHelper::doesMentoringStartExist($recordId, $instance, $pid)) {
    $start = "";
}

$recordIds = Download::recordIds($token, $server);
$metadata = Download::metadata($token, $server);
$metadataFields = REDCapManagement::getFieldsFromMetadata($metadata, $instrument);
$customQuestionData = MMAHelper::getCustomQuestions($pid, $myMentors);
list($customMetadata) = MMAHelper::getMetadataForCustomQuestions($customQuestionData);
$customFields = [];
foreach ($customMetadata as $field => $info) {
	$customFields[] = $info['field_name'];
}
$metadataFields = array_merge($metadataFields, $customFields);
$uploadRow = [];
if (preg_match("/^".MMAHelper::CUSTOM_QUESTIONS_SOURCE_KEY."/", $fieldName)) {
	$saveField = "_admin";
} else {
	$saveField = "";
}
if ($type == "radio" || $type == "yesno") {
	if ($recordId
		&& in_array($recordId, $recordIds)
		&& isset($value)
		&& $fieldName
		&& in_array($fieldName, $metadataFields)
	) {
		$uploadRow = [
			"record_id" => $recordId,
			"redcap_repeat_instrument" => $instrument,
			"redcap_repeat_instance" => $instance,
			"mentoring_last_update" => date("Y-m-d"),
			"mentoring_agreement_complete" => "2",
		];
		if (in_array($fieldName, $customFields)) {
			if (empty($currentCustomResponses)) {
				$currentCustomResponses = [];
			}
			$currentCustomResponses[$fieldName] = $value;
			$uploadRow["mentoring_custom_question_json$saveField"] = json_encode($currentCustomResponses);
			$uploadRow["mentoring_custom_question_readable$saveField"] = MMAHelper::getCustomQuestionsReadable($customQuestionData, $currentCustomResponses);
		} else {
			$uploadRow[$fieldName] = $value;
		}
		if ($userid) {
			$uploadRow["mentoring_userid"] = $userid;
		}
	}
} elseif ($type == "checkbox") {
	if (preg_match("/___/", $fieldName)) {
		$checkboxValues = ["", "0", "1",];
		list($field, $key) = preg_split("/___/", $fieldName);
		if ($recordId
			&& in_array($recordId, $recordIds)
			&& isset($value)
			&& in_array($value, $checkboxValues)
			&& $field
			&& in_array($field, $metadataFields)
		) {
			$uploadRow = [
				"record_id" => $recordId,
				"redcap_repeat_instrument" => $instrument,
				"redcap_repeat_instance" => $instance,
				"mentoring_last_update" => date("Y-m-d"),
				"mentoring_phase" => $phase,
				"mentoring_end" => $end,
				"mentoring_agreement_complete" => "2",
			];
			if (in_array($field, $customFields)) {
				if (empty($currentCustomResponses)) {
					$currentCustomResponses = [];
				}
				$currentCustomResponses[$field][$key] = $value;
				$uploadRow["mentoring_custom_question_json$saveField"] = json_encode($currentCustomResponses);
				$uploadRow["mentoring_custom_question_readable$saveField"] = MMAHelper::getCustomQuestionsReadable($customQuestionData, $currentCustomResponses);
			} else {
				$uploadRow[$fieldName] = $value;
			}
            if ($start) {
                $uploadRow["mentoring_start"] = $start;
            }
			if ($userid) {
				$uploadRow["mentoring_userid"] = $userid;
			}
		}
	}
} elseif ($type == "textarea") {
	if ($recordId
		&& in_array($recordId, $recordIds)
		&& $instrument
		&& $instance
		&& $fieldName
		&& in_array($fieldName, $metadataFields)
	) {
		$uploadRow = [
			"record_id" => $recordId,
			"redcap_repeat_instrument" => $instrument,
			"redcap_repeat_instance" => $instance,
			"mentoring_last_update" => date("Y-m-d"),
			"mentoring_end" => $end,
			"mentoring_phase" => $phase,
			"mentoring_agreement_complete" => "2",
		];
		if (in_array($fieldName, $customFields)) {
			if (empty($currentCustomResponses)) {
				$currentCustomResponses = [];
			}
			$currentCustomResponses[$fieldName] = $value;
			$uploadRow["mentoring_custom_question_json$saveField"] = json_encode($currentCustomResponses);
			$uploadRow["mentoring_custom_question_readable$saveField"] = MMAHelper::getCustomQuestionsReadable($customQuestionData, $currentCustomResponses);
		} else {
			$uploadRow[$fieldName] = $value;
		}
        if ($start) {
            $uploadRow["mentoring_start"] = $start;
        }
		if ($userid) {
			$uploadRow["mentoring_userid"] = $userid;
		}
	}
} elseif ($type == "notes") {
	if ($recordId
		&& in_array($recordId, $recordIds)
		&& $instrument
		&& $instance
		&& $fieldName
		&& in_array($fieldName, $metadataFields)
		&& $value
	) {
		$redcapData = Download::fieldsForRecords($token, $server, ["record_id", $fieldName], [$recordId]);
		$priorNote = REDCapManagement::findField($redcapData, $recordId, $fieldName, "mentoring_agreement", $instance);
		if ($priorNote) {
			$newNote = $priorNote . "\n". $value;
		} else {
			$newNote = $value;
		}

		$uploadRow = [
			"record_id" => $recordId,
			"redcap_repeat_instrument" => $instrument,
			"redcap_repeat_instance" => $instance,
			$fieldName => $newNote,
			"mentoring_end" => $end,
			"mentoring_phase" => $phase,
			"mentoring_last_update" => date("Y-m-d"),
			"mentoring_agreement_complete" => "2",
		];
        if ($start) {
            $uploadRow["mentoring_start"] = $start;
        }
	}
}


if (!empty($uploadRow)) {
	try {
		$feedback = Upload::oneRow($uploadRow, $token, $server);
		echo json_encode($feedback);
	} catch (\Exception $e) {
		echo "Exception: ".$e->getMessage()."<br>\n".REDCapManagement::sanitize($e->getTraceAsString());
	}
} else {
	echo "No inputs!";
}
