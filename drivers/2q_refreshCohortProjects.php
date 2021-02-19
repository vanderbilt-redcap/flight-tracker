<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Cohorts.php");
require_once(dirname(__FILE__)."/../classes/Grants.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");

function copyAllCohortProjects($token, $server, $pid, $records) {
	$metadata = Download::metadata($token, $server);
	$cohorts = new Cohorts($token, $server, $metadata);
	$cohortNames = $cohorts->getCohortNames();

	$module = CareerDev::getModule();
    $pids = $module->framework->getProjectsWithModuleEnabled();
	foreach ($cohortNames as $cohort) {
	    foreach ($pids as $destPid) {
	        $tokenName = CareerDev::getSetting("tokenName", $destPid);
	        if ($tokenName == $cohort) {
                $destToken = CareerDev::getSetting("token", $destPid);
                CareerDev::log("Copying project to cohort $cohort. From pid $pid, to pid $destPid");
                \Vanderbilt\FlightTrackerExternalModule\copyEntireProject($token, $destToken, $server, $metadata, $cohort);
            }
        }
	}
}
