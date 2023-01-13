<?php

namespace Vanderbilt\CareerDevLibrary;

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\FederalRePORTER;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

function getNewInstanceForRecord($oldReporters, $recordId) {
	$max = 0;
	foreach ($oldReporters as $row) {
		if (($recordId == $row['record_id']) && ($row['redcap_repeat_instrument'] == "reporter")) {
			if ($row['redcap_repeat_instance'] > $max) {
				$max = $row['redcap_repeat_instance'];
			}
		}
	}
	return $max + 1;
}

function getReporterCount($oldReporters, $recordId) {
	$cnt = 0;
	foreach ($oldReporters as $row) {
		if (($recordId == $row['record_id']) && ($row['redcap_repeat_instrument'] == "reporter")) {
			$cnt++;
		}
	}
	return $cnt;
}

function isNewItem($oldReporters, $item, $recordId) {
	foreach ($oldReporters as $row) {
		if (isset($item['projectNumber'])) {
			if (($recordId == $row['record_id']) && ($item['projectNumber'] == $row['reporter_projectnumber']) && ($item['fy'] == $row['reporter_fy'])) {
				CareerDev::log("$recordId skipped entry because match on {$item['projectNumber']}, {$item['fy']}");
				return false;
			}
		}
	}
	return true;
}

function updateReporterOld($token, $server, $pid, $recordIds) {
	# clear out old data
    if (isset($_GET['test'])) {
        CareerDev::log("Clearing out old data");
    }
	$oldReporters = array();
	$redcapRows = array();
	$uploadRows = array();
	foreach($recordIds as $recordId) {
		$redcapData = Download::fieldsForRecords($token, $server, CareerDev::$reporterFields, array($recordId));
		foreach ($redcapData as $row) {
			if ($row['redcap_repeat_instrument'] == "reporter") {
				$oldReporters[] = $row;
			}
		}
		foreach ($redcapData as $row) {
			if ($row['redcap_repeat_instrument'] == "reporter") {
				if (!isset($redcapRow[$row['record_id']])) {
					$redcapRows[$row['record_id']] = array();
				}
				if ($row['reporter_projectnumber']) {
					$redcapRow[$row['record_id']][] = $row['reporter_projectnumber'];
				}
			}
		}
	}

	unset($redcapData);

	### DOWNLOAD PROCESS

	# download names
	$fields = array("record_id", "identifier_last_name", "identifier_middle", "identifier_first_name", "identifier_institution");
	$redcapData = Download::fieldsForRecords($token, $server, $fields, $recordIds);

	$maxTries = 2;
	$includedFields = array();
	foreach ($redcapData as $row) {
		# for each REDCap Record, download data for each person
		# search for PI of last_name and at Vanderbilt
        $recordId = $row['record_id'];
		$firstName = $row['identifier_first_name'];
		$lastName = $row['identifier_last_name'];
		$middleName = $row['identifier_middle'];
        $firstNames = NameMatcher::explodeFirstName($firstName, $middleName);
        $lastNames = NameMatcher::explodeLastName($lastName);
		$listOfNames = array();
		foreach ($lastNames as $ln) {
			foreach ($firstNames as $fn) {
				if ($ln && $fn) {
				    if (!NameMatcher::isShortName($fn)) {
				        $fn = "*".$fn."*";
                    }
					$listOfNames[] = strtoupper($fn." ".$ln);
				}
			}
		}
		$lastName = NameMatcher::removeParentheses($lastName);
		if ($firstName && $lastName && !in_array(strtoupper($firstName." ".$lastName), $listOfNames)) {
			$listOfNames[] = strtoupper($firstName." ".$lastName);
		}

        $helperInstitutions = Application::getHelperInstitutions();
		$institutions = array();
		$allInstitutions = array_unique(array_merge(CareerDev::getInstitutions(), $helperInstitutions));
		if ($row['identifier_institution']) {
			$institutions = Scholar::explodeInstitutions($row['identifier_institution']);
		}
		foreach ($allInstitutions as $inst) {
			$inst = strtolower($inst);
			if ($inst && !in_array($inst, $institutions)) {
				array_push($institutions, $inst);
			}
		}
		if (isset($_GET['test'])) {
            Application::log("Institutions: ".REDCapManagement::json_encode_with_spaces($institutions));
        }

        $included = [];
		foreach ($listOfNames as $myName) {
		    $included = array_merge($included, FederalRePORTER::searchPI($myName, $pid, $recordId, $institutions));
		}

		# format $included into REDCap infinitely repeating structures
		$upload = array();
		$notUpload = array();

		$instance = getNewInstanceForRecord($oldReporters, $row['record_id']);
		foreach ($included as $item) {
			$uploadRow = array();
			if (isNewItem($oldReporters, $item, $row['record_id'])) {
				foreach ($item as $field => $value) {
					$newField = "reporter_".strtolower($field);
					if (in_array($newField, CareerDev::$reporterFields)) {
						if (!isset($includedFields[$newField])) {
							$includedFields[$newField] = $field;
						} 
						if (preg_match("/startdate/", $newField) || preg_match("/enddate/", $newField)) {
							$value = \Vanderbilt\FlightTrackerExternalModule\getReporterDate($value);
						}
						$uploadRow[$newField] = $value;
					}
				}
				$uploadRow['reporter_last_update'] = date("Y-m-d");
				if (!empty($uploadRow)) {
					$uploadRow['record_id'] = $row['record_id'];
					$uploadRow['redcap_repeat_instrument'] = "reporter";
					$uploadRow['redcap_repeat_instance'] = "$instance";
					$uploadRow['reporter_complete'] = '2';
					$upload[] = $uploadRow;
					$instance++;
				}
			} else {
				$notUpload[] = $item;
			}
		}
		if (isset($_GET['test'])) {
            CareerDev::log($row['record_id']." ".count($upload)." rows to upload; skipped ".count($notUpload)." rows from original of ".getReporterCount($oldReporters, $row['record_id']));
        }

		foreach ($upload as $uploadRow) {
			if (!isset($uploadRows[$uploadRow['record_id']])) {
				$uploadRows[$uploadRow['record_id']] = array();
			}
			$uploadRows[$uploadRow['record_id']][$uploadRow['reporter_projectnumber']] = $uploadRow;
		}
	
		# upload to REDCap
		if (!empty($upload)) {
			$feedback = Upload::rows($upload, $token, $server);
			$output = json_encode($feedback);
			if (isset($_GET['test'])) {
                CareerDev::log("Upload $firstName $lastName ({$row['record_id']}): ".$output);
            }
		}
	}

	$totalReporterEntriesUploaded = 0;
	$totalRecordsAffected = count($uploadRows);
	foreach ($uploadRows as $record => $rows) {
		$totalReporterEntriesUploaded += count($rows);
	}

    REDCapManagement::deduplicateByKey($token, $server, $pid, $recordIds, "reporter_nihapplid", "reporter", "reporter");

    CareerDev::saveCurrentDate("Last Federal RePORTER Download", $pid);
}
