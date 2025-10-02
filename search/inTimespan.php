<?php

use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Links;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;
use Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$hasType = false;
foreach ($_POST as $variable => $value) {
	if (preg_match("/^type___/", (string) $variable)) {
		$hasType = true;
		break;
	}
}

$metadata = Download::metadata($token, $server);
$choicesStrs = [];
foreach ($metadata as $row) {
	if (preg_match("/summary_award_type_/", $row['field_name'])) {
		$choicesStrs["award_type"] = $row['select_choices_or_calculations'];
	}
	if (preg_match("/summary_award_source_/", $row['field_name'])) {
		$choicesStrs["award_source"] = $row['select_choices_or_calculations'];
	}
}
$choices = [];
foreach ($choicesStrs as $type => $choicesStr) {
	$choicePairs = preg_split("/\s*\|\s*/", $choicesStr);
	$choices[$type] = [];
	foreach ($choicePairs as $pair) {
		$a = preg_split("/\s*,\s*/", $pair);
		if (count($a) == 2) {
			$choices[$type][$a[0]] = $a[1];
		} elseif (count($a) > 2) {
			$a = preg_split("/,/", $pair);
			$b = [];
			for ($i = 1; $i < count($a); $i++) {
				$b[] = $a[$i];
			}
			$choices[$type][trim($a[0])] = implode(",", $b);
		}
	}
}
?>
<?php

if ($hasType && isset($_POST['begin']) && ($_POST['begin'] != "")) {
	$begin = strtotime(REDCapManagement::sanitize($_POST['begin']));
	if (isset($_POST['end']) && ($_POST['end'] != "")) {
		$end = strtotime(REDCapManagement::sanitize($_POST['end']));
	} else {
		$end = time();
	}

	$types = [];
	foreach ($_POST as $variable => $value) {
		if (preg_match("/^type___/", (string) $variable)) {
			$types[] = REDCapManagement::sanitize($value);
		}
	}

	$redcapData = Download::fields($token, $server, CareerDev::$summaryFields);
	echo "<h1>Timespan Search Results</h1>";
	echo "<h2>".REDCapManagement::sanitize($_POST['begin'])." - ";
	if (isset($_POST['end']) && $_POST['end']) {
		echo REDCapManagement::sanitize($_POST['end']);
	} else {
		echo "now";
	}
	echo "</h2>";
	$hasResults = false;
	$j = 0;
	$names = [];
	foreach ($redcapData as $row) {
		for ($i = 1; $i <= 15; $i++) {
			if ($row['summary_award_date_'.$i] && in_array($row['summary_award_type_'.$i], $types)) {
				$myStart = strtotime($row['summary_award_date_'.$i]);
				$myEnd = strtotime($row['summary_award_end_date_'.$i]);
				if (
					($myStart >= $begin) && ($myStart <= $end)
					|| ($myEnd >= $begin) && ($myEnd < $end)
					|| ($myStart < $begin) && ($myEnd > $end)
				) {
					$names[$row['identifier_last_name']] =  ["i" => $i, "row" => $row];
				}
			}
		}
	}

	ksort($names);
	echo "<h3>".count($names)." Results</h3>";
	echo "<table class='centered'>";
	foreach ($names as $lastName => $ary) {
		$i = $ary["i"];
		$row = $ary["row"];
		$rowClass = 'odd';
		if ($j % 2 === 0) {
			$rowClass = 'even';
		}

		echo "<tr class='$rowClass'>";
		echo "<td>".Links::makeSummaryLink($pid, $row['record_id'], $event_id, $row['identifier_first_name']." ".$row['identifier_last_name'])."</td>";
		echo "<td>".$row['summary_award_sponsorno_'.$i]."</td>";
		echo "<td style='padding-right: 10px;'>Start: ".$row['summary_award_date_'.$i]."</td>";
		if ($row['summary_award_end_date_'.$i]) {
			echo "<td style='padding-right: 10px;'>End: ".$row['summary_award_end_date_'.$i]."</td>";
		} else {
			echo "<td>&nbsp;</td>";
		}
		echo "<td>".$choices['award_type'][$row['summary_award_type_'.$i]]."</td>";
		echo "<td>".$choices['award_source'][$row['summary_award_source_'.$i]]."</td>";
		"</tr>";
		$hasResults = true;
		$j++;
	}
	if (!$hasResults) {
		echo "<tr><td>No results</td></tr>";
	}
	echo "</table>";

} else {
	echo "<h1>Search Career-Defining Grants Within Timespan</h1>";
	$link = CareerDev::link("search/inTimespan.php");
	echo "<form method='POST' action='$link'>";
	echo Application::generateCSRFTokenHTML();

	echo "<table class='centered'><tr><td style='vertical-align: top; padding-right: 16px;'>";
	$choiceHTML = [];
	foreach ($choices["award_type"] as $value => $label) {
		$id = "type___$value";
		$choiceHTML[] = "<input type='checkbox' name='$id' id='$id' value='$value'> <label for='$id'>$label</label>";
	}
	echo "<h3>Award Types</h3>";
	echo "<p style='text-align: left;'>".implode("<br>", $choiceHTML)."</p>";

	echo "</td><td style='vertical-align: top;'>";
	echo "<h3>Timespan</h3>";
	echo "<h4>Please specify a beginning time!</h4>\n";
	echo "<p class='centered'>Begin: <input type='date' name='begin'></p>";
	echo "<p class='centered'>End: <input type='date' name='end'><br>Leave blank for today</p>";

	echo "<p class='centered'><input type='submit'></p>";
	echo "</td></tr></table>";
	echo "</form>";
}
