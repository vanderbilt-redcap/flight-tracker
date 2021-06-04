<?php

namespace Vanderbilt\CareerDevLibrary;

class Measurement {
    public function __construct($numerator, $denominator = "") {
        $this->numerator = $numerator;
        $this->denominator = $denominator;
    }

    public function getNumerator() {
        return $this->numerator;
    }

    public function getDenominator() {
        return $this->denominator;
    }

    public function setPercentage($bool) {
        $this->isPerc = $bool;
    }

    public function isPercentage() {
        return $this->isPerc;
    }

    public function setNames($numer, $denom) {
        if (!is_array($numer) || !is_array($denom)) {
            throw new \Exception("Each variable must be an array!");
        }
        $this->numerNames = $numer;
        $this->denomNames = $denom;
    }

    public function getNames($type) {
        if (strtolower($type) == "numer") {
            return $this->numerNames;
        } else if (strtolower($type) == "denom") {
            return $this->denomNames;
        } else {
            throw new \Exception("Improper type $type");
        }
    }

    private $numerator;
    private $denominator;
    private $numerNames = [];
    private $denomNames = [];
    private $isPerc = FALSE;
}

class MoneyMeasurement extends Measurement {
    public function __construct($amount, $total = "") {
        $this->amount = $amount;
        $this->total = $total;
    }

    public function getAmount() {
        return $this->amount;
    }

    public function getTotal() {
        return $this->total;
    }

    private $amount = 0;
    private $total = 0;
}

class ObservedMeasurement extends Measurement {
    public function __construct($value, $n) {
        $this->value = $value;
        $this->n = $n;
    }

    public function getValue() {
        return $this->value;
    }

    public function getN() {
        return $this->n;
    }

    private $value = 0;
    private $n = 0;
}

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
            throw new Exception("Date must be in MDY or YMD format!");
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

