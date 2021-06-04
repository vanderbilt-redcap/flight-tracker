<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\CohortConfig;
use \Vanderbilt\CareerDevLibrary\Filter;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../wrangler/css.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$requestedTitle = $_GET['title'];
$mssg = "";
if ($_GET['mssg']) {
	$mssg = \Vanderbilt\FlightTrackerExternalModule\makeSafe($_GET['mssg']);
}

echo \Vanderbilt\FlightTrackerExternalModule\getCohortHeaderHTML();
echo "<div id='content'>\n";

if ($mssg) {
	echo "<div class='green centered'>$mssg</div>\n";
}

if ($requestedTitle) {
	$metadata = Download::metadata($token, $server);
	$cohorts = new Cohorts($token, $server, CareerDev::getModule());
	$numCols = 4;

	$names = Download::names($token, $server);

	$cohortNames = $cohorts->getCohortNames();
	if (in_array($requestedTitle, $cohortNames)) {
		$config = $cohorts->getCohort($requestedTitle); 
		echo "<h1>Cohort $requestedTitle</h1>\n";
		echo $config->getHTML($metadata);

		$filter = new Filter($token, $server, $metadata);
		$records = $filter->getRecords($config);

		$nameLinks = array();
		foreach ($records as $recordId) {
			$name = Links::makeRecordHomeLink($pid, $recordId, $names[$recordId]);
			array_push($nameLinks, $name);
		}

		$columnSize = ceil(count($nameLinks) / $numCols);
		$cols = array();
		for($i=0; $i < $numCols; $i++) {
			$cols[$i] = array();
		}
		$i = 0;
		$numInCol = ceil(count($nameLinks) / count($cols));
		foreach ($nameLinks as $nameLink) {
			array_push($cols[floor($i / $numInCol)], $nameLink);
			$i++;
		}

		$size = count($nameLinks);
		echo "<h2>Size of Cohort $requestedTitle: $size Scholars</h2>\n";
		echo "<table style='margin-left: auto; margin-right: auto;'>\n";
		echo "<tr>\n";
		foreach ($cols as $i => $nameLinks) {
			echo "<td>".implode("<br>", $nameLinks)."</td>\n";
		}

		echo "</tr>\n";
		echo "</table>\n";
	} else {
		echo "<p class='centered'>The requested title was not found.</p>\n";
	}
} else {
	echo "<p class='centered'>No request for a title was made.</p>\n";
}
echo "</div>\n";
