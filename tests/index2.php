<?php

namespace Vanderbilt\FlightTrackerExternalModule;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Consortium;
use \Vanderbilt\CareerDevLibrary\Grant;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/CareerDev.php");
require_once(dirname(__FILE__)."/classes/Consortium.php");
require_once(dirname(__FILE__)."/classes/Grant.php");

$bottomPadding = "<br><br><br><br><br>\n";
$grantNumberHeader = "";
if ($grantNumber = CareerDev::getSetting("grant_number", $pid)) {
	$grantNumberHeader = " - ".Grant::translateToBaseAwardNumber($grantNumber);
}

$projectSettings = Download::getProjectSettings($token, $server);
$projectNotes = "";
if ($projectSettings['project_notes']) {
    $projectNotes = "<p class='centered'>".$projectSettings['project_notes']."</p>";
}

?>
<html><body>Success 2.</body></html>
