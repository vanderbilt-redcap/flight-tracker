<?php

# must be run on server with access to its database

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\NameMatcher;

require_once(dirname(__FILE__)."/../small_base.php");

function updateVFRS($token, $server, $pid, $records) {
	$completes = Download::oneField($token, $server, "pre_screening_survey_complete");
	foreach ($records as $recordId) {
	    if ($completes[$recordId] != "2") {
	        updateVFRSForRecord($token, $server, $pid, $recordId);
		}
	}
	CareerDev::saveCurrentDate("Last VFRS Update", $pid);
}


# one name has ???; eliminate
# trims trailing initial.
function fixForMatchVFRS($n) {
	$n = preg_replace("/\s+\w\.$/", "", $n);
	$n = preg_replace("/\s+\w$/", "", $n);
	$n = str_replace("???", "", $n);
	$n = strtolower($n);
	return $n;
}

# returns true/false over whether the names "match"
function matchNamesForVFRS($fn1, $ln1, $fn2, $ln2) {
	if ($fn1 && $ln1 && $fn2 && $ln2) {
		$fn1 = fixForMatchVFRS($fn1);
		$fn2 = fixForMatchVFRS($fn2);
		$ln1 = fixForMatchVFRS($ln1);
		$ln2 = fixForMatchVFRS($ln2);
		if (NameMatcher::matchName($fn1, $ln1, $fn2, $ln2)) {
			return true;
		}
	}
	return false;
}

# returns true/false over whether this is a pair to skip
function notSkipVFRS($fn1, $ln1, $fn2, $ln2) {
	if (($ln1 == "ho") && ($fn1 == "richard") && ($ln2 == "holden") && ($fn2 == "richard")) {
		return false;
	}
	if (($ln2 == "ho") && ($fn2 == "richard") && ($ln1 == "holden") && ($fn1 == "richard")) {
		return false;
	}
	return true;
}

# returns array with match indices in $rows
function matchRowsVFRS($prefix, $rows, $newmanRow) {
	$firstName1 = $newmanRow['first_name'];
	$lastName1 = $newmanRow['last_name'];
	$match_is = array();
	if ($firstName1 && $lastName1) {
		$i = 0;
		foreach ($rows as $row) {
			$firstName2 = "";
			$lastName2 = "";
			if ($prefix == "coeus") {
				$coeusName2 = getCoeusName($row['person_name']);
				$firstName2 = fixToCompareVFRS($coeusName2[1]);
				$lastName2 = fixToCompareVFRS($coeusName2[0]);
			} else {
				$firstName2 = fixToCompareVFRS($row['first_name']);
				$lastName2 = fixToCompareVFRS($row['last_name']);
			}
			if (notSkipVFRS($firstName1, $lastName1, $firstName2, $lastName2)) {
				if (matchNamesForVFRS($firstName1, $lastName1, $firstName2, $lastName2)) {
					$match_is[] = $i;
				}
			}
			$i++;
		}
	}
	return $match_is;
}

# combines two rows
# row overwrites row2 in case of direct conflict
function combineRowsVFRS($row, $row2) {
	$combined = array();
	$rowData = json_decode($row['DATA'], true);
	$row2Data = json_decode($row2['DATA'], true);
	$combinedData = array();

	$fields = array("record_id", "redcap_repeat_instrument", "redcap_repeat_instance");
	foreach ($fields as $field) {
		if (isset($rowData[$field])) {
			$combinedData[$field] = $rowData[$field];
		} else if (isset($row2Data[$field])) {
			$combinedData[$field] = $row2Data[$field];
		} else {
			$combinedData[$field] = "";
		}
	}
	# row overwrites row2
	if ($row2Data) {
		foreach ($row2Data as $field => $value) {
			if (!in_array($field, $fields)) {
				$combinedData[$field] = $value;
			}
		}
	} else {
		Application::log("Error decoding row2");
	}
	if ($rowData) {
		foreach ($rowData as $field => $value) {
			if (!in_array($field, $fields)) {
				$combinedData[$field] = $value;
			}
		}
	} else {
		Application::log("Error decoding row");
	}

	$combined['prefix'] = array();
	$rowList = array();
	if ($row) {
		$rowList[] = $row;
	}
	if ($row2) {
		$rowList[] = $row2;
	}
	foreach ($rowList as $myRowData) {
		if (is_array($myRowData['prefix'])) {
			foreach ($myRowData['prefix'] as $prefix) {
				if (!in_array($prefix, $combined['prefix'])) {
					$combined['prefix'][] = $prefix;
				}
			}
		} else {
			if (!in_array($myRowData['prefix'], $combined['prefix'])) {
				$combined['prefix'][] = $myRowData['prefix'];
			}
		}
	}
	$combined['first_name'] = $row['first_name'];
	$combined['last_name'] = $row['last_name'];
	$combined['DATA'] = json_encode($combinedData);

	return $combined;
}

# we adjusted the indices to not be 0-based but 1-based. So we have to add 1 to each value
function adjustForVFRS($redcapRow) {
	$fieldsToAdjust = array("vfrs_graduate_degree", "vfrs_degree2", "vfrs_degree3", "vfrs_degree4", "vfrs_degree5");
	foreach ($redcapRow as $field => $value) {
		if (in_array($field, $fieldsToAdjust) && ($value !== '')) {
			$redcapRow[$field] = $value + 1;
		}
	}
	return $redcapRow;
}

function updateVFRSForRecord($token, $server, $pid, $record) {
	$redcapData = Download::fieldsForRecords($token, $server, array("record_id", "identifier_first_name", "identifier_last_name"), array($record));

	# the names list keeps track of the names for each prefix
	# will eventually be used to combine the data
	$prefix = "summary";
	$names[$prefix] = array();
	foreach ($redcapData as $row) {
		$names[$prefix][] = array("prefix" => $prefix, "record_id" => $row['record_id'], "first_name" => $row['identifier_first_name'], "last_name" => $row['identifier_last_name'], "DATA" => json_encode($row));
	}

	# Token for VFRS
	$vfrs_token = CareerDev::getVFRSToken();
	$metadata = Download::metadata($token, $server);
	$allFields = REDCapManagement::getFieldsFromMetadata($metadata);

	$vfrsData = Download::recordIds($vfrs_token, 'https://redcap.vanderbilt.edu/api/', "participant_id");

	$prefix = "vfrs";
	$names[$prefix] = array();
	$skipRegexes = array("/^ecommons$/", "/^c___/", "/^m___/", "/_complete$/");
	foreach ($vfrsData as $row) {
		$id = $row['participant_id'];
		$firstName = $row['name_first'];
		$lastName = $row['name_last'];
		$row2 = array();
		foreach ($row as $field => $value) {
			$matched = FALSE;
			foreach ($skipRegexes as $regex) {
				if (preg_match($regex, $field)) {
					$matched = TRUE;
					break;
				}
			}
			$newField = strtolower("vfrs_".$field);
			if (!$matched && in_array($newField, $allFields)) {
				$row2[$newField] = $value;
			}
		}
		if ($id && $firstName && $lastName) {
			$names[$prefix][] = array("prefix" => $prefix, "participant_id" => $id, "first_name" => $firstName, "last_name" => $lastName, "DATA" => json_encode($row2));
		}
	}
	unset($vfrsData);

	# match on name all of the disparate data sources
	# matchNamesForVFRS() function is central here
	# must be in Newman Data as this serves as the basis
	$skip = array("vfrs", "coeus");
	$combined = array();
	$numRows = 0;
	$sentNames = array();
	$namesToSort = array();
	$queue = array();
	foreach ($names as $prefix => $rows) {
		if (!in_array($prefix, $skip)) {
			$i = 0;
			foreach ($rows as $newmanRow) {
				$upload = array();
				$uploadTypes = array();
				$row = $newmanRow;
				$proceed = true;
				$firstName1 = $row['first_name'];
				$lastName1 = $row['last_name'];
				foreach ($sentNames as $namePair) {
					$firstName2 = $namePair['first_name'];
					$lastName2 = $namePair['last_name'];
					if (matchNamesForVFRS($firstName1, $lastName1, $firstName2, $lastName2)) {
						$proceed = false;
						break;
					}
				}
				if (!$proceed) {
					Application::log("$prefix DUPLICATE at $firstName1 $firstName2 $lastName1 $lastName2", $pid);
				} else if (skip($firstName1, $lastName1)) {
					Application::log("$prefix SKIP at $firstName1 $lastName1", $pid);
				} else {
					Application::log("$prefix MATCH at $firstName1 $lastName1", $pid);
					foreach ($names as $prefix2 => $rows2) {
						if ($prefix2 != "coeus") {
							$match2_is = matchRowsVFRS($prefix2, $rows2, $newmanRow);
							foreach ($match2_is as $match2_i) {
								$row = combineRowsVFRS($row, $rows2[$match2_i]);
								foreach ($row['prefix'] as $prefix3) {
									if (!in_array($prefix3, $uploadTypes)) {
										$uploadTypes[] = $prefix3;
									}
								}
							}
						}
					}
					if (isset($row['DATA'])) {
						$rowData = json_decode($row['DATA'], true);
						$rowData['redcap_repeat_instrument'] = "";
						$rowData['redcap_repeat_instance'] = "";
						$rowData = adjustForVFRS($rowData);
						$rowData = REDCapManagement::filterOutVariable("m", $rowData);
						$rowData = REDCapManagement::filterOutVariable("c", $rowData);
						$rowData["pre_screening_survey_complete"] = "2";
						array_unshift($upload, $rowData);
						$numRows += count($upload);
	
						foreach ($upload as $uploadRow) {
							$queue[] = $uploadRow;
						}
					}
					if (count($upload) > 0) {
						$uploadNames = array( "first_name" => fixToCompareVFRS($upload[0]['identifier_first_name']), "last_name" => fixToCompareVFRS($upload[0]['identifier_last_name']) );
						$sentNames[] = $uploadNames;
					}
				}
			}
		}
	}
	
	Application::log("queue: ".count($queue), $pid);
	# send any leftover data in one last upload
	if (count($queue) > 0) {
		$feedback = Upload::rows($queue, $token, $server);
		Application::log("Upload ".count($queue)." rows ".json_encode($feedback), $pid);
        uploadPositionChangesFromVFRS($token, $server, $record);
	}
	Application::log("$numRows rows uploaded into Record $record", $pid);
}

function uploadPositionChangesFromVFRS($token, $server, $recordId) {
    $metadata = Download::metadata($token, $server);
    $choices = REDCapManagement::getChoices($metadata);
    $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
    $positionFields = [];
    $vfrsFields = [];
    foreach ($metadataFields as $field) {
        if (preg_match("/^promotion_/", $field) || ($field == "position_change_complete")) {
            $positionFields[] = $field;
        } else if (preg_match("/^vfrs_/", $field) || ($field == "pre_screening_survey_complete")) {
            $vfrsFields[] = $field;
        }
    }
    if (empty($positionFields) || empty($vfrsData)) {
        return;
    }
    $positionData = Download::fieldsForRecords($token, $server, $positionFields, [$recordId]);
    $vfrsData = Download::fieldsForRecords($token, $server, $vfrsFields, [$recordId]);

    $transferData = [];
    $row = $vfrsData[0];
    $transferData['promotion_job_title'] = $choices['vfrs_current_appointment'][$row['vfrs_current_appointment']];
    $transferData['promotion_job_category'] = '1';
    $transferData['promotion_institution'] = "Vanderbilt University Medical Center";
    $transferData['promotion_division'] = $row['vfrs_division'];
    $transferData['promotion_date'] = date("Y-m-d");
    $appointment = $row['vfrs_when_did_this_appointment'];
    $appointment = preg_replace("/[^\d^\/^\-]/", "", $appointment);   // data clean
    $transferData['promotion_in_effect'] = REDCapManagement::MY2YMD($appointment);
    $transferData['position_change_complete'] = "2";

    $maxInstance = REDCapManagement::getMaxInstance($positionData, "position_change", $recordId);
    if (!isDataAlreadyCopied($positionData, $transferData)) {
        $transferData['record_id'] = $recordId;
        $transferData['redcap_repeat_instrument'] = "position_change";
        $transferData['redcap_repeat_instance'] = $maxInstance + 1;
        Upload::oneRow($transferData, $token, $server);
    }
}

function fixToCompareVFRS($n) {
    $n = str_replace("/", "\/", $n);
    $n = str_replace("???", "", $n);
    $n = stripQuotesVFRS($n);
    $n = strtolower($n);
    return $n;
}

# strip double quotes
function stripQuotesVFRS($v) {
    $v = preg_replace("/^\"/", "", $v);
    $v = preg_replace("/\"$/", "", $v);
    return $v;
}

