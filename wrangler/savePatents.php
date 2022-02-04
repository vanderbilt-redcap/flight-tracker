<?php

use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\PatentsView;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$metadata = Download::metadata($token, $server);
$pmids = [];
$numbers = [];
if (isset($_POST['finalized'])) {
	$newFinalized = json_decode($_POST['finalized']);
    $newOmissions = json_decode($_POST['omissions']);
    $newResets = json_decode($_POST['resets']);
	$recordId = REDCapManagement::sanitize($_POST['record_id']);

    $patentFields = Application::getPatentFields($metadata);
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
						$uploadRow = array(
									"record_id" => $recordId,
									"redcap_repeat_instrument" => "patent",
									"redcap_repeat_instance" => $row['redcap_repeat_instance'],
									"patent_include" => $val,
									);
						$priorNumbers[] = $number;
						$upload[] = $uploadRow;
						$matched = TRUE;
						break;
					}
				}
			}
			if (!$matched) {
				# new patent
				$patents = new PatentsView($recordId, $pid);
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
} else if (isset($_POST['number'])) {
    $numbers = [$_POST['number']];
} else if (isset($_POST['numbers'])) {
    $numbers = $_POST['numbers'];
} else {
    $data = array("error" => "You don't have any input! This should never happen.");
    echo json_encode($data);
}

if ($numbers && !empty($numbers)) {
    $recordId = $_POST['record_id'];
    $patentFields = Application::getPatentFields($metadata);
    $redcapData = Download::fieldsForRecords($token, $server, $patentFields, [$recordId]);
    if ($recordId) {
        $maxInstance = REDCapManagement::getMaxInstance($redcapData, "patent", $recordId);
        $maxInstance++;
        $patents = new PatentsView($recordId, $pid);
        $upload = [];
        foreach ($numbers as $number) {
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
    }
}
