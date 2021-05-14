<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Links.php");

$metadata = Download::metadata($token, $server);

$fields = CareerDev::$summaryFields;
foreach ($metadata as $row) {
	if (preg_match("/mentor/", $row['field_name'])) {
		$fields[] = $row['field_name'];
	}
}

$redcapData = \Vanderbilt\FlightTrackerExternalModule\alphabetizeREDCapData(Download::fields($token, $server, $fields));

?>
<style>
.small { font-size: 13px; }
.halfMargin { margin: .5em 0; }
</style>

<?php

echo "<h1>Current Scholars and Their Mentors</h1>\n";
$cnt = 1;
$revAwardTypes = \Vanderbilt\FlightTrackerExternalModule\getReverseAwardTypes();
$numScholars = 0;
foreach ($redcapData as $row) {
	if ($i = \Vanderbilt\FlightTrackerExternalModule\findEligibleAward($row)) {
		$numScholars++;
	}
}
echo "<h2>$numScholars Scholars</h2>\n";
echo "<h3>Only for Current Members; Graduated Members are Omitted</h3>\n";
$sources = array(
		"/^newman_/" => "Newman Data",
		"/^vfrs_/" => "VFRS",
		"/^check_/" => "Initial Survey",
		"/^followup_/" => "Follow-Up Survey",
		"/^summary_/" => "Summary",
		"/^spreadsheet_/" => "Manual Spreadsheet",
		);
$skipRegex = array(
			"/_vunet$/",
			"/_source$/",
			"/_sourcetype$/",
			); 
echo "<table style='margin-left: auto; margin-right: auto;'>\n";
echo "<tr class='even'><th>Record</th><th>Scholar</th><th>Reported Mentor(s)</th><th>Qualifying Award</th></tr>\n";
foreach ($redcapData as $row) {
	if ($i = \Vanderbilt\FlightTrackerExternalModule\findEligibleAward($row)) {
		if ($cnt % 2 == 1) {
			$rowClass = "odd";
		} else {
			$rowClass = "even";
		}
		$cnt++;
		echo "<tr class='$rowClass'>\n";
		echo "<td class='centered'>".Links::makeRecordHomeLink($pid, $row['record_id'], $row['record_id'])."</td>\n";
		echo "<td class='centered'>{$row['identifier_first_name']} {$row['identifier_last_name']}</td>";

		$mentors = array();

		foreach ($row as $field => $value) {
			if (preg_match("/mentor/", $field) && ($value != '')) {
				$source = "";
				foreach ($sources as $regex => $sourceName) {
					if (preg_match($regex, $field)) {
						$source = $sourceName;
						break;
					}
				}
				foreach ($skipRegex as $regex) {
					if (preg_match($regex, $field)) {
						$source = "";
						break;
					}
				}
				if ($source) {
					if (!isset($mentors[$value])) {
						$mentors[$value] = array();
					}
					if (!in_array($source, $mentors[$value])) {
						array_push($mentors[$value], $source);
					}
				} else {
					array_push($mentors[$value], $field);
				}
			}
		}
		echo "<td class='centered'>\n";
		foreach ($mentors as $name => $dataSources) {
			if (!empty($dataSources)) {
				echo "<p class='halfMargin'>$name<br><span class='small centered'>".implode("<br>", $dataSources)."</span></p>\n";
			}
		}
		echo "</td>\n";
		echo "<td class='small centered'>{$row['summary_award_sponsorno_'.$i]}<br>{$revAwardTypes[$row['summary_award_type_'.$i]]}<br>{$row['summary_award_date_'.$i]}</td>\n";
		echo "</tr>\n";
	}
}
echo "</table>\n";

