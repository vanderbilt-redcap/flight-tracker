<?php

use \Vanderbilt\CareerDevLibrary\EmailManager;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$metadata = Download::metadata($token, $server);
if ($_POST['messages']) {
	$mgr = new EmailManager($token, $server, $pid, CareerDev::getModule(), $metadata);
	$mgr->sendPreparedEmails($_POST['messages'], TRUE);
} else {
	throw new \Exception("Must have messages populated!");
}
