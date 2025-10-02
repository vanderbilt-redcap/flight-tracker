<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class CohortConfig
{
	public function __construct($name, $configAry = [], $pid = null) {
		$this->name = $name;
		if (self::isValidConfigArray($configAry)) {
			$this->config = $configAry;
		} else {
			$this->config = [
						"rows" => [],
						];
		}
		$this->pid = $pid;
		$this->token = Application::getSetting("token", $this->pid);
		$this->server = Application::getSetting("server", $this->pid);
	}

	public static function getComparisons() {
		return [
				"gt" => "&gt;",
				"gteq" => "&gt;=",
				"eq" => "=",
				"neq" => "!=",
				"lteq" => "&lt;=",
				"lt" => "&lt;",
				];
	}

	# returns boolean
	public static function evaluateRow($configRow, $redcapRecordRows, $filter) {
		if (!self::isValidRow($configRow)) {
			return false;
		}
		if ($configRow['type'] == "resources") {
			$variable = "resources_resource";
			$choice = $configRow['variable'];
			foreach ($redcapRecordRows as $row) {
				if ($row[$variable] == $choice) {
					return true;
				}
			}
		} else {
			$variable = $configRow['variable'];
			$comparison = $configRow['comparison'];
			if (preg_match("/^calc_/", $variable)) {
				# calculated variable
				if (isset($configRow['choice'])) {
					$choice = $configRow['choice'];
					$funcChoices = $filter->$variable(Filter::GET_VALUE, $redcapRecordRows);
					if (!is_array($funcChoices)) {
						$funcChoices = [$funcChoices];
					}
					# check in/not-in relationship
					return self::compare($funcChoices, $comparison, $choice);
				} else {
					$value = $configRow['value'];
					$funcValues = $filter->$variable(Filter::GET_VALUE, $redcapRecordRows);
					if (!is_array($funcValues)) {
						$funcValues = [$funcValues];
					}
					if ($valueTime = strtotime($value)) {
						foreach ($funcValues as $funcValue) {
							$funcTime = strtotime($funcValue);
							if (self::compare($funcTime, $comparison, $valueTime)) {
								return true;
							}
						}
					} else {
						foreach ($funcValues as $funcValue) {
							if (self::compare($funcValue, $comparison, $value)) {
								return true;
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
						} elseif (isset($row[$variable."___".$choice])) {
							return self::compare($row[$variable."___".$choice], $comparison, "1");
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
							if (isset($row[$variable])) {
								return self::compare($row[$variable], $comparison, $value);
							}
						}
					}
				}
			}
		}
		return false;
	}

	private static function compare($value1, $comparison, $value2) {
		switch ($comparison) {
			case "contains":
				if (is_array($value1)) {
					# in
					return in_array($value2, $value1);
				} elseif (is_array($value2)) {
					# in
					return in_array($value1, $value2);
				} else {
					return (strpos($value1, $value2) !== false);
				}
				break;
			case "not_contains":
				if (is_array($value1)) {
					# not in
					return !in_array($value2, $value1);
				} elseif (is_array($value2)) {
					# not in
					return !in_array($value1, $value2);
				} else {
					return (strpos($value1, $value2) === false);
				}
				break;
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
				} elseif (is_array($value2)) {
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
				} elseif (is_array($value2)) {
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
		return false;
	}

	public function isIn($rows, $filter) {
		$combiner = $this->getCombiner();
		if ($combiner == "") {
			# combiners are in each row
			if (count($this->getRows()) == 1) {
				$row = $this->getRows()[0];
				return self::evaluateRow($row, $rows, $filter);
			} else {
				# 1. evaluate rows
				$postStep1 = [];
				foreach ($this->getRows() as $row) {
					$evaluatedRow = [];
					$evaluatedRow['value'] = self::evaluateRow($row, $rows, $filter);
					if ($row['combiner']) {
						$evaluatedRow['combiner'] = $row['combiner'];
					}
					$postStep1[] = $evaluatedRow;
				}

				# 2. evaluate XORs
				$postStep2 = [];
				$previous = null;
				foreach ($postStep1 as $evalRow) {
					if (!$previous) {
						$previous = $evalRow;
					} elseif (isset($evalRow['combiner']) && ($evalRow['combiner'] == "XOR")) {
						$newEvalRow = [];
						$newEvalRow['combiner'] = $previous['combiner'] ?? "";
						$cnt = 0;
						if ($previous['value']) {
							$cnt++;
						}
						if ($evalRow['value']) {
							$cnt++;
						}
						if ($cnt == 1) {
							$newEvalRow['value'] = true;
						} else {
							$newEvalRow['value'] = false;
						}
						$previous = $newEvalRow;
					} else {
						$postStep2[] = $previous;
						$previous = $evalRow;
					}
				}
				if ($previous) {
					$postStep2[] = $previous;
				}

				# 3. evaluate ANDs
				$postStep3 = [];
				$previous = null;
				foreach ($postStep2 as $evalRow) {
					if (!$previous) {
						$previous = $evalRow;
					} elseif (isset($evalRow['combiner']) && ($evalRow['combiner'] == "AND")) {
						$newEvalRow = [];
						$newEvalRow['combiner'] = $previous['combiner'] ?? "";
						$newEvalRow['value'] = ($previous['value'] && $evalRow['value']);
						$previous = $newEvalRow;
					} else {
						$postStep3[] = $previous;
						$previous = $evalRow;
					}
				}
				if ($previous) {
					$postStep3[] = $previous;
				}

				# 4. evaluate ORs
				foreach ($postStep3 as $evalRow) {
					if ($evalRow['value']) {
						return true;
					}
				}
				return false;
			}
		} elseif ($combiner == "AND") {
			# old system - combiner is universal
			foreach ($this->getRows() as $row) {
				if (!self::evaluateRow($row, $rows, $filter)) {
					return false;
				}
			}
			return true;
		} elseif ($combiner == "OR") {
			# old system - combiner is universal
			foreach ($this->getRows() as $row) {
				if (self::evaluateRow($row, $rows, $filter)) {
					return true;
				}
			}
			return false;
		} elseif ($combiner == "XOR") {
			# old system - combiner is universal
			$count = 0;
			foreach ($this->getRows() as $row) {
				if (self::evaluateRow($row, $rows, $filter)) {
					$count++;
				}
			}
			if ($count == 1) {
				return true;
			}
			return false;
		}
		throw new \Exception("Improper combiner: '$combiner'");
	}

	public function getManualRecords() {
		if (isset($this->config['records'])) {
			return $this->config['records'];
		}
		return [];
	}

	public function addRecords($records) {
		$this->config['records'] = $records;
	}

	public function getFields(): array {
		$fields = [];
		$citationCalcFunctions = [
			"calc_rcr",
			"calc_pub_type",
			"calc_mesh_term",
			"calc_num_pubs",
			"calc_from_time",
		];
		foreach ($this->getRows() as $row) {
			if ($row['type'] == "resources") {
				$newFields = ["resources_resource"];
			} elseif ($row['variable'] == "calc_employment") {
				$newFields = Application::$institutionFields;
			} elseif (in_array($row['variable'], $citationCalcFunctions)) {
				$newFields = [
					"record_id",
					"citation_include",
					"citation_pmid",
					"citation_day",
					"citation_month",
					"citation_year",
					"citation_mesh_terms",
					"citation_pub_types",
				];
			} elseif (preg_match("/^calc_/", $row['variable'])) {
				$newFields = Application::$summaryFields;
			} else {
				$newFields = [$row['variable']];
			}
			$fields = array_unique(array_merge($fields, $newFields));
		}
		return $fields;
	}

	public static function isValidConfigArray($ary) {
		if (isset($ary['combiner']) && in_array($ary['combiner'], self::getAllowedCombiners())) {
			if (isset($ary['rows'])) {
				foreach ($ary['rows'] as $row) {
					if (!self::isValidRow($row)) {
						return false;
					}
				}
				return true;
			}
		} elseif (isset($ary['rows'])) {
			$first = true;
			foreach ($ary['rows'] as $row) {
				if (!self::isValidRow($row)) {
					return false;
				}
				if ($first) {
					$first = false;
				} elseif (!isset($row['combiner']) || !in_array($row['combiner'], self::getAllowedCombiners())) {
					return false;
				}
			}
			return true;
		} elseif (isset($ary['records'])) {
			return true;
		}
		return false;
	}

	public static function getAllowedCombiners() {
		return ["AND", "OR", "XOR"];
	}

	public static function isValidRow($row) {
		// $filter needs metadata!!!
		// $allowedVariables = array_unique(array_keys(array_merge($filter->getDemographicChoices(), $filter->getGrantChoices(), $filter->getPublicationChoices())));

		if (gettype($row) != "array") {
			throw new \Exception("Improper type; should be array! ".gettype($row));
		}
		if ($row['type'] == "resources") {
			return true;
		} elseif (is_array($row['variable'])) {
			return true;
		} else {
			// if (in_array($row['variable'], $allowedVariables)) {
			if (isset($row['choice']) || (isset($row['comparison']) && isset($row['value']))) {
				return true;
			} else {
				throw new \Exception("Cannot find choice or comparison or value in " . json_encode($row));
			}
		}
	}

	public function addRow($row) {
		if (self::isValidRow($row)) {
			$this->config['rows'][] = $row;
		} else {
			throw new \Exception("Improperly formatted row: ".json_encode($row));
		}
	}

	public function setCombiner($cbx) {
		$allowed = self::getAllowedCombiners();
		if (in_array($cbx, $allowed)) {
			$this->config['combiner'] = $cbx;
		} elseif ($cbx != "") {
			throw new \Exception("Illegal combiner '$cbx'. Try one from ".json_encode($allowed));
		}
		# blank combiners allowed
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
		return [];
	}

	public function getName() {
		return $this->name;
	}

	public function getHTML($metadata) {
		if (isset($this->config['records'])) {
			if (empty($this->config['records'])) {
				return "<p class='centered'>No Records Specified</p>";
			} else {
				return "<p class='centered'>Records: ".implode(", ", $this->config['records'])."</p>";
			}
		}
		$html = "<table style='margin-left: auto; margin-right: auto;'>\n";

		$filter = new Filter($this->token, $this->server, $metadata);

		$choices = Scholar::getChoices($metadata);
		$labels = $filter->getAllChoices();
		$reverseAwardTypes = Grant::getReverseAwardTypes();

		$fieldsInOrder = ["type", "variable", "choice", "comparison", "value"];
		$html .= "<tr>\n";
		$html .= "<th>Combiner</th>\n";
		foreach ($fieldsInOrder as $field) {
			$html .= "<th>".ucfirst($field)."</th>\n";
		}
		$html .= "</tr>\n";

		$comparisons = self::getComparisons();
		$contains = Filter::getContainsSettings();
		$stringComparisons = Filter::getStringComparisons();
		$firstRow = true;
		foreach ($this->getRows() as $row) {
			$html .= "<tr class='borderedRow centeredRow'>\n";
			if ($firstRow) {
				$html .= "<td></td>\n";
				$firstRow = false;
			} else {
				if ($row['combiner'] && ($this->getCombiner() == "")) {
					$html .= "<td>".$row['combiner']."</td>";
				} elseif ($this->getCombiner()) {
					$html .= "<td>".$this->getCombiner()."</td>";
				} else {
					throw new \Exception("Could not find combiner for row ".json_encode($row));
				}
			}
			$usesContains = false;
			foreach ($fieldsInOrder as $field) {
				if (isset($row[$field])) {
					if (($row["type"] == "resources") && ($field == "variable")) {
						$value = $choices["resources_resource"][$row[$field]];
					} elseif (($field == "choice") && isset($choices[$row['variable']]) && $choices[$row['variable']][$row[$field]]) {
						$value = $choices[$row['variable']][$row[$field]];
						$usesContains = true;
					} elseif (($field == "variable") && isset($labels[$row[$field]])) {
						$value = $labels[$row[$field]];
					} elseif (($field == "choice") && in_array($row["variable"], ["calc_award_type", "calc_active_award_type"]) && (isset($reverseAwardTypes[$row[$field]]))) {
						$value = $reverseAwardTypes[$row[$field]];
						$usesContains = true;
					} elseif ($field == "type") {
						$value = ucfirst($row[$field]);
					} elseif ($field == "comparison") {
						if ($usesContains) {
							$value = $contains[$row[$field]];
						} elseif ($comparisons[$row[$field]]) {
							$value = $comparisons[$row[$field]];
						} elseif ($stringComparisons[$row[$field]]) {
							$value = $stringComparisons[$row[$field]];
						} else {
							throw new \Exception("This should never happen. Comparison of type {$row[$field]}.");
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
	private $pid;
	private $token = "";
	private $server = "";
}
