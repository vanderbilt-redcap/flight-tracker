<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class DateManagement {
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

    public static function getWeekNumInYear($ts = FALSE) {
        if (!$ts) {
            $ts = time();
        }
        $dayOfYear = date("z", $ts);
        return floor(($dayOfYear - 1) / 7) + 1;
    }

    public static function getWeekNumInMonth($ts = FALSE) {
        if (!$ts) {
            $ts = time();
        }
        $dayOfMonth = date("j", $ts);
        return floor(($dayOfMonth - 1) / 7) + 1;
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
        $nodes = preg_split("/[\-\/]/", $str);
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

    public static function MY2YMD($my) {
        if (!$my) {
            return "";
        }
        $sep = "-";
        $nodes = preg_split("/[\-\/]/", $my);
        if (count($nodes) == 2) {
            return $nodes[1] . $sep . $nodes[0] . $sep . "01";
        } else if (count($nodes) == 3) {
            $mdy = $my;
            return self::MDY2YMD($mdy);
        } else if (count($nodes) == 1) {
            $year = $nodes[0];
            if ($year > 1900) {
                return $year."-01-01";
            } else {
                throw new \Exception("Invalid year: $year");
            }
        } else {
            throw new \Exception("Cannot convert MM/YYYY $my");
        }
    }

    public static function getYear($date) {
        $ts = strtotime($date);
        if ($ts) {
            return date("Y", $ts);
        }
        return "";
    }

    public static function getDayDuration($date1, $date2) {
        $span = self::getSecondDuration($date1, $date2);
        $oneDay = 24 * 3600;
        return $span / $oneDay;
    }

    public static function getSecondDuration($date1, $date2) {
        $ts1 = strtotime($date1);
        $ts2 = strtotime($date2);
        if ($ts1 && $ts2) {
            return abs($ts2 - $ts1);
        } else {
            throw new \Exception("Could not get timestamps from $date1 and $date2");
        }
    }

    public static function getYearDuration($date1, $date2) {
        $span = self::getSecondDuration($date1, $date2);
        $oneYear = 365 * 24 * 3600;
        return $span / $oneYear;
    }

    public static function REDCapTsToPHPTs($redcapTs) {
        $year = substr($redcapTs, 0, 4);
        $month = substr($redcapTs, 4, 2);
        $day = substr($redcapTs, 6, 2);
        return strtotime("$year-$month-$day");
    }

    public static function getLatestDate($dates) {
        $latestTs = 0;
        $latestDate = "";
        foreach ($dates as $date) {
            $ts = strtotime($date);
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
        if (preg_match("/^\d+[\/\-]\d+[\/\-]\d+$/", $str)) {
            $nodes = preg_split("/[\/\-]/", $str);
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
        if (preg_match("/^\d+[\/\-]\d+[\/\-]\d+$/", $str)) {
            $nodes = preg_split("/[\/\-]/", $str);
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
        if (preg_match("/^\d+[\/\-]\d+[\/\-]\d+$/", $str)) {
            $nodes = preg_split("/[\/\-]/", $str);
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

    public static function isOracleDate($d) {
        return preg_match("/^\d\d-[A-Z]{3}-\d\d$/", $d);
    }

    public static function oracleDate2YMD($d) {
        if ($d === "") {
            return "";
        }
        $nodes = preg_split("/\-/", $d);
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
            $months = array(
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
            );
            if (!isset($months[$month])) {
                throw new \Exception("Invalid month $month");
            }
            $month = $months[$month];
            return $year."-".$month."-".$day;
        } else {
            throw new \Exception("Invalid date $d");
        }
        return "";
    }

    public static function YMD2MDY($ymd) {
        $ts = strtotime($ymd);
        if ($ts) {
            return date("m-d-Y", $ts);
        }
        return "";
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
        $nodes = preg_split("/[\/\-]/", $date);
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
            return $year."-".$month."-".$day;
        }
        return "";
    }

    public static function DMY2YMD($dmy) {
        return self::genericDateToYMD($dmy, "dmy");
    }

    public static function addMonths($date, $months) {
        $ts = strtotime($date);
        if ($ts) {
            $year = date("Y", $ts);
            $month = date("m", $ts);
            $month += $months;
            while ($month <= 0) {
                $month += 12;
                $year--;
            }
            while ($month > 12) {
                $month -= 12;
                $year++;
            }
            $day = date("d", $ts);
            return $year."-".$month."-".$day;
        }
        return "";
    }

    public static function addYears($date, $years) {
        $ts = strtotime($date);
        $year = date("Y", $ts);
        $year += $years;
        $monthDays = date("-m-d", $ts);
        return $year.$monthDays;
    }

    public static function stripMY($str) {
        if (preg_match("/\d\d[\/\-]\d\d\d\d/", $str, $matches)) {
            return $matches[0];
        } else if (preg_match("/\d\d\d\d/", $str, $matches)) {
            return $matches[0];
        }
        return $str;
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
        $ts = strtotime($ymd);
        if ($ts) {
            return date("F j, Y", $ts);
        }
        return "";
    }

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
        $ts = strtotime($ymd);
        if ($ts) {
            return date("m/Y", $ts);
        }
        return $ymd;
    }
}
