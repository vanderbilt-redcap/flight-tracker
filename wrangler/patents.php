<?php

use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

$defaultLocation = Application::link("wrangler/include.php", $pid)."&wranglerType=Patents";
if (isset($_GET['record'])) {
	$records = Download::recordIds($token, $server);
	$record = Sanitizer::getSanitizedRecord($_GET['record'], $records);
	if ($record) {
		header("Location: ".$defaultLocation."&record=$record");
	} else {
		header("Location: ".$defaultLocation);
	}
} else {
	header("Location: ".$defaultLocation);
}
