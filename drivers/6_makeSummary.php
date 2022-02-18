<?php

# used every time that the summaries are recalculated 
# 15-30 minute runtimes

$sendEmail = false;
$screen = true;
$br = "\n";
if (isset($_GET['pid']) || (isset($argv[1]) && ($argv[1] == "prod_cron"))) {
	$br = "<br>";
	$sendEmail = true;
	$screen = false;
}

require_once(dirname(__FILE__)."/../small_base.php");
define("NOAUTH", true);
require_once(dirname(__FILE__)."/../../../redcap_connect.php");
require_once(APP_PATH_DOCROOT.'/ProjectGeneral/math_functions.php');

if ($tokenName == $info['unit']['name']) {
	require_once(dirname(__FILE__)."/setup6.php");
}
$lockFile = APP_PATH_TEMP."6_makeSummary.lock";
$lockStart = time();
if (file_exists($lockFile)) {
	$mssg = "Another instance is already running.";
	\REDCap::email($victrEmail, "no-reply@vanderbilt.edu", "CareerDev makeSummary script die", $mssg);
	die($mssg);
}
$fp = fopen($lockFile, "w");
fwrite($fp, date("Y-m-d h:m:s"));
fclose($fp);

# if this is specified as the 2nd command-line argument, it will only run for one record
$selectRecord = "";
if (isset($argv[2])) {
	$selectRecord = $argv[2];
}
$GLOBALS['selectRecord'] = $selectRecord;

$echoToScreen .= "SERVER: ".$server."$br";
$echoToScreen .= "TOKEN: ".$token."$br";
$echoToScreen .= "PID: ".$pid."$br";
if ($selectRecord) {
	$echoToScreen .= "RECORD: ".$selectRecord."$br";
}
$echoToScreen .= "$br";

if (!isset($_GET['pid'])) {
	if (!isset($argv[1]) || (($pid == 66635) && isset($argv[1]) && ($argv[1] != "prod_override") && ($argv[1] != "prod_cron"))) {
		$a = readline("Are you sure? > ");
		if ($a != "y") {
			unlink($lockFile);
			die();
		}
	}
}

$errors = array();
$_GLOBAL["errors"] = $errors;
$_GLOBAL["echoToScreen"] = $echoToScreen;

# tests the JSON string to see if there are errors
function testOutput($jsonStr) {
	global $errors;

	$data = json_decode($jsonStr, true);
	if ($data && isset($data['count'])) {
		return;
	} else if ($jsonStr) {
		$errors[] = $jsonStr;
	}
}


# uses COEUS, custom grants, modified lists, Newman, and the Scholars' Survey
# produces an array of ordered grants
function getOrderedCDAVariables($data, $record) {
	global $selectRecord;
	global $echoToScreen, $br;

	$debug = false;

	$maxAwards = 15;
	$recordRows = array();
	foreach ($data as $row) {
		if  ($row['record_id'] == $record) {
			$recordRows[] = $row;
		}
	}
	$listOfAwards = array();
	$awardTimestamps = array();
	$normativeRow = array();

	# Strategy: Do not include N/A's. Sort by start timestamp and then look for duplicates

	# listOfAwards contain all the awards
	# awardTimestamps contain the timestamps of the awards
	# changes contain the changes that are made my the modification lists in toImport

	# all awards have important "specifications" or "specs" stored with the grant
	# this facilitates their use by multiple outfits

	# award numbers are also important as our sources and types

	# import modified lists first from the wrangler/index.php interface
	# these trump everything
	# toImport
	$importData = array();
	$changes = array();
	$changes['start_date'] = array();
	$changes['end_date'] = array();
	$changes['type'] = array();
	foreach ($recordRows as $row) {
		if (isset($row['redcap_repeat_instrument']) && $row['redcap_repeat_instrument'] == "") {
			if ($row['summary_calculate_to_import']) {
				$importData = json_decode($row['summary_calculate_to_import'], true);
				foreach ($importData as $index => $ary) {
					$action = $ary[0];
					$award = $ary[1];
					$awardno = $award['sponsor_award_no'];
					$award['source'] = "modify";
					if ($action == "ADD") {
						$listOfAwards[$awardno] = $award;
						if ($award['redcap_type'] != "N/A") {
							if ($debug || $selectRecord) {
								$echoToScreen .= "TS A: $awardno {$award['start_date']} ".json_encode($award)."$br";
							}
							$awardTimestamps[$awardno] = strtotime($award['start_date']); 
							$changes['type'][$awardno] = $award['redcap_type'];
						}
					} else if (preg_match("/CHANGE/", $action)) {
						$changes['type'][$awardno] = $award['redcap_type'];
						$changes['start_date'][$awardno] = $award['start_date'];
						if (isset($award['end_date'])) {
							$changes['end_date'][$awardno] = $award['end_date'];
						}
					}
				}
			}
		}
	}

	# next important is a custom grant
	foreach ($recordRows as $row) {
		if (isset($row['redcap_repeat_instrument']) && $row['redcap_repeat_instrument'] == "custom_grant") {
			$type = "custom";
			$specs = getCustomSpecs($row, $data);
			if (!empty($specs) && markAsSeenAndCheckIfSeen($specs, $record)) {
				if (!$specs['redcap_type']) {
					$awardType = calculateAwardType($specs);
					// $echoToScreen .= "$type: ".$awardType[0]."$br";
					$specs['redcap_type'] = $awardType[0];
				}
				if (!isset($listOfAwards[$specs['sponsor_award_no']])) {
					if (isset($changes['type'][$specs['sponsor_award_no']])) {
						$specs['redcap_type'] = $changes['type'][$specs['sponsor_award_no']];
						$awardType[0] = $specs['redcap_type'];
					}
					if (isset($changes['start_date'][$specs['sponsor_award_no']])) {
						$specs['start_date'] = $changes['start_date'][$specs['sponsor_award_no']];
					}
					if (isset($changes['end_date'][$specs['sponsor_award_no']])) {
						$specs['end_date'] = $changes['end_date'][$specs['sponsor_award_no']];
					}
					$listOfAwards[$specs['sponsor_award_no']] = $specs;
				}
				if ($specs['redcap_type'] != "N/A") {
					$index = $specs['sponsor_award_no']."____".$specs['sponsor_type']."____".$specs['start_date'];
					if (!isset($importData[$index]) || ($importData[$index][0] != "REMOVE")) {
						if ($debug || $selectRecord) {
							$echoToScreen .= "TS B: {$specs['sponsor_award_no']} {$specs['start_date']} ".json_encode($specs)."$br";
						}
						$awardTimestamps[$specs['sponsor_award_no']] = strtotime($specs['start_date']); 
						// $echoToScreen .= "$type {$normativeRow['record_id']} {$specs['person_name']}: ".json_encode($specs)."$br";
					}
				} else {
					// $echoToScreen .= "$type {$normativeRow['record_id']} reject: ".json_encode($specs)."$br";
				}
			} else {
				// $echoToScreen .= "$type Seen: ".json_encode($specs)."$br";
			}
		}
	}

	# next important is COEUS
	foreach ($recordRows as $row) {
		if (isset($row['redcap_repeat_instrument']) && $row['redcap_repeat_instrument'] == "coeus") {
			if (($row['coeus_direct_sponsor_type'] != "Profit") && ($row['coeus_project_start_date'] !== "")) {
				$specs = makeCoeusSpecs($row, $data);
				if (markAsSeenAndCheckIfSeen($specs, $record)) {
					$awardType = calculateAwardType($specs);
					// $echoToScreen .= "1: ".json_encode($awardType)."$br";
					$specs['redcap_type'] = $awardType[0];
					// if (!isset($listOfAwards[$specs['sponsor_award_no']])) {
					if (isset($changes['type'][$specs['sponsor_award_no']])) {
						$specs['redcap_type'] = $changes['type'][$specs['sponsor_award_no']];
						$awardType[0] = $specs['redcap_type'];
					}
					if (isset($changes['start_date'][$specs['sponsor_award_no']])) {
						$specs['start_date'] = $changes['start_date'][$specs['sponsor_award_no']];
					}
					if (isset($changes['end_date'][$specs['sponsor_award_no']])) {
						$specs['end_date'] = $changes['end_date'][$specs['sponsor_award_no']];
					}
					$listOfAwards[$specs['sponsor_award_no']] = $specs;
					// }
					if ($awardType[0] != "N/A") {
						if (!isset($importData[$specs['sponsor_award_no']]) || ($importData[$specs['sponsor_award_no']][-2] != "REMOVE")) {
							if ($debug || $selectRecord) {
								$echoToScreen .= "TS C: {$specs['sponsor_award_no']} {$specs['start_date']} ".json_encode($specs)."$br";
							}
							$awardTimestamps[$specs['sponsor_award_no']] = strtotime($specs['start_date']); 
							// $echoToScreen .= "COEUS {$row['record_id']} {$specs['person_name']}: ".json_encode($specs)."$br";
						}
					} else {
						// $echoToScreen .= "COEUS reject: ".json_encode($awardType)."$br";
					}
				}
			}
		}
	}

	# next important is RePORTER
	foreach ($recordRows as $row) {
		$forms = array("reporter", "exporter");
		if (isset($row['redcap_repeat_instrument']) && $row['redcap_repeat_instrument'] == "reporter") {
			$specs = makeReporterSpecs($row, $data);
			$projectNumber = $row['reporter_projectnumber'];
		} else if (isset($row['redcap_repeat_instrument']) && $row['redcap_repeat_instrument'] == "exporter") {
			$specs = makeExporterSpecs($row, $data);
			$projectNumber = $row['exporter_full_project_num'];
		}
		if (isset($row['redcap_repeat_instrument']) && in_array($row['redcap_repeat_instrument'], $forms)) {
			if ($specs['start_date'] && markAsSeenAndCheckIfSeen($specs, $record)) {
				$awardType = calculateAwardType($specs);
				// $echoToScreen .= "1: ".$awardType[0]."$br";
				$specs['redcap_type'] = $awardType[0];
				// $echoToScreen .= "RePORTER changes for {$row['reporter_projectnumber']}: ".json_encode($changes).$br;
				# SJP 2018-01-02
				# this seemingly is not needed; uncomment if incorrect
				// if (!isset($listOfAwards[$projectNumber])) {
				if (isset($changes['type'][$$projectNumber])) {
					$specs['redcap_type'] = $changes['type'][$projectNumber];
					$awardType[0] = $specs['redcap_type'];
				}
				if (isset($changes['start_date'][$specs['sponsor_award_no']])) {
					$specs['start_date'] = getReporterDate($changes['start_date'][$specs['sponsor_award_no']]);
				}
				if (isset($changes['end_date'][$specs['sponsor_award_no']])) {
					$specs['end_date'] = getReporterDate($changes['end_date'][$specs['sponsor_award_no']]);
				}
				$listOfAwards[$projectNumber] = $specs;
				// }
				if ($awardType[0] != "N/A") {
					$prefixes = array();
					if (preg_match("/\-\d+.*$/", $projectNumber)) {
						$prefixes[] = preg_replace("/\-\d+.*$/", "", $projectNumber);
					}
					if (preg_match("/\s+\(\d+\)$/", $projectNumber)) {
						$prefixes[] = preg_replace("/\s+\(\d+\)$/", "", $projectNumber);
					}
					$prefixes[] = $projectNumber;
					$foundIndex = false;
					foreach ($prefixes as $prefix) {
						$index = $prefix ."____".$specs['sponsor_type']."____".$specs['start_date'];
						if (isset($importData[$index]) && ($importData[$index][0] == "REMOVE")) {
							$foundIndex = true;
							// $echoToScreen .= "Found index $index\n";
						}
					}
					if (!$foundIndex) {
						if ($debug || $selectRecord) {
							$echoToScreen .= "TS C: {$specs['sponsor_award_no']} {$specs['start_date']} ".json_encode($specs)."$br";
							// $echoToScreen .= "TS C: ".json_encode($importData)."$br";
							// $echoToScreen .= "TS C: ".json_encode($prefixes)."$br";
						}
						if ($row['reporter_budgetstartdate']) {
							$awardTimestamps[$projectNumber] = strtotime(getReporterDate($specs['start_date'])); 
						} else {
							$awardTimestamps[$projectNumber] = strtotime(getReporterDate($specs['start_date'])); 
						}
						// $echoToScreen .= "RePORTER {$row['record_id']} {$specs['person_name']}: ".json_encode($awardType[1])."$br";
					}
				} else {
					// $echoToScreen .= "RePORTER reject: ".json_encode($awardType)."$br";
				}
			}
		} else if (!isset($row['redcap_repeat_instrument']) || $row['redcap_repeat_instrument'] == "") {
			$normativeRow = $row;
		}
	}

	# Follow-Up Surveys are next imortant; then Scholars' Survey; then Newman, then Sheet 2, then the 2017 New items
	$types = array("Followup", "Check", "Newman", "Sheet2", "New");
	foreach ($types as $type) {
		$arrayOfSpecs = array();
		if ($type == "Followup") {
			$arrayOfSpecs = array();
			$followupRows = selectFollowupRows($recordRows);
			foreach ($followupRows as $instance => $row) {
				$ary = getFollowUpSpecArray($row);
				foreach ($ary as $spec) {
					$arrayOfSpecs[] = $spec;
				}
			}
		} else if ($type == "Check") {
			$arrayOfSpecs = getCheckSpecArray($normativeRow);
		} else if ($type == "Newman") {
			$arrayOfSpecs = getNewmanSpecArray($normativeRow);
		} else if ($type == "Sheet2") {
			$arrayOfSpecs = getSheet2SpecArray($normativeRow);
		} else if ($type == "New") {
			$arrayOfSpecs = getNew2017SpecArray($normativeRow);
		}
		echo $type." ".json_encode($arrayOfSpecs)."\n";
		foreach ($arrayOfSpecs as $specs) {
			if (markAsSeenAndCheckIfSeen($specs, $record)) {
				$awardType = calculateAwardType($specs);
				// $echoToScreen .= "$type: ".$awardType[0]."$br";
				$specs['redcap_type'] = $awardType[0];
				if (!isset($listOfAwards[$specs['sponsor_award_no']])) {
					if ($debug || $selectRecord) {
						$echoToScreen .= "Setting listOfAwards with {$specs['sponsor_award_no']}$br";
					}
					if (isset($changes['type'][$specs['sponsor_award_no']])) {
						$specs['redcap_type'] = $changes['type'][$specs['sponsor_award_no']];
						$awardType[0] = $specs['redcap_type'];
					}
					if (isset($changes['start_date'][$specs['sponsor_award_no']])) {
						$specs['start_date'] = $changes['start_date'][$specs['sponsor_award_no']];
					}
					if (isset($changes['end_date'][$specs['sponsor_award_no']])) {
						$specs['end_date'] = $changes['end_date'][$specs['sponsor_award_no']];
					}
					$listOfAwards[$specs['sponsor_award_no']] = $specs;
					if ($awardType[0] != "N/A") {
						if (!isset($importData[$specs['sponsor_award_no']]) || ($importData[$specs['sponsor_award_no']][0] != "REMOVE")) {
							if ($debug || $selectRecord) {
								$echoToScreen .= "TS D: {$specs['sponsor_award_no']} {$specs['start_date']} ".json_encode($specs)."$br";
							}
							$awardTimestamps[$specs['sponsor_award_no']] = strtotime($specs['start_date']); 
							// $echoToScreen .= "$type {$normativeRow['record_id']} {$specs['person_name']}: ".json_encode($awardType[1])."$br";
						}
					} else {
						// $echoToScreen .= "$type {$normativeRow['record_id']} reject: ".json_encode($awardType[1])."$br";
					}
				}
			} else {
				// $echoToScreen .= "$type Seen: ".json_encode($specs)."$br";
			}
		}
	}
 

	# sort awards by timestamp (keys)
	asort($awardTimestamps);


	# order holds the final ordered awards that are the authoritative list to be stored
	# first, we have to look through for duplicates

	$order = array();
	$i = 0;
	$prevTs = 0;
	$prevRedcapType = "";
	$seenBases = array();
	$awardTypes = getAwardTypes();
	if ($debug || $selectRecord) {
		$echoToScreen .= "awardTimestamps sR: ".json_encode($awardTimestamps)."$br";
		$echoToScreen .= "listOfAwards sR: ".json_encode($listOfAwards)."$br";
		$echoToScreen .= "changes sR: ".json_encode($changes)."$br";
	}
	// $echoToScreen .= "$record awardTimestamps: ".json_encode($awardTimestamps)."$br";
	// $echoToScreen .= "$record listOfAwards: ".json_encode($listOfAwards)."$br";
	// $echoToScreen .= "$record changes: ".json_encode($changes)."$br";
	foreach ($awardTimestamps as $awardNo => $ts) {
		if ($i < $maxAwards) {
			$currBase = getBaseAwardNumber($awardNo);
			if ($debug || $selectRecord) {
				$echoToScreen .= "$i currBase: $currBase for $awardNo$br";
			}
			$specs = $listOfAwards[$awardNo];
			if ($specs['source'] != "coeus") {
				# search for duplicates in COEUS
				foreach ($awardTimestamps as $awardNo2 => $ts2) {
					if ($awardNo != $awardNo2) {
						$base2 = getBaseAwardNumber($awardNo2);
						if (($currBase == $base2) || (preg_match("/^R01$/", $awardNo)  && preg_match("/R01/", $awardNo2))) {
							if ($debug || $selectRecord) {
								$echoToScreen .= "$i match at base2: $currBase for $awardNo$br";
							}
							$specs2 = $listOfAwards[$awardNo2];
							$valid = array();
							// $echoToScreen .= "Comparing $awardNo and $awardNo2$br";
							if (preg_match("/^R01$/", $awardNo) && preg_match("/R01\S/", $awardNo2)) {
								$valid = array("coeus", "data", "sheet2");
							}
							if ($specs['source'] == "sheet2") {
								$valid = array("coeus", "data");
							} else if ($specs['source'] == "data") {
								$valid = array("coeus");
							} 
							if (in_array($specs2['source'], $valid)) {
								# reassign main loop
								$specs = $specs2;
								$awardNo = $awardNo2;
								$ts = $ts2;
								$currBase = $base2;  // equal
								# do NOT break so as to see all options
							}
						}
					}
				}
			}
			if (!in_array($currBase, $seenBases) && ($ts > $prevTs)) {
				if ($debug || $selectRecord) {
					$echoToScreen .= "ADDING A: $awardNo ".json_encode($listOfAwards[$awardNo])."$br";
				}
				if ($listOfAwards[$awardNo]["start_date"]) {
					$startDate1 = strtotime($listOfAwards[$awardNo]["start_date"]);
					foreach ($awardTimestamps as $awardNo2 => $ts2) {
						$currBase2 = getBaseAwardNumber($awardNo2);
						# search for earliest iteration with currBase
						if (($currBase == $currBase2) && ($listOfAwards[$awardNo2]["start_date"])) {
							$startDate2 = strtotime($listOfAwards[$awardNo2]['start_date']);
							if ($startDate1 > $startDate2) {
								$awardNo = $awardNo2;
								$startDate1 = $startDate2;
								$ts = $ts2;
								# no need to switch $currBase
							}
						}
					}
				}
				$order[] = $listOfAwards[$awardNo];
				$prevTs = $ts;
				$prevRedcapType = $listOfAwards[$awardNo]['redcap_type'];
				$seenBases[] = $currBase;
			} else if (!in_array($currBase, $seenBases) && ($ts == $prevTs) && ($listOfAwards[$awardNo]['redcap_type'] != $prevRedcapType)) {
				if ($debug || $selectRecord) {
					$echoToScreen .= "PREVIOUS: ".json_encode($listOfAwards[$awardNo])."$br";
				}
				$currAwardTypeNo = $awardTypes[$listOfAwards[$awardNo]['redcap_type']];
				$prevAwardTypeNo = $awardTypes[$prevRedcapType];
				$seenBases[] = $currBase;
				if ($currAwardTypeNo > $prevAwardTypeNo) {
					if ($debug || $selectRecord) {
						$echoToScreen .= "ADDING B: ".json_encode($listOfAwards[$awardNo])."$br";
					}
					$order[] = $listOfAwards[$awardNo];
					$prevRedcapType = $listOfAwards[$awardNo]['redcap_type'];
				} else {
					if (count($order) == 0) {
						if ($debug || $selectRecord) {
							$echoToScreen .= "ADDING C: ".json_encode($listOfAwards[$awardNo])."$br";
						}
						$order[] = $listOfAwards[$awardNo];
						$prevRedcapType = $listOfAwards[$awardNo]['redcap_type'];
					} else {
						$prevElem = array_pop($order);
						if ($debug || $selectRecord) {
							$echoToScreen .= "INSERTING NEXT-TO-Last: ".json_encode($listOfAwards[$awardNo])."$br";
						}
						$order[] = $listOfAwards[$awardNo];
						$order[] = $prevElem;
					}
				}
			} else {
				if ($debug || $selectRecord) {
					$echoToScreen .= "DUPLICATE: ".json_encode($listOfAwards[$awardNo])."$br";
					$echoToScreen .= "	 : $currBase ".json_encode($seenBases)."$br";
				}
			}
		}
		$i++;
	}
	if ($debug || $selectRecord) {
		$echoToScreen .= "order: ".json_encode($order)."$br";
	}
	// $echoToScreen .= "$br";
	// $echoToScreen .= "Record: $record$br";
	// $echoToScreen .= "listOfAwards: ".json_encode($listOfAwards)."$br";
	// $echoToScreen .= "awardTimestamps: ".json_encode($awardTimestamps)."$br";
	// foreach ($awardTimestamps as $award => $ts) {
		// $echoToScreen .= "     $award - ".date("Y-m-d", $ts)."$br";
	// }
	// $echoToScreen .= "order: ".json_encode($order)."$br";

	return array($order, $listOfAwards);
}

# finds the R01 out of a compound grant list
function findR01($sn) {
	global $echoToScreen, $br;
	if (preg_match("/\d[Rr]01\S+/", $sn, $matches)) {
		// $echoToScreen .= "findR01 returning A: ".json_encode($matches)."$br";
		return $matches[0];
	}
	if (preg_match("/[Rr]01\S+/", $sn, $matches)) {
		// $echoToScreen .= "findR01 returning B: ".json_encode($matches)."$br";
		return $matches[0];
	}
	return $sn;
}

# adjusts the award end dates to concur with the first r01
function reworkAwardEndDates($row) {
	if (!$row['summary_first_r01']) {
		return $row;
	}
	$r01 = strtotime($row['summary_first_r01']);
	$endKDate = date("Y-m-d", $r01 - 24 * 3600);
	$kTypes = array(1, 2, 3, 4);

	for ($i = 1; $i <= 15; $i++) {
		$type = $row['summary_award_type_'.$i];
		$endDate = strtotime($row['summary_award_end_date_'.$i]);
		if (in_array($type, $kTypes) && ($r01 < $endDate)) {
			$row['summary_award_end_date_'.$i] = $endKDate;
		} 
	}
	return $row;
}

# get the default specs for a custom grant
function getCustomSpecs($row) {
	if ($row["custom_start"] != "") {
		$awardTypes = getReverseAwardTypes();
		$awardTypes[""] = "";

		$specs = getBlankSpecs();
		$specs['redcap_type'] = $awardTypes[$row['custom_type']];
		$specs['person_name'] = $row['identifier_first_name']." ".$row['identifier_last_name'];
		$specs['start_date'] = $row['custom_start'];
		$specs['end_date'] = $row['custom_end'];
		$specs['budget'] = $row['custom_costs'];
		$specs['sponsor'] = $row['custom_org'];
		$specs['source'] = "custom";
		$specs['last_update'] = $row['custom_last_update'];
		# Co-PI or PI, not Co-I or Other
		if (($row['custom_role'] == 1) || ($row['custom_role'] == 2)) {
			$specs['pi_flag'] = 'Y';
		} else {
			$specs['pi_flag'] = 'N';
		}
		$awardno = $row['custom_number'];
		$specs['sponsor_award_no'] = $awardno;
		if (preg_match("/^\d?[A-Z]\d\d/", $awardno, $matches)) {
			$match = preg_replace("/^\d/", "", $matches[0]);
			$specs['nih_mechanism'] = $match;
		}
		return $specs;
	}
	return array();
}

# get the follow-up Survey default spec array
function getFollowUpSpecArray($row) {
	$ary = array();
	for ($i=1; $i <= 15; $i++) {
		if ($row["followup_grant$i"."_start"] != "") {
			$specs = getBlankSpecs();
			$specs['person_name'] = $row['identifier_first_name']." ".$row['identifier_last_name'];
			$specs['start_date'] = $row['followup_grant'.$i.'_start'];
			$specs['end_date'] = $row['followup_grant'.$i.'_end'];
			$specs['source'] = "scholars";
			$specs['budget'] = $row['followup_grant'.$i.'_costs'];
			$specs['sponsor'] = $row['followup_grant'.$i.'_org'];
			# Co-PI or PI, not Co-I or Other
			if (($row['followup_grant'.$i.'_role'] == 1) || ($row['followup_grant'.$i.'_role'] == 2) || ($row['followup_grant'] == '')) {
				$specs['pi_flag'] = 'Y';
			} else {
				$specs['pi_flag'] = 'N';
			}
			$awardno = $row['followup_grant'.$i.'_number'];
			$specs['sponsor_award_no'] = $awardno;
			if (preg_match("/^\d?[A-Z]\d\d/", $awardno, $matches)) {
				$match = preg_replace("/^\d/", "", $matches[0]);
				$specs['nih_mechanism'] = $match;
			}
			$ary[] = $specs;
		}
	}
	return $ary;
}

# get the Scholars' Survey (always nicknamed check) default spec array
function getCheckSpecArray($row) {
	$ary = array();
	for ($i=1; $i <= 15; $i++) {
		if ($row["check_grant$i"."_start"] != "") {
			$specs = getBlankSpecs();
			$specs['person_name'] = $row['identifier_first_name']." ".$row['identifier_last_name'];
			$specs['start_date'] = $row['check_grant'.$i.'_start'];
			$specs['end_date'] = $row['check_grant'.$i.'_end'];
			$specs['source'] = "scholars";
			$specs['budget'] = $row['check_grant'.$i.'_costs'];
			$specs['sponsor'] = $row['check_grant'.$i.'_org'];
			# Co-PI or PI, not Co-I or Other
			if (($row['check_grant'.$i.'_role'] == 1) || ($row['check_grant'.$i.'_role'] == 2) || ($row['check_grant'] == '')) {
				$specs['pi_flag'] = 'Y';
			} else {
				$specs['pi_flag'] = 'N';
			}
			$awardno = $row['check_grant'.$i.'_number'];
			$specs['sponsor_award_no'] = $awardno;
			if (preg_match("/^\d?[A-Z]\d\d/", $awardno, $matches)) {
				$match = preg_replace("/^\d/", "", $matches[0]);
				$specs['nih_mechanism'] = $match;
			}
			$ary[] = $specs;
		}
	}
	return $ary;
}

# This puts the new2017 folks into specs
function getNew2017SpecArray($row) {
	$ary = array();

	$internalKDate = "";
	if (!preg_match("/none/", $row['newman_new_first_institutional_k_award'])) {
		$internalKDate = $row['newman_new_first_institutional_k_award'];
	}
	if ($internalKDate) {
		$specs = getBlankSpecs();
		$specs['person_name'] = $row['identifier_first_name']." ".$row['identifier_last_name'];
		$specs['start_date'] = $internalKDate;
		$specs['source'] = "new2017";
		$specs['sponsor_type'] = $row["newman_new_current_program_funding"];
		if ($specs['sponsor_type']) {
			$specs['sponsor_award_no'] = $specs['sponsor_type'];
		} else {
			$specs['sponsor_award_no'] = "Internal K - Rec. {$row['record_id']}";
		}
		$specs['pi_flag'] = "Y";
		$ary[] = $specs;
	}

	$noninstDate = "";
	if (!preg_match("/none/", $row['newman_new_first_individual_k_award'])) {
		$noninstDate = $row['newman_new_first_individual_k_award'];
	}
	if ($noninstDate) {
		$specs = getBlankSpecs();
		$specs['person_name'] = $row['identifier_first_name']." ".$row['identifier_last_name'];
		$specs['start_date'] = $noninstDate;
		$specs['source'] = "new2017";
		$specs['pi_flag'] = "Y";
		# for this, the type = the award no
		$awardno = $row['newman_new_current_nih_funding'];
		if (!$awardno) {
			$specs['sponsor_award_no'] = "Unknown individual - Rec. {$row['record_id']}";
		} else {
			$specs['sponsor_award_no'] = $awardno;
		}
		$ary[] = $specs;
	}
	return $ary;
}

# sheet 2 into specs
# sheet 2 is of questionable origin and is the least reliable of the data sources
# we do not know the origin or author of sheet 2
function getSheet2SpecArray($row) {
	$ary = array();

	$internalKDate = "";
	if (!preg_match("/none/", $row['newman_sheet2_institutional_k_start'])) {
		$internalKDate = $row['newman_sheet2_institutional_k_start'];
	}
	if ($internalKDate) {
		$specs = getBlankSpecs();
		$specs['person_name'] = $row['identifier_first_name']." ".$row['identifier_last_name'];
		$specs['start_date'] = $internalKDate;
		$specs['source'] = "sheet2";
		foreach (getNewmanFirstType($row, "sheet2_internal") as $type) {
			$specs['sponsor_type'] = $type;
			if (preg_match("/K12/", $specs['sponsor_type']) || preg_match("/KL2/", $specs['sponsor_type'])) {
				$specs['sponsor_award_no'] = $specs['sponsor_type'];
			} else {
				if ($specs['sponsor_type']) {
					$specs['sponsor_award_no'] = $specs['sponsor_type'];
				} else {
					$specs['sponsor_award_no'] = "Internal K - Rec. {$row['record_id']}";
				}
			}
			$specs['pi_flag'] = "Y";
			$ary[] = $specs;
		}
	}

	$noninstDate = "";
	if (!preg_match("/none/", $row['newman_sheet2_noninstitutional_start'])) {
		$noninstDate = $row['newman_sheet2_noninstitutional_start'];
	}
	if ($noninstDate) {
		$specs = getBlankSpecs();
		$specs['person_name'] = $row['identifier_first_name']." ".$row['identifier_last_name'];
		$specs['start_date'] = $noninstDate;
		$specs['source'] = "sheet2";
		$specs['pi_flag'] = "Y";
		# for this, the type = the award no
		foreach (getNewmanFirstType($row, "sheet2_noninst") as $awardno) {
			if (!$awardno) {
				$specs['sponsor_award_no'] = "Unknown individual - Rec. {$row['record_id']}";
			} else {
				$specs['sponsor_award_no'] = $awardno;
			}
			if ($row['newman_sheet2_first_r01_date'] && !preg_match("/none/", $row['newman_sheet2_first_r01_date']) && preg_match("/[Rr]01/", $awardno)) {
				# found R01
				// $echoToScreen .= "R01 found $awardno$br";
			} else {
				$ary[] = $specs;
			}
		}
	}

	$r01Date = "";
	if (!preg_match("/none/", $row['newman_sheet2_first_r01_date'])) {
		$r01Date = $row['newman_sheet2_first_r01_date'];
	}
	if ($r01Date) {
		$specs = getBlankSpecs();
		$specs['person_name'] = $row['identifier_first_name']." ".$row['identifier_last_name'];
		$specs['start_date'] = $r01Date;
		$specs['pi_flag'] = "Y";
		$specs['source'] = "sheet2";

		$previous = $row['newman_sheet2_previous_funding'];
		$current = $row['newman_sheet2_current_funding'];

		if (preg_match("/[Rr]01/", $previous)) {
			$specs['sponsor_award_no'] = findR01($previous);
		} else if (preg_match("/[Rr]01/", $current)) {
			$specs['sponsor_award_no'] = findR01($current);
		} else {
			$specs['sponsor_award_no'] = "R01";
		}
		$ary[] = $specs;
	}

	return $ary;
}

# Newman data into specs
function getNewmanSpecArray($row) {
	global $echoToScreen, $br;
	$ary = array();
	$date1 = "";
	if (!preg_match("/none/", $row['newman_data_date_first_institutional_k_award_newman'])) {
		$date1 = $row['newman_data_date_first_institutional_k_award_newman'];
	}
	if ($date1) {
		$specs = getBlankSpecs();
		$specs['person_name'] = $row['identifier_first_name']." ".$row['identifier_last_name'];
		$specs['pi_flag'] = "Y";
		$specs['start_date'] = $date1;
		$specs['source'] = "data";
		foreach (getNewmanFirstType($row, "data_internal") as $type) {
			$specs['sponsor_type'] = $type;
			if (preg_match("/K12/", $specs['sponsor_type']) || preg_match("/KL2/", $specs['sponsor_type'])) {
				$specs['sponsor_award_no'] = $specs['sponsor_type'];
			} else {
				if ($specs['sponsor_type']) {
					$specs['sponsor_award_no'] = $specs['sponsor_type'];
				} else {
					$specs['sponsor_award_no'] = "Internal K - Rec. {$row['record_id']}";
				}
			}
			$ary[] = $specs;
		}
	}

	$date2 = "";
	if (!preg_match("/none/", $row['newman_data_individual_k_start'])) {
		$date2 = $row['newman_data_individual_k_start'];
	}
	if ($date2) {
		// $echoToScreen .= "In individual with $date2$br";
		$specs = getBlankSpecs();
		$specs['person_name'] = $row['identifier_first_name']." ".$row['identifier_last_name'];
		$specs['pi_flag'] = "Y";
		$specs['source'] = "data";
		$specs['start_date'] = $date2;
		foreach (getNewmanFirstType($row, "data_individual") as $type) {
			// $echoToScreen .= "In loop with $type$br";
			$specs['sponsor_type'] = $type;
			if ($type) {
				$specs['sponsor_award_no'] = $specs['sponsor_type'];
			} else {
				$specs['sponsor_award_no'] = "Individual K - Rec. {$row['record_id']}";
			}
			$ary[] = $specs;
		}
	}

	$date3 = "";
	if (!preg_match("/none/", $row['newman_data_r01_start'])) {
		$date3 = $row['newman_data_r01_start'];
	}
	if ($date3) {
		$specs = getBlankSpecs();
		$specs['person_name'] = $row['identifier_first_name']." ".$row['identifier_last_name'];
		$specs['pi_flag'] = "Y";
		$specs['start_date'] = $date3;
		$specs['source'] = "data";
		$specs['sponsor_type'] = "R01";
		$specs['sponsor_award_no'] = "R01";
		$ary[] = $specs;
	}

	// $echoToScreen .= "Returing ".json_encode($ary)."$br";
	return $ary;
}

# splits multiple awards into an array for one Newman data entry
function splitAwards($en) {
	$a = preg_split("/\s*[\|;,]\s*/", $en);
	if (count($a) >= 2) {
		// $echoToScreen .= "Awards: ".json_encode($a)."$br";
	}
	return $a;
}

# get the first type of a Newman data entry
function getNewmanFirstType($row, $dataSource) {
	global $echoToScreen, $br;
	$current = "";
	$previous = "";
	if ($dataSource == "data_individual") {
		$previous = $row['newman_data_previous_nih_grant_funding_newman'];
		$current = $row['newman_data_nih_current'];
	} else if ($dataSource == "data_internal") {
		$previous = $row['newman_data_previous_program_funding_newman'];
		$current = $row['newman_data_current_program_funding_newman'];
	} else if ($dataSource == "sheet2_internal") {
		$previous = $row['newman_sheet2_previous_program_funding_2'];
		$current = $row['newman_sheet2_current_program_funding_2'];
	} else if ($dataSource == "sheet2_noninst") {
		$previous = $row['newman_sheet2_previous_funding'];
		$current = $row['newman_sheet2_current_funding'];
	}
	if ((preg_match("/none/", $current) || ($current == "")) && (preg_match("/none/", $previous) || ($previous == ""))) {
		return array("");
	} else {
		// $echoToScreen .= $row['record_id']." ".$row['redcap_repeat_instance'].": Examining $previous and $current$br";
		$previous = preg_replace("/none/", "", $previous);
		$current = preg_replace("/none/", "", $current);
		if ($previous && $current) {
			return splitAwards($previous);
		} else if ($previous) {
			return splitAwards($previous);
		} else if ($current) {
			return splitAwards($current);
		} else {
			// individual K
			return array("");
		}
	}
}

# a blank set of specs
function getBlankSpecs() {
	return array(
			'redcap_type' => "",
			'person_name' => "",
			'start_date' => "",
			'end_date' => "",
			'budget' => "",
			'sponsor' => "",
			'source' => "",
			'sponsor_type' => "",
			'sponsor_award_no' => "",
			'percent_effort' => "",
			'nih_mechanism' => "",
			'pi_flag' => "",
			'last_update' => "",
			);
}

# ExPORTER specs
function makeExporterSpecs($row, $data) {
	global $echoToScreen, $br;
	$ary = getBlankSpecs();

	$ary['start_date'] = getReporterDate($row['exporter_project_start']);
	$ary['end_date'] = getReporterDate($row['exporter_project_end']);
	$ary['budget'] = 0;
	$baseAwardNo = getBaseAwardNumber($row2['exporter_full_project_num']);
	foreach ($data as $row2) {
		if (($row['record_id'] == $row2['record_id']) && ($row2['redcap_repeat_instrument'] == "exporter") && (getBaseAwardNumber($row2['exporter_full_project_num']) == $baseAwardNo)) {
			$ary['budget'] += $row['exporter_total_cost'];
		}
	}
	$ary['sponsor'] = $row['exporter_ic_name'];
	$ary['sponsor_type']  = $row['exporter_ic_name'];
	$ary['sponsor_award_no']  = preg_replace("/-[^\-]+$/", "", $row['exporter_full_project_num']);
	$ary['source'] = "exporter";
	$ary['pi_flag'] = "Y";
	// $echoToScreen .= "ExPORTER Specs: ".json_encode($ary)."$br";

	return $ary;
}

# RePORTER specs
function makeReporterSpecs($row, $data) {
	global $echoToScreen, $br;
	$ary = getBlankSpecs();

	$ary['start_date'] = getReporterDate($row['reporter_projectstartdate']);
	$ary['end_date'] = getReporterDate($row['reporter_projectenddate']);
	$ary['budget'] = 0;
	$baseAwardNo = getBaseAwardNumber($row2['reporter_projectnumber']);
	foreach ($data as $row2) {
		if (($row['record_id'] == $row2['record_id']) && ($row2['redcap_repeat_instrument'] == "reporter") && (getBaseAwardNumber($row2['reporter_projectnumber']) == $baseAwardNo)) {
			$ary['budget'] += $row['reporter_totalcostamount'];
		}
	}
	$ary['sponsor'] = $row['reporter_agency'];
	$ary['sponsor_type']  = $row['reporter_agency'];
	$ary['last_update']  = $row['reporter_last_update'];
	$ary['sponsor_award_no']  = preg_replace("/-[^\-]+$/", "", $row['reporter_projectnumber']);
	$ary['source'] = "reporter";
	$ary['pi_flag'] = "Y";
	// $echoToScreen .= json_encode($ary)."$br";

	return $ary;
}

function makeCoeusSpecs($row, $data) {
	global $echoToScreen, $br;
	$ary = getBlankSpecs();

	$awardNo = preg_replace("/-[^\-]+$/", "", $row['coeus_sponsor_award_number']);
	$ary['person_name'] = $row['coeus_person_name'];
	if (preg_match("/[Kk]12/", $awardNo) && ($row['coeus_pi_flag'] == "N")) {
		$ary['start_date'] = "";
		$ary['end_date'] = "";
	} else {
		$ary['start_date'] = $row['coeus_project_start_date'];
		$ary['end_date'] = $row['coeus_project_end_date'];
	}
	$ary['budget'] = $row['coeus_direct_cost_budget_period'];
	$ary['sponsor'] = $row['coeus_direct_sponsor_name'];
	$ary['sponsor_type']  = $row['coeus_direct_sponsor_type'];
	$ary['sponsor_award_no']  = $awardNo;
	$ary['source'] = "coeus";
	$ary['percent_effort'] = $row['coeus_percent_effort'];
	$ary['nih_mechanism'] = getMechanism($row, $data);
	$ary['last_update'] = $row['coeus_last_update'];
	$ary['pi_flag'] = $row['coeus_pi_flag'];
	// $echoToScreen .= json_encode($ary)."$br";

	return $ary;
}

# get NIH mechanism from the sponsor award number
function getMechanismFromData($recordId, $sponsorNo, $data) {
	$baseAwardNo = getBaseAwardNumber($sponsorNo);
	foreach ($data as $row) {
		if ($row['record_id'] == $recordId) {
			if (($sponsorNo == $row['coeus_sponsor_award_number']) || ($baseAwardNo == getBaseAwardNumber($row['coeus_sponsor_award_number']))) {
				return getMechanism($row, $data);
			}
		}
	}
	return "";
}

# gets the NIH mechanism
function getMechanism($row, $data) {
	$recordId = $row['record_id'];
	$baseAwardNo = getBaseAwardNumber($row['coeus_sponsor_award_number']);
	foreach ($data as $row2) {
		if ($row2['record_id'] == $recordId) {
			$baseAwardNo2 = getBaseAwardNumber($row2['coeus_sponsor_award_number']);
			if ($baseAwardNo2 == $baseAwardNo) {
				if ($row2['coeus_nih_mechanism']) {
					return $row2['coeus_nih_mechanism'];
				}
			} 
		}
	}
	return "";
}

# This calculates the summary grants information from COEUS, Federal Reporter, NIH Exporter, and Custom Grants (only)
function calculateYearlyGrants($data) {
	$outData = array();

	# associative array
	$transformedData = array();

	# instruments in order of priority
	$instruments = array("coeus", "reporter", "exporter", "custom_grant", "followup", ""); 

	$records = array();
	foreach ($data as $row) {
		if (!in_array($row['record_id'], $records)) {
			$records[] = $row['record_id'];
		}
		if (!isset($transformedData[$row['record_id']])) {
			$transformedData[$row['record_id']] = array();
		}
		$transformedData[$row['record_id']][] = $row;
	}

	foreach ($transformedData as $recordId => $ary) {
		$startTs = 0;
		$endTs = 0;
		$everInternalKBool = false;
		$everIndividualKEquivBool = false;
		$everK12KL2Bool = false;
		$everR01EquivBool = false;
		foreach ($ary as $row) {
			if (in_array($row['redcap_repeat_instrument'], $instruments)) {
				$currStartTsAry = array();
				$currEndTsAry = array();
				if ($row['redcap_repeat_instrument'] == "coeus") {
					$currStartTsAry[] = strtotime($row['coeus_budget_start_date']);
					$currEndTsAry[] = strtotime($row['coeus_budget_end_date']);
				} else if ($row['redcap_repeat_instrument'] == "reporter") {
					$currStartTsAry[] = strtotime($row['reporter_budgetstartdate']);
					$currEndTsAry[] = strtotime($row['reporter_budgetenddate']);
				} else if ($row['redcap_repeat_instrument'] == "exporter") {
					$currStartTsAry[] = strtotime($row['exporter_budget_start']);
					$currEndTsAry[] = strtotime($row['exporter_budget_end']);
				} else if ($row['redcap_repeat_instrument'] == "custom_grant") {
					$currStartTsAry[] = strtotime($row['custom_start']);
					$currEndTsAry[] = strtotime($row['custom_end']);
				} else if ($row['redcap_repeat_instrument'] == "followup") {
					for ($i = 1; $i <= 15; $i++) {
						if ($row['followup_grant'.$i.'_start'] && $row['followup_grant'.$i.'_end']) {
							$currStartTsAry[] = strtotime($row['followup_grant'.$i.'_start']);
							$currEndTsAry[] = strtotime($row['followup_grant'.$i.'_end']);
						}
					}
				} else if ($row['redcap_repeat_instrument'] == "") {
					for ($i = 1; $i <= 15; $i++) {
						if ($row['check_grant'.$i.'_start'] && $row['check_grant'.$i.'_end']) {
							$currStartTsAry[] = strtotime($row['check_grant'.$i.'_start']);
							$currEndTsAry[] = strtotime($row['check_grant'.$i.'_end']);
						}
					}
				}
				for ($tsAryIdx = 0; $tsAryIdx < count($currStartTsAry) && $tsAryIdx < count($currEndTsAry); $tsAryIdx++) {
					$currStartTs = $currStartTsAry[$tsAryIdx];
					$currEndTs = $currEndTsAry[$tsAryIdx];
					if ($currStartTs && $currEndTs) {
						if ($startTs) {
							if ($currStartTs < $startTs) {
								$startTs = $currStartTs;
							}
						} else {
							$startTs = $currStartTs;
						}
						if ($endTs) {
							if ($currEndTs > $endTs) {
								$endTs = $currEndTs;
							}
						} else {
							$endTs = $currEndTs;
						}
					}
				}
			}
			if ($row['redcap_repeat_instrument'] === "") {
				if ($row['summary_ever_internal_k']) {
					$everInternalKBool = true;
				}
				if ($row['summary_ever_individual_k_or_equiv']) {
					$everIndividualKEquivBool = true;
				}
				if ($row['summary_ever_k12_kl2']) {
					$everK12KL2Bool = true;
				}
				if ($row['summary_ever_r01_or_equiv']) {
					$everR01EquivBool = true;
				}
			}
		}
		if ($startTs && $endTs) {
			$startYear = date("Y", $startTs);
			$endYear = date("Y", $endTs);
			$i = 1;
			$isFederal = array(
					"Non-Profit - Foundations/ Associations" => "Non-Federal",
					"DOD" => "Federal",
					"NASA" => "Federal",
					"ED" => "Federal",
					"NSF" => "Federal",
					"Federal" => "Federal",
					"Institutional Funds" => "Non-Federal",
					"Non-Profit - Other" => "Non-Federal",
					"State - Tennessee" => "Non-Federal",
					"Non-Profit - Education" => "Non-Federal",
					"State - Other" => "Non-Federal",
					"DOE" => "Federal",
					"NIH" => "Federal",
					"Profit" => "Non-Federal",
					"PHS" => "Federal",
					"Local Government" => "Non-Federal",
					"Endowment" => "Non-Federal",
					"Non-Profit - Hospital" => "Non-Federal",
					);

			for ($year = $startYear; $year <= $endYear; $year++) {
				$dollars = array("vumc" => 0, "nonvumc" => 0, "direct" => 0);
				$kDollars = array("vumc" => 0, "nonvumc" => 0, "direct" => 0);
				$kVUMCDollars = array("vumc" => 0, "nonvumc" => 0, "direct" => 0);
				$r01Dollars = array("vumc" => 0, "nonvumc" => 0, "direct" => 0);
				$r01EquivDollars = array("vumc" => 0, "nonvumc" => 0, "direct" => 0);
				$federalDollars = array("vumc" => 0, "nonvumc" => 0, "direct" => 0);
				$nonKDollars = array("vumc" => 0, "nonvumc" => 0, "direct" => 0);
				$nonKNonVUMCDollars = array("vumc" => 0, "nonvumc" => 0, "direct" => 0);
				$allDollars = array("vumc" => 0, "nonvumc" => 0, "direct" => 0);
				$everInternalK = array("vumc" => 0, "nonvumc" => 0, "direct" => 0);
				$everK12 = array("vumc" => 0, "nonvumc" => 0, "direct" => 0);
				$everIndivK = array("vumc" => 0, "nonvumc" => 0, "direct" => 0);
				$yearStart = strtotime("$year-01-01 00:00:00");
				$yearEnd = strtotime("$year-12-31 23:59:59");
				$yearDur = $yearEnd - $yearStart;
				$usedBaseAwardNumbers = array();
				foreach ($instruments as $instrument) {
					foreach ($ary as $row) {
						$grants = array();
						if (($instrument == "exporter") && ($row['redcap_repeat_instrument'] == "exporter")) {
							$grant = array();
							$grant['awardNo'] = $row['exporter_full_project_num'];
							if ($row['exporter_budget_start'] && $row['exporter_budget_end']) {
								$grant['currStartTs'] = strtotime($row['exporter_budget_start']);
								$grant['currEndTs'] = strtotime($row['exporter_budget_end']);
								$grant['fraction'] = calculateFractionEffort($grant['currStartTs'], $grant['currEndTs'], $yearStart, $yearEnd);
							}
							$grant['budget'] = $row['exporter_total_cost'];
							$grants[] = $grant;
						}
						if (($instrument == "reporter") && ($row['redcap_repeat_instrument'] == "reporter")) {
							$grant = array();
							$grant['awardNo'] = $row['reporter_projectnumber'];
							if ($row['reporter_budgetstartdate'] && $row['reporter_budgetenddate']) {
								$grant['currStartTs'] = strtotime($row['reporter_budgetstartdate']);
								$grant['currEndTs'] = strtotime($row['reporter_budgetenddate']);
								$grant['fraction'] = calculateFractionEffort($grant['currStartTs'], $grant['currEndTs'], $yearStart, $yearEnd);
							}
							$grant['budget'] = $row['reporter_totalcostamount'];
							$grants[] = $grant;
						}
						if (($instrument == "coeus") && ($row['redcap_repeat_instrument'] == "coeus")) {
							$grant = array();
							$grant['currStartTs'] = strtotime($row['coeus_budget_start_date']);
							$grant['currEndTs'] = strtotime($row['coeus_budget_end_date']);
							$grant['fraction'] = calculateFractionEffort($grant['currStartTs'], $grant['currEndTs'], $yearStart, $yearEnd);
							$grant['awardNo'] = $row['coeus_sponsor_award_number'];
							$grant['budget'] = $row['coeus_total_cost_budget_period'];
							$grants[] = $grant;
						}
						if (($instrument == "custom_grant") && ($row['redcap_repeat_instrument'] == "custom_grant")) {
							$grant = array();
							$grant['currStartTs'] = strtotime($row['custom_start']);
							$grant['currEndTs'] = strtotime($row['custom_end']);
							$grant['fraction'] = calculateFractionEffort($grant['currStartTs'], $grant['currEndTs'], $yearStart, $yearEnd);
							$grant['awardNo'] = $row['custom_number'];
							$grant['budget'] = $row['custom_costs'];
							$grants[] = $grant;
						}
						if (($instrument == "followup") && ($row['redcap_repeat_instrument'] == "followup")) {
							for ($i = 0; $i < 15; $i++) {
								if ($row['followup_grant'.$i.'_start']) {
									$grant = array();
									$grant['currStartTs'] = strtotime($row['followup_grant'.$i.'_start']);
									$grant['currEndTs'] = strtotime($row['followup_grant'.$i.'_end']);
									$grant['fraction'] = calculateFractionEffort($grant['currStartTs'], $grant['currEndTs'], $yearStart, $yearEnd);
									$grant['awardNo'] = $row['followup_grant'.$i.'_number'];
									$grant['budget'] = $row['followup_grant'.$i.'_costs'];
									$grants[] = $grant;
								}
							}
						}
						if (($instrument == "") && ($row['redcap_repeat_instrument'] == "")) {
							# scholar's survey
							for ($i = 0; $i < 15; $i++) {
								if ($row['check_grant'.$i.'_start']) {
									$grant = array();
									$grant['currStartTs'] = strtotime($row['check_grant'.$i.'_start']);
									$grant['currEndTs'] = strtotime($row['check_grant'.$i.'_end']);
									$grant['fraction'] = calculateFractionEffort($grant['currStartTs'], $grant['currEndTs'], $yearStart, $yearEnd);
									$grant['awardNo'] = $row['check_grant'.$i.'_number'];
									$grant['budget'] = $row['check_grant'.$i.'_costs'];
									$grants[] = $grant;
								}
							}
						}
						foreach ($grants as $grant) {
							$budget = $grant['budget'];
							$awardNo = $grant['awardNo'];
							$fraction = $grant['fraction'];
							$currStartTs = $grant['currStartTs'];
							$currEndTs = $grant['currEndTs'];

							if (($budget > 0) && ($awardNo !== "") && ($fraction > 0)) {
								$federal = false;
								if (($instrument == "coeus") && ($row['redcap_repeat_instrument'] == "coeus") && (($isFederal[$row['coeus_direct_sponsor_type']] == "Federal") || ($isFederal[$row['coeus_prime_sponsor_type']] == "Federal"))) {
									$federalDollars = addToGrantTotals($federalDollars, $row, $instrument, $fraction, $usedBaseAwardNumbers);
									$federal = true;
								}
								if (($instrument == "exporter") && ($row['redcap_repeat_instrument'] == "exporter")) {
									# all exporter dollars are federal
									$federalDollars = addToGrantTotals($federalDollars, $row, $instrument, $fraction, $usedBaseAwardNumbers);
									$federal = true;
								}
								if (($instrument == "reporter") && ($row['redcap_repeat_instrument'] == "reporter")) {
									# all reporter dollars are federal
									$federalDollars = addToGrantTotals($federalDollars, $row, $instrument, $fraction, $usedBaseAwardNumbers);
									$federal = true;
								}
								if ($federal) {
									if ($everInternalKBool) {
										$everInternalK = addToGrantTotals($everInternalK, $row, $instrument, $fraction, $usedBaseAwardNumbers);
									}
									if ($everIndividualKEquivBool) {
										$everIndivK = addToGrantTotals($everIndivK, $row, $instrument, $fraction, $usedBaseAwardNumbers);
									}
									if ($everK12KL2Bool) {
										$everK12 = addToGrantTotals($everK12, $row, $instrument, $fraction, $usedBaseAwardNumbers);
									}
								}
								$dollars = addToGrantTotals($dollars, $row, $instrument, $fraction, $usedBaseAwardNumbers);
								if (preg_match("/K\d\d/", $awardNo)) {
									$kDollars = addToGrantTotals($kDollars, $row, $instrument, $fraction, $usedBaseAwardNumbers);
								} else {
									$nonKDollars = addToGrantTotals($nonKDollars, $row, $instrument, $fraction, $usedBaseAwardNumbers);
								}
								$isR01 = false;
								if (preg_match("/R01/", $awardNo)) {
									$r01Dollars = addToGrantTotals($r01Dollars, $row, $instrument, $fraction, $usedBaseAwardNumbers);
									$isR01 = true;
								}
								if (($row['coeus_direct_cost_budget_period'] && ($row['coeus_direct_cost_budget_period'] >= 250000) && !preg_match("/R01/", $awardNo) && !preg_match("/K\d\d/", $awardNo)) || $isR01) {
									$r01EquivDollars = addToGrantTotals($r01EquivDollars, $row, $instrument, $fraction, $usedBaseAwardNumbers);
								}
								if (($row['reporter_totalcostamount'] && ($row['reporter_totalcostamount'] >= 250000) && !preg_match("/R01/", $awardNo)) || $isR01) {
									$r01EquivDollars = addToGrantTotals($r01EquivDollars, $row, $instrument, $fraction, $usedBaseAwardNumbers);
								}
								if ((preg_match("/K\d\d/", $awardNo)) || (preg_match("/VUMC/", $awardNo))) {
									$kVUMCDollars = addToGrantTotals($kVUMCDollars, $row, $instrument, $fraction, $usedBaseAwardNumbers);
								} else {
									$nonKNonVUMCDollars = addToGrantTotals($nonKNonVUMCDollars, $row, $instrument, $fraction, $usedBaseAwardNumbers);
								}
								$allDollars = addToGrantTotals($allDollars, $row, $instrument, $fraction, $usedBaseAwardNumbers);
								echo "SUMMARY GRANTS $year $instrument (".pretty($budget).") ".json_encode($allDollars)." $awardNo\n";
							}
						}
					}
					# since coeus repeats award numbers but allocates dollars by year,
					# and since we want to count all of them, we add the award numbers at the end
					foreach ($ary as $row) {
						if ($row['redcap_repeat_instrument'] == $instrument) {
							if ($row['redcap_repeat_instrument'] == "coeus") {
								$awardNo = $row['coeus_sponsor_award_number'];
							} else if ($row['redcap_repeat_instrument'] == "reporter") {
								$awardNo = $row['reporter_projectnumber'];
							} else if ($row['redcap_repeat_instrument'] == "exporter") {
								$awardNo = $row['exporter_full_project_num'];
							}
							$baseAwardNumber = getBaseAwardNumber($awardNo);
							if ($baseAwardNumber && !in_array($baseAwardNumber, $usedBaseAwardNumbers)) {
								$usedBaseAwardNumbers[] = $baseAwardNumber;
							}
						}
					}
				}

				$newRow = array(
						"record_id" => $recordId,
						"redcap_repeat_instance" => $i,
						"redcap_repeat_instrument" => "summary_grants",
						"summary_grants_year" => $year,
						"summary_grants_total_vumc_dollar_in_year" => $dollars['vumc'],
						"summary_grants_total_vumc_nonk_dollar_in_year" => $nonKDollars['vumc'],
						"summary_grants_total_vumc_k_dollar_in_year" => $kDollars['vumc'],
						"summary_grants_total_vumc_r01_dollar_in_year" => $r01Dollars['vumc'],
						"summary_grants_total_vumc_r01_equiv_dollar_in_year" => $r01EquivDollars['vumc'],
						"summary_grants_total_vumc_federal_dollar_in_year" => $federalDollars['vumc'],
						"summary_grants_total_vumc_all_dollar_in_year" => $allDollars['vumc'],
						"summary_grants_total_vumc_ever_internal_k_dollar_in_year" => $everInternalK['vumc'],
						"summary_grants_total_vumc_ever_kl2k12_dollar_in_year" => $everK12['vumc'],
						"summary_grants_total_vumc_ever_indiv_k_or_equiv_dollar_in_year" => $everIndivK['vumc'],
						"summary_grants_total_nonvumc_dollar_in_year" => $dollars['nonvumc'],
						"summary_grants_total_nonvumc_nonk_dollar_in_year" => $nonKDollars['nonvumc'],
						"summary_grants_total_nonvumc_k_dollar_in_year" => $kDollars['nonvumc'],
						"summary_grants_total_nonvumc_r01_dollar_in_year" => $r01Dollars['nonvumc'],
						"summary_grants_total_nonvumc_r01_equiv_dollar_in_year" => $r01EquivDollars['nonvumc'],
						"summary_grants_total_nonvumc_federal_dollar_in_year" => $federalDollars['nonvumc'],
						"summary_grants_total_nonvumc_all_dollar_in_year" => $allDollars['nonvumc'],
						"summary_grants_dollar_in_year" => $dollars['direct'],
						"summary_grants_nonk_dollar_in_year" => $nonKDollars['direct'],
						"summary_grants_nonk_nonvumc_dollar_in_year" => $nonKNonVUMCDollars['direct'],
						"summary_grants_k_dollar_in_year" => $kDollars['direct'],
						"summary_grants_k_vumc_dollar_in_year" => $kVUMCDollars['direct'],
						"summary_grants_r01_dollar_in_year" => $r01Dollars['direct'],
						"summary_grants_r01_equiv_dollar_in_year" => $r01EquivDollars['direct'],
						"summary_grants_federal_dollar_in_year" => $federalDollars['direct'],
						"summary_grants_all_dollar_in_year" => $allDollars['direct'],
					);
				echo "SUMMARY GRANTS FINAL $year vumc \$".pretty($newRow['summary_grants_total_vumc_all_dollar_in_year'])."\n";
				$outData[] = $newRow;
				$i++;
			}
		}
	}
	return $outData;
}

# not used
# we were using a threshold of 20% percent effort to count as worthy of being included
# check calculateAwardType for new inclusion criteria
function isEnoughPercentEffort($specs) {
	if (is_numeric($specs['percent_effort'])) {
		if ($specs['percent_effort'] >= 20) {
			return true;
		}
	}
	return false;
}

# an attempt to cut down on duplicates in the award processing stage
$awardsSeen = array();
$awardsCategorized = array();
function markAsSeenAndCheckIfSeen($specs, $record) {
	global $echoToScreen, $br;
	global $awardsSeen, $awardsCategorized;
	$awardNo = $specs['sponsor_award_no'];
	$baseAwardNo = getBaseAwardNumber($awardNo);
	if (!isset($awardsSeen[$record])) {
		$awardsSeen[$record] = array();
	}
	if (isset($awardsSeen[$record][$baseAwardNo]) && !in_array($awardNo, $awardsCategorized)) {
		// $echoToScreen .= "A: $baseAwardNo $record ".json_encode($awardsSeen)."$br";
		return false;
	} else {
		$awardsSeen[$record][$baseAwardNo] = $specs;
		if (!in_array($awardNo, $awardsCategorized)) {
			$awardsCategorized[] = $awardNo;
		}
		return true;
	}
}

# Finds the award type (from getAwardTypes)
# difficult
function calculateAwardType($specs) {
	$awardNo = $specs['sponsor_award_no'];
	global $echoToScreen, $br;

	$awardType = maybeReclassifyIntoType($awardNo);
	if ($awardType) {
		return array($awardType, $specs);
	}
	$r01Equivs = array("R00", "U01", "U19", "R56", "M01", "UG3", "P50", "P01", "P20", "UL1", "RC2", "U54", "RC1", "R18", "U24", "P60", "R35", "DP2", "DP3", "U18", "R61", "RC4", "RM1");
	if (($awardNo == "") || ($awardNo == "000")) {
		return array("N/A", $specs);
	} else if ($specs['pi_flag'] == "N"){
		return array("N/A", $specs);
	} else if (preg_match("/K12/", $awardNo)) {
		return array("K12/KL2", $specs);
	} else if (preg_match("/VUMC/", $awardNo)) {
		return array("N/A", $specs);
	} else if (preg_match("/Unknown individual/", $awardNo)) {
		return array("K Equivalent", $specs);
	} else if ($specs['budget'] && ($specs['budget'] >= 250000) && ($specs['nih_mechanism'] != "R01") && !preg_match("/[Rr]01/", $awardNo)) {
		return array("R01 Equivalent", $specs);
	} else {
		foreach ($r01Equivs as $letters) {
			if (preg_match("/^".$letters."/", $awardNo) || preg_match("/^\d".$letters."/", $awardNo)) {
				return array("R01 Equivalent", $specs);
			}
		}
	}
	if (preg_match("/^I01/", $awardNo) || preg_match("/\dI01/", $awardNo)) {
		return array("R01 Equivalent", $specs);
	} else if (preg_match("/^K23 - /", $awardNo)) {
		return array("K12/KL2", $specs);
	} else if (preg_match("/^R03/", $awardNo) || preg_match("/\dR03/", $awardNo)) {
		return array("N/A", $specs);
	} else if (preg_match("/^K24/", $awardNo) || preg_match("/\dK24/", $awardNo)) {
		return array("N/A", $specs);
	} else if (preg_match("/Internal K/", $awardNo)) {
		if (!$specs['start_date']) {
			$echoToScreen .= "No start date for ".json_encode($specs)."$br";
		}
		return array("Internal K", $specs);
	} else if (preg_match("/Individual K/", $awardNo)) {
		if (!$specs['start_date']) {
			$echoToScreen .= "No start date for ".json_encode($specs)."$br";
		}
		return array("Individual K", $specs);
	} else if (preg_match("/^R01$/", $awardNo) || preg_match("/\dR01/", $awardNo)) {
		if (!$specs['start_date']) {
			$echoToScreen .= "No start date for ".json_encode($specs)."$br";
		}
		return array("R01", $specs);
	} else if (($specs['nih_mechanism'] == "KL2") || ($specs['nih_mechanism'] == "K12")) {
		return array("K12/KL2", $specs);
	// } else if (preg_match("/W\d\dXWH/", $awardNo)) {
		// return array("R01 Equivalent", $specs);
	// } else if (preg_match("/\d[Pp]30/", $awardNo)) {
		// return array("R01 Equivalent", $specs);
	// } else if (preg_match("/^\d[Uu]/", $awardNo)  && ($specs['percent_effort'] >= 20)) {
		// return array("R01 Equivalent", $specs);
	} else if (($specs['nih_mechanism'] == "R01") || (preg_match("/^[Rr]01/", $awardNo) || preg_match("/\d[Rr]01/"))) {
		return array("R01", $specs);
	} else if (preg_match("/^[Kk]12/", $specs['nih_mechanism'])) {
		return array("K12/KL2", $specs);
	} else if (preg_match("/\d[Kk]L2/", $awardNo)) {
		return array("K12/KL2", $specs);
	} else if (preg_match("/\d[Kk]12/", $awardNo)) {
		return array("K12/KL2", $specs);
	} else if (preg_match("/[Kk]01/", $specs['nih_mechanism'])) {
		return array("Individual K", $specs);
	} else if (preg_match("/^[Kk]\d\d/", $specs['nih_mechanism']) || preg_match("/\d[Kk]\d\d/", $awardNo)) {
		return array("Individual K", $specs);
	} else if ($specs['sponsor'] == "Veterans Administration, Tennessee") {
		return array("K Equivalent", $specs);
	} else if (preg_match("/^[R]00/", $specs['nih_mechanism'])) {
		return array("K Equivalent", $specs);
	} else if (preg_match("/^[Kk]23/", $awardNo) || preg_match("/\d\s*[kK]23/", $awardNo)) {
		return array("Individual K", $specs);
	} else if (preg_match("/^[Kk]22/", $awardNo) || preg_match("/\d[Kk]22/", $awardNo)) {
		return array("Individual K", $specs);
	} else if (preg_match("/^[Kk]\d\d/", $awardNo) || preg_match("/\d[kK]\d\d/", $awardNo)) {
		return array("Individual K", $specs);
	} else if (preg_match("/Clinical Scientist Award 2009/", $awardNo)) {
		return array("K Equivalent", $specs);
	} else if (preg_match("/Clinical Scientist Development/", $awardNo)) {
		return array("K Equivalent", $specs);
	} else if (preg_match("/FTF/", $awardNo)) {
		return array("K Equivalent", $specs);
	} else if (preg_match("/SDG/", $awardNo)) {
		return array("K Equivalent", $specs);
	} else if (preg_match("/-CDA-/", $awardNo)) {
		return array("K Equivalent", $specs);
	} else if (preg_match("/^T\d\d/", $awardNo) || preg_match("/\dT\d\d/", $awardNo)) {
		return array("Mentoring/Training Grant Admin", $specs);
	} else if ($specs['sponsor_type'] == "Non-Profit - Foundations/ Associations") {
		if ($specs['percent_effort'] >= 50) {
			return array("K Equivalent", $specs);
		// } else if ($specs['percent_effort'] >= 1)  {
			// # already have PI_FLAG as Y
			// return array("Research Fellowship", $specs);
		} else {
			return array("N/A", $specs);
		}
	}
	return array("N/A", $specs);
}

# assigns a date to the CDA for the item at $index
function calculateCDADate($data, $row, $index) {
	global $echoToScreen, $br;
	$cdav = getOrderedCDAVariables($data, $row['record_id']);
	$variables = $cdav[0];
	// $echoToScreen .= "calculateCDADate {$row['record_id']} $index: ".json_encode($variables)."$br";
	if (count($variables) <= $index) {
		return "";
	}
	if ($index < 0) {
		return "";
	}
	return $variables[$index]['start_date'];
}

# transforms a degree select box to something usable by other variables
function transformSelectDegree($num) {
	if (!$num) {
		return "";
	}
	$transform = array(
			1 => 5,   #MS
			2 => 4,   # MSCI
			3 => 3,   # MPH
			4 => 6,   # other
			);
	return $transform[$num];
}

# if someone puts @vanderbilt for the domain of an email, this puts @vanderbilt.edu
function formatEmail($email) {
	if (preg_match("/@/", $email)) {
		return preg_replace("/vanderbilt$/", "vanderbilt.edu", $email);
	}
	return "";
}

# returns an array of (variableName, variableType) for when they left VUMC
# used for the Scholars' survey and Follow-Up surveys
function findVariableWhenLeftVU($normativeRow, $rows) {
	$followupRows = selectFollowupRows($rows);
	foreach ($followupRows as $instance => $row) {
		$prefices = array(
					"followup_prev1" => "followup",
					"followup_prev2" => "followup",
					"followup_prev3" => "followup",
					"followup_prev4" => "followup",
					"followup_prev5" => "followup",
				);
		foreach ($prefices as $prefix => $type) {
			$variable = $prefix."_institution";
			$variable_date = $prefix."_academic_rank_enddt";
			if (isset($row[$variable]) &&
				(preg_match("/vanderbilt/", strtolower($row[$variable])) || preg_match("/vumc/", strtolower($row[$variable]))) &&
				isset($row[$variable_date]) &&
				($row[$variable_date] != "")) {

				return array($variable_date, $type);
			}
		}
	}

	if ($row['check_institution'] != 1) {
		$prefices = array(
					"check_prev1" => "scholars",
					"check_prev2" => "scholars",
					"check_prev3" => "scholars",
					"check_prev4" => "scholars",
					"check_prev5" => "scholars",
				);
		foreach ($prefices as $prefix => $type) {
			$variable = $prefix."_institution";
			$variable_date = $prefix."_academic_rank_enddt";
			if (isset($normativeRow[$variable]) &&
				(preg_match("/vanderbilt/", strtolower($normativeRow[$variable])) || preg_match("/vumc/", strtolower($normativeRow[$variable]))) &&
				isset($normativeRow[$variable_date]) &&
				($normativeRow[$variable_date] != "")) {

				return array($variable_date, $type);
			}
		}
	}

	return array("", "");
}

# key = instance; value = REDCap data row
function selectFollowupRows($rows) {
	foreach ($rows as $row) {
		if ($row['redcap_repeat_instrument'] == "followup") {
			$followupRows[$row['redcap_repeat_instance']] = $row;
		}
	}
	krsort($followupRows);		// start with most recent survey
	return $followupRows;
}

# find the next maximum for instrument $instrument
function findNextMax($rows, $instrument, $skip) {
	$max = 0;
	foreach ($rows as $rowForNextMax) {
		if (($row['redcap_repeat_instrument'] == $instrument) && ($row['redcap_repeat_instance'] > $max) && (!in_array($row['redcap_repeat_instance'], $skip))) {
			$max = $row['redcap_repeat_instance'];
		}
	}
	return $max;
}

# calculate the primary mentor
function calculatePrimaryMentor($rows) {
	$repeatingForms = array("followup");
	$order = array(
			"override_mentor" => "override",
			"followup_primary_mentor" => "followup",
			"check_primary_mentor" => "scholars",
			"vfrs_mentor1" => "vfrs",
			"newman_data_mentor1" => "data",
			"newman_sheet2_mentor1" => "sheet2",
			"newman_new_mentor1" => "new2017",
			);
	foreach ($order as $field => $src) {
		if (in_array($src, $repeatingForms)) {
			$processed = array();	// keep track of prior instances processed
			$max = findNextMax($rows, $src, $processed);

			// stop when all instances processed   ==> stop when $max == 0
			while ($max) {
				foreach ($rows as $row) {
					if (($row['redcap_repeat_instrument'] == $src) && ($row['redcap_repeat_instance'] == $max)) {
						$processed[] = $row['redcap_repeat_instance'];
						if ($row[$field]) {
							return array($row[$field], $src);
						} else {
							$max = findNextMax($rows, $src, $processed);
						}
						break;
					}
				}
			}
		} else {
			foreach ($rows as $row) { 
			 	if ($row['redcap_repeat_instrument'] == "") {
					# normative row
					if ($row[$field]) {
						return array($row[$field], $src);
					}
					break;
				}
			}
		}
	}
	return array("", "");
}

# calculate when left Vanderbilt
function calculateLeftVanderbilt($row, $rows) {
	$order = array();
	$leftVUAry = findVariableWhenLeftVU($row, $rows);
	$leftVU = $leftVUAry[0];
	$leftVUType = $leftVUAry[1];
	if ($leftVU != "") {
		$order[$leftVU] = $leftVUType;
	}
	$order["newman_data_date_left_vanderbilt"] = "data";
	$order["newman_sheet2_left_vu"] = "sheet2";
	$order["overrides_left_vu"] = "override";
	foreach ($order as $field => $type) {
		if ($row[$field] && preg_match("/^\d\d\d\d-\d\d-\d\d$/", $row[$field])) {
			return array($row[$field], $type);
		}
	}
	return array("", "");
}

# calculate preferred email address
function calculateEmail($normativeRow, $rows) {
	$order = array(
			"override_email" => "override",
			"followup_email" => "followup",
			"check_email" => "scholars",
			"vfrs_email" => "vfrs",
			"newman_data_email" => "data",
			"newman_sheet2_project_email" => "sheet2",
			);
	foreach ($order as $field => $type) {
		if ($type == "followup") {
			foreach ($rows as $row) {
				if ($row['redcap_repeat_instrument'] == "followup") {
					if ($row[$field]) {
						return array(formatEmail($row[$field]), $type);
					}
				}
			}
		} else if ($normativeRow[$field]) {
			return array(formatEmail($normativeRow[$field]), $type);
		}
	}
	return array("", "");
}

# calculates the first degree
function calculateFirstDegree($row) {
	# move over and then down
	$order = array(
			array("override_degrees" => "override"),
			array("check_degree1" => "scholars", "check_degree2" => "scholars", "check_degree3" => "scholars", "check_degree4" => "scholars", "check_degree5" => "scholars"),
			array("vfrs_graduate_degree" => "vfrs", "vfrs_degree2" => "vfrs", "vfrs_degree3" => "vfrs", "vfrs_degree4" => "vfrs", "vfrs_degree5" => "vfrs", "vfrs_please_select_your_degree" => "vfrs"),
			array("newman_new_degree1" => "new2017", "newman_new_degree2" => "new2017", "newman_new_degree3" => "new2017"),
			array("newman_data_degree1" => "data", "newman_data_degree2" => "data", "newman_data_degree3" => "data"),
			array("newman_demographics_degrees" => "demographics"),
			array("newman_sheet2_degree1" => "sheet2", "newman_sheet2_degree2" => "sheet2", "newman_sheet2_degree3" => "sheet2"),
			);

	# combines degrees and sets up for translateFirstDegree
	$value = "";
	$degrees = array();
	foreach ($order as $variables) {
		foreach ($variables as $variable => $type) {
			if ($variable == "vfrs_please_select_your_degree") {
				$row[$variable] = transformSelectDegree($row[$variable]);
			}
			if ($row[$variable] && !in_array($row[$variable], $degrees)) {
				$degrees[] = $row[$variable];
			}
		}
	}
	if (empty($degrees)) {
		return "";
	} else if (in_array(1, $degrees) || in_array(9, $degrees) || in_array(10, $degrees) || in_array(7, $degrees) || in_array(8, $degrees) || in_array(14, $degrees) || in_array(12, $degrees)) { #MD
		if (in_array(2, $degrees) || in_array(9, $degrees) || in_array(10, $degrees)) {
			$value = 10;  # MD/PhD
		} else if (in_array(3, $degrees) || in_array(16, $degrees) || in_array(18, $degrees)) { #MPH
			$value = 7;
		} else if (in_array(4, $degrees) || in_array(7, $degrees)) { #MSCI
			$value = 8;
		} else if (in_array(5, $degrees) || in_array(8, $degrees)) { # MS
			$value = 9;
		} else if (in_array(6, $degrees) || in_array(13, $degrees) || in_array(14, $degrees)) { #Other
			$value = 7;     # MD + other
		} else if (in_array(11, $degrees) || in_array(12, $degrees)) { #MHS
			$value = 12;
		} else {
			$value = 1;   # MD only
		}
	} else if (in_array(2, $degrees)) { #PhD
		if (in_array(11, $degrees)) {
			$value = 10;  # MD/PhD
		} else if (in_array(3, $degrees)) { # MPH
			$value = 2;
		} else if (in_array(4, $degrees)) { # MSCI
			$value = 2;
		} else if (in_array(5, $degrees)) { # MS
			$value = 2;
		} else if (in_array(6, $degrees)) { # Other
			$value = 2;
		} else {
			$value = 2;     # PhD only
		}
	} else if (in_array(6, $degrees)) {  # Other
		if (in_array(1, $degrees)) {   # MD
			$value = 7;  # MD + other
		} else if (in_array(2, $degrees)) {  #PhD
			$value = 2;
		} else {
			$value = 6;
		}
	} else if (in_array(3, $degrees)) {  # MPH
		$value = 6;
	} else if (in_array(4, $degrees)) {  # MSCI
		$value = 6;
	} else if (in_array(5, $degrees)) {  # MS
		$value = 6;
	} else if (in_array(15, $degrees)) {  # PsyD
		$value = 6;
	}
	return $value;
}

# List of reclassifications
# "Internal K" => 1,
# "K12/KL2" => 2,
# "Individual K" => 3,
# "K Equivalent" => 4,
# "R01" => 5,
# "R01 Equivalent" => 6,
function maybeReclassifyIntoType($awardNo) {
	$translate = array(
		"Human Frontiers in Science CDA" => "K Equivalent",
		"VA Merit" => "R01 Equivalent",
		"VA Career Development Award" => "K Equivalent",
		"VA Career Dev. Award" => "K Equivalent",
		"K award VCRS" => "K12/KL2",
		"VACDA" => "K Equivalent",
		"1I01BX00219001" => "R01 Equivalent",
		"1I01BX002223-01A1 (VA Merit)" => "R01 Equivalent",
		"Robert Wood Johnson Faculty Development Award" => "K Equivalent",
		"VPSD" => "Internal K",
		"VCRS" => "K12/KL2",
		"ACS Scholar (R01-equiv)" => "K Equivalent",
		"Major DOD award" => "R01 Equivalent",
		"Dermatology Foundation Physician-Scientist Career Development Award" => "K Equivalent",
		"Damon Runyon Cancer Research Foundation Clinical Investigator award" => "K Equivalent",
		"LUNGevity CDA" => "K Equivalent",
		"ACS Scholar Award" => "K Equivalent",
		"VCRS" => "K12/KL2",
		"VA Career Dev" => "K Equivalent",
		"Peds K12" => "K12/KL2",
		"VA Merit Award" => "R01 Equivalent",
		"BIRCWH: ACS Mentored Research Scholar Grant" => "K12/KL2",
		"AHA FtF" => "K Equivalent",
		"AHA FtF|1R01HL128983-01A1" => "K Equivalent",
		"DOD grant (R01-equivalent)" => "R01 Equivalent",
		"Burroughs Wellcome CAMS 2013" => "K Equivalent",
		"VCTRS" => "Internal K",
		"1IK2BX002498-01_(VACDA)" => "K Equivalent",
		"VCORCDP" => "K12/KL2",
		"VCORCPD" => "K12/KL2",
		"VCRS/VCTRS" => "Internal K",
		"VEHSS" => "K12/KL2",
		"VPSD" => "Internal K",
		"VPSD - P30 ARRA" => "Internal K",
		"VPSD" => "Internal K",
		"VEHSS" => "K12/KL2",
		"K award" => "Individual K",
		"VFRS" => "Internal K",
		"VCRS" => "Internal K",
		"1IK2BX002126-01 (VA CDA)" => "K Equivalent",
		"1U01HG004798-01" => "R01 Equivalent",
		"VPSD" => "Internal K",
		"Kaward" => "Individual K",
		"K 99" => "Individual K",
		"NIEHS" => "K12/KL2",
		"1U01CA182364-01(MPI)" => "R01 Equivalent",
		"VPSD" => "Internal K",
		"1IK2BX001701-01A2 (VA CDA)" => "K Equivalent",
		"VA CDA" => "K Equivalent",
		"1U01CA143072-01 (MPI)" => "R01 Equivalent",
		"1IK2HX000758-01 (VA CDA)" => "K Equivalent",
		"VEMRT" => "K12/KL2",
		"1U01IP000464-01" => "R01 Equivalent",
		"VPSD" => "Internal K",
		"LUNGevity Foundation" => "K Equivalent",
		"VICMIC" => "K12/KL2",
		"V-POCKET" => "K12/KL2",
		"BIRCWH" => "K12/KL2",
		"1UM1CA186704-01" => "R01 Equivalent",
		"5P50CA098131-12" => "R01 Equivalent",
		"1IK2HX000988-01A1 (VACDA)" => "Individual K",
		"1IK2BX002929-01A2" => "K Equivalent",
		"VA CDA" => "K Equivalent",
		"NASPGHAN-CDHNF Fellow to Faculty Transition Award in Inflammatory Bowel Diseases" => "K Equivalent",
		"Robert Wood Johnson Faculty Development Award" => "K Equivalent",
		"VACDA" => "K Equivalent",
		"VCTRS-RWJF" => "Internal K",
		"VACDA" => "K Equivalent",
		"PhARMA foundation award" => "K Equivalent",
		"1U01AI096186-01 (MPI)" => "R01 Equivalent",
		"1U2GGH000812-01" => "R01 Equivalent",
		"5IK2BX002797-02" => "K Equivalent",
		"1RC4MH092755-01" => "R01 Equivalent",
	);

	if (isset($translate[$awardNo])) {
		return $translate[$awardNo];
	}

	return "";
}

# translates from innate ordering into new categories in summary_degrees
function translateFirstDegree($num) {
	$translate = array(
			"" => "",
			1 => 1,
			2 => 4,
			6 => 6,
			7 => 3,
			8 => 3,
			9 => 3,
			10 => 2,
			11 => 2,
			12 => 3,
			13 => 3,
			14 => 6,
			15 => 3,
			16 => 6,
			17 => 6,
			18 => 6,
			3 => 6,
			4 => 6,
			5 => 6,
			);
	return $translate[$num];
}

# as name states
function calculateGender($row) {
	$order = array(
			"override_gender" => "override",
			"check_gender" => "scholars",
			"vfrs_gender" => "vfrs",
			"newman_new_gender" => "new2017",
			"newman_demographics_gender" => "demographics",
			"newman_data_gender" => "data",
			"newman_nonrespondents_gender" => "nonrespondents",
			);

	$tradOrder = array("override_gender", "check_gender");
	foreach ($order as $variable => $type) {
		if ($row[$variable] !== "") {
			if (in_array($variable, $tradOrder)) {
				return array($row[$variable], $type);
			}
			if ($row[$variable] == 1) {  # Male
				return array(2, $type);
			} else if ($row[$variable] == 2) {   # Female
				return array(1, $type);
			}
			# forget no-reports and others
		}
	}
	return array("", "");
}

# returns array of 3 (overall classification, race type, ethnicity type)
function calculateAndTranslateRaceEthnicity($row) {
	$orderRace = array(
				"override_race" => "override", 
				"check_race" => "scholars", 
				"vfrs_race" => "vfrs", 
				"newman_new_race" => "new2017",
				"newman_demographics_race" => "demographics",
				"newman_data_race" => "data",
				"newman_nonrespondents_race" => "nonrespondents",
				);
	$orderEth = array(	
				"override_ethnicity" => "override",
				"check_ethnicity" => "scholars",
				"vfrs_ethnicity" => "vfrs",
				"newman_new_ethnicity" => "new2017",
				"newman_demographics_ethnicity" => "demographics",
				"newman_data_ethnicity" => "data",
				"newman_nonrespondents_ethnicity" => "nonrespondents",
				);
	$race = "";
	$raceType = "";
	foreach ($orderRace as $variable => $type) {
		if (($row[$variable] !== "") && ($row[$variable] != 8)) {
			$race = $row[$variable];
			$raceType = $type;
			break;
		}
	}
	if ($race === "") {
		return array("", "", "");
	}
	$eth = "";
	$ethType = "";
	foreach ($orderEth as $variable => $type) {
		if (($row[$variable] !== "") && ($row[$variable] != 4)) {
			$eth = $row[$variable];
			$ethType = $type;
			break;
		}
	}
	$val = "";
	if ($race == 2) {   # Asian
		$val = 5;
	}
	if ($eth == "") {
		return array("", "", "");
	}
	if ($eth == 1) { # Hispanic
		if ($race == 5) { # White
			$val = 3;
		} else if ($race == 4) { # Black
			$val = 4;
		}
	} else if ($eth == 2) {  # non-Hisp
		if ($race == 5) { # White
			$val = 1;
		} else if ($race == 4) { # Black
			$val = 2;
		}
	}
	if ($val === "") {
		$val = 6;  # other
	}
	return array($val, $raceType, $ethType);
}

# convert date
function convertToYYYYMMDD($date) {
	$nodes = preg_split("/[\-\/]/", $date);
	if (($nodes[0] == 0) || ($nodes[1] == 0)) {
		return "";
	}
	if ($nodes[0] > 1900) {
		return $nodes[0]."-".$nodes[1]."-".$nodes[2];
	}
	if ($nodes[2] < 1900) {
		if ($nodes[2] < 20) {
			$nodes[2] = 2000 + $nodes[2];
		} else {
			$nodes[2] = 1900 + $nodes[2];
		}
	}
	// from MDY
	return $nodes[2]."-".$nodes[0]."-".$nodes[1];
}

# finds date-of-birth
function calculateDOB($row) {
	$order = array(
			"check_date_of_birth" => "scholars",
			"vfrs_date_of_birth" => "vfrs",
			"newman_new_date_of_birth" => "new2017",
			"newman_demographics_date_of_birth" => "demographics",
			"newman_data_date_of_birth" => "data",
			"newman_nonrespondents_date_of_birth" => "nonrespondents",
			);

	foreach ($order as $variable => $type) {
		if ($row[$variable] !== "") {
			$date = convertToYYYYMMDD($row[$variable]);
			if ($date) {
				return array($date, $type);
			}
		}
	}
	return array("", "");
}

# VFRS did not use the 6-digit classification, so we must translate
function transferVFRSDepartment($dept) {
	$exchange = array(
				1	=> 104300,
				2	=> 104250,
				3	=> 104785,
				4	=> 104268,
				5	=> 104286,
				6	=> 104705,
				7	=> 104280,
				8	=> 104791,
				9	=> 999999,
				10	=> 104782,
				11	=> 104368,
				12	=> 104270,
				13	=> 104400,
				14	=> 104705,
				15	=> 104425,
				16	=> 104450,
				17	=> 104366,
				18	=> 104475,
				19	=> 104781,
				20	=> 104500,
				21	=> 104709,
				22	=> 104595,
				23	=> 104290,
				24	=> 104705,
				25	=> 104625,
				26	=> 104529,
				27	=> 104675,
				28	=> 104650,
				29	=> 104705,
				30	=> 104726,
				31	=> 104775,
				32	=> 999999,
				33	=> 106052,
				34	=> 104400,
				35	=> 104353,
				36	=> 120430,
				37	=> 122450,
				38	=> 120660,
				39	=> 999999,
				40	=> 104705,
				41	=> 104366,
				42	=> 104625,
				43	=> 999999,
			);
	if (isset($exchange[$dept])) {
		return $exchange[$dept];
	}
	return "";
}

# calculates a secondary department
# not used because primary department is only one needed
# may be out of date
function calculateSecondaryDept($row) {
	$order = array(
			"newman_demographics_department2" => "demographics",
			"newman_data_departments2" => "data",
			"newman_sheet2_department2" => "sheet2",
			"vfrs_department" => "vfrs",
			"newman_new_department" => "new2017",
			"newman_demographics_department1" => "demographics",
			"newman_data_department1" => "data",
			"newman_sheet2_department1" => "sheet2",
			);

	$ary = calculatePrimaryDept($row);
	$primDept = $ary[0];
	foreach ($order as $variable => $type) {
		$dept = $row[$variable];
		if (preg_match("/^vfrs_/", $variable)) {
			$dept = transferVFRSDepartment($row[$variable]);
		}
		if (($row[$variable] !== "") && ($primDept != $dept)) {
			return array($dept, $type);
		}
	}
	return array("", "");
}

# calculates a primary department
function calculatePrimaryDept($row) {
	$order = array(
			"override_department1" => "override",
			"check_primary_dept" => "scholars",
			"vfrs_department" => "vfrs",
			"newman_new_department" => "new2017",
			"newman_demographics_department1" => "demographics",
			"newman_data_department1" => "data",
			"newman_sheet2_department1" => "sheet2",
			);
	$default = array();
	$overridable = array(104368 => 104366, 999999 => "ALL");

	foreach ($order as $variable => $type) {
		$dept = $row[$variable];
		if (preg_match("/^vfrs_/", $variable)) {
			$dept = transferVFRSDepartment($row[$variable]);
		}
		if ($row[$variable] !== "") {
			if (empty($default)) {
				$default = array($dept, $type);
			} else {
				foreach ($overridable as $oldNo => $newNo) {
					if ($oldNo == $default[0]) {
						if (($newNo == "ALL") || ($newNo == $dept)) {
							$default = array($dept, $type);
						}
					}
				}
			}
			
		}
	}
	if (empty($default)) {
		return array("", "");
	}
	return $default;
}

# calculates if citizenship asserted
function calculateCitizenship($row) {
	$order = array(
			"check_citizenship" => "scholars",
			);

	foreach ($order as $variable => $type) {
		if ($row[$variable] !== "") {
			return array($row[$variable], $type);
		}
	}
	return array("", "");
}

# calculate current rank of academic appointment
function calculateAppointments($normativeRow, $rows) {
	$followupRows = selectFollowupRows($rows);
	$outputRow = array();
	$calculatePrevious = false;

	# setup search variables
	$vars = array(
			"followup_primary_dept" => array("summary_primary_dept", "followup"),
			"check_primary_dept" => array("summary_primary_dept", "scholars"),
			"followup_division" => array("summary_current_division", "followup"),
			"check_division" => array("summary_current_division", "scholars"),
			"override_rank" => array("summary_current_rank", "override"),
			"followup_academic_rank" => array("summary_current_rank", "followup"),
			"check_academic_rank" => array("summary_current_rank", "scholars"),
			"newman_new_rank" => array("summary_current_rank", "new2017"),
			"followup_academic_rank_dt" => array("summary_current_start", "followup"),
			"check_academic_rank_dt" => array("summary_current_start", "scholars"),
			"followup_tenure_status" => array("summary_current_tenure", "followup"),
			"check_tenure_status" => array("summary_current_tenure", "scholars"),
			);
	if ($calculatePrevious) {
		for ($i = 1; $i <= 5; $i++) {
			$vars['check_prev'.$i.'_primary_dept'] = array("summary_prev".$i."_dept", "scholars");
			$vars['check_prev'.$i.'_division'] = array("summary_prev".$i."_division", "scholars");
			$vars['check_prev'.$i.'_institution'] = array("summary_prev".$i."_institution", "scholars");
			$vars['check_prev'.$i.'_academic_rank'] = array("summary_prev".$i."_rank", "scholars");
			$vars['check_prev'.$i.'_academic_rank_stdt'] = array("summary_prev".$i."_start", "scholars");
			$vars['check_prev'.$i.'_academic_rank_enddt'] = array("summary_prev".$i."_end", "scholars");
		}
		for ($i = 1; $i <= 5; $i++) {
			$vars['followup_prev'.$i.'_primary_dept'] = array("summary_prev".$i."_dept", "followup");
			$vars['followup_prev'.$i.'_division'] = array("summary_prev".$i."_division", "followup");
			$vars['followup_prev'.$i.'_institution'] = array("summary_prev".$i."_institution", "followup");
			$vars['followup_prev'.$i.'_academic_rank'] = array("summary_prev".$i."_rank", "followup");
			$vars['followup_prev'.$i.'_academic_rank_stdt'] = array("summary_prev".$i."_start", "followup");
			$vars['followup_prev'.$i.'_academic_rank_enddt'] = array("summary_prev".$i."_end", "followup");
		}
	}

	# fill
	if ($row['check_institution'] != "") {
		$outVar = "summary_current_institution";
		if ($row['check_institution'] == 1) {
			$outputRow[$outVar] = array("Vanderbilt", "scholars");
		} else if ($row['check_institution'] == 2) {
			$outputRow[$outVar] = array("Meharry", "scholars");
		} else if ($row['check_institution_oth']) {
			$outputRow[$outVar] = array($row['check_institution_oth'], "scholars");
		} else {
			$outputRow[$outVar] = array("Other", "scholars");
		}
	}
	$indexedRows = array();
	foreach ($vars as $src => $ary) {
		$dest = $ary[0];
		$srcType = $ary[1];
		if ($srcType == "followup") {
			foreach ($followupRows as $instance => $row) {
				# only use most recent follow-up survey
				$indexedRows[$src] = $row;
				break;
			}
		} else {
			$indexedRows[$src] = $normativeRow;
		}
	}
	foreach ($vars as $src => $ary) {
		$dest = $ary[0];
		$srcType = $ary[1];
		$row = $indexedRows[$src];
		if ($row[$src] != "") {
			if (preg_match("/_end$/", $dest) || preg_match("/_start$/", $dest)) {
				$outputRow[$dest] = array(convertToYYYYMMDD($row[$src]), $srcType);
			} else {
				$seen = false;
				foreach ($vars as $src2 => $ary2) {
					$dest2 = $ary2[0];
					$srcType2 = $ary2[1];
					if ($src2 == $src) {
						break;
					}
					if ($dest2 == $dest) {
						# before src2 == src matched
						$seen = true;
					}
				}
				if (!$seen) {
					$outputRow[$dest] = array($row[$src], $srcType);
				} else if (!isset($outputRow[$dest]) || ($outputRow[$dest] == "")) {
					# blank => overwrite
					$outputRow[$dest] = array($row[$src], $srcType);
				}
			}
		}
	}

	return $outputRow;
}

# Finds the type of the CDA for the award at $index
function calculateCDAType($data, $row, $index) {
	global $echoToScreen, $br;
	$awardTypes = getAwardTypes();
	$cdav = getOrderedCDAVariables($data, $row['record_id']);
	$variables = $cdav[0];
	if (count($variables) <= $index) {
		return array($awardTypes["N/A"], getBlankSpecs());
	}
	if ($index < 0) {
		return array($awardTypes["N/A"], getBlankSpecs());
	}
	$skip = array("modify", "custom");
	if (in_array($variables[$index]["source"], $skip)) {
		$awardType = array($variables[$index]["redcap_type"], $variables[$index]);
	} else {
		// $echoToScreen .= "calculateCDAType 1: ".json_encode($variables[$index])."$br";
		$awardType = calculateAwardType($variables[$index]);
		// $echoToScreen .= "calculateCDAType 2: ".json_encode($awardType)."$br";
	}
	if (isset($awardTypes[$awardType[0]])) {
		return array($awardTypes[$awardType[0]], $awardType[1]);
	}
	return array($awardTypes["N/A"], getBlankSpecs());
}

function makeUpper($str) {
	$uclist = "\t\r$br\f\v(-";
	$str = ucwords($str, $uclist);
	while (preg_match("/ [a-z]\.? /", $str, $matches)) {
		$orig = $matches[0];
		$upper = strtoupper($orig);
		$str = str_replace($orig, $upper, $str);
	}
	if (preg_match("/ [a-z]\.?$/", $str, $matches)) {
		$orig = $matches[0];
		$upper = strtoupper($orig);
		$str = str_replace($orig, $upper, $str);
	}
	return $str;
}

# Returns the COEUS person-name for the first instance that contains it inside of Record $recordId
# Assume $data is ordered, as in downloaded REDCap data
function getCoeusPersonName($recordId, $data) {
	foreach ($data as $row) {
		if (($row['record_id'] == $recordId) && isset($row['redcap_repeat_instrument']) && ($row['redcap_repeat_instrument'] == "coeus") && $row['coeus_person_name']) {
			return $row['coeus_person_name'];
		}
	}
	return "";
}

# converted to R01/R01-equivalent in $row for $typeOfK ("any" vs. "external")
# return value:
# 		1, Converted K to R01-or-Equivalent While on K
#		2, Converted K to R01-or-Equivalent Not While on K
#		3, Still On K; No R01-or-Equivalent
#		4, Not On K; No R01-or-Equivalent
#		5, No K, but R01-or-Equivalent
#		6, No K; No R01-or-Equivalent
function converted($row, $typeOfK) {
	if ($row['summary_'.$typeOfK.'_k'] && $row['summary_first_r01']) {
		if (preg_match('/last_/', $typeOfK)) {
			$prefix = "last";
		} else {
			$prefix = "first";
		}
		if ($row['summary_'.$prefix.'_external_k'] == $row['summary_'.$prefix.'_any_k']) {
			if (datediff($row['summary_'.$typeOfK.'_k'], $row['summary_first_r01'], "y") <= 5) {
				return 1;
			} else {
				return 2;
			}
		} else {
			if (datediff($row['summary_'.$typeOfK.'_k'], $row['summary_first_r01'], "y") <= 3) {
				return 1;
			} else {
				return 2;
			}
		}
	} else if ($row['summary_'.$typeOfK.'_k']) {
		if (preg_match('/last_/', $typeOfK)) {
			$prefix = "last";
		} else {
			$prefix = "first";
		}
		if ($row['summary_".$prefix."_external_k'] == $row['summary_".$prefix."_any_k']) {
			if (datediff($row['summary_'.$typeOfK.'_k'], date('Y-m-d'), "y") <= 5) {
				return 3;
			} else {
				return 4;
			}
		} else {
			if (datediff($row['summary_'.$typeOfK.'_k'], date('Y-m-d'), "y") <= 3) {
				return 3;
			} else {
				return 4;
			}
		}
	} else {
		if ($row['summary_first_r01']) {
			return 5;
		} else {
			return 6;
		}
	}
}

# downloads the record id's for a list
# get only the records modified within the last week
$data = array(
	'token' => $token,
	'content' => 'record',
	'format' => 'json',
	'fields' => array('record_id'),
	'type' => 'flat',
	'rawOrLabel' => 'raw',
	'rawOrLabelHeaders' => 'raw',
	'exportCheckboxLabel' => 'false',
	'exportSurveyFields' => 'false',
	'exportDataAccessGroups' => 'false',
	'returnFormat' => 'json',
);
//	'dateRangeBegin' => date("Y-m-d h:i:s", time() - 3600 * 7),
if ($selectRecord) {
	$data['records'] = array($selectRecord);
}
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $server);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_VERBOSE, 0);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_AUTOREFERER, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
$output = curl_exec($ch);
curl_close($ch);

$rows = json_decode($output, true);
if (!$rows || empty($rows)) {
	echo "output: ".$output.$br;
}
$recordIds = array();
foreach ($rows as $row) {
	$recordId = $row['record_id'];
	if (!in_array($recordId, $recordIds)) {
		$recordIds[] = $recordId;
	}
}

$echoToScreen .= count($recordIds)." record ids (max ".max($recordIds).").$br";
if (count($recordIds) == 0) {
	$echoToScreen .= "$output$br";
}

unset($output);
unset($rows);

# uses all of the above functions to assign to REDCap variables
# done in batches
$total = array();
$found = array();
$total['demographics'] = 0; $found['demographics'] = 0;
$total['CDAs'] = 0; $found['CDAs'] = 0;
$cdaType = array();
for ($i = 1; $i <= 7; $i++) {
	$cdaType[$i] = 0;
}
$cdaType[99] = 0;
$manuals = array();
$awards = array();

$echoToScreen .= "Changing metadata (downloading)...$br";
$data = array(
	'token' => $token,
	'content' => 'metadata',
	'format' => 'json',
	'returnFormat' => 'json'
);
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $server);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_VERBOSE, 0);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_AUTOREFERER, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
$output = curl_exec($ch);
curl_close($ch);

$metadata = json_decode($output, true);
$awardTypes = getAwardTypes();
$awardTypesIntermediate = array();
foreach ($awardTypes as $label => $value) {
	$awardTypesIntermediate[] = $value.", ".$label;
}
$awardTypesStr = implode(" | ", $awardTypesIntermediate);
$i = 0;
foreach ($metadata as $row) {
	if (preg_match("/^summary_award_type/", $row['field_name'])) {
		$metadata[$i]['select_choices_or_calculations'] = $awardTypesStr;
	}
	$i++;
}

$echoToScreen .= "Changing metadata (uploading)...$br";
$data = array(
	'token' => $token,
	'content' => 'metadata',
	'format' => 'json',
	'data' => json_encode($metadata),
	'returnFormat' => 'json'
);
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $server);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_VERBOSE, 0);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_AUTOREFERER, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
$output = curl_exec($ch);
print $output."$br";
curl_close($ch);

# done in batches of 10 records
$pullSize = 10;
$types = array("fill");
$numPulls = ceil(max($recordIds) / $pullSize);
if ($selectRecord) {
	$numPulls = 1;
}
for ($pull = 0; $pull < $numPulls; $pull++) {
	$records = array();
	$thisPullSize = $pullSize;
	if (max($recordIds) < $thisPullSize * ($pull + 1)) {
		$thisPullSize = max($recordIds) % $pullSize;
	}
	if ($selectRecord) {
		$records[] = $selectRecord;
	} else {
		for ($j = 0; $j < $thisPullSize; $j++) {
			$records[] = $pull * $pullSize + $j + 1;
		}
	}

	$echoToScreen .= ($pull + 1).") Getting data ".json_encode($records)."$br";
	$data = array(
		'token' => $token,
		'content' => 'record',
		'format' => 'json',
		'type' => 'flat',
		'records' => $records,
		'rawOrLabel' => 'raw',
		'rawOrLabelHeaders' => 'raw',
		'exportCheckboxLabel' => 'false',
		'exportSurveyFields' => 'false',
		'exportDataAccessGroups' => 'false',
		'returnFormat' => 'json'
	);
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $server);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_VERBOSE, 0);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_AUTOREFERER, true);
	curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
	curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
	$output = curl_exec($ch);
	curl_close($ch);
	$redcapData = json_decode($output, true);

	$echoToScreen .= "Pull ".($pull + 1)." downloaded ".count($redcapData)." rows of REDCap data.$br";

	foreach ($types as $type) {
		$i = 0;
		$newData = array();
		// $echoToScreen .= "$type: Making data set$br";
		$maxAwards = 15;
		$summaryGrants = calculateYearlyGrants($redcapData);
		// $echoToScreen .= "Summary Grants: ".count($summaryGrants)." on this pull$br";
		$redcapDataRows = array();
		foreach ($redcapData as $row) {
			if (!isset($redcapDataRows[$row['record_id']])) {
				$redcapDataRows[$row['record_id']] = array();
			}
			$redcapDataRows[$row['record_id']][] = $row;
		}

		foreach ($redcapData as $row) {
			if (isset($row['redcap_repeat_instrument']) && ($row['redcap_repeat_instrument'] == "custom_grant")) {
				$newData[] = $row;
			} else if (isset($row['redcap_repeat_instrument']) && ($row['redcap_repeat_instrument'] == "summary_grants")) {
				$row2 = $row;
				foreach ($row2 as $field => $value) {
					if (preg_match("/^summary_grants/", $field)) {
						$row2[$field] = "";
					}
				}
				$newData[] = $row2;
			} else if (isset($row['redcap_repeat_instrument']) && ($row['redcap_repeat_instrument'] == "reporter")) {
				$newData[] = $row;
			} else if (isset($row['redcap_repeat_instrument']) && ($row['redcap_repeat_instrument'] == "exporter")) {
				$newData[] = $row;
			} else if (isset($row['redcap_repeat_instrument']) && ($row['redcap_repeat_instrument'] == "followup")) {
				$newData[] = $row;
			} else if (isset($row['redcap_repeat_instrument']) && ($row['redcap_repeat_instrument'] == "coeus")) {
				$newData[] = $row;
			} else if (!isset($row['redcap_repeat_instrument']) || ($row['redcap_repeat_instrument'] == "")) {
				if ($type == "fill") {
					$comments = array();

					$awardsSeen = array();
					$awardsCategorized = array();

					$na = 99;
					$row2 = $row;
					$row2['summary_last_calculated'] = date("Y-m-d H:i");
					if ($row['check_name_first'] || $row['check_name_last']) {
						$row2['summary_survey'] = 1;
					} else {
						$row2['summary_survey'] = 0;
					}

					if ($row['identifier_vunet']) {
						$row2['identifier_vunet'] = strtolower($row['identifier_vunet']);
					} else if ($row['vunetid']) {
						$row2['identifier_vunet'] = strtolower($row['vunetid']);
					} else if ($row['vfrs_vunet_id']) {
						$row2['identifier_vunet'] = strtolower($row['vfrs_vu_net_id']);
					} else {
						$row2['identifier_vunet'] = "";
					}

					$row2['identifier_coeus'] = getCoeusPersonName($row['record_id'], $redcapData);
					// $ary = calculateEmail($row, $redcapDataRows[$row['record_id']]);
					// $row2['identifier_email'] = $ary[0];
					// $row2['identifier_email_source'] = $ary[1];
					// $total['demographics']++; if ($row2['identifier_email']) { $found['demographics']++; }

					$ary = calculateCitizenship($row);
					$row2['summary_citizenship'] = $ary[0];
					$row2['summary_citizenship_source'] = $ary[1];
					$total['demographics']++; if ($row2['summary_citizenship']) { $found['demographics']++; }

					# Appointments
					$values = calculateAppointments($row2);
					foreach ($values as $variable => $ary) {
						$value = $ary[0];
						$source = $ary[1];
						if (!isset($comments[$variable])) {
							$row2[$variable] = $value;
						}
						$row2[$variable."_source"] = $source;
						$total['demographics']++; if ($row2[$variable]) { $found['demographics']++; }
					}

					# Grants
					$j = 0;
					$cdav = getOrderedCDAVariables($redcapData, $row['record_id']);
					$variables = $cdav[0];
					$listOfAwards = $cdav[1];
					// $echoToScreen .= "CDAVariables {$row['record_id']}: ".count($variables).": ".json_encode($variables)."$br";
					$row2['summary_calculate_order'] = json_encode($variables);
					$row2['summary_calculate_list_of_awards'] = json_encode($listOfAwards);
					$row2['summary_calculate_to_import'] = $row2['summary_calculate_to_import'];
					for ($i = 1; $i <= $maxAwards; $i++) {
						$awardType = array($na);
						while (($awardType[0] == $na) && (count($variables) > $j)) {
							$awardType = calculateCDAType($redcapData, $row, $j);
							if ($awardType[0] == $na) {
								$echoToScreen .= "precalculateCDADate {$row['record_id']} {$row['identifier_first_name']} {$row['identifier_last_name']}: $j Award is N/A. ".json_encode($awardType)."$br";
								$echoToScreen .= "$br";
							}
							$j++;
						}
						if ($awardType[1]['redcap_type'] != $na) {
							$specs = $awardType[1];
							// $echoToScreen .= "{$row['record_id']} ADDING $i: ".json_encode($specs)."$br";
							if (!isset($comments['summary_award_date_'.$i])) {
								$row2['summary_award_date_'.$i] = $specs['start_date'];
								if (isset($specs['end_date'])) {
									$row2['summary_award_end_date_'.$i] = $specs['end_date'];
								} else {
									$row2['summary_award_end_date_'.$i] = "";
								}
							}
							if ($i == 1) {
								$total['CDAs']++;
								if ($row2['summary_award_date_'.$i]) {
									$found['CDAs']++;
								}
							}
							if (!isset($comments['summary_award_type_'.$i])) {
								$row2['summary_award_type_'.$i] = $awardTypes[$specs['redcap_type']];
							}
							if ($i == 1) {
								$cdaType[$row2['summary_award_type_'.$i]]++;
							}
							if (!isset($comments['summary_award_sponsorno_'.$i])) {
								$row2['summary_award_sponsorno_'.$i] = $specs['sponsor_award_no'];
							}
							if (!isset($comments['summary_award_nih_mechanism_'.$i])) {
								if (!$specs['nih_mechanism']) {
									$row2['summary_award_nih_mechanism_'.$i] = getMechanismFromData($row2['record_id'], $specs['sponsor_award_no'], $redcapData);
								} else {
									$row2['summary_award_nih_mechanism_'.$i] = $specs['nih_mechanism'];
								}
							}
							if (!isset($comments['summary_award_percent_effort_'.$i])) {
								$row2['summary_award_percent_effort_'.$i] = $specs['percent_effort'];
							}
							if (!isset($comments['summary_award_budget_'.$i])) {
								$row2['summary_award_budget_'.$i] = $specs['budget'];
							}
							if (isset($specs['source'])) {
								$scholars = array("check");
								if (in_array($specs['source'], $scholars)) {
									$row2['summary_award_source_'.$i] = "scholars";
								} else {
									$row2['summary_award_source_'.$i] = $specs['source'];
								}
							} else {
								$row2['summary_award_source_'.$i] = "";
							}
							if ($i == 1) {
								$total['CDAs']++;
								if ($row2['summary_award_type_'.$i] != $na) {
									$found['CDAs']++;
								}
							}
						} else {
							if (!isset($comments['summary_award_date_'.$i])) {
								$row2['summary_award_date_'.$i] = "";
							}
							if (!isset($comments['summary_award_end_date_'.$i])) {
								$row2['summary_award_end_date_'.$i] = "";
							}
							if (!isset($comments['summary_award_type_'.$i])) {
								$row2['summary_award_type_'.$i] = $na;
							}
							$row2['summary_award_source_'.$i] = "";
							if ($i == 1) {
								$cdaType[$row2['summary_award_type_'.$i]]++;
							}
							if (!isset($comments['summary_award_sponsorno_'.$i])) {
								$row2['summary_award_sponsorno_'.$i] = "";
							}
							if (!isset($comments['summary_award_nih_mechanism_'.$i])) {
								$row2['summary_award_nih_mechanism_'.$i] = "";
							}
							if (!isset($comments['summary_award_percent_effort_'.$i])) {
								$row2['summary_award_percent_effort_'.$i] = "";
							}
							if (!isset($comments['summary_award_budget_'.$i])) {
								$row2['summary_award_budget_'.$i] = "";
							}
						}
					}
					$row2['summary_ever_internal_k'] = 0;
					$row2['summary_ever_individual_k_or_equiv'] = 0;
					$row2['summary_ever_k12_kl2'] = 0;
					$row2['summary_ever_r01_or_equiv'] = 0;
					$row2['summary_first_external_k'] = "";
					$row2['summary_first_any_k'] = "";
					$row2['summary_last_any_k'] = "";
					$row2['summary_first_r01'] = "";
					for ($i = 1; $i <= $maxAwards; $i++) {
						$t = $row2['summary_award_type_'.$i];
						if ($row2['override_first_r01'] !== "") {
							$row2['summary_first_r01'] = $row2['override_first_r01'];
							$row2['summary_first_r01_source'] = "override";
							$row2['summary_ever_r01_or_equiv'] = 1;
						}
						if ($t == 1) {
							$row2['summary_ever_internal_k'] = 1;
						} else if ($t == 2) {
							$row2['summary_ever_k12_kl2'] = 1;
						} else if (($t == 3) || ($t == 4)) {
							$row2['summary_ever_individual_k_or_equiv'] = 1;
						} else if (($t == 5) || ($t == 6)) {
							if ($row2['summary_first_r01'] == "") {
								$row2['summary_first_r01'] = $row2['summary_award_date_'.$i];
								$row2['summary_first_r01_type'] = $row2['summary_award_type_'.$i];
								$row2['summary_first_r01_source'] = $row2['summary_award_source_'.$i];
							}
							$row2['summary_ever_r01_or_equiv'] = 1;
						}

						$externalKs = array(3, 4);
						$Ks = array(1, 2, 3, 4);
						if (in_array($t, $externalKs) && !$row2['summary_first_external_k']) {
							$row2['summary_first_external_k'] = $row2['summary_award_date_'.$i];
							$row2['summary_first_external_k_source'] = $row2['summary_award_source_'.$i];
						}
						if (in_array($t, $externalKs)) {
							$row2['summary_last_external_k'] = $row2['summary_award_date_'.$i];
							$row2['summary_last_external_k_source'] = $row2['summary_award_source_'.$i];
						}
						if (in_array($t, $Ks) && !$row2['summary_first_any_k']) {
							$row2['summary_first_any_k'] = $row2['summary_award_date_'.$i];
							$row2['summary_first_any_k_source'] = $row2['summary_award_source_'.$i];
						}
						if (in_array($t, $Ks)) {
							$row2['summary_last_any_k'] = $row2['summary_award_date_'.$i];
							$row2['summary_last_any_k_source'] = $row2['summary_award_source_'.$i];
						}
					}
					$row2 = reworkAwardEndDates($row2);
					$row2['summary_ever_external_k_to_r01_equiv'] = converted($row2, "first_external");
					$row2['summary_ever_last_external_k_to_r01_equiv'] = converted($row2, "last_external");
					$row2['summary_ever_first_any_k_to_r01_equiv'] = converted($row2, "first_any");
					$row2['summary_ever_last_any_k_to_r01_equiv'] = converted($row2, "last_any");
					if (!isset($comments['summary_degrees'])) {
						$val = calculateFirstDegree($row);
						$val = translateFirstDegree($val);
						$row2['summary_degrees'] = $val;
					}
					$total['demographics']++; if ($row2['summary_degrees']) { $found['demographics']++; }
					if (!isset($comments['summary_gender'])) {
						$ary = calculateGender($row);
						$row2['summary_gender'] = $ary[0];
						$row2['summary_gender_source'] = $ary[1];
					}
					$total['demographics']++; if ($row2['summary_gender']) { $found['demographics']++; }
					if (!isset($comments['summary_mentor'])) {
						$ary = calculatePrimaryMentor($redcapDataRows[$row['record_id']]);
						$row2['summary_mentor'] = $ary[0];
						$row2['summary_mentor_source'] = $ary[1];
					}
					$total['demographics']++; if ($row2['summary_']) { $found['demographics']++; }
					if (!isset($comments['summary_race_ethnicity'])) {
						$ary = calculateAndTranslateRaceEthnicity($row);
						$row2['summary_race_ethnicity'] = $ary[0];
						$row2['summary_race_source'] = $ary[1];
						$row2['summary_ethnicity_source'] = $ary[2];
					}
					$total['demographics']++; if ($row2['summary_race_ethnicity']) { $found['demographics']++; }
					if (!isset($comments['summary_dob'])) {
						$ary = calculateDOB($row);
						$row2['summary_dob'] = $ary[0];
						$row2['summary_dob_source'] = $ary[1];
					}
					$total['demographics']++; if ($row2['summary_dob']) { $found['demographics']++; }
					if (!isset($comments['summary_primary_dept'])) {
						$ary = calculatePrimaryDept($row);
						$row2['summary_primary_dept'] = $ary[0];
						$row2['summary_primary_dept_source'] = $ary[1];
					}
					$total['demographics']++; if ($row2['summary_primary_dept']) { $found['demographics']++; }
					if (!isset($comments['summary_left_vanderbilt'])) {
						$ary = calculateLeftVanderbilt($row, $redcapDataRows[$row['record_id']]);
						$row2['summary_left_vanderbilt'] = $ary[0];
						$row2['summary_left_vanderbilt_source'] = $ary[1];
					}
					$selfReported = array("scholars", "vfrs");
					$newman = array( "data", "sheet2", "demographics", "new2017", "k12", "nonrespondents", "override" );
					foreach ($row2 as $field => $value) {
						$newfield = "";
						if (preg_match("/_source$/", $field)) {
							$newfield = $field."type";
						} else if (preg_match("/summary_award_source_/", $field)) {
							$newfield = preg_replace("/summary_award_source_/", "summary_award_sourcetype_", $field);
						}
						if ($newfield) {
							$newvalue = "";
							if ($value == "") {
								$newvalue = "";
							} else if (in_array($value, $selfReported)) {
								$newvalue = "1";
							} else if (in_array($value, $newman)) {
								$newvalue = "2";
							} else {
								$newvalue = "0";
							}
							$row2[$newfield] = $newvalue;
						}
					}
					$newData[] = $row2;
				} else if ($type == "clear") {
					$row2 = array();
					foreach ($row as $field => $value) {
						if (preg_match("/^summary_/", $field)) {
							$row2[$field] = "";
						} else {
							$row2[$field] = $value;
						}
					}
					$newData[] = $row2;
				}
			}
			$i++;
		}
	
		if ($type == "fill") {
			foreach ($found as $countType => $count) {
				$echoToScreen .= "'$countType'$br";
				$echoToScreen .= ($pull + 1).") $countType fill percentage (cumulative): {$found[$countType]} / {$total[$countType]} = ".floor(($found[$countType] * 100) / $total[$countType])."%$br";
			}
		}

		# upload data
		$echoToScreen .= ($pull + 1)." of $numPulls) $type Uploading ".count($newData)." rows of data$br";
		$data = array(
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'overwriteBehavior' => 'overwrite',
			'data' => json_encode($newData),
			'returnContent' => 'count',
			'returnFormat' => 'json'
		);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $server);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
		$output = curl_exec($ch);
		$echoToScreen .= "Data results $type: ".$output."$br";
		testOutput($output);
		curl_close($ch);

		# upload summary grants
		$echoToScreen .= ($pull + 1)." of $numPulls) $type Uploading ".count($summaryGrants)." rows of Summary Grants$br";
		$data = array(
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'overwriteBehavior' => 'overwrite',
			'data' => json_encode($summaryGrants),
			'returnContent' => 'count',
			'returnFormat' => 'json'
		);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $server);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
		$output = curl_exec($ch);
		testOutput($output);
		$echoToScreen .= "Summary Grant results: ".$output."$br";
		curl_close($ch);

	}
}

foreach ($found as $countType => $count) {
	$echoToScreen .= "Final) $countType fill percentage: {$found[$countType]} / {$total[$countType]} = ".floor(($found[$countType] * 100) / $total[$countType])."%$br";
}

foreach ($awardTypes as $type => $i) {
	$echoToScreen .= "$type: {$cdaType[$i]}$br";
}

$successMessage = "";
if ($screen) {
	if (empty($errors)) {
		$successMessage = "SUCCESS$br$br";
	} else {
		$successMessage = "ERRORS$br".implode($br, $errors).$br.$br;
	}
	echo $successMesssage;
 	echo $echoToScreen;
} else {
	echo $server.$br.$br;
	if (count($errors) === 0) {
		$successMessage = "SUCCESS";
	} else {
		$successMessage = "ERRORs ".count($errors)."<ol>".implode($br."<li>", $errors)."</ol>";
		if ($sendEmail) {
			\REDCap::email($victrEmail, "no-reply@vanderbilt.edu", "CareerDev makeSummary script ERRORs", "$server$br$br<h2>".count($errors)." ERRORs</h2><ol><li>".implode($br."<li>", $errors)."</ol>");
		}
	}
	echo $successMesssage;
}
if ($sendEmail) {
	\REDCap::email($victrEmail, "no-reply@vanderbilt.edu", "CareerDev makeSummary script run", $successMessage.$br.$br.$echoToScreen);
}

if ($tokenName == $info['unit']['name']) {
	require_once(dirname(__FILE__)."/test6.php");
}

# close the lockFile
unlink($lockFile);
