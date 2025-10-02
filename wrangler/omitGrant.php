<?php

use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Upload;
use Vanderbilt\CareerDevLibrary\Grant;
use Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$records = Download::recordIds($token, $server);
$recordId = REDCapManagement::getSanitizedRecord($_POST['record'], $records);
$grantNumber = REDCapManagement::sanitize($_POST['grantNumber']);
$source = REDCapManagement::sanitize($_POST['source']);
if ($recordId && $grantNumber && $source) {
	$fields = [
		"record_id",
		"summary_calculate_to_import",
		"summary_calculate_list_of_awards",
	];
	$redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
	$listOfAwardsJSON = REDCapManagement::findField($redcapData, $recordId, "summary_calculate_list_of_awards");
	$listOfAwards = json_decode($listOfAwardsJSON, true);
	$foundGrant = false;
	if ($listOfAwards) {
		foreach ($listOfAwards as $specs) {
			if (($source == $specs['source']) && ($grantNumber == $specs['sponsor_award_no'])) {
				$foundGrant = $specs;
				break;
			}
		}
	}
	if ($foundGrant) {
		$toImportJSON = REDCapManagement::findField($redcapData, $recordId, "summary_calculate_to_import");
		$toImport = json_decode($toImportJSON, true);
		if (!$toImport) {
			$toImport = [];
		}
		$foundIndex = Grant::getIndex($foundGrant["sponsor_award_no"], $foundGrant["sponsor"], $foundGrant["start_date"]);
		if (isset($toImport[$foundIndex])) {
			$toImport[$foundIndex][0] = "REMOVE";
		} else {
			$toImport[$foundIndex] = ["REMOVE", $foundGrant];
		}
		$toImportJSON = json_encode($toImport);
		$uploadRow = ["record_id" => $recordId, "summary_calculate_to_import" => $toImportJSON];
		$feedback = Upload::oneRow($uploadRow, $token, $server);
		echo json_encode($feedback);
	} else {
		echo "Error: Not found";
	}
} else {
	echo "Error: Improper post parameters";
}
