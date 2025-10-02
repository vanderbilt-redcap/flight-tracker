<?php

require_once(dirname(__FILE__)."/../small_base.php");
echo "TOKEN: $token\n";
echo "SERVER: $server\n";
echo "\n";

if ($token == $info['prod']['token']) {
	$a = readline("PROD Are you sure? (y/n) > ");
	if ($a != "y") {
		die();
	}
}

$repeatableForms = ["custom_grant" => "custom", "coeus" => "coeus", "summary_grants" => "summary_grants", "reporter" => "reporter"];

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
echo $output."\n";
curl_close($ch);

$fp = fopen("backup.".time().".json", "w");
fwrite($fp, $output);
fclose($fp);

$redcapData = json_decode($output, true);
echo "Downloaded ".count($redcapData)." rows\n";
$plainData = [];
$repeatableData = [];
foreach ($repeatableForms as $form => $prefix) {
	$repeatableData[$form] = [];
}
$recordIds = [];
foreach ($redcapData as $row) {
	if (isset($repeatableData[$row['redcap_repeat_instrument']])) {
		$repeatableData[$row['redcap_repeat_instrument']][] = $row;
	} else {
		$plainData[] = $row;
	}
	if (!in_array($row['record_id'], $recordIds)) {
		$recordIds[] = $row['record_id'];
	}
}
unset($redcapData);

$data = [
	'token' => $token,
	'action' => 'delete',
	'content' => 'record',
	'records' => $recordIds
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
echo "Deleted ".count($recordIds)." records: $output\n";
;
curl_close($ch);

$data = [
	'token' => $token,
	'content' => 'record',
	'format' => 'json',
	'type' => 'flat',
	'overwriteBehavior' => 'write',
	'forceAutoNumber' => 'false',
	'data' => json_encode($plainData),
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
echo "Upload non-repeatables (".count($plainData)." rows): ".$output."\n";
curl_close($ch);

# for memory
unset($plainData);


foreach ($repeatableForms as $form => $prefix) {
	$blankRowCount = 0;
	$blankRowRecords = [];
	$upload = [];
	foreach ($repeatableData[$form] as $row) {
		$allBlank = true;
		foreach ($row as $field => $value) {
			if (preg_match("/$prefix/", $field)) {
				if ($value !== "") {
					$allBlank = false;
				}
			}
		}
		if (!$allBlank) {
			$upload[] = $row;
		} else {
			$blankRowCount++;
			if (!in_array($row['record_id'], $blankRowRecords)) {
				$blankRowRecords[] = $row['record_id'];
			}
		}
	}
	echo "For form $form, $blankRowCount rows and ".count($blankRowRecords)." records.\n";
	echo "Uploading ".count($upload)." rows\n";
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
	echo "For $form, uploaded: ".$output."\n";
	curl_close($ch);
}
echo "Done.\n";
