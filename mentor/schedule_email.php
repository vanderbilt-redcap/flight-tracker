<?php

namespace Vanderbilt\CareerDevLibrary;

require_once dirname(__FILE__)."/preliminary.php";
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/base.php");

if (($pid == 127616) && Application::isVanderbilt()) {
    # test project - do not send any emails.
    echo '[]';
    exit();
}

if ($_REQUEST['uid'] && MMAHelper::getMMADebug()) {
    $userid2 = Sanitizer::sanitize($_REQUEST['uid']);
} else {
    $userid2 = Application::getUsername();
}

$emails = [];
$userids = [];
$whom = "";
if ($_POST['menteeRecord'] && $_POST['recipients']) {
    $menteeRecord = Sanitizer::sanitize($_POST['menteeRecord']);
    $recipients = Sanitizer::sanitize($_POST['recipients']);
    if ($hash) {
        $fields = [
            "record_id",
            "identifier_email",
            "mentor_email",
            ];
        $redcapData = Download::fieldsForRecordsByPid($pid, $fields, [$menteeRecord]);
        $normativeRow = REDCapManagement::getNormativeRow($redcapData);
        if (in_array($recipients, ["mentee", "all"])) {
            if ($normativeRow['identifier_email']) {
                $emails[] = $normativeRow['identifier_email'];
            }
        }
        if (in_array($recipients, ["mentor", "all"])) {
            if ($normativeRow['mentor_email']) {
                $emails[] = $normativeRow['mentor_email'];
            }
        }
    } else {
        $userids = MMAHelper::getUseridsForRecord($token, $server, $menteeRecord, $recipients);
        if (in_array($userid2, $userids)) {
            $emails = MMAHelper::getEmailAddressesForRecord($userids);
        }
    }
    $whom = ($recipients === "all") ? "both the mentor and the mentee" : $recipients;
}
$to = $emails ? implode(",", $emails) : "";
$from = Application::getSetting("default_from", $pid);
$subject = Sanitizer::sanitizeWithoutStrippingHTML($_POST['subject'], FALSE);
$message = Sanitizer::sanitizeWithoutStrippingHTML($_POST['message'], FALSE);
$datetimeToSend = Sanitizer::sanitize($_POST['datetime']);
if ($to && $from && $subject && $message && $datetimeToSend) {
    $ts = strtotime($datetimeToSend);
    if ($ts) {
        $prettySendTime = date("F j, Y, g:i a", $ts);
    } else {
        die(json_encode(["error" => "Invalid send time."]));
    }
    MMAHelper::scheduleEmail($to, $from, $subject, $message, $datetimeToSend, $pid, $token, $server);
    echo json_encode(["result" => "A message for $whom has been enqueued to send at $prettySendTime."]);
} else {
    if (!$to) {
        $result = ["error" => "No email addresses could be found for the recipients."];
    } else {
        $result = ["error" => "Improper fields."];
    }
    if (MMAHelper::getMMADebug()) {
        $post = Sanitizer::sanitizeArray($_POST);
        $result['to'] = $to;
        $result['from'] = $from;
        $result['subject'] = $subject;
        $result['message'] = $message;
        $result['datetime'] = $datetimeToSend;
        $result['userids'] = $userids;
        $result['userid2'] = $userid2;
        $result['post'] = $post;
    }
    echo json_encode($result);
}