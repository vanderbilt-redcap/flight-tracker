<?php

define("NOAUTH", true);

use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Links.php");

$url = CareerDev::link("wrangler/pubs.php");
if (isset($_GET['headers']) && ($_GET['headers'] == "false")) {
	$url .= "&headers=false";
}

if ($_GET['record']) {
	$record = $_GET['record'];
} else {
	$record = getNextRecordWithData($token, $server, 0);
	if ($record) {
		header("Location: $url&record=".$record);
	}
}

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/baseSelect.php");

if (!$record) {
	echo "<h1>No Data Available</h1>\n";
	exit();
}

$redcapData = Download::records($token, $server, array($record));
$nextRecord = getNextRecordWithData($token, $server, $record);

$pubs = new Publications($token, $server);
$pubs->setRows($redcapData);

if (count($_POST) >= 1) {
	$pubs->saveEditText($_POST);
	if (isset($_GET['headers']) && ($_GET['headers'] == "false")) {
		header("Location: $url&record=".$record);
	} else {
		header("Location: $url&record=".$nextRecord);
	}
} else if ($record != 0) {
	echo "<input type='hidden' id='nextRecord' value='".$nextRecord."'>\n";

	if (!isset($_GET['headers']) || ($_GET['headers'] != "false")) {
		echo "<div class='subnav'>\n";
		echo Links::makeDataWranglingLink($pid, "Grant Wrangler", $record, FALSE, "green")."\n";
		echo Links::makeProfileLink($pid, "Scholar Profile", $record, FALSE, "green")."\n";
		echo "<a class='yellow'>".Publications::getSelectRecord()."</a>\n";
		echo "<a class='yellow'>".Publications::getSearch()."</a>\n";

		$nextPageLink = "$url&record=".$nextRecord;
		# next record is in the same window => don't use Links class
		echo "<a class='blue' href='$nextPageLink'>View Next Record With New Data</a>\n";
		echo Links::makeLink("https://www.ncbi.nlm.nih.gov/pubmed/advanced", "Access PubMed", TRUE, "purple")."\n";

		echo "</div>\n";
		echo "<div id='content'>\n";
		echo \Vanderbilt\FlightTrackerExternalModule\makeHelpLink();
	}

	if (isset($_GET['mssg'])) {
		echo "<div class='green shadow centered note'>".$_GET['mssg']."</div>";
	}
	echo "<p class='green shadow' id='note' style='width: 600px; margin-left: auto; margin-right: auto; text-align: center; padding: 10px; border-radius: 10px; display: none; font-size: 16px;'></p>\n";

	$html = $pubs->getEditText();
	echo $html;
	if (!isset($_GET['headers']) || ($_GET['headers'] != "false")) {
		echo "</div>\n";      // #content
	}
} else {
	# record == 0
	echo "<h1>No more new citations!</h1>\n";
}

function getNextRecordWithData($token, $server, $currRecord) {
	$records = Download::records($token, $server);
	$pos = 0;
	for ($i = 0; $i < count($records); $i++) {
		if ($currRecord == $records[$i]) {
			$pos = $i+1;
			break;
		}
	}

	if ($pos == count($records)) {
		return $records[0];
	}

	$pullSize = 3;
	while ($pos < count($records)) {
		$pullRecords = array();
		for ($i = $pos; ($i < count($records)) && ($i < $pos + $pullSize); $i++) {
			array_push($pullRecords, $records[$i]);
		}
		$redcapData = Download::fieldsForRecords($token, $server, array("record_id", "citation_pmid", "citation_include"), $pullRecords);
		foreach ($redcapData as $row) {
			if (($row['record_id'] > $currRecord) && $row['citation_pmid'] && ($row['citation_include'] === "")) {
				return $row['record_id'];
			}
		}
		$pos += $pullSize;
	}
	if (count($records) >= 1) {
		return $records[0];
	}
	return "";
}
