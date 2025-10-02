<?php

use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\REDCapLookupByUserid;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

$prefill = [];
$userid = Application::getUsername();
if ($userid && ($userid !== "[survey respondant]")) {
	$lookup = new REDCapLookupByUserid($userid);
	$prefill['email'] = $lookup->getEmail();
	$prefill['name'] = $lookup->getName();
}

header("Content-Type: application/json");
echo json_encode($prefill);
