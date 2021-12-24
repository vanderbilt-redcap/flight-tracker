<?php

use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Grant;
use Vanderbilt\CareerDevLibrary\Cohorts;
use Vanderbilt\CareerDevLibrary\Links;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\RePORTER;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

$metadata = Download::metadata($token, $server);
$metadataForms = REDCapManagement::getFormsFromMetadata($metadata);
if (in_array("coeus_submission", $metadataForms)) {
    $attemptInstrument = "coeus_submission";
    $coeusFields = ["record_id", 'coeus_sponsor_award_number', 'coeus_award_create_date', 'coeus_pi_flag', 'coeussubmission_sponsor_proposal_number', 'coeussubmission_proposal_create_date', 'coeussubmission_proposal_status', 'coeussubmission_pi_flag'];
} else {
    $attemptInstrument = "coeus2";
    $coeusFields = ["record_id", "coeus2_agency_grant_number", "coeus2_award_status", "coeus2_submitted_to_agency", "coeus2_role"];
}

$cohort = $_GET['cohort'];
$thresholdTs = $_GET['date'] ? strtotime($_GET['date']) : 0;
if (isset($_GET['page'])) {
    $module = Application::getModule();
} else {
    $module = CareerDev::getPluginModule();
}
if ($cohort) {
    $records = Download::cohortRecordIds($token, $server, $module, $cohort);
} else if ($_GET['record']) {
    $allRecords = Download::records($token, $server);
    $recordId = REDCapManagement::getSanitizedRecord($_GET['record'], $allRecords);
    $records = $recordId ? [$recordId] : [];
} else {
    $records = Download::recordIds($token, $server);
}
$names = Download::names($token, $server);

$recordStats = [];
$pullSize = 10;
for ($i = 0; $i < count($records); $i += $pullSize) {
    $pullRecords = [];
    for ($j = $i; ($j < count($records)) && ($j < $i + $pullSize); $j++) {
        if (isset($records[$j])) {
            $pullRecords[] = $records[$j];
        }
    }
    foreach ($pullRecords as $recordId) {
        $recordStats[$recordId] = [
            "Overall" => ["Attempts" => 0, "Successes" => 0],
            "Counting Retries" => ["Attempts" => 0, "Successes" => 0],
            ];
        if (isset($_GET['test'])) {
            $recordStats[$recordId]["Test"] = ["Attempts" => [], "Successes" => []];
        }
    }
    $redcapData = Download::fieldsForRecords($token, $server, $coeusFields, $pullRecords);
    $codedAccepts = ["000", ""];
    $awardedStatuses = ["Awarded", "Supplement Funded"];
    $priorDates = [];
    $awardNumbers = [];
    $seen = [];
    // sortRowsByFields($redcapData, ['record_id', 'coeus2_submitted_to_agency']);
    foreach ($redcapData as $row) {
        $recordId = $row['record_id'];
        if (!isset($priorDates[$recordId])) {
            $priorDates[$recordId] = [];
        }
        if (!isset($seen[$recordId])) {
            $seen[$recordId] = [];
        }
        if (!isset($awardNumbers[$recordId])) {
            $awardNumbers[$recordId] = [];
        }
        if (($attemptInstrument == "coeus2") && ($row['redcap_repeat_instrument'] == "coeus2")) {
            if (in_array($row['coeus2_award_status'], $awardedStatuses)) {
                $awardNumbers[$recordId][] = $row['coeus2_agency_grant_number'];
            }
        } else if (($attemptInstrument == "coeus_submission") && ($row['redcap_repeat_instrument'] == "coeus")) {
            $awardNumbers[$recordId][] = $row['coeus_sponsor_award_number'];
        }
    }

    foreach ($redcapData as $row) {
        $recordId = $row['record_id'];
        $awardNo = FALSE;
        $submissionDate = FALSE;
        $isPI = FALSE;
        $role = "";
        $status = "";
        $source = "";
        if (($row['redcap_repeat_instrument'] == $attemptInstrument) && ($attemptInstrument == "coeus2")) {
            $awardNo = $row['coeus2_agency_grant_number'];
            $submissionDate = $row['coeus2_submitted_to_agency'];
            $status = $row['coeus2_award_status'];
            $role = $row['coeus2_role'];
            $isPI = in_array($role, ['3']);
        } else if (($attemptInstrument == "coeus_submission") && in_array($row['redcap_repeat_instrument'], ["coeus", "coeus_submission"])) {
            if ($row['redcap_repeat_instrument'] == "coeus") {
                $awardNo = $row['coeus_sponsor_award_number'];
                $submissionDate = $row['coeus_award_create_date'];
                $status = "Awarded";
                $isPI = in_array($row['coeus_pi_flag'], ['1', 'Y']);
                if ($isPI) {
                    $role = 3;
                } else {
                    $role = 2;
                }
            } else if ($row['redcap_repeat_instrument'] == "coeus_submission") {
                $awardNo = $row['coeussubmission_sponsor_proposal_number'];
                $submissionDate = $row['coeussubmission_proposal_create_date'];
                $status = $row['coeussubmission_proposal_status'];
                $isPI = ($row['coeussubmission_pi_flag'] == "1");
                if ($isPI) {
                    $role = 3;
                } else {
                    $role = 2;
                }
            }
        }
        if ($awardNo !== FALSE) {
            $baseAwardNo = Grant::translateToBaseAwardNumber($awardNo);
            $isDenomOverall = 0;
            $isDenomRetries = 0;
            $isNumer = 0;
            $submissionTs = $submissionDate ? strtotime($submissionDate) : FALSE;
            $seenAlready = in_array($baseAwardNo, $seen[$recordId]);
            if ((!$submissionTs || ($submissionTs > $thresholdTs)) && !isInternalAward($awardNo) && $isPI && !isTrainingGrant($awardNo, $role)) {
                $applicationType = Grant::getApplicationType($awardNo);
                $awardYear = Grant::getSupportYear($awardNo);
                $otherSuffixes = Grant::getOtherSuffixes($awardNo);
                if (in_array($status, $awardedStatuses)) {
                    if ($awardYear == "01") {
                        if (!$seenAlready) {
                            $seen[$recordId][] = $baseAwardNo;
                            list($isDenomOverall, $isDenomRetries) = getDenoms($baseAwardNo, $awardYear, $otherSuffixes, $awardNumbers);
                            if ($isDenomOverall >= 1) {
                                $isNumer = 1;
                            }
                        }
                    } else if ($applicationType == 2) {
                        $isNumer = 1;
                        list($isDenomOverall, $isDenomRetries) = getDenoms($baseAwardNo, $awardYear, $otherSuffixes, $awardNumbers[$recordId]);
                    } else if (in_array($awardNo, $codedAccepts)) {
                        $isDenomRetries = 1;
                        $isDenomOverall = 1;
                        $isNumer = 1;
                    } else if (!hasFirstYearGrant($baseAwardNo, $awardNo, $awardNumbers[$recordId]) && !$seenAlready) {
                        # check RePORTER
                        foreach (["NIH", "Federal"] as $reporterHookup) {
                            $reporter = new RePORTER($pid, $recordId, $reporterHookup);
                            $allFundedAwardNumbers = $reporter->getAssociatedAwardNumbers($baseAwardNo);
                            $has01 = FALSE;
                            $has01A1 = FALSE;
                            foreach ($allFundedAwardNumbers as $currAwardNo) {
                                $currAwardYear = Grant::getSupportYear($currAwardNo);
                                $currAwardSuffix = Grant::getOtherSuffixes($currAwardNo);
                                if ($currAwardYear == "01") {
                                    if (preg_match("/Amendment Number \d/", $currAwardSuffix)) {
                                        $numAmendments = (int) str_replace("Amendment Number ", "", $currAwardSuffix);
                                        $awardNumbers[$recordId][] = $currAwardNo;
                                        $isDenomRetries = 1 + $numAmendments;
                                        $isDenomOverall = 1;
                                        $isNumer = 1;
                                        $source = $reporterHookup;
                                        break;
                                    } else if ($currAwardSuffix == "") {
                                        $awardNumbers[$recordId][] = $currAwardNo;
                                        $isDenomRetries = 1;
                                        $isDenomOverall = 1;
                                        $isNumer = 1;
                                        $source = $reporterHookup;
                                        break;
                                    }
                                }
                            }
                            if ($isDenomRetries + $isDenomOverall + $isNumer > 0) {
                                break;
                            }
                        }
                        $seen[$recordId][] = $baseAwardNo;
                        $recordLink = Links::makeRecordHomeLink($pid, $recordId, "Record $recordId ({$names[$recordId]})");
                        if ($isDenomOverall + $isDenomRetries + $isNumer == 0) {
                            $note = "";
                            if (!empty($allFundedAwardNumbers)) {
                                $note = "<br>Reported grant numbers: ".implode(", ", $allFundedAwardNumbers);
                            }
                            echo "<div class='red'>$recordLink may have taken over grant $baseAwardNo without an initial application or this is a non-Federal grant!$note</div>";
                        } else {
                            echo "<div class='yellow'>$recordLink required accessing the $source RePORTER for $baseAwardNo!</div>";
                        }
                    }
                } else if (in_array($status, ["Unfunded", "Disapproved"])) {
                    if (in_array($awardNo, $codedAccepts)) {
                        $isDenomRetries = 1;
                        $isDenomOverall = 1;
                    } else if (($awardYear == "01") || ($applicationType == 2)) {
                        list($isDenomOverall, $isDenomRetries) = getDenoms($baseAwardNo, $awardYear, $otherSuffixes, $awardNumbers[$recordId]);
                    }
                } else if (in_array($status, ["Pending", "Award Pending", "Transfer Pending"])) {
                    $timespan24Months = 24 * 30 * 24 * 3600;
                    if ($submissionTs) {
                        if ($submissionTs < time() - $timespan24Months) {
                            list($isDenomOverall, $isDenomRetries) = getDenoms($baseAwardNo, $awardYear, $otherSuffixes, $awardNumbers[$recordId]);
                        }
                    }
                } else if (in_array($status, ["Withdrawn", "Funded/unfunded status of the proposal", ""])) {
                } else {
                    echo "<div class='red'>Unknown category '$status' in Record $recordId instance ".$row['redcap_repeat_instance']."!</div>";
                }
            }

            if (!in_array($submissionDate, $priorDates[$recordId])) {
                if ($isDenomOverall + $isDenomRetries > 0) {
                    $recordStats[$recordId]["Overall"]["Attempts"] += $isDenomOverall;
                    $recordStats[$recordId]["Counting Retries"]["Attempts"] += $isDenomRetries;
                    if (isset($_GET['test'])) {
                        $recordStats[$recordId]["Test"]["Attempts"][] = $baseAwardNo;
                    }
                }
                if ($isNumer > 0) {
                    $recordStats[$recordId]["Overall"]["Successes"] += $isNumer;
                    $recordStats[$recordId]["Counting Retries"]["Successes"] += $isNumer;
                    if (isset($_GET['test'])) {
                        $recordStats[$recordId]["Test"]["Successes"][] = $baseAwardNo;
                    }
                }
                if ($isNumer + $isDenomOverall + $isDenomRetries > 0) {
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
                $totals[$stat][$type] = 0;
            }
            if (is_array($awards)) {
                $totals[$stat][$type] += count($awards);
            } else {
                $totals[$stat][$type] += $awards;
            }
        }
    }
}

echo "<h1>Grant Success Rates from COEUS</h1>";

$url = Application::link("successRate.php");
$link = REDCapManagement::splitURL($url)[0];
$params = REDCapManagement::getParameters($url);
$defaultDate = isset($_GET['date']) ? REDCapManagement::sanitize($_GET['date']) : "";
$skip = ["cohort", "date"];
echo "<form method='GET' action='$link'>";
foreach ($params as $key => $value) {
    $value = urlencode($value);
    if (!in_array($key, $skip)) {
        echo "<input type='hidden' name='$key' value='$value'>";
    }
}
echo "<p class='centered'>Include grants only after <input type='date' name='date' value='$defaultDate'></p>";
$cohorts = new Cohorts($token, $server, $module);
echo "<p class='centered'>".$cohorts->makeCohortSelect($cohort)."</p>";
echo "<p class='centered'><button>Go!</button></p>";
echo "</form>";
echo "<h3>Definitions</h3>";
echo "<p class='centered'><b>Overall</b> - initial application + retry = 1 attempts.<br><b>Counting Retries</b> - initial application + retry = 2 attempts.</p>";

echo "<h2>For Entire Population</h2>";
echo makeStatsString($totals);

foreach ($records as $recordId) {
    $stats = $recordStats[$recordId];
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
            $lines[] = "$stat $type: " . REDCapManagement::pretty($count);
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

function isInternalAward($awardNo) {
    return preg_match("/VUMC/i", $awardNo);
}

# Does not work - need to fix
function sortRowsByFields(&$rows, $fields) {
    foreach (array_reverse($fields) as $field) {
        $rowsByIndex = [];
        $sortFunction = "";
        foreach ($rows as $row) {
            if (strtotime($row[$field])) {
                if ($row[$field]) {
                    $sortFunction = "krsort";
                }
                $value = strtotime($row[$field]);
            } else {
                if ($row[$field]) {
                    $sortFunction = "ksort";
                }
                $value = $row[$field];
            }
            if (!isset($rowsByIndex[$value])) {
                $rowsByIndex[$value] = $row;
            }
        }
        if (function_exists($sortFunction)) {
            $sortFunction($rowsByIndex);
        }
        $rows = array_values($rowsByIndex);
    }
}

function isTrainingGrant($awardNo, $role) {
    $activityCode = Grant::getActivityCode($awardNo);
    $trainingGrantCodes = ["T32", "K12"];
    # 3 is PI ==> not PI of a training grant
    if (in_array($activityCode, $trainingGrantCodes) && ($role != 3)) {
        return TRUE;
    }
    return FALSE;
}

function getDenoms($baseAwardNo, $awardYear, $otherSuffixes, $recordAwardNumbers) {
    if (preg_match("/Amendment Number \d/", $otherSuffixes)) {
        $numAmendments = (int) str_replace("Amendment Number ", "", $otherSuffixes);
        return [1, 1 + $numAmendments];
    } else {
        foreach ($recordAwardNumbers as $currAwardNo) {
            $currBaseAwardNo = Grant::translateToBaseAwardNumber($currAwardNo);
            if ($currBaseAwardNo == $baseAwardNo) {
                $currAwardYear = Grant::getSupportYear($currAwardNo);
                $currSuffixes = Grant::getOtherSuffixes($currAwardNo);
                if (($awardYear == $currAwardYear) && ($otherSuffixes == "") && preg_match("/Amendment Number \d/", $currSuffixes)) {
                    # awarded later & counted later
                    return [0, 0];
                }
            }
        }
    }
    return [1, 1];
}

function hasFirstYearGrant($baseAwardNo, $awardNo, $recordAwardNumbers) {
    foreach ($recordAwardNumbers as $currAwardNo) {
        $currBaseAwardNo = Grant::translateToBaseAwardNumber($currAwardNo);
        if ($currBaseAwardNo == $baseAwardNo) {
            $currAwardYear = Grant::getSupportYear($currAwardNo);
            if ($currAwardYear == "01") {
                return TRUE;
            }
        } else if ($awardNo && !in_array($awardNo, $recordAwardNumbers)) {
            return TRUE;
        }
    }
    return FALSE;
}
