<?php

use Vanderbilt\CareerDevLibrary\ExcludeList;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$records = Download::recordIds($token, $server);
$record = Sanitizer::getSanitizedRecord($_POST['record'], $records);
$type = Sanitizer::sanitize($_POST['type']);
$value = Sanitizer::sanitize($_POST['value']);
$field = Sanitizer::sanitize($_POST['field']);

if (!in_array($record, $records)) {
	throw new \Exception("Invalid record ".$record);
}

$list = new ExcludeList($type, $pid);
$feedback = $list->updateValue($record, $field, $value);
echo json_encode($feedback);
