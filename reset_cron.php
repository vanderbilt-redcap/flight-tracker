<?php

$moduleDirectoryPrefix = @$_GET['prefix'];
if ($moduleDirectoryPrefix == "flightTracker") {
	$result = \ExternalModules\ExternalModules::resetCron($moduleDirectoryPrefix);
	if ($result) {
		echo "Success: The cron was reset.";
	} else {
		echo "The cron was not reset due to an internal error.";
	}
} else {
	echo "This module only works on Flight Tracker for Scholars";
}
