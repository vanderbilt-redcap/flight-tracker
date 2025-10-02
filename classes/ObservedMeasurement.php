<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class ObservedMeasurement extends Measurement
{
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
