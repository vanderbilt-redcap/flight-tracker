<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(APP_PATH_DOCROOT."Classes/System.php");

$moduleId = CareerDev::getModuleId();
if (!$moduleId) {
    die("No module ID");
}

$hours = 16;
\System::increaseMaxExecTime($hours * 3600);

$newTableName = "redcap_external_modules_log_copy";

$sql = "CREATE TABLE `$newTableName` (
`log_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
`timestamp` datetime NOT NULL,
`ui_id` int(11) DEFAULT NULL,
`ip` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
`external_module_id` int(11) DEFAULT NULL,
`project_id` int(11) DEFAULT NULL,
`record` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
`message` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
PRIMARY KEY (`log_id`),
KEY `external_module_id` (`external_module_id`),
KEY `message` (`message`(190)),
KEY `record` (`record`),
KEY `redcap_log_redcap_projects_record` (`project_id`,`record`),
KEY `timestamp` (`timestamp`),
KEY `ui_id` (`ui_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ";
db_query($sql);

if ($error = db_error()) {
    die("ERROR: $error $sql");
}

$sql = "SELECT * FROM redcap_external_modules_log WHERE external_module_id != '$moduleId'";
$q = db_query($sql);
$escapedKeys = [];
$keys = [];
$rowsOfValues = [];
while ($row = db_fetch_assoc($q)) {
    $escapedValues = [];
    if (empty($escapedKeys)) {
        foreach ($row as $key => $value) {
            $escapedKeys[] = "`$key`";
            $keys[] = $key;
        }
    }
    foreach ($keys as $key) {
        $value = $row[$key] ?? "";
        $escapedValues[] = "'".db_real_escape_string($value)."'";
    }
    $rowsOfValues[] = "(".implode(", ", $escapedValues).")";
    if (count($rowsOfValues) >= 10) {
        $sql = "INSERT INTO $newTableName (".implode(", ", $escapedKeys).") VALUES ".implode(", ", $rowsOfValues);
        db_query($sql);
        if ($error = db_error()) {
            die("ERROR: $error $sql");
        }

        $rowsOfValues = [];
    }
}
if (!empty($rowsOfValues)) {
    $sql = "INSERT INTO $newTableName (".implode(", ", $escapedKeys).") VALUES ".implode(", ", $rowsOfValues);
    db_query($sql);
    if ($error = db_error()) {
        die("ERROR: $error $sql");
    }
}

# redcap_external_modules_log_parameters has a foreign key to log_id

$sql = "SET FOREIGN_KEY_CHECKS = 0";
db_query($sql);
if ($error = db_error()) {
    die("ERROR: $error $sql");
}

$sql = "DROP TABLE redcap_external_modules_log";
db_query($sql);
if ($error = db_error()) {
    die("ERROR: $error $sql");
}

$sql = "SET FOREIGN_KEY_CHECKS = 1";
db_query($sql);
if ($error = db_error()) {
    die("ERROR: $error $sql");
}

# redcap_external_modules_log_parameters is never used by Flight Tracker

$sql = "RENAME TABLE $newTableName TO redcap_external_modules_log";
db_query($sql);
if ($error = db_error()) {
    die("ERROR: $error $sql");
}

echo "Done.\n";