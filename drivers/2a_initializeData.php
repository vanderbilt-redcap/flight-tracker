<?php

namespace Vanderbilt\CareerDevLibrary;

# performs an initial upload of the data to the REDCap database
# get Newman Data from spreadsheets, VFRS, COEUS

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

# Token for VFRS
$vfrs_token = '';

# all of the newman data
# a prefix differentiates the data in the REDCap database
$files = array(
		"Nonrespondents.clean.csv" => "nonrespondents",
		"newman2016_data_new.clean.csv" => "data",
		"newman2016_demo.clean.csv" => "demographics",
		"newman2016_data.clean.csv" => "data",
		"newman2016_sheet2.clean.csv" => "sheet2",
		"KL2.clean.csv" => "kl2",
);

# the names list keeps track of the names for each prefix
# will eventually be used to combine the data
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
					$row2[$field] = stripQuotes($value);
				}
				$json = json_encode($row2);
				if (!$json) {
					$json = handleFailedData($row2);
				}
				$names[$prefix][] = array("prefix" => $prefix, "first_name" => strtolower($firstName), "last_name" => strtolower($lastName), "DATA" => $json);
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

# returns string without double quotes at beginning and end of string
function stripQuotes($v) {
	$v = preg_replace("/^\"/", "", $v);
	$v = preg_replace("/\"$/", "", $v);
	return $v;
}

# if the string contains special characters, it will fail
# this function handles how to convert those characters to
# ASCII and to strip the double-quotes around them
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

# downloads the VFRS data
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

# COEUS downloads - already downloaded
# match to each other on award number/award sequence
# award must be run first for this algorithm to work; then investigator
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
		$personName = $row['coeus_person_name'];
		$awardNo = $row['coeus_award_no'];
		$awardSeq = $row['coeus_award_seq'];
		if ($form == "award") {
			if ($awardNo && $awardSeq) {
				$awards[$awardNo."___".$awardSeq] = array("prefix" => $form, "award_no" => $awardNo, "award_seq" => $awardSeq, "DATA" => json_encode($row));
			} else {
				echo "Does not have award_no $awardNo and award_seq $awardSeq\n";
			}
		} else {
			if ($awardNo && $awardSeq && $personName) {
				if (isset($awards[$awardNo."___".$awardSeq])) {
					$json2 = $awards[$awardNo."___".$awardSeq]["DATA"];
					$row2 = json_decode($json2, true);
					foreach ($row2 as $field => $value) {
						$row[$field] = $value;
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

# as stated, stips quotes and trims
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

# COEUS formats as "lastname, firstname MI"
# This needs to be broken up to match with Newman
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

# Ho/Holden creates a lot of trouble for this algorithm. They are handled in a special script
# Everything else is normal. Some regular expressions here. Should be all lower case.
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

# one name has ???; eliminate
# trims trailing initial.
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

# combines two rows
# row overwrites row2 in case of direct conflict
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

# makes the row a coeus row with a repeatable instance
function formatCoeusRow($row, $instance) {
	$rowData = json_decode($row['DATA'], true);
	$rowData['redcap_repeat_instance'] = $instance;
	$rowData['redcap_repeat_instrument'] = "coeus";
	return $rowData;
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

# tells us whether to skip one of these rows
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

# match on name all of the disparate data sources
# match() function is central here
# must be in Newman Data as this serves as the basis
$skip = array("vfrs", "coeus");
$combined = array();
$record_id = 1;
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

				# send in batches of 200+
				# one row is all non-repeatable data -OR- one instance of repeatable data
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
					echo "Upload ".count($queue)." rows: $output\n";
					curl_close($ch);

					$queue = array();
				}
				$uploadNames = array( "first_name" => fixToCompare($upload[0]['first_name']), "last_name" => fixToCompare($upload[0]['last_name']) );
				$sentNames[] = $uploadNames;
			}
		}
	}
}

# send any leftover data in one last upload
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
	echo "Upload ".count($queue)." rows\n";
	curl_close($ch);
}
echo "$numRows rows uploaded into ".($record_id - 1)." records.\n";
