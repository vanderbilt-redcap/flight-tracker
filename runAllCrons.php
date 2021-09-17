<?php

use \Vanderbilt\CareerDevLibrary\CronManager;
use \Vanderbilt\CareerDevLibrary\Application;
use function Vanderbilt\FlightTrackerExternalModule\loadInitialCrons;

require_once(dirname(__FILE__)."/classes/Autoload.php");
require_once(dirname(__FILE__)."/cronLoad.php");
require_once(dirname(__FILE__)."/small_base.php");

$mgr = new CronManager($token, $server, $pid, Application::getModule());
loadInitialCrons($mgr, FALSE, $token, $server);
$mgr->runBatchJobs();

$link = Application::link("batch.php", $pid);
echo "<p class='centered'>Batch jobs enqueued for pid $pid. Go to <a href='$link'>Batch Inspector</a> to monitor.</p>";