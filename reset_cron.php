<?php

$moduleDirectoryPrefix = (isset($_GET['prefix']) && !is_array($_GET['prefix'])) ? $_GET['prefix'] : "";
if ($moduleDirectoryPrefix && method_exists("\\ExternalModules\\ExternalModules", "resetCron")) {
	$result = \ExternalModules\ExternalModules::resetCron($moduleDirectoryPrefix);
} elseif ($moduleDirectoryPrefix) {
	$moduleId = \ExternalModules\ExternalModules::getIdForPrefix($moduleDirectoryPrefix);
	$sql = "DELETE FROM redcap_external_module_settings WHERE external_module_id = '$moduleId' AND `key` = '".\ExternalModules\ExternalModules::KEY_RESERVED_IS_CRON_RUNNING."'";
	$result = \ExternalModules\ExternalModules::query($sql, []);
}
if (isset($result)) {
	echo "Success: The cron was reset.";
} else {
	echo "The cron was not reset due to an internal error.";
}
