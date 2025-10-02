<?php

namespace Vanderbilt\CareerDevLibrary;

# Used in Cohorts, CohortConfig, and Filter

require_once(__DIR__ . '/ClassLoader.php');

class CalcSettings
{
	public function __construct(string $type) {
		$valid = self::getValidTypes();
		if (in_array($type, $valid)) {
			$this->type = $type;
		} else {
			throw new \Exception("Invalid type $type.");
		}
	}

	public function set1DToHash(array $ary1D): void {
		$this->choices = [];
		foreach ($ary1D as $item) {
			$this->choices[$item] = $item;
		}
	}

	public function setChoicesHash(array $associativeArray): void {
		$this->choices = [];
		foreach ($associativeArray as $value => $label) {
			$this->choices[$value] = $label;
		}
	}

	public function setChoices(array $ary): void {
		$this->choices = [];
		foreach ($ary as $item) {
			$this->choices[$item] = $item;
		}
	}

	public function getChoices(): array {
		if ($this->type == "choices") {
			if ($this->choices) {
				return $this->choices;
			}
		}
		return [];
	}

	public function getComparisons(): array {
		if ($this->type == "string") {
			return Filter::getStringComparisons();
		} elseif (($this->type == "number") || ($this->type == "date")) {
			return CohortConfig::getComparisons();
		}
		return [];
	}

	public static function getValidTypes(): array {
		return ["choices", "string", "number", "date"];
	}

	public static function transformToInputType(string $calcSettingsType): string {
		switch ($calcSettingsType) {
			case "string":
				return "text";
			case "number":
				return "number";
			case "date":
				return "date";
		}
		return "";
	}

	public static function getTypeFromMetadata(string $field, array $metadata): string {
		$numberValidationTypes = ["integer", "number"];
		$choiceFieldTypes = ["radio", "checkbox", "dropdown"];

		foreach ($metadata as $row) {
			if ($row['field_name'] == $field) {
				if ($row['select_choices_or_calculations'] && in_array($row['field_type'], $choiceFieldTypes)) {
					return "choices";
				} elseif (preg_match("/^date/", $row['text_validation_type_or_show_slider_number'])) {
					return "date";
				} elseif (in_array($row['text_validation_type_or_show_slider_number'], $numberValidationTypes)) {
					return "number";
				} else {
					# not number nor date => string
					return "string";
				}
			}
		}
		# invalid $field
		return "";
	}

	public function getType(): string {
		return $this->type;
	}

	private $type;
	private $choices;
}
