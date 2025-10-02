<?php

define("NOAUTH", true);      // for plugin

if (empty($_POST)) {
	$json = file_get_contents("php://input");
	$_POST = json_decode($json, true) ?? [];
}

require_once(dirname(__FILE__)."/../../../redcap_connect.php");

$records = (isset($_POST['records']) && is_array($_POST['records'])) ? $_POST['records'] : [];
$pid = (isset($_GET['pid']) && is_numeric($_GET['pid'])) ? $_GET['pid'] : "";
$instrument = $_POST['instrument'] ?? "";
$instances = (isset($_POST['instances']) && is_array($_POST['instances'])) ? $_POST['instances'] : [];

if ($records && $instrument && $instances && $pid) {
	$results = [];
	foreach ($records as $record) {
		if (is_string($record)) {
			$instance = $instances[$record] ?? 1;
			$link = \REDCap::getSurveyLink($record, $instrument, null, $instance, $pid);
			$results[$record] = $link;
		}
	}
	echo json_encode($results);
} else {
	die("Must supply records, instrument, and instances!");
}
