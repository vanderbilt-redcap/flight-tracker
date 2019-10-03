<?php

require_once(dirname(__FILE__)."/../small_base.php");

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

	// $info['prod']['token'] = "2C91720C83191C9AB471CBDE0D404094";
	// $info['prod']['token'] = "EF5C8DAF7632F8AEFD7C606B841A2D80";
	// $info['prod']['token'] = "52E9090FA1E19EE7FE2656D6B13AEA22";
	// $info['prod']['token'] = "43EB01029FB59E0DAFC893B3EA9BC265";
	$info['prod']['token'] = "4CE353DA1F2348C4D09F4D1826709782";
}

$selectRecord = "";
if (isset($argv[2])) {
	$selectRecord = $argv[2];
}

echo "DESTINATION: ".$info['test']['server']."\n";
echo "DESTINATION: ".$info['test']['token']."\n";
echo "\n";

# get source's metadata
$data = array(
    'token' => $info['prod']['token'],
    'content' => 'metadata',
    'format' => 'json',
    'returnFormat' => 'json'
);
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $info['prod']['server']);
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
echo "Downloaded metadata ".count($metadata)."\n";

# upload to test's metadata
$data = array(
    'token' => $info['test']['token'],
    'content' => 'metadata',
    'format' => 'json',
    'data' => json_encode($metadata),
    'returnFormat' => 'json'
);
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $info['test']['server']);
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

echo "Uploaded metadata $output\n";

# get source's record id's
$data = array(
    'token' => $info['prod']['token'],
    'content' => 'record',
    'format' => 'json',
    'type' => 'flat',
    'fields' => array("record_id"),
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
curl_setopt($ch, CURLOPT_URL, $info['prod']['server']);
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
$prodData = json_decode($output, true);
$prodRecords = array();
foreach ($prodData as $row) {
	if (!in_array($row['record_id'], $prodRecords)) {
		$prodRecords[] = $row['record_id'];
	}
}

# get count of test records
$data = array(
    'token' => $info['test']['token'],
    'content' => 'record',
    'format' => 'json',
    'type' => 'flat',
    'fields' => array("record_id"),
    'rawOrLabel' => 'raw',
    'rawOrLabelHeaders' => 'raw',
    'exportCheckboxLabel' => 'false',
    'exportSurveyFields' => 'false',
    'exportDataAccessGroups' => 'false',
    'returnFormat' => 'json'
);
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $info['test']['server']);
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
$testData = json_decode($output, true);
$testRecords = array();
foreach ($testData as $row) {
	if (!in_array($row['record_id'], $testRecords)) {
		$testRecords[] = $row['record_id'];
	}
}

echo "List of ".count($prodRecords)." on prod and ".count($testRecords)." on test\n";

# delete from test
if ((count($testRecords) > 0) && (!$selectRecord)) {
	echo "Deleting records on test...\n";
	
	$data = array(
		'token' => $info['test']['token'],
		'action' => 'delete',
		'records' => $testRecords,
		'content' => 'record'
	);
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $info['test']['server']);
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
		$data = array(
			'token' => $info['prod']['token'],
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'records' => $records,
			'rawOrLabel' => 'raw',
			'rawOrLabelHeaders' => 'raw',
			'exportCheckboxLabel' => 'false',
			'exportSurveyFields' => 'false',
			'exportDataAccessGroups' => 'false',
			'returnFormat' => 'json'
		);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $info['prod']['server']);
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
		$pullData = json_decode($output, true);
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

		$data = array(
			'token' => $info['test']['token'],
			'content' => 'record',
			'format' => 'json',
			'type' => 'flat',
			'overwriteBehavior' => 'normal',
			'data' => json_encode($pushData),
			'returnContent' => 'count',
			'returnFormat' => 'json'
		);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $info['test']['server']);
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
		echo "$i. Upload ".$n." of $totalPulls: $output\n";
		error_log("$i. Upload ".$n." of $totalPulls: $output");
		curl_close($ch);
	}
} 
