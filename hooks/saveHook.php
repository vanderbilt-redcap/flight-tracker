<?php

use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\CitationCollection;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

global $token, $server;

if ($instrument == "identifiers") {
	$sql = "SELECT field_name FROM redcap_data WHERE project_id = ".db_real_escape_string($project_id)." AND record = '".db_real_escape_string($record)."' AND field_name LIKE '%_complete'";
	$q = db_query($sql);
	if (db_num_rows($q) == 1) {
		if ($row = db_fetch_assoc($q)) {
			if ($row['field_name'] == "identifiers_complete") {
				# new record => only identifiers form filled out
				\Vanderbilt\FlightTrackerExternalModule\queueUpInitialEmail($record);
			}
		}
	}
}
Application::refreshRecordSummary($token, $server, $project_id, $record);
