<?php

namespace Vanderbilt\CareerDevLibrary;

use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

function getLDAPs($token, $server, $pid, $records) {
	$metadata = Download::metadata($token, $server);
	$repeatingForms = REDCapManagement::getRepeatingForms($pid);
	$firstNames = Download::firstnames($token, $server);
	$lastNames = Download::lastnames($token, $server);
	$userids = Download::userids($token, $server);
	foreach ($records as $recordId) {
		$firstName = $firstNames[$recordId];
		try {
			Upload::deleteForm($token, $server, $pid, "ldapds_", $recordId);
		} catch (\Throwable $e) {
			if (!preg_match("/Could not find record/", $e->getMessage())) {
				throw $e;
			} else {
				Application::log($e->getMessage(), $pid);
			}
		}
		$recordUserids = (isset($userids[$recordId]) && $userids[$recordId]) ? preg_split("/\s*,\s*/", $userids[$recordId]) : [];
		foreach ($recordUserids as $userid) {
			$lastName = $lastNames[$recordId];
			try {
				if ($userid) {
					Application::log("$pid: Searching for userid $userid");
					$upload = LDAP::getREDCapRowsFromUid($userid, $metadata, $recordId, $repeatingForms);
				} else {
					Application::log("$pid: Searching for name $firstName $lastName");
					$upload = LDAP::getREDCapRowsFromName($firstName, $lastName, $metadata, $recordId, $repeatingForms);
				}
				if (!empty($upload)) {
					Upload::rows($upload, $token, $server);
				}
			} catch (\Exception $e) {
				Application::log("ERROR: ".$e->getMessage());
			}
		}
	}
	CareerDev::saveCurrentDate("Last LDAP Directory Pull", $pid);
}
