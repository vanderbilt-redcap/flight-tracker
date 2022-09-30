<?php

use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

$moduleId = \ExternalModules\ExternalModules::getIdForPrefix("flightTracker");

if ($moduleId && $pid) {
    $sql = [];
    $sql[] = "DELETE FROM redcap_external_module_settings WHERE project_id = ? AND external_module_id = ?";
    $sql[] = "DELETE FROM redcap_external_modules_log WHERE project_id = ? AND external_module_id = ?";
    $sql[] = "DELETE FROM redcap_external_modules_log_parameters WHERE project_id = ? AND external_module_id = ?";

    $module = Application::getModule();
    foreach ($sql as $query) {
        $module->query($query, [$pid, $moduleId]);
    }
    echo "Done with ".count($sql)." queries.";
} else {
    echo "Could not determine moduleId.";
}
