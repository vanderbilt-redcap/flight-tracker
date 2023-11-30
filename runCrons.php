<?php

use \Vanderbilt\CareerDevLibrary\CronManager;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/classes/Autoload.php");
require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/cronLoad.php");

define('REDIRECT_EMAILS', FALSE);

$module = Application::getModule();
$module->cronManager = new CronManager($token, $server, $pid, $module);
$specialOnly = FALSE;
if (isset($argv[2]) && ($argv[2] = "special")) {
	$specialOnly = TRUE;
}
if (isset($_GET['initial'])) {
    \Vanderbilt\FlightTrackerExternalModule\loadInitialCrons($module->cronManager, $specialOnly);
} else {
    \Vanderbilt\FlightTrackerExternalModule\loadCrons($module->cronManager, $specialOnly);
}
error_log($module->cronManager->getNumberOfCrons()." total crons loaded in");
if (REDIRECT_EMAILS) {
    $adminEmail = "scott.j.pearson@vumc.org";
}
$module->cronManager->runBatchJobs();
// $module->cronManager->run($adminEmail, $tokenName, $pid);
