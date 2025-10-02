<?php

use Vanderbilt\CareerDevLibrary\EmailManager;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$metadata = Download::metadata($token, $server);
$field = EmailManager::getFieldForCurrentEmailSetting();
if ($_POST['to'] && $_POST[$field]) {
	$mgr = new EmailManager($token, $server, $pid, CareerDev::getModule(), $metadata);
	$data = $mgr->prepareRelevantEmails($_POST['to'], $_POST[$field]);
	echo json_encode($data);
} else {
	throw new \Exception("Must have to and $field populated!");
}
