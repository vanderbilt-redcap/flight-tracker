<?php

namespace Vanderbilt\CareerDevLibrary;

define('NOAUTH', true);
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../../../redcap_connect.php");

echo "Placing in ".APP_PATH_TEMP."\n\n";

/**
  * gets the instance for the exporter form based on existing data
  * if none defined, returns [max + 1 or 1 (if there is no max), $new]
  * $new is boolean; TRUE if new instance; else FALSE
  * @param int $recordId
  * @param array $upload List of records to upload
  */
function getExPORTERInstance($recordId, $redcapData, $upload, $uploadLine) {
	$maxInstance = 0;

	foreach ($redcapData as $row) {
		if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "exporter") && ($maxInstance < $row['redcap_repeat_instance'])) {
			$maxInstance = $row['redcap_repeat_instance'];
			$same = true;
			foreach ($uploadLine as $field => $value) {
				if ($row[$field] != $value) {
					$same = false;
					break;
				}
			}
			if ($same) {
				return [$row['redcap_repeat_instance'], false];
			}
		}
	}
	foreach ($upload as $row) {
		if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "exporter") && ($maxInstance < $row['redcap_repeat_instance'])) {
			$maxInstance = $row['redcap_repeat_instance'];
		}
	}

	return [$maxInstance + 1, true];
}

/**
 * download a file from NIH ExPORTER, unzip, returns absolute filename
 * returns empty string if $file not found
 * @param string $file Filename without leading URL
 */
function downloadURLAndUnzip($file) {
	$csvfile = preg_replace("/.zip/", ".csv", $file);
	if (!file_exists(APP_PATH_TEMP.$csvfile)) {
		echo "Downloading $file...\n";

		$url = "https://exporter.nih.gov/CSVs/final/".$file;
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		$zip = curl_exec($ch);
		$resp = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		if ($resp == 200) {
			echo "Unzipping $file...\n";
			$fp = fopen(APP_PATH_TEMP.$file, "w");
			fwrite($fp, $zip);
			fclose($fp);

			$za = new ZipArchive();
			if ($za->open(APP_PATH_TEMP.$file) === true) {
				$za->extractTo(APP_PATH_TEMP);
				$za->close();
				return APP_PATH_TEMP.$csvfile;
			}
		}
		return "";
	} else {
		return APP_PATH_TEMP.$csvfile;
	}
}

$files = [];

// echo APP_PATH_TEMP."\n";
// $files[] = downloadURLAndUnzip("RePORTER_PRJ_C_FY2018_048.zip");
// echo $files[0]."\n";

# find relevant zips
# download relevent zips into APP_PATH_TEMP
# unzip zip files
for ($year = 2009; $year <= date("Y"); $year++) {
	$url = "RePORTER_PRJ_C_FY".$year.".zip";
	$file = downloadURLAndUnzip($url);
	if ($file) {
		$files[$file] = $year;
	}
}
for ($year = date("Y") - 1; $year <= date("Y") + 1; $year++) {
	for ($week = 1; $week <= 53; $week++) {
		$weekWithLeading0s = sprintf('%03d', $week);
		$url = "RePORTER_PRJ_C_FY".$year."_".$weekWithLeading0s.".zip";
		$file = downloadURLAndUnzip($url);
		if ($file) {
			$files[$file] = $year;
		}
	}
}


echo "Downloading REDCap\n";
global $token, $server;

$data = [
	'token' => $token,
	'content' => 'record',
	'format' => 'json',
	'type' => 'flat',
	'forms' => ["custom", "exporter"],
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

/**
 * Make an array of REDCap fields from a line read in from the CSV
 * @param $line array read from CSV that corresponds with the order in $headers
 * @param $headers array read from CSV that consist of the headers for each row
 * constraint: count($line) == count($headers)
 */
function makeUploadHoldingQueue($line, $headers) {
	$j = 0;
	$dates = ["exporter_budget_start", "exporter_budget_end", "exporter_project_start", "exporter_project_end"];
	$uploadLineHoldingQueue = [];
	foreach ($line as $item) {
		$field = "exporter_".strtolower($headers[$j]);
		if (in_array($field, $dates)) {
			$item = MDY2YMD($item);
		}
		$uploadLineHoldingQueue[$field] = REDCapmanagement::convert_from_latin1_to_utf8_recursively($item);
		$j++;
	}
	$uploadLineHoldingQueue['exporter_last_update'] = date("Y-m-d");
	return $uploadLineHoldingQueue;
}

# download names and ExPORTER instances from REDCap
# parse CSVs with screen for Vanderbilt and names
$institutions = ["/".INSTITUTION."/i", "/Meharry/i"];
$cities = ["/Nashville/i"];
$matchingQueries = ["ORG_NAME" => $institutions, "ORG_CITY" => $cities];
$upload = [];
$newUploads = [];		// new records
foreach ($files as $file => $year) {
	$fp = fopen($file, "r");
	$i = 0;
	$headers = [];
	while ($line = fgetcsv($fp)) {
		if ($i === 0) {
			$j = 0;
			foreach ($line as $item) {
				$headers[$j] = $item;
				$j++;
			}
		} else {
			$j = 0;
			$possibleMatch = false;
			$firstNames = [];
			$lastNames = [];
			foreach ($line as $item) {
				foreach ($matchingQueries as $column => $reAry) {
					if ($column == $headers[$j]) {
						foreach ($reAry as $re) {
							if (preg_match($re, $item)) {
								$possibleMatch = true;
							}
						}
						break;
					}
				}
				if ("PI_NAMEs" == $headers[$j]) {
					$names = preg_split("/\s*;\s*/", $item);
					foreach ($names as $name) {
						if ($name && !preg_match("/MOSES, HAROLD L/i", $name)) {
							$name = preg_replace("/\s*\(contact\)/", "", $name);
							if (preg_match("/,/", $name)) {
								list($last, $firstWithMiddle) = preg_split("/\s*,\s*/", $name);
								if (preg_match("/\s+/", $firstWithMiddle)) {
									list($first, $middle) = preg_split("/\s+/", $firstWithMiddle);
								} else {
									$first = $firstWithMiddle;
									$middle = "";
								}
								$firstNames[] = $first;
								$lastNames[] = $last;
							} else {
								$last = "";
								$firstWithMiddle = "";
							}
						}
					}
				}
				$j++;
			}
			if ($possibleMatch) {
				for ($k = 0; $k < count($firstNames); $k++) {
					$recordId = matchName($firstNames[$k], $lastNames[$k]);
					if ($recordId && $firstNames[$k] && $lastNames[$k]) {
						# upload line
						$uploadLine = ["record_id" => $recordId, "redcap_repeat_instrument" => "exporter"];
						$uploadLineHoldingQueue = makeUploadHoldingQueue($line, $headers);
						list($uploadLine["redcap_repeat_instance"], $isNew) = getExPORTERInstance($recordId, $redcapData, $upload, $uploadLineHoldingQueue);
						if ($isNew) {
							$uploadLine = array_merge($uploadLine, $uploadLineHoldingQueue);
							echo "Matched name {$recordId} {$firstNames[$k]} {$lastNames[$k]} = {$uploadLine['exporter_pi_names']}\n";
							$upload[] = $uploadLine;
						}
					} elseif (!$recordId && $firstNames[$k] && $lastNames[$k] && $year >= date("Y")) {
						# new person?
						$j = 0;
						$isK = false;
						$isSupportYear1 = false;
						foreach ($line as $item) {
							if (strtolower($headers[$j]) == "full_project_num") {
								$awardNo = $item;
								if (preg_match("/^\d[Kk]\d\d/", $awardNo) || preg_match("/^[Kk]\d\d/", $awardNo)) {
									$addToNewUploads = true;
								}
							}
							if (strtolower($headers[$j]) == "support_year") {
								if ($item == 1) {
									$isSupportYear1 = true;
								}
							}
							$j++;
						}
						if ($isK && $isSupportYear1) {
							$uploadLineHoldingQueue = makeUploadHoldingQueue($line, $headers);
							$newUploads[$firstNames[$k]." ".$lastNames[$k]] = $uploadLineHoldingQueue;
						}
					}
				}
			}
		}
		$i++;
	}
	echo "Inspected $i lines\n";
	fclose($fp);
}

# add newUploads to uploads
$maxRecordId = 0;
foreach ($redcapData as $row) {
	if ($row['record_id'] > $maxRecordId) {
		$maxRecordId = $row['record_id'];
	}
}
foreach ($newUploads as $fullName => $uploadLineHoldingQueue) {
	$maxRecordId++;

	$uploadLine = ["record_id" => $maxRecordId, "redcap_repeat_instrument" => "exporter"];
	list($uploadLine["redcap_repeat_instance"], $isNew) = getExPORTERInstance($recordId, $redcapData, $upload, $uploadLineHoldingQueue);
	$uploadLine = array_merge($uploadLine, $uploadLineHoldingQueue);

	echo "Found new name {$maxRecordId} {$fullName} --> {$uploadLine['exporter_full_project_num']}\n";

	list($firstName, $lastName) = preg_split("/\s/", $fullName);
	$upload[] = ["record_id" => $maxRecordId, "redcap_repeat_instrument" => "", "redcap_repeat_instance" => "", "identifier_first_name" => ucfirst(strtolower($firstName)), "identifier_last_name" => ucfirst(strtolower($lastName))];
	$upload[] = $uploadLine;
}

# upload to REDCap
$data = [
	'token' => $token,
	'content' => 'record',
	'format' => 'json',
	'type' => 'flat',
	'overwriteBehavior' => 'normal',
	'forceAutoNumber' => 'false',
	'data' => json_encode($upload),
	'returnContent' => 'count',
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

echo count($upload)." rows\n";
echo $output."\n";

$mssg = "NIH Exporter run\n\n".count($upload)." rows\n".$output."\n\n";
\REDCap::email($adminEmail, "no-reply@vanderbilt.edu", "CareerDev NIH Exporter", $mssg);

CareerDev::saveCurrentDate("Last NIH ExPORTER Download", $pid);
