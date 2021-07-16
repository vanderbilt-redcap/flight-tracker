<?php

use \Vanderbilt\CareerDevLibrary\CronManager;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/classes/Autoload.php");
require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/cronLoad.php");

define('REDIRECT_EMAILS', FALSE);

$manager = new CronManager($token, $server, $pid, Application::getModule());
$specialOnly = FALSE;
if (isset($argv[2]) && ($argv[2] = "special")) {
	$specialOnly = TRUE;
}
if (isset($_GET['initial'])) {
    \Vanderbilt\FlightTrackerExternalModule\loadInitialCrons($manager, $specialOnly);
} else {
    \Vanderbilt\FlightTrackerExternalModule\loadCrons($manager, $specialOnly);
}
error_log($manager->getNumberOfCrons()." total crons loaded in");
if (REDIRECT_EMAILS) {
    $adminEmail = "scott.j.pearson@vumc.org";
}
$manager->runBatchJobs();
// $manager->run($adminEmail, $tokenName, $pid);
