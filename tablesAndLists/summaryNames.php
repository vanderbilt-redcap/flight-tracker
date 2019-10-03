<?php

# lists the names in the summary information

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Links;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Links.php");

$redcapData = \Vanderbilt\FlightTrackerExternalModule\alphabetizeREDCapData(Download::fields($token, $server, array("record_id", "identifier_first_name", "identifier_last_name", "identifier_email")));

echo "<style>";
echo "a { text-decoration: none; color: black; }";
echo "a:active { text-decoration: underline; }";
echo "a:hover { text-decoration: underline; }";
echo "</style>";
echo "<h1>Names of Career Development Data</h1>";
echo "<table class='centered'>";
echo "<tr class='odd'><th>Record ID</th><th>Full Name</th><th>First Name</th><th>Last Name</th><th>Email</th></tr>";
$i = 0;
foreach ($redcapData as $row) {
	if ($i % 2 == 0) {
		$myclass = "even";
	} else if ($i % 2 == 1) {
		$myclass = "odd";
	}
	$url = APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=summary";
	$email = "";
	if ($row['identifier_email']) {
		$email = "<a href='mailto:".$row['identifier_email']."'>".$row['identifier_email']."</a>";
	}
	echo "<tr class='$myclass'>\n";
	echo "<td>".Links::makeSummaryLink($pid, $row['record_id'], $event_id, "Record {$row['record_id']}", "")."</td>";
	echo "<td>".Links::makeIdentifiersLink($pid, $row['record_id'], $event_id, $row['identifier_first_name']." ".$row['identifier_last_name'], "")."</td>";
	echo "<td>".Links::makeIdentifiersLink($pid, $row['record_id'], $event_id, $row['identifier_first_name'], "")."</td>";
	echo "<td>".Links::makeSummaryLink($pid, $row['record_id'], $event_id, $row['identifier_last_name'], "")."</td>";
	echo "<td>$email</td>";
	echo "</tr>\n";
	$i++;
}
echo "</table>";

