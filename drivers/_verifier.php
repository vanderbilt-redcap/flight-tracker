<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../small_base.php");

$data = [
	'token' => $token,
	'content' => 'metadata',
	'format' => 'json',
	'returnFormat' => 'json'
];
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
$fieldTypes = [];
foreach ($metadata as $row) {
	$fieldTypes[$row['field_name']] = $row['field_type'];
}

$data = [
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
];
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

function getPrefix($field) {
	$nodes = preg_split("/_/", $field);
	if ($nodes[0] == "coeus") {
		return $nodes[0];
	} elseif ($nodes[0] == "vfrs") {
		return $nodes[0];
	}
	return $nodes[0]."_".$nodes[1];
}


$files = [
		"Nonrespondents.clean.csv" => "nonrespondents",
		"newman2016_data_new.clean.csv" => "data",
		"newman2016_demo.clean.csv" => "demographics",
		"newman2016_data.clean.csv" => "data",
		"newman2016_sheet2.clean.csv" => "sheet2",
		"KL2.clean.csv" => "kl2",
		];
# when labels are duplicated, as in data_new and data, the label goes with the
# later file
$orderedFiles = [
			"newman2016_demo.clean.csv" => "newman_",
			"newman2016_data_new.clean.csv" => "newman_",
			"newman2016_data.clean.csv" => "newman_",
			"newman2016_sheet2.clean.csv" => "newman_",
			"Nonrespondents.clean.csv" => "newman_",
			"KL2.clean.csv" => "kl2_",
			];

$counts = [];
$totals = [];
foreach ($orderedFiles as $file => $prefix) {
	$counts[$prefix.$files[$file]] = 0;
	$totals[$prefix.$files[$file]] = 0;
}
$counts["vfrs"] = 0;
$counts["coeus"] = 0;
$totals["vfrs"] = 0;
$totals["coeus"] = 0;

function stringLike($str1, $str2) {
	if (($str1 == "ho") && ($str2 == "holden")) {
		return false;
	}
	if (($str2 == "ho") && ($str1 == "holden")) {
		return false;
	}
	$str1 = str_replace("???", "", $str1);
	$str2 = str_replace("???", "", $str2);
	if ($str1 && preg_match("/".$str1."/", $str2)) {
		return true;
	}
	if ($str2 && preg_match("/".$str2."/", $str1)) {
		return true;
	}
	return false;
}

function getVariations($name) {
	$names = preg_split("/[\s-]+/", $name);
	$outnames = [];
	foreach ($names as $n) {
		if ($n != "") {
			if (preg_match("/^\(.+\)$/", $n)) {
				$n = preg_replace("/^\(/", "", $n);
				$n = preg_replace("/\)$/", "", $n);
			}
			$outnames[] = $n;
		}
	}
	return $outnames;
}

function getNewmanDataForMatch($data, $matches) {
	foreach ($data as $row) {
		if ($row['redcap_repeat_instrument'] == "") {
			$dataFirstNames = getVariations(strtolower($row['first_name']));
			$dataLastNames = getVariations(strtolower($row['last_name']));
			$lineFirstNames = getVariations(strtolower($matches["first_name"]));
			$lineLastNames = getVariations(strtolower($matches["last_name"]));
			foreach ($dataFirstNames as $dataFirstName) {
				foreach ($dataLastNames as $dataLastName) {
					foreach ($lineFirstNames as $lineFirstName) {
						foreach ($lineLastNames as $lineLastName) {
							if (stringLike($dataFirstName, $lineFirstName) && stringLike($dataLastName, $lineLastName)) {
								return $row;
							}
						}
					}
				}
			}
		}
	}
	return [];
}

foreach ($orderedFiles as $file => $prefix) {
	$prefix = $prefix.$files[$file]."_";
	echo "Opening $file\n";
	$fp = fopen($file, "r");
	$i = 0;
	$headers = [];
	$matches = ["first_name", "last_name"];
	while ($line = fgetcsv($fp)) {
		if ($i == 0) {
		} elseif ($i == 1) {
			$headers = $line;
		} elseif (($i == 2) && ($headers[0] == "") && ($headers[1] == "") && ($headers[2] == "")) {
			$headers = $line;
		} else {
			$myMatches = [];
			foreach ($matches as $match) {
				$myMatches[$match] = "";
			}
			for ($j = 0; $j < count($line) && $j < count($headers); $j++) {
				if (in_array($headers[$j], $matches)) {
					$myMatches[$headers[$j]] = $line[$j];
				}
			}
			$row = getNewmanDataForMatch($redcapData, $myMatches);
			if (empty($row)) {
				echo "No matches for ".json_encode($myMatches)."\n";
			} else {
				$skip = ["vunetid", "middle_name"];
				for ($j = 0; $j < count($line) && $j < count($headers); $j++) {
					if (!in_array($headers[$j], $matches) && $headers[$j] && !in_array($headers[$j], $skip)) {
						$field = $prefix.$headers[$j];
						$totals[getPrefix($field)]++;
						if (isset($row[$field])) {
							if (($row[$field] == "") && ($line[$j] != "")) {
								// echo "$i ({$row['record_id']}): ".json_encode($myMatches)." Field $field is blank; line {$line[$j]} is not.\n";
								$counts[getPrefix($field)]++;
							} elseif (($row[$field] != "") && ($line[$j] == "")) {
								// echo "$i ({$row['record_id']}): ".json_encode($myMatches)." Field $field is not blank ({$row[$field]}); line {$line[$j]} is blank.\n";
								$counts[getPrefix($field)]++;
							} elseif (($fieldTypes[$field] == 'text') && ($row[$field] != $line[$j])) {
								// echo "$i ({$row['record_id']}): ".json_encode($myMatches)." Field $field ({$row[$field]}) and line {$line[$j]} are not equal.\n";
								$counts[getPrefix($field)]++;
							}

						} else {
							echo "$i ({$row['record_id']}): ".json_encode($myMatches)." Could not find $field\n";
							$counts[getPrefix($field)]++;
						}
					}
				}
			}
		}
		$i++;
	}
	fclose($fp);
}

$coeusFiles = ["career_dev/coeus_award.json", "career_dev/coeus_investigator.json"];
foreach ($coeusFiles as $file) {
	echo "Opening $file\n";
	$fp = fopen($file, "r");
	$json = trim(fgets($fp));
	fclose($fp);
	$coeusData = json_decode($json, true);
	unset($json);
	foreach ($coeusData as $row) {
		$coeusRow = [];
		foreach ($row as $field => $value) {
			if (!isset($value)) {
				$coeusRow["coeus_".strtolower($field)] = "";
			} else {
				$coeusRow["coeus_".strtolower($field)] = $value;
			}
		}
		$awardNo = $coeusRow["coeus_award_no"];
		$i = 0;
		foreach ($redcapData as $rcRow) {
			if (($rcRow['redcap_repeat_instrument'] == "coeus") && ($coeusRow['coeus_award_no'] == $rcRow['coeus_award_no']) && ($coeusRow['coeus_award_seq'] == $rcRow['coeus_award_seq'])) {
				$myMatches = ["first_name" => "", "last_name" => ""];
				foreach ($redcapData as $rcRow2) {
					if (($rcRow['record_id'] == $rcRow2['record_id']) && ($rcRow2['redcap_repeat_instance'] == "")) {
						foreach ($myMatches as $field => $value) {
							if ($rcRow2[$field]) {
								$myMatches[$field] = $rcRow2[$field];
							}
						}
						break;
					}
				}
				$skip = ["coeus_person_name"];
				foreach ($coeusRow as $field => $value) {
					if (!in_array($field, $skip)) {
						$totals[getPrefix($field)]++;
						if (isset($rcRow[$field])) {
							if (($rcRow[$field] == "") && ($value != "")) {
								echo "$i ({$rcRow['record_id']} {$rcRow['coeus_award_no']}): ".json_encode($myMatches)." Field $field is blank; json $value is not.\n";
								$counts[getPrefix($field)]++;
							} elseif (($rcRow[$field] != "") && ($value == "")) {
								echo "$i ({$rcRow['record_id']} {$rcRow['coeus_award_no']}): ".json_encode($myMatches)." Field $field is not blank ({$rcRow[$field]}); json $value is blank.\n";
								$counts[getPrefix($field)]++;
							} elseif (($fieldTypes[$field] == 'text') && ($rcRow[$field] != $value)) {
								echo "$i ({$rcRow['record_id']} {$rcRow['coeus_award_no']}): ".json_encode($myMatches)." Field $field ({$rcRow[$field]}) and json $value are not equal.".json_encode($coeusRow)."\n";
								$counts[getPrefix($field)]++;
							}
						} else {
							echo "$i ({$rcRow['record_id']}) {$rcRow['coeus_award_no']}: ".json_encode($myMatches)." Could not find $field\n";
							$counts[getPrefix($field)]++;
						}
					}
				}
			}
			$i++;
		}

	}
	unset($coeusData);
}

echo "\n\n";
foreach ($counts as $type => $cnt) {
	echo "$type: $cnt/".$totals[$type]."\n";
}
