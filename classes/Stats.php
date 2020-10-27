<?php

namespace Vanderbilt\CareerDevLibrary;

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
    public function tTest($comparison) {
        $t = $this->unpairedTTest($comparison);
        return $t;
    }

    public function getDegreesOfFreedom() {
        return $this->getN() - 1;
    }

    public function getValues() {
        return $this->arr;
    }

    public function unpairedTTest($comparison) {
        if (get_class($comparison) != "Vanderbilt\CareerDevLibrary\Stats") {
            return self::$nan;
        }
        $n = $this->getN();
        $m = $comparison->getN();
        if ($n == 0) {
            return self::$nan;
        }
        if ($m == 0) {
            return self::$nan;
        }

        $mean1 = $this->mean();
        $mean2 = $comparison->mean();
        $sd1 = $this->standardDeviation();
        $sd2 = $comparison->standardDeviation();

        // Formula to find t-test of two set of data.
        $t_test = ($mean1 - $mean2) / sqrt(($sd1 * $sd1) / $n + ($sd2 * $sd2) / $m);
        return $t_test;
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

    public function confidenceInterval($percent, $df = FALSE) {
        $n = $this->getN();
        if ($n == 0) {
            return [self::$nan, self::$nan];
        }
        $mu = $this->mean();
        $sigma = $this->standardDeviation();
        if ($df) {
            # T score
            $z = CohortStudy::convertPercentToZ($percent, $df);
        } else {
            $z = self::convertStandardPercentsToZ($percent);
        }
        $ci = [];
        $ci[0] = $mu - $z * $sigma / sqrt($n);
        $ci[1] = $mu + $z * $sigma / sqrt($n);
        return $ci;
    }

    protected $arr = [];
    public static $nan = "NaN";
}