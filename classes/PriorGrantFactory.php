<?php

namespace Vanderbilt\CareerDevLibrary;

# Helper class for GrantFactory.php

require_once(__DIR__ . '/ClassLoader.php');

class PriorGrantFactory extends GrantFactory
{
	public function getAwardFields() {
		$fields = [];
		for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
			$fields[] = 'summary_award_sponsorno_'.$i;
		}
		return $fields;
	}

	public function getPIFields() {
		return [];
	}

	public function processRow($row, $otherRows, $token = "") {
		list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
		for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
			if (isset($row['summary_award_date_'.$i]) && $row['summary_award_date_'.$i]) {
				$grant = new Grant($this->lexicalTranslator);
				$grant->setVariable("pid", $pid);
				$grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
				$grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
				$url = self::ROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=summary#summary_award_date_".$i;
				$grant->setVariable('start', $row['summary_award_date_'.$i]);
				$grant->setVariable('end', $row['summary_award_end_date_'.$i]);
				$grant->setVariable('project_start', $row['summary_award_date_'.$i]);
				$grant->setVariable('project_end', $row['summary_award_end_date_'.$i]);
				$grant->setVariable('last_update', $row['summary_award_last_update_'.$i]);
				$grant->setVariable('title', $row['summary_award_title_'.$i]);
				$grant->setVariable('budget', $row['summary_award_total_budget_'.$i]);
				$grant->setVariable('total_budget', $row['summary_award_total_budget_'.$i]);
				$grant->setVariable('direct_budget', $row['summary_award_direct_budget_'.$i]);
				$grant->setNumber($row['summary_award_sponsorno_'.$i]);
				$grant->setVariable('source', $row['summary_award_source_'.$i]);
				$grant->setVariable('age', $row['summary_award_age_'.$i]);
				$grant->setVariable('pi_flag', 'Y');
				$grant->setVariable('role', $row['summary_award_role_'.$i]);
				$grant->setVariable('nih_mechanism', $row['summary_award_nih_mechanism_'.$i]);
				$grant->setVariable('percent_effort', $row['summary_award_percent_effort_'.$i]);
				$grant->setVariable('url', $url);
				$grant->setVariable('link', Links::makeLink($url, "See Grant"));
				$grant->setVariable('last_update', $row['summary_last_calculated']);

				$type = $row['summary_award_type_'.$i];
				$reverseAwardTypes = Grant::getReverseAwardTypes();
				if ($reverseAwardTypes[$type]) {
					$grant->setVariable("type", $reverseAwardTypes[$type]);
				} else {
					$grant->putInBins();
				}
				$grant->setVariable("pis", $this->getPIs($row));
				$this->grants[] = $grant;
			}
		}
	}
}
