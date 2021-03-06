<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");

$recordId = $_REQUEST['record'];
authenticate($userid, $recordId);

$instance = $_REQUEST['instance'];

$metadata = Download::metadata($token, $server);
$metadata = filterMetadata($metadata);
$metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
$notesFields = getNotesFields($metadataFields);

$redcapData = Download::fieldsForRecords($token, $server, array_unique(array_merge(["record_id"], $metadataFields)), [$recordId]);
$skip = array_merge(["record_id", "redcap_repeat_instance", "redcap_repeat_instrument"], $notesFields);
$outputData = [];
foreach ($redcapData as $row) {
    if (($row['redcap_repeat_instrument'] == "mentoring_agreement") && ($row['redcap_repeat_instance'] == $instance)) {
        $outputData = [];
        foreach ($row as $field => $value) {
            if (!in_array($field, $skip)) {
                $outputData[$field] = $value;
            }
        }
        break;
    }
}
echo json_encode($outputData);