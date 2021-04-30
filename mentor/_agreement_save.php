<?php
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once dirname(__FILE__)."/../small_base.php";
require_once dirname(__FILE__)."/base.php";
require_once(dirname(__FILE__)."/../classes/Upload.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");

$metadata = Download::metadata($token, $server);
$records = Download::recordIds($token, $server);
if (!$_POST['record_id'] || !in_array($_POST['record_id'], $records)) {
    die("Improper Record Id");
}

$uploadRow = transformCheckboxes($_POST, $metadata);
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