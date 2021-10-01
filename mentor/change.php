<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Upload;

require_once dirname(__FILE__)."/preliminary.php";
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$recordId = REDCapManagement::sanitize($_REQUEST['record']);
$value = REDCapManagement::sanitize($_REQUEST['value']);
$fieldName = REDCapManagement::sanitize($_REQUEST['field_name']);
$instance = REDCapManagement::sanitize($_REQUEST['instance']);
$type = REDCapManagement::sanitize($_REQUEST['type']);
$userid = REDCapManagement::sanitize($_REQUEST['userid']);
$instrument = "mentoring_agreement";

$recordIds = Download::recordIds($token, $server);
$metadata = Download::metadata($token, $server);
$metadataFields = REDCapManagement::getFieldsFromMetadata($metadata, $instrument);

$uploadRow = [];
if ($type == "radio") {
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
            $fieldName => $value,
            "mentoring_last_update" => date("Y-m-d"),
            "mentoring_userid" => $userid,
            "mentoring_agreement_complete" => "2",
        ];
    }
} else if ($type == "checkbox") {
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
                $fieldName => $value,
                "mentoring_last_update" => date("Y-m-d"),
                "mentoring_userid" => $userid,
                "mentoring_agreement_complete" => "2",
            ];
        }
    }
} else if ($type == "textarea") {
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
            $fieldName => $value,
            "mentoring_last_update" => date("Y-m-d"),
            "mentoring_userid" => $userid,
            "mentoring_agreement_complete" => "2",
        ];
    }
} else if ($type == "notes") {
    if ($recordId
        && in_array($recordId, $recordIds)
        && $instrument
        && $instance
        && $fieldName
        && in_array($fieldName, $metadataFields)
        && $value
    ) {
        $redcapData = Download::fieldsForRecords($token, $server, array("record_id", $fieldName), array($recordId));
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
            "mentoring_last_update" => date("Y-m-d"),
            "mentoring_agreement_complete" => "2",
        ];
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
