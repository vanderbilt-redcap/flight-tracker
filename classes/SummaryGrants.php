<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/Grants.php");

# The Grants class is an abstraction based upon the hard-data procured in the Grant sources.
# This takes the Grants class as input and outputs how much each scholar receives in a calendar year.

class SummaryGrants {
	public function __construct($token, $server) {
		$this->token = $token;
		$this->server = $server;
		$this->yearDef = "Calendar";
	}

	public function setYearToFiscal() {
		$this->yearDef = "Fiscal";
	}

	public function setYearToCalendar() {
		$this->yearDef = "Calendar";
	}

	public function setYearToFederal() {
		$this->yearDef = "Federal";
	}

	public function isFiscalYear() {
		return ($this->yearDef == "Fiscal");
	}

	public function isCalendarYear() {
		return ($this->yearDef == "Calendar");
	}

	public function isFederalYear() {
		return ($this->yearDef == "Federal");
	}

	# grants must be compiled after this method
	public function setGrants($recordId, $grants, $metadata = array()) {
		$this->grants = $grants;
		$this->recordId = $recordId;
		$this->dollars = array();
		if (empty($metadata)) {
			if (!$this->metadata || empty($this->metadata)) {
				$this->metadata = Download::metadata($this->token, $this->server);
			}
		} else {
			$this->metadata = $metadata;
		}
	}

	public function process() {
		$yearspanStart = PHP_INT_MAX;
		$yearspanEnd = 0;
		foreach ($this->grants->getGrants("native") as $grant) {
			$start = $grant->getVariable("start");
			$end = $grant->getVariable("end");
			if ($start && $end) {
				$startTs = strtotime($start);
				$endTs = strtotime($end);
				if ($startTs && $endTs) {
					if ($startTs < $yearspanStart) {
						$yearspanStart = $startTs;
					}
					if ($endTs > $yearspanEnd) {
						$yearspanEnd = $endTs;
					}
				}
			}
		}

		# put grants in order, from original sources so that each source has its budget
		$grantsToUseInOrder = array();
		foreach ($this->grants->getGrants("compiled") as $compiledGrant) {
			$compiledBaseNo = $compiledGrant->getBaseNumber();
			$grantsForThisStep = array();
			foreach ($this->grants->getGrants("native") as $grant) {
				$grantBaseNo = $grant->getBaseNumber();
				if ($compiledBaseNo == $grantBaseNo) {
					array_push($grantsForThisStep, $grant);
				}
			}
			$grantsFromOneSource = array();
			foreach (Grants::getSourceOrder() as $source) {
				foreach ($grantsForThisStep as $grant) {
					if ($source == $grant->getVariable("source")) {
						array_push($grantsFromOneSource, $grant);
					}
				}
				# once added for one source, stop; this source will be used for budgeting purposes
				if (count($grantsFromOneSource) > 0) {
					break;
				}
			}
			foreach ($grantsFromOneSource as $grant) {
				array_push($grantsToUseInOrder, $grant);
			}
		}

		# now, total up budgets
		foreach ($grantsToUseInOrder as $grant) {
			$budget = $grant->getVariable("budget");	// total budget for timespan
			$directBudget = $grant->getVariable("direct_budget");	// direct budget for timespan
			$start = $grant->getVariable("start");
			$end = $grant->getVariable("end");
			if ($budget && $start && $end) {
				$startTs = strtotime($start);
				$endTs = strtotime($end);
				if ($startTs && $endTs) {
					for ($year = date("Y", $yearspanStart); strtotime("$year-12-31 23:59:59") <= $yearspanEnd; $year++) {
						if (!isset($this->dollars[$year])) {
							$this->dollars[$year] = array();
							$this->dollars[$year]['summary_grants_total_dollar'] = 0;
							$this->dollars[$year]['summary_grants_direct_dollar'] = 0;
							$this->dollars[$year]['summary_grants_federal'] = 0;
							$this->dollars[$year]['summary_grants_non_federal'] = 0;
							$this->dollars[$year]['summary_grants_internal_k_k12_kl2'] = 0;
							$this->dollars[$year]['summary_grants_indiv_k_k_equiv'] = 0;
							$this->dollars[$year]['summary_grants_r01_r01_equiv'] = 0;
						}
						if ($this->isCalendarYear()) {
							$yearStart = strtotime("$year-01-01 00:00:00");
							$yearEnd = strtotime("$year-12-31 23:59:59");
						} else if ($this->isFederalYear()) {
							$priorYear = $year - 1;
							$yearStart = strtotime("$priorYear-10-01 00:00:00");
							$yearEnd = strtotime("$year-09-31 23:59:59");
						} else {
							# Traditional Academic Fiscal Year
							$priorYear = $year - 1;
							$yearStart = strtotime("$priorYear-07-01 00:00:00");
							$yearEnd = strtotime("$year-06-30 23:59:59");
						}
						$fraction = self::calculateFractionEffort($startTs, $endTs, $yearStart, $yearEnd);
						$this->dollars[$year]['summary_grants_total_dollar'] += $fraction * $budget;
						$this->dollars[$year]['summary_grants_direct_dollar'] += $fraction * $directBudget;
						if ($grant->isFederal()) {
							$this->dollars[$year]['summary_grants_federal'] += $fraction * $directBudget;
						} else {
							$this->dollars[$year]['summary_grants_non_federal'] += $fraction * $directBudget;
						}
						if (($grant->getVariable("type") == "Internal K")
							|| ($grant->getVariable("type") == "K12/KL2")) {
							$this->dollars[$year]['summary_grants_internal_k_k12_kl2'] += $fraction * $directBudget;
						}
						if (($grant->getVariable("type") == "Individual K")
							|| ($grant->getVariable("type") == "K Equivalent")) {
							$this->dollars[$year]['summary_grants_indiv_k_k_equiv'] += $fraction * $directBudget;
						}
						if (($grant->getVariable("type") == "R01")
							|| ($grant->getVariable("type") == "R01 Equivalent")) {
							$this->dollars[$year]['summary_grants_r01_r01_equiv'] += $fraction * $directBudget;
						}
					}
				}
			}
		}
	}

	# given two timestamps (UNIX) $start, $end - let's call this duration.
	# provide the timestamps of a larger period, $yearStart, $yearEnd
	# figures the fraction of the year that is filled by the duraction
	# returns value | 0 <= value <= 1
	private static function calculateFractionEffort($start, $end, $yearStart, $yearEnd) {
		if ($start && $end) {
			$grantDur = $end - $start;
			$yearDur = $yearEnd - $yearStart;
			$currDur = 0;
	
			if (($start >= $yearStart) && ($start <= $yearEnd)) {
				if ($end > $yearEnd) {
					$currDur = $yearEnd - $start;
				} else {
					$currDur = $end - $start;
				}
			} else if (($end >= $yearStart) && ($end <= $yearEnd)) {
				# currStart before yearStart
				$currDur = $end - $yearStart;
			} else if (($end > $yearEnd) && ($start < $yearStart)) {
				$currDur = $yearDur;
			}
			return $currDur / $grantDur;
		}
		return 0;
	}

	public function grantType_test($tester) {
		$this->setupTests();
		foreach ($this->grants->getGrants() as $grant) {
			$tester->assertEqual(get_class($grant), "Grant");
		}
	}

	public function upload_test($tester) {
		$this->setupTests();
		$tester->tag("token");
		$tester->assertNotBlank($this->token);
		$tester->tag("server");
		$tester->assertNotBlank($this->server);

		$this->setupTests();
		$tester->tag("metadata 1");
		$tester->assertNotNull($this->metadata);
		$tester->tag("metadata 2");
		$tester->assertTrue(!empty($this->metadata));
		$tester->tag("dollars");
		$tester->assertTrue(empty($dollars));

		$this->process();
		$tester->tag("dollars not zero for ".$this->recordId);
		$tester->assertNotEqual(count($this->dollars), 0);

		$upload = $this->makeUploadRows();
		$dataUpload = $this->makeDataUploadRows();
		$blankUpload = $this->makeBlankUploadRows($dataUpload);
		$tester->tag("data upload = dollars");
		$tester->assertEqual(count($dataUpload), count($this->dollars));
		$tester->tag("blank upload >= 0");
		$tester->assertTrue(count($blankUpload) >= 0);
		$tester->tag("data + blank = upload");
		$tester->assertEqual(count($dataUpload) + count($blankUpload), count($upload));
		$tester->tag("upload rows not zero");
		$tester->assertNotEqual(count($upload), 0);
	}

	public function setupTests() {
		$records = Download::recordIds($this->token, $this->server);
		$n = rand(0, count($records) - 1);
		$record = $records[$n];

		$grants = new Grants($this->token, $this->server);
		$redcapData = Download::records($this->token, $this->server, array($record));
		$grants->setRows($redcapData);
		$grants->compileGrants();
		$this->setGrants($record, $grants);
	}

	public function makeUploadRows() {
		$data = $this->makeDataUploadRows();
		$blank = $this->makeBlankUploadRows($data);

		$upload = array();
		foreach (array_merge($data, $blank) as $row) {
			$row['summary_grants_complete'] = '2';
			array_push($upload, $row);
		}
		return $upload;
	}

	private function getSummaryGrantsREDCapFields($omit) {
		# blank out nonfunctioning fields => get metadata and process
		$grantsFields = array();
		foreach ($this->metadata as $row) {
			if (preg_match("/^summary_grants_/", $row['field_name']) && (!in_array($row['field_name'], $omit))) {
				array_push($grantsFields, $row['field_name']);
			}
		}
		return $grantsFields;
	}

	private function formatYear($year) {
		if ($this->isCalendarYear()) {
			return "$year";
		} else if ($this->isFiscalYear()) {
			return "FY$year";
		} else if ($this->isFederalYear()) {
			return "FedFY$year";
		}
		return "$year";
	}

	private function makeDataUploadRows() {
		$upload = array();
		$yearField = "summary_grants_year";

		$grantsFields = $this->getSummaryGrantsREDCapFields(array($yearField));


		$instance = 1;
		foreach ($this->dollars as $year => $variables) {
			$formattedYear = $this->formatYear($year);
			$row = array("record_id" => (string) $this->recordId, "redcap_repeat_instrument" => "summary_grants", "redcap_repeat_instance" => "$instance", $yearField => $formattedYear);
			foreach ($grantsFields as $variable) {
				if ($variable != $yearField) {
					$row[$variable] = (string) Grant::convertToMoney($variables[$variable]);
				}
			}
			array_push($upload, $row);
			$instance++;
		}
		return $upload;
	}

	private function makeBlankUploadRows($newDataRows) {
		$yearField = "summary_grants_year";
		$grantsFields = $this->getSummaryGrantsREDCapFields(array($yearField));

		$nextInstance = 1;
		foreach ($newDataRows as $row) {
			if ($row['redcap_repeat_instrument'] == "summary_grants") {
				if ($row['redcap_repeat_instance'] >= $nextInstance) {
					$nextInstance = $row['redcap_repeat_instance'] + 1;
				}
			}
		}

		$upload = array();
		$maxInstance = 0;
		$existingData = Download::records($this->token, $this->server, array($this->recordId));
		foreach ($existingData as $row) {
			if ($row['redcap_repeat_instrument'] == "summary_grants") {
				if ($maxInstance < $row['redcap_repeat_instance']) {
					$maxInstance = $row['redcap_repeat_instance'];
				}
			}
		}

		for ($i = $nextInstance; $i <= $maxInstance; $i++) {
			$row = array("record_id" => (string) $this->recordId, "redcap_repeat_instrument" => "summary_grants", "redcap_repeat_instance" => "$i", $yearField => "");
			foreach ($grantsFields as $variable) {
				if ($variable != $yearField) {
					$row[$variable] = "";
				}
			}
			array_push($upload, $row);
		}

		return $upload;
	}

	public function upload() {
		$upload = $this->makeUploadRows();
		Upload::rows($upload, $this->token, $this->server);
	}

	private $grants;
	private $dollars;
	private $recordId;
	private $token;
	private $server;
	private $metadata;
	private $yearDef;
}
