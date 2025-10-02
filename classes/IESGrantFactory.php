<?php

namespace Vanderbilt\CareerDevLibrary;

# Helper class for GrantFactory.php

require_once(__DIR__ . '/ClassLoader.php');

class IESGrantFactory extends GrantFactory
{
	public function getAwardFields() {
		return ['ies_awardnum'];
	}

	public function getPIFields() {
		return [];
	}

	public function processRow($row, $otherRows, $token = "") {
		list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
		$url = self::ROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=nsf&instance={$row['redcap_repeat_instance']}";
		$awardNo = $row['ies_awardnum'];
		$dollars = $row['ies_awardamt'];

		$grant = new Grant($this->lexicalTranslator);
		$grant->setVariable("pid", $pid);
		$grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
		$grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
		$grant->setVariable('start', $row['ies_start']);
		$grant->setVariable('project_start', $row['ies_start']);
		$grant->setVariable('end', $row['ies_end']);
		$grant->setVariable('project_end', $row['ies_end']);
		$grant->setVariable('title', $row['ies_title']);
		$grant->setVariable('budget', $dollars);
		$grant->setVariable('total_budget', $dollars);
		$grant->setVariable('direct_budget', $dollars);
		$grant->setVariable('sponsor', $row['ies_centername']);
		$grant->setVariable('original_award_number', $awardNo);
		$grant->setNumber($awardNo);
		$grant->setVariable('source', "ies");
		$grant->setVariable('pi_flag', 'Y');
		$grant->setVariable("institution", $row['ies_principalaffiliationname']);

		$grant->putInBins();

		$grant->setVariable("role", "PI");    // Co-PIs are not currently listed
		$grant->setVariable('url', $url);
		$grant->setVariable('link', Links::makeLink($url, "See Grant"));
		$grant->setVariable('last_update', $row['ies_last_update']);
		$grant->setVariable('flagged', $row['ies_flagged'] ?? "");

		$grant->setVariable("pis", $this->getPIs($row));
		$this->grants[] = $grant;
	}
}
