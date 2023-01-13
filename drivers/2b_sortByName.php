<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../small_base.php");

# This sorts the data set alphabetically

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


# downloads three fields
echo "Downloading small copy...\n";
$fields = array("record_id", "first_name", "last_name");
$data = array(
	'token' => $token,
	'content' => 'record',
	'format' => 'json',
	'fields' => $fields,
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
unset($output);

# compares two names to see which comes first
function nameCompare($a, $b)
{
	$ln1 = strtolower($a['last_name']);
	$ln2 = strtolower($b['last_name']);
	$fn1 = strtolower($a['first_name']);
	$fn2 = strtolower($b['first_name']);

	if (($ln1 == $ln2) && ($fn1 == $fn2)) {
		return 0;
	}

	$cmp = strcmp($ln1, $ln2);
	if ($cmp < 0) {
		return -1;
	} else if ($cmp > 0) {
		return 1;
	} else {
		$cmpfn = strcmp($fn1, $fn2);
		if ($cmpfn < 0) {
			return -1;
		} else if ($cmpfn > 0) {
			return 1;
		} else {
			# should never happen
			return 0;
		} 
	}
}


echo "Ordering names...\n";
$names = array();
foreach ($redcapData as $row) {
	$nameRow = array();
	foreach ($row as $field => $value) {
		if (in_array($field, $fields)) {
			$nameRow[$field] = $value;
		}
	}
	if (count($nameRow) == count($fields)) {
		$names[] = $nameRow;
	}
}
usort($names, "nameCompare");

# reordering records
$records = array();
$translate = array();
$i = 1;
foreach ($names as $nameRow) {
	$translate[$nameRow['record_id']] = $i;
	$records[] = $nameRow['record_id'];
	$i++;
}

unset($redcapData);
echo "Downloading full copy...\n";
$data = array(
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

$output = preg_replace("/^\[\{/", "{", $output);
$output = preg_replace("/\}\]$/", "}", $output);
$outputAry = preg_split("/\},\{/", $output);
for ($i = 0; $i < count($outputAry); $i++) {
	if ($i !== 0) {
		$outputAry[$i] = "{".$outputAry[$i];
	}
	if ($i + 1 != count($outputAry)) {
		$outputAry[$i] = $outputAry[$i]."}";
	}
}

# returns array(array(...), array(...));
function putIntoBatches($r) {
	$size = 50;
	$ary = array();
	for ($i = 0; $i < count($r); $i++) {
		$index = floor($i / $size);
		if (!isset($ary[$index])) {
			$ary[$index] = array();
		}
		$ary[$index][] = $r[$i];
	}
	return $ary;
}

echo "Deleting all records\n";
$recordBatches = putIntoBatches($records);
$i = 1;
foreach ($recordBatches as $recordBatch) {
	echo "$i of ".count($recordBatches).") Attempting to delete ".count($recordBatch)." records\n";
	$data = array(
		'token' => $token,
		'content' => 'record',
		'action' => 'delete',
		'records' => $recordBatch
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
	echo "Delete ($i of ".count($recordBatches)."): ".$output."\n";
	curl_close($ch);

	$i++;
}

unset($output);
$numRows = count($outputAry);
echo "Translating record id's ($numRows rows)\n";
$i = 1;
$queue = array();
foreach ($outputAry as $jsonRow) {
	$row = json_decode($jsonRow, true);
	if ($row['record_id']) {
		$row['record_id'] = $translate[$row['record_id']];

		// echo "Enqueuing {$row['record_id']}";
		// if ($row['redcap_repeat_instance']) {
			// echo " (".$row['redcap_repeat_instance'].")";
		// }
		// echo "\n";
		$queue[] = $row;
	}
	# batches of 400 rows to upload
	# one row = all non-repeating variables -OR- one instance of repeating
	if ((count($queue) >= 400) || ($i == count($outputAry))) {
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
		echo "$i of $numRows - ".count($queue)." rows ".$output."\n";
		curl_close($ch);

		$queue = array();
	}

	$i++;
}

