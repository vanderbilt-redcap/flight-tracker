<?php

use \Vanderbilt\CareerDevLibrary\CronManager;

require_once(dirname(__FILE__)."/classes/Autoload.php");
require_once(dirname(__FILE__)."/small_base.php");

$manager = new CronManager($token, $server, $pid);
$manager->addCron("drivers/12_reportStats.php", "reportStats", date("Y-m-d"));
error_log($manager->getNumberOfCrons()." total crons loaded in");
$manager->run($adminEmail, $tokenName, $pid);
