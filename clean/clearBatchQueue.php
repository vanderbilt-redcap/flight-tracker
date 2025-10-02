<?php

use Vanderbilt\CareerDevLibrary\CronManager;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

$module = Application::getModule();
$suffix = Sanitizer::sanitize($_GET['suffix'] ?? "");
$manager = new CronManager($token, $server, $pid, $module, $suffix);
if (isset($_GET['first'])) {
	$manager->markFirstItemAsDone();
	# also might need to reset External Module (disable then re-enable system-wide) to reset redcap_crons table settings
} else {
	$manager->clearBatchQueue();
}
echo "Done.";
