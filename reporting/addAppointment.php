<?php

use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$recordId = $_REQUEST['record'];
$role = $_REQUEST['role'];
$name = $_REQUEST['name'];
$start = $_REQUEST['start'];
$end = $_REQUEST['end'];

$records = Download::records($token, $server);
if (!in_array($recordId, $records)) {
    die("Invalid record");
}

$metadata = Download::metadata($token, $server);
$redcapData = Download::fieldsForRecords($token, $server, Application::getCustomFields($metadata), [$recordId]);
$max = REDCapManagement::getMaxInstance($redcapData, "custom_grant", $recordId);

if ($start && !REDCapManagement::isDate($start)) {
    $start = "";
}
if ($end && !REDCapManagement::isDate($end)) {
    $end = "";
}

$uploadRow = [
    "record_id" => $recordId,
    "redcap_repeat_instrument" => "custom_grant",
    "redcap_repeat_instance" => $max + 1,
    "custom_number" => $name,
    "custom_role" => $role,
    "custom_start" => $start,
    "custom_end" => $end,
];
$feedback = Upload::oneRow($uploadRow, $token, $server);
echo json_encode($feedback);