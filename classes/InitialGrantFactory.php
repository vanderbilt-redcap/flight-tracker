<?php

namespace Vanderbilt\CareerDevLibrary;

# Helper class for GrantFactory.php

require_once(__DIR__ . '/ClassLoader.php');

class InitialGrantFactory extends GrantFactory
{
	public function setPrefix($prefix) {
		$prefix = preg_replace("/_$/", "", $prefix);
		$this->prefix = $prefix;
	}

	public function getAwardFields() {
		$prefix = $this->prefix;
		$fields = [];
		for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
			$fields[] = $prefix.'_grant'.$i.'_number';
		}
		return $fields;
	}

	public function getPIFields() {
		return [];
	}

	# get the Scholars' Survey (always nicknamed check) default spec array
	public function processRow($row, $otherRows, $token = "") {
		$prefix = $this->prefix;
		list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
		for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
			if (($row[$prefix."_grant$i"."_start"] != "")
				&& (
					!isset($row[$prefix."_grant$i"."_notmine"])
					|| ($row[$prefix."_grant$i"."_notmine"] != '1')
				)
				&& in_array($row[$prefix."_grant".$i."_role"], [1, 2])) {

				$url = self::ROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=initial_survey";
				$awardno = $row[$prefix.'_grant'.$i.'_number'];
				$grant = new Grant($this->lexicalTranslator);
				$grant->setVariable("pid", $pid);
				$grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
				$grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
				$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
				$grant->setVariable('start', $row[$prefix.'_grant'.$i.'_start']);
				$grant->setVariable('end', $row[$prefix.'_grant'.$i.'_end']);
				$grant->setVariable('project_start', $row[$prefix.'_grant'.$i.'_start']);
				$grant->setVariable('project_end', $row[$prefix.'_grant'.$i.'_end']);
				if ($prefix == "check") {
					$grant->setVariable('source', "scholars");
				} elseif ($prefix == "init_import") {
					$grant->setVariable('source', "manual");
				}
				$costs = Grant::removeCommas($row[$prefix.'_grant'.$i.'_costs']);
				$grant->setVariable('budget', $costs);
				$grant->setVariable('total_budget', $costs);
				$grant->setVariable('direct_budget', $costs);
				// $grant->setVariable('fAndA', Grants::getFAndA($awardno, $row['check_grant'.$i.'_start']));
				$grant->setVariable('finance_type', Grants::getFinanceType($awardno));
				$grant->setVariable('sponsor', $row[$prefix.'_grant'.$i.'_org']);
				$grant->setVariable('flagged', $row[$prefix.'_grant'.$i.'_flagged'] ?? "");
				$grant->setVariable('url', $url);
				$grant->setVariable('link', Links::makeLink($url, "See Grant"));
				# Co-PI or PI, not Co-I or Other
				if (in_array($row[$prefix.'_grant'.$i.'_role'], [1, 2, ''])) {
					$grant->setVariable('pi_flag', 'Y');
				} else {
					$grant->setVariable('pi_flag', 'N');
				}
				if (empty($this->choices)) {
					$field = $prefix."_grant".$i."_role";
					$fieldChoices = DataDictionaryManagement::getChoicesForField($pid, $field);
					$grant->setVariable("role", $fieldChoices[$row[$field]]);
				} else {
					$grant->setVariable("role", $this->choices[$prefix."_grant".$i."_role"][$row[$prefix."_grant".$i."_role"]]);
				}
				$grant->setNumber($awardno);
				$grant->setVariable("original_award_number", $awardno);
				if (preg_match("/^\d?[A-Z]\d\d/", $awardno, $matches)) {
					$match = preg_replace("/^\d/", "", $matches[0]);
					$grant->setVariable('nih_mechanism', $match);
				}
				$grant->putInBins();
				$grant->setVariable("pis", $this->getPIs($row));
				$this->grants[] = $grant;
			}
		}
	}

	private $prefix = "";
}
