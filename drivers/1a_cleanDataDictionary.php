<?php

# This file cleans the data dictionary of dropdown elements to restart the database building
# project.
# May be out of date

require_once(dirname(__FILE__)."/../small_base.php");

# download the metadata
$data = [
	'token' => $token,
	'content' => 'metadata',
	'format' => 'json',
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

# get rid of dropdowns; make them text elements
# do not scrub vfrs values
$metadata = json_decode($output, true);
echo count($metadata)." rows of metadata downloaded.\n";
$i = 0;
foreach ($metadata as $row) {
	if (!preg_match("/^summary_/", $row['field_name']) && !preg_match("/^vfrs_/", $row['field_name']) && !preg_match("/^check_/", $row['field_name'])) {
		if ($metadata[$i]['text_validation_type_or_show_slider_number'] != "integer") {
			$metadata[$i]['text_validation_type_or_show_slider_number'] = "";
			$metadata[$i]['select_choices_or_calculations'] = "";
			$metadata[$i]['field_type'] = "text";
		}
	}
	$i++;
}

# uploads scrubbed metadata
echo "Uploading metadata\n";
$data = [
	'token' => $token,
	'content' => 'metadata',
	'format' => 'json',
	'data' => json_encode($metadata),
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
print $output."\n";
curl_close($ch);

# download records
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
curl_close($ch);
$redcapData = json_decode($output, true);
echo count($redcapData)." rows downloaded.\n";

$records = [];
foreach ($redcapData as $row) {
	if (!in_array($row['record_id'], $records)) {
		$records[] = $row['record_id'];
	}
}

#delete all records
echo "Deleting records\n";
if (!empty($records)) {
	$data = [
		'token' => $token,
		'action' => 'delete',
		'content' => 'record',
		'records' => $records
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
	echo "Delete Records: $output\n";
	curl_close($ch);
}
