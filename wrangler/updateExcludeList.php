<?php

use \Vanderbilt\CareerDevLibrary\ExcludeList;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$records = Download::recordIds($token, $server);
$record = REDCapManagement::getSanitizedRecord($_POST['record'], $records);
$type = REDCapManagement::sanitize($_POST['type']);
$value = REDCapManagement::sanitize($_POST['value']);

if (!in_array($record, $records)) {
    throw new \Exception("Invalid record ".$record);
}

$list = new ExcludeList($type, $pid);
$feedback = $list->updateValue($record, $value);
echo json_encode($feedback);
