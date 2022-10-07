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
        $instance = $instances[$record] ?: 1;
		$link = \REDCap::getSurveyLink($record, $instrument, NULL, $instance, $pid);
		$results[$record] = $link;
	}
	echo json_encode($results);
} else {
	throw new \Exception("Must supply records, instrument, and instances!");
}
