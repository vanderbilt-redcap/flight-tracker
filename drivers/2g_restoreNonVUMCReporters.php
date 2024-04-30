<?php

require_once("small_base.php");

$backup_token = "DAEF83030E6CD5703582DCE8EB4E8406";
$backup_server = "https://redcap.vumc.org/api/";

echo "Download reporter fields\n";
$data = array(
	'token' => $backup_token,
	'content' => 'record',
	'format' => 'json',
	'type' => 'flat',
	'fields' => array_merge($reporterFields, array("record_id", "summary_last_name", "summary_first_name")),
	'rawOrLabel' => 'raw',
	'rawOrLabelHeaders' => 'raw',
	'exportCheckboxLabel' => 'false',
	'exportSurveyFields' => 'false',
	'exportDataAccessGroups' => 'false',
	'returnFormat' => 'json'
);
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $backup_server);
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
$redcapData_backup = json_decode($output, true);

$data = array(
	'token' => $token,
	'content' => 'record',
	'format' => 'json',
	'type' => 'flat',
	'fields' => array_merge($reporterFields, array("record_id", "summary_last_name", "summary_first_name")),
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
$redcapData = json_decode($output, true);

$upload = array();
foreach ($redcapData_backup as $row) {
	if ($row['redcap_repeat_instrument'] == "reporter") {
		$instance = 0;
		foreach (array_merge($redcapData, $upload) as $row2) {
			if (($row2['record_id'] == $row['record_id']) && ($row2['redcap_repeat_instrument'] == "reporter")) {
				if ($instance < $row2['redcap_repeat_instance']) {
					$instance = $row2['redcap_repeat_instance'];
				}
			}
		}
		if (!preg_match("/vanderbilt/", strtolower($row['reporter_orgname']))) {
			$instance++;
			$row2 = array();
			$row2["record_id"] = $row['record_id'];
			$row2["redcap_repeat_instrument"] = "reporter";
			$row2["redcap_repeat_instance"] = $instance;
			foreach ($row as $field => $value) {
				if (preg_match("/^reporter_/", $field)) {
					if (preg_match("/enddate/", $field) || preg_match("/startdate/", $field)) {
						$row2[$field] = getReporterDate($value);
					} else {
						$row2[$field] = $value;
					}
				}
			}
			$upload[] = $row2;
		}
	}
}

echo count($upload)." rows to upload\n";

$data = array(
    'token' => $token,
    'content' => 'record',
    'format' => 'json',
    'type' => 'flat',
    'overwriteBehavior' => 'normal',
    'forceAutoNumber' => 'false',
    'data' => json_encode($upload),
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
echo "Upload results: $output\n";
curl_close($ch);

