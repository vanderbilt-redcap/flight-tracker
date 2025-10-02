<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use Vanderbilt\CareerDevLibrary\Grants;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Publications;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Links;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\DateManagement;
use Vanderbilt\CareerDevLibrary\FeatureSwitches;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

$classes = isset($_GET['awardsOnly']) ? ["All"] : ["PubsCDAs", "All"];
if (Grants::areFlagsOn($pid)) {
	$classes[] = "Flagged";
}
$submissionClasses = ["Unfunded", "Pending", "Awarded"];   // correlated with CSS below
$switches = new FeatureSwitches($token, $server, $pid);
$switchSettings = $switches->getSwitches();

$records = Download::recordIds($token, $server);
$recordId = isset($_GET['record']) ? REDCapManagement::getSanitizedRecord($_GET['record'], $records) : $records[0];
$nextRecord = $records[0];
for ($i = 0; $i < count($records); $i++) {
	if (($records[$i] == $recordId) && ($i + 1 < count($records))) {
		$nextRecord = $records[$i + 1];
		break;
	}
}
if (isset($redcapData)) {
	# this page is included via a require_once, so this variable might have already been downloaded
	$rows = $redcapData;
} else {
	$metadataFields = Download::metadataFields($token, $server);
	$validPrefixes = [];
	$grantPrefixes = [
		"coeus_",
		"coeussubmission_",
		"vera_",
		"verasubmission_",
		"summary_",
		"nih_",
		"reporter_",
		"custom_",
	];
	$pubPrefixes = [
		"citation_",
		"eric_",
	];
	if (($switchSettings["Grants"] != "Off") && !isset($_GET['noCDA'])) {
		$validPrefixes = array_merge($validPrefixes, $grantPrefixes);
	}
	if ($switchSettings["Publications"] != "Off") {
		$validPrefixes = array_merge($validPrefixes, $pubPrefixes);
	}
	$fieldsToDownload = ["record_id", "identifier_first_name", "identifier_middle", "identifier_last_name"];
	foreach ($validPrefixes as $prefix) {
		$prefixFields = DataDictionaryManagement::filterFieldsForPrefix($metadataFields, $prefix);
		$fieldsToDownload = array_unique(array_merge($fieldsToDownload, $prefixFields));
	}
	$rows = Download::fieldsForRecords($token, $server, $fieldsToDownload, [$recordId]);
}

$name = "";
foreach ($rows as $row) {
	if ($row['redcap_repeat_instrument'] == "") {
		$name = $row['identifier_first_name']." ".$row['identifier_last_name'];
		break;
	}
}

$grantsAndPubs = [];
$maxTs = [];
$minTs = [];

$grants = new Grants($token, $server, "empty");
$grants->setRows($rows);
$grants->compileGrants();
$grants->compileGrantSubmissions();
$id = 1;
list($submissions, $submissionTimestamps) = makeSubmissionDots($grants->getGrants("submissions"), $id);
if (!empty($submissions) && !isset($_GET['awardsOnly'])) {
	$classes[] = "AllWithSubmissions";
}

foreach ($classes as $c) {
	$maxTs[$c] = 0;
	$minTs[$c] = time();
	$grantsAndPubs[$c] = [];

	if ($c == "AllWithSubmissions") {
		$allTimestamps = $submissionTimestamps;
		$grantsAndPubs[$c] = $submissions;

		foreach ($allTimestamps as $submissionTs) {
			if ($submissionTs) {
				if ($maxTs[$c] < $submissionTs) {
					$maxTs[$c] = $submissionTs;
				}
				if ($minTs[$c] > $submissionTs) {
					$minTs[$c] = $submissionTs;
				}
			}
		}
	}

	$grantClass = CareerDev::getSetting("grant_class", $pid);
	$grantAry = makeTrainingDatesBar($rows, $id, $minTs[$c], $maxTs[$c], ($grantClass == "T"));
	if ($grantAry) {
		$grantsAndPubs[$c][] = $grantAry;
	}
	if ($switchSettings["Grants"] == "Off") {
		$grantType = "NONE";
	} elseif (in_array($c, ["All", "AllWithSubmissions"])) {
		$grantType = "all";
	} elseif (($c == "PubsCDAs") && !isset($_GET['noCDA'])) {
		$grantType = "prior";
	} elseif (($c == "PubsCDAs") && isset($_GET['noCDA'])) {
		$grantType = "NONE";
	} elseif ($c == "Flagged") {
		$grantType = "flagged";
	} else {
		throw new \Exception("Class is not set up $c");
	}
	if (isset($_GET['test'])) {
		echo "$c has grantType $grantType and ".$grants->getCount($grantType)." grants<br>";
	}
	if (($grantType !== "NONE") && ($grants->getCount($grantType) > 0)) {
		if (isset($_GET['test'])) {
			echo "grants->$grantType has ".$grants->getCount($grantType)." items<br>";
		}
		$grantBars = makeGrantBars($grants->getGrants($grantType), $id, $minTs[$c], $maxTs[$c]);
		$grantsAndPubs[$c] = array_merge($grantsAndPubs[$c], $grantBars);
	}

	if (($c == "PubsCDAs") && ($switchSettings["Publications"] != "Off")) {
		$pubDots = makePubDots($rows, $token, $server, $id, $minTs[$c], $maxTs[$c]);
		$grantsAndPubs[$c] = array_merge($grantsAndPubs[$c], $pubDots);
	}

	$currTs = time();
	if ($maxTs[$c] < $currTs) {
		$maxTs[$c] = $currTs;
	}

	$spacing = ($maxTs[$c] + 90 * 24 * 3600 - $minTs[$c]) / 6;
	$maxTs[$c] += $spacing;
	$minTs[$c] -= $spacing;
}

?>
    <script src="<?= Application::link("/charts/vis.min.js") ?>"></script>
    <link href="<?= Application::link("charts/vis.min.css") ?>" rel="stylesheet" type="text/css" />
    <link href='<?= Application::link("/css/career_dev.css") ?>' type='text/css' />
<?php

if (isset($_GET['next'])) {
	echo "<h1>$recordId: $name</h1>\n";
	$timelineLink = Application::link("charts/timeline.php");
	echo "<p style='text-align: center;'><a href='$timelineLink&record=$nextRecord&next'>Next Record</a></p>\n";
}

$suffix = $pid."_".time();
foreach ($classes as $c) {
	$divHeader = "";
	$divFooter = "";
	if ($c == "All") {
		$divHeader = "<div id='allGrants'>";
		$divFooter = "</div>";
		if (in_array("AllWithSubmissions", $classes)) {
			$divFooter = "<p class='centered'><button class='smaller' onclick='$(\"#allGrants\").hide(); $(\"#allGrantsWithSubmissions\").show(); return false;'>Show Grant Submissions</button></p>".$divFooter;
		}
		$vizTitle = "All Grants";
	} elseif ($c == "AllWithSubmissions") {
		$vizTitle = "All Grants (Including Submissions)";
		$divHeader = "<div id='allGrantsWithSubmissions'>";
		$divFooter = "<p class='centered'><button class='smaller' onclick='$(\"#allGrantsWithSubmissions\").hide(); $(\"#allGrants\").show(); return false;'>Show Grant Awards</button></p></div>";
	} elseif (($c == "PubsCDAs") && isset($_GET['noCDA'])) {
		$vizTitle = "Publications";
	} elseif (($c == "PubsCDAs") && !isset($_GET['noCDA'])) {
		$vizTitle = "Career Defining Awards &amp; Publications";
	} elseif ($c == "Flagged") {
		$vizTitle = "Flagged Grants Only";
	} else {
		$vizTitle = "This should never happen.";
	}

	echo $divHeader;
	echo "<h3>$vizTitle</h3>";
	if ($c == "AllWithSubmissions") {
		echo "<table class='centered max-width'><tbody><tr>";
		$cells = [];
		foreach ($submissionClasses as $submissionClass) {
			$cells[] = "<td class='$submissionClass' style='border-width: 20px; border-style: solid;'>$submissionClass</td>";
		}
		echo implode("<td>&nbsp;</td>", $cells);
		echo "</tr></tbody></table>";
	}
	echo "<div id='visualization{$suffix}_$c' class='visualization'></div>";
	echo "<div class='alignright'><button onclick='html2canvas(document.getElementById(\"visualization{$suffix}_$c\"), { onrendered: (canvas) => { downloadCanvas(canvas, \"timeline.png\"); } }); return false;' class='smallest'>Save</button></div>";
	echo $divFooter;
}

?>

<script type="text/javascript">

// for mousewheel
function runTimeoutToTurnOffEvents(el) {
    setTimeout(() => {
        el.parentNode.replaceChild(el.cloneNode(true), el);
        $("#allGrantsWithSubmissions").hide();
    }, 2000);
}

const container_<?= $suffix ?> = {};
const items_<?= $suffix ?> = {};
const options_<?= $suffix ?> = {};
const timeline_<?= $suffix ?> = {};
$(document).ready(() => {
    <?php
	foreach ($classes as $c) {
		$dataset = json_encode($grantsAndPubs[$c]);
		$startDate = json_encode(date("Y-m-d", (int) $minTs[$c]));
		$endDate = json_encode(date("Y-m-d", (int) $maxTs[$c]));

		echo "
        container_".$suffix."['$c'] = document.getElementById('visualization".$suffix."_$c');
        items_".$suffix."['$c'] = new vis.DataSet($dataset);
        options_".$suffix."['$c'] = { start: $startDate, end: $endDate };
        ";
		echo getJSToLaunchTimeline($suffix, $c)."\n";
	}
?>
});
</script>


<?php

function makeAwardDots($grantAry, &$id) {
	$grantsAndPubs = [];
	$submissionTimestamps = [];
	foreach ($grantAry as $grant) {
		addIfValid($grant, $grantsAndPubs, $submissionTimestamps, $id, "Awarded");
	}
	return [$grantsAndPubs, $submissionTimestamps];
}

function makeSubmissionDots($grantAry, &$id) {
	$grantsAndPubs = [];
	$submissionTimestamps = [];
	foreach ($grantAry as $grant) {
		$awardStatus = $grant->getVariable("status");
		addIfValid($grant, $grantsAndPubs, $submissionTimestamps, $id, $awardStatus);
	}
	return [$grantsAndPubs, $submissionTimestamps];
}

function addIfValid($grant, &$grantsAndPubs, &$submissionTimestamps, &$id, $awardStatus) {
	$skipNumbers = [];
	$validStatuses = ["Awarded", "Unfunded", "Pending"];
	$awardNo = $grant->getNumber();
	$title = $grant->getVariable("title");
	$submissionDate = $grant->getVariable("submission_date");
	if (isset($_GET['test'])) {
		echo "Looking at $awardNo with $awardStatus and $submissionDate<br/>";
	}

	if (
		DateManagement::isDate($submissionDate)
		&& !in_array($awardNo, $skipNumbers)
		&& in_array($awardStatus, $validStatuses)
		&& !preg_match("/-0[23456789]$/", $awardNo)
	) {
		if (isset($_GET['test'])) {
			echo "Adding $awardNo with $awardStatus and $submissionDate with $id<br/>";
		}
		if (DateManagement::isMDY($submissionDate)) {
			$submissionTs = strtotime(DateManagement::MDY2YMD($submissionDate));
		} elseif (DateManagement::isDMY($submissionDate)) {
			$submissionTs = strtotime(DateManagement::DMY2YMD($submissionDate));
		} else {
			$submissionTs = strtotime($submissionDate);
		}
		if (!$submissionTs) {
			return;
		}
		$submissionTimestamps[] = $submissionTs;

		$grantAry = [];
		$grantAry['id'] = $id;
		$grantAry['start'] = date("Y-m-d", $submissionTs);
		$grantAry['className'] = $awardStatus;
		$grantAry['type'] = "point";
		$grantNumber = $awardNo;
		$titleLength = 25;
		if (strlen($title) > $titleLength) {
			$truncatedTitle = substr($title, 0, $titleLength)."...";
		} else {
			$truncatedTitle = $title;
		}
		if (Application::isVanderbilt() && ($grantNumber == "000")) {
			$grantNumber = "Internal Co-I Project";
		} elseif ($grantNumber && ($grantNumber != "No Title Assigned")) {
			$grantNumber .= " Application";
		} else {
			$grantNumber = $awardStatus.": ".$truncatedTitle;
		}
		$url = $grant->getVariable("url");
		$grantAry['content'] = isset($_GET['hideHeaders']) ? $grantNumber : Links::makeLink($url, $grantNumber, true);
		$grantsAndPubs[] = $grantAry;
		$id++;
	}
}

function makeTrainingDatesBar($rows, &$id, &$minTs, &$maxTs, $isCurrentTrainee) {
	foreach ($rows as $row) {
		if ($row['redcap_repeat_instrument'] == "") {
			if ($row['summary_training_start']) {
				$startTs = strtotime($row['summary_training_start']);
				if ($row['summary_training_end']) {
					$endTs = strtotime($row['summary_training_end']);
				} else {
					if ($isCurrentTrainee) {
						$endTs = time();
					} else {
						$endTs = false;
					}
				}

				$grantAry = [
					"id" => $id,
					"content" => "(Start of Training)",
					"group" => "Grant",
				];
				$grantAry['start'] = date("Y-m-d", $startTs);

				if ($endTs) {
					$grantAry['type'] = "range";
					$grantAry['content'] = "(Training Period)";
					$grantAry['end'] = date("Y-m-d", $endTs);
				} else {
					$grantAry['type'] = "box";
				}

				if ($minTs > $startTs) {
					$minTs = $startTs;
				}
				if ($endTs && ($maxTs < $endTs)) {
					$maxTs = $endTs;
				}
				$id++;
				return $grantAry;
			}
		}
	}
	return [];
}

function makeGrantBars($grantAry, &$id, &$minTs, &$maxTs) {
	$grantsAndPubs = [];
	foreach ($grantAry as $grant) {
		$typeInfo = "";
		$grantType = $grant->getVariable("type");
		if ($grantType !== "N/A") {
			$typeInfo = " ($grantType)";
		}
		$grantAry = [
			"id" => $id,
			"content" => $grant->getBaseNumber().$typeInfo,
			"group" => "Grant",
		];
		$start = $grant->getVariable("start");
		$grantAry['start'] = $start;
		$startTs = strtotime($start);

		$endTs = 0;
		if ($end = $grant->getVariable("end")) {
			$grantAry['type'] = "range";
			$grantAry['end'] = $end;
			$endTs = strtotime($end);
		} else {
			$grantAry['type'] = "box";
		}

		if ($endTs) {
			if ($maxTs < $endTs) {
				$maxTs = $endTs;
			}
		} else {
			if ($maxTs < $startTs) {
				$maxTs = $startTs;
			}
		}
		if ($minTs > $startTs) {
			$minTs = $startTs;
		}

		$grantsAndPubs[] = $grantAry;
		$id++;
	}
	return $grantsAndPubs;
}

function makePubDots($rows, $token, $server, &$id, &$minTs, &$maxTs) {
	$pubs = new Publications($token, $server);
	$pubs->setRows($rows);
	$citations = $pubs->getCitations("Included");

	$grantsAndPubs = [];

	foreach ($citations as $citation) {
		$ts = $citation->getTimestamp();
		if ($citation->getVariable("data_source") == "citation") {
			$pmid = $citation->getPMID();
			$journal = $citation->getVariable("journal");
			$link = Links::makeLink($citation->getURL(), $journal ?: ($pmid ? "PMID ".$pmid : "Pub"));
		} elseif ($citation->getVariable("data_source") == "eric") {
			$ericID = $citation->getERICID();
			if ($ericID) {
				$link = Links::makeLink($citation->getURL(), $ericID);
			} else {
				$link = "Pub";
			}
		} else {
			$link = "";
		}
		if ($ts && $link) {
			$pubAry = [
				"id" => $id,
				"content" => $link,
				"start" => date("Y-m-d", $ts),
				"group" => "Publications",
				"type" => "point",
			];
			if ($ts > $maxTs) {
				$maxTs = $ts;
			}
			if ($ts < $minTs) {
				$minTs = $ts;
			}

			$grantsAndPubs[] = $pubAry;
		}

		$id++;
	}
	return $grantsAndPubs;
}

function getJSToLaunchTimeline($suffix, $c) {
	$lines = [];
	$lines[] = "timeline_".$suffix."[\"$c\"] = new vis.Timeline(container_".$suffix."[\"$c\"], items_".$suffix."[\"$c\"], options_".$suffix."[\"$c\"]);";
	$lines[] = "$(timeline_".$suffix."[\"$c\"]).unbind(\"mousewheel\");";
	$lines[] = "runTimeoutToTurnOffEvents(container_".$suffix."[\"$c\"]);";
	return implode("", $lines);
}
