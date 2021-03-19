<?php

use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Grant;
use Vanderbilt\CareerDevLibrary\Cohorts;
use Vanderbilt\CareerDevLibrary\Links;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/Application.php");
require_once(dirname(__FILE__)."/CareerDev.php");
require_once(dirname(__FILE__)."/classes/Download.php");
require_once(dirname(__FILE__)."/classes/Grant.php");
require_once(dirname(__FILE__)."/classes/Cohorts.php");
require_once(dirname(__FILE__)."/classes/Links.php");
require_once(dirname(__FILE__)."/classes/REDCapManagement.php");

$coeus2Fields = ["record_id", "coeus2_agency_grant_number", "coeus2_award_status", "coeus2_submitted_to_agency"];
$cohort = $_GET['cohort'] ? $_GET['cohort'] : "";
if (isset($_GET['page'])) {
    $module = Application::getModule();
} else {
    $module = CareerDev::getPluginModule();
}
if ($cohort) {
    $records = Download::cohortRecordIds($token, $server, $module, $cohort);
} else {
    $records = Download::recordIds($token, $server);
}
$names = Download::names($token, $server);

$recordStats = [];
$pullSize = 10;
for ($i = 0; $i < count($records); $i += $pullSize) {
    $pullRecords = [];
    for ($j = $i; ($j < count($records)) && ($j < $i + $pullSize); $j++) {
        $pullRecords[] = $records[$j];
    }
    foreach ($pullRecords as $recordId) {
        $recordStats[$recordId] = [
            "Overall" => ["Attempts" => [], "Successes" => []],
            "Unique Grant" => ["Attempts" => [], "Successes" => []],
            ];
    }
    $redcapData = Download::fieldsForRecords($token, $server, $coeus2Fields, $pullRecords);
    $codedAccepts = ["000", ""];
    $priorDates = [];
    foreach ($redcapData as $row) {
        $recordId = $row['record_id'];
        if (!isset($priorDates[$recordId])) {
            $priorDates[$recordId] = [];
        }
        if ($row['redcap_repeat_instrument'] == "coeus2") {
            $awardNo = $row['coeus2_agency_grant_number'];
            $baseAwardNo = Grant::translateToBaseAwardNumber($awardNo);
            $isDenom = FALSE;
            $isNumer = FALSE;
            $submissionDate = $row['coeus2_submitted_to_agency'];
            $status = $row['coeus2_award_status'];
            if (in_array($status, ["Awarded", "Supplement Funded"])) {
                $isNumer = TRUE;
                $isDenom = TRUE;
            } else if (in_array($status, ["Unfunded", "Disapproved"])) {
                $isDenom = TRUE;
            } else if (in_array($status, ["Pending", "Award Pending", "Transfer Pending"])) {
                $timespan24Months = 24 * 30 * 24 * 3600;
                $submissionTs = strtotime($submissionDate);
                if ($submissionTs < time() - $timespan24Months) {
                    $isDenom = TRUE;
                }
            } else if (in_array($status, ["Withdrawn", "Funded/unfunded status of the proposal", ""])) {
            } else {
                echo "<div class='red'>Unknown category '$status' in Record $recordId instance ".$row['redcap_repeat_instance']."!</div>";
            }

            if (!in_array($submissionDate, $priorDates[$recordId])) {
                if ($isDenom) {
                    if (in_array($awardNo, $codedAccepts)) {
                        $recordStats[$recordId]["Overall"]["Attempts"][] = $awardNo;
                        $recordStats[$recordId]["Unique Grant"]["Attempts"][] = $awardNo;
                    } else {
                        if (!in_array($awardNo, $recordStats[$recordId]["Overall"]["Attempts"])) {
                            $recordStats[$recordId]["Overall"]["Attempts"][] = $awardNo;
                        }
                        if (!in_array($baseAwardNo, $recordStats[$recordId]["Unique Grant"]["Attempts"])) {
                            $recordStats[$recordId]["Unique Grant"]["Attempts"][] = $baseAwardNo;
                        }
                    }
                }
                if ($isNumer) {
                    if (in_array($awardNo, $codedAccepts)) {
                        $recordStats[$recordId]["Overall"]["Successes"][] = $awardNo;
                        $recordStats[$recordId]["Unique Grant"]["Successes"][] = $awardNo;
                    } else {
                        if (!in_array($awardNo, $recordStats[$recordId]["Overall"]["Successes"])) {
                            $recordStats[$recordId]["Overall"]["Successes"][] = $awardNo;
                        }
                        if (!in_array($baseAwardNo, $recordStats[$recordId]["Unique Grant"]["Successes"])) {
                            $recordStats[$recordId]["Unique Grant"]["Successes"][] = $baseAwardNo;
                        }
                    }
                }
                if ($isNumer || $isDenom) {
                    $priorDates[$recordId][] = $submissionDate;
                }
            }
        }
    }
}

$totals = [];
foreach ($recordStats as $recordId => $stats) {
    foreach ($stats as $stat => $types) {
        if (!isset($totals[$stat])) {
            $totals[$stat] = [];
        }
        foreach ($types as $type => $awards) {
            if (!isset($totals[$stat])) {
                $totals[$stat][$type] = [];
            }
            $totals[$stat][$type] += count($awards);
        }
    }
}

echo "<h1>Grant Success Rates from COEUS</h1>";

$cohorts = new Cohorts($token, $server, $module);
echo "<p class='centered'>".$cohorts->makeCohortSelect($cohort)."</p>";
echo "<h3>Definitions</h3>";
echo "<p class='centered'><b>Overall</b> - All grant applications.<br><b>Unique Grant</b> - All grant applications <i>excluding renewals</i>.</p>";

echo "<h2>For Entire Population</h2>";
echo makeStatsString($totals);

foreach ($recordStats as $recordId => $stats) {
    $link = Links::makeRecordHomeLink($pid, $recordId, "Record $recordId: ".$names[$recordId]);
    echo "<h4>$link</h4>";
    echo makeStatsString($stats);
}

function makeStatsString($stats) {
    $html = "<p class='centered'>";
    $lines = [];
    foreach ($stats as $stat => $types) {
        $counts = [];
        foreach ($types as $type => $ary) {
            if (is_array($ary)) {
                $count = count($ary);
            } else {
                $count = $ary;
            }
            $counts[$type] = $count;
            $lines[] = "$stat $type: ".REDCapManagement::pretty($count);
        }
        if ($counts["Attempts"] > 0) {
            $perc = round($counts["Successes"] * 1000 / $counts["Attempts"]) / 10;
            $lines[] = "<b>$stat Percentage: $perc%</b>";
        }
    }
    $html .= implode("<br>", $lines);
    $html .= "</p>";
    return $html;
}
