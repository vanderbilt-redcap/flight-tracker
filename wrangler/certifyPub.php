<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$records = Download::recordIds($token, $server);
$recordId = REDCapManagement::getSanitizedRecord($_POST['record'], $records);
$newPMID = REDCapManagement::sanitize($_POST['pmid']);
$state = REDCapManagement::sanitize($_POST['state']);
$hash = REDCapManagement::sanitize($_POST['hash']);

if (!$recordId || !in_array($recordId, $records)) {
    throw new \Exception("Invalid Record");
}

if (!REDCapManagement::isValidSurvey($pid, $hash)) {
    throw new \Exception("Invalid Survey");
}

if ($state == "checked") {
    $includeValue = "1";
} else if ($state == "unchecked") {
    $includeValue = "";
} else if ($state == "omitted") {
    $includeValue = "0";
} else {
    throw new \Exception("Invalid state");
}

$redcapData = Download::fieldsForRecords($token, $server, ["record_id", "citation_pmid"], [$recordId]);
$pmids = [];
foreach ($redcapData as $row) {
    $pmids[] = $row['citation_pmid'];
}
if (!$newPMID || !in_array($newPMID, $pmids)) {
    throw new \Exception("Invalid PMID");
}

foreach ($redcapData as $row) {
    if (($row['record_id'] == $recordId) && ($row['citation_pmid'] == $newPMID)) {
        $uploadRow = [
            "record_id" => $recordId,
            "redcap_repeat_instrument" => "citation",
            "redcap_repeat_instance" =>  $row['redcap_repeat_instance'],
            "citation_include" => $includeValue,
        ];
        $feedback = Upload::oneRow($uploadRow, $token, $server);
        echo json_encode($feedback);
    }
}
