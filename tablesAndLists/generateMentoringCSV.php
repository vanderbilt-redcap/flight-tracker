<?php

use \Vanderbilt\CareerDevLibrary\Download;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Download.php");

$metadata = Download::metadata($token, $server);

$possHeaders = array(
			"record_id",
			"identifier_first_name", "identifier_last_name",
			"summary_mentor",
			"override_mentor",
			"check_primary_mentor",
			"vfrs_mentor1", "vfrs_mentor2", "vfrs_mentor3", "vfrs_mentor4", "vfrs_mentor5",
			"newman_data_mentor1", "newman_data_mentor2",
			"newman_sheet2_mentor1", "newman_sheet2_mentor2",
			"newman_new_mentor1",
			"followup_primary_mentor",
		);
$headers = array();
foreach ($metadata as $row) {
	if (in_array($row['field_name'], $possHeaders)) {
		array_push($headers, $row['field_name']);
	}
}

$redcapData = Download::fields($token, $server, $headers);
$lines = array();

$linesRow = array();
foreach ($redcapData as $row) {
	if ($linesRow['record_id'] && ($row['record_id'] != $linesRow['record_id'])) {
		$lines[] = $linesRow;
		$linesRow = array();
	}
	foreach ($headers as $item) {
		if ($item != "followup_primary_mentor") {
			if ($row['redcap_repeat_instrument'] == "") {
				$linesRow[$item] = $row[$item];
			}
		} else {
			if (!isset($linesRow[$item])) {
				$linesRow[$item] = array();
			}
			if ($row['redcap_repeat_instrument'] == "followup") {
				$linesRow[$item][] = $row[$item];
			}
		}
	}
}
$lines[] = $linesRow;

$numHeaders = array();
foreach ($headers as $item) {
	$numHeaders[$item] = 1;
}
foreach ($lines as $line) {
	foreach ($line as $item => $values) {
		$n = 1;
		if (is_array($values)) {
			$n = count($values);
		}
		if ($n > $numHeaders[$item]) {
			$numHeaders[$item] = $n;
		}
	}
}

$filename = 'mentors_' . date('Ymd') .'_' . date('His').".csv";
header("Pragma: public");
header("Expires: 0");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
header("Cache-Control: private",false);
header("Content-Type: application/octet-stream");
header("Content-Disposition: attachment; filename=\"$filename\";" );
header("Content-Transfer-Encoding: binary");

$fp = fopen('php://output', 'w');
$firstRow = array();
foreach ($headers as $item) {
	for ($i = 0; $i < $numHeaders[$item]; $i++) {
		$firstRow[] = $item;
	}
}
fputcsv($fp, $firstRow);
foreach ($lines as $line) {
	$thisRow = array();
	foreach ($line as $item => $values) {
		if (is_array($values)) {
			for ($i = 0; $i < $numHeaders[$item]; $i++) {
				if ($values[$i]) {
					$thisRow[] = $values[$i];
				} else {
					$thisRow[] = "";
				}
			}
		} else {
			$thisRow[] = $values;
			for ($i = 1; $i < $numHeaders[$item]; $i++) {
				$thisRow[] = "";
			}
		}
	}
	fputcsv($fp, $thisRow);
}
fclose($fp);


