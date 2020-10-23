<?php

namespace Vanderbilt\CareerDevLibrary;

use MathPHP\Statistics\Significance;

require_once(dirname(__FILE__)."/math-php/src/Probability/Distribution/Distribution.php");
require_once(dirname(__FILE__)."/math-php/src/Probability/Distribution/Continuous/ContinuousDistribution.php");
require_once(dirname(__FILE__)."/math-php/src/Probability/Distribution/Continuous/Continuous.php");
require_once(dirname(__FILE__)."/math-php/src/Number/ObjectArithmetic.php");

$directoriesToSearch = [
    "math-php/src/Statistics",
    "math-php/src/Probability/Distribution/Continuous",
    "math-php/src/Probability",
    "math-php/src/Number",
    "math-php/src/Functions",
    ];

foreach ($directoriesToSearch as $dir) {
    $dir = preg_replace("/^\//", "", $dir);
    $dir = preg_replace("/\/$/", "", $dir);
    foreach (glob(dirname(__FILE__)."/$dir/*.php") as $filename)  {
        require_once $filename;
    }
}

# Future: Can implementation tie into: https://github.com/markrogoyski/math-php/tree/master/tests
# Adapted from https://www.geeksforgeeks.org/program-implement-t-test/
class Stats {
    public function __construct($arr) {
        $this->arr = $arr;
    }

    public function getMu() {
        return $this->mean();
    }

    public function getSigma() {
        return $this->standardDeviation();
    }

    public function mean() {
        $n = $this->getN();
        if ($n == 0) {
            return self::$nan;
        }
        $sum = 0;
        for ($i = 0; $i < $n; $i++) {
            $sum = $sum + $this->arr[$i];
        }
        return $sum / $n;
    }

    public function getN() {
        return count($this->arr);
    }

    public function stddev() {
        return $this->standardDeviation();
    }

    // Function to find standard deviation of given array.
    public function standardDeviation() {
        $n = $this->getN();
        if ($n == 0) {
            return self::$nan;
        }
        $sum = 0;
        for ($i = 0; $i < $n; $i++) {
            $sum = $sum + ($this->arr[$i] - $this->mean()) * ($this->arr[$i] - $this->mean());
        }
        return sqrt($sum / ($n - 1));
    }

    // Unimplemented
    public static function pairedTTest($stats) {
        return self::$nan;
    }

    // Function to find t-test of two set of statistical data.
    public function tTest($stats) {
        return $this->unpairedTTest($stats);
    }

    public function getValues() {
        return $this->arr;
    }

    public function unpairedTTest($comparison) {
        $ary = Significance::tTest($this->getValues(), $comparison->getValues());
        return $ary['p2'];   // two-tailed answer
    }

    public function z($x) {
        if ($this->getN() == 0) {
            return self::$nan;
        }
        return ($x - $this->mean()) / $this->standardDeviation();
    }

    public static function convertStandardPercentsToZ($percent) {
        if ($percent == 70) {
            return 1.04;
        } else if ($percent == 75) {
            return 1.15;
        } else if ($percent == 80) {
            return 1.282;
        } else if ($percent == 85) {
            return 1.440;
        } else if ($percent == 90) {
            return 1.645;
        } else if ($percent == 92) {
            return 1.75;
        } else if ($percent == 95) {
            return 1.960;
        } else if ($percent == 96) {
            return 2.05;
        } else if ($percent == 98) {
            return 2.33;
        } else if ($percent == 99) {
            return 2.576;
        } else if ($percent == "99.5") {
            return 2.807;
        } else if ($percent == "99.9") {
            return 3.291;
        } else {
            throw new \Exception("Percent $percent not supported!");
        }
    }

    public function confidenceInterval($percent) {
        $n = $this->getN();
        if ($n == 0) {
            return [self::$nan, self::$nan];
        }
        $mu = $this->mean();
        $sigma = $this->standardDeviation();
        $z = self::convertStandardPercentsToZ($percent);
        $ci = [];
        $ci[0] = $mu - $z * $sigma / sqrt($n);
        $ci[1] = $mu + $z * $sigma / sqrt($n);
        return $ci;
    }

    protected $arr = [];
    public static $nan = "NaN";
}