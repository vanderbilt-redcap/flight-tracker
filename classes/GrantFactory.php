<?php

namespace Vanderbilt\CareerDevLibrary;


# This file compiles all of the grants from various data sources and compiles them into an ordered list of grants.
# It should remove duplicate grants as well.
# Gelper classes as well.
# Unit-testable.

require_once(dirname(__FILE__)."/Download.php");
require_once(dirname(__FILE__)."/Links.php");
require_once(APP_PATH_DOCROOT.'/ProjectGeneral/math_functions.php');

define('MAX_GRANTS', 15);
abstract class GrantFactory {
	public function __construct($name, $lexicalTranslator) {
		$this->name = $name;
		$this->lexicalTranslator = $lexicalTranslator;
	}

	public function getGrants() {
		return $this->grants;
	}

	public static function cleanAwardNo($awardNo) {
		$awardNo = preg_replace("/-\d\d[A-Za-z]\d$/", "", $awardNo);
		$awardNo = preg_replace("/-\d[A-Za-z]\d\d$/", "", $awardNo);
		$awardNo = preg_replace("/-\d\d\d\d$/", "", $awardNo);
		return $awardNo;
	}

	abstract public function processRow($row);

	protected $name = "";
	protected $grants = array();
	protected $lexicalTranslator;
}

class ScholarsGrantFactory extends GrantFactory {

	# get the Scholars' Survey (always nicknamed check) default spec array
	public function processRow($row) {
		global $pid, $event_id;
		for ($i=1; $i <= $this->maxGrants; $i++) {
			if ($row["check_grant$i"."_start"] != "") {
				$awardno = $row['check_grant'.$i.'_number'];
				$grant = new Grant($this->lexicalTranslator);
				$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
				$grant->setVariable('start', $row['check_grant'.$i.'_start']);
				$grant->setVariable('end', $row['check_grant'.$i.'_end']);
				$grant->setVariable('source', "scholars");
				$costs = Grant::removeCommas($row['check_grant'.$i.'_costs']);
				$grant->setVariable('budget', $costs);
				$grant->setVariable('direct_budget', Grants::directCostsFromTotal($costs, $awardno, $row['check_grant'.$i.'_start']));
				// $grant->setVariable('fAndA', Grants::getFAndA($awardno, $row['check_grant'.$i.'_start']));
				$grant->setVariable('finance_type', Grants::getFinanceType($awardno));
				$grant->setVariable('sponsor', $row['check_grant'.$i.'_org']);
				$grant->setVariable('link', Links::makeLink(APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=initial_survey", "See Grant"));
				# Co-PI or PI, not Co-I or Other
				if (($row['check_grant'.$i.'_role'] == 1) || ($row['check_grant'.$i.'_role'] == 2) || ($row['check_grant'.$i.'_role'] == '')) {
					$grant->setVariable('pi_flag', 'Y');
				} else {
					$grant->setVariable('pi_flag', 'N');
				}
				$grant->setNumber($awardno);
				$grant->setVariable("original_award_number", $awardno);
				if (preg_match("/^\d?[A-Z]\d\d/", $awardno, $matches)) {
					$match = preg_replace("/^\d/", "", $matches[0]);
					$grant->setVariable('nih_mechanism', $match);
				}
				$grant->putInBins();
				array_push($this->grants, $grant);
			}
		}
	}

	private $maxGrants = MAX_GRANTS;
}

class FollowupGrantFactory extends GrantFactory {
	public function processRow($row) {
		global $pid, $event_id;
		for ($i=1; $i <= $this->maxGrants; $i++) {
			if ($row["followup_grant$i"."_start"] != "") {
				$awardno = $row['followup_grant'.$i.'_number'];

				$grant = new Grant($this->lexicalTranslator);
				$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
				$grant->setVariable('start', $row['followup_grant'.$i.'_start']);
				$grant->setVariable('end', $row['followup_grant'.$i.'_end']);
				$grant->setVariable('source', "scholars");
				$costs = Grant::removeCommas($row['followup_grant'.$i.'_costs']);
				$grant->setVariable('budget', Grants::totalCostsFromDirect($costs, $awardno, $row['followup_grant'.$i.'_start']));
				// $grant->setVariable('fAndA', Grants::getFAndA($awardno, $row['followup_grant'.$i.'_start']));
				$grant->setVariable('finance_type', Grants::getFinanceType($awardno));
				$grant->setVariable('direct_budget', $costs);
				$grant->setVariable('sponsor', $row['followup_grant'.$i.'_org']);
				$grant->setVariable('link', Links::makeLink(APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=followup&instance={$row['redcap_repeat_instance']}", "See Grant"));
				# Co-PI or PI, not Co-I or Other
				if (($row['followup_grant'.$i.'_role'] == 1) || ($row['followup_grant'.$i.'_role'] == 2) || ($row['followup_grant'.$i.'_role'] == '')) {
					$grant->setVariable('pi_flag', 'Y');
				} else {
					$grant->setVariable('pi_flag', 'N');
				}
				$grant->setNumber($awardno);
				$grant->setVariable("original_award_number", $awardno);
				if (preg_match("/^\d?[A-Z]\d\d/", $awardno, $matches)) {
					$match = preg_replace("/^\d/", "", $matches[0]);
					$grant->setVariable('nih_mechanism', $match);
				}
				array_push($this->grants, $grant);
			}
		}
	}

	private $maxGrants = MAX_GRANTS;
}

class NewmanGrantFactory extends GrantFactory {
	public function processRow($row) {
		$this->processNewmanData($row);
		$this->processSheet2($row);
		$this->processNew2017($row);
	}

	private static function addYearsToDate($date, $years) {
		$ts = strtotime($date);
		$yearOfTs = date("Y", $ts);
		$yearOfNewTs = $yearOfTs + $years;
		$restOfTs = date("-m-d", $ts);
		return $yearOfNewTs.$restOfTs;
	}

	private static function getInternalKLength() {
		return 3;
	}

	private static function getExternalKLength() {
		return 5;
	}

	private function processNewmanData($row) {
		global $pid, $event_id;
		$internalKAwardLength = self::getInternalKLength();
		$externalKAwardLength = self::getExternalKLength();

		$ary = array();
		$date1 = "";
		if (!preg_match("/none/", $row['newman_data_date_first_institutional_k_award_newman'])) {
			$date1 = $row['newman_data_date_first_institutional_k_award_newman'];
		}
		if ($date1) {
			foreach (self::getNewmanFirstType($row, "data_internal") as $type) {
				$grant = new Grant($this->lexicalTranslator);
				$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
				$grant->setVariable('pi_flag', "Y");
				$grant->setVariable('start', $date1);
				$grant->setVariable('end', self::addYearsToDate($date1, $internalKAwardLength));
				$grant->setVariable('budget', 0);
				$grant->setVariable('direct_budget', 0);
				$grant->setVariable('source', "data");
				$grant->setVariable('sponsor_type', $type);
				$grant->setVariable('link', Links::makeLink(APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=data", "See Grant"));
				if (preg_match("/K12/", $type) || preg_match("/KL2/", $type)) {
					$grant->setNumber($type);
				} else {
					if ($type) {
						$grant->setNumber($type);
					} else {
						$grant->setNumber("Internal K - Rec. {$row['record_id']}");
					}
				}
				$grant->putInBins();
				array_push($this->grants, $grant);
			}
		}
	
		$date2 = "";
		if (!preg_match("/none/", $row['newman_data_individual_k_start'])) {
			$date2 = $row['newman_data_individual_k_start'];
		}
		if ($date2) {
			foreach (self::getNewmanFirstType($row, "data_individual") as $type) {
				$grant = new Grant($this->lexicalTranslator);
				$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
				$grant->setVariable('pi_flag', "Y");
				$grant->setVariable('source', "data");
				$grant->setVariable('link', Links::makeLink(APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=data", "See Grant"));
				$grant->setVariable('start', $date2);
				$grant->setVariable('end', self::addYearsToDate($date2, $externalKAwardLength));
				$grant->setVariable('budget', 0);
				$grant->setVariable('direct_budget', 0);
				$grant->setVariable('sponsor_type', $type);
				if ($type) {
					$grant->setNumber($specs['sponsor_type']);
				} else {
					$grant->setNumber("Individual K - Rec. {$row['record_id']}");
				}
				$grant->putInBins();
				array_push($this->grants, $grant);
			}
		}
	
		$date3 = "";
		if (!preg_match("/none/", $row['newman_data_r01_start'])) {
			$date3 = $row['newman_data_r01_start'];
		}
		if ($date3) {
			$grant = new Grant($this->lexicalTranslator);
			$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
			$grant->setVariable('pi_flag', "Y");
			$grant->setVariable('start', $date3);
			$grant->setVariable('budget', 0);
			$grant->setVariable('direct_budget', 0);
			$grant->setVariable('source', "data");
			$grant->setVariable('link', Links::makeLink(APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=data", "See Grant"));
			$grant->setVariable('sponsor_type', "R01");
			$grant->setNumber("R01");
			$grant->putInBins();
			array_push($this->grants, $grant);
		}
	}

	# get the first type of a Newman data entry
	private static function getNewmanFirstType($row, $dataSource) {
		$current = "";
		$previous = "";
		if ($dataSource == "data_individual") {
			$previous = $row['newman_data_previous_nih_grant_funding_newman'];
			$current = $row['newman_data_nih_current'];
		} else if ($dataSource == "data_internal") {
			$previous = $row['newman_data_previous_program_funding_newman'];
			$current = $row['newman_data_current_program_funding_newman'];
		} else if ($dataSource == "sheet2_internal") {
			$previous = $row['newman_sheet2_previous_program_funding_2'];
			$current = $row['newman_sheet2_current_program_funding_2'];
		} else if ($dataSource == "sheet2_noninst") {
			$previous = $row['newman_sheet2_previous_funding'];
			$current = $row['newman_sheet2_current_funding'];
		}
		if ((preg_match("/none/", $current) || ($current == "")) && (preg_match("/none/", $previous) || ($previous == ""))) {
			return array();
		} else {
			$previous = preg_replace("/none/", "", $previous);
			$current = preg_replace("/none/", "", $current);
			if ($previous && $current) {
				return self::splitAwards($previous);
			} else if ($previous) {
				return self::splitAwards($previous);
			} else if ($current) {
				return self::splitAwards($current);
			} else {
				// individual K
				return array();
			}
		}
	}

	# splits multiple awards into an array for one Newman data entry
	private static function splitAwards($en) {
		$a = preg_split("/\s*[\|;,]\s*/", $en);
		return $a;
	}


	# sheet 2 into specs
	# sheet 2 is of questionable origin and is the least reliable of the data sources
	# we do not know the origin or author of sheet 2
	private function processSheet2($row) {
		global $pid, $event_id;

		$internalKAwardLength = self::getInternalKLength();
		$externalKAwardLength = self::getExternalKLength();

		$internalKDate = "";
		if (!preg_match("/none/", $row['newman_sheet2_institutional_k_start'])) {
			$internalKDate = $row['newman_sheet2_institutional_k_start'];
		}
		if ($internalKDate) {
			foreach (self::getNewmanFirstType($row, "sheet2_internal") as $type) {
				$grant = new Grant($this->lexicalTranslator);
				$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
				$grant->setVariable('start', $internalKDate);
				$grant->setVariable('end', self::addYearsToDate($internalKDate, $internalKAwardLength));
				$grant->setVariable('source', "sheet2");
				$grant->setVariable('link', Links::makeLink(APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=sheet2", "See Grant"));
				$grant->setVariable('budget', 0);
				$grant->setVariable('direct_budget', 0);
				$grant->setVariable('sponsor_type', $type);
				if (preg_match("/K12/", $type) || preg_match("/KL2/", $type)) {
					$grant->setNumber($type);
				} else {
					if ($specs['sponsor_type']) {
						$grant->setNumber($type);
					} else {
						$grant->setNumber("Internal K - Rec. {$row['record_id']}");
					}
				}
				$grant->setVariable('pi_flag', "Y");
				$grant->putInBins();
				array_push($this->grants, $grant);
			}
		}

		$noninstDate = "";
		if (!preg_match("/none/", $row['newman_sheet2_noninstitutional_start'])) {
			$noninstDate = $row['newman_sheet2_noninstitutional_start'];
		}
		if ($noninstDate) {
			foreach (self::getNewmanFirstType($row, "sheet2_noninst") as $awardno) {
				$grant = new Grant($this->lexicalTranslator);
				$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
				$grant->setVariable('start', $noninstDate);
				$grant->setVariable('end', self::addYearsToDate($noninstDate, $externalKAwardLength));
				$grant->setVariable('source', "sheet2");
				$grant->setVariable('link', Links::makeLink(APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=sheet2", "See Grant"));
				$grant->setVariable('budget', 0);
				$grant->setVariable('direct_budget', 0);
				$grant->setVariable('pi_flag', "Y");
				# for this, the type = the award no
				if (!$awardno) {
					$grant->setNumber("Unknown individual - Rec. {$row['record_id']}");
				} else {
					$grant->setNumber($awardno);
				}
				if (!$row['newman_sheet2_first_r01_date'] || preg_match("/none/", $row['newman_sheet2_first_r01_date']) || !preg_match("/[Rr]01/", $awardno)) {
					$grant->putInBins();
					array_push($this->grants, $grant);
				}
			}
		}
	
		$r01Date = "";
		if (!preg_match("/none/", $row['newman_sheet2_first_r01_date'])) {
			$r01Date = $row['newman_sheet2_first_r01_date'];
		}
		if ($r01Date) {
			$grant = new Grant($this->lexicalTranslator);
			$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
			$grant->setVariable('start', $r01Date);
			$grant->setVariable('pi_flag', "Y");
			$grant->setVariable('source', "sheet2");
			$grant->setVariable('link', Links::makeLink(APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=sheet2", "See Grant"));
			$grant->setVariable('budget', 0);
			$grant->setVariable('direct_budget', 0);

			$previous = $row['newman_sheet2_previous_funding'];
			$current = $row['newman_sheet2_current_funding'];

			if (preg_match("/[Rr]01/", $previous)) {
				$grant->setNumber(self::findR01($previous));
			} else if (preg_match("/[Rr]01/", $current)) {
				$grant->setNumber(self::findR01($current));
			} else {
				$grant->setNumber("R01");
			}
			$grant->putInBins();
			array_push($this->grants, $grant);
		}
	}

	# This puts the new2017 folks into grants
	private function processNew2017($row) {
		global $pid, $event_id;
		$internalKDate = "";

		$internalKAwardLength = self::getInternalKLength();
		$externalKAwardLength = self::getExternalKLength();

		if (!preg_match("/none/", $row['newman_new_first_institutional_k_award'])) {
			$internalKDate = $row['newman_new_first_institutional_k_award'];
		}
		if ($internalKDate) {
			$grant = new Grant($this->lexicalTranslator);
			$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
			$grant->setVariable('start', $internalKDate);
			$grant->setVariable('end', self::addYearsToDate($internalKDate, $internalKAwardLength));
			$grant->setVariable('source', "new2017");
			$grant->setVariable('link', Links::makeLink(APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=new_2017", "See Grant"));
			$grant->setVariable('budget', 0);
			$grant->setVariable('direct_budget', 0);
			$sponsorType = $row["newman_new_current_program_funding"];
			$grant->setVariable('sponsor_type', $sponsorType);
			if ($sponsorType) {
				$grant->setNumber($sponsorType);
			} else {
				$grant->setNumber("Internal K - Rec. {$row['record_id']}");
			}
			$grant->setVariable('pi_flag', "Y");
			$grant->putInBins();
			array_push($this->grants, $grant);
		}
	
		$noninstDate = "";
		if (!preg_match("/none/", $row['newman_new_first_individual_k_award'])) {
			$noninstDate = $row['newman_new_first_individual_k_award'];
		}
		if ($noninstDate) {
			$grant = new Grant($this->lexicalTranslator);
			$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
			$grant->setVariable('start', $noninstDate);
			$grant->setVariable('end', self::addYearsToDate($noninstDate, $externalKAwardLength));
			$grant->setVariable('source', "new2017");
			$grant->setVariable('link', Links::makeLink(APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=new_2017", "See Grant"));
			$grant->setVariable('budget', 0);
			$grant->setVariable('direct_budget', 0);
			$grant->setVariable('pi_flag', "Y");
			# for this, the type = the award no
			$awardno = $row['newman_new_current_nih_funding'];
			if (!$awardno) {
				$grant->setNumber("Unknown individual - Rec. {$row['record_id']}");
			} else {
				$grant->setNumber($awardno);
			}
			$grant->putInBins();
			array_push($this->grants, $grant);
		}
	}

	# finds the R01 out of a compound grant list
	private static function findR01($sn) {
		if (preg_match("/\d[Rr]01\S+/", $sn, $matches)) {
			return $matches[0];
		}
		if (preg_match("/[Rr]01\S+/", $sn, $matches)) {
			return $matches[0];
		}
		return $sn;
	}
}

class CoeusGrantFactory extends GrantFactory {
	public static function cleanAwardNo($awardNo) {
		$awardNo = preg_replace("/^\d\d\d\d\d\d-\d\d\d\s-\s\d\s/", "", $awardNo);
		$awardNo = preg_replace("/^[A-Z][A-Z]\d\d\d\d\d\d\d\d\s-\s\d\s/", "", $awardNo);
		$awardNo = preg_replace("/^[A-Z]\d\d\d\d\d\s-\s\d\s/", "", $awardNo);
		return parent::cleanAwardNo($awardNo);
	}

	public function processRow($row) {
		global $pid, $event_id;
		$grant = new Grant($this->lexicalTranslator);
		$awardNo = self::cleanAwardNo($row['coeus_sponsor_award_number']);
		$grant->setVariable('original_award_number', $row['coeus_sponsor_award_number']);
		if (isset($row['coeus_person_name'])) {
			$grant->setVariable('person_name', $row['coeus_person_name']);
		} else if (isset($row['coeus_principal_investigator'])) {
			$grant->setVariable('person_name', $row['coeus_principal_investigator']);
			$grant->setVariable('vunet', $row['coeus_pi_vunetid']);
		}
		$grant->setVariable('project_start', $row['coeus_project_start_date']);
		$grant->setVariable('project_end', $row['coeus_project_end_date']);
		$grant->setVariable('award_date', $row['coeus_award_create_date']);
		$grant->setVariable('start', $row['coeus_budget_start_date']);
		$grant->setVariable('end', $row['coeus_budget_end_date']);
		$grant->setVariable('finance_type', Grants::getFinanceType($awardNo));
		if (preg_match("/[Kk]12/", $awardNo) && ($row['coeus_pi_flag'] == "N")) {
			$grant->setVariable('budget', '0');
			$grant->setVariable('direct_budget', '0');
		} else {
			$grant->setVariable('budget', $row['coeus_total_cost_budget_period']);
			$grant->setVariable('direct_budget', $row['coeus_direct_cost_budget_period']);
		}
		$grant->setVariable('title', $row['coeus_title']);
		$grant->setVariable('sponsor', $row['coeus_direct_sponsor_name']);
		$grant->setVariable('sponsor_type', $row['coeus_direct_sponsor_type']);

		# used in budgetary calculations
		$grant->setVariable('prime_sponsor_type', $row['coeus_prime_sponsor_type']);
		$grant->setVariable('prime_sponsor_name', $row['coeus_prime_sponsor_name']);
		$grant->setVariable('direct_sponsor_type', $row['coeus_direct_sponsor_type']);
		$grant->setVariable('direct_sponsor_name', $row['coeus_direct_sponsor_name']);

		$grant->setNumber($awardNo);
		$grant->setVariable("original_award_number", $row['coeus_sponsor_award_number']);
		$grant->setVariable('source', "coeus");
		$grant->setVariable('link', Links::makeLink(APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=coeus&instance={$row['redcap_repeat_instance']}", "See Grant"));
		$grant->setVariable('percent_effort', $row['coeus_percent_effort']);
		if ($row['coeus_nih_mechanism']) {
			$grant->setVariable('nih_mechanism', $row['coeus_nih_mechanism']);
		} else {
			$grant->setVariable('nih_mechanism', Grant::getActivityCode($awardNo));
		}
		$grant->setVariable('last_update', $row['coeus_last_update']);
		$grant->setVariable('pi_flag', $row['coeus_pi_flag']);

		$grant->putInBins();
		array_push($this->grants, $grant);
	}
}

class RePORTERGrantFactory extends GrantFactory {
	public function processRow($row) {
		global $pid, $event_id;
		$awardNo = self::cleanAwardNo($row['reporter_projectnumber']);
		$grant = new Grant($this->lexicalTranslator);
		$grant->setVariable('original_award_number', $row['reporter_projectnumber']);
		$grant->setVariable('person_name', $row['reporter_contactpi']);
		$grant->setVariable('start', self::getReporterDate($row['reporter_budgetstartdate']));
		$grant->setVariable('end', self::getReporterDate($row['reporter_budgetenddate']));
		$grant->setVariable('project_start', self::getReporterDate($row['reporter_projectstartdate']));
		$grant->setVariable('project_end', self::getReporterDate($row['reporter_projectenddate']));
		$grant->setVariable('title', $row['reporter_title']);
		$grant->setVariable('budget', $row['reporter_totalcostamount']);
		$grant->setVariable('direct_budget', Grants::directCostsFromTotal($row['reporter_totalcostamount'], $awardNo, self::getReporterDate($row['reporter_budgetstartdate'])));
		// $grant->setVariable('fAndA', Grants::getFAndA($awardNo, self::getReporterDate($row['reporter_budgetstartdate'])));
		$grant->setVariable('finance_type', Grants::getFinanceType($awardNo));
		$grant->setVariable('sponsor', $row['reporter_agency']);
		$grant->setVariable('sponsor_type', $row['reporter_agency']);
		$grant->setVariable('last_update', $row['reporter_last_update']);
		$grant->setNumber($awardNo);
		$grant->setVariable('nih_mechanism', Grant::getActivityCode($awardNo));
		$grant->setVariable('source', "reporter");
		$grant->setVariable('link', Links::makeLink(APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=reporter&instance={$row['redcap_repeat_instance']}", "See Grant"));
		$grant->setVariable('pi_flag', "Y");

		$grant->putInBins();
		array_push($this->grants, $grant);
	}

	# gets the date from a RePORTER formatting (YYYY-MM-DDThh:mm:ss);
	# returns YYYY-MM-DD
	public static function getReporterDate($dt) {
		if (!$dt) {
			return "";
		}
		$nodes = preg_split("/T/", $dt);
		if (count($nodes) != 2) {
			return $nodes[0];
		}
		return $nodes[0];
	}
}

class ExPORTERGrantFactory extends GrantFactory {
	public function processRow($row) {
		global $pid, $event_id;
		$grant = new Grant($this->lexicalTranslator);
		$grant->setVariable('person_name', $row['exporter_pi_names']);
		$grant->setVariable('start', RePORTERGrantFactory::getReporterDate($row['exporter_budget_start']));
		$grant->setVariable('end', RePORTERGrantFactory::getReporterDate($row['exporter_budget_end']));
		$grant->setVariable('project_start', RePORTERGrantFactory::getReporterDate($row['exporter_project_start']));
		$grant->setVariable('project_end', RePORTERGrantFactory::getReporterDate($row['exporter_project_end']));
		$grant->setVariable('title', $row['exporter_project_title']);
		$grant->setVariable('budget', $row['exporter_total_cost']);
		$grant->setVariable('direct_budget', $row['exporter_direct_cost_amt']);
		$grant->setVariable('sponsor', $row['exporter_ic_name']);
		$grant->setVariable('sponsor_type', $row['exporter_ic_name']);
		$grant->setVariable('original_award_number', $row['exporter_full_project_num']);
		$awardNo = self::cleanAwardNo($row['exporter_full_project_num']);
		$grant->setVariable('finance_type', Grants::getFinanceType($awardNo));
		$grant->setNumber($awardNo);
		$grant->setVariable('source', "exporter");
		$grant->setVariable('nih_mechanism', Grant::getActivityCode($awardNo));
		$grant->setVariable('link', Links::makeLink(APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=exporter&instance={$row['redcap_repeat_instance']}", "See Grant"));
		$grant->setVariable('pi_flag', "Y");
		$grant->setVariable('last_update', $row['exporter_last_update']);

		$grant->putInBins();
		array_push($this->grants, $grant);
	}
}

class CustomGrantFactory extends GrantFactory {
	public function processRow($row) {
		global $pid, $event_id;
		$awardNo = self::cleanAwardNo($row['custom_number']);

		$grant = new Grant($this->lexicalTranslator);
		$grant->setVariable('start', $row['custom_start']);
		$grant->setVariable('end', $row['custom_end']);
		$grant->setVariable('title', $row['custom_title']);
		$grant->setVariable('budget', Grants::totalCostsFromDirect($row['custom_costs'], $awardNo, $row['custom_start']));
		// $grant->setVariable('fAndA', Grants::getFAndA($awardNo, $row['custom_start']));
		$grant->setVariable('finance_type', Grants::getFinanceType($awardNo));
		$grant->setVariable('direct_budget', $row['custom_costs']);
		$grant->setVariable('sponsor', $row['custom_org']);
		$grant->setVariable('original_award_number', $row['custom_number']);
		$grant->setNumber($awardNo);
		$grant->setVariable('source', "custom");
		if (($row['custom_role'] == 1) || ($row['custom_role'] == 2) || ($row['custom_role'] == '')) {
			$grant->setVariable('pi_flag', 'Y');
		} else {
			$grant->setVariable('pi_flag', 'N');
		}
		$grant->setVariable('nih_mechanism', Grant::getActivityCode($awardNo));
		$grant->setVariable('link', Links::makeLink(APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=custom_grant&instance={$row['redcap_repeat_instance']}", "See Grant"));
		$grant->setVariable('last_update', $row['custom_last_update']);

		$type = $row['custom_type'];
		$reverseAwardTypes = Grant::getReverseAwardTypes();
		if ($reverseAwardTypes[$type]) {
			$grant->setVariable("type", $reverseAwardTypes[$type]);
		} else {
			$grant->putInBins();
		}
		array_push($this->grants, $grant);
	}
}

class PriorGrantFactory extends GrantFactory {
	public function processRow($row) {
		global $pid, $event_id;
		for ($i = 1; $i <= MAX_GRANTS; $i++) {
			if ($row['summary_award_date_'.$i]) {
				$grant = new Grant($this->lexicalTranslator);
				$grant->setVariable('start', $row['summary_award_date_'.$i]);
				$grant->setVariable('end', $row['summary_award_end_date_'.$i]);
				$grant->setVariable('last_update', $row['summary_award_last_update_'.$i]);
				$grant->setVariable('title', $row['summary_award_title_'.$i]);
				$grant->setVariable('budget', $row['summary_award_total_budget_'.$i]);
				$grant->setVariable('direct_budget', $row['summary_award_direct_budget_'.$i]);
				$grant->setNumber($row['summary_award_sponsorno_'.$i]);
				$grant->setVariable('source', $row['summary_award_source_'.$i]);
				$grant->setVariable('age', $row['summary_award_age_'.$i]);
				$grant->setVariable('pi_flag', 'Y');
				$grant->setVariable('nih_mechanism', $row['summary_award_nih_mechanism_'.$i]);
				$grant->setVariable('percent_effort', $row['summary_award_percent_effort_'.$i]);
				$grant->setVariable('link', Links::makeLink(APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=summary#summary_award_date_".$i, "See Grant"));
				$grant->setVariable('last_update', $row['summary_last_calculated']);
		
				$type = $row['summary_award_type_'.$i];
				$reverseAwardTypes = Grant::getReverseAwardTypes();
				if ($reverseAwardTypes[$type]) {
					$grant->setVariable("type", $reverseAwardTypes[$type]);
				} else {
					$grant->putInBins();
				}
				array_push($this->grants, $grant);
			}
		}
	}
}
