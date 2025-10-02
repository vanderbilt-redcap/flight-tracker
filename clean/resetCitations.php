<?php

use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Upload;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");


$metadata = Download::metadata($token, $server);
$records = Download::recordIds($token, $server);
if (isset($_GET['record'])) {
	$recordId = Sanitizer::getSanitizedRecord($_GET['record'], $records);
	$recordsToProcess = $recordId ? [$recordId] : [];
} else {
	$recordsToProcess = $records;
}

foreach ($recordsToProcess as $recordId) {
	$redcapData = Download::fieldsForRecords($token, $server, Application::getCitationFields($metadata), [$recordId]);
	$resets = [];
	foreach ($redcapData as $row) {
		if ($row['redcap_repeat_instrument'] == "citation") {
			if ($row['citation_include'] == 0) {
				$resets[] = $row['redcap_repeat_instance'];
			}
		}
	}

	$upload = [];
	foreach ($resets as $instance) {
		$uploadRow = [
			"record_id" => $recordId,
			"redcap_repeat_instrument" => "citation",
			"redcap_repeat_instance" => $instance,
			"citation_include" => "",
		];
		$upload[] = $uploadRow;
	}
	if (!empty($upload)) {
		echo "Resetting ".count($upload)." rows for Record $recordId<br>";
		Upload::rows($upload, $token, $server);
	}
}
