<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\FeatureSwitches;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

Application::increaseProcessingMax(2);

$files = Application::getMetadataFiles();
$deletionRegEx = DataDictionaryManagement::getDeletionRegEx();

$pidsToRun = [];
$pids = Application::getPids();
foreach ($pids as $requestedPid) {
    if (REDCapManagement::isActiveProject($requestedPid)) {
        $requestedToken = Application::getSetting("token", $requestedPid);
        $requestedServer = Application::getSetting("server", $requestedPid);
        if ($requestedToken && $requestedServer) {
            $requestedMetadata = Download::metadata($requestedToken, $requestedServer);
            $switches = new FeatureSwitches($requestedToken, $requestedServer, $requestedPid);
            list ($missing, $additions, $changed) = DataDictionaryManagement::findChangedFieldsInMetadata($requestedMetadata, $files, $deletionRegEx, CareerDev::getRelevantChoices(), $switches->getFormsToExclude(), $requestedPid);
            if (count($additions) + count($changed) > 0) {
                $pidsToRun[] = $requestedPid;
            }
        }
    }
}

$returnData = DataDictionaryManagement::installMetadataForPids($pidsToRun, $files, $deletionRegEx);
echo REDCapManagement::json_encode_with_spaces($returnData);
