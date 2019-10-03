<?php

# usage: php 2h_installVFRS.php <database> <record #>
# performs an upload of the VFRS data to the REDCap database

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");

echo "SERVER: ".$server."\n";
echo "TOKEN: ".$token."\n";
echo "PID: ".$pid."\n";
echo "\n";

$record = "";
if (isset($argv[2])) {
	$record = $argv[2];
}
if (!$record) {
	die("No record specified: php 2h_installVFRS.php <database> <record #>\n");
}
echo "RECORD: ".$record."\n";

$redcapData = Download::fieldsForRecords($token, $server, array("record_id", "identifier_first_name", "identifier_last_name"), array($record));
echo "Downloaded ".count($redcapData)." rows\n";


# the names list keeps track of the names for each prefix
# will eventually be used to combine the data
$prefix = "summary";
$names[$prefix] = array();
foreach ($redcapData as $row) {
	echo "Adding ".json_encode($row)."\n";
	$names[$prefix][] = array("prefix" => $prefix, "record_id" => $row['record_id'], "first_name" => $row['identifier_first_name'], "last_name" => $row['identifier_last_name'], "DATA" => json_encode($row));
}

# Token for VFRS
$vfrs_token = 'A987974FEEBDA008EB3200B182EAD1EE';

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

echo "Downloading VFRS data\n";
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
curl_setopt($ch, CURLOPT_URL, 'https://redcap.vanderbilt.edu/api/');
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
echo "Downloaded ".count($vfrsData)." rows\n";

$prefix = "vfrs";
$names[$prefix] = array();
$skip = array("ecommons");
foreach ($vfrsData as $row) {
	$id = $row['participant_id'];
	$firstName = $row['name_first'];
	$lastName = $row['name_last'];
	$row2 = array();
	foreach ($row as $field => $value) {
		if (!preg_match("/_complete$/", $field) && !in_array($field, $skip)) {
			$row2[strtolower("vfrs_".$field)] = $value;
		}
	}
	if ($id && $firstName && $lastName) {
		$names[$prefix][] = array("prefix" => $prefix, "participant_id" => $id, "first_name" => $firstName, "last_name" => $lastName, "DATA" => json_encode($row2));
	}
}
unset($vfrsData);

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
	$n = strtolower($n);
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
					echo "MATCH\n";
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

	$fields = array("record_id", "redcap_repeat_instrument", "redcap_repeat_instance");
	foreach ($fields as $field) {
		if (isset($rowData[$field])) {
			echo "Assigning $field to {$rowData[$field]}\n";
			$combinedData[$field] = $rowData[$field];
		} else if (isset($row2Data[$field])) {
			echo "Assigning 2 $field to {$row2Data[$field]}\n";
			$combinedData[$field] = $row2Data[$field];
		} else {
			echo "Assigning 3 $field to ''\n";
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
		echo "Error decoding ".json_encode($row2)."\n";
	}
	if ($rowData) {
		foreach ($rowData as $field => $value) {
			if (!in_array($field, $fields)) {
				$combinedData[$field] = $value;
			}
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
	echo "Exploring ".$prefix.": ".count($rows)." rows\n";
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
				echo "$prefix DUPLICATE at $firstName1 $firstName2 $lastName1 $lastName2\n";
			} else if (skip($firstName1, $lastName1)) {
				echo "$prefix SKIP at $firstName1 $lastName1\n";
			} else {
				echo "$prefix MATCH at $firstName1 $lastName1\n";
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
					}
				}
				if (isset($row['DATA'])) {
					$rowData = json_decode($row['DATA'], true);
					$rowData['redcap_repeat_instrument'] = "";
					$rowData['redcap_repeat_instance'] = "";
					$rowData = adjustForVFRS($rowData);
					array_unshift($upload, $rowData);
					$numRows += count($upload);

					foreach ($upload as $uploadRow) {
						$queue[] = $uploadRow;
					}
				}
				if (count($upload) > 0) {
					$uploadNames = array( "first_name" => fixToCompare($upload[0]['identifier_first_name']), "last_name" => fixToCompare($upload[0]['identifier_last_name']) );
					$sentNames[] = $uploadNames;
				}
			}
		}
	}
}

echo "queue: ".count($queue)."\n";
# send any leftover data in one last upload
if (count($queue) > 0) {
	$feedback = Upload::rows($queue, $token, $server);
	echo "Upload ".count($queue)." rows ".json_encode($feedback)."\n";
}
echo "$numRows rows uploaded into ".($record_id - 1)." records.\n";
