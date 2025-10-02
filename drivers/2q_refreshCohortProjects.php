<?php

namespace Vanderbilt\CareerDevLibrary;

use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

function copyAllCohortProjects($token, $server, $pid, $records) {
	$metadata = Download::metadata($token, $server);
	$module = CareerDev::getModule();
	$cohorts = new Cohorts($token, $server, $module);
	foreach ($cohorts->getCohortNames() as $cohort) {
		$destPid = $cohorts->getReadonlyPortalValue($cohort, "pid");
		$destToken = $cohorts->getReadonlyPortalValue($cohort, "token");
		if (
			$destPid && $destToken
			&& REDCapManagement::isActiveProject($destPid)
		) {
			CareerDev::log("Copying project to cohort $cohort. From pid $pid, to pid $destPid");
			\Vanderbilt\FlightTrackerExternalModule\copyEntireProject($token, $destToken, $server, $metadata, $cohort);
			\Vanderbilt\FlightTrackerExternalModule\copyBackAnyMentoringAgreements($token, $destToken, $server, $metadata, $cohort);

			$defaultSettings = [
				"turn_off" => true,
				"tokenName" => $cohort,
				"pid" => $destPid,
				"event_id" => REDCapManagement::getEventIdForClassical($destPid),
				"token" => $destToken,
				"supertoken" => "",
				"sourcePid" => $pid,
			];
			CareerDev::duplicateAllSettings($pid, $destPid, $defaultSettings);
		}
	}
}
