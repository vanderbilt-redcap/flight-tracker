<?php

use Vanderbilt\CareerDevLibrary\EmailManager;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$who = $_POST;

$module = CareerDev::getModule();
$metadata = [];
if (!$module) {
	$metadata = Download::metadata($token, $server);
}

$mgr = new EmailManager($token, $server, $pid, $module, $metadata);
$names = $mgr->getNames($who);
if (empty($names)) {
	echo "No names match your description.";
} else {
	if (isset($who['recipient']) && ($who['recipient'] == "individuals")) {
		$emails = $mgr->getEmails($who);

		$lines = [];
		foreach ($names as $recordId => $name) {
			$email = $emails[$recordId];
			$lines[] = $name . ";" . $email;
		}
		echo implode("<br>", $lines);
	} else {
		echo implode("<br>", array_values($names));
	}
}
