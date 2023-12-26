<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\CelebrationsEmail;
use \Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(__DIR__."/small_base.php");
require_once(__DIR__."/classes/Autoload.php");


$handler = new CelebrationsEmail($token, $server, $pid, []);
$action = Sanitizer::sanitize($_POST['action']);
if ($action == "delete") {
    $settingName = Sanitizer::sanitizeWithoutChangingQuotes($_POST['name']);
    $handler->deleteConfiguration($settingName);
    echo "Done.";
} else if ($action == "add") {
    $settingName = Sanitizer::sanitizeWithoutChangingQuotes($_POST['name']);
    $content = Sanitizer::sanitize($_POST['content']);
    $scope = "";
    $what = "";
    if ($content == "new_grants") {
        $scope = "New";
        $what = "Grants";
    } else if ($content == "all_pubs") {
        $scope = "All";
        $what = "Publications";
    } else if ($content == "first_last_author_pubs") {
        $scope = "First/Last Author";
        $what = "Publications";
    } else if ($content == "high_impact_pubs") {
        $scope = "High-Impact";
        $what = "Publications";
    }
    if ($scope && $what) {
        $setting = [
            "who" => Sanitizer::sanitize($_POST['who']),
            "when" => Sanitizer::sanitize($_POST['when']),
            "what" => $what,
            "scope" => $scope,
            "grants" => Sanitizer::sanitize($_POST['grants']),
        ];
        $handler->addOrModifyConfiguration($settingName, $setting);
        echo "Done.";
    } else {
        echo "ERROR: Wrong request.";
    }
} else if ($action == "changeEmail") {
    $email = Sanitizer::sanitize($_POST['email']);
    $handler->saveEmail($email);
    echo "Done.";
} else {
    echo "ERROR: Improper action.";
}
