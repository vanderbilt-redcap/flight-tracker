<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

function sendWeeklyEmailHighlights($token, $server, $pid, $records) {
	sendEmailHighlights($token, $server, $pid, "weekly");
}

function sendMonthlyEmailHighlights($token, $server, $pid, $records) {
	sendEmailHighlights($token, $server, $pid, "monthly");
}

function sendEmailHighlights($token, $server, $pid, $frequency) {
	$metadata = Download::metadata($token, $server);
	$highlights = new CelebrationsEmail($token, $server, $pid, $metadata);
	$to = $highlights->getEmail();
	if ($to) {
		$html = $highlights->getEmailHTML($frequency);
		if (Application::isLocalhost()) {
			echo $html;
		}
		if ($html) {
			$defaultFrom = Application::getSetting("default_from", $pid) ?: "noreply.flighttracker@vumc.org";
			$subject = "Flight Tracker Scholar Impact Update";
			\REDCap::email($to, $defaultFrom, $subject, $html);
		}
	}
}

function prefillJournals() {
	$pid = 168378;
	$redcapData = \REDCap::getData($pid, "json-array");
	$existingHandles = [];
	$maxRecord = 0;
	foreach ($redcapData as $row) {
		if ($row['handle']) {
			$existingHandles[] = $row['handle'];
		}
		if ($maxRecord < $row['record_id']) {
			$maxRecord = $row['record_id'];
		}
	}

	$filename = __DIR__."/../journals-on-twitter/twitter_accounts_of_journals.csv";
	if (file_exists($filename)) {
		$fp = fopen($filename, "r");
		$headers = [];
		$data = [];
		while ($line = fgetcsv($fp, )) {
			if (empty($headers)) {
				$headers = $line;
			} else {
				$row = [];
				foreach ($line as $i => $val) {
					$row[$headers[$i]] = $val;
				}
				$data[] = $row;
			}
		}
		fclose($fp);

		$upload = [];
		$issnsForAbbreviations = Citation::getISSNsForAbbreviations();
		foreach ($data as $row) {
			if (($row['has_twitter'] === "1") && ($row['twitter'] !== "NA")) {
				$handle = $row['twitter'];
				$journalTitle = $row['journal_title'];
				$issnPrint = $row['issn'];
				$issnOnline = $row['e_issn'];
				foreach ([$issnPrint, $issnOnline] as $issn) {
					if (($issn !== "NA") && isset($issnsForAbbreviations[$issn])) {
						if (!preg_match("/^@/", $handle)) {
							$handle = "@$handle";
						}
						foreach ($issnsForAbbreviations[$issn] as $abbv) {
							if (!in_array($handle, $existingHandles) && $journalTitle && $handle) {
								$maxRecord++;
								$upload[] = [
									"record_id" => $maxRecord,
									"name" => $journalTitle,
									"abbreviation" => $abbv,
									"handle" => $handle,
									"journal_twitter_handles_complete" => "2",
								];
								$existingHandles[] = $handle;
							}
						}
					}
				}
			}
		}
		if (!empty($upload)) {
			$params = [
				"project_id" => $pid,
				"dataFormat" => "json-array",
				"data" => $upload,
				"commitData" => true,
			];
			echo count($upload)." items<br/>";
			return \REDCap::saveData($params);
		}
	}
	return [];
}
