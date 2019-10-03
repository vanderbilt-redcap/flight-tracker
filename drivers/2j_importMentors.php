<?php

require_once(dirname(__FILE__)."/../small_base.php");

$upload = array();
$i = 0;
$records = array();
$fp = fopen(dirname(__FILE__)."/../mentors.csv", "r");
while ($line = fgetcsv($fp)) {
	if ($i > 0) {
		$scholarName = preg_split("/\s+/", $line[0]);
		$mentorNames = preg_split("/\s*[\&,]\s*/", $line[1]);
		if (count($scholarName) > 2) {
			echo "$i: ".json_encode($scholarName)."\n";
			$temp = fopen("php://stdin","r");
			$scholarJSON = "[".fgets($temp)."]";
			fclose($temp);
			$scholarName = json_decode(trim($scholarJSON));
		}
		if (count($scholarName) == 2) {
			$recordId = matchName($scholarName[0], $scholarName[1]);
			if ($recordId && !in_array($recordId, $records)) {
				$upload[] = array("record_id" => $recordId, "spreadsheet_mentors" => implode("\n", $mentorNames));
				$records[] = $recordId;
			} else if ($recordId) {
				echo "Duplicate with $recordId and ".json_encode($scholarName)."\n";
			} else {
				echo "No match with ".json_encode($scholarName)."\n";
			}
		}
	}
	$i++;
}
fclose($fp);
echo count($upload);
echo "\n";

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
echo $output."\n";
curl_close($ch);

