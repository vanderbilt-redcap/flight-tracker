<?php

namespace Vanderbilt\CareerDevLibrary;

# used in Scholar.php

require_once(__DIR__ . '/ClassLoader.php');

class RaceEthnicityResult extends ScholarResult
{
	public function __construct($value, $raceSource, $ethnicitySource, $pid = "") {
		$this->value = $value;
		$this->raceSource = self::translateSourceIfNeeded($raceSource, $pid);
		$this->ethnicitySource = self::translateSourceIfNeeded($ethnicitySource, $pid);
		$this->pid = $pid;
	}

	public function getRaceSource() {
		return $this->raceSource;
	}

	public function getEthnicitySource() {
		return $this->ethnicitySource;
	}

	public function getRaceSourceType() {
		return self::calculateSourceType($this->raceSource, $this->pid);
	}

	public function getEthnicitySourceType() {
		return self::calculateSourceType($this->ethnicitySource, $this->pid);
	}

	private $raceSource;
	private $ethnicitySource;
}
