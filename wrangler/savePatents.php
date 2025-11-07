<?php

use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\PatentsView;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$patentFields = array_merge(["record_id"], Download::metadataFieldsByPidWithPrefix($pid, "patent_"));
$metadata = Download::metadataByPid($pid, $patentFields);
$pmids = [];
$numbers = [];
if (
    isset($_POST['finalized'])
    || isset($_POST['omissions'])
    || isset($_POST['resets'])
) {
    $records = Download::recordIds($token, $server);
    $newFinalized = Sanitizer::sanitizeArray($_POST['finalized'] ?? []);
    $newOmissions = Sanitizer::sanitizeArray($_POST['omissions'] ?? []);
    $newResets = Sanitizer::sanitizeArray($_POST['resets'] ?? []);
    $recordId = Sanitizer::getSanitizedRecord($_POST['record_id'], $records);

    $redcapData = Download::fieldsForRecords($token, $server, $patentFields, [$recordId]);
    $maxInstance = REDCapManagement::getMaxInstance($redcapData, "patent", $recordId);

	$priorNumbers = [];
	$upload = [];
	$toProcess = array("1" => $newFinalized, "0" => $newOmissions, "" => $newResets);
	foreach ($toProcess as $val => $aryOfNumbers) {
		foreach ($aryOfNumbers as $number) {
			$matched = FALSE;
			foreach ($redcapData as $row) {
				if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "patent")) {
					if ($number == $row['patent_number']) {
                        $uploadRow = [
                            "record_id" => $recordId,
                            "redcap_repeat_instrument" => "patent",
                            "redcap_repeat_instance" => $row['redcap_repeat_instance'],
                            "patent_include" => $val,
                            "patent_complete" => Publications::getPublicationCompleteStatusFromInclude(strval($val)),
                        ];
						$priorNumbers[] = $number;
						$upload[] = $uploadRow;
						$matched = TRUE;
						break;
					}
				}
			}
			if (!$matched) {
				# new patent
				$patents = new PatentsView($recordId, $pid, "none", $metadata);
				$patentData = $patents->getDetails($number);
				$uploadRows = $patents->patents2REDCap($patentData, $maxInstance);
				$maxInstance += count($uploadRows);
				$priorNumbers[] = $number;
				$upload = array_merge($upload, $uploadRows);
			}
		}
	}
	if (!empty($upload)) {
		$feedback = Upload::rows($upload, $token, $server);
		echo json_encode($feedback);
	} else {
		$data = array("error" => "You don't have any new patents enqueued to change!");
		echo json_encode($data);
	}
	exit;
} else if (isset($_POST['number'])) {
    $numbers = [Sanitizer::sanitize($_POST['number'])];
} else if (isset($_POST['numbers'])) {
    $numbers = Sanitizer::sanitizeArray($_POST['numbers']);
} else {
    $data = array("error" => "You don't have any input! This should never happen.");
    echo json_encode($data);
    exit;
}

if ($numbers && !empty($numbers)) {
    $records = Download::recordIds($token, $server);
    $recordId = Sanitizer::getSanitizedRecord($_POST['record_id'], $records);
    $redcapData = Download::fieldsForRecords($token, $server, $patentFields, [$recordId]);

    $existingNumbers = [];
    foreach ($redcapData as $row) {
        if (($row['redcap_repeat_instrument'] == "patent") && $row['patent_number']) {
            $existingNumbers[] = $row['patent_number'];
        }
    }
    $dedupedNumbers = [];
    foreach ($numbers as $number) {
        if (!in_array($number, $existingNumbers)) {
            $dedupedNumbers[] = $number;
        }
    }

    if ($recordId && !empty($dedupedNumbers)) {
        $maxInstance = REDCapManagement::getMaxInstance($redcapData, "patent", $recordId);
        $maxInstance++;
        $patents = new PatentsView($recordId, $pid, "none", $metadata);
        $upload = [];
        foreach ($dedupedNumbers as $number) {
            $patentData = $patents->getDetails($number);
            $uploadRows = $patents->patents2REDCap($patentData, $maxInstance);
            $upload = array_merge($upload, $uploadRows);
        }
        for ($i = 0; $i < count($upload); $i++) {
            if ($upload[$i]['redcap_repeat_instrument'] == "patent") {
                $upload[$i]['patent_include'] = '1';
            }
        }
        if (!empty($upload)) {
            $feedback = Upload::rows($upload, $token, $server);
            echo json_encode($feedback);
        } else {
            echo json_encode(array("error" => "Upload queue empty!"));
        }
    } else {
        $feedback = [
            "error" => "All of the requested patents exist in the database. Perhaps they have been omitted earlier.",
        ];
        echo json_encode($feedback);
    }
} else {
    $feedback = [
        "error" => "Empty list of Patents",
    ];
    echo json_encode($feedback);
}
