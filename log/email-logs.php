<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

$date = date("Y-m-d", time() - 24 * 3600);  // two days prior
$pid = Sanitizer::sanitizePid($_GET['pid']);

if (is_numeric($pid)) {
	$results = $module->queryLogs("
		select log_id, timestamp, message
		order by log_id desc
		where project_id = '$pid'
			and timestamp >= '$date 00:00:00'
	");

	$rows = [];
	while($row = $results->fetch_assoc()){
		$rows[] = $row;
	}

    $from = Application::getSetting("default_from", $pid);
	if ($adminEmail) {
        $adminEmails = preg_split("/\s*,\s*/", $adminEmail);
        $from = $adminEmails[0];
    }

	$mssg = "";
	$mssg .= "<h1>".$module->getName()." Error Report</h1>\n";
	$mssg .= "<p>Institution: ".Application::getInstitution()."<br>Project: $tokenName ($pid)<br>Contact email(s): $adminEmail</p>\n";
	$mssg .= "<table style='border-collapse: collapse;'>\n";
	$mssg .= "<tr><th>Log ID</th><th>Timestamp</th><th>Message</th></tr>\n";
	$i = 0;
	foreach ($rows as $row) {
		$style = "";
		if ($i % 2 == 0) {
			$style = " style='background-color: #eeeeee;'";
		}
		$mssg .= "<tr><td$style>".$row['log_id']."</td><td$style>".$row['timestamp']."</td><td$style>".$row['message']."</td></tr>\n";
		$i++;
	}
	$mssg .= "</table>\n";

	\REDCap::email(Application::getFeedbackEmail(), $from, $module->getName()." Error Report", $mssg); 
}
