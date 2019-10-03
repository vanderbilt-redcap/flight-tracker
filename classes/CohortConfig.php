<?php

namespace Vanderbilt\CareerDevLibrary;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;


require_once(dirname(__FILE__)."/Filter.php");
require_once(dirname(__FILE__)."/Scholar.php");
require_once(dirname(__FILE__)."/../CareerDev.php");

class CohortConfig {
	public function __construct($name, $configAry = array()) {
		$this->name = $name;
		if (self::isValidConfigArray($configAry)) {
			$this->config = $configAry;
		} else {
			$this->config = array(
						"combiner" => "AND",
						"rows" => array(),
						);
		}
	}

	public static function getComparisons() {
		return array(
				"gt" => "&gt;",
				"gteq" => "&gt;=",
				"eq" => "=",
				"neq" => "!=",
				"lteq" => "&lt;=",
				"lt" => "&lt;",
				);
	}

	# returns boolean
	public static function evaluateRow($configRow, $redcapRecordRows, $filter) {
		if (!self::isValidRow($configRow)) {
			return FALSE;
		}
		if ($configRow['type'] == "resources") {
			$variable = "resources_resource";
			$choice = $configRow['variable'];
			foreach ($redcapRecordRows as $row) {
				if ($row[$variable] == $choice) {
					return TRUE;
				}
			}
		} else {
			$variable = $configRow['variable'];
			$comparison = $configRow['comparison'];
			if (preg_match("/^calc_/", $variable)) {
				# calculated variable
				if (isset($configRow['choice'])) {
					$choice = $configRow['choice'];
					$funcChoices = $filter->$variable(GET_VALUE, $redcapRecordRows);
					if (!is_array($funcChoices)) {
						$funcChoices = array($funcChoices);
					}
					# check in/not-in relationship
					return self::compare($funcChoices, $comparison, $choice);
				} else {
					$value = $configRow['value'];
					$funcValues = $filter->$variable(GET_VALUE, $redcapRecordRows);
					if (!is_array($funcValues)) {
						$funcValues = array($funcValues);
					}
					if ($valueTime = strtotime($value)) {
						foreach ($funcValues as $funcValue) {
							$funcTime = strtotime($funcValue);
							if (self::compare($funcTime, $comparison, $valueTime)) {
								return TRUE;
							}
						}
					} else {
						foreach ($funcValues as $funcValue) {
							if (self::compare($funcValue, $comparison, $value)) {
								return TRUE;
							}
						}
					}
				}
			} else {
				# real variable
				if (isset($configRow['choice'])) {
					$choice = $configRow['choice'];
					foreach ($redcapRecordRows as $row) {
						if ($row[$variable]) {
							return self::compare($row[$variable], $comparison, $choice);
						}
					}
				} else {
					$value = $configRow['value'];
					if ($valueTime = strtotime($value)) {
						foreach ($redcapRecordRows as $row) {
							if ($row[$variable]) {
								$variableTime = strtotime($row[$variable]);
								return self::compare($variableTime, $comparison, $valueTime);
							}
						}
					} else {
						foreach ($redcapRecordRows as $row) {
							if ($row[$variable]) {
								return self::compare($row[$variable], $comparison, $value);
							}
						}
					}
				}
			}
		}
		return FALSE;
	}

	private static function compare($value1, $comparison, $value2) {
		switch($comparison) {
			case "gt":
				return ($value1 > $value2);
				break;
			case "gteq":
				return ($value1 >= $value2);
				break;
			case "eq":
				if (is_array($value1)) {
					# in
					return in_array($value2, $value1);
				} else if (is_array($value2)) {
					# in
					return in_array($value1, $value2);
				} else {
					return ($value1 == $value2);
				}
				break;
			case "neq":
				if (is_array($value1)) {
					# not in
					return !in_array($value2, $value1);
				} else if (is_array($value2)) {
					# not in
					return !in_array($value1, $value2);
				} else {
					return ($value1 != $value2);
				}
				break;
			case "lteq":
				return ($value1 <= $value2);
				break;
			case "lt":
				return ($value1 < $value2);
				break;
		}
		return FALSE;
	}

	public function isIn($rows, $filter) {
		$combiner = $this->getCombiner();
		if ($combiner == "AND") {
			foreach ($this->getRows() as $row) {
				if (!self::evaluateRow($row, $rows, $filter)) {
					return FALSE;
				}
			}
			return TRUE;
		} else if ($combiner == "OR") {
			foreach ($this->getRows() as $row) {
				if (self::evaluateRow($row, $rows, $filter)) {
					return TRUE;
				}
			}
			return FALSE;
		} else if ($combiner == "XOR") {
			$count = 0;
			foreach ($this->getRows() as $row) {
				if (self::evaluateRow($row, $rows, $filter)) {
					$count++;
				}
			}
			if ($count == 1) {
				return TRUE;
			}
			return FALSE;
		}
	}

	public function getFields() {
		$fields = array();
		foreach ($this->getRows() as $row) {
			if ($row['type'] != "resources") {
				if (preg_match("/^calc_/", $row['variable'])) {
					foreach (array_merge(CareerDev::$summaryFields, CareerDev::$citationFields) as $field) {
						if (!in_array($field, $fields)) {
							array_push($fields, $field);
						}
					}
				} else {
					if (!in_array($row['variable'], $fields)) {
						array_push($fields, $row['variable']);
					}
				}
			} else {
				$field = "resources_resource";
				if (!in_array($field, $fields)) {
					array_push($fields, $field);
				}
			}
		}
		return $fields;
	}

	public static function isValidConfigArray($ary) {
		if (isset($ary['combiner']) && in_array($ary['combiner'], self::getAllowedCombiners())) {
			if (isset($ary['rows'])) {
				foreach ($ary['rows'] as $row) {
					if (!self::isValidRow($row)) {
						return FALSE;
					}
				}
				return TRUE;
			}
		}
		return FALSE;
	}

	public static function getAllowedCombiners() {
		return array("AND", "OR", "XOR");
	}

	public static function isValidRow($row) {
		$allowedVariables = array_unique(array_keys(array_merge(Filter::getDemographicChoices(), Filter::getGrantChoices(), Filter::getPublicationChoices())));
		
		if (gettype($row) != "array") {
			throw new \Exception("Improper type; should be array! ".gettype($row));
			return FALSE;
		}
		if ($row['type'] == "resources") {
			return TRUE;
		} else if (in_array($row['variable'], $allowedVariables)) {
			if ($row['choice'] || ($row['comparison'] && $row['value'])) {
				return TRUE;
			} else {
				throw new \Exception("Cannot find choice or comparison or value in ".json_encode($row));
			}
		} else {
			throw new \Exception("Cannot find variable in ".json_encode($allowedVariables));
		}
		return FALSE;
	}

	public function addRow($row) {
		if (self::isValidRow($row)) {
			array_push($this->config['rows'], $row);
		} else {
			throw new \Exception("Improperly formatted row: ".json_encode($row));
		}
	}

	public function setCombiner($cbx) {
		$allowed = self::getAllowedCombiners();
		if (in_array($cbx, $allowed)) {
			$this->config['combiner'] = $cbx;
		} else {
			throw new \Exception("Illegal combiner $cbx. Try one from ".json_encode($allowed));
		}
	}

	public function getCombiner() {
		if (isset($this->config['combiner'])) {
			return $this->config['combiner'];
		}
		return "";
	}

	public function toArray() {
		return $this->config;
	}

	public function getArray() {
		return $this->toArray();
	}

	public function getRows() {
		if (isset($this->config['rows'])) {
			return $this->config['rows'];
		}
		return array();
	}

	public function getName() {
		return $this->name;
	}

	public function getHTML($metadata) {
		$html = "";
		$html .= "<table style='margin-left: auto; margin-right: auto;'>\n";

		$choices = Scholar::getChoices($metadata);
		$labels = Filter::getAllChoices();
		$reverseAwardTypes = Grant::getReverseAwardTypes();

		$fieldsInOrder = array("type", "variable", "choice", "comparison", "value");
		$html .= "<tr>\n";
		$html .= "<th>Combiner</th>\n";
		foreach ($fieldsInOrder as $field) {
			$html .= "<th>".ucfirst($field)."</th>\n";
		}
		$html .= "</tr>\n";

		$comparisons = self::getComparisons();
		$contains = Filter::getContainsSettings();
		$firstRow = TRUE;
		foreach ($this->getRows() as $row) {
			$html .= "<tr class='borderedRow centeredRow'>\n";
			if ($firstRow) {
				$html .= "<td></td>\n";
				$firstRow = FALSE;
			} else {
				$html .= "<td>".$this->getCombiner()."</td>";
			}
			$usesContains = FALSE;
			foreach ($fieldsInOrder as $field) {
				if (isset($row[$field])) {
					if (($row["type"] == "resources") && ($field == "variable")) {
						$value = $choices["resources_resource"][$row[$field]];
					} else if (($field == "choice") && isset($choices[$row['variable']]) && $choices[$row['variable']][$row[$field]]) {
						$value = $choices[$row['variable']][$row[$field]];
						$usesContains = TRUE;
					} else if (($field == "variable") && (isset($labels[$row[$field]]))) {
						$value = $labels[$row[$field]];
					} else if (($field == "choice") && ($row["variable"] == "calc_award_type") && (isset($reverseAwardTypes[$row[$field]]))) {
						$value = $reverseAwardTypes[$row[$field]];
						$usesContains = TRUE;
					} else if ($field == "type") {
						$value = ucfirst($row[$field]);
					} else if ($field == "comparison") {
						if ($usesContains) {
							$value = $contains[$row[$field]];
						} else {
							$value = $comparisons[$row[$field]];
						}
					} else {
						$value = $row[$field];
					}
				} else {
					$value = "";
				}
				$html .= "<td>$value</td>\n";
			}
			$html .= "</tr>\n";
		}
		$html .= "</table>\n";

		return $html;
	}

	private $config;
	private $name;
}
