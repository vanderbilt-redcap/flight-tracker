<?php

require_once(dirname(__FILE__)."/classes/Crons.php");
require_once(dirname(__FILE__)."/small_base.php");

$manager = new CronManager($token, $server, $pid);
$specialOnly = FALSE;
if (isset($argv[2]) && ($argv[2] = "special")) {
	$specialOnly = TRUE;
}
loadCrons($manager, $specialOnly);
error_log($manager->getNumberOfCrons()." total crons loaded");
$manager->run($adminEmail, $tokenName, $pid);
