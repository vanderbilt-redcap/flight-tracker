<?php

use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Upload;
use Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

if (!isset($_GET['record'])) {
	die("No record");
}

$records = Download::records($token, $server);
$record = REDCapManagement::getSanitizedRecord($_GET['record'], $records);

if ($pid && $record) {
	Upload::deleteForm($token, $server, $pid, "citation_", $record);
	echo "Done.";
} else {
	echo "No pid or record.";
}
