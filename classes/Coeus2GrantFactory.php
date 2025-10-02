<?php

namespace Vanderbilt\CareerDevLibrary;

# Helper class for GrantFactory.php

require_once(__DIR__ . '/ClassLoader.php');

class Coeus2GrantFactory extends CoeusGrantFactory
{
	public function __construct($name, $lexicalTranslator, $metadata, $type = "Grant", $token = "", $server = "") {
		parent::__construct($name, $lexicalTranslator, $metadata, $token, $server);
		$this->type = $type;
	}

	public function getAwardFields() {
		return ['coeus2_agency_grant_number', 'coeus2_award_status'];
	}

	public function getPIFields() {
		return ['coeus2_collaborators'];
	}

	public function processRow($row, $otherRows, $token = "") {
		$addGrant = false;
		if (in_array($this->type, ["Grant", "Grants"])) {
			$addGrant = ($row['coeus2_award_status'] == "Awarded");
		} elseif (in_array($this->type, ["Submissions", "Submission"])) {
			$addGrant = ($row['coeus2_award_status'] != "Awarded");
		} else {
			throw new \Exception("Improper type ".$this->type);
		}
		if ($addGrant) {
			list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
			$url = self::ROOT . "DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=coeus2&instance={$row['redcap_repeat_instance']}";
			$awardNo = self::cleanAwardNo($row['coeus2_agency_grant_number']);
			$choices = REDCapManagement::getChoices($this->metadata);
			$grant = new Grant($this->lexicalTranslator);
			$grant->setVariable("pid", $pid);
			$grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
			$grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
			$roleText = $choices['coeus2_role'][$row['coeus2_role']];
			if ($awardNo == '000') {
				$awardNo = Grant::$noNameAssigned;
			}

			$grant->setNumber($awardNo);
			$grant->setVariable('source', "coeus2");
			$grant->setVariable('original_award_number', $row['coeus2_agency_grant_number']);
			$grant->setVariable('sponsor_type', $row['coeus2_agency_name']);
			$grant->setVariable('person_name', $this->name);
			$grant->setVariable('start', REDCapManagement::datetime2Date($row['coeus2_current_period_start']));
			$grant->setVariable('end', REDCapManagement::datetime2Date($row['coeus2_current_period_end']));
			$grant->setVariable('title', $row['coeus2_title']);
			$grant->setVariable('budget', $row['coeus2_current_period_total_funding']);
			$grant->setVariable('total_budget', $row['coeus2_current_period_total_funding']);
			$grant->setVariable('direct_budget', $row['coeus2_current_period_direct_funding']);
			$grant->setVariable('last_update', $row['coeus2_last_update']);
			$grant->setVariable('flagged', $row['coeus2_flagged'] ?? "");
			$grant->setVariable('pi_flag', ($roleText == "Principal Investigator") ? "Y" : "N");
			$grant->setVariable('finance_type', Grants::getFinanceType($awardNo));
			$grant->setVariable('nih_mechanism', Grant::getActivityCode($awardNo));
			$grant->setVariable("status", $row['coeus2_award_status']);
			$grant->setVariable("submission_date", $row['coeus2_in_progress']);
			$grant->setVariable("submission_id", $row['coeus2_id']);
			$grant->setVariable('url', $url);
			$grant->setVariable('link', Links::makeLink($url, "See Grant"));
			if ($roleText == "Principal Investigator") {
				$grant->setVariable("role", "PI");
			} elseif ($roleText == "Investigator") {
				$grant->setVariable("role", "Co-I");
			} else {
				$grant->setVariable("role", $roleText);
			}

			$grant->putInBins();
			// Application::log("Coeus2GrantFactory adding ".json_encode($grant->toArray()));
			$grant->setVariable("pis", $this->getPIs($row));
			$this->grants[] = $grant;
		}
	}

	protected $type = "Grant";
}
