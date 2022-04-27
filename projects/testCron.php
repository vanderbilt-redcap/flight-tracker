<?php

use \Vanderbilt\CareerDevLibrary\Application;

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set("log_errors", 1);

echo "Including libraries - CareerDevLibrary<br/>";

require_once(dirname(__FILE__)."/../classes/Autoload.php");
echo "Including libraries - base<br/>";
require_once(dirname(__FILE__)."/../small_base.php");

error_log("Getting Module");
echo "Getting Module<br/>";
$module = Application::getModule();
error_log("Starting cron");
echo "Starting cron<br/>";
$module->cron();
error_log("Done");
echo "Done.<br/>";
