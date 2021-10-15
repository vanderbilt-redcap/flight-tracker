<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$records = Download::recordIds($token, $server);
$recordId = REDCapManagement::getSanitizedRecord($_POST['record'], $records);
$instance = REDCapManagement::sanitize($_POST['instance']);
$pmid = REDCapManagement::sanitize($_POST['pmid']);

if ($recordId && $instance && $pmid) {
    $citationFields = ["record_id", "citation_pmid", "citation_include"];
    $redcapData = Download::fieldsForRecords($token, $server, $citationFields, [$recordId]);
    $found = FALSE;
    foreach ($redcapData as $row) {
        if (($row['redcap_repeat_instrument'] == "citation")
            && ($row['redcap_repeat_instance'] == $instance)
            && ($row['citation_pmid'] == $pmid)
            && ($row['record_id'] == $recordId)) {
            $found = TRUE;
        }
    }
    if ($found) {
        $uploadRow = [
            "record_id" => $recordId,
            "redcap_repeat_instrument" => "citation",
            "redcap_repeat_instance" => $instance,
            "citation_include" => "0",
        ];
        $feedback = Upload::oneRow($uploadRow, $token, $server);
        echo json_encode($feedback);
    } else {
        echo "Error: Could not find instance";
    }
} else {
    echo "Error: Wrong post parameters";
}