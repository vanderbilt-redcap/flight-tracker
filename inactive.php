<?php

use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Publications;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Sanitizer;
use Vanderbilt\CareerDevLibrary\Links;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\Grants;
use Vanderbilt\CareerDevLibrary\URLManagement;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

$numMonths = Sanitizer::sanitizeInteger($_GET['numMonths'] ?? 36);
$thresholdTs = strtotime("-$numMonths months");

$thisUrl = Application::link("this");
$thresholdLongDate = isset($_GET['numMonths']) ? " After ".date("F j, Y", $thresholdTs) : " After a Certain Date";
echo "<h1>Scholars Inactive$thresholdLongDate</h1>";
echo "<p class='centered max-width'>If a scholar is inactive for a period of time, you may want to check that she/he has not changed institutions. Of course, inactivity could be due to a number of other causes, like retirement or a change in job focus. Inactivity for both grants and publications is considered, but publication data must be wrangled in order to yield accurate results. Scholars without any confirmed publications are excluded from this list. Please fill out a Position Change form in REDCap if you find out they have made a career change.</p>";
echo "<form action='$thisUrl' method='GET'>";
echo URLManagement::getParametersAsHiddenInputs($thisUrl);
echo "<p class='centered'><label for='numMonths'>Number of Months:</label> <input type='number' name='numMonths' id='numMonths' value='$numMonths' /></p>";
echo "<p class='centered'><button>Make Report</button></p>";
echo "</form>";

if (!isset($_GET['numMonths'])) {
	exit;
}

$metadata = Download::metadata($token, $server);
$records = Download::recordIds($token, $server);
$names = Download::names($token, $server);
$citationFields = array_unique(array_merge(Application::getCitationFields($metadata), ["record_id"]));
$inactivePublicationRecords = [];
foreach ($records as $recordId) {
	$redcapData = Download::fieldsForRecords($token, $server, $citationFields, [$recordId]);
	$pubs = new Publications($token, $server, $metadata);
	$pubs->setRows($redcapData);
	$citationsAfterThreshold = $pubs->getSortedCitationsInTimespan($thresholdTs);
	$sortedCitations = $pubs->getSortedCitationsInTimespan(0);
	$lastCitationTs = (count($sortedCitations) > 0) ? $sortedCitations[count($sortedCitations) - 1]->getTimestamp() : false;
	if ((count($citationsAfterThreshold) === 0) && (count($sortedCitations) > 0) && $lastCitationTs) {
		$inactivePublicationRecords[$recordId] = $lastCitationTs;
	}
}

$inactiveOnBoth = [];
$cdaGrantFields = ["record_id"];
$fieldsForDatesInOrder = [
	"end",
	"start",
];
for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
	foreach ($fieldsForDatesInOrder as $field) {
		$cdaGrantFields[] = "summary_award_".$field."_".$i;
	}
}
$widerGrantFields = array_unique(array_merge(["record_id"], REDCapManagement::getMinimalGrantFields($metadata)));

foreach ($inactivePublicationRecords as $recordId => $lastActivePublicationTs) {
	$lastActiveGrantTs = 0;
	$driver = [
		"prior" => $cdaGrantFields,
		"all" => $widerGrantFields,
	];
	foreach ($driver as $grantType => $fields) {
		$redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
		$grants = new Grants($token, $server, $metadata);
		$grants->setRows($redcapData);
		foreach ($grants->getGrants($grantType) as $grant) {
			foreach ($fieldsForDatesInOrder as $field) {
				$date = $grant->getVariable($field);
				if ($date) {
					$ts = strtotime($date);
					if ($ts && ($ts > $lastActiveGrantTs)) {
						$lastActiveGrantTs = $ts;
					}
				}
			}
		}
		if ($lastActiveGrantTs >= $thresholdTs) {
			break;
		}
	}



	if ($lastActiveGrantTs < $thresholdTs) {
		$inactiveOnBoth[$recordId] = max([$lastActiveGrantTs, $lastActivePublicationTs]);
	}
}

$list = [];
foreach ($inactiveOnBoth as $recordId => $tsOfLastActivity) {
	$dateOfLastActivity = date("m-d-Y", $tsOfLastActivity);
	$name = $names[$recordId] ?? "";
	$list[$tsOfLastActivity] = Links::makeRecordHomeLink($pid, $recordId, "Record $recordId: $name")." - last active on $dateOfLastActivity";
}
krsort($list);
if (empty($list)) {
	echo "<p class='centered max-width'>No one has stopped activity. Either they never started, or they have been active within the last $numMonths months.</p>";
} else {
	echo "<p class='centered'>".implode("<br/>", array_values($list))."</p>";
}
