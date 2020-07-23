<?php
try {

	# update COEUS information in REDCap without disturbing other information
	# 6 should be run afterwards
	# part of a larger process that downloads from COEUS database, reformats, puts in REDCap, and recalculates

	# combines some repeated code from 2a and others. It's not entirely important to centralize one copy of this
	# code as most of this code is the only reliable copy of it. 2a and others were used only in the past.

	require_once(dirname(__FILE__)."/../small_base.php");
	require_once(dirname(__FILE__)."/../classes/Links.php");

	define("NOAUTH", "true");
	require_once(dirname(__FILE__)."/../../../redcap_connect.php");
	
	if (php_sapi_name() != 'cli') {
		die("Unavailable");
	}
	
	echo date("Y-m-d")."\n";
	echo "SERVER: ".$server."\n";
	echo "TOKEN: ".$token."\n";
	echo "PID: ".$pid."\n";
	echo "\n";
	
	if (($pid == 66635) && isset($argv[1]) && ($argv[1] != 'prod_override') && ($argv[1] != 'prod_cron'))  {
	        $a = readline("Are you sure? > ");
	        if ($a != "y") {
	                die();
	        }
	}

	$refreshHaroldMoses = true;
	
	$noChange = false;
	if (($pid == 66635) && isset($argv[1]) && ($argv[1] != 'prod_override') && ($argv[1] != 'prod_cron')) {
		$a = readline("Hit X to change the database; otherwise, test run> ");
		$noChange = true;
		if (($a == "X") || ($a == "x")) {
			$noChange = false;
		}
		if ($noChange) {
			echo "No uploads or deletions\n";
		} else {
			echo "Uploads and deletions ENABLED\n";
		}
	}

	$selectRecord = "";
	if (isset($argv[2])) {
		$selectRecord = $argv[2];
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
	
	$names = array();
	
	# download original data
	$prefix = "native";
	$data = array(
		'token' => $token,
		'content' => 'record',
		'format' => 'json',
		'type' => 'flat',
		'rawOrLabel' => 'raw',
		'rawOrLabelHeaders' => 'raw',
		'exportCheckboxLabel' => 'false',
		'exportSurveyFields' => 'false',
		'exportDataAccessGroups' => 'false',
		'returnFormat' => 'json'
	);
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
	
	echo "Got all project data\n";

	if (!$noChange) {
		echo "make dated backup of original data\n";
		$fp = fopen("/app001/www-logs/career_dev/backup.".date("Ymd-his").".json", "w");
		echo "Writing\n";
		fwrite($fp, $output);
		echo "Closing\n";
		fclose($fp);
		echo "Wrote backup\n";
	} else {
		echo "Skipping backup\n";
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
	
	echo "Decoding JSON\n";
	$redcapData = json_decode($output, true);
	echo "Getting initial counts\n";
	$initialCounts = getCoeusRowCount($redcapData);
	
	# must save all data
	# we will delete the REDCap data as this is the only way to reset the infinitely repeating forms for now
	$names[$prefix] = array();
	$repeatable = array();
	$coeus = array();
	$records = array();
	echo "Parsing REDCap data\n";
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
	echo "Parsed\n";

	# delete old records
	$sql = "DELETE FROM redcap_data WHERE project_id = $pid AND field_name LIKE 'coeus_%' AND record IN ('".implode("','", $records)."')";
	db_query($sql);

	# old way - deletes the survey hash, so we don't use
	// $data = array(
		// 'token' => $token,
		// 'action' => 'delete',
		// 'content' => 'record',
		// 'records' => $records
	// );
	// $ch = curl_init();
	// curl_setopt($ch, CURLOPT_URL, $server);
	// curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	// curl_setopt($ch, CURLOPT_VERBOSE, 0);
	// curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	// curl_setopt($ch, CURLOPT_AUTOREFERER, true);
	// curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
	// curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
	// curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
	// curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
	// $output = "";
	// if (!$noChange) {
		// $output = curl_exec($ch);
	// }
	// echo "Deleted records: ".$output."\n";
	// curl_close($ch);


	# upload non-repeating
	// echo "Uploading non-COEUS parts of records\n";
	// $data = array(
		// 'token' => $token,
		// 'content' => 'record',
		// 'format' => 'json',
		// 'type' => 'flat',
		// 'overwriteBehavior' => 'normal',
		// 'data' => json_encode($names[$prefix]),
		// 'returnContent' => 'count',
		// 'returnFormat' => 'json'
	// );
	// $ch = curl_init();
	// curl_setopt($ch, CURLOPT_URL, $server);
	// curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	// curl_setopt($ch, CURLOPT_VERBOSE, 0);
	// curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	// curl_setopt($ch, CURLOPT_AUTOREFERER, true);
	// curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
	// curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
	// curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
	// curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
	// if (!$noChange) {
		// $output = curl_exec($ch);
	// }
	// echo "Uploaded ".count($names[$prefix])." new records: ".$output."\n";
	// curl_close($ch);

	# upload repeating
	// echo "Uploading ".count($repeatable)." rows of Repeatable information\n";
	// if (!empty($repeatable)) {
		// $size = 600;
		// for ($i = 0; $i < count($repeatable); $i += $size) {
			// $repeatableRows = array();
			// for ($j = $i; ($j < $i + $size) && ($j < count($repeatable)); $j++) {
				// $repeatableRows[] = $repeatable[$j];
			// }
			// $data = array(
				// 'token' => $token,
				// 'content' => 'record',
				// 'format' => 'json',
				// 'type' => 'flat',
				// 'overwriteBehavior' => 'normal',
				// 'data' => json_encode($repeatableRows),
				// 'returnContent' => 'count',
				// 'returnFormat' => 'json'
			// );
			// $ch = curl_init();
			// curl_setopt($ch, CURLOPT_URL, $server);
			// curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			// curl_setopt($ch, CURLOPT_VERBOSE, 0);
			// curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			// curl_setopt($ch, CURLOPT_AUTOREFERER, true);
			// curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
			// curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
			// curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
			// curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
			// if (!$noChange) {
				// $output = curl_exec($ch);
				// if ($feedback = json_decode($output)) {
					// if ($feedback['error']) {
						// echo "$i of ".count($repeatable).": ERROR: ".json_encode($repeatableRows)."\n";
					// }
				// }
			// }
			// echo "$i of ".count($repeatable).". Uploaded ".count($repeatableRows)." new repeatable : ".$output."\n";
			// curl_close($ch);
		// }
	// }
	
	
	# format COEUS - this looks a lot like 2a
	$files = array(dirname(__FILE__)."/../coeus_award.format.json" => "award", dirname(__FILE__)."/../coeus_investigator.format.json" => "investigator");
	$prefix = "coeus";
	$names[$prefix] = array();
	$awards = array();
	$unmatchedInvestigators = array();
	$unmatchedAwards = array();
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
					echo "Does not have award_no $awardNo and award_seq $awardSeq\n";
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
	echo count($unmatchedInvestigators)." unmatched investigators\n";
	$fp = fopen("/app001/www/redcap/plugins/career_dev/unmatched.investigator.json", "w");
	foreach($unmatchedInvestigators as $code => $row) {
		fwrite($fp, json_encode($row)."\n");
	}
	fclose($fp);
	echo count($unmatchedAwards)." unmatched awards\n";
	$fp = fopen("/app001/www/redcap/plugins/career_dev/unmatched.award.json", "w");
	foreach($unmatchedAwards as $code => $row) {
		fwrite($fp, json_encode($row)."\n");
	}
	fclose($fp);
	echo "\n";
	echo "Uploading COEUS Information\n";
	
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
			echo "Error decoding ".json_encode($row2)."\n";
		}
		if ($rowData) {
			foreach ($rowData as $field => $value) {
				$combinedData[$field] = $value;
			}
		} else {
			echo "Error decoding ".json_encode($row)."\n";
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
			$i = 0;
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
					// echo "$prefix DUPLICATE at $firstName1 $firstName2 $lastName1 $lastName2\n";
				} else if (skip($firstName1, $lastName1)) {
					// echo "$prefix SKIP at $firstName1 $lastName1\n";
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
									echo "Skipping {$uploadRow['coeus_person_name']}\n";
								}
							}
						}
					}
					// $rowData = json_decode($row['DATA'], true);
					// $rowData['redcap_repeat_instrument'] = "";
					// $rowData['redcap_repeat_instance'] = "";
					// array_unshift($upload, $rowData);

					if ((count($upload) < count($coeus[$row['record_id']]))) {
						if ($refreshHaroldMoses && (strtolower($lastName1) == "moses") && (strtolower($firstName1) == "harold")) {
							echo "Refreshing Harold Moses by not uploading\n";
						} else {
							// Rule: Only add, never subtract
							$restored[$row['record_id']] = count($upload);
							$upload = $coeus[$row['record_id']];
						}
					}
					$i = 0;
					foreach ($upload as $uploadRow) {
						$upload[$i]['record_id'] = $row['record_id'];
                                                if ($i >= count($coeus[$row['record_id']])) {
							$upload[$i]['coeus_last_update'] = date("Y-m-d");
						}
						$i++;
					}
					$record_id++;
					$numRows += count($upload);

					foreach ($upload as $uploadRow) {
						$queue[] = $uploadRow;
					}

					$queueCount = getCoeusRowCount($queue);
					foreach ($queueCount as $queueRecordId => $cnt) {
						$imaginaryCounts[$queueRecordId] = $cnt;
					}
	
					# upload batches of 200 rows - not records, rows
					if (count($queue) > 200) {
                                                // echo json_encode($queue)."\n";
						$data = array(
							'token' => $token,
							'content' => 'record',
							'format' => 'json',
							'type' => 'flat',
							'overwriteBehavior' => 'normal',
							'data' => json_encode($queue),
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
						if (!$noChange) {
							$output = curl_exec($ch);
						}
						echo "Upload ".count($queue)." rows: $output\n";
						// foreach ($queue as $row) {
							// $lastName = "";
							// if (isset($row['last_name'])) {
								// $lastName = $row['last_name'];
							// }
							// if (!$lastName && isset($row['coeus_person_name'])) {
								// $lastName = $row['coeus_person_name'];
							// }
							// if ($lastName && preg_match("/^R/", $lastName)) {
								// echo $row['record_id'].": ".$lastName."\n";
							// }
						// }

						$uploadedNames = array();
						foreach ($queue as $row) {
							if (!in_array($row['coeus_person_name'], $uploadedNames)) {
								$uploadedNames[] = $row['coeus_person_name'];
							}
						}
						echo "uploadedNames: ".json_encode($uploadedNames)."\n";

						curl_close($ch);
	
						$queue = array();
					}
					// $uploadNames = array( "first_name" => fixToCompare($upload[0]['first_name']), "last_name" => fixToCompare($upload[0]['last_name']) );
					// $sentNames[] = $uploadNames;
				}
			}
		}
	}
	# upload the rest
	if (count($queue) > 0) {
		$data = array(
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'overwriteBehavior' => 'normal',
			'data' => json_encode($queue),
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
		if (!$noChange) {
			$output = curl_exec($ch);
		}
		echo "Upload ".count($queue)." rows: $output\n";
		curl_close($ch);

		$uploadedNames = array();
		foreach ($queue as $row) {
			if (!in_array($row['coeus_person_name'], $uploadedNames)) {
				$uploadedNames[] = $row['coeus_person_name'];
			}
		}
		echo "uploadedNames: ".count($uploadedNames)."\n";
	}
	echo "$numRows rows uploaded into ".($record_id - 1)." records.\n";
	
	# send out alert email; download limited list first and then use to send out alert
	$data = array(
		'token' => $token,
		'content' => 'record',
		'format' => 'json',
		'type' => 'flat',
		'fields' => array("record_id", "identifier_first_name", "identifier_last_name", "coeus_person_name"),
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
	$finalCounts = getCoeusRowCount($redcapData);
	$finalNames = array();
	foreach ($redcapData as $row) {
		if ($row['redcap_repeat_instrument'] === "") {
			$finalNames[$row['record_id']] = $row["identifier_first_name"]." ".$row['identifier_last_name'];
		}
	}
	echo "Including redcap_connect\n";
	$mssg = "COEUS Updated on CareerDev<br>";
	$mssg .= "<br>";
	$mssg .= count($unmatchedAwards)." unmatched awards<br>";
	$mssg .= count($unmatchedInvestigators)." unmatched investigators<br>";
	$mssg .= "<br>COUNTS INCREASED<br>";
	foreach ($initialCounts as $recordId => $initialCount) {
		if (!$noChange && ($initialCount < $finalCounts[$recordId])) {
			$mssg .= Links::makeDataWranglingLink(66635, $finalNames[$recordId], $recordId)." changed from $initialCount to {$finalCounts[$recordId]}<br>";
		} else if ($noChange && ($initialCount < $imaginaryCounts[$recordId])) {
			$mssg .= Links::makeDataWranglingLink(66635, $finalNames[$recordId], $recordId)." changed from $initialCount to {$imaginaryCounts[$recordId]}<br>";
		}
	}
	$mssg .= "<br>PRESERVED OLD DATA (New data < Old data)<br>";
	foreach ($initialCounts as $recordId => $initialCount) {
		if (isset($restored[$recordId])) {
			$mssg .= Links::makeDataWranglingLink(66635, $finalNames[$recordId], $recordId, TRUE))." remained at $initialCount (new: {$restored[$recordId]})<br>";
		}
	}
	if (empty($initialCounts)) {
		$mssg .= "No new data is available.<br>";
	}
	
	echo "Comparing tokens\n";
	if ($info['prod']['token'] == $token) {
		echo "Sending email\n";
		\REDCap::email($victrEmail, "no-reply@vanderbilt.edu", "COEUS Updated on CareerDev", $mssg);
	}
} catch (Exception $e) {
	\REDCap::email($victrEmail, "no-reply@vanderbilt.edu", "COEUS NOT Updated on CareerDev", "The script threw an exception.<br>".$e->getMessage());
}

CareerDev::saveCurrentDate("Last COEUS Download", $pid);
