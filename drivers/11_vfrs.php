<?php

# must be run on server with access to its database

namespace Vanderbilt\CareerDevLibrary;

use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../small_base.php");

function updateVFRS(string $token, string $server, int $pid, array $records): void {
	# Token for VFRS
	$vfrsToken = CareerDev::getVFRSToken();
	$vfrsNameData = Download::fields($vfrsToken, $server, ["participant_id", "name_first", "name_last"]);
	$vfrsMetadataFields = array_merge(["record_id"], Download::metadataFieldsByPidWithPrefix($pid, "vfrs_"));
	$vfrsMetadata = Download::metadataByPid($pid, $vfrsMetadataFields);
	foreach ($records as $recordId) {
		updateVFRSForRecord($token, $server, $pid, $recordId, $vfrsNameData, $vfrsMetadata);
	}
	Application::saveCurrentDate("Last VFRS Update", $pid);
}

function updateVFRSMulti(array $pids): void {
	$vfrs_token = CareerDev::getVFRSToken();
	$vfrsNameData = Download::fields($vfrs_token, 'https://redcap.vumc.org/api/', ["participant_id", "name_first", "name_last"]);
	foreach ($pids as $currPid) {
		$currToken = Application::getSetting("token", $currPid);
		$currServer = Application::getSetting("server", $currPid);
		$vfrsMetadataFields = array_merge(["record_id"], Download::metadataFieldsByPidWithPrefix($currPid, "vfrs_"));
		$vfrsMetadata = Download::metadataByPid($currPid, $vfrsMetadataFields);
		if (REDCapManagement::isActiveProject($currPid) && $currToken && $currServer) {
			$forms = Download::metadataFormsByPid($currPid);
			if (in_array("pre_screening_survey", $forms)) {
				$records = Download::recordIdsByPid($currPid);
				foreach ($records as $recordId) {
					updateVFRSForRecord($currToken, $currServer, $currPid, $recordId, $vfrsNameData, $vfrsMetadata);
				}
				Application::saveCurrentDate("Last VFRS Update", $currPid);
			}
		}
	}
}

# one name has ???; eliminate
# trims trailing initial.
function fixAnomaliesForMatchVFRS(string $n): string {
	$n = preg_replace("/\s+\w\.$/", "", $n);
	$n = preg_replace("/\s+\w$/", "", $n);
	$n = str_replace("???", "", $n);
	$n = strtolower($n);
	return $n;
}

# returns true/false over whether the names "match"
function matchNamesForVFRS(string $fn1, string $ln1, string $fn2, string $ln2): bool {
	if ($fn1 && $ln1 && $fn2 && $ln2) {
		$fn1 = fixAnomaliesForMatchVFRS($fn1);
		$fn2 = fixAnomaliesForMatchVFRS($fn2);
		$ln1 = fixAnomaliesForMatchVFRS($ln1);
		$ln2 = fixAnomaliesForMatchVFRS($ln2);
		return NameMatcher::matchName($fn1, $ln1, $fn2, $ln2);
	}
	return false;
}

# returns true/false over whether this is a pair to skip
function cannotSkipForVFRSAnomalies(string $fn1, string $ln1, string $fn2, string $ln2): bool {
	if (($ln1 == "ho") && ($fn1 == "richard") && ($ln2 == "holden") && ($fn2 == "richard")) {
		return false;
	}
	if (($ln2 == "ho") && ($fn2 == "richard") && ($ln1 == "holden") && ($fn1 == "richard")) {
		return false;
	}
	return true;
}

# returns array with match indices in $rows that match the row
function matchRowIndexesVFRS(array $rows, array $newmanRow): array {
	$firstName1 = $newmanRow['first_name'];
	$lastName1 = $newmanRow['last_name'];
	$matchIndexes = [];
	if ($firstName1 && $lastName1) {
		foreach ($rows as $i => $row) {
			$firstName2 = fixSpreadsheetAnomaliesToCompare($row['first_name']);
			$lastName2 = fixSpreadsheetAnomaliesToCompare($row['last_name']);
			if (cannotSkipForVFRSAnomalies($firstName1, $lastName1, $firstName2, $lastName2)) {
				if (matchNamesForVFRS($firstName1, $lastName1, $firstName2, $lastName2)) {
					$matchIndexes[] = $i;
				}
			}
		}
	}
	return $matchIndexes;
}

# combines two rows
# row overwrites row2 in case of direct conflict
function combineVFRSRows(array $row1, array $row2, int $pid): array {
	$combined = [];
	$row1Data = $row1['DATA'];
	$row2Data = $row2['DATA'];
	$combinedData = [];

	$headerFields = ["record_id", "redcap_repeat_instrument", "redcap_repeat_instance"];
	foreach ($headerFields as $field) {
		if (isset($row1Data[$field]) && $row1Data[$field]) {
			$combinedData[$field] = $row1Data[$field];
		} elseif (isset($row2Data[$field]) && $row2Data[$field]) {
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
		Application::log("Error decoding row2", $pid);
	}
	if ($row1Data) {
		foreach ($row1Data as $field => $value) {
			if (!in_array($field, $headerFields) && ($value !== "")) {
				$combinedData[$field] = $value;
			}
		}
	} else {
		Application::log("Error decoding row", $pid);
	}

	$combined['prefix'] = [];
	$rowList = [];
	if ($row1) {
		$rowList[] = $row1;
	}
	if ($row2) {
		$rowList[] = $row2;
	}
	foreach ($rowList as $myRowData) {
		if (is_array($myRowData['prefix'])) {
			$combined['prefix'] = array_unique(array_merge($combined['prefix'], $myRowData['prefix']));
		} elseif (!in_array($myRowData['prefix'], $combined['prefix'])) {
			$combined['prefix'][] = $myRowData['prefix'];
		}
	}
	$combined['first_name'] = $row1['first_name'];
	$combined['last_name'] = $row1['last_name'];
	$combined['DATA'] = $combinedData;

	return $combined;
}

# In Flight Tracker, we adjusted the indices to not be 0-based but 1-based.
# So we have to add 1 to each value for certain fields.
function adjustDegreesForVFRS(array $redcapRow): array {
	$doctoralFields = ["vfrs_graduate_degree", "vfrs_degree2",];
	$doctoralDegreeIndexes = [1, 2, 6];
	$mastersFields = ["vfrs_degree3", "vfrs_degree4", "vfrs_degree5",];
	$mastersDegreeIndexes = [3, 4, 5];
	$fieldsToAdjust = array_merge($mastersFields, $doctoralFields);
	foreach ($redcapRow as $field => $value) {
		if (in_array($field, $fieldsToAdjust) && ($value !== '')) {
			$value = $value + 1;
			if (
				(in_array($value, $doctoralDegreeIndexes) && in_array($field, $doctoralFields))
				|| (in_array($value, $mastersDegreeIndexes) && in_array($field, $mastersFields))
			) {
				$redcapRow[$field] = $value;
			}
		}
	}
	return $redcapRow;
}

function hasVFRSName(string $first, string $last, array $vfrsNameData): bool {
	foreach ($vfrsNameData as $row) {
		if (
			$row['name_first']
			&& $row['name_last']
			&& matchNamesForVFRS($first, $last, $row['name_first'], $row['name_last'])
		) {
			return true;
		}
	}
	return false;
}

function updateVFRSForRecord(string $token, string $server, int $pid, string $record, array $vfrsNameData, array $vfrsMetadata): void {
	list($first, $middle, $last, $userid) = Download::threeNamePartsAndUserid($token, $server, $record);
	if (!hasVFRSName($first, $last, $vfrsNameData)) {
		return;
	}

	# the names list keeps track of the names for each prefix
	# will eventually be used to combine the data
	$prefix = "summary";
	$names[$prefix] = [];
	$vfrsToken = CareerDev::getVFRSToken();
	$vfrsFields = array_diff(DataDictionaryManagement::getFieldsFromMetadata($vfrsMetadata), DataDictionaryManagement::getFieldsOfType($vfrsMetadata, "descriptive"));
	$names[$prefix][] = [
		"prefix" => $prefix,
		"record_id" => $record,
		"first_name" => $first,
		"last_name" => $last,
		"DATA" => [],
		# Using this value for DATA will not make adjustments with new data
		// "DATA" => REDCapManagement::getNormativeRow(Download::fieldsForRecordsByPid($pid, $vfrsFields, [$record])),
	];

	$prefix = "vfrs";
	$names[$prefix] = [];
	foreach ($vfrsNameData as $row) {
		$id = $row['participant_id'];
		$firstName = $row['name_first'];
		$lastName = $row['name_last'];
		if ($id && $firstName && $lastName) {
			$names[$prefix][] = [
				"prefix" => $prefix,
				"participant_id" => $id,
				"first_name" => $firstName,
				"last_name" => $lastName,
			];
		}
	}

	# match on name all the disparate data sources
	# matchNamesForVFRS() function is central here
	# must be in Newman Data as this serves as the basis
	$skip = ["vfrs"];
	$sentNames = [];
	$queue = [];
	foreach ($names as $prefix => $rowsForPrefix) {
		if (!in_array($prefix, $skip)) {
			foreach ($rowsForPrefix as $row) {
				$upload = [];
				$uploadTypes = [];
				$proceed = true;
				$firstName1 = $row['first_name'];
				$lastName1 = $row['last_name'];
				foreach ($sentNames as $namePair) {
					$firstName2 = $namePair['first_name'];
					$lastName2 = $namePair['last_name'];
					if (matchNamesForVFRS($firstName1, $lastName1, $firstName2, $lastName2)) {
						Application::log("$record: $prefix DUPLICATE at $firstName1 $firstName2 $lastName1 $lastName2", $pid);
						$proceed = false;
						break;
					}
				}
				if ($proceed && skipVFRS($firstName1, $lastName1)) {
					Application::log("$record: $prefix SKIP at $firstName1 $lastName1", $pid);
				} elseif ($proceed && isset($row['DATA'])) {
					Application::log("$record: $prefix MATCH at $firstName1 $lastName1", $pid);
					foreach ($names as $prefix2 => $rows2) {
						if ($prefix2 != "summary") {
							$match2Indices = matchRowIndexesVFRS($rows2, $row);
							if (!empty($match2Indices)) {
								rsort($match2Indices);
								foreach ($match2Indices as $match2Index) {
									if (isset($rows2[$match2Index]['participant_id'])) {
										$vfrsRecordData2 = Download::records($vfrsToken, $server, [$rows2[$match2Index]['participant_id']]);
										$rows2[$match2Index]['DATA'] = translateVFRSDataToFTIndexing(REDCapManagement::getNormativeRow($vfrsRecordData2), $vfrsFields);
										$row = combineVFRSRows($row, $rows2[$match2Index], $pid);
										$uploadTypes = array_unique(array_merge($uploadTypes, $row['prefix']));
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
						$rowData = adjustDegreesForVFRS($rowData);
						$rowData["pre_screening_survey_complete"] = "2";
						# must place normative row first
						array_unshift($upload, $rowData);
					}
					if (count($upload) > 0) {
						$uploadNames = [
							"first_name" => $first,
							"last_name" => $last,
						];
						$sentNames[] = $uploadNames;
						$queue = array_merge($queue, $upload);
					}
				}
			}
		}
	}

	# send any leftover data in one last upload
	if (count($queue) > 0) {
		$feedback = Upload::rowsByPid($queue, $pid);
		Application::log("Upload ".count($queue)." rows ".json_encode($feedback), $pid);
		uploadPositionChangesFromVFRS($pid, $record, $vfrsMetadata);
	}
	Application::log(count($queue)." rows uploaded into Record $record", $pid);
}

function translateVFRSDataToFTIndexing(array $vfrsRow, array $vfrsFields): array {
	$newRow = [];
	foreach ($vfrsRow as $field => $value) {
		# m = methods checkboxes --> skip
		# c = contents checkboxes --> skip
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
				} elseif ($index == "clinical") {
					$newIndex = 2;
				} elseif ($index == "translational") {
					$newIndex = 3;
				} else {
					throw new \Exception("Invalid research_type index $index");
				}
				$newRow["vfrs_research_type___$newIndex"] = $value;
			} else {
				$newField = "vfrs_".$field;
				if (in_array($newField, $vfrsFields) && ($value !== "")) {
					$newRow[$newField] = $value;
				}
			}
		}
	}
	return $newRow;
}

function uploadPositionChangesFromVFRS(int $pid, string $recordId, array $vfrsMetadata): void {
	$choices = DataDictionaryManagement::getChoices($vfrsMetadata);
	$positionFields = Download::metadataFieldsByPidWithPrefix($pid, "promotion_");
	$vfrsFields = DataDictionaryManagement::getFieldsFromMetadata($vfrsMetadata);
	if (empty($positionFields) || empty($vfrsFields)) {
		return;
	}
	$positionFields = array_unique(array_merge(['record_id', 'position_change_complete'], $positionFields));
	$vfrsFields = array_unique(array_merge(['record_id', 'pre_screening_survey_complete'], $vfrsFields));
	$positionData = Download::fieldsForRecordsByPid($pid, $positionFields, [$recordId]);
	$vfrsData = Download::fieldsForRecordsByPid($pid, $vfrsFields, [$recordId]);

	$transferData = [];
	$row = REDCapManagement::getNormativeRow($vfrsData);
	if (empty($row)) {
		return;
	}
	$transferData['promotion_job_title'] = $choices['vfrs_current_appointment'][$row['vfrs_current_appointment']];
	$transferData['promotion_job_category'] = '1';
	$transferData['promotion_institution'] = "Vanderbilt University Medical Center";
	$transferData['promotion_division'] = $row['vfrs_division'];
	$transferData['promotion_date'] = date("Y-m-d");
	if (DateManagement::isDate($row['vfrs_when_did_this_appointment'])) {
		$appointment = preg_replace("/[^\d^\/^\-]/", "", $row['vfrs_when_did_this_appointment']);   // data clean
		$transferData['promotion_in_effect'] = DateManagement::MY2YMD($appointment);
	} else {
		# unknown format => hand to PHP
		$ts = strtotime($row['vfrs_when_did_this_appointment']);
		$transferData['promotion_in_effect'] = date("Y-m-d", $ts);
	}
	$transferData['position_change_complete'] = "2";

	$maxInstance = REDCapManagement::getMaxInstance($positionData, "position_change", $recordId);
	if (!isPositionDataAlreadyCopied($positionData, $transferData)) {
		$transferData['record_id'] = $recordId;
		$transferData['redcap_repeat_instrument'] = "position_change";
		$transferData['redcap_repeat_instance'] = $maxInstance + 1;
		Upload::rowsByPid([$transferData], $pid);
	}
}

function isPositionDataAlreadyCopied(array $positionData, array $transferData): bool {
	$skip = ["promotion_date", "redcap_repeat_instance", "position_change_complete"];
	$hasDataAlready = false;
	foreach ($positionData as $row) {
		$allFieldsPresent = true;
		foreach ($transferData as $field => $value) {
			if (!in_array($field, $skip) && (trim($row[$field]) != trim($value))) {
				$allFieldsPresent = false;
				break;
			}
		}
		if ($allFieldsPresent) {
			$hasDataAlready = true;
			break;
		}
	}
	return $hasDataAlready;
}


function fixSpreadsheetAnomaliesToCompare(string $n): string {
	$n = str_replace("/", "\/", $n);
	$n = str_replace("???", "", $n);
	$n = stripQuotesVFRS($n);
	$n = strtolower($n);
	return $n;
}

# strip double quotes
function stripQuotesVFRS(string $v): string {
	$v = preg_replace("/^\"/", "", $v);
	$v = preg_replace("/\"$/", "", $v);
	return $v;
}

# skip two people
function skipVFRS(string $fn, string $ln): bool {
	$fn = strtolower($fn);
	$ln = strtolower($ln);
	if (($fn == "hal") && ($ln == "moses")) {
		return true;
	} elseif (($fn == "alex") && ($ln == "patrick???")) {
		return true;
	}
	return false;
}
