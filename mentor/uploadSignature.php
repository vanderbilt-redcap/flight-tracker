<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Upload;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");

$field = $_REQUEST['field'];
$menteeRecord = $_REQUEST['menteeRecord'];
$b64image = $_REQUEST['b64image'];
$mimeType = $_REQUEST['mime_type'];
$instance = $_REQUEST['instance'];
$date = $_REQUEST['date'];

authenticate($userid, $menteeRecord);

$dateField = $field."_date";
$metadata = Download::metadata($token, $server);
$metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
$metadataRow = REDCapManagement::getRowForFieldFromMetadata($field, $metadata);
if (
    $field &&
    $menteeRecord &&
    $b64image &&
    $mimeType &&
    $instance &&
    $date &&
    $pid &&
    in_array($field, $metadataFields) &&
    in_array($dateField, $metadataFields) &&
    ($metadataRow['field_type'] == "file")) {

    if (preg_match("/image\/svg\+xml/", $mimeType)) {
        $filename = "signature.svg";
    } else if (preg_match("/image\/png/", $mimeType)) {
        $filename = "signature.png";
    } else if (preg_match("/image\/jpe?g/", $mimeType)) {
        $filename = "signature.jpg";
    } else {
        $filename = "signature";
    }

    $uploadRow = [
        "record_id" => $menteeRecord,
        "redcap_repeat_instrument" => "mentoring_agreement",
        "redcap_repeat_instance" => $instance,
        $dateField => $date,
    ];
    $fileFeedback = Upload::file($pid, $menteeRecord, $field, $b64image, $filename, $instance);
    if (!$fileFeedback["error"]) {
        $feedback = Upload::oneRow($uploadRow, $token, $server);
        echo json_encode($fileFeedback)." ".json_encode($feedback);
    } else {
        echo "Error: ".json_encode($fileFeedback);
    }
} else {
    echo "Improper inputs";
}
echo "Done\n";
