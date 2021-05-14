<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");

$recordId = $_POST['record'];
$instance = $_POST['instance'];
$pmid = $_POST['pmid'];

if ($recordId && $instance && $pmid) {
    $records = Download::recordIds($token, $server);
    if (in_array($recordId, $records)) {
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
        echo "Error: Could not find record";
    }
} else {
    echo "Error: Wrong post parameters";
}