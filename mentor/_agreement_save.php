<?php
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\MMAHelper;

require_once dirname(__FILE__)."/preliminary.php";
require_once dirname(__FILE__)."/../small_base.php";
require_once dirname(__FILE__)."/base.php";
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$metadata = Download::metadata($token, $server);
$records = Download::recordIds($token, $server);
if (!$_POST['record_id'] || !in_array($_POST['record_id'], $records)) {
    die("Improper Record Id");
}

$uploadRow = MMAHelper::transformCheckboxes($_POST, $metadata);
$uploadRow = MMAHelper::handleTimestamps($uploadRow, $token, $server, $metadata);
$uploadRow["redcap_repeat_instrument"] = "mentoring_agreement";

try {
    $feedback = Upload::oneRow($uploadRow, $token, $server);
    echo json_encode($feedback);
} catch (\Exception $e) {
    echo "Exception: ".$e->getMessage();
}
