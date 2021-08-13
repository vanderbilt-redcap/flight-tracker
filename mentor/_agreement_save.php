<?php
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once dirname(__FILE__)."/preliminary.php";
require_once dirname(__FILE__)."/../small_base.php";
require_once dirname(__FILE__)."/base.php";
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$metadata = Download::metadata($token, $server);
$records = Download::recordIds($token, $server);
if (!$_POST['record_id'] || !in_array($_POST['record_id'], $records)) {
    die("Improper Record Id");
}

$uploadRow = transformCheckboxes($_POST, $metadata);
$uploadRow = handleTimestamps($uploadRow, $token, $server, $metadata);
$uploadRow["redcap_repeat_instrument"] = "mentoring_agreement";

try {
    $feedback = Upload::oneRow($uploadRow, $token, $server);
    echo json_encode($feedback);
} catch (\Exception $e) {
    echo "Exception: ".$e->getMessage();
}


function transformCheckboxes($row, $metadata) {
    $indexedMetadata = REDCapManagement::indexMetadata($metadata);
    $newUploadRow = [];
    foreach ($row as $key => $value) {
        if ($indexedMetadata[$key]) {
            $metadataRow = $indexedMetadata[$key];
            if ($metadataRow['field_type'] == "checkbox") {
                $key = $key."___".$value;
                $value = "1";
            }
        }
        $newUploadRow[$key] = $value;
    }
    return $newUploadRow;
}

function handleTimestamps($row, $token, $server, $metadata) {
    $agreementFields = REDCapManagement::getFieldsFromMetadata($metadata, "mentoring_agreement");
    $instance = $row['redcap_repeat_instance'];
    $recordId = $row['record_id'];
    $redcapData = Download::fieldsForRecords($token, $server, $agreementFields, [$recordId]);
    if (REDCapManagement::findField($redcapData, $recordId, "mentoring_start", $instance)) {
        unset($row['mentoring_start']);
    }
    if (!REDCapManagement::findField($redcapData, $recordId, "mentoring_end", $instance)) {
        $row['mentoring_end'] = date("Y-m-d H:i:s");
    }
    return $row;
}