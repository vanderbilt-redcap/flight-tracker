<?php

use Vanderbilt\CareerDevLibrary\EmailManager;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");

Application::log(CareerDev::getProgramName()." running email cron at ".date("Y-m-d H:i"), $pid);
$mgr = new EmailManager($token, $server, $pid, Application::getModule());
try {
	$mgr->sendRelevantEmails();
} catch (\Exception $e) {
	Application::log("Error: ".$e->getMessage()." ".$e->getTraceAsString());
}
