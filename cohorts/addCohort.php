<?php

use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Filter;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

if (isset($_POST['title'])) {
	# no CSS or JS

	$name = REDCapManagement::sanitize($_POST['title']);
	$config = REDCapManagement::sanitize($_POST['config']);

	if (preg_match("/['\"#&]/", $name)) {
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
    echo "<main><div id='content'>\n";

    $metadata = Download::metadata($token, $server);
    $cohorts = new Cohorts($token, $server, CareerDev::getModule());
    $cohortTitles = $cohorts->getCohortNames();
    echo "<h2>".count($cohortTitles)." Existing Cohorts (Click to Edit)</h2>\n";
    $cohortTitlesWithEditLinks = [];
    $link = Application::link("this");
    foreach ($cohortTitles as $cohortName) {
        $cohortConfig = $cohorts->getCohort($cohortName);
        if (count($cohortConfig->getManualRecords()) === 0) {
            $url = $link."&edit=".urlencode($cohortName);
            $cohortTitlesWithEditLinks[] = "<a href='$url'>$cohortName</a>";
        } else {
            $cohortTitlesWithEditLinks[] = $cohortName;
        }
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
