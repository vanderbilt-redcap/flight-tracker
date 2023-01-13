<?php
namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../small_base.php");

$mdyfields = array(
			"check_date_of_birth",
			"check_academic_rank_dt",
			"check_prev1_academic_rank_stdt",
			"check_prev1_academic_rank_enddt",
			"check_prev2_academic_rank_stdt",
			"check_prev2_academic_rank_enddt",
			"check_prev3_academic_rank_stdt",
			"check_prev3_academic_rank_enddt",
			"check_prev4_academic_rank_stdt",
			"check_prev4_academic_rank_enddt",
			"check_prev5_academic_rank_stdt",
			"check_prev5_academic_rank_enddt",
			"check_grant1_start",
			"check_grant1_end",
			"check_grant2_start",
			"check_grant2_end",
			"check_grant3_start",
			"check_grant3_end",
			"check_grant4_start",
			"check_grant4_end",
			"check_grant5_start",
			"check_grant5_end",
			"check_grant6_start",
			"check_grant6_end",
			"check_grant7_start",
			"check_grant7_end",
			"check_grant8_start",
			"check_grant8_end",
			"check_grant9_start",
			"check_grant9_end",
			"check_grant10_start",
			"check_grant10_end",
			"check_grant11_start",
			"check_grant11_end",
			"check_grant12_start",
			"check_grant12_end",
			"check_grant13_start",
			"check_grant13_end",
			"check_grant14_start",
			"check_grant14_end",
			"check_grant15_start",
			"check_grant15_end",
		);
$fields = array(
		"newman_demographics_date_of_birth",
		"newman_demographics_first_individual_k_date",
		"newman_demographics_first_r_date",
		"newman_data_individual_k_start",
		"newman_data_date_first_institutional_k_award_newman",
		"newman_data_r01_start",
		"newman_data_date_left_vanderbilt",
		"newman_data_date_of_birth",
		"newman_sheet2_institutional_k_start",
		"newman_sheet2_noninstitutional_start",
		"newman_sheet2_first_r01_date",
		"newman_sheet2_left_vu",
		"vfrs_date_of_birth",
		"coeus_project_start_date",
		"coeus_project_end_date",
		);

$fields2 = $fields;
$fields2[] = "record_id";

$data = array(
	'token' => $token,
	'content' => 'record',
	'format' => 'json',
	'fields' => $fields2,
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
curl_close($ch);
$redcapData = json_decode($output, true);

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

$metadataUpload = array();
foreach ($metadata as $row) {
	if (in_array($row['field_name'], $fields)) {
		$row['text_validation_type_or_show_slider_number'] = "date_ymd";
	}
	if (in_array($row['field_name'], $mdyfields)) {
		$row['text_validation_type_or_show_slider_number'] = "date_mdy";
	}
	$metadataUpload[] = $row;
}

$data = array(
	'token' => $token,
	'content' => 'metadata',
	'format' => 'json',
	'data' => json_encode($metadataUpload),
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
$output = json_encode($metadataUpload);
$output = curl_exec($ch);
print "Metadata Upload: $output\n";
curl_close($ch);

function convert2DigitYear($year) {
	if ($year > 20) {
		return 1900 + $year;
	} else {
		return 2000 + $year;
	}
}

function pad2Digits($str) {
	if (strlen($str) == 2) {
		return $str;
	}
	if (strlen($str) == 1) {
		return "0".$str;
	}
	return "01";
}

function interpretMonth($m) {
	$m = strtolower($m);
	$months = array(
			"jan" => "01",
			"feb" => "02",
			"mar" => "03",
			"apr" => "04",
			"may" => "05",
			"jun" => "06",
			"jul" => "07",
			"aug" => "08",
			"sep" => "09",
			"oct" => "10",
			"nov" => "11",
			"dec" => "12",
			);
	if (isset($months[$m])) {
		return $months[$m];
	}
	return "01";
}

$results = array();
function convertDate2YMD($d, $field, $format, $name) {
	global $results;

	if (!$d) {
		return $d;
	}
	if (preg_match("/none/", $d)) {
		echo "I\n";
		return "";
	}

	$type1 = $format[0];
	$type2 = $format[1];
	$type3 = $format[2];
	$typesYMD = array();
	for ($i = 0; $i < 3; $i++) {
		$typesYMD[$format[$i]] = $i;
	}

	$d = trim($d);
	$d = preg_replace("/^\(/", "", $d);
	$d = str_replace("\\/", "/", $d);
	if (preg_match("/^\d+[\/\-]\d+[\/\-]\d\d\d\d$/", $d)) {
		$matches = array();
		$splits = preg_split("/[\s;:,]+/", $d);
		foreach ($splits as $s) {
			if (preg_match("/^\d+[\/\-]\d+[\/\-]\d\d\d\d$/", $s)) {
				$matches[] = $s;
			} else {
				echo "$field Cannot convert $s to YMD\n";
				$line = readline("> ");
				$matches[] = trim($line);
			}
		}
		if (count($matches) > 1) {
			# return earliest
			$years = array();
			$formattedDates = array();
			$minYear = 2100;
			$minDate = "01/01/2100";
			foreach ($matches as $match) {
				$nodes = preg_split("/[\/\-]/", $match);
				$years[] = $nodes[$typesYMD['y']];
				$date = $nodes[$typesYMD['y']]."-".pad2Digits($nodes[$typesYMD['m']])."-".pad2Digits($nodes[$typesYMD['d']]);
				$formattedDates[] = $date;
				if ($nodes[$typesYMD['y']] < $minYear) {
					$minYear = $nodes[$typesYMD['y']];
					$minDate = $date;
				} else if ($nodes[2] == $minYear) {
					$minNodes = preg_split("/[\/\-]/", $minDate);
					if ((int) $nodes[$typesYMD['m']] < (int) $minNodes[$typesYMD['m']]) {
						$minDate = $date;
					} else if ((int) $nodes[$typesYMD['m']] == (int) $minNodes[$typesYMD['m']]) {
						if ((int) $nodes[$typesYMD['d']] < (int) $minNodes[$typesYMD['d']]) {
							$minDate = $date;
						}
					}
				} 
			}
			echo "A ";
			return $minDate;
		}
	}
	if (preg_match("/^\d+[\/\-]\d\d\d\d$/", $d)) {
		$nodes = preg_split("/[\/\-]/", $d);
		echo "B ";
		return $nodes[1]."-".pad2Digits($nodes[0])."-01";
	}
	if (preg_match("/^\d+[\/\-]\d+[\/\-]\d\d\d\d$/", $d)) {
		$nodes = preg_split("/[\/\-]/", $d);
		echo "C ";
		return $nodes[2]."-".pad2Digits($nodes[$typesYMD['m']])."-".pad2Digits($nodes[$typesYMD['d']]);
	}
	if (preg_match("/^\d+[\/\-]\d\d$/", $d)) {
		$nodes = preg_split("/[\/\-]/", $d);
		$year = convert2DigitYear($nodes[1]);
		echo "D ";
		return $year."-".pad2Digits($nodes[0])."-01";
	}
	if (preg_match("/^\d\d\d\d[\/\-]\d+[\/\-]\d+$/", $d)) {
		$nodes = preg_split("/[\/\-]/", $d);
		echo "D1 ";
		return $nodes[$typesYMD['y']]."-".pad2Digits($nodes[$typesYMD['m']])."-".pad2Digits($nodes[$typesYMD['d']]);
	}
	if (preg_match("/^\d+[\/\-]\d+[\/\-]\d\d$/", $d)) {
		$nodes = preg_split("/[\/\-]/", $d);
		$year = convert2DigitYear($nodes[$typesYMD['y']]);
		echo "E ";
		return $year."-".pad2Digits($nodes[$typesYMD['m']])."-".pad2Digits($nodes[$typesYMD['d']]);
	}
	if (preg_match("/^\d\d\d\d$/", $d)) {
		echo "F ";
		return $d."-01-01";
	}
	if (preg_match("/^\d\d[\/\-]\w\w\w[\/\-]\d\d$/", $d)) {
		$nodes = preg_split("/[\/\-]/", $d);
		$month = interpretMonth($nodes[1]);
		$year = convert2DigitYear($nodes[$typesYMD['y']]);
		$day = pad2Digits($nodes[$typesYMD['d']]);
		echo "G ";
		return $year."-".$month."-".$day;
	}
	if (preg_match("/^\d+[\/\-]\w\w\w$/", $d)) {
		$nodes = preg_split("/[\/\-]/", $d);
		$year = convert2DigitYear($nodes[0]);
		$month = interpretMonth($nodes[1]);
		$day = "01";
		echo "H ";
		return $year."-".$month."-".$day;
	}
	#mdy in middle of string
	if (preg_match("/\d+\/\d+\/\d\d\d\d/", $d, $matches)) {
		$nodes = preg_split("/\//", $matches[0]);
		echo "I ";
		return $nodes[2]."-".pad2Digits($nodes[0])."-".pad2Digits($nodes[1]);
	}
	$line = "";
	if (isset($results[$d])) {
		$line = $results[$d];
	}
	echo json_encode($results)."\n";
	echo "$field Cannot convert $d to YMD\n";
	while (!preg_match("/^\d\d\d\d-\d\d-\d\d$/", $line)) {
		$line = readline($name."YYYY-MM-DD> ");
		$line = trim($line);
	}
	$results[$d] = $line;
	return $line;
}

$i = 0;
$format = array();
foreach ($redcapData[0] as $field => $value) {
	if (in_array($field, $fields)) {
		$numFound = 0;
		echo "$field\n";
		foreach ($redcapData as $row2) {
			foreach ($row2 as $field2 => $value2) {
				if (($field == $field2) && ($value2)) {
					echo $value2."\n";
					$numFound++;
				}
			}
			if ($numFound > 15) {
				$line = readline("type (ymd, dmy, mdy)> ");
				$line = trim($line);
				$format[$field] = $line;
				break;
			}
		}
	}
}
$i = 0;
foreach ($redcapData as $row) {
	foreach ($row as $field => $value) {
		if (in_array($field, $fields)) {
			$date = convertDate2YMD($value, $field, $format[$field], $row['first_name']." ".$row['last_name']);
			if ($date) {
				// echo "Converted $value to $date\n";
				if ($date == "2000-01-01") {
					echo "ERROR: Look at {$row['record_id']} $value to $date\n"; 
				}
			}
			$redcapData[$i][$field] = $date;
		}
	}
	$i++;
}

echo count($redcapData)." rows\n";

$data = array(
	'token' => $token,
	'content' => 'record',
	'format' => 'json',
	'type' => 'flat',
	'overwriteBehavior' => 'overwrite',
	'data' => json_encode($redcapData),
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
echo $output."\n";
curl_close($ch);

?>
