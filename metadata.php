<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\FeatureSwitches;

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

$files = Application::getMetadataFiles();
$lastCheckField = "prior_metadata_ts";
$deletionRegEx = DataDictionaryManagement::getDeletionRegEx();
$switches = new FeatureSwitches($token, $server, $pid);

if ($_POST['process'] == "check") {
    $ts = $_POST['timestamp'];
    $lastCheckTs = CareerDev::getSetting($lastCheckField);
    if (!$lastCheckTs) {
        $lastCheckTs = 0;
    }

    # check a maximum of once every 30 seconds
    if ($ts > $lastCheckTs + 30) {
        $metadata = Download::metadata($token, $server);
        list ($missing, $additions, $changed) = DataDictionaryManagement::findChangedFieldsInMetadata($metadata, $files, $deletionRegEx, CareerDev::getRelevantChoices(), $switches->getFormsToExclude());
        CareerDev::setSetting($lastCheckField, time(), $pid);
        if (count($additions) + count($changed) > 0) {
            if (Application::isSuperUser()) {
                $module = Application::getModule();
                $pids = $module->getPids();
                echo "<div id='metadataWarning' class='install-metadata-box install-metadata-box-danger'>
                <i class='fa fa-exclamation-circle' aria-hidden='true'></i> <a href='javascript:;' onclick='installMetadataForProjects(" . json_encode($pids) . ");'>Click here to install for all " . Application::getProgramName() . " projects (REDCap SuperUsers only).</a>
                </div>";
            }
            echo "<script>var missing = " . json_encode($missing) . ";</script>\n";
            echo "<div id='metadataWarning' class='install-metadata-box install-metadata-box-danger'>
                <i class='fa fa-exclamation-circle' aria-hidden='true'></i> An upgrade in your Data Dictionary exists. <a href='javascript:;' onclick='installMetadata(missing);'>Click here to install.</a>
                <ul><li>The following fields will be added: " . (empty($additions) ? "<i>None</i>" : "<strong>" . implode(", ", $additions) . "</strong>") . "</li>
                <li>The following fields will be changed: " . (empty($changed) ? "<i>None</i>" : "<strong>" . implode(", ", $changed) . "</strong>") . "</li></ul>
            </div>";
        }
    }
} else if (in_array($_POST['process'], ["install", "install_all"])) {
    $returnData = DataDictionaryManagement::installMetadataFromFiles($files, $token, $server, $pid, $eventId, $grantClass, CareerDev::getRelevantChoices(), $deletionRegEx, $switches->getFormsToExclude());
    echo json_encode($returnData);
}

