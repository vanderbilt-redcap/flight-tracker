<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class ComparisonStudy extends Study {
    public function getNumberHealthy($type) {
        $ary = [];
        if ($type == "Treatment") {
            $ary = $this->getTreatment()->getValues();
        } else if ($type == "Control") {
            $ary = $this->getControl()->getValues();
        }
        if (empty($ary)) {
            return 0;
        }
        $num = 0;
        foreach ($ary as $item) {
            if ($item) {
                $num++;
            }
        }
        return $num;
    }

    public function getNumberDiseased($type) {
        $ary = [];
        if ($type == "Treatment") {
            $ary = $this->getTreatment()->getValues();
        } else if ($type == "Control") {
            $ary = $this->getControl()->getValues();
        }
        if (empty($ary)) {
            return 0;
        }
        $num = 0;
        foreach ($ary as $item) {
            if (!$item) {
                $num++;
            }
        }
        return $num;
    }

    public function getRelativeRisk() {
        $numExposedHealthy = $this->getNumberHealthy("Treatment");
        $numExposedDiseased = $this->getNumberDiseased("Treatment");
        $numNotExposedHealthy = $this->getNumberHealthy("Control");
        $numNotExposedDiseased = $this->getNumberDiseased("Control");
        $numer = $numExposedDiseased / ($numExposedDiseased + $numExposedHealthy);
        $denom = $numNotExposedDiseased / ($numNotExposedDiseased + $numNotExposedHealthy);
        return $numer / $denom;
    }

    # https://www.ncbi.nlm.nih.gov/pmc/articles/PMC2938757/
    # Healthy = case (+)
    public function getOddsRatio() {
        $numExposedHealthy = $this->getNumberHealthy("Treatment");
        $numExposedDiseased = $this->getNumberDiseased("Treatment");
        $numNotExposedHealthy = $this->getNumberHealthy("Control");
        $numNotExposedDiseased = $this->getNumberDiseased("Control");
        return $numExposedHealthy * $numNotExposedDiseased / ($numNotExposedHealthy * $numExposedDiseased);
    }
}