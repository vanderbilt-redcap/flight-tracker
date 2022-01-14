<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__) . "/preliminary.php");
require_once(dirname(__FILE__) . "/../small_base.php");
require_once(dirname(__FILE__) . "/../classes/Autoload.php");
require_once(dirname(__FILE__) . "/base.php");

$data = MMAHelper::createHash($token, $server);

$emailFields = ["mentorEmail", "menteeEmail"];
$nameFields = ["menteeFirstName", "menteeLastName", "mentorFirstName", "mentorLastName"];

foreach ($emailFields as $field) {
    if (!$_POST[$field] || !REDCapManagement::isEmail($_POST[$field])) {
        die("Emails not specified!");
    }
}
foreach ($nameFields as $field) {
    if (!$_POST[$field]) {
        die("Not all names are specified!");
    }
}

$recordId = $data['record'];
if ($recordId) {
    $uploadRow = [
        "record_id" => $recordId,
        "identifier_first_name" => REDCapManagement::sanitize($_POST['menteeFirstName']),
        "identifier_last_name" => REDCapManagement::sanitize($_POST['menteeLastName']),
        "identifier_email" => REDCapManagement::sanitize($_POST['menteeEmail']),
        "mentor_first_name" => REDCapManagement::sanitize($_POST['mentorFirstName']),
        "mentor_last_name" => REDCapManagement::sanitize($_POST['mentorLastName']),
        "mentor_email" => REDCapManagement::sanitize($_POST['mentorEmail']),
        "identifiers_complete" => "2",
    ];
    Upload::oneRow($uploadRow, $token, $server);
    echo json_encode($data);
} else {
    die("Record could not be created!");
}

