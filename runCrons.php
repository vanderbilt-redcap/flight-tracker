<?php

use \Vanderbilt\CareerDevLibrary\CronManager;

require_once(dirname(__FILE__)."/classes/Crons.php");
require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/cronLoad.php");

$manager = new CronManager($token, $server, $pid);
$specialOnly = FALSE;
if (isset($argv[2]) && ($argv[2] = "special")) {
	$specialOnly = TRUE;
}
\Vanderbilt\FlightTrackerExternalModule\loadCrons($manager, $specialOnly);
error_log($manager->getNumberOfCrons()." total crons loaded in");
$manager->run($adminEmail, $tokenName, $pid);
