<?php

require_once(dirname(__FILE__)."/../small_base.php");

# This script copies the master project to a test project

if ($_GET['pid']  == $info['prodtest']['pid']) {
	$info['test']['token'] = $info['prodtest']['token'];
	$info['test']['server'] = $info['prodtest']['server'];
	$info['test']['pid'] = $info['prodtest']['pid'];
	$info['test']['event_id'] = $info['prodtest']['event_id'];
} else {
	die("Only works with {$info['prodtest']['pid']}");
}
// } else if ($argv[1] == "backup") {
	// $info['test']['token'] = $info['prod']['token'];
	// $info['test']['server'] = $info['prod']['server'];
	// $info['test']['pid'] = $info['prod']['pid'];
// 
	// // $info['prod']['token'] = "2C91720C83191C9AB471CBDE0D404094";
	// $info['prod']['token'] = "7B78A797A079758F28AF521ED44D684D";
// }

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

require_once(dirname(__FILE__)."/../../../redcap_connect.php");

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

$sql = "SELECT * FROM redcap_data WHERE project_id = ".$info['prod']['pid'];
$q = db_query($sql);
if ($error = db_error()) {
	echo "SELECT $error $sql\n";
}
$insertRows = array();
$fields = array("project_id", "event_id", "record", "field_name", "value", "instance");
$cnt = 0;
$cnt_up = 0;
while ($row = db_fetch_assoc($q)) {
	$insertRowFields = array();
	foreach ($fields as $field) {
		$value = $row[$field];
		if ($field == "project_id") {
			$value = $info['test']['pid'];
		}
		if ($field == "event_id") {
			$value = $info['test']['event_id'];
		}
		$insertRowFields[] = "'".db_real_escape_string($value)."'";
	}
	$insertRows[] = "(".implode(", ", $insertRowFields).")";
	if (count($insertRows) > 50) {
		$cnt_up += insertRows($fields, $insertRows);
		$insertRows = array();
	}
	$cnt++;
}
if (count($insertRows) > 0) {
	$cnt_up += insertRows($fields, $insertRows);
}
echo "Downloaded $cnt rows\n";
echo "Uploaded $cnt_up rows\n";

function insertRows($fields, $insertRows) {
	$sql = "INSERT INTO redcap_data (".implode(",", $fields).") VALUES ".implode(",", $insertRows);
	db_query($sql);
	if ($error = db_error()) {
		echo "INSERT $error $sql\n";
	} else {
		echo "<br><br>$sql";
	}
	return count($insertRows);
}
