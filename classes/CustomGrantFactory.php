<?php

namespace Vanderbilt\CareerDevLibrary;

# Helper class for GrantFactory.php

require_once(__DIR__ . '/ClassLoader.php');

class CustomGrantFactory extends GrantFactory
{
	public function getAwardFields() {
		return ['custom_number'];
	}

	public function getPIFields() {
		return [];
	}

	public function __construct($name, $lexicalTranslator, $metadata, $type = "Grant", $token = "", $server = "") {
		parent::__construct($name, $lexicalTranslator, $metadata, $token, $server);
		$this->type = $type;
	}

	public function processRow($row, $otherRows, $token = "") {
		list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
		$url = self::ROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=custom_grant&instance={$row['redcap_repeat_instance']}";
		$awardNo = self::cleanAwardNo($row['custom_number']);
		$directCosts = $row['custom_costs'];
		if (isset($row['custom_costs_total']) && $row['custom_costs_total']) {
			$totalCosts = $row['custom_costs_total'];
			if (!$directCosts) {
				$directCosts = Grants::directCostsFromTotal($totalCosts, $awardNo, $row['custom_start']);
			}
		} else {
			if (REDCapManagement::hasValue($directCosts)) {
				$totalCosts = Grants::totalCostsFromDirect($directCosts, $awardNo, $row['custom_start']);
			} else {
				$totalCosts = '';
			}
		}

		$grant = new Grant($this->lexicalTranslator);
		$grant->setVariable("pid", $pid);
		$grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
		$grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
		$grant->setVariable('start', $row['custom_start']);
		$grant->setVariable('end', $row['custom_end']);
		$grant->setVariable('project_start', $row['custom_start']);
		$grant->setVariable('project_end', $row['custom_end']);
		$grant->setVariable('title', $row['custom_title']);
		$grant->setVariable('budget', $totalCosts);
		$grant->setVariable('total_budget', $totalCosts);
		// $grant->setVariable('fAndA', Grants::getFAndA($awardNo, $row['custom_start']));
		$grant->setVariable('finance_type', Grants::getFinanceType($awardNo));
		$grant->setVariable('direct_budget', $directCosts);
		$grant->setVariable('sponsor', $row['custom_org']);
		$grant->setVariable("submission_date", $row['custom_submission_date'] ?? "");
		$grant->setVariable('original_award_number', $row['custom_number']);
		$grant->setNumber($awardNo);
		$grant->setVariable('source', "custom");
		if (in_array($row['custom_role'], [1, 2, ''])) {
			$grant->setVariable('pi_flag', 'Y');
			$type = $row['custom_type'];
			$reverseAwardTypes = Grant::getReverseAwardTypes();
			if ($type && isset($reverseAwardTypes[$type]) && $reverseAwardTypes[$type]) {
				$grant->setVariable("type", $reverseAwardTypes[$type]);
			} else {
				$grant->putInBins();
			}
		} else {
			$grant->setVariable('pi_flag', 'N');
			$grant->putInBins();
		}
		if (empty($this->choices)) {
			$field = "custom_role";
			$fieldChoices = DataDictionaryManagement::getChoicesForField($pid, $field);
			$grant->setVariable("role", $fieldChoices[$row[$field]]);
		} elseif ($row['custom_role'] !== "") {
			$grant->setVariable("role", $this->choices["custom_role"][$row["custom_role"]]);
		}
		$grant->setVariable('nih_mechanism', Grant::getActivityCode($awardNo));
		$grant->setVariable('url', $url);
		$grant->setVariable('link', Links::makeLink($url, "See Grant"));
		$grant->setVariable('last_update', $row['custom_last_update']);
		$grant->setVariable('flagged', $row['custom_flagged'] ?? "");

		if (in_array($this->type, ["Grant", "Grants"]) && ($row['custom_is_submission'] != "1")) {
			$grant->setVariable("pis", $this->getPIs($row));
			$this->grants[] = $grant;
		} elseif (in_array($this->type, ["Submission", "Submissions"]) && ($row['custom_is_submission'] == "1")) {
			$statusIdx = $row['custom_submission_status'];
			$status = $this->choices['custom_submission_status'][$statusIdx] ?? "";
			$proposalType = $row['custom_resubmission_date'] ? "Resubmission" : "New";

			$grant->setVariable("status", $status);
			$grant->setVariable("proposal_type", $proposalType);
			$grant->setVariable("submission_id", $awardNo);

			$grant->setVariable("pis", $this->getPIs($row));
			$this->grants[] = $grant;
		}
	}

	protected $type = "";
}
