<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Filter;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Cohorts.php");
require_once(dirname(__FILE__)."/../classes/Filter.php");
require_once(dirname(__FILE__)."/../CareerDev.php");

$cohort = $_GET['cohort'];
$cohorts = new Cohorts($token, $server, CareerDev::getModule());
if ($cohort) {
	if ($cohorts->isIn($cohort)) {
		$metadata = Download::metadata($token, $server);
		$names = Download::names($token, $server);
		$records = Download::cohortRecordIds($token, $server, $metadata, $cohort);
		header('Content-Type: text/plain; charset=utf-8');
		header('Content-Disposition: attachment; filename="'.$cohort.'.txt"');
		foreach ($records as $recordId) {
			$name = $names[$recordId];
			echo $name."\n";
		}
	} else {
		require_once(dirname(__FILE__)."/../wrangler/css.php");
		require_once(dirname(__FILE__)."/../charts/baseWeb.php");
		echo "<p class='red centered'>Improper cohort</p>\n";
	}
} else {
	require_once(dirname(__FILE__)."/../wrangler/css.php");
	require_once(dirname(__FILE__)."/../charts/baseWeb.php");

	echo \Vanderbilt\FlightTrackerExternalModule\getCohortHeaderHTML();

	$cohortTitles = $cohorts->getCohortNames();
	echo "<div id='content'>\n";
	echo "<h1>Export a Cohort's List of Names</h1>\n";

	echo "<p class='centered'><select onchange='if ($(this).val()) { window.location.href=\"".CareerDev::link("cohorts/exportCohort.php")."&cohort=\"+$(this).val(); }'>\n";
	echo "<option value=''>---SELECT---</option>\n";
	foreach ($cohortTitles as $title) {
		echo "<option value='$title'>$title</option>\n";
	}
	echo "</select></p>\n";
	echo "</div>\n";
}
