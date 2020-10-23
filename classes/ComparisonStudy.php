<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/Stats.php");

class ComparisonStudy {
    public function __construct($control, $treatment) {
        if (is_array($control) && is_array($treatment)) {
            $control = new Stats($control);
            $treatment = new Stats($treatment);
        }

        $statsClassName = "Vanderbilt\CareerDevLibrary\Stats";
        if ((get_class($control) != $statsClassName) || (get_class($treatment) != $statsClassName)) {
            throw new \Exception("Both control and treatment need to be a Stats object: ".get_class($control)." ".get_class($treatment));
        }
        $this->control = $control;
        $this->treatment = $treatment;
    }

    public function getControl() {
        return $this->control;
    }

    public function getTreatment() {
        return $this->treatment;
    }

    # types include unpaired, paired, left-tailed paired, right-tailed paired
    # TODO need to develop paired tests
    public function getP($type = "unpaired") {
        if (strtolower($type) == "unpaired") {
            return $this->control->unpairedTTest($this->treatment);
        } else {
            if ($this->control->getN() != $this->treatment->getN()) {
                throw new \Exception("Control and treatment cohorts have different sizes!");
            }
            return $this->control->pairedTTest($this->treatment);
        }
    }

    public function z($x, $useControl) {
        return $this->getZ($x, $useControl);
    }

    public function getZ($x, $useControl) {
        if ($useControl) {
            return $this->control->z($x);
        } else {
            return $this->treatment->z($x);
        }
    }

    public function supportsPercent($percent) {
        try {
            Stats::convertStandardPercentsToZ($percent);
            return TRUE;
        } catch(\Exception $e) {
            return FALSE;
        }
    }

    public function getConfidenceInterval($percent) {
        return $this->getTreatmentCI($percent);
    }


    public function getTreatmentCI($percent) {
        return $this->treatment->confidenceInterval($percent);
    }

    protected $control;
    protected $treatment;
}
