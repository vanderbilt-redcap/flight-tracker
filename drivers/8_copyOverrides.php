<?php

$data = array(
	'token' => 'E72AF649D5285B3F169D7B655A0C4BD3',
	'content' => 'record',
	'format' => 'json',
	'type' => 'flat',
	'fields' => array('identifier_email', 'record_id', 'identifier_last_name', 'identifier_first_name'),
	'rawOrLabel' => 'raw',
	'rawOrLabelHeaders' => 'raw',
	'exportCheckboxLabel' => 'false',
	'exportSurveyFields' => 'false',
	'exportDataAccessGroups' => 'false',
	'returnFormat' => 'json',
);
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://redcap.vumc.org/api/');
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

$backupData = json_decode($output, true);

$data = array(
    'token' => 'C65F37B496A52AE5E044A8D79FDD2A02',
    'content' => 'record',
    'format' => 'json',
    'type' => 'flat',
    'fields' => array('identifier_email', 'identifier_last_name', 'identifier_first_name', 'record_id'),
    'rawOrLabel' => 'raw',
    'rawOrLabelHeaders' => 'raw',
    'exportCheckboxLabel' => 'false',
    'exportSurveyFields' => 'false',
    'exportDataAccessGroups' => 'false',
    'returnFormat' => 'json',
);
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://redcap.vumc.org/api/');
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

$overrides = array();
foreach ($redcapData as $row) {
	foreach ($backupData as $row2) {
		if (($row2['identifier_first_name'] == $row['identifier_first_name']) && ($row['identifier_last_name'] == $row2['identifier_last_name']) && ($row['identifier_email'] != $row2['identifier_email'])) {
			echo "Master: ".json_encode($row)."\n";
			echo "Backup: ".json_encode($row2)."\n";
			$ch = "";
			while ($ch != "m" && $ch != "b") {
				$ch = readline("m/b> ");
			}
			if ($ch == "b") {
				$overrides[] = array('record_id' => $row['record_id'], 'override_email' => $row2['identifier_email']);
			}
			echo "\n";
			break;
		}
	}
}

echo "Uploading...\n";
$data = array(
    'token' => 'C65F37B496A52AE5E044A8D79FDD2A02',
    'content' => 'record',
    'format' => 'json',
    'type' => 'flat',
    'overwriteBehavior' => 'normal',
    'data' => json_encode($overrides),
    'returnContent' => 'count',
    'returnFormat' => 'json'
);
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://redcap.vumc.org/api/');
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
echo "Upload: ".$output."\n";
curl_close($ch);

