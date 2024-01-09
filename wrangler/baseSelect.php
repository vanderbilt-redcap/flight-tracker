<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Wrangler;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

define("IMG_SIZE", Citation::getImageSize());

require_once(dirname(__FILE__)."/css.php");

$downloadedRecords = Download::records($token, $server);
$sanitizedPage = isset($_GET['page']) ? Sanitizer::sanitize($_GET['page']) : "";
$sanitizedRecord = Sanitizer::getSanitizedRecord($_GET['record'] ?? "", $downloadedRecords);
if (!$sanitizedRecord && (count($downloadedRecords) > 0)) {
    $sanitizedRecord = $downloadedRecords[0];
}

$submissionUrl = Application::link("wrangler/savePubs.php");
if ($_GET['wranglerType'] == "Patents") {
    $submissionUrl = Application::link("wrangler/savePatents.php");
}

echo Wrangler::getWranglerJS($sanitizedRecord, $submissionUrl, $pid, $sanitizedPage);

function getSearch() {
	return Publications::getSearch();
}

function getSelectRecord() {
	return Publications::getSelectRecord();
}
