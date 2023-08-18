<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\DateManagement;

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
$customFields = Application::getCustomFields($metadata);

$names = Download::names($token, $server);

echo "<h1>Current Scholars &amp; Their Mentors</h1>";
$revAwardTypes = \Vanderbilt\FlightTrackerExternalModule\getReverseAwardTypes();
echo "<p class='centered'>Only for Currently Active Scholars (on a Training Grant)</p>";
echo "<table style='margin-left: auto; margin-right: auto;'>";
echo "<tr class='even'><th>Record</th><th>Scholar</th><th>Reported Mentor(s)</th><th>Qualifying Award</th></tr>";
$cnt = 1;
foreach ($names as $recordId => $name) {
    $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
    $trainingData = Download::trainingGrants($token, $server, $customFields, [5, 6, 7], [$recordId], $metadata);
    foreach ($redcapData as $row) {
        if (($i = \Vanderbilt\FlightTrackerExternalModule\findEligibleAward($row)) || currentlyInTraining($row) || hasActiveTrainingGrant($trainingData)) {
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
                $awardDate = $row['summary_award_date_' . $i] ? DateManagement::YMD2MDY($row['summary_award_date_' . $i]) : "";
                $awardInfo = "K ($i): {$row['summary_award_sponsorno_'.$i]}<br/>{$revAwardTypes[$row['summary_award_type_'.$i]]}<br/>$awardDate";
            } else if ($trainingRow = hasActiveTrainingGrant($trainingData)) {
                $trainingDate = $trainingRow['custom_start'] ? DateManagement::YMD2MDY($trainingRow['custom_start']) : "";
                $awardInfo = $trainingRow["custom_number"] . "<br/>" . $revAwardTypes[$trainingRow["custom_type"]] . "<br/>" . $trainingDate;
            } else {
                $awardInfo = "In Training";
            }
            echo "<td class='smaller centered'>$awardInfo</td>";
            echo "</tr>";
        }
    }
}
echo "</table>";

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

function hasActiveTrainingGrant($trainingData) {
    if (empty($trainingData)) {
        return FALSE;
    }
    foreach ($trainingData as $row) {
        if ($row['custom_start']) {
            if (!$row['custom_end']) {
                return $row;
            }

            $startTs = strtotime($row['custom_start']);
            $endTs = strtotime($row['custom_end']);
            $currTs = time();

            if (($currTs >= $startTs) && ($currTs <= $endTs)) {
                return $row;
            }

        }
    }
    return FALSE;
}