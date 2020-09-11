<?php

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");

authenticate($userid, $_POST['menteeRecord']);
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
$from = "noreply@vumc.org";
$subject = $_POST['subject'];
$message = $_POST['message'];
$datetimeToSend = $_POST['datetime'];
if ($to && $from && $subject && $message && $datetimeToSend) {
    if ($datetimeToSend == "now") {
        $ts = time() + 60;
        $datetimeToSend = date("Y-m-d h:I");
    }
    scheduleEmail($to, $from, $subject, $message, $datetimeToSend);
    echo "Message enqueued.";
} else {
    echo "Improper fields.";
}