<?php

namespace Vanderbilt\CareerDevLibrary;

# Helper class for GrantFactory.php

require_once(__DIR__ . '/ClassLoader.php');

class NSFGrantFactory extends GrantFactory
{
	public function getAwardFields() {
		return ['nsf_id'];
	}

	public function getPIFields() {
		return ["nsf_copdpi", "nsf_pdpiname"];
	}

	public function processRow($row, $otherRows, $token = "") {
		list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
		$url = self::ROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=nsf&instance={$row['redcap_repeat_instance']}";
		$awardNo = $row['nsf_id'];
		$dollars = $row['nsf_estimatedtotalamt'];
		$title = $row['nsf_title'];

		$grant = new Grant($this->lexicalTranslator);
		$grant->setVariable("pid", $pid);
		$grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
		$grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
		$grant->setVariable('start', $row['nsf_startdate']);
		$grant->setVariable('project_start', $row['nsf_startdate']);
		$grant->setVariable('end', $row['nsf_expdate']);
		$grant->setVariable('project_end', $row['nsf_expdate']);
		$grant->setVariable('title', $title);
		$grant->setVariable('budget', $dollars);
		$grant->setVariable('total_budget', $dollars);
		$grant->setVariable('direct_budget', $dollars);
		$grant->setVariable('sponsor', $row['nsf_agency']);
		$grant->setVariable("institution", $row['nsf_awardeename']);
		$grant->setVariable('original_award_number', $awardNo);
		$grant->setNumber($awardNo);
		$grant->setVariable('source', "nsf");
		$grant->setVariable('pi_flag', 'Y');

		$grant->putInBins();

		list($firstName, $lastName) = NameMatcher::splitName($this->name, 2);
		$role = "";
		if (NameMatcher::matchName($firstName, $lastName, $row['nsf_pifirstname'], $row['nsf_pilastname'])) {
			$role = "PI";
		} else {
			$coPIs = preg_split("/\s*[,;]\s*/", $row['nsf_copdpi']);
			foreach ($coPIs as $coPI) {
				$coPI = trim($coPI);
				list($coPIFirst, $coPILast) = NameMatcher::splitName($coPI, 2);
				if (NameMatcher::matchName($firstName, $lastName, $coPIFirst, $coPILast)) {
					$role = "Co-PI";
					break;
				}
			}
		}
		$grant->setVariable("role", $role);
		$grant->setVariable('nih_mechanism', $row['nsf_agency']);
		$grant->setVariable('url', $url);
		$grant->setVariable('link', Links::makeLink($url, "See Grant"));
		$grant->setVariable('last_update', $row['nsf_last_update']);
		$grant->setVariable('flagged', $row['nsf_flagged'] ?? "");

		$grant->setVariable("pis", $this->getPIs($row));
		$this->grants[] = $grant;
	}
}
