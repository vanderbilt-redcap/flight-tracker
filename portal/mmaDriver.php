<?php

use \Vanderbilt\CareerDevLibrary\Portal;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\REDCapLookup;

require_once(__DIR__."/../classes/Autoload.php");

$currPid = Sanitizer::sanitizePid($_POST['pid'] ?? "");
$record = Sanitizer::sanitize($_POST['record'] ?? "");
$action = Sanitizer::sanitize($_POST['action'] ?? "");
$allPids = Application::getPids();
if (count($allPids) > 0) {
    Application::keepAlive($allPids[0]);
}

$data = [];
try {
    $portal = new Portal($currPid, $record, "", "", $allPids);
    if ($action == "searchForName") {
        $name = Sanitizer::sanitizeWithoutChangingQuotes($_POST['name'] ?? "");
        if ($name) {
            list($first, $last) = NameMatcher::splitName($name);
            $lookup = new REDCapLookup($first, $last);
            $uids = $lookup->getUidsAndNames(TRUE);
            $data['html'] = $portal->processUidsAndNames($name, $uids, 1);
        } else {
            $data['error'] = "No name.";
        }
    } else if ($action == "submitMentorNameAndUserid") {
        $name = Sanitizer::sanitizeWithoutChangingQuotes($_POST['name'] ?? "");
        $userid = Sanitizer::sanitize($_POST['userid'] ?? "");
        if ($name && $userid) {
            $mentorUid = ($userid == Portal::NONE) ? "" : $userid;
            $data['feedback'] = $portal->addMentorNameAndUid($name, $mentorUid);
        } else {
            $data['error'] = "You must specify a mentor name and select an option.";
        }
    } else {
        $data['error'] = "Illegal action.";
    }
} catch (\Exception $e) {
    $data['error'] = $e->getMessage();
    if (Application::isLocalhost()) {
        $data['error'] .= " ".Sanitizer::sanitize($e->getTraceAsString());
    }
}
echo json_encode($data);
