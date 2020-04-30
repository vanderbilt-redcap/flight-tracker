<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");

$record = $_POST['record'];
$text = $_POST['text'];
$field = "identifier_support_summary";

$recordIds = Download::recordIds($token, $server);
if ($record && in_array($record, $recordIds)) {
    $row = array("record_id" => $record, $field => $text);
    $feedback = Upload::oneRow($row, $token, $server);
    echo json_encode($feedback);
} else {
    echo "Invalid record '$record'";
}
