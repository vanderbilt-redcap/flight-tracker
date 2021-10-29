<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once dirname(__FILE__)."/preliminary.php";
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/base.php");

$userid2 = Application::getUsername();

$emails = [];
if ($_POST['menteeRecord'] && $_POST['recipients']) {
    $menteeRecord = REDCapManagement::sanitize($_POST['menteeRecord']);
    $recipients = REDCapManagement::sanitize($_POST['recipients']);
    $userids = getUseridsForRecord($token, $server, $menteeRecord, $recipients);
    if (in_array($userid2, $userids)) {
        $emails = getEmailAddressesForRecord($userids);
    }
}
$to = "";
if ($emails) {
    $to = implode(",", $emails);
}
$from = Application::getSetting("default_from", $pid);
$subject = REDCapManagement::sanitizeWithoutStrippingHTML($_POST['subject'], FALSE);
$message = REDCapManagement::sanitizeWithoutStrippingHTML($_POST['message'], FALSE);
$datetimeToSend = REDCapManagement::sanitize($_POST['datetime']);
if ($to && $from && $subject && $message && $datetimeToSend) {
    if ($datetimeToSend == "now") {
        $ts = time() + 60;
        $datetimeToSend = date("Y-m-d H:i", $ts);
    }
    scheduleEmail($to, $from, $subject, $message, $datetimeToSend, $pid, $token, $server);
    echo "Message enqueued for $datetimeToSend.";
} else {
    echo "Improper fields.";
}