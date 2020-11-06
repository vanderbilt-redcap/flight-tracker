<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/Stats.php");
require_once(dirname(__FILE__)."/Study.php");

class ComparisonStudy extends Study {
    public function getNumberHealthy($type) {
        if ($type == "Treatment") {
            $ary = $this->getTreatment()->getValues();
        } else if ($type == "Control") {
            $ary = $this->getControl()->getValues();
        }
        if (!$ary) {
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
        if ($type == "Treatment") {
            $ary = $this->getTreatment()->getValues();
        } else if ($type == "Control") {
            $ary = $this->getControl()->getValues();
        }
        if (!$ary) {
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