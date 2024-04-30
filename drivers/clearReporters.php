<?php

require_once(dirname(__FILE__)."/../small_base.php");

echo "Downloading data\n";
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

$redcapData = json_decode($output, true);
$deletes = array();
foreach ($redcapData as $row) {
	if ($row['redcap_repeat_instrument'] == "reporter") {
		echo "Deleting record {$row['record_id']} instance {$row['redcap_repeat_instance']}\n";
		$ch = curl_init();
		$url = "https://redcap.vumc.org/plugins/career_dev/drivers/deleteInstance.php?pid=$pid&instrument=reporter&instance=".$row['redcap_repeat_instance']."&record=".$row['record_id'];
		curl_setopt($ch, CURLOPT_URL, $url);
		$output = "";
		$output = curl_exec($ch);
		echo "Output: ".$output."\n";
		curl_close($ch);
		sleep(1);
	}
}

