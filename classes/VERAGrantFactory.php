<?php

namespace Vanderbilt\CareerDevLibrary;

# Helper class for GrantFactory.php

require_once(__DIR__ . '/ClassLoader.php');

class VERAGrantFactory extends GrantFactory
{
	public function getAwardFields() {
		return ['vera_direct_sponsor_award_id', 'vera_award_id'];
	}

	public function getPIFields() {
		return [];
	}

	public function processRow($row, $otherRows, $token = "") {
		list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
		$url = self::ROOT . "DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=vera&instance={$row['redcap_repeat_instance']}";
		$awardNo = self::cleanAwardNo($row['vera_direct_sponsor_award_id']);
		if ($awardNo == "") {
			$awardNo = Grant::$noNameAssigned;
		}

		if ($row['vera_personnel_role'] == "PD/PI") {
			$role = "PI";
		} elseif ($row['vera_personnel_role'] == "Co-PD/PI") {
			$role = "Co-PI";
		} else {
			return;
		}

		$grant = new Grant($this->lexicalTranslator);
		$grant->setVariable("pid", $pid);
		$grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
		$grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
		$grant->setVariable("person_name", $row['vera_pi_full_name']);
		$grant->setVariable("role", $role);
		# Their PI-flag field excludes Co-PIs
		$grant->setVariable('pi_flag', in_array($role, ["PI", "Co-PI"]) ? "Y" : "N");

		$grant->setVariable("project_start", $row['vera_project_start_date']);
		$grant->setVariable("project_end", $row['vera_project_end_date']);
		$grant->setVariable("start", $row['vera_budget_allocation_startdate']);
		$grant->setVariable("end", $row['vera_budget_allocation_enddate']);
		$grant->setVariable("title", $row['vera_title']);
		$grant->setVariable("budget", $row['vera_budget_allocation_total']);
		$grant->setVariable("total_budget", $row['vera_budget_allocation_total']);
		$grant->setVariable("direct_budget", $row['vera_budget_allocation_direct_total']);

		$grant->setVariable('sponsor', $row['vera_direct_sponsor_name']);
		$grant->setVariable('sponsor_type', $row['vera_direct_sponsor_type']);

		# blank if same
		$primeSponsorType = $row['vera_prime_sponsor_type'] ?: $row['vera_direct_sponsor_type'];
		$primeSponsorName = $row['vera_prime_sponsor_name'] ?: $row['vera_direct_sponsor_name'];
		$grant->setVariable('prime_sponsor_type', $primeSponsorType);
		$grant->setVariable('prime_sponsor_name', $primeSponsorName);
		$grant->setVariable('direct_sponsor_type', $row['vera_direct_sponsor_type']);
		$grant->setVariable('direct_sponsor_name', $row['vera_direct_sponsor_name']);
		$grant->setVariable("institution", "Vanderbilt University");

		$grant->setNumber($awardNo);
		$grant->setVariable("original_award_number", $row['vera_direct_sponsor_award_id']);
		$grant->setVariable('source', "vera");
		$grant->setVariable('url', $url);
		$grant->setVariable('link', Links::makeLink($url, "See Grant"));
		$grant->setVariable("submission_date", $row['vera_datecreated']);

		if ($row['vera_reporting_award_type_mechanism']) {
			$grant->setVariable('nih_mechanism', $row['vera_reporting_award_type_mechanism']);
		} else {
			$grant->setVariable('nih_mechanism', Grant::getActivityCode($awardNo));
		}
		$grant->setVariable('last_update', $row['vera_last_update']);
		$grant->setVariable('flagged', $row['vera_flagged'] ?? "");

		$grant->putInBins();
		$grant->setVariable("pis", $this->getPIs($row));
		$this->grants[] = $grant;
	}
}
