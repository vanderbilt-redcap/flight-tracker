<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\ReactNIHTables;
use \Vanderbilt\CareerDevLibrary\Sanitizer;

# This page uses NOAUTH but has a unique hash that's used for authentication
# Links to this page are sent in an email to people not on the REDCap project (delegates and scholars)
# Therefore, this alternate form of authentication is needed.
# Links to this page are sent via an email from the Tables 2-4 React app
# This page provides feedback, which are saved into DB and displayed in React app

define("NOAUTH", TRUE);

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
Application::increaseProcessingMax(1);

if (!isset($_GET['pid'])) {
    $_GET['pid'] = $_GET['project_id'] ?? "";
}
Application::increaseProcessingMax(1);
Application::keepAlive($pid);
$module = Application::getModule();
$userid = Application::getUsername();
$reactHandler = new ReactNIHTables($pid, $token, $server);

echo "<title>Flight Tracker Feedback for NIH Training Tables</title>".Application::makeIcon();
if (isset($_GET['email'])) {
    $email = Sanitizer::sanitize($_GET['email'] ?? "No email");
    $requestedHash = Sanitizer::sanitize($_GET['hash']);
    echo $reactHandler->getTable1_4Header();
    if ($reactHandler->verify($requestedHash, $email)) {
        list($tables, $emailHash) = $reactHandler->getInformation($requestedHash, $email);
        list($userids, $name) = $reactHandler->getUseridsAndNameAssociatedWithEmail($email);
        echo $reactHandler->saveNotes($_POST, $email, $tables);
    } else {
        echo "Invalid request.";
    }
} else if (
    (isset($_GET['revise']) && REDCapManagement::isEmailOrEmails($_GET['revise']))
    || (isset($_GET['submit_row']) && REDCapManagement::isEmailOrEmails($_GET['submit_row']))
) {
    $email = Sanitizer::sanitize($_GET['revise'] ?? $_GET['submit_row'] ?? "No email");
    $requestedHash = Sanitizer::sanitize($_GET['hash']);
    $dateOfReport = Sanitizer::sanitizeDate($_GET['date'] ?? date("Y-m-d"));
    $savedName = Sanitizer::sanitize($_GET['savedName'] ?? "");
    echo $reactHandler->getTable1_4Header();
    if ($reactHandler->verify($requestedHash, $email)) {
        list($tables, $emailHash) = $reactHandler->getInformation($requestedHash, $email);
        list($userids, $name) = $reactHandler->getUseridsAndNameAssociatedWithEmail($email);
        if (isset($_GET['revise'])) {
            echo $reactHandler->makeHTMLForNIHTableEdits($dateOfReport, $name, $email, $emailHash, $tables, $savedName);
        } else if (isset($_GET['submit_row'])) {
            $post = Sanitizer::sanitizeArray($_POST, TRUE, FALSE);
            echo $reactHandler->makeTable4NewRow($dateOfReport, $name, $email, $emailHash, $savedName, $post);
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
        $reactHandler->saveConfirmationTimestamp($email, $tables);
        echo "Data saved. Thank you!";
    } else {
        echo "Invalid request.";
    }
} else {
    echo "Invalid request.";
}
