<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

abstract class Study {
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

    protected $control;
    protected $treatment;
}