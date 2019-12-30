<?php

use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Download;

require_once(dirname(__FILE__)."/../../../redcap_connect.php");
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(APP_PATH_DOCROOT."Classes/Records.php");

# This script copies the master project to a test project

if (!isset($argv[1])) {
    $argv[1] = "test";
}
if ($argv[1] == "prodtest") {
	$info['test']['token'] = $info['prodtest']['token'];
	$info['test']['server'] = $info['prodtest']['server'];
} else if ($argv[1] == "dev") {
	$info['test']['token'] = $info['dev']['token'];
	$info['test']['server'] = $info['dev']['server'];
} else if ($argv[1] == "backup") {
	$info['test']['token'] = $info['prod']['token'];
	$info['test']['server'] = $info['prod']['server'];
}

$selectRecord = "";
if (isset($argv[2])) {
	$selectRecord = $argv[2];
}

echo "DESTINATION: ".$info['test']['server']."\n";
echo "DESTINATION: ".$info['test']['token']."\n";
echo "\n";

$feedback = resetRepeatingInstruments($info['prod']['token'], $info['prod']['server'], $info['test']['token'], $info['test']['server']);
$output = json_encode($feedback);
echo "Copied repeating instruments: ".$output."\n";

# get source's metadata
$metadata = Download::metadata($info['prod']['token'], $info['prod']['server']);
echo "Downloaded metadata ".count($metadata)."\n";

# upload to test's metadata
$feedback = Upload::metadata($info['test']['token'], $info['test']['server']);
$output = json_encode($feedback);
echo "Uploaded metadata $output\n";

# get source's record id's
$prodRecords = Download::recordIds($info['prod']['token'], $info['prod']['server']);
if ($selectRecord) {
	if (in_array($selectRecord, $prodRecords)) {
		$prodRecords = array($selectRecord);
	} else {
		$prodRecords = array();
	}
}

# get count of test records
$testRecords = Download::recordIds($info['test']['token'], $info['test']['server']);

echo "List of ".count($prodRecords)." on prod and ".count($testRecords)." on test\n";

# delete from test
if ((count($testRecords) > 0) && (!$selectRecord)) {
	echo "Deleting records on test...\n";
	$feedback = deleteRecords($info['test']['token'], $info['test']['server']);
	$output = json_encode($feedback);
	echo "Deleted records on test: $output\n";
}

# download/upload in pulls
$pullSize = 1;
$totalPulls = floor((count($prodRecords) + $pullSize - 1) / $pullSize);
$count = "";
if (isset($argv[1])) {
	$count = $argv[1];
}

for ($i = 0; $i < count($prodRecords); $i += $pullSize) {
	$records = array();
	$j = $i;
	$n = floor(($i + $pullSize - 1) / $pullSize) + 1;
	while ((($j == $i) || ($j % $pullSize != 0)) && ($j < count($prodRecords))) {
		$records[] = $prodRecords[$j];
		$j++;
	}
	if (($count != '50') || ($i === 0)) {
		echo "$i. Download ".$n." of $totalPulls\n";
		$pullData = Download::records($info['prod']['token'], $info['prod']['server'], $records);
		echo "$i. Downloaded ".count($pullData)." rows\n";

		$skip = array("followup_complete");
		$pushData = array();
		foreach ($pullData as $pullRow) {
			$pushRow = array();
			foreach ($pullRow as $field => $value) {
				if (!in_array($field, $skip)) {
					$pushRow[$field] = $value;
				}
			}
			array_push($pushData, $pushRow);
		}

		$feedback = Upload::rows($pushData, $info['test']['token'], $info['test']['server']);
		error_log("$i. Upload ".$n." of $totalPulls: ".json_encode($feedback));
	}
} 
Records::addRecordToRecordListCache();
