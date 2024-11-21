<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class DateMeasurement extends Measurement {
    public function __construct($date) {
        if (preg_match("/^\d\d\d\d-\d+-\d+$/", $date)) {
            # YMD
            preg_match("/^\d\d\d\d/", $date, $matches);
            $this->year = $matches[0];

            preg_match("/-\d+-/", $date, $matches);
            $this->month = str_replace("-", "", $matches[0]);

            preg_match("/-\d+$/", $date, $matches);
            $this->day = str_replace("-", "", $matches[0]);
        } else if (preg_match("/^\d+-\d+-\d\d\d\d/", $date)) {
            # MDY
            preg_match("/\d\d\d\d$/", $date, $matches);
            $this->year = $matches[0];

            preg_match("/^\d+-/", $date, $matches);
            $this->month = str_replace("-", "", $matches[0]);

            preg_match("/-\d+-/", $date, $matches);
            $this->day = str_replace("-", "", $matches[0]);
        } else {
            throw new \Exception("Date $date must be in MDY or YMD format!");
        }
    }

    public function getYMD() {
        return $this->year."-".$this->month."-".$this->day;
    }

    public function getMDY() {
        return $this->month."-".$this->day."-".$this->year;
    }

    public function getWeekDay() {
        $ymd = $this->getYMD();
        $ts = strtotime($ymd);
        return date("l", $ts);
    }

    private $year;
    private $month;
    private $day;
}

