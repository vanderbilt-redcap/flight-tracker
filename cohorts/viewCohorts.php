<?php

use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Links;
use Vanderbilt\CareerDevLibrary\Cohorts;
use Vanderbilt\CareerDevLibrary\CohortConfig;
use Vanderbilt\CareerDevLibrary\Filter;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../wrangler/css.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$metadata = Download::metadata($token, $server);
$cohorts = new Cohorts($token, $server, CareerDev::getModule());
$numCols = 4;

echo \Vanderbilt\FlightTrackerExternalModule\getCohortHeaderHTML();
echo "<div id='content'>\n";

echo "<h1>Existing Cohorts</h1>\n";

$names = Download::names($token, $server);
$cohortNames = $cohorts->getCohortNames();

$redcapData = [];
if (empty($cohortNames)) {
	echo "<h4>No cohorts have been created.</h4>\n";
} else {
	$allFields = $cohorts->getAllFields();
	$redcapData = Download::getIndexedRedcapData($token, $server, $allFields);
}


foreach ($cohortNames as $title) {
	$config = $cohorts->getCohort($title);
	echo "<br><br>\n";
	echo "<h2>Cohort: $title</h2>\n";
	echo $config->getHTML($metadata);

	$filter = new Filter($token, $server, $metadata);
	$records = $filter->getRecords($config, $redcapData);

	$nameLinks = [];
	foreach ($records as $recordId) {
		$name = Links::makeRecordHomeLink($pid, $recordId, $names[$recordId]);
		array_push($nameLinks, $name);
	}

	$columnSize = ceil(count($nameLinks) / $numCols);
	$cols = [];
	for ($i = 0; $i < $numCols; $i++) {
		$cols[$i] = [];
	}
	$i = 0;
	$numInCol = ceil(count($nameLinks) / count($cols));
	foreach ($nameLinks as $nameLink) {
		array_push($cols[(int) floor($i / $numInCol)], $nameLink);
		$i++;
	}

	$htmlTitle = \Vanderbilt\FlightTrackerExternalModule\makeHTMLId("table_".$title);
	$size = count($nameLinks);
	$link = CareerDev::link("cohorts/exportCohort.php");
	echo "<h3>Size of Cohort $title: $size Scholars</h3>\n";
	echo "<table style='margin-left: auto; margin-right: auto;'><tr class='centeredRow'>\n";
	echo "<td><button class='biggerButton' onclick='$(\"#$htmlTitle\").show();'>Show Names</button></td>\n";
	echo "<td><button class='biggerButton' onclick='window.location.href=\"$link&cohort=$title\";'>Export Names</button></td>\n";
	echo "</tr></table>\n";
	echo "<table style='margin-left: auto; margin-right: auto; display: none;' id='$htmlTitle'>\n";
	echo "<tr>\n";
	foreach ($cols as $i => $nameLinks) {
		echo "<td>".implode("<br>", $nameLinks)."</td>\n";
	}

	echo "</tr>\n";
	echo "</table>\n";
}
if (count($cohortNames) == 0) {
	echo "<p class='centered'>No cohorts are available.</p>\n";
}
echo "</div>\n";
