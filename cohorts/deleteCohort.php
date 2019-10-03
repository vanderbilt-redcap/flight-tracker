<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Cohorts;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Cohorts.php");

# no JS, no CSS

$cohort = $_POST['cohort'];

if ($cohort) {
	$cohorts = new Cohorts($token, $server, CareerDev::getModule());
	if ($cohorts->nameExists($cohort)) {
		$cohorts->deleteCohort($cohort);
		echo "success";
	} else {
		echo "The name is not found.";
	}
} else {
	echo "You must supply a valid cohort.";
}
