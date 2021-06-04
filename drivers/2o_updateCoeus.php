<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../formatter_func.php");
require_once(dirname(__FILE__)."/../coeusPull_func.php");

define("NOAUTH", "true");
require_once(dirname(__FILE__)."/../../../redcap_connect.php");
	
function processCoeus($token, $server, $pid, $records) {
	CareerDev::log("Step 1");
	pullCoeus($token, $server, $pid);
	CareerDev::log("Step 1 done");
	CareerDev::log("Step 2");
	processFiles($token, $server, $pid);
	CareerDev::log("Step 2 done");
	updateCoeus($token, $server, $pid, $records);
	CareerDev::log("Step 3 done");
}

function updateCoeus($token, $server, $pid, $allRecordIds) {
	# update COEUS information in REDCap without disturbing other information
	# 6 should be run afterwards
	# part of a larger process that downloads from COEUS database, reformats, puts in REDCap, and recalculates

	# combines some repeated code from 2a and others. It's not entirely important to centralize one copy of this
	# code as most of this code is the only reliable copy of it. 2a and others were used only in the past.

	CareerDev::log(date("Y-m-d"));
	CareerDev::log("SERVER: ".$server);
	CareerDev::log("TOKEN: ".$token);
	
	$refreshHaroldMoses = true;
	
	$noChange = false;

	
	# download original data
	$pullSize = 1;
	$numPulls = ceil(count($allRecordIds) / $pullSize);
	$unmatchedInvestigators = array();
	$unmatchedAwards = array();

	for ($pullStart = 0; $pullStart < count($allRecordIds); $pullStart += $pullSize) {
		$pullNumber = floor($pullStart / $pullSize) + 1;
		$pullRecordIds = array();
		for ($j = $pullStart; ($j < count($allRecordIds)) && ($j < $pullStart + $pullSize); $j++) {
			array_push($pullRecordIds, $allRecordIds[$j]);
		}
		$redcapData = Download::records($token, $server, $pullRecordIds);
		CareerDev::log($pullNumber." Got project data (".count($redcapData).") for pull ".$pullNumber." of ".$numPulls);

		if (!$noChange) {
			CareerDev::log($pullNumber." make dated backup of original data");
			// $fp = fopen("/app001/www-logs/career_dev/backup.".date("Ymd-his").".json", "a");
			// foreach ($redcapData as $row) {
				// fwrite($fp, json_encode($row)."\n");
			// }
			// fclose($fp);
		} else {
			CareerDev::log($pullNumber." Skipping backup");
		}

		$initialCounts = getCoeusRowCount($redcapData);
	
		# must save all data
		# we will delete the REDCap data as this is the only way to reset the infinitely repeating forms for now
		$names = array();
		$prefix = "native";
		$names[$prefix] = array();
		$repeatable = array();
		$coeus = array();
		$records = array();
		foreach ($redcapData as $row) {
			if ($row['redcap_repeat_instrument'] == "") {
				$names[$prefix][] = $row;
			}
			# preserve repeatables besides coeus
			if ($row['redcap_repeat_instrument'] && ($row['redcap_repeat_instrument'] != "coeus") && ($row['redcap_repeat_instrument'] != "summary_grants")) {
				if (isset($row['followup_complete'])) {
					unset($row['followup_complete']);
				}
				$repeatable[] = $row;
			}
			if (!isset($coeus[$row['record_id']])) {
				$coeus[$row['record_id']] = array();
			}
			if ($row['redcap_repeat_instrument'] == "coeus") {
				$coeus[$row['record_id']][] = $row;
			}
			if (!in_array($row['record_id'], $records)) {
				$records[] = $row['record_id'];
			}
		}
		unset($redcapData);    // save memory

		# delete old records
		$sql = "DELETE FROM redcap_data WHERE project_id = $pid AND field_name LIKE 'coeus_%' AND record IN ('".implode("','", $records)."')";
		db_query($sql);
	
		# format COEUS - this looks a lot like 2a
		$files = array(dirname(__FILE__)."/../coeus_award.format.json" => "award", dirname(__FILE__)."/../coeus_investigator.format.json" => "investigator");
		$prefix = "coeus";
		$names[$prefix] = array();
		$awards = array();
		foreach ($files as $file => $form) {
			$fp = fopen($file, "r");
			while ($line = fgets($fp)) {
				$line = trim($line);
				$data = json_decode($line);
				$row = array();
				foreach ($data as $field => $value) {
					$row[strtolower($prefix."_".$field)] = utf8_decode($value);
				}
				$awardNo = $row['coeus_award_no'];
				$awardSeq = $row['coeus_award_seq'];
				if ($form == "award") {
					if ($awardNo && $awardSeq) {
						$awards[$awardNo."___".$awardSeq] = array("prefix" => $form, "award_no" => $awardNo, "award_seq" => $awardSeq, "DATA" => json_encode($row));
					} else {
						CareerDev::log($pullNumber." Does not have award_no $awardNo and award_seq $awardSeq");
					}
				} else {
					$personName = $row['coeus_person_name'];
					if ($awardNo && $awardSeq && $personName) {
						if (isset($awards[$awardNo."___".$awardSeq])) {
							$json2 = $awards[$awardNo."___".$awardSeq]["DATA"];
							$row2 = json_decode($json2, true);
							foreach ($row2 as $field => $value) {
								if (preg_match("/^\d\d\-...\-\d\d$/", $value)) {
									$row[$field] = formatYMD($value);
								} else {
									$row[$field] = utf8_decode($value);
								}
							}
						} else {
							if (!in_array($personName."___".$awardNo."___".$awardSeq, $unmatchedInvestigators)) {
								$unmatchedInvestigators[$personName."___".$awardNo."___".$awardSeq] = $row;
							}
							if (!in_array($awardNo."___".$awardSeq, $unmatchedAwards)) {
								$unmatchedAwards[$awardNo."___".$awardSeq] = $row;
							}
						}
						$names[$prefix][] = array("prefix" => $prefix, "person_name" => $personName, "award_no" => $awardNo, "award_seq" => $awardSeq, "DATA" => json_encode($row));
					}
				}
			}
		}
		unset($awards);
		CareerDev::log($pullNumber." Uploading COEUS Information");

		# match
		$skip = array("vfrs", "coeus");
		$combined = array();
		$record_id = 1;
		$numRows = 0;
		$sentNames = array();
		$namesToSort = array();
		$queue = array();
		$restored = array();
		$imaginaryCounts = array();
		foreach ($names as $prefix => $rows) {
			if (!in_array($prefix, $skip)) {
				foreach ($rows as $newmanRow) {
					$upload = array();
					$uploadTypes = array();
					$row = $newmanRow;
					$proceed = true;
					$firstName1 = $row['identifier_first_name'];
					$lastName1 = $row['identifier_last_name'];
					foreach ($sentNames as $namePair) {
						$firstName2 = $namePair['first_name'];
						$lastName2 = $namePair['last_name'];
						if (match($firstName1, $lastName1, $firstName2, $lastName2)) {
							$proceed = false;
							break;
						}
					}
					if (!$proceed) {
						// CareerDev::log($pullNumber." $prefix DUPLICATE at $firstName1 $firstName2 $lastName1 $lastName2");
					} else if (skip($firstName1, $lastName1)) {
						// CareerDev::log($pullNumber." $prefix SKIP at $firstName1 $lastName1");
					} else {
						foreach ($names as $prefix2 => $rows2) {
							if ($prefix2 == "coeus") {
								$match2_is = matchRows($prefix2, $rows2, $newmanRow);
								$instance = 1;
								foreach ($match2_is as $match2_i) {
									$uploadRow = formatCoeusRow($rows2[$match2_i], $instance);
									if ($uploadRow['coeus_person_name'] != "Moses,Harold L") {
										$upload[] = $uploadRow;
										$instance++;
										if (!in_array("coeus", $uploadTypes)) {
											$uploadTypes[] = "coeus";
										}
									} else {
										CareerDev::log($pullNumber." Skipping {$uploadRow['coeus_person_name']}");
									}
								}
							}
						}
	
						if ((count($upload) < count($coeus[$row['record_id']]))) {
							if ($refreshHaroldMoses && (strtolower($lastName1) == "moses") && (strtolower($firstName1) == "harold")) {
								CareerDev::log($pullNumber." Refreshing Harold Moses by not uploading");
							} else {
								// Rule: Only add, never subtract
								$restored[$row['record_id']] = count($upload);
								$upload = $coeus[$row['record_id']];
							}
						}
						$j = 0;
						foreach ($upload as $uploadRow) {
							$upload[$j]['record_id'] = $row['record_id'];
							if ($j >= count($coeus[$row['record_id']])) {
								$upload[$j]['coeus_last_update'] = date("Y-m-d");
							}
							$j++;
						}
						$record_id++;
						$numRows += count($upload);
	
						foreach ($upload as $uploadRow) {
							array_push($queue, $uploadRow);
						}
	
						$queueCount = getCoeusRowCount($queue);
						foreach ($queueCount as $queueRecordId => $cnt) {
							$imaginaryCounts[$queueRecordId] = $cnt;
						}
					}
				}
			}
		
			if (count($queue) > 0) {
				// CareerDev::log(json_encode($queue)."");
				$feedback = Upload::rows($queue, $token, $server);
				$output = json_encode($feedback);

				CareerDev::log($pullNumber." Upload ".count($queue)." rows: $output");

				$uploadedNames = array();
				foreach ($queue as $row) {
					if (!in_array($row['coeus_person_name'], $uploadedNames)) {
						$uploadedNames[] = $row['coeus_person_name'];
					}
				}
				CareerDev::log($pullNumber." uploadedNames: ".json_encode($uploadedNames)."");

				$queue = array();
			}
			CareerDev::log($pullNumber." $numRows rows uploaded into ".count($records)." records");
		}
	}

	CareerDev::log($pullNumber." ".count($unmatchedInvestigators)." unmatched investigators");
	$fp = fopen("/app001/www/redcap/plugins/career_dev/unmatched.investigator.json", "a");
	foreach($unmatchedInvestigators as $code => $row) {
		fwrite($fp, json_encode($row)."\n");
	}
	fclose($fp);
	CareerDev::log($pullNumber." ".count($unmatchedAwards)." unmatched awards");
	$fp = fopen("/app001/www/redcap/plugins/career_dev/unmatched.award.json", "w");
	foreach($unmatchedAwards as $code => $row) {
		fwrite($fp, json_encode($row)."\n");
	}
	fclose($fp);


	# send out alert email; download limited list first and then use to send out alert
	$fields = array("record_id", "identifier_first_name", "identifier_last_name", "coeus_person_name");
	$redcapData = Download::fields($token, $server, $fields);
	$finalCounts = getCoeusRowCount($redcapData);
	$finalNames = array();
	foreach ($redcapData as $row) {
		if ($row['redcap_repeat_instrument'] === "") {
			$finalNames[$row['record_id']] = $row["identifier_first_name"]." ".$row['identifier_last_name'];
		}
	}
	CareerDev::log($pullNumber." COEUS Updated on CareerDev");
	CareerDev::log($pullNumber." ".count($unmatchedAwards)." unmatched awards");
	CareerDev::log($pullNumber." ".count($unmatchedInvestigators)." unmatched investigators");
	CareerDev::log($pullNumber." COUNTS INCREASED");
	foreach ($initialCounts as $recordId => $initialCount) {
		if (!$noChange && ($initialCount < $finalCounts[$recordId])) {
			CareerDev::log($pullNumber." ".Links::makeDataWranglingLink(66635, $finalNames[$recordId], $recordId)." changed from $initialCount to {$finalCounts[$recordId]}");
		} else if ($noChange && ($initialCount < $imaginaryCounts[$recordId])) {
			CareerDev::log($pullNumber." ".Links::makeDataWranglingLink(66635, $finalNames[$recordId], $recordId)." changed from $initialCount to {$imaginaryCounts[$recordId]}");
		}
	}
	CareerDev::log($pullNumber." PRESERVED OLD DATA (New data < Old data)");
	foreach ($initialCounts as $recordId => $initialCount) {
		if (isset($restored[$recordId])) {
			CareerDev::log($pullNumber." ".Links::makeDataWranglingLink(66635, $finalNames[$recordId], $recordId, TRUE)." remained at $initialCount (new: {$restored[$recordId]})");
		}
	}
	if (empty($initialCounts)) {
		CareerDev::log($pullNumber." No new data is available.");
	}
	
	CareerDev::saveCurrentDate("Last COEUS Download", $pid);
}


# formats to YYYY-MM-DD
function formatYMD($dmy) {
	if ($dmy === "") {
		return "";
	}
	$nodes = preg_split("/\-/", $dmy);
	$day = $nodes[0];
	$month = $nodes[1];
	$year = $nodes[2];
	if ($year < 40) {
		$year += 2000;
	} else if ($year < 100) {
		$year += 1900;
	}
	if (($day < 10) && (strlen($day) <= 1)) {
		$day = "0".$day;
	}
	$months = array(
			"JAN" => "01",
			"FEB" => "02",
			"MAR" => "03",
			"APR" => "04",
			"MAY" => "05",
			"JUN" => "06",
			"JUL" => "07",
			"AUG" => "08",
			"SEP" => "09",
			"OCT" => "10",
			"NOV" => "11",
			"DEC" => "12",
		);
	$month = $months[$month];
	return $year."-".$month."-".$day;
}
	
/**
 * Remove any non-ASCII characters and convert known non-ASCII characters 
 * to their ASCII equivalents, if possible.
 *
 * @param string $string 
 * @return string $string
 * @author Jay Williams <myd3.com>
 * @license MIT License
 * @link http://gist.github.com/119517
 */
function convert_ascii($string) 
{ 
	// Replace Single Curly Quotes
	$search[]  = chr(226).chr(128).chr(152);
	$replace[] = "'";
	$search[]  = chr(226).chr(128).chr(153);
	$replace[] = "'";
	// Replace Smart Double Curly Quotes
	$search[]  = chr(226).chr(128).chr(156);
	$replace[] = '"';
	$search[]  = chr(226).chr(128).chr(157);
	$replace[] = '"';
	// Replace En Dash
	$search[]  = chr(226).chr(128).chr(147);
	$replace[] = '--';
	// Replace Em Dash
	$search[]  = chr(226).chr(128).chr(148);
	$replace[] = '---';
	// Replace Bullet
	$search[]  = chr(226).chr(128).chr(162);
	$replace[] = '*';
	// Replace Middle Dot
	$search[]  = chr(194).chr(183);
	$replace[] = '*';
	// Replace Ellipsis with three consecutive dots
	$search[]  = chr(226).chr(128).chr(166);
	$replace[] = '...';
	// Apply Replacements
	$string = str_replace($search, $replace, $string);
	// Remove any non-ASCII Characters
	$string = preg_replace("/[^\x01-\x7F]/","", $string);
	return $string; 
}
	
# strip double quotes
function stripQuotes($v) {
	$v = preg_replace("/^\"/", "", $v);
	$v = preg_replace("/\"$/", "", $v);
	return $v;
}

# handles failed data probably due to special characters
function handleFailedData($row) {
	$row2 = array();
		foreach ($row as $field => $value) {
		$value2 = json_encode($value);
		if ($value2 === false) {
			$value2 = convert_ascii($value);
		}
		$row2[$field] = stripQuotes($value2);
	}

	return json_encode($row2);
}

# count how many COEUS rows exist
function getCoeusRowCount($data) {
	$counts = array();
		foreach ($data as $row) {
		$counts[$row['record_id']] = 0;
	}
	foreach ($data as $row) {
		if ($row['redcap_repeat_instrument'] == "coeus") {
			$counts[$row['record_id']]++;
		}
	}
	return $counts;
}

# strip quotes and trim
function fixToDatabase($n) {
	$n = stripQuotes($n);
	$n = trim($n);
	return $n;
}
# textual fixes to facilitate matching via regex's
function fixToCompare($n) {
	$n = str_replace("/", "\/", $n);
	$n = str_replace("???", "", $n);
	$n = stripQuotes($n);
	$n = strtolower($n);
	return $n;
}

# returns array of (lastname, firstname)
# middle name ignored
function getCoeusName($n) {
	$nodes = preg_split("/,/", $n);
	$newNodes = array();
	$j = 0;
	foreach ($nodes as $node) {
		$node = preg_replace("/\s*Jr\.*/", "", $node);
		if ($j < 2) {
			$newNodes[] = $node;
		} else if ($j >= 2) {
			$newNodes[1] = $newNodes[1].",".$node;
		}
		$j++;
	}
	while (count($newNodes) < 2) {
		$newNodes[] = "";
	}
	return $newNodes;
}

# returns true/false over whether the names "match"
function match($fn1, $ln1, $fn2, $ln2) {
	return coeusMatch($fn1, $ln1, $fn2, $ln2);
}

function fixForMatch($n) {
	return coeusFixForMatch($n);
}

# returns true/false over whether this is a pair to skip
function notSkip($fn1, $ln1, $fn2, $ln2) {
	$fn1 = fixForMatch($fn1);
	$fn2 = fixForMatch($fn2);
	$ln1 = fixForMatch($ln1);
	$ln2 = fixForMatch($ln2);
	if (($ln1 == "ho") && ($fn1 == "richard") && ($ln2 == "holden") && ($fn2 == "richard")) {
		return false;
	}
	if (($ln2 == "ho") && ($fn2 == "richard") && ($ln1 == "holden") && ($fn1 == "richard")) {
		return false;
	}
	return true;
}

# returns array with match indices in $rows
function matchRows($prefix, $rows, $newmanRow) {
	$firstName1 = $newmanRow['identifier_first_name'];
	$lastName1 = $newmanRow['identifier_last_name'];
	$personName1 = $newmanRow['identifier_coeus'];
	$match_is = array();
	if ($firstName1 && $lastName1) {
		$i = 0;
		foreach ($rows as $row) {
			$firstName2 = "";
			$lastName2 = "";
			if ($prefix == "coeus") {
				$personName2 = $row['person_name'];
				$coeusName2 = getCoeusName($personName2);
				$firstName2 = fixToCompare($coeusName2[1]);
				$lastName2 = fixToCompare($coeusName2[0]);
			} else {
				$firstName2 = fixToCompare($row['identifier_first_name']);
				$lastName2 = fixToCompare($row['identifier_last_name']);
			}

			if (strtolower($personName1) == strtolower($personName2)) {
				$match_is[] = $i;
			} else if (notSkip($firstName1, $lastName1, $firstName2, $lastName2)) {
				if (match($firstName1, $lastName1, $firstName2, $lastName2)) {
					$match_is[] = $i;
				}
			}
			$i++;
		}
	}
	return $match_is;
}

# combine two REDCap rows
# row overwrites row2
function combineRows($row, $row2) {
	$combined = array();
	$rowData = json_decode($row['DATA'], true);
	$row2Data = json_decode($row2['DATA'], true);
	$combinedData = array();

	# row overwrites row2
	if ($row2Data) {
		foreach ($row2Data as $field => $value) {
			$combinedData[$field] = $value;
		}
	} else {
		CareerDev::log("Error decoding ".json_encode($row2)."");
	}
	if ($rowData) {
		foreach ($rowData as $field => $value) {
			$combinedData[$field] = $value;
		}
	} else {
		CareerDev::log("Error decoding ".json_encode($row)."");
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
	$combined['first_name'] = $row['identifier_first_name'];
	$combined['last_name'] = $row['identifier_last_name'];
	$combined['DATA'] = json_encode($combinedData);

	return $combined;
}

# formats a REDCap line with an infinitely repeating instance and an instrument
function formatCoeusRow($row, $instance) {
	$rowData = json_decode($row['DATA'], true);
	$rowData['redcap_repeat_instance'] = $instance;
	$rowData['redcap_repeat_instrument'] = "coeus";
	$rowData['coeus_complete'] = "2";
	return $rowData;
}

# skip two people
function skip($fn, $ln) {
	$fn = strtolower($fn);
	$ln = strtolower($fn);
	if (($fn == "hal") && ($ln == "moses")) {
		return true;
	} else if (($fn == "alex") && ($ln == "patrick???")) {
		return true;
	}
	return false;
}


