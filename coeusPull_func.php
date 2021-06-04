<?php
use \Vanderbilt\CareerDevLibrary\COEUSConnection;

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

function pullCoeus($token, $server, $pid) {
	$conn = new COEUSConnection();
	$conn->connect();
	$data = $conn->pullAllRecords();
	$conn->close();

	$fp = fopen(dirname(__FILE__)."/coeus_award.json", "w");
	fwrite($fp, json_encode($data['awards']));
	fclose($fp);

	$fp = fopen(dirname(__FILE__)."/coeus_investigator.json", "w");
	fwrite($fp, json_encode($data['investigators']));
	fclose($fp);

	error_log("Done with data save.");

	error_log(count($data['investigators'])." investigators downloaded");
	error_log(count($data['awards'])." awards downloaded");
}

