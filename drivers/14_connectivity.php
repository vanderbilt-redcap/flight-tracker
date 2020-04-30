<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\ConnectionStatus;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../classes/ConnectionStatus.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");

function testConnectivity($token, $server, $pid, $howToReturn = "Email") {
    $sites = Application::getSites();
    Application::log("Testing connection for ".count($sites)." servers");
    $html = "";
    $numFailures = 0;
    $numTests = 0;
    foreach ($sites as $name => $server) {
        $connStatus = new ConnectionStatus($name, $server);
        $results = $connStatus->test();
        foreach ($results as $key => $result) {
            if (preg_match("/error/i", $result)) {
                Application::log("$server: $key - ".$result);
                $numFailures++;
            }
            $numTests++;
        }
        $title = $name." (<a href='".$connStatus->getURL()."'>$server</a>)";
        $html .= ConnectionStatus::formatResultsInHTML($title, $results);
    }
    if ($numFailures == 0) {
        Application::log($numTests." tests passed over ".count($sites)." servers without failure");
    }
    if ($howToReturn == "HTML") {
        return $html;
    } else {
        $adminEmail = Application::getSetting("admin_email", $pid);
        $html = "
        <style>
        .green { background-color: #8dc63f; }
        .red { background-color: #ffc3c4; }
        </style>
        ".$html;
        \REDCap::email($adminEmail, "no-reply@vumc.org", "Flight Tracker Connectivity Checker", $html);
    }
}