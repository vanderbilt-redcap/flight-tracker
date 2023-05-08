<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Grants;

require_once(dirname(__FILE__)."/../autoload.php");

$ind_ks = array(3, 4);
$GLOBALS['ind_ks'] = $ind_ks;
$int_ks = array(1, 2);
$GLOBALS['int_ks'] = $int_ks;
if ($_POST['r01equivtype'] == "r01") {
    $rs = array(5);
} else {
    $rs = array(5, 6);
}
$GLOBALS['rs'] = $rs;

function getTypeOfLastK($data, $recordId) {
    $ks = array(
        1 => "Internal K",
        2 => "K12/KL2",
        3 => "Individual K",
        4 => "K Equivalent",
    );
    foreach ($data as $row) {
        if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "")) {
            for ($i = Grants::$MAX_GRANTS; $i >= 1; $i--) {
                if (in_array($row['summary_award_type_'.$i], array_keys($ks))) {
                    return $ks[$row['summary_award_type_'.$i]];
                }
            }
        }
    }
    return "";
}

function getKAwardees($data, $intKLength, $indKLength) {
    global $ind_ks, $int_ks, $rs;

    $qualifiers = array();
    $today = date("Y-m-d");

    foreach ($data as $row) {
        if ($row['redcap_repeat_instrument'] === "") {
            $person = $row['identifier_first_name']." ".$row['identifier_last_name'];
            $first_r = "";
            for ($i = 1; $i <= 15; $i++) {
                if (in_array($row['summary_award_type_'.$i], $rs)) {
                    $first_r = $row['summary_award_date_'.$i];
                    break;
                }
            }

            $first_k = "";
            if (!$first_r) {
                for ($i = 1; $i <= 15; $i++) {
                    if (in_array($row['summary_award_type_'.$i], $ind_ks)) {
                        $first_k = $row['summary_award_date_'.$i];
                        if (REDCapManagement::datediff($row['summary_award_date_'.$i], $today, "y") <= $indKLength) {
                            $qualifiers[$row['record_id']] = $person;
                        }
                        break;
                    }
                }
            }

            if (!$first_k && !$first_r) {
                $first_int_k = "";
                for ($i = 1; $i < 15; $i++) {
                    if (in_array($row['summary_award_type_'.$i], $int_ks)) {
                        $first_int_k = $row['summary_award_date_'.$i];
                        if (REDCapManagement::datediff($row['summary_award_date_'.$i], $today, "y") <= $intKLength) {
                            $qualifiers[$row['record_id']] = $person;
                        }
                        break;
                    }
                }
            }
        }
    }
    return $qualifiers;
}

function breakUpKs($kType) {
    $kPre = preg_split("//", $kType);
    $ks = [];
    foreach ($kPre as $k) {
        if ($k !== "") {
            $ks[] = $k;
        }
    }
    return $ks;
}

function isConverted($row, $kLength, $orderK, $kType, $searchIfLeft) {
    global $ind_ks, $int_ks, $rs;
    $k99r00 = 9;
    $ks = breakUpKs($kType);
    $today = date("Y-m-d");

    $k = "";
    $first_r = "";
    $last_k = "";
    for ($i = 1; $i <= 15; $i++) {
        if (in_array($row['summary_award_type_'.$i], $ks)) {
            $last_k = $row['summary_award_date_'.$i];
        }
        if (in_array($row['summary_award_type_'.$i], $ks)) {
            if (!$k) {
                $k = $row['summary_award_date_'.$i];
            } else if ($orderK == "last_k") {
                $k = $row['summary_award_date_'.$i];
            }
        } else if (!$first_r && in_array($row['summary_award_type_'.$i], $rs)) {
            $first_r = $row['summary_award_date_'.$i];
        } else if ($row['summary_award_type_'.$i] == $k99r00) {
            // omit
            return false;
        }
    }
    if (!$k) {
        # no CDA
        // echo "A ".$row['record_id']." ".$row['identifier_first_name']." ".$row['identifier_last_name']."<br>";
        return false;
    }
    if (!$first_r) {
        if ($kLength && (REDCapManagement::datediff($k, $today, "y") <= $kLength)) {
            # K < X years old
            // echo "B".REDCapManagement::datediff($k, $today, "y")." ".$k." ".$row['record_id']." ".$row['identifier_first_name']." ".$row['identifier_last_name']."<br>";
            return false;
        }
        # did not convert
        if ($kLength && ($orderK == "last_k") && (REDCapManagement::datediff($last_k, $today, "y") <= $kLength)) {
            # no R (not converted) and last K < X years old
            // echo "C ".$row['record_id']." ".$row['identifier_first_name']." ".$row['identifier_last_name']."<br>";
            return false;
        }
        if ($searchIfLeft && $row['identifier_left_date']) {
            // echo "D ".$row['record_id']." ".$row['identifier_first_name']." ".$row['identifier_last_name']."<br>";
            return false;
        }
        $hasNonVanderbiltEmail = isset($row['identifier_email']) && $row['identifier_email'] &&
            !preg_match("/vanderbilt\.edu/i", $row['identifier_email']) &&
            !preg_match("/vumc\.org/i", $row['identifier_email']);
        if (Application::isVanderbilt() && $searchIfLeft && $hasNonVanderbiltEmail) {
            # lost to follow up because has non-Vanderbilt email
            # will not implement for other domains because we don't know their setups
            # for other domains, rely on identifier_left_date
            return false;
        }
        # no R and no reason to throw out => not converted
        return "denom";
    }
    # leftovers have an R => converted
    return "numer";
}

function isRowInKRange($row, $kLength, $orderK, $kType, $kStartDate, $kEndDate, $excludeUnconvertedKsBefore, $searchIfLeft) {
    if (!$kStartDate && !$kEndDate && !$excludeUnconvertedKsBefore) {
        return TRUE;
    }
    $ks = breakUpKs($kType);
    $kDate = "";
    $c = isConverted($row, $kLength, $orderK, $kType, $searchIfLeft);
    for ($i = 0; $i < Grants::$MAX_GRANTS; $i++) {
        if (in_array($row['summary_award_type_'.$i], $ks)) {
            $rowField = "summary_award_date_".$i;
            if ($orderK == "first_k") {
                if (!$kDate && $row[$rowField]) {
                    $kDate = $row[$rowField];
                }
            } else if ($orderK == "last_k") {
                if ($row[$rowField]) {
                    $kDate = $row[$rowField];
                }
            }
        }
    }
    if ($kStartDate && !REDCapManagement::dateCompare($kDate, ">=", $kStartDate)) {
        return FALSE;
    }
    if ($kEndDate && !REDCapManagement::dateCompare($kDate, "<=", $kEndDate)) {
        return FALSE;
    }
    if (($c == "denom") && REDCapManagement::dateCompare($kDate, "<=", $excludeUnconvertedKsBefore)) {
        return FALSE;
    }
    return TRUE;
}

function getAverages($data, $kLength, $orderK, $kType, $kStartDate, $kEndDate, $excludeUnconvertedKsBefore, $searchIfLeft, $conversionFunc = "\Vanderbilt\FlightTrackerExternalModule\isConverted") {
    global $rs, $pid, $event_id;

    $avgs = array(
        "conversion" => 0,
        "age" => 0,
        "age_at_first_cda" => 0,
        "age_at_first_r" => 0,
        "converted" => [],
        "not_converted" => [],
        "omitted" => [],
    );
    $sums = array();
    foreach ($avgs as $key => $value) {
        if (!is_array($avgs[$key])) {
            $sums[$key] = array();
        }
    }

    foreach ($data as $row) {
        if (($row['redcap_repeat_instrument'] === "") && isRowInKRange($row, $kLength, $orderK, $kType, $kStartDate, $kEndDate, $excludeUnconvertedKsBefore, $searchIfLeft)) {
            $c = $conversionFunc($row, $kLength, $orderK, $kType, $searchIfLeft);
            if ($c == "numer") {
                // echo "Numer ".$row['record_id']." ".$row['identifier_first_name']." ".$row['identifier_last_name']."<br>";
                $sums["conversion"][] = 100;   // percent
                $avgs["converted"][] = Links::makeSummaryLink($pid, $row['record_id'], $event_id, $row['identifier_first_name']." ".$row['identifier_last_name']);
            } else if ($c == "denom") {
                // echo "Denom ".$row['record_id']." ".$row['identifier_first_name']." ".$row['identifier_last_name']."<br>";
                $sums["conversion"][] = 0;
                $avgs["not_converted"][] = Links::makeSummaryLink($pid, $row['record_id'], $event_id, $row['identifier_first_name']." ".$row['identifier_last_name']);
            } else {
                $avgs["omitted"][] = Links::makeSummaryLink($pid, $row['record_id'], $event_id, $row['identifier_first_name']." ".$row['identifier_last_name']);
            }
            if ($row['summary_dob']) {
                $today = date("Y-m-d");
                $sums["age"][] = REDCapManagement::datediff($row['summary_dob'], $today, "y");
                if ($row['summary_award_date_1']) {
                    $sums["age_at_first_cda"][] = REDCapManagement::datediff($row['summary_dob'], $row['summary_award_date_1'], "y");
                }
                for ($i = 1; $i <= 15; $i++) {
                    if ($row['summary_award_date_'.$i] && in_array($row['summary_award_type_'.$i], $rs)) {
                        $sums["age_at_first_r"][] = REDCapManagement::datediff($row['summary_dob'], $row['summary_award_date_'.$i], "y");
                        break;
                    }
                }
            }
        }
    }

    foreach ($sums as $key => $ary) {
        # one decimal place
        $perc = "";
        if ($key == "conversion") {
            $perc = "%";
        }
        if (count($ary) > 0) {
            $avgs[$key] = (floor(10 * array_sum($ary) / count($ary)) / 10)."$perc<br><span class='small'>(n=".count($ary).")</span>";
        } else {
            $avgs[$key] = "Incalculable<br><span class='small'>(n=".count($ary).")</span>";
        }
    }

    $avgs["num_omitted"] = count($avgs["omitted"]);
    $avgs["num_converted"] = count($avgs["converted"]);
    $avgs["num_not_converted"] = count($avgs["not_converted"]);

    return $avgs;
}
