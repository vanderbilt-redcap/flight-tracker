<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

$records = Download::recordIds($token, $server);
$recordId = REDCapManagement::getSanitizedRecord($_GET['record'], $records);
if (!$recordId) {
    die("Invalid record.");
}

$prefixes = [
    "nih_reporter_",
    "reporter_",
    "exporter_",
];

foreach ($prefixes as $prefix) {
    Upload::deleteForm($token, $server, $pid, $prefix, $recordId);
}
echo "Done.";