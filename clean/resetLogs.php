<?php

$pid = $_GET['pid'];
try {
	if (!$pid) {
		die("You must specify a pid!");

        	$fromAndWhereClause = "FROM redcap_external_modules_log AS l INNER JOIN redcap_external_modules AS m ON m.external_module_id = l.external_module_id WHERE m.directory_prefix = 'flight_tracker' AND l.project_id = ?";
		$iteration = 0;
        	do {
			$iteration++;
            		$deleteSql = "DELETE $fromAndWhereClause ORDER BY l.log_id LIMIT 10000";
            		$selectSql = "SELECT l.log_id $fromAndWhereClause ORDER BY l.log_id LIMIT 1";
            		$this->query($deleteSql, [$pid]);
            		$result = $this->query($selectSql, [$pid]);
            		$moreToDelete = $result && $result->fetch_assoc();
        	} while ($moreToDelete && ($iteration < 50000));
		echo "Success.";
	}
} catch(\Exception $e) {
	echo "ERROR: ".$e->getMessage();
}
