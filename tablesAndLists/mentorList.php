<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$metadata = Download::metadata($token, $server);

$fields = CareerDev::$summaryFields;
foreach ($metadata as $row) {
	if (preg_match("/mentor/", $row['field_name'])) {
		$fields[] = $row['field_name'];
	}
}
$fields[] = "summary_training_start";
$fields[] = "summary_training_end";

$names = Download::names($token, $server);

?>
<style>
.small { font-size: 13px; }
.halfMargin { margin: .5em 0; }
</style>

<?php

echo "<h1>Current Scholars and Their Mentors</h1>\n";
$revAwardTypes = \Vanderbilt\FlightTrackerExternalModule\getReverseAwardTypes();
echo "<p class='centered'>Only for Currently Active Scholars (on a Training Grant)</p>";
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
$cnt = 1;
foreach ($names as $recordId => $name) {
    $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
    foreach ($redcapData as $row) {
        if (($i = \Vanderbilt\FlightTrackerExternalModule\findEligibleAward($row)) || currentlyInTraining($row)) {
            if ($cnt % 2 == 1) {
                $rowClass = "odd";
            } else {
                $rowClass = "even";
            }
            $cnt++;
            echo "<tr class='$rowClass'>\n";
            echo "<td class='centered'>".Links::makeRecordHomeLink($pid, $recordId, "Record ".$recordId)."</td>\n";
            echo "<td class='centered'>$name</td>";

            $scholar = new Scholar($token, $server, $metadata, $pid);
            $scholar->setRows($redcapData);
            $mentors = $scholar->getAllMentors();
            echo "<td class='centered'>".implode("<br>", $mentors)."</td>";
            if ($i) {
                echo "<td class='small centered'>K ($i): {$row['summary_award_sponsorno_'.$i]}<br>{$revAwardTypes[$row['summary_award_type_'.$i]]}<br>{$row['summary_award_date_'.$i]}</td>\n";
            } else {
                echo "<td class='small centered'>In Training</td>";
            }
            echo "</tr>\n";
        }
    }
}
echo "</table>\n";

function currentlyInTraining($row) {
    if ($row['summary_training_start']) {
        if (!$row['summary_training_end']) {
            return TRUE;
        }

        $startTs = strtotime($row['summary_training_start']);
        $endTs = strtotime($row['summary_training_end']);
        $currTs = time();

        return (($currTs >= $startTs) && ($currTs <= $endTs));
    }
    return FALSE;
}