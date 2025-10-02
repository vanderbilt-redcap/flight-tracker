<?php

use Vanderbilt\CareerDevLibrary\Cohorts;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Filter;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

if (isset($_POST['title'])) {
	# no CSS or JS

	$name = Sanitizer::sanitize($_POST['title']);
	$config = Sanitizer::sanitizeArray($_POST['config'], true, false);

	if (preg_match(Cohorts::PROHIBITED_CHARACTERS_REGEX, $name)) {
		echo "Invalid name. Title cannot contain single-quotes, hashtags, ampersands, or double-quotes.";
	} else {
		$cohorts = new Cohorts($token, $server, CareerDev::getModule());
		$cohorts->addCohort($name, $config);
		echo "success: Cohort $name added";
	}
} else {
	require_once(dirname(__FILE__)."/../charts/baseWeb.php");
	require_once(dirname(__FILE__)."/../wrangler/css.php");

	echo \Vanderbilt\FlightTrackerExternalModule\getCohortHeaderHTML();
	echo "<main><div id='content'>";

	$metadata = Download::metadata($token, $server);
	$cohorts = new Cohorts($token, $server, CareerDev::getModule());
	$cohortTitles = $cohorts->getCohortNames();
	echo "<h2>".count($cohortTitles)." Existing Cohorts (Click to Edit)</h2>\n";
	$cohortTitlesWithEditLinks = [];
	$link = Application::link("this");
	$handpickLink = Application::link("cohorts/pickCohort.php");
	foreach ($cohortTitles as $cohortName) {
		$cohortConfig = $cohorts->getCohort($cohortName);
		if ($cohortConfig && count($cohortConfig->getManualRecords()) === 0) {
			$url = $link."&edit=".urlencode($cohortName);
		} else {
			$url = $handpickLink."&cohort=".urlencode($cohortName);
		}
		$cohortTitlesWithEditLinks[] = "<a href='$url'>$cohortName</a>";
	}
	echo "<p class='centered'>".implode("<br>", $cohortTitlesWithEditLinks)."</p>\n";

	echo "<h1>Add a Cohort</h1>\n";
	echo \Vanderbilt\FlightTrackerExternalModule\makeHelpLink();
	$filter = new Filter($token, $server, $metadata);
	if (isset($_GET['edit']) && in_array($_GET['edit'], $cohortTitles)) {
		$editableCohort = $_GET['edit'];
		echo $filter->getHTML($editableCohort);
	} else {
		echo $filter->getHTML();
	}
	echo "</div></main>";
}
