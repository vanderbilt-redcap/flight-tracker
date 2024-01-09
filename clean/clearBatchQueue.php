<?php

use \Vanderbilt\CareerDevLibrary\CronManager;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

$module = Application::getModule();
$suffix = Sanitizer::sanitize($_GET['suffix'] ?? "");
$manager = new CronManager($token, $server, $pid, $module, $suffix);
$manager->clearBatchQueue();
echo "Done.";