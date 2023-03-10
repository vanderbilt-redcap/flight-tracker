<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\ReactNIHTables;
use \Vanderbilt\CareerDevLibrary\Sanitizer;

if (isset($_GET['pid']) && is_numeric($_GET['pid'])) {
    $myPid = htmlentities($_GET['pid']);
    $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
    $url = str_replace("pid=".$myPid, "project_id=".$myPid, $url);
    header("Location: $url");
}

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
Application::increaseProcessingMax(1);

if (!isset($_GET['pid'])) {
    $_GET['pid'] = $_GET['project_id'] ?? "";
}
$module = Application::getModule();
$userid = Application::getUsername();
$reactHandler = new ReactNIHTables($pid, $token, $server);

echo Application::makeIcon();
echo "<title>Flight Tracker Feedback for NIH Training Tables</title>";
if (isset($_GET['email'])) {
    $email = Sanitizer::sanitize($_GET['email'] ?? "No email");
    $requestedHash = Sanitizer::sanitize($_GET['hash']);
    echo $reactHandler->getTable1_4Header();
    if ($reactHandler->verify($requestedHash, $email)) {
        list($tables, $emailHash) = $reactHandler->getInformation($requestedHash, $email);
        list($userids, $name) = $reactHandler->getUseridsAndNameAssociatedWithEmail($email);
        if (ReactNIHTables::isAuthorized($userid, $userids)) {
            echo $reactHandler->saveNotes($_POST, $email, $tables);
        } else {
            echo "Could not match user-id.";
        }
    } else {
        echo "Invalid request.";
    }
} else if (isset($_GET['revise']) && REDCapManagement::isEmailOrEmails($_GET['revise'])) {
    $email = Sanitizer::sanitize($_GET['revise'] ?? "No email");
    $requestedHash = Sanitizer::sanitize($_GET['hash']);
    $dateOfReport = Sanitizer::sanitize($_GET['date'] ?? date("Y-m-d"));
    $savedName = Sanitizer::sanitize($_GET['savedName'] ?? "");
    echo $reactHandler->getTable1_4Header();
    if ($reactHandler->verify($requestedHash, $email)) {
        list($tables, $emailHash) = $reactHandler->getInformation($requestedHash, $email);
        list($userids, $name) = $reactHandler->getUseridsAndNameAssociatedWithEmail($email);
        if (ReactNIHTables::isAuthorized($userid, $userids)) {
            echo $reactHandler->makeHTMLForNIHTableEdits($dateOfReport, $name, $email, $emailHash, $tables, $savedName);
        } else {
            echo "Could not match user-id.";
        }
    } else {
        echo "Invalid request.";
    }
} else if (isset($_GET['confirm']) && REDCapManagement::isEmailOrEmails($_GET['confirm'])) {
    $email = Sanitizer::sanitize($_GET['confirm'] ?? "No email");
    $requestedHash = Sanitizer::sanitize($_GET['hash']);
    echo $reactHandler->getTable1_4Header();
    if ($reactHandler->verify($requestedHash, $email)) {
        list($tables, $emailHash) = $reactHandler->getInformation($requestedHash, $email);
        list($userids, $name) = $reactHandler->getUseridsAndNameAssociatedWithEmail($email);
        if (ReactNIHTables::isAuthorized($userid, $userids)) {
            $reactHandler->saveConfirmationTimestamp($email, $tables);
            echo "Data saved. Thank you!";
        } else {
            echo "Could not match user-id.";
        }
    } else {
        echo "Invalid request.";
    }
}
