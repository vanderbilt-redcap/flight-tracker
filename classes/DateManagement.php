<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class DateManagement {
    const SEP_REGEX = "[\/\-]";

    public static function getEarliestDate($dates) {
        if (count($dates) == 0) {
            return "";
        } else if (count($dates) == 1) {
            return $dates[0];
        }
        $earliestDate = $dates[0];
        for ($i = 1; $i < count($dates); $i++) {
            $date = $dates[$i];
            if (DateManagement::dateCompare($date, "<", $earliestDate)) {
                $earliestDate = $date;
            }
        }
        return $earliestDate;
    }
    public static function getFederalFiscalYear($ts = FALSE) {
        if (!$ts) {
            $ts = time();
        }
        $month = (int) date("m", $ts);
        $year = (int) date("Y", $ts);
        if ($month >= 10) {
            $year++;
        }
        return $year;
    }

    public static function getDateFragment($str) {
        if (preg_match("/\d+".self::SEP_REGEX."\d+".self::SEP_REGEX."\d+/", $str, $matches)) {
            return $matches[0];
        } else if (preg_match("/\d+".self::SEP_REGEX."\d+/", $str, $matches)) {
            # MM/YYYY
            return $matches[0];
        } else if (preg_match("/\d{4}/", $str, $matches)) {
            return $matches[0];
        }
        return FALSE;
    }

    public static function isNumericalMonth($month) {
        if (is_numeric($month)) {
            if (($month >= 1) && ($month <= 12)) {
                return TRUE;
            }
        }
        return FALSE;
    }

    public static function getWeekNumInYear($ts = FALSE) {
        if (!$ts) {
            $ts = time();
        }
        $dt = new \DateTime();
        $dt->setTimestamp($ts);
        $dayOfYear = $dt->format("z");
        return floor(((int) $dayOfYear - 1) / 7) + 1;
    }

    public static function getWeekNumInMonth($ts = FALSE) {
        if (!$ts) {
            $ts = time();
        }
        $dt = new \DateTime();
        $dt->setTimestamp($ts);
        $dayOfMonth = $dt->format("j");
        return floor(((int) $dayOfMonth - 1) / 7) + 1;
    }

    public static function isYear($d) {
        return preg_match("/^\d{4}$/", $d) || preg_match("/^\d{2}$/", $d);
    }

    public static function getReporterDateInYMD($dt) {
        if (!$dt) {
            return "";
        }
        $nodes = preg_split("/T/", $dt);
        if (count($nodes) != 2) {
            return $nodes[0];
        }
        return $nodes[0];
    }

    public static function dateCompare($d1, $op, $d2) {
        $ts1 = strtotime($d1);
        $ts2 = strtotime($d2);
        if ($op == ">") {
            return ($ts1 > $ts2);
        } else if ($op == ">=") {
            return ($ts1 >= $ts2);
        } else if ($op == "<=") {
            return ($ts1 <= $ts2);
        } else if ($op == "<") {
            return ($ts1 < $ts2);
        } else if ($op == "==") {
            return ($ts1 == $ts2);
        } else if (($op == "!=") || ($op == "<>")) {
            return ($ts1 != $ts2);
        } else {
            throw new \Exception("Invalid operation ($op)!");
        }
    }

    public static function isMY($str) {
        if (!$str) {
            return FALSE;
        }
        $nodes = preg_split("/".self::SEP_REGEX."/", $str);
        if (count($nodes) == 2) {
            $month = $nodes[0];
            $year = $nodes[1];
            if (($month >= 1) && ($month <= 12)) {
                if (is_numeric($year)) {
                    return TRUE;
                }
            }
        }
        return FALSE;
    }

    public static function YM2MY($ym, $sep = "-") {
        if (!$ym) {
            return "";
        }
        $nodes = preg_split("/".self::SEP_REGEX."/", $ym);
        if (count($nodes) == 2) {
            if ($nodes[0] >= 1900) {
                return $nodes[1] . $sep . $nodes[0];
            } else if ($nodes[1] >= 1900) {
                # mistake
                return $nodes[0] . $sep . $nodes[1];
            }
        }
        throw new \Exception("Invalid YYYY-MM date $ym!");
    }

    public static function MY2YM($my, $sep = "-") {
        if (!$my) {
            return "";
        }
        $nodes = preg_split("/".self::SEP_REGEX."/", $my);
        if (count($nodes) == 2) {
            if ($nodes[1] >= 1900) {
                return $nodes[1] . $sep . $nodes[0];
            } else if ($nodes[0] >= 1900) {
                # mistake
                return $nodes[0] . $sep . $nodes[1];
            }
        }
        throw new \Exception("Invalid MM-YYYY date $my!");
    }

    public static function MY2YMD($my) {
        if (!$my) {
            return "";
        }
        $sep = "-";
        $nodes = preg_split("/".self::SEP_REGEX."/", $my);
        if (count($nodes) == 2) {
            return $nodes[1] . $sep . $nodes[0] . $sep . "01";
        } else if (count($nodes) == 3) {
            $mdy = $my;
            return self::MDY2YMD($mdy);
        } else if (count($nodes) == 1) {
            $year = $nodes[0];
            if ($year > 1900) {
                return $year.$sep."01".$sep."01";
            } else {
                throw new \Exception("Invalid year: $year");
            }
        } else {
            throw new \Exception("Cannot convert MM/YYYY $my");
        }
    }

    public static function getYear($date) {
        try {
            $dt = new \DateTime($date);
            return $dt->format("Y");
        } catch(\Throwable $e) {

        }
        return "";
    }

    public static function getDayDuration($date1, $date2) {
        $span = self::getSecondDuration($date1, $date2);
        $oneDay = 24 * 3600;
        return $span / $oneDay;
    }

    public static function getSecondDuration($date1, $date2) {
        try {
            $dt1 = new \DateTime($date1);
            $dt2 = new \DateTime($date2);
            return abs($dt1->getTimestamp() - $dt2->getTimestamp());
        } catch (\Throwable $e) {
            throw new \Exception("Could not get timestamps from $date1 and $date2");
        }
    }

    public static function getYearDuration($date1, $date2) {
        $span = self::getSecondDuration($date1, $date2);
        $oneYear = 365 * 24 * 3600;
        return $span / $oneYear;
    }

    public static function REDCapTsToPHPTs($redcapTs) {
        $year = (int) substr($redcapTs, 0, 4);
        $month = (int) substr($redcapTs, 4, 2);
        $day = (int) substr($redcapTs, 6, 2);
        $dt = new \DateTime();
        $dt->setDate($year, $month, $day);
        return $dt->getTimestamp();
    }

    public static function PHPTsToREDCapTs($phpTs) {
        $dt = new \DateTime();
        $dt->setTimestamp($phpTs);
        return $dt->format("YmdHis");
    }

    public static function getLatestDate($dates) {
        $latestTs = 0;
        $latestDate = "";
        foreach ($dates as $date) {
            $dt = new \DateTime($date);
            $ts = $dt->getTimestamp();
            if ($ts > $latestTs) {
                $latestDate = $date;
                $latestTs = $ts;
            }
        }
        if ($latestTs && $latestDate) {
            return $latestDate;
        } else {
            return "";
        }
    }

    public static function isDMY($str) {
        if (preg_match("/^\d+".self::SEP_REGEX."\d+".self::SEP_REGEX."\d+$/", $str)) {
            $nodes = preg_split("/".self::SEP_REGEX."/", $str);
            $earliestYear = 1900;
            if (count($nodes) == 3) {
                if (($nodes[0] <= 31) && ($nodes[1] <= 12) && (($nodes[2] >= $earliestYear) || ($nodes[2] < 100))) {
                    # DMY
                    return TRUE;
                }
            }
        }
        return FALSE;
    }

    public static function isMDY($str) {
        if (preg_match("/^\d+".self::SEP_REGEX."\d+".self::SEP_REGEX."\d+$/", $str)) {
            $nodes = preg_split("/".self::SEP_REGEX."/", $str);
            $earliestYear = 1900;
            if (count($nodes) == 3) {
                if (
                    ($nodes[0] <= 12)
                    && ($nodes[1] <= 31)
                    && (
                        ($nodes[2] >= $earliestYear)
                        || ($nodes[2] < 100)
                    )
                ) {
                    # MDY
                    return TRUE;
                }
            }
        }
        return FALSE;
    }

    public static function isYMD($str) {
        if (preg_match("/^\d+".self::SEP_REGEX."\d+".self::SEP_REGEX."\d+$/", $str)) {
            $nodes = preg_split("/".self::SEP_REGEX."/", $str);
            $earliestYear = 1900;
            if (count($nodes) == 3) {
                if (
                    (
                        ($nodes[0] >= $earliestYear)
                        || (
                            ($nodes[0] < 100)
                            && ($nodes[0] > 12)    // not MDY-ambiguous
                        )
                    )
                    && ($nodes[1] <= 12)
                    && ($nodes[2] <= 31)
                ) {
                    # YMD
                    return TRUE;
                }
            }
        }
        return FALSE;
    }

    public static function isTimestamp($str) {
        if (is_string($str)) {
            return preg_match("/^\d\d\d\d-\d\d-\d\dT\d\d:\d\d:\d\d/", $str);
        }
        return FALSE;
    }

    public static function isDatetime($str) {
        if (is_string($str)) {
            return preg_match("/^\d\d\d\d-\d\d-\d\d\s\d\d:\d\d:\d\d/", $str);
        }
        return FALSE;
    }

    public static function getDateFromTimestamp($str) {
        if (preg_match("/^\d\d\d\d-\d\d-\d\d/", $str, $matches)) {
            return $matches[0];
        }
        return "";
    }

    public static function isDate($str) {
        if (is_string($str)) {
            return self::isYMD($str) || self::isDMY($str) || self::isMDY($str);
        }
        return FALSE;
    }

    public static function hasTime($str) {
        return preg_match("/\d\d:\d\d/", $str);
    }

    public static function toYMD($date) {
        if (self::isDate($date)) {
            try {
                $dt = new \DateTime($date);
                return $dt->format("Y-m-d");
            } catch (\Throwable $e) {
                return $date;
            }
        }
        return $date;
    }

    public static function isOracleDate($d) {
        return preg_match("/^\d\d".self::SEP_REGEX."[A-Z]{3}".self::SEP_REGEX."\d\d$/", $d);
    }

    public static function oracleDate2YMD($d) {
        if ($d === "") {
            return "";
        }
        $nodes = preg_split("/".self::SEP_REGEX."/", $d);
        if (is_numeric($nodes[0]) && is_numeric($nodes[2])) {
            $day = $nodes[0];
            $month = $nodes[1];
            $year = $nodes[2];
            if ($year < 40) {
                $year += 2000;
            } else if ($year < 100) {
                $year += 1900;
            }
            if (($day < 10) && (strlen($day) <= 1)) {
                $day = "0".$day;
            }
            $month = self::getMonthNumber($month);
            return $year."-".$month."-".$day;
        } else {
            throw new \Exception("Invalid date $d");
        }
    }

    public static function getMonthNumber($monthStr) {
        if ($monthStr === "") {
            return $monthStr;
        }
        if (is_numeric($monthStr)) {
            return $monthStr;
        }
        $monthStr = strtoupper($monthStr);
        $months = [
            "JAN" => "01",
            "FEB" => "02",
            "MAR" => "03",
            "APR" => "04",
            "MAY" => "05",
            "JUN" => "06",
            "JUL" => "07",
            "AUG" => "08",
            "SEP" => "09",
            "OCT" => "10",
            "NOV" => "11",
            "DEC" => "12",
        ];
        for ($i = 1; $i <= 12; $i++) {
            $month = REDCapManagement::padInteger($i, 2);
            $ts = strtotime("2020-$month-01");
            $months[strtoupper(date("F", $ts))] = $month;
        }

        if (isset($months[$monthStr])) {
            return $months[$monthStr];
        }
        $date = date_parse($monthStr);
        if (!empty($date['errors'])) {
            throw new \Exception(implode("<br/>\n", $date['errors']));
        }
        if ($date['month']) {
            return REDCapManagement::padInteger($date['month'], 2);
        }
        throw new \Exception("Invalid month $monthStr");
    }

    public static function YMD2MDY($ymd) {
        try {
            $dt = new \DateTime($ymd);
            return $dt->format("m-d-Y");
        } catch (\Throwable $e) {
            return "";
        }
    }

    public static function MDY2YMD($mdy) {
        return self::genericDateToYMD($mdy, "mdy");
    }

    private static function adjustSmallYear($year) {
        if ($year > 50) {
            $year += 1900;
        } else {
            $year += 2000;
        }
        return $year;
    }

    public static function genericDateToYMD($date, $format) {
        $nodes = preg_split("/".self::SEP_REGEX."/", $date);
        if ((count($nodes) == 3) && REDCapManagement::isArrayNumeric($nodes)) {
            if (in_array($format, ["DMY", "dmy"])) {
                $day = (int) $nodes[0];
                $month = (int) $nodes[1];
                $year = (int) $nodes[2];
            } else if (in_array($format, ["MDY", "mdy"])) {
                $month = (int) $nodes[0];
                $day = (int) $nodes[1];
                $year = (int) $nodes[2];
            } else if (in_array($format, ["YMD", "ymd"])) {
                $year = (int) $nodes[0];
                $month = (int) $nodes[1];
                $day = (int) $nodes[2];
            } else {
                throw new \Exception("Invalid date format $format");
            }
            if ($year < 100) {
                $year = self::adjustSmallYear($year);
            }
            $dt = new \DateTime();
            $dt->setDate($year, $month, $day);
            return $dt->format("Y-m-d");
        }
        return "";
    }

    public static function convertExcelDate($d) {
        if (preg_match("/^(\w{3})".self::SEP_REGEX."(\d+)$/", $d, $matches)) {
            try {
                $monthNum = self::getMonthNumber($matches[1]);
                if (is_numeric($monthNum) && is_numeric($matches[2])) {
                    $year = (int) $matches[2];
                    if ($year < 100) {
                        $year += 2000;
                    }
                    return "$monthNum-$year";
                }
            } catch (\Exception $e) {
                return FALSE;
            }
        } else if (preg_match("/^(\d+)".self::SEP_REGEX."(\w{3})$/", $d, $matches)) {
            try {
                $monthNum = self::getMonthNumber($matches[2]);
                if (is_numeric($monthNum) && is_numeric($matches[1])) {
                    $year = (int) $matches[1];
                    if ($year < 100) {
                        $year += 2000;
                    }
                    return "$monthNum-$year";
                }
            } catch (\Exception $e) {
                return FALSE;
            }
        }
        return FALSE;
    }

    public static function DMY2YMD($dmy) {
        return self::genericDateToYMD($dmy, "dmy");
    }

    public static function addMonths($date, $months) {
        try {
            $dt = new \DateTime($date);
            if ($months > 0) {
                $dt->modify("+$months months");
            } else if ($months < 0) {
                # minus sign included in statement
                $dt->modify("$months months");
            }
            return $dt->format("Y-m-d");
        } catch(\Throwable $e) {
            return "";
        }
    }

    public static function addYears($date, $years) {
        try {
            $dt = new \DateTime($date);
            if ($years > 0) {
                $dt->modify("+$years years");
            } else if ($years < 0) {
                # minus sign included in statement
                $dt->modify("$years years");
            }
            return $dt->format("Y-m-d");
        } catch(\Throwable $e) {
            return "";
        }
    }

    public static function stripMY($str) {
        if (preg_match("/\d\d".self::SEP_REGEX."\d\d\d\d/", $str, $matches)) {
            return $matches[0];
        } else if (preg_match("/\d\d\d\d/", $str, $matches)) {
            return $matches[0];
        }
        return $str;
    }

    public static function datetime2LongDateTime($datetime) {
        try {
            $dt = new \DateTime($datetime);
            return $dt->format("F j, Y, g:i a");
        } catch(\Throwable $e) {
            return "";
        }
    }

    public static function datetime2LongDate($datetime) {
        try {
            $dt = new \DateTime($datetime);
            return $dt->format("F j, Y");
        } catch(\Throwable $e) {
            return "";
        }
    }

    public static function datetime2Date($datetime) {
        if (preg_match("/\s/", $datetime)) {
            $nodes = preg_split("/\s+/", $datetime);
            return $nodes[0];
        }
        # date, not datetime
        return $datetime;
    }

    public static function MDY2LongDate($mdy) {
        $ymd = self::MDY2YMD($mdy);
        return self::YMD2LongDate($ymd);
    }

    public static function YMD2LongDate($ymd) {
        try {
            $dt = new \DateTime($ymd);
            return $dt->format("F j, Y");
        } catch(\Throwable $e) {
            return "";
        }
    }

    public static function correctLeapYear(&$date) {
        if (preg_match("/".self::SEP_REGEX."02".self::SEP_REGEX."29$/", $date)) {
            $year = (int) preg_split("/".self::SEP_REGEX."/", $date)[0];
            if ($year % 4 !== 0) {
                $date = $year."-02-28";
            }
        }
    }

    # Adapted from REDCap to keep consistent
    public static function datediff($d1, $d2, $unit=null, $returnSigned=false, $returnSigned2=false)
    {
        $now = date("Y-m-d H:i:s");
        $today = date("Y-m-d");

        global $missingDataCodes;
        // Make sure Units are provided and that dates are trimmed
        if ($unit == null) return NAN;
        $d1 = trim($d1);
        $d2 = trim($d2);
        // Missing data codes
        if (isset($missingDataCodes) && !empty($missingDataCodes)) {
            if ($d1 != '' && isset($missingDataCodes[$d1])) $d1 = '';
            if ($d2 != '' && isset($missingDataCodes[$d2])) $d2 = '';
        }
        // If ymd, mdy, or dmy is used as the 4th parameter, then assume user is using Calculated field syntax
        // and assume that returnSignedValue is the 5th parameter.
        if (in_array(strtolower(trim($returnSigned)), array('ymd', 'dmy', 'mdy'))) {
            $returnSigned = $returnSigned2;
        }
        // Initialize parameters first
        if (strtolower($d1) === "today") $d1 = $today; elseif (strtolower($d1) === "now") $d1 = $now;
        if (strtolower($d2) === "today") $d2 = $today; elseif (strtolower($d2) === "now") $d2 = $now;
        $d1isToday = ($d1 == $today);
        $d2isToday = ($d2 == $today);
        $d1isNow = ($d1 == $now);
        $d2isNow = ($d2 == $now);
        $returnSigned = ($returnSigned === true || $returnSigned === 'true');
        // Determine data type of field ("date", "time", "datetime", or "datetime_seconds")
        $format_checkfield = ($d1isToday ? $d2 : $d1);
        $numcolons = substr_count($format_checkfield, ":");
        if ($numcolons == 1) {
            if (strpos($format_checkfield, "-") !== false) {
                $datatype = "datetime";
            } else {
                $datatype = "time";
            }
        } else if ($numcolons > 1) {
            $datatype = "datetime_seconds";
        } else {
            $datatype = "date";
        }
        // TIME only
        if ($datatype == "time" && !$d1isToday && !$d2isToday) {
            if ($d1isNow) {
                $d2 = "$d2:00";
                $d1 = substr($d1, -8);
            } elseif ($d2isNow) {
                $d1 = "$d1:00";
                $d2 = substr($d2, -8);
            }
            // Return in specified units
            return self::secondDiff(strtotime($d1),strtotime($d2),$unit,$returnSigned);
        }
        // DATE, DATETIME, or DATETIME_SECONDS
        // If using 'today' for either date, then set format accordingly
        if ($d1isToday) {
            if ($datatype == "time") {
                return NAN;
            } else {
                $d2 = substr($d2, 0, 10);
            }
        } elseif ($d2isToday) {
            if ($datatype == "time") {
                return NAN;
            } else {
                $d1 = substr($d1, 0, 10);
            }
        }
        // If a date[time][_seconds] field, then ensure it has dashes
        if (substr($datatype, 0, 4) == "date" && (strpos($d1, "-") === false || strpos($d2, "-") === false)) {
            return NAN;
        }
        // Make sure the date/time values aren't empty
        if ($d1 == "" || $d2 == "" || $d1 == null || $d2 == null) {
            return NAN;
        }
        // Make sure both values are same length/datatype
        if (strlen($d1) != strlen($d2)) {
            if (strlen($d1) > strlen($d2) && $d2 != '') {
                if (strlen($d1) == 16) {
                    if (strlen($d2) == 10) $d2 .= " 00:00";
                    $datatype = "datetime";
                } else if (strlen($d1) == 19) {
                    if (strlen($d2) == 10) $d2 .= " 00:00";
                    else if (strlen($d2) == 16) $d2 .= ":00";
                    $datatype = "datetime_seconds";
                }
            } else if (strlen($d2) > strlen($d1) && $d1 != '') {
                if (strlen($d2) == 16) {
                    if (strlen($d1) == 10) $d1 .= " 00:00";
                    $datatype = "datetime";
                } else if (strlen($d2) == 19) {
                    if (strlen($d1) == 10) $d1 .= " 00:00";
                    else if (strlen($d1) == 16) $d1 .= ":00";
                    $datatype = "datetime_seconds";
                }
            }
        }
        // Separate time if datetime or datetime_seconds
        $d1b = explode(" ", $d1);
        $d2b = explode(" ", $d2);
        // Split into date and time (in units of seconds)
        $d1 = $d1b[0];
        $d2 = $d2b[0];
        $d1sec = (!empty($d1b[1])) ? strtotime($d1b[1]) : 0;
        $d2sec = (!empty($d2b[1])) ? strtotime($d2b[1]) : 0;
        // Separate pieces of date component
        $dt1 = explode("-", $d1);
        $dt2 = explode("-", $d2);
        // Convert the dates to seconds (conversion varies due to dateformat)
        $dat1 = mktime(0,0,0,(int)$dt1[1],(int)$dt1[2],(int)$dt1[0]) + $d1sec;
        $dat2 = mktime(0,0,0,(int)$dt2[1],(int)$dt2[2],(int)$dt2[0]) + $d2sec;
        // Get the difference in seconds
        return self::secondDiff($dat1, $dat2, $unit, $returnSigned);
    }

    // Return the difference of two number values in desired units converted from seconds
    private static function secondDiff($time1,$time2,$unit,$returnSigned) {
        $sec = $time2-$time1;
        if (!$returnSigned) $sec = abs($sec);
        // Return in specified units
        if ($unit == "s") {
            return $sec;
        } else if ($unit == "m") {
            return $sec/60;
        } else if ($unit == "h") {
            return $sec/3600;
        } else if ($unit == "d") {
            return $sec/86400;
        } else if ($unit == "M") {
            return $sec/2630016; // Use 1 month = 30.44 days
        } else if ($unit == "y") {
            return $sec/31556952; // Use 1 year = 365.2425 days
        }
        return NAN;
    }

    public static function YMD2MY($ymd) {
        try {
            $dt = new \DateTime($ymd);
            return $dt->format("m/Y");
        } catch(\Throwable $e) {
            return $ymd;
        }
    }

    public static function runTests() {
        $invalidDates = [
            "2023-02-29" => "2023-03-01",
            "2024-02-30" => "2024-03-01",
            "2024-13-01" => "2024-13-01",
        ];
        $datesToTest = [
            "2024-02-29" => strtotime("2024-02-29"),
            "2020-01-01" => strtotime("2020-01-01"),
        ];

        $errors = [];
        $successes = [];
        foreach ($invalidDates as $date => $expected) {
            try {
                $result = self::toYMD($date);
                if ($result && ($result != $expected)) {
                    $errors[] = "Invalid date $date produced $result.";
                } else {
                    $successes[] = "Invalid date $date succeeded.";
                }
            } catch (\Throwable $e) {
                $successes[] = "Invalid date $date succeeded.";
            }
        }
        foreach ($datesToTest as $date => $ts) {
            try {
                $ymdResult = self::toYMD($date);
                $mdyResult = self::YMD2MDY($date);
                $longDate = self::YMD2LongDate($date);
                if (strtotime($ymdResult) != $ts) {
                    $errors[] = "Invalid YMD result for $date.";
                } else {
                    $successes[] = "YMD result for $date succeeded.";
                }
                if ($mdyResult != date("m-d-Y", $ts)) {
                    $errors[] = "Invalid MDY result $mdyResult for $date.";
                } else {
                    $successes[] = "MDY result for $date succeeded.";
                }
                if (strtotime($longDate) != $ts) {
                    $errors[] = "Invalid long date result for $date.";
                } else {
                    $successes[] = "Long date result for $date succeeded.";
                }

                $numbersToCheck = [2, 5, 10, -5];
                foreach ($numbersToCheck as $num) {
                    $dt = new \DateTime($date);
                    if ($num > 0) {
                        $dt->modify("+$num years");
                    } else if ($num < 0) {
                        $dt->modify("$num years");
                    }
                    if ($dt->format("Y-m-d") != self::addYears($date, $num)) {
                        $errors[] = "Invalid date $date add $num years: ".self::addYears($date, $num)." vs. ".$dt->format("Y-m-d");
                    } else {
                        $successes[] = "Add $num years to $date succeeded.";
                    }
                    $dt = new \DateTime($date);
                    if ($num > 0) {
                        $dt->modify("+$num months");
                    } else if ($num < 0) {
                        $dt->modify("$num months");
                    }
                    if ($dt->format("Y-m-d") != self::addMonths($date, $num)) {
                        $errors[] = "Invalid date $date add $num months: ".self::addMonths($date, $num)." vs. ".$dt->format("Y-m-d");
                    } else {
                        $successes[] = "Add $num months to $date succeeded.";
                    }
                }
            } catch(\Throwable $e) {
                $errors[] = "Invalid for date $date: ".$e->getMessage();
            }
        }
        echo count($errors)." errors and ".count($successes)." successes.\n";
        echo implode("\n", $errors)."\n";
    }
}
