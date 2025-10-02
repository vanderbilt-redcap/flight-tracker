<?php

use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\EmailManager;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$startTs = time();
$metadata = Download::metadata($token, $server);
$mgr = new EmailManager($token, $server, $pid, Application::getModule(), $metadata);
$queue = $mgr->getQueue($startTs);

echo "<h1>Email Queue</h1>";
echo "<h2>Current Date and Time: ".date("m-d-Y H:i", $startTs)."</h2>";

if (empty($queue)) {
	echo "<p class='centered'>No emails are presently enqueued.</p>";
} else {
	echo "<h3>Pending Emails</h3>";
	echo "<table class='centered max-width'>";
	echo "<thead>";
	echo "<tr>";
	echo "<th>Email Name</th><th>Date/Time to Send</th><th>From</th><th>Subject</th><th>To</th>";
	echo "</tr>";
	echo "</thead>";
	echo "<tbody>";
	$rowNum = 0;
	foreach ($queue as $row) {
		echo "<tr>";
		echo "<td>".$row['name']."</td>";
		echo "<td>".REDCapManagement::YMD2MDY($row['date'])." ".$row['time']."</td>";
		echo "<td>".$row['from']."</td>";
		echo "<td>".$row['subject']."</td>";
		echo "<td><a href='javascript:;' onclick='$(\"#row_$rowNum\").show();'>".$row['to_count']." recipients</a><div id='row_$rowNum'>".implode("<br>", $row['to'])."</div></td>";
		echo "</tr>";
		$rowNum++;
	}
	echo "</tbody></table>";
}
