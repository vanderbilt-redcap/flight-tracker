<?php

use Vanderbilt\FlightTrackerExternalModule\CareerDev;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Cohorts;
use Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

# no JS, no CSS

$oldValue = Sanitizer::sanitize($_POST['oldValue'] ?? "");
$newValue = Sanitizer::sanitize($_POST['newValue'] ?? "");

if ($oldValue && $newValue) {
	if (!preg_match(Cohorts::PROHIBITED_CHARACTERS_REGEX, $newValue)) {
		$cohorts = new Cohorts($token, $server, CareerDev::getModule());
		if (!$cohorts->nameExists($newValue)) {
			if ($cohorts->nameExists($oldValue)) {
				$cohorts->modifyCohortName($oldValue, $newValue);
				echo "success";
			} else {
				echo "The old name is not found.";
			}
		} else {
			echo "The proposed new name is already used.";
		}
	} else {
		echo "You cannot specify a single-quote, a double-quote, an ampersand, or a hashtag.";
	}
} else {
	echo "You must supply a valid old value and a valid new value.";
}
