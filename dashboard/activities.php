<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Measurement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Dashboard;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
$dashboard = new Dashboard($pid);
require_once(dirname(__FILE__)."/".$dashboard->getTarget().".php");

const TOTAL_LABEL = "Total Awards &amp; Activities";
$cohort = Sanitizer::sanitizeCohort($_GET['cohort'] ?? "");

$lexicon = [
    "Award" => "Awards",
    "Honorary or Professional Society" => "Honorary or Professional Societies",
    "Abstract or Paper" => "Abstracts or Papers",
    "Oral Presentation" => "Oral Presentations",
    "Poster Presentation" => "Poster Presentations",
    "Leadership Position" => "Leadership Positions",
];

$headers = [];
$measurements = [];

$metadataFields = Download::metadataFieldsByPid($pid);
$fields = array_unique(array_merge(
    ["record_id"],
    DataDictionaryManagement::filterFieldsForPrefix($metadataFields, "activityhonor"),
    DataDictionaryManagement::filterFieldsForPrefix($metadataFields, "surveyactivityhonor")
));
$awardNameChoices = DataDictionaryManagement::getChoicesForField($pid, "activityhonor_local_name");
$committeeChoices = DataDictionaryManagement::getChoicesForField($pid, "activityhonor_committee_name");
$levelChoices = DataDictionaryManagement::getChoicesForField($pid, "activityhonor_activity_realm");
$typeChoices = DataDictionaryManagement::getChoicesForField($pid, "activityhonor_type");
$committeeTypeChoices = DataDictionaryManagement::getChoicesForField($pid, "activityhonor_committee_nature");

if ($cohort) {
    $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else {
    $records = Download::recordIdsByPid($pid);
}

$instruments = ["honors_awards_and_activities", "honors_awards_and_activities_survey"];
$counts = [TOTAL_LABEL => []];
foreach ($records as $recordId) {
    $redcapData = Download::fieldsForRecordsByPid($pid, $fields, [$recordId]);
    foreach ($redcapData as $row) {
        if (in_array($row['redcap_repeat_instrument'], $instruments)) {
            $prefix = REDCapManagement::getPrefixFromInstrument($row['redcap_repeat_instrument']);
            if ($row[$prefix."_name"]) {
                $instance = $row['redcap_repeat_instance'];
                $licensePlate = "$recordId:$instance";
                $counts[TOTAL_LABEL][] = $licensePlate;
                if (isset($typeChoices[$row[$prefix."_type"]])) {
                    if ($row[$prefix."_type"] == 6) {
                        if (isset($committeeTypeChoices[$row[$prefix . "_committee_nature"]])) {
                            $label = $committeeTypeChoices[$row[$prefix . "_committee_nature"]] . " Committee Memberships";
                        } else {
                            $label = "";
                        }
                        if ($row[$prefix . "_committee_name"]) {
                            $index = $row[$prefix . "_committee_name"];
                            $committeeName = $committeeChoices[$index] ?? "";
                            if ($committeeName) {
                                $dashboardLabel = "Different Categorized Committees";
                                if (!isset($counts[$dashboardLabel])) {
                                    $counts[$dashboardLabel] = [];
                                }
                                if (!in_array($committeeName, $counts[$dashboardLabel])) {
                                    $counts[$dashboardLabel][] = $committeeName;
                                }
                            }
                        }
                    } else if ($row[$prefix."_type"] == 1) {
                        $label = $lexicon[$typeChoices[$row[$prefix."_type"]]];
                        $index = $row[$prefix . "_local_name"];
                        $categorizedAwardName = $awardNameChoices[$index] ?? "";
                        if ($categorizedAwardName) {
                            $dashboardLabel = "Different Categorized Awards";
                            if (!isset($counts[$dashboardLabel])) {
                                $counts[$dashboardLabel] = [];
                            }
                            if (!in_array($categorizedAwardName, $counts[$dashboardLabel])) {
                                $counts[$dashboardLabel][] = $categorizedAwardName;
                            }
                        }
                    } else {
                        $label = $lexicon[$typeChoices[$row[$prefix."_type"]]] ?? $typeChoices[$row[$prefix."_type"]];
                    }
                    if ($label) {
                        if (!isset($counts[$label])) {
                            $counts[$label] = [];
                        }
                        $counts[$label][] = $licensePlate;
                    }
                }
            }
        }
    }
}
# put levels at end/bottom
foreach ($indexedRedcapData as $recordId => $rows) {
    foreach ($rows as $row) {
        if (in_array($row['redcap_repeat_instrument'], $instruments)) {
            $prefix = REDCapManagement::getPrefixFromInstrument($row['redcap_repeat_instrument']);
            if (
                $row[$prefix . "_name"]
                && isset($levelChoices[$row[$prefix."_activity_realm"]])
            ) {
                $level = $levelChoices[$row[$prefix."_activity_realm"]];
                if ($level) {
                    $instance = $row['redcap_repeat_instance'];
                    $licensePlate = "$recordId:$instance";
                    $dashboardLabel = "$level Activities / Awards";
                    if (!isset($counts[$dashboardLabel])) {
                        $counts[$dashboardLabel] = [];
                    }
                    $counts[$dashboardLabel][] = $licensePlate;
                }
            }
        }
    }
}

$headers = [];
$measurements = [];
$headers[] = "Overall Summary";
if ($cohort) {
	$headers[] = "For Cohort " . $cohort;
}

$numAwards = count($counts[TOTAL_LABEL]);
foreach ($counts as $label => $instances) {
    if ($label == TOTAL_LABEL) {
        $measurements[$label] = new Measurement(count($instances));
    } else {
        $measurements[$label] = new Measurement(count($instances), $numAwards);
    }
}

echo $dashboard->makeHTML($headers, $measurements, [], $cohort);
