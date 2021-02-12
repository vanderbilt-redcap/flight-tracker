<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");
require_once(dirname(__FILE__)."/../classes/NameMatcher.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");

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

function updateReporter($token, $server, $pid, $recordIds) {
	# clear out old data
	CareerDev::log("Clearing out old data");
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
		$firstName = $row['identifier_first_name'];
		$lastName = $row['identifier_last_name'];
        $firstNames = NameMatcher::explodeFirstName($row['identifier_first_name']);
        $lastNames = NameMatcher::explodeLastName($row['identifier_last_name']);
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
		if ($firstName && $lastName && !in_array($firstName." ".$lastName, $listOfNames)) {
			$listOfNames[] = strtoupper($firstName." ".$lastName);
		}

        $helperInstitutions = Application::getHelperInstitutions();
		$institutions = array();
		$allInstitutions = array_unique(array_merge(CareerDev::getInstitutions(), $helperInstitutions));
		if ($row['identifier_institution']) {
			$institutions = preg_split('/\s*,\s*/', strtolower($row['identifier_institution']));
		}
		foreach ($allInstitutions as $inst) {
			$inst = strtolower($inst);
			if ($inst && !in_array($inst, $institutions)) {
				array_push($institutions, $inst);
			}
		}
        $institutions = REDCapManagement::explodeInstitutions($institutions);
		Application::log("Institutions: ".REDCapManagement::json_encode_with_spaces($institutions));

        $included = array();
		foreach ($listOfNames as $myName) {
			$query = "/v1/projects/search?query=PiName:".urlencode($myName);
			$currData = array();
			$try = 0;
			$max = 0;   // reset with every new name
			do {
				if (isset($myData) && isset($myData['offset']) && $myData['offset'] == 0) {
					$try++;
				} else {
					$try = 0;
				}
				$url = "https://api.federalreporter.nih.gov".$query."&offset=".($max + 1);
                list($resp, $output) = REDCapManagement::downloadURL($url);
                $myData = json_decode($output, true);
				if ($myData && $myData['items']) {
						foreach ($myData['items'] as $item) {
						$currData[] = $item;
					}
					$max = $myData['offset'] + $myData['limit'] - 1;
						// echo "Checking {$myData['totalCount']} (".count($myData['items'])." here) and ".($myData['offset'] - 1 + $myData['limit'])."\n";
					usleep(400000);     // up to 3 per second
				} else {
					$myData = array("totalCount" => 0, "limit" => 0, "offset" => 0);
				}
				CareerDev::log($myName." (".$lastName.") $try: Checking {$myData['totalCount']} and {$myData['offset']} and {$myData['limit']}");
			} while (($myData['totalCount'] > $myData['limit'] + $myData['offset']) || (($myData['offset'] == 0) && ($try < $maxTries)));
			CareerDev::log("{$row['record_id']}: $lastName currData ".count($currData));

			# dissect current data; must have first name to include
			$pis = array();
			if ($currData) {
				foreach ($currData as $item) {
					$itemName = $item['contactPi'];
					if (!in_array($itemName, $pis)) {
						$pis[] = $itemName;
					}
					if ($item['otherPis']) {
						$otherPis = preg_split("/\s*;\s*/", $item['otherPis']);
						foreach ($otherPis as $otherPi) {
							$otherPi = trim($otherPi);
							if ($otherPi && !in_array($otherPi, $pis)) {
								$pis[] = $otherPi;
							}
						}
					}
					$found = false;
					foreach ($pis as $itemName) {
						$itemNames = preg_split("/\s*,\s*/", $itemName);
						// $itemLastName = $itemNames[0];
						if (count($itemNames) > 1) {
							$itemFirstName = $itemNames[1];
						} else {
							$itemFirstName = $itemNames[0];
						}
						$listOfFirstNames = preg_split("/\s/", strtoupper($firstName));
						foreach ($institutions as $institution) {
							foreach ($listOfFirstNames as $myFirstName) {
								$myFirstName = preg_replace("/^\(/", "", $myFirstName);
								$myFirstName = preg_replace("/\)$/", "", $myFirstName);
								if (preg_match("/".strtoupper($myFirstName)."/", $itemFirstName) && (preg_match("/$institution/i", $item['orgName']))) {
									Application::log("Possible match $itemFirstName and $institution vs. '{$item['orgName']}'");
								    if (in_array($institution, $helperInstitutions)) {
								        Application::log("Helper institution ($institution)?");
									    $proceed = FALSE;
                                        Application::log("Helper institution inspecting cities: ".json_encode(CareerDev::getCities())." vs. '".$item['orgCity']."'");
									    foreach (CareerDev::getCities() as $city) {
                                            if (preg_match("/".$city."/i", $item['orgCity'])) {
                                                Application::log("Matched city");
                                                $proceed = TRUE;
                                            }
                                        }
                                    } else {
									    $proceed = TRUE;
									    if (CareerDev::isVanderbilt() && ((strtoupper($myFirstName) != "HAROLD") && (strtoupper($lastName) == "MOSES") && preg_match("/HAROLD L/i", $myFirstName))) {
                                            # Hack: exclude HAROLD L MOSES since HAROLD MOSES JR is valid
									        $proceed = FALSE;
                                        }
                                    }
								    if ($proceed) {
										CareerDev::log("Including $itemFirstName {$item['orgName']}");
										$included[] = $item;
										$found = true;
										break;
									}
								} else {
									// echo "Not including $itemFirstName {$item['orgName']}\n";
								}
							}
							if ($found) {
								break;
							}
						}
						if ($found) {
							break;
						}
					}
				}
			}
			CareerDev::log("{$row['record_id']}: $firstName $lastName included ".count($included));
			// echo "itemNames: ".json_encode($pis)."\n";
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
		CareerDev::log($row['record_id']." ".count($upload)." rows to upload; skipped ".count($notUpload)." rows from original of ".getReporterCount($oldReporters, $row['record_id']));

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
			CareerDev::log("Upload $firstName $lastName ({$row['record_id']}): ".$output);
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
