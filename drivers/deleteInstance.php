<?php

// define("NOAUTH", true);
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../../../redcap_connect.php");

if (isset($_GET['instance']) && isset($_GET['instrument']) && isset($_GET['pid']) && isset($_GET['record'])) {
	$instance = $_GET['instance'];
	$instrument = $_GET['instrument'];
	$record = $_GET['record'];
	$pid = $_GET['pid'];

	$sql = "SELECT field_name FROM redcap_metadata WHERE project_id = $pid AND form_name = '".db_real_escape_string($instrument)."'";
	$q = db_query($sql); 
	if ($error = db_error()) { die("ERROR 1:<br>$sql<br>$error"); }
	$fields = array();
	while ($row = db_fetch_assoc($q)) {
		$fields[] = $row['field_name'];
	}

	if (!empty($fields)) {
		$fieldsStr = "(";
		$fields2 = array();
		foreach ($fields as $field) {
			$fields2[] = "'".db_real_escape_string($field)."'";
		}
		$fieldsStr .= implode(",", $fields2).")";
		$sql = "DELETE FROM redcap_data WHERE project_id = $pid AND field_name IN $fieldsStr AND record = '".db_real_escape_string($record)."' AND instance";
		if ($instance == 1) {
			$sql .= " IS NULL";
		} else {
			$sql .= " = $instance";
		}
		$q = db_query($sql);
		if ($error = db_error()) { die("ERROR 2:<br>$sql<br>$error"); }

		echo "Deletion complete ".db_affected_rows()." rows affected.";
	} else {
		echo "No data to delete";
	}
} else {
	echo "Please specify instance, instrument, and pid";
}
