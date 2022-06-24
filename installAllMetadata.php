<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\FeatureSwitches;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

Application::increaseProcessingMax(2);

$files = Application::getMetadataFiles();
$lastCheckField = "prior_metadata_ts";
$deletionRegEx = DataDictionaryManagement::getDeletionRegEx();

$pidsToRun = [];
$pids = Application::getPids();
foreach ($pids as $requestedPid) {
    if (REDCapManagement::isActiveProject($requestedPid)) {
        $pidsToRun[] = $requestedPid;
    }
}

$returnData = [];
foreach ($pidsToRun as $currPid) {
    $currToken = Application::getSetting("token", $currPid);
    $currServer = Application::getSetting("server", $currPid);
    $currSwitches = new FeatureSwitches($currToken, $currServer, $currPid);
    $currGrantClass = Application::getSetting("grant_class", $currPid);
    $currEventId = Application::getSetting("event_id", $currPid);
    if ($currToken && $currServer && $currEventId) {
        Application::log("Installing metadata", $currPid);
        $returnData[$currPid] = DataDictionaryManagement::installMetadataFromFiles($files, $currToken, $currServer, $currPid, $currEventId, $currGrantClass, CareerDev::getRelevantChoices(), $deletionRegEx, $currSwitches->getFormsToExclude());
    }
}
echo REDCapManagement::json_encode_with_spaces($returnData);
