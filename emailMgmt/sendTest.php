<?php

use \Vanderbilt\CareerDevLibrary\EmailManager;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$metadata = Download::metadata($token, $server);
if (isset($_POST['messages'])) {
    $messages = Sanitizer::sanitizeArray($_POST['messages'], FALSE, FALSE);
	$mgr = new EmailManager($token, $server, $pid, CareerDev::getModule(), $metadata);
	$mgr->sendPreparedEmails($messages, TRUE);
} else {
	throw new \Exception("Must have messages populated!");
}
