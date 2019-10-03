<?php

namespace Vanderbilt\CareerDevLibrary;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/CareerDev.php");

class Application {
	public static function getPID($token) {
		return CareerDev::getPid($token);
	}

	public static function getModule() {
		return CareerDev::getModule();
	}

	public static function getInstitutions() {
		return CareerDev::getInstitutions();
	}
}
