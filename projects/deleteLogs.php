<?php

do {
    $sql = "DELETE FROM redcap_external_modules_log
        WHERE external_module_id = 7
            AND project_id = '20752'
            LIMIT 50000";
    db_query($sql);
    $error = db_error();
    if ($error) {
        die("ERROR in DELETE: $error");
    }
    $proceed = (db_affected_rows() > 0);
} while ($proceed);
