<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\EmailManager;

require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/EmailManager.php");

if ($_POST['messages']) {
	$mgr = new EmailManager($token, $server, $pid, CareerDev::getModule());
	$mgr->sendPreparedEmails($_POST['messages'], TRUE);
} else {
	throw new \Exception("Must have messages populated!");
}
