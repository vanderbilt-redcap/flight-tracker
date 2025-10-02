<?php

use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Upload;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$names = Download::names($token, $server);
$seenNames = [];
$recordsToDelete = [];
foreach ($names as $recordId => $name) {
	if (!in_array($name, $seenNames)) {
		$seenNames[] = $name;
	} else {
		$recordsToDelete[] = $recordId;
	}
}

if (!empty($recordsToDelete)) {
	echo "Deleting Records ".implode(", ", $recordsToDelete)."<br/>";
	Upload::deleteRecords($token, $server, $recordsToDelete);
} else {
	echo "Nothing to Delete.<br/>";
}
echo "Done.";
