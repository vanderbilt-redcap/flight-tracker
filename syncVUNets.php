<?php

use \Vanderbilt\CareerDevLibrary\COEUSConnection;
use \Vanderbilt\CareerDevLibrary\Download;

require_once(dirname(__FILE__)."/classes/Autoload.php");
require_once(dirname(__FILE__)."/charts/baseWeb.php");

echo "<h1>Synching VUNets to COEUS</h1>\n";
$allIds = Download::vunets($token, $server);
echo "<p class='centered'>Downloaded ".count($allIds)." vunets from REDCap</p>\n";

try {
	$conn = new COEUSConnection();
	$conn->connect();

	$alreadyInRows = $conn->getCurrentIds();
	$alreadyInIds = array();
	foreach ($alreadyInRows as $row) {
		$id = $row['CAREER_VUNET'];
		array_push($alreadyInIds, $id);
	}
	echo "<p class='centered'>Found ".count($alreadyInIds)." ids in the Oracle database</p>\n";

	# filter
	$newIds = array();
	foreach ($allIds as $recordId => $id) {
		if (!in_array($id, $alreadyInIds)) {
			array_push($newIds, $id);
		}
	}

	if (count($newIds) > 0) {
		echo "<p class='centered'>Inserting new ids ".implode(", ", $newIds)."</p>\n";
		$conn->insertNewIds($newIds);
	} else {
		echo "<p class='centered'>No new ids</p>\n";
	}
	$conn->close();
} catch(\Exception $e) {
	die($e->getMessage());
}

