<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');
class Measurement
{
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
		} elseif (strtolower($type) == "denom") {
			return $this->denomNames;
		} else {
			throw new \Exception("Improper type $type");
		}
	}

	private $numerator;
	private $denominator;
	private $numerNames = [];
	private $denomNames = [];
	private $isPerc = false;
}
