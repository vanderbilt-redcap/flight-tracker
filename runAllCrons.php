<?php

use \Vanderbilt\CareerDevLibrary\CronManager;
use \Vanderbilt\CareerDevLibrary\Application;
use function Vanderbilt\FlightTrackerExternalModule\loadInitialCrons;

require_once(dirname(__FILE__)."/classes/Autoload.php");
require_once(dirname(__FILE__)."/cronLoad.php");
require_once(dirname(__FILE__)."/small_base.php");

$module = Application::getModule();
$module->cronManager = new CronManager($token, $server, $pid, $module, "");
loadInitialCrons($module->cronManager, FALSE, $token, $server);
$module->cronManager->runBatchJobs();

$link = Application::link("batch.php", $pid);
echo "<p class='centered'>Batch jobs enqueued for pid $pid. Go to <a href='$link'>Batch Inspector</a> to monitor.</p>";