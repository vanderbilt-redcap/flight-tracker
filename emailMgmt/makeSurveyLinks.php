<?php

define("NOAUTH", TRUE);      // for plugin

require_once(dirname(__FILE__)."/../../../redcap_connect.php");

$records = (isset($_POST['records']) && is_array($_POST['records'])) ? $_POST['records'] : [];
$pid = (isset($_GET['pid']) && is_numeric($_GET['pid'])) ? $_GET['pid'] : "";
$instrument = $_POST['instrument'] ?? "";
$instances = (isset($_POST['instances']) && is_array($_POST['instances'])) ? $_POST['instances'] : [];

if ($records && $instrument && $instances && $pid) {
	$results = array();
	foreach ($records as $record) {
        if (is_string($record)) {
            $instance = $instances[$record] ?? 1;
            $link = \REDCap::getSurveyLink($record, $instrument, NULL, $instance, $pid);
            $results[$record] = $link;
        }
	}
	echo json_encode($results);
} else {
	throw new \Exception("Must supply records, instrument, and instances!");
}
