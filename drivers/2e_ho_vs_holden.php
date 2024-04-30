<?php

namespace Vanderbilt\CareerDevLibrary;

# This manually handles the Ho vs. Holden case
# Deletes old record and appends two new records of Ho and Holden
# Handles all of their data manually, including COEUS information
# Much of this is a lazy copy of 2a

require_once(dirname(__FILE__)."/../small_base.php");

echo "SERVER: ".$server."\n";
echo "TOKEN: ".$token."\n";
echo "PID: ".$pid."\n";
echo "\n";

if ($pid == 66635) {
        $a = readline("Are you sure? > ");
        if ($a != "y") {
                die();
        }
}

$vfrs_token = 'A987974FEEBDA008EB3200B182EAD1EE';

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
$files = array(
		"Nonrespondents.clean.csv" => "nonrespondents",
		"newman2016_data_new.clean.csv" => "data",
		"newman2016_demo.clean.csv" => "demographics",
		"newman2016_data.clean.csv" => "data",
		"newman2016_sheet2.clean.csv" => "sheet2",
		"KL2.clean.csv" => "kl2",
);
$names = array();
foreach ($files as $file => $prefix) {
	$fp = fopen($file, "r");
	$i = 0;
	$names[$prefix] = array();
	$columns = array();
	while ($line = fgetcsv($fp)) {
		if (($i == 1) || ($i == 2) && empty($columns)) {
			if ($line[0] || $line[1] || $line[2]) {
				foreach ($line as $l) {
					$columns[] = $l;
				}
			}
		} else if (($i >= 2) && (!empty($columns))) {
			$row = array();
			$j = 0;
			$firstName = "";
			$lastName = "";
			foreach ($columns as $col) {
				if ($col) {
					if ($col == "first_name") {
						$firstName = fixToDatabase($line[$j]);
						$row[strtolower($col)] = $line[$j];
					} else if ($col == "last_name") {
						$lastName = fixToDatabase($line[$j]);
						$row[strtolower($col)] = $line[$j];
					} else if ($col == "vunetid") {
						$row[strtolower($col)] = fixToDatabase($line[$j]);
					} else if ($col != "middle_name") {
						$preprefix = "newman";
						if ($prefix == "kl2") {
							$preprefix = "kl2";
						}
						$row[$preprefix."_".$prefix."_".strtolower($col)] = $line[$j];
					}
				}
				$j++;
			}
			if (!empty($row) && $firstName && $lastName) {
				$row2 = array();
				foreach ($row as $field => $value) {
					$row2[$field] = trim(stripQuotes($value));
					if (preg_match("/\d+\/\d+\/\d+/", $row2[$field])) {
						$nodes = preg_split("/\//", $row2[$field]);
						$month = $nodes[0];
						$day = $nodes[1];
						$year = $nodes[2];
						if ($year < 30) {
							$year += 2000;
						} else if ($year < 100) {
							$year += 1900;
						}
						if ($month < 10 && strlen($month) == 1) {
							$month = "0".$month;
						}
						if ($day < 10 && strlen($day) == 1) {
							$day = "0".$day;
						}
						$row2[$field] = $year."-".$month."-".$day;
					} else if ($row2[$field] == "MSCI") {
						$row2[$field] = 4;
					} else if ($row2[$field] == "MD MSCI") {
						$row2[$field] = 7;
					} else if ($row2[$field] == "MD") {
						$row2[$field] = 1;
					} else if ($row2[$field] == "PhD") {
						$row2[$field] = 2;
					} else if ($row2[$field] == "2014") {
						$row2[$field] = "2014-01-01";
					} else if ($row2[$field] == "Medicine") {
						$row2[$field] = 104366;
					} else if ($row2[$field] == "Pharmacology") {
						$row2[$field] = 104290;
					} else if ($row2[$field] == "Pediatrics") {
						$row2[$field] = 104595;
					} else if ($row2[$field] == "Biomedical Informatics") {
						$row2[$field] = 104785;
					} else if ($row2[$field] == "NH") {
						$row2[$field] = 2;
					} else if ($row2[$field] == "Asian") {
						$row2[$field] = 2;
					} else if ($row2[$field] == "White") {
						$row2[$field] = 5;
					} else if ($row2[$field] == "W") {
						$row2[$field] = 5;
					} else if ($row2[$field] == "M") {
						$row2[$field] = 1;
					} else if ($row2[$field] == "Asst Prof") {
						$row2[$field] = 5;
					} else if ($row2[$field] == "Asst. Prof") {
						$row2[$field] = 5;
					} else if ($row2[$field] == "Asst. Prof.") {
						$row2[$field] = 5;
					}
				}
				$json = json_encode($row2);
				if (!$json) {
					$json = handleFailedData($row2);
				}
				if ((strtolower($firstName) == "richard" && strtolower($lastName) == "holden") || (strtolower($firstName) == "richard" && strtolower($lastName) == "ho")) {
					$names[$prefix][] = array("prefix" => $prefix, "first_name" => strtolower($firstName), "last_name" => strtolower($lastName), "DATA" => $json);
				}
			}
		}
		$i++;
	}
	fclose($fp);
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

function stripQuotes($v) {
	$v = preg_replace("/^\"/", "", $v);
	$v = preg_replace("/\"$/", "", $v);
	return $v;
}

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

#VFRS
$data = array(
	'token' => $vfrs_token,
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
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://redcap.vumc.org/api/');
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
$vfrsData = json_decode($output, true);
$prefix = "vfrs";
$names[$prefix] = array();
foreach ($vfrsData as $row) {
	$id = $row['participant_id'];
	$firstName = $row['name_first'];
	$lastName = $row['name_last'];
	$row2 = array();
	foreach ($row as $field => $value) {
		if (!preg_match("/_complete$/", $field)) {
			$row2[strtolower("vfrs_".$field)] = $value;
		}
	}
	if ($id && $firstName && $lastName) {
		$names[$prefix][] = array("prefix" => $prefix, "participant_id" => $id, "first_name" => $firstName, "last_name" => $lastName, "DATA" => json_encode($row2));
	}
}
unset($vfrsData);

$files = array("../coeus_award.format.json" => "award", "../coeus_investigator.format.json" => "investigator");
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
			$row[strtolower($prefix."_".$field)] = $value;
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
							$row[$field] = $value;
						}
					}
				} else {
					if (!in_array($personName."___".$awardNo."___".$awardSeq, $unmatchedInvestigators)) {
						$unmatchedInvestigators[] = $personName."___".$awardNo."___".$awardSeq;
					}
					if (!in_array($awardNo."___".$awardSeq, $unmatchedAwards)) {
						$unmatchedAwards[] = $awardNo."___".$awardSeq;
					}
				}
				$names[$prefix][] = array("prefix" => $prefix, "person_name" => $personName, "award_no" => $awardNo, "award_seq" => $awardSeq, "DATA" => json_encode($row));
			}
		}
	}
}
unset($awards);
echo count($unmatchedInvestigators)." unmatched investigators\n";
echo count($unmatchedAwards)." unmatched awards\n";

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

# return boolean
function nameMatch($n1, $n2) {
	if (($n1 == "ho") || ($n2 == "ho") && (($n1 == "holden") || ($n2 == "holden"))) {
		if ($n1 == $n2) {
			return true;
		}
	} else {
		if (preg_match("/^".$n1."/", $n2) || preg_match("/^".$n2."/", $n1)) {
			return true;
		}
	}
	if (preg_match("/[\(\-]".$n1."/", $n2) || preg_match("/[\(\-]".$n2."/", $n1)) {
		return true;
	}
	return false;
}

function fixForMatch($n) {
	$n = preg_replace("/\s+\w\.$/", "", $n);
	$n = preg_replace("/\s+\w$/", "", $n);
	$n = str_replace("???", "", $n);
	return $n;
}

# returns true/false over whether the names "match"
function match($fn1, $ln1, $fn2, $ln2) {
	if ($fn1 && $ln1 && $fn2 && $ln2) {
		$fn1 = fixForMatch($fn1);
		$fn2 = fixForMatch($fn2);
		$ln1 = fixForMatch($ln1);
		$ln2 = fixForMatch($ln2);
		if (nameMatch($fn1, $fn2) && nameMatch($ln1, $ln2)) {
			return true;
		}
	}
	return false;
}

# returns true/false over whether this is a pair to skip
function notSkip($fn1, $ln1, $fn2, $ln2) {
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
				$firstName2 = fixToCompare($coeusName2[1]);
				$lastName2 = fixToCompare($coeusName2[0]);
			} else {
				$firstName2 = fixToCompare($row['first_name']);
				$lastName2 = fixToCompare($row['last_name']);
			}
			if (notSkip($firstName1, $lastName1, $firstName2, $lastName2)) {
				if (match($firstName1, $lastName1, $firstName2, $lastName2)) {
					$match_is[] = $i;
				}
			}
			$i++;
		}
	}
	return $match_is;
}

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
	$combined['first_name'] = $row['first_name'];
	$combined['last_name'] = $row['last_name'];
	$combined['DATA'] = json_encode($combinedData);

	return $combined;
}

function formatCoeusRow($row, $instance) {
	$rowData = json_decode($row['DATA'], true);
	$rowData['redcap_repeat_instance'] = $instance;
	$rowData['redcap_repeat_instrument'] = "coeus";
	return $rowData;
}

function adjustForVFRS($redcapRow) {
	$fieldsToAdjust = array("vfrs_graduate_degree", "vfrs_degree2", "vfrs_degree3", "vfrs_degree4", "vfrs_degree5");
	foreach ($redcapRow as $field => $value) {
		if (in_array($field, $fieldsToAdjust) && ($value !== '')) {
			$redcapRow[$field] = $value + 1;
		}
	}
	return $redcapRow;
}

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
$recordIdsData = json_decode($output, true);
curl_close($ch);
$maxRecordId = 0;
foreach ($recordIdsData as $row) {
	if ($row['record_id'] > $maxRecordId) {
		$maxRecordId = $row['record_id'];
	}
} 

# match
$skip = array("vfrs", "coeus");
$combined = array();
$record_id = $maxRecordId + 1;
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
					if ($prefix2 != "coeus") {
						$match2_is = matchRows($prefix2, $rows2, $newmanRow);
						foreach ($match2_is as $match2_i) {
							$row = combineRows($row, $rows2[$match2_i]);
							foreach ($row['prefix'] as $prefix3) {
								if (!in_array($prefix3, $uploadTypes)) {
									$uploadTypes[] = $prefix3;
								}
							}
						}
					} else {
						$match2_is = matchRows($prefix2, $rows2, $newmanRow);
						$instance = 1;
						foreach ($match2_is as $match2_i) {
							$upload[] = formatCoeusRow($rows2[$match2_i], $instance);
							$instance++;
							if (!in_array("coeus", $uploadTypes)) {
								$uploadTypes[] = "coeus";
							}
						}
					}
				}
				$rowData = json_decode($row['DATA'], true);
				$rowData['redcap_repeat_instrument'] = "";
				$rowData['redcap_repeat_instance'] = "";
				$rowData = adjustForVFRS($rowData);
				array_unshift($upload, $rowData);
				$i = 0;
				foreach ($upload as $uploadRow) {
					$upload[$i]['record_id'] = $record_id;
					$i++;
				}
				$record_id++;
				$numRows += count($upload);
	
				foreach ($upload as $uploadRow) {
					$queue[] = $uploadRow;
				}

				if (count($queue) > 200) {
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
					// $output = curl_exec($ch);
					// echo "Upload ".count($queue)." rows: $output\n";
					// curl_close($ch);
					// echo json_encode($queue);

					$queue = array();
				}
				$uploadNames = array( "first_name" => fixToCompare($upload[0]['first_name']), "last_name" => fixToCompare($upload[0]['last_name']) );
				$sentNames[] = $uploadNames;
			}
		}
	}
}
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
	$output = curl_exec($ch);
	echo "Upload ".count($queue)." rows $output\n";
	curl_close($ch);
	$records = array();
	foreach ($queue as $row) {
		if (!in_array($row['record_id'], $records)) {
			$records[] = $row['record_id'];
		}
	}
	echo "Records in queue: ".json_encode($records)."\n";
}
// echo "$numRows rows uploaded into ".($record_id - 1)." records.\n";
