<?php

namespace Vanderbilt\CareerDevLibrary;

use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

function deleteCoeus2Notice($token, $server, $pid, $recordIds) {
	CareerDev::clearDate("Last StarBRITE COEUS Pull", $pid);
}

function deleteExPORTERNotice($token, $server, $pid, $recordIds) {
	CareerDev::clearDate("Last NIH ExPORTER Download", $pid);
}
