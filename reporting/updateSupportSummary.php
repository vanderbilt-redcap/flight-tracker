<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$record = REDCapManagement::sanitize($_POST['record']);
$text = REDCapManagement::sanitize($_POST['text']);
$field = "identifier_support_summary";

$recordIds = Download::recordIds($token, $server);
if ($record && in_array($record, $recordIds)) {
    $row = array("record_id" => $record, $field => $text);
    $feedback = Upload::oneRow($row, $token, $server);
    echo json_encode($feedback);
} else {
    echo "Invalid record '$record'";
}
