<?php

namespace Vanderbilt\CareerDevLibrary;

# Helper class for GrantFactory.php

require_once(__DIR__ . '/ClassLoader.php');

class CoeusSubmissionGrantFactory extends GrantFactory
{
	public function getAwardFields() {
		return ['coeussubmission_sponsor_proposal_number'];
	}

	public function getPIFields() {
		return [];
	}

	public function processRow($row, $otherRows, $token = "") {
		list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
		$url = self::ROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=coeus_submission&instance={$row['redcap_repeat_instance']}";
		$grant = new Grant($this->lexicalTranslator);
		$grant->setVariable("pid", $pid);
		$grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
		$grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
		$awardNo = self::cleanAwardNo($row['coeussubmission_sponsor_proposal_number']);
		$grant->setVariable('original_award_number', $row['coeussubmission_sponsor_proposal_number']);
		$grant->setNumber($awardNo);
		$grant->setVariable('person_name', $row['coeussubmission_person_name']);
		$grant->setVariable('project_start', $row['coeussubmission_project_start_date']);
		$grant->setVariable('project_end', $row['coeussubmission_project_end_date']);
		$grant->setVariable('start', $row['coeussubmission_budget_start_date']);
		$grant->setVariable('end', $row['coeussubmission_budget_end_date']);

		$status = $row['coeussubmission_proposal_status'];
		if (preg_match("/Pending/i", $status)) {
			$status = "Pending";
		}
		$grant->setVariable('status', $status);
		$proposalType = in_array($row['coeussubmission_proposal_type'], ["Resubmission", "Revision"]) ? "Resubmission" : "New";
		$grant->setVariable('proposal_type', $proposalType);
		$grant->setVariable("submission_date", $row['coeussubmission_proposal_create_date']);
		$grant->setVariable("submission_id", $row['coeussubmission_ip_number']);

		$grant->setVariable('sponsor', $row['coeussubmission_direct_sponsor_name']);
		$grant->setVariable('sponsor_type', $row['coeussubmission_direct_sponsor_type']);
		$grant->setVariable('prime_sponsor_type', $row['coeussubmission_prime_sponsor_type']);
		$grant->setVariable('prime_sponsor_name', $row['coeussubmission_prime_sponsor_name']);
		$grant->setVariable('direct_sponsor_type', $row['coeussubmission_direct_sponsor_type']);
		$grant->setVariable('direct_sponsor_name', $row['coeussubmission_direct_sponsor_name']);

		$directBudget = (int) $row['coeussubmission_direct_cost_budget_period'];
		$indirectBudget = (int) $row['coeussubmission_indirect_cost_budget_period'];
		$totalBudget = $directBudget + $indirectBudget;
		$grant->setVariable('title', $row['coeussubmission_title']);
		$grant->setVariable('budget', $totalBudget);
		$grant->setVariable('total_budget', $totalBudget);
		$grant->setVariable('direct_budget', $directBudget);

		$grant->setVariable('source', "coeus");
		$grant->setVariable('url', $url);
		$grant->setVariable('link', Links::makeLink($url, "See Grant"));
		$grant->setVariable('percent_effort', $row['coeussubmission_percent_effort']);
		$grant->setVariable('last_update', $row['coeussubmission_last_update']);
		$grant->setVariable('pi_flag', $row['coeussubmission_pi_flag']);
		$grant->setVariable('flagged', $row['coeussubmission_flagged'] ?? "");

		$grant->putInBins();
		$grant->setVariable("pis", $this->getPIs($row));
		$this->grants[] = $grant;
	}
}
