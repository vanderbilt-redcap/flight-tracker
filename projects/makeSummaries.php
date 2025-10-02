<?php

use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../drivers/6d_makeSummary.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

Application::increaseProcessingMax(8);
if ($_GET['record']) {
	$records = [$_GET['record']];
} else {
	$records = Download::recordIds($token, $server);
}
\Vanderbilt\CareerDevLibrary\makeSummary($token, $server, $pid, $records, true);
echo "Done.";
