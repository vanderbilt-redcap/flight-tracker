<?php

require_once(dirname(__FILE__)."/../small_base.php");

# file with columns for lastName, firstName
$files = ["edge.csv" => [0, 1], "grant_pacing.csv" => [1,2]];
$redcapValue = ["edge.csv" => 1, "grant_pacing.csv" => 2];

$matches = [];
foreach ($files as $file => $cols) {
	$lastNames = [];
	$firstNames = [];

	$fp = fopen(dirname(__FILE__)."/".$file, "r");
	$i = 0;
	while ($line = fgetcsv($fp)) {
		if ($i !== 0) {
			$allPresent = true;
			foreach ($cols as $col) {
				if (!$line[$col]) {
					$allPresent = false;
					break;
				}
			}
			if ($allPresent) {
				$lastName = $line[$cols[0]];
				$firstName = $line[$cols[1]];

				$lastNames[] = $lastName;
				$firstNames[] = $firstName;
			}
		}
		$i++;
	}

	$matches[$file] = matchNames($firstNames, $lastNames);
	$j = 0;
	for ($i = 0; $i < count($lastNames) && $i < count($firstNames) && $i < count($matches[$file]); $i++) {
		if ($matches[$file][$i] === "") {
			// echo $j.". ".$lastNames[$i]." ".$firstNames[$i]." not found\n";
			$j++;
		}
	}
	echo count($matches[$file])." matches in $file\n";
	echo $j." extras in $file\n";
	fclose($fp);
}

echo "\n";

foreach ($matches as $file => $recordIds) {
	$upload = [];
	$done = [];
	foreach ($recordIds as $recordId) {
		if ($recordId && !in_array($recordId, $done)) {
			$row = [];
			$row["record_id"] = $recordId;
			$row['identifier_workshops___'.$redcapValue[$file]] = '1';
			$upload[] = $row;
			$done[] = $recordId;
		}
	}

	$data = [
		'token' => $token,
		'content' => 'record',
		'format' => 'json',
		'type' => 'flat',
		'overwriteBehavior' => 'overwrite',
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
	echo "Upload (".count($upload).") $file: ".$output."\n";
	;
	curl_close($ch);
}
