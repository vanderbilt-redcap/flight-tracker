<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

function copyAllCohortProjects($token, $server, $pid, $records) {
    $metadata = Download::metadata($token, $server);
    $module = CareerDev::getModule();
	$cohorts = new Cohorts($token, $server, $module);
	$cohortNames = $cohorts->getCohortNames();

	foreach ($cohortNames as $cohort) {
	    $destPid = $cohorts->getReadonlyPortalValue($cohort, "pid");
	    $destToken = $cohorts->getReadonlyPortalValue($cohort, "token");
        if ($cohorts->hasReadonlyProjectsEnabled() && $destPid && $destToken && REDCapManagement::isActiveProject($destPid)) {
            CareerDev::log("Copying project to cohort $cohort. From pid $pid, to pid $destPid");
            \Vanderbilt\FlightTrackerExternalModule\copyEntireProject($token, $destToken, $server, $metadata, $cohort);

            $defaultSettings = [
                "turn_off" => TRUE,
                "tokenName" => $cohort,
                "pid" => $destPid,
                "event_id" => REDCapManagement::getEventIdForClassical($destPid),
                "token" => $destToken,
                "supertoken" => "",
            ];
            CareerDev::duplicateAllSettings($pid, $destPid, $defaultSettings);
        }
	}
}
