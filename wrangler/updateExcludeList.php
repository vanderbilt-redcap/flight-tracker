<?php

use \Vanderbilt\CareerDevLibrary\ExcludeList;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$records = Download::recordIds($token, $server);
$record = REDCapManagement::sanitize($_POST['record']);
$type = REDCapManagement::sanitize($_POST['type']);

if (!in_array($record, $records)) {
    throw new \Exception("Invalid record ".$_POST['record']);
}

$list = new ExcludeList($type, $pid);
$feedback = $list->updateValue($_POST['record'], $_POST['value']);
echo json_encode($feedback);
