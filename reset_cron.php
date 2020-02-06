<?php

$moduleDirectoryPrefix = @$_GET['prefix'];
$result = \ExternalModules\ExternalModules::resetCron($moduleDirectoryPrefix);
if ($result) {
	echo "Success: The cron was reset.";
} else {
	echo "The cron was not reset due to an internal error.";
}
