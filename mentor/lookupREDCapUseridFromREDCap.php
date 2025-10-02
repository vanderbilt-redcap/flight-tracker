<?php

namespace Vanderbilt\CareerDevLibrary;

require_once dirname(__FILE__)."/preliminary.php";
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

if (isset($_POST['firstName']) && isset($_POST['lastName'])) {
	$firstName = REDCapManagement::sanitize($_POST['firstName']);
	$lastName = REDCapManagement::sanitize($_POST['lastName']);
	$lookup = new REDCapLookup($firstName, $lastName);
	$uidsAndNames = $lookup->getUidsAndNames();
	echo json_encode($uidsAndNames);
}
