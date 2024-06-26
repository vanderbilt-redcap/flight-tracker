<?php

use \Vanderbilt\CareerDevLibrary\CronManager;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\FlightTrackerExternalModule\FlightTrackerExternalModule;

require_once(dirname(__FILE__)."/classes/Autoload.php");
require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/cronLoad.php");

define('REDIRECT_EMAILS', FALSE);

$module = Application::getModule();
$suffix = Sanitizer::sanitize($_GET['suffix'] ?? "");
$module->cronManager = new CronManager($token, $server, $pid, $module, $suffix);
$specialOnly = FALSE;
if (isset($argv[2]) && ($argv[2] = "special")) {
	$specialOnly = TRUE;
}
if (isset($_GET['initial'])) {
    \Vanderbilt\FlightTrackerExternalModule\loadInitialCrons($module->cronManager, $specialOnly);
} else if ($suffix == "") {
    \Vanderbilt\FlightTrackerExternalModule\loadCrons($module->cronManager, $specialOnly);
} else if ($suffix == FlightTrackerExternalModule::LONG_RUNNING_BATCH_SUFFIX) {
    $pids = Application::getActivePids();
    \Vanderbilt\FlightTrackerExternalModule\loadMultiProjectCrons($module->cronManager, $pids);
} else if ($suffix == FlightTrackerExternalModule::LOCAL_BATCH_SUFFIX) {
    \Vanderbilt\FlightTrackerExternalModule\loadLocalCrons($module->cronManager, $token, $server);
} else if ($suffix == FlightTrackerExternalModule::INTENSE_BATCH_SUFFIX) {
    \Vanderbilt\FlightTrackerExternalModule\loadIntenseCrons($module->cronManager, $specialOnly);
}
error_log($module->cronManager->getNumberOfCrons()." total crons loaded in");
if (REDIRECT_EMAILS) {
    $adminEmail = "scott.j.pearson@vumc.org";
}
$module->cronManager->runBatchJobs();
// $module->cronManager->run($adminEmail, $tokenName, $pid);
