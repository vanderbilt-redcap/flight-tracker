<?php

# must be run on server with access to its database

namespace Vanderbilt\CareerDevLibrary;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../small_base.php");

function updateVFRS($token, $server, $pid, $records) {
    $completes = Download::oneField($token, $server, "pre_screening_survey_complete");
    $participantIds = Download::oneField($token, $server, "vfrs_participant_id");

    # Token for VFRS
    $vfrsToken = CareerDev::getVFRSToken();
    $vfrsData = Download::fields($vfrsToken, $server, ["participant_id", "name_first", "name_last"]);

    foreach ($records as $recordId) {
	    if (($completes[$recordId] != "2") || ($participantIds[$recordId] === "")) {
	        updateVFRSForRecord($token, $server, $pid, $recordId, $vfrsData);
		}
	}
	Application::saveCurrentDate("Last VFRS Update", $pid);
}

function updateVFRSMulti($pids) {
    Application::log("updateVFRSMulti with ".count($pids)." pids");
    $vfrs_token = CareerDev::getVFRSToken();
    $vfrsData = Download::fields($vfrs_token, 'https://redcap.vanderbilt.edu/api/', ["participant_id", "name_first", "name_last"]);

    foreach ($pids as $currPid) {
        $currToken = Application::getSetting("token", $currPid);
        $currServer = Application::getSetting("server", $currPid);
        if (REDCapManagement::isActiveProject($currPid) && $currToken && $currServer) {
            Application::setPid($currPid);
            $forms = Download::metadataForms($currToken, $currServer);
            if (in_array("pre_screening_survey", $forms)) {
                $records = Download::records($currToken, $currServer);
                $completes = Download::oneField($currToken, $currServer, "pre_screening_survey_complete");
                foreach ($records as $recordId) {
                    if ($completes[$recordId] != "2") {
                        Application::log("updateVFRSMulti calling updateVFRSForRecord for Record $recordId", $currPid);
                        updateVFRSForRecord($currToken, $currServer, $currPid, $recordId, $vfrsData);
                    }
                }
                Application::saveCurrentDate("Last VFRS Update", $currPid);
            }
        }
    }
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
	$rowData = $row['DATA'];
	$row2Data = $row2['DATA'];
	$combinedData = array();

	$headerFields = array("record_id", "redcap_repeat_instrument", "redcap_repeat_instance");
	foreach ($headerFields as $field) {
		if (isset($rowData[$field]) && $rowData[$field]) {
			$combinedData[$field] = $rowData[$field];
		} else if (isset($row2Data[$field]) && $row2Data[$field]) {
			$combinedData[$field] = $row2Data[$field];
		} else {
			$combinedData[$field] = "";
		}
	}
	# row overwrites row2
	if ($row2Data) {
		foreach ($row2Data as $field => $value) {
			if (!in_array($field, $headerFields) && ($value !== "")) {
				$combinedData[$field] = $value;
			}
		}
	} else {
		Application::log("Error decoding row2");
	}
	if ($rowData) {
		foreach ($rowData as $field => $value) {
			if (!in_array($field, $headerFields) && ($value !== "")) {
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
	$combined['DATA'] = $combinedData;

	return $combined;
}

# we adjusted the indices to not be 0-based but 1-based. So we have to add 1 to each value
function adjustForVFRS($redcapRow) {
    $doctoralFields = ["vfrs_graduate_degree", "vfrs_degree2",];
    $mastersFields = ["vfrs_degree3", "vfrs_degree4", "vfrs_degree5",];
	$fieldsToAdjust = array_merge($mastersFields, $doctoralFields);
	foreach ($redcapRow as $field => $value) {
		if (in_array($field, $fieldsToAdjust) && ($value !== '')) {
            $value = $value + 1;
            if (in_array($value, [1, 2, 6]) && in_array($field, $doctoralFields)) {
                $redcapRow[$field] = $value;
            } else if (in_array($value, [3, 4, 5]) && in_array($field, $mastersFields)) {
                $redcapRow[$field] = $value;
            }
		}
	}
	return $redcapRow;
}

function updateVFRSForRecord($token, $server, $pid, $record, $vfrsData) {
	$redcapData = Download::fieldsForRecords($token, $server, array("record_id", "identifier_first_name", "identifier_last_name"), array($record));
    $vfrsToken = CareerDev::getVFRSToken();

	# the names list keeps track of the names for each prefix
	# will eventually be used to combine the data
	$prefix = "summary";
	$names[$prefix] = array();
	foreach ($redcapData as $row) {
        $vfrsRecordData = Download::formForRecords($token, $server, "pre_screening_survey", [$record]);
        $names[$prefix][] = array("prefix" => $prefix, "record_id" => $row['record_id'], "first_name" => $row['identifier_first_name'], "last_name" => $row['identifier_last_name'], "DATA" => empty($vfrsRecordData) ? [] : $vfrsRecordData[0]);
	}

	$metadata = Download::metadata($token, $server);
	$allFields = REDCapManagement::getFieldsFromMetadata($metadata);

	$prefix = "vfrs";
	$names[$prefix] = array();
	foreach ($vfrsData as $row) {
		$id = $row['participant_id'];
		$firstName = $row['name_first'];
		$lastName = $row['name_last'];
		if ($id && $firstName && $lastName) {
			$names[$prefix][] = array("prefix" => $prefix, "participant_id" => $id, "first_name" => $firstName, "last_name" => $lastName);
		}
	}

	# match on name all the disparate data sources
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
			foreach ($rows as $row) {
				$upload = array();
				$uploadTypes = array();
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
				} else if (skipVFRS($firstName1, $lastName1)) {
					Application::log("$prefix SKIP at $firstName1 $lastName1", $pid);
				} else if (isset($row['DATA'])) {
					Application::log("$prefix MATCH at $firstName1 $lastName1", $pid);
					foreach ($names as $prefix2 => $rows2) {
						if (!in_array($prefix2, ["coeus", "summary"])) {
							$match2_is = matchRowsVFRS($prefix2, $rows2, $row);
                            rsort($match2_is);
                            Application::log("match2_is for Record $record: ".implode(", ", $match2_is));
							foreach ($match2_is as $match2_i) {
                                if (isset($rows2[$match2_i]['participant_id'])) {
                                    $vfrsRecordData2 = Download::records($vfrsToken, $server, [$rows2[$match2_i]['participant_id']]);
                                    $rows2[$match2_i]['DATA'] = translateVFRSData(empty($vfrsRecordData2) ? [] : $vfrsRecordData2[0]);
                                    $row = combineRowsVFRS($row, $rows2[$match2_i]);
                                    foreach ($row['prefix'] as $prefix3) {
                                        if (!in_array($prefix3, $uploadTypes)) {
                                            $uploadTypes[] = $prefix3;
                                        }
                                    }
                                }
							}
						}
					}
					if (isset($row['DATA'])) {
                        $rowData = $row['DATA'];
                        $rowData['record_id'] = $record;
                        $rowData['redcap_repeat_instrument'] = "";
                        $rowData['redcap_repeat_instance'] = "";
						$rowData = adjustForVFRS($rowData);
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

function translateVFRSData($vfrsRow) {
    $newRow = [];
    foreach ($vfrsRow as $field => $value) {
        if (
            !preg_match("/^m___/", $field)
            && !preg_match("/^c___/", $field)
            && ($field != "please_specify7")
            && !preg_match("/_complete$/", $field)
        ) {
            if (preg_match("/^research_type___/", $field)) {
                $index = str_replace("research_type___", "", $field);
                if ($index == "basic") {
                    $newIndex = 1;
                } else if ($index == "clinical") {
                    $newIndex = 2;
                } else if ($index == "translational") {
                    $newIndex = 3;
                } else {
                    throw new \Exception("Invalid research_type index $index");
                }
                $newRow["vfrs_research_type___$newIndex"] = $value;
            } else {
                $newRow["vfrs_".$field] = $value;
            }
        }
    }
    return $newRow;
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

# skip two people
function skipVFRS($fn, $ln) {
    $fn = strtolower($fn);
    $ln = strtolower($ln);
    if (($fn == "hal") && ($ln == "moses")) {
        return true;
    } else if (($fn == "alex") && ($ln == "patrick???")) {
        return true;
    }
    return false;
}

