<?php

use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use Vanderbilt\CareerDevLibrary\FeatureSwitches;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

Application::increaseProcessingMax(2);
$pids = Application::getPids();
$returnData = DataDictionaryManagement::installAllMetadataForNecessaryPids($pids);
echo REDCapManagement::json_encode_with_spaces($returnData);
