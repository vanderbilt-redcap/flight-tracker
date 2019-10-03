<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\EmailManager;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../classes/EmailManager.php");

$field = getFieldForCurrentEmailSetting();
if ($_POST['to'] && $_POST[$field]) {
	$mgr = new EmailManager($token, $server, $pid, CareerDev::getModule());
	$data = $mgr->prepareRelevantEmails($_POST['to'], $_POST['existingName']);
	return json_encode($data);
} else {
	throw new \Exception("Must have to and $field populated!");
}
