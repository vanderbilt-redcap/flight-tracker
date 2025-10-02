<?php

namespace Vanderbilt\CareerDevLibrary;

# used in Scholar.php

require_once(__DIR__ . '/ClassLoader.php');

class ScholarResult
{
	public function __construct($value, $source, $sourceType, $date, $pid) {
		$this->value = $value;
		$this->source = self::translateSourceIfNeeded($source, $pid);
		$this->sourceType = $sourceType;
		$this->date = $date;
		$this->pid = $pid;
		$this->field = "";
		$this->instance = "";
	}

	public function trimResult() {
		$this->trimValue();
	}

	public function trimValue() {
		$this->value = trim($this->value);
	}

	public function displayInText() {
		$properties = [];
		$properties[] = "value='".$this->value."'";
		if ($this->source) {
			$properties[] = "source=".$this->source;
		}
		if ($this->sourceType) {
			$properties[] = "sourceType=".$this->sourceType;
		}
		if ($this->date) {
			$properties[] = "date=".$this->date;
		}
		if ($this->field) {
			$properties[] = "field=".$this->field;
		}
		if ($this->instance) {
			$properties[] = "instance=".$this->instance;
		}
		if ($this->pid) {
			$properties[] = "pid=".$this->pid;
		}
		return implode("; ", $properties);
	}

	public function setInstance($instance) {
		$this->instance = $instance;
	}

	public function getInstance() {
		return $this->instance;
	}

	public function setField($field) {
		$this->field = $field;
	}

	public function getField() {
		return $this->field;
	}

	public function setValue($val) {
		$this->value = $val;
	}

	public function getValue() {
		return $this->value;
	}

	public function getSource() {
		return $this->source;
	}

	public function setSource($src) {
		$this->source = $src;
		$this->sourceType = self::calculateSourceType($src, $this->pid);
	}

	public function getSourceType() {
		if (!$this->sourceType) {
			$this->sourceType = self::calculateSourceType($this->source, $this->pid);
		}
		return $this->sourceType;
	}

	public function getDate() {
		return $this->date;
	}

	# returns index from source's choice array
	protected static function translateSourceIfNeeded($source, $pid) {
		$sourceChoices = Scholar::getSourceChoices([], $pid);
		foreach ($sourceChoices as $index => $label) {
			if (($label == $source) || ($index == $source)) {
				return $index;
			}
		}
		return "";
	}

	public static function calculateSourceType($source, $pid = "") {
		$selfReported = ["scholars", "followup", "vfrs"];
		$newman = [ "data", "sheet2", "demographics", "new2017", "k12", "nonrespondents", "manual" ];

		if ($source == "") {
			$sourcetype = "";
		} elseif (in_array($source, $selfReported)) {
			$sourcetype = "1";
		} elseif ($pid && in_array($source, Scholar::getAdditionalSourceTypes(Application::getModule(), "1", $pid))) {
			$sourcetype = "1";
		} elseif (in_array($source, $newman)) {
			$sourcetype = "2";
		} elseif ($pid && in_array($source, Scholar::getAdditionalSourceTypes(Application::getModule(), "2", $pid))) {
			$sourcetype = "2";
		} else {
			$sourcetype = "0";
		}

		return $sourcetype;
	}

	protected $value;
	protected $source;
	protected $sourceType;
	protected $date;
	protected $field;
	protected $instance;
	protected $pid;
}
