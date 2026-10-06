<?php

namespace Vanderbilt\CareerDevLibrary;

# Helper class for GrantFactory.php

require_once(__DIR__ . '/ClassLoader.php');

class UAMSGrantFactory extends GrantFactory {
	
	public function getAwardFields() {

		return [];
	}

	public function getPIFields() {
		return [];
	}
	
	public function processRow($row, $otherRows, $token = "") {
		
		list($pid, $event_id) = self::getProjectIdentifiers($token);
		$awardNo = $row["muse_number"];// TODO get award number field
		$url = APP_PATH_WEBROOT . "DataEntry/index.php?pid=$pid" .
		"&id={$row['record_id']}&event_id=$event_id" .
		"&page={$row['redcap_repeat_instrument']} " .
		"&instance={$row['redcap_repeat_instance']}";
		$grant = new Grant($this->lexicalTranslator);
		$grant->setNumber($awardNo);
// TODO generate list of assignments from $row, which contains
// this grant’s data from REDCap
		$grant->setVariable("original_award_number", $awardNo);
		$grant->setVariable("person_name", $row['muse_first_name']." ".$row['muse_last_name']);
		$grant->setVariable("title", $row['muse_title']);
		$grant->setVariable("role", $row['flighttracker_role']);
		if($row['muse_role'] == 'PD/PI' or $row['muse_role'] = 'Co-PD/PI'){
			$grant->setVariable("pi_flag", "Y");
		}else{
			$grant->setVariable("pi_flag", "N");
			
		}

		$grant->setVariable("source", "local_gms");
		$grant->setVariable("url", $url);
		$grant->setVariable("project_start", $row['muse_project_start']);
		$grant->setVariable("project_end", $row['muse_project_end']);
		$grant->setVariable("start", $row['muse_project_start']);
		$grant->setVariable("end", $row['muse_project_end']);
		$grant->setVariable("budget", $row['muse_direct_costs']);
		$grant->setVariable("direct_budget", $row['muse_direct_costs']);
		$grant->setVariable("total_budget", $row['muse_costs_total']);
		$grant->setVariable("link", Links::makeLink($url, "See Grant"));
		$grant->setVariable("last_update", $row['muse_last_updated']);

		$grant->putInBins();
		$this->grants[] = $grant;
	}
}