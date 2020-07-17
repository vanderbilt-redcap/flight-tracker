<?php

require_once(dirname(__FILE__)."/small_base.php");

$moduleId = \ExternalModules\ExternalModules::getIdForPrefix("flightTracker");

if ($moduleId && $pid) {
    $sql = [];
    $sql[] = "DELETE FROM redcap_external_module_settings WHERE project_id = '$pid' AND external_module_id = '$moduleId'";
    $sql[] = "DELETE FROM redcap_external_modules_log WHERE project_id = '$pid' AND external_module_id = '$moduleId'";
    $sql[] = "DELETE FROM redcap_external_modules_log_parameters WHERE project_id = '$pid' AND external_module_id = '$moduleId'";

    foreach ($sql as $query) {
        db_query($query);
        $error = db_error();
        if ($error) {
            die("ERROR: $error on $query");
        }
    }
    echo "Done with ".count($sql)." queries.";
} else {
    echo "Could not determine moduleId.";
}
