<?php

namespace Vanderbilt\CareerDevLibrary;

# TODO Check TODO below!!!

require_once(dirname(__FILE__)."/../classes/Autoload.php");

function sendTable1PredocEmails($token, $server, $pid, $records) {
	$populations = ["predocs", "both"];
	$from = Application::getSetting("default_from", $pid);
	sendTable1Emails($populations, $from);
}

function sendTable1PostdocEmails($token, $server, $pid, $records) {
	$populations = ["postdocs", "both"];
	$from = Application::getSetting("default_from", $pid);
	sendTable1Emails($populations, $from);
}

function sendTable1Emails($populations, $from) {
	$table1Pid = Application::getTable1PID();
	if ($table1Pid) {
		$link = Application::getTable1SurveyLink();
		if (!$link) {
			throw new \Exception("Could not generate a public survey link for NIH Training Table 1!");
		}
		$redcapData = \REDCap::getData($table1Pid, "json-array", null, ["record_id", "name", "email", "population", "program", "last_update"]);

		$emailsToSend = [];
		$programsByEmail = [];
		$lastUpdates = [];
		foreach ($redcapData as $row) {
			if (in_array($row['population'], $populations)) {
				$email = $row['email'];
				$name = $row['name'];
				$emailsToSend[$email] = [
					"subject" => "Help keep NIH Training Table data up to date!",
					"message" => "Dear $name,<br/><br/>Please help keep our data for NIH Training Table 1 up to date by filling out this short REDCap survey.<br/><a href='$link'>$link</a><br/><br/>",
				];
				if (!isset($programsByEmail[$email])) {
					$programsByEmail[$email] = [];
				}
				if (!in_array($row['program'], $programsByEmail[$email])) {
					$programsByEmail[$email][] = $row['program'];
				}

				$ts = $row['last_update'] ? strtotime($row['last_update']) : 0;
				if (!isset($lastUpdates[$email])) {
					$lastUpdates[$email] = [];
				}
				if (!isset($lastUpdates[$email][$row['program']])) {
					$lastUpdates[$email][$row['program']] = 0;
				}
				if ($lastUpdates[$email][$row['program']] < $ts) {
					$lastUpdates[$email][$row['program']] = $ts;
				}
			}
		}

		foreach ($emailsToSend as $to => $messageData) {
			$subject = $messageData['subject'];
			$mssg = $messageData['message'];
			$programs = [];
			foreach ($programsByEmail[$to] as $program) {
				$ts = $lastUpdates[$to][$program];
				$date = ($ts > 0) ? " (last updated: ".date("m-d-Y", $ts).")" : "";
				$programs[] = $program.$date;
			}
			$numPrograms = count($programs);
			$mssg .= "The $numPrograms programs you have filled out in the past are:<br/>".implode("<br/>", $programs);
			// TODO \REDCap::email($to, $from, $subject, $mssg);
		}
	} else {
		throw new \Exception("No Table 1 project-id specified!");
	}
}
