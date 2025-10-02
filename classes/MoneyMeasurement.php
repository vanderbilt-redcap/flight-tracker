<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class MoneyMeasurement extends Measurement
{
	public function __construct($amount, $total = "") {
		$this->amount = $amount;
		$this->total = $total;
	}

	public function getAmount() {
		if ($this->amount == Stats::$nan) {
			return "0";
		} else {
			return $this->amount;
		}
	}

	public function getTotal() {
		return $this->total;
	}

	private $amount = 0;
	private $total = 0;
}
