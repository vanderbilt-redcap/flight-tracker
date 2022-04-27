<?php

use \Vanderbilt\CareerDevLibrary\Application;

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set("log_errors", 1);

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

error_log("Getting Module");
$module = Application::getModule();
error_log("Starting cron");
$module->cron();
error_log("Done");
