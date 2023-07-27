<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

Application::applySecurityHeaders();

require_once(dirname(__FILE__)."/../small_base.php");

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
if (!$newPMID) {
    throw new \Exception("Invalid PMID");
}
if (!in_array($newPMID, $pmids)) {
    $metadata = Download::metadata($token, $server);
    $maxInstance = REDCapManagement::getMaxInstance($redcapData, "citation", $recordId);
    $confirmed = ($state == "checked") ? [$newPMID] : [];
    $upload = Publications::getCitationsFromPubMed([$newPMID], $metadata, "", $recordId, $maxInstance + 1, $confirmed, $pid, TRUE);
    if (!empty($upload)) {
        $feedback = Upload::rows($upload, $token, $server);
        echo json_encode($feedback);
    } else {
        throw new \Exception("Nothing to upload");
    }
} else {
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
}

