<?php

# must be run on server with access to its database

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../../../redcap_connect.php");

define("NOAUTH", true);

function sendInitialSurveys($token, $server, $pid) {
	error_log("sendInitialSurveys with ".$token." ".$server." ".$pid);

	$records = Download::recordIds($token, $server);
	$firstNames = Download::firstnames($token, $server);
	$lastNames = Download::lastnames($token, $server);
	$emails = Download::emails($token, $server);

	$oneWeekAgoTs = date("Ymd000000", time() - 24 * 3600 * 7);
	$twoWeeksAgoTs = date("Ymd000000", time() - 24 * 3600 * 14);
	$threeWeeksAgoTs = date("Ymd000000", time() - 24 * 3600 * 21);
	foreach ($records as $recordId) {
		$firstName = $firstNames[$recordId];
		$lastName = $lastNames[$recordId];
		$email = $emails[$recordId];
		$mssg = "Dear Dr. $firstName $lastName,<br><br>We ask that all Newman Society members take a yearly survey to help us collect baseline information and keep track of your successes, such as grants, papers, and promotions.  The survey is pre-filled with as much information as we can gather from other sources.  We would be very grateful if you would pull out your CV and take 10-15 minutes to verify information and fill in any blanks.<br><br>Use the link below within the next three weeks. Many thanks for helping us capture key data about your success.<br><br>";

		error_log("Query 1 for $recordId");
		$sql = "SELECT ts FROM redcap_log_event WHERE pk = '".db_real_escape_string($recordId)."' AND project_id = '$pid' AND ts < $oneWeekAgoTs AND ts >= $twoWeeksAgoTs AND description LIKE 'Create record%' ORDER BY ts DESC LIMIT 1";
		$q = db_query($sql); 
		$error = db_error();
		if ($error) {
			throw new \Exception("Bad SQL ".$error." ".$sql);
		} else if (db_num_rows($q) > 0) {
			# new record => send email
			sendEmail($pid, $recordId, $firstName, $lastName, $email, $mssg);
		}
	
		error_log("Query 2 for $recordId");
		$sql = "SELECT ts FROM redcap_log_event WHERE pk = '".db_real_escape_string($recordId)."' AND project_id = '$pid' AND ts < $twoWeeksAgoTs AND ts >= $threeWeeksAgoTs AND description LIKE 'Create record%' ORDER BY ts DESC LIMIT 1";
		$q = db_query($sql); 
		$error = db_error();
		if ($error) {
			throw new \Exception("Bad SQL ".$error." ".$sql);
		} else if (db_num_rows($q) > 0) {
			# created 1-2 weeks ago => send follow-up email if survey not filled out
			$redcapData = Download::fieldsForRecords($token, $server, array("record_id", "initial_survey_complete"), array($recordId));
			foreach ($redcapData as $row) {
				if (($row['record_id'] == $recordId) && ($row['initial_survey_complete'] != '2')) {
					# not complete => send email
					sendEmail($pid, $recordId, $firstName, $lastName, $email, $mssg);
					break;     // inner
				}
			}
		}
	}
	CareerDev::saveCurrentDate("Last Survey Blast Run");
}

function sendEmail($pid, $recordId, $firstName, $lastName, $email, $mssg) {
	$instrument = "initial_survey";
	$to = $email;
	$from = "katherine.hartmann@vumc.org";
	$link = \REDCap::getSurveyLink($recordId, $instrument, '', 1, $pid);

	$mssg .= $link."<br><br>Thanks - KH<br><br><b>Katherine E. Hartmann, MD, PhD</b><br>Associate Dean, Clinical and Translational Scientist Development<br>Deputy Director, Institute for Medicine & Public Health<br>Director, Graduate Studies in Epidemiology<br>Professor, Obstetrics & Gynecology and Medicine";

	// $to = "scott.j.pearson@vumc.org,datacore@vumc.org,rebecca.helton@vumc.org";
	$subj = "10-15 minutes to help us keep up with your successes";

	error_log("Sending email to $recordId");
	\REDCap::email($to, $from, $subj, $mssg);
}
