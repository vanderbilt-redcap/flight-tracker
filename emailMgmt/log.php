<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\EmailManager;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$metadata = Download::metadata($token, $server);
$names = Download::names($token, $server);
$mgr = new EmailManager($token, $server, $pid, CareerDev::getModule(), $metadata); 

# indexed by setting name; value is an array of items (indices of ts and records)
$sentEmails = $mgr->getSentEmails("all");

echo "<h1>Email Log</h1>\n";
echo "<p class='centered'>Automatic introductory emails are cleaned up over time. Only the most recent instances appear here.</p>\n"; 

if (empty($sentEmails)) {
	echo "<h4>No emails have been sent.</h4>\n";
} else {
	foreach ($sentEmails as $settingName => $sentItems) {
		echo "<h2>$settingName</h2>\n";
		foreach ($sentItems as $item) {
			$ts = $item['ts'];
			$date = date("l m-d-Y H:i", $ts);
			$itemRecords = $item['records'];
			$itemNames = array();
			foreach ($itemRecords as $recordId) {
				$itemNames[$recordId] = Links::makeRecordHomeLink($pid, $recordId, $names[$recordId]);
			}
			echo "<h3>$date</h3>\n";
			echo "<p class='centered'>".implode(", ", array_values($itemNames))."</p>\n";
		}
	}
}
