<?php

use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Filter;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Cohorts.php");
require_once(dirname(__FILE__)."/../classes/Filter.php");

if (isset($_POST['title'])) {
	# no CSS or JS

	$name = $_POST['title'];
	$config = $_POST['config'];

	if (preg_match("/['\"#&]/", $name)) {
		echo "Invalid name. Title cannot contain single-quotes, hashtags, ampersands, or double-quotes.";
	} else {
		$cohorts = new Cohorts($token, $server, CareerDev::getModule());
		$feedback = $cohorts->addCohort($name, $config);
		echo "success: Cohort $name added ".json_encode($feedback); 
	}
} else {
	require_once(dirname(__FILE__)."/../charts/baseWeb.php");
	require_once(dirname(__FILE__)."/../wrangler/css.php");

	echo \Vanderbilt\FlightTrackerExternalModule\getCohortHeaderHTML();
	echo "<div id='content'>\n";

	$metadata = Download::metadata($token, $server);
	$cohorts = new Cohorts($token, $server, CareerDev::getModule());
	$cohortTitles = $cohorts->getCohortNames();
	echo "<h2>".count($cohortTitles)." Existing Cohorts</h2>\n";
	echo "<p class='centered'>".implode("<br>", $cohortTitles)."</p>\n";

	echo "<h1>Add a Cohort</h1>\n";
	echo \Vanderbilt\FlightTrackerExternalModule\makeHelpLink();
	$filter = new Filter($token, $server, $metadata);
	echo $filter->getHTML();
	echo "</div>\n";
}
