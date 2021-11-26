<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\MMAHelper;

require_once dirname(__FILE__)."/preliminary.php";
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/base.php");

if (($pid == 127616) && Application::isVanderbilt()) {
    # test project - do not send any emails.
    exit();
}

if ($_REQUEST['uid'] && DEBUG) {
    $userid2 = REDCapManagement::sanitize($_REQUEST['uid']);
} else {
    $userid2 = Application::getUsername();
}

$emails = [];
$userids = [];
if ($_POST['menteeRecord'] && $_POST['recipients']) {
    $menteeRecord = REDCapManagement::sanitize($_POST['menteeRecord']);
    $recipients = REDCapManagement::sanitize($_POST['recipients']);
    $userids = MMAHelper::getUseridsForRecord($token, $server, $menteeRecord, $recipients);
    if (in_array($userid2, $userids)) {
        $emails = MMAHelper::getEmailAddressesForRecord($userids);
    }
}
$to = $emails ? implode(",", $emails) : "";
$from = Application::getSetting("default_from", $pid);
$subject = REDCapManagement::sanitizeWithoutStrippingHTML($_POST['subject'], FALSE);
$message = REDCapManagement::sanitizeWithoutStrippingHTML($_POST['message'], FALSE);
$datetimeToSend = REDCapManagement::sanitize($_POST['datetime']);
if ($to && $from && $subject && $message && $datetimeToSend) {
    if ($datetimeToSend == "now") {
        $ts = time() + 60;
        $datetimeToSend = date("Y-m-d H:i", $ts);
    }
    MMAHelper::scheduleEmail($to, $from, $subject, $message, $datetimeToSend, $pid, $token, $server);
    echo "Message enqueued for $datetimeToSend.";
} else {
    echo "Improper fields.";
    $post = REDCapManagement::sanitizeArray($_POST);
    if (DEBUG) {
        echo "\n";
        echo "POST: ".json_encode($post)."\n";
        echo "userids: ".json_encode($userids)."\n";
        echo "userid2: $userid2\n";
        echo "to: $to\n";
        echo "from: $from\n";
        echo "subject: $subject\n";
        echo "message: $message\n";
        echo "datetime: $datetimeToSend\n";
    }
}