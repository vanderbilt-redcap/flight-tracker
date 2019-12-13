<?php

use \Vanderbilt\CareerDevLibrary\EmailManager;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../classes/EmailManager.php");
require_once(dirname(__FILE__)."/../classes/Download.php");

$metadata = Download::metadata($token, $server);
$mgr = new EmailManager($token, $server, $pid, CareerDev::getModule(), $metadata);
$mgr->sendRelevantEmails();
