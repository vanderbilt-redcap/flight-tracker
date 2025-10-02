<?php

# to be included into 6_makeSummary.php

require_once(dirname(__FILE__)."/../small_base.php");

echo "In test6 with $token\n";

$data = [
	'token' => $token,
	'content' => 'record',
	'format' => 'json',
	'type' => 'flat',
	'fields' => array_merge($summaryFields, $calculateFields),
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

$fp = fopen(dirname(__FILE__)."/summaries.csv", "r");
$i = 0;
$headers = [];
$values = [];
while ($line = fgetcsv($fp)) {
	if ($i === 0) {
		$j = 0;
		foreach ($line as $item) {
			if ($j == 0) {
				$headers[] = "record_id";
			} else {
				$headers[] = $item;
			}
			$j++;
		}
	} else {
		$j = 0;
		$valueLine = [];
		$hasData = false;
		foreach ($line as $item) {
			$valueLine[$headers[$j]] = $item;
			if ($item) {
				$hasData = true;
			}
			$j++;
		}
		if ($hasData) {
			$values[$line[0]] = $valueLine;
		}
	}
	$i++;
}
fclose($fp);

$inequalities = [];
$hasError = false;
$skip = [
		"summary_citations",
		"summary_citation_id",
		"summary_pubmed_citations",
		"summary_pubmed_citation_id",
		"summary_calculate_order",
		"summary_calculate_list_of_awards",
		"summary_calculate_to_import",
		];
foreach ($redcapData as $row) {
	if (!isset($row['redcap_repeat_instance']) || ($row['redcap_repeat_instance'] == "")) {
		$recordId = $row['record_id'];
		$csvRow = $values[$recordId];
		$inequalities[$recordId] = [];
		foreach ($csvRow as $field => $value) {
			foreach ($row as $rowField => $rowValue) {
				if (($rowField == $field) && ($row[$rowField] != $value) && (!in_array($field, $skip))) {
					$inequalities[$recordId][$field] = ["csv" => $value, "redcap" => $rowValue];
					$hasError = true;
				}
			}
		}
	}
}
$mssg = "";
if ($hasError) {
	$mssg = "CHANGES<br><br>";
	foreach ($inequalities as $recordId => $fields) {
		foreach ($fields as $field => $values) {
			$mssg .= "Record $recordId <b>$field</b> - CSV: {$values['csv']}; REDCap: {$values['redcap']}<br>";
		}
	}
} else {
	$mssg = "SUCCESS<br><br>There were no changes found in unit-testing. Records ".implode(", ", array_keys($inequalities))." have been tested and been found to be equal.";
}
$victrEmail = "scott.j.pearson@vumc.org";
echo "Sending email to $victrEmail\n";
require_once(dirname(__FILE__)."/../../../redcap_connect.php");
echo "Got redcap_connect.php\n";
\REDCap::email($victrEmail, "no-reply@vanderbilt.edu", "CareerDev Unit-Testing", $mssg);
echo "Email sent\n";
