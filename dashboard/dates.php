<?php

use \Vanderbilt\CareerDevLibrary\Measurement;
use \Vanderbilt\CareerDevLibrary\DateMeasurement;
use \Vanderbilt\CareerDevLibrary\MoneyMeasurement;
use \Vanderbilt\CareerDevLibrary\ObservedMeasurement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/".\Vanderbilt\FlightTrackerExternalModule\getTarget().".php");


$headers = array();
array_push($headers, "Important Dates");
if ($_GET['cohort']) {
	array_push($headers, "For Cohort ".$_GET['cohort']);
}

$settings = \Vanderbilt\FlightTrackerExternalModule\getAllSettings();

$measurements = array();
foreach ($settings as $setting => $value) {
	if ($value) {
		$measurements[$setting] = new DateMeasurement($value);
	}
}
echo makeHTML($headers, $measurements, array(), $_GET['cohort']);
