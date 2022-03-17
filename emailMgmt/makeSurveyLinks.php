<?php

define("NOAUTH", TRUE);      // for plugin

require_once(dirname(__FILE__)."/../../../redcap_connect.php");

$records = $_POST['records'];
$pid = $_GET['pid'];
$instrument = $_POST['instrument'];
$instances = $_POST['instances'];

if ($records && $instrument && $instances) {
	$results = array();
	foreach ($records as $record) {
		if ($instances[$record]) {
			$instance = $instances[$record];
		} else {
			$instance = 1;
		}
		$link = \REDCap::getSurveyLink($record, $instrument, $instance);
		$results[$record] = $link;
	}
	echo json_encode($results);
} else {
	throw new \Exception("Must supply records, instrument, and instances!");
}
