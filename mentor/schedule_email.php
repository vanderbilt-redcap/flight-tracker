<?php

use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/base.php");

$userid2 = $userid;

$emails = [];
if ($_POST['menteeRecord'] && $_POST['recipients']) {
    $userids = getUseridsForRecord($token, $server, $_POST['menteeRecord'], $_POST['recipients']);
    if (in_array($userid2, $userids)) {
        $emails = getEmailAddressesForRecord($userids);
    }
}
$to = "";
if ($emails) {
    $to = implode(",", $emails);
}
$from = Application::getSetting("default_from", $pid);
$subject = $_POST['subject'];
$message = $_POST['message'];
$datetimeToSend = $_POST['datetime'];
if ($to && $from && $subject && $message && $datetimeToSend) {
    if ($datetimeToSend == "now") {
        $ts = time() + 60;
        $datetimeToSend = date("Y-m-d h:I");
    }
    if (!DEBUG) {
        scheduleEmail($to, $from, $subject, $message, $datetimeToSend);
    }
    echo "Message enqueued for $datetimeToSend.";
} else {
    echo "Improper fields.";
}