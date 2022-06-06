<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(APP_PATH_DOCROOT."Classes/System.php");

$moduleId = CareerDev::getModuleId();

$hours = 16;
\System::increaseMaxExecTime($hours * 3600);

$pid = $_GET['pid'];
if (!$pid) {
    die("You must specify a pid!");
}

try {
    $fromAndWhereClause = "FROM redcap_external_modules_log WHERE external_module_id = '$moduleId' AND project_id = ?";
    $iteration = 0;
    do {
        $iteration++;
        $deleteSql = "DELETE $fromAndWhereClause ORDER BY log_id LIMIT 10000";
        $selectSql = "SELECT log_id $fromAndWhereClause ORDER BY log_id LIMIT 1";
        $module->query($deleteSql, [$pid]);
        $result = $module->query($selectSql, [$pid]);
        $moreToDelete = $result && $result->fetch_assoc();
    } while ($moreToDelete && ($iteration < 50000));
    echo "Success.";
} catch(\Exception $e) {
	echo "ERROR: ".$e->getMessage();
}
