<?php

namespace Vanderbilt\CareerDevLibrary;

# Helper class for GrantFactory.php

require_once(__DIR__ . '/ClassLoader.php');

class CoeusGrantFactory extends GrantFactory {
    public function getAwardFields() {
        return ['coeus_award_no', 'coeus_sponsor_award_number'];
    }

    public function getPIFields() {
        return [];
    }

    public static function cleanAwardNo($awardNo) {
        $awardNo = preg_replace("/^\d\d\d\d\d\d-\d\d\d\s-\s\d\s/", "", $awardNo);
        $awardNo = preg_replace("/^[A-Z][A-Z]\d\d\d\d\d\d\d\d\s-\s\d\s/", "", $awardNo);
        $awardNo = preg_replace("/^[A-Z]\d\d\d\d\d\s-\s\d\s/", "", $awardNo);
        return parent::cleanAwardNo($awardNo);
    }

    public function processRow($row, $otherRows, $token = "") {
        list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
        $url = self::ROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=coeus&instance={$row['redcap_repeat_instance']}";
        $grant = new Grant($this->lexicalTranslator);
        $grant->setVariable("pid", $pid);
        $grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
        $grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
        $awardNo = self::cleanAwardNo($row['coeus_sponsor_award_number']);
        $grant->setVariable('original_award_number', $row['coeus_sponsor_award_number']);
        $grant->setVariable("submission_date", $row['coeus_award_create_date']);

        $isSubproject = preg_match("/VUMC\d/", $awardNo) ? TRUE : FALSE;
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
        $grant->setVariable('subproject', $isSubproject);
        if (preg_match("/[Kk]12/", $awardNo) && !in_array($row['coeus_pi_flag'], ["N", "0"])) {
            $grant->setVariable('budget', '0');
            $grant->setVariable('total_budget', '0');
            $grant->setVariable('direct_budget', '0');
            $grant->setVariable("role", "");
        } else {
            # TODO takes about 1 second per record to complete
            // if ($role = $this->extractFromOtherSources($otherRows, ["coeus"], "role", $awardNo)) {
            // $grant->setVariable("role", $role);
            // }
            if ($row['coeus_multi_pi_flag'] === "0") {
                $grant->setVariable("role", "PI");
            } else if ($row['coeus_multi_pi_flag'] === "1") {
                $grant->setVariable("role", "Co-PI");
            } else {
                $grant->setVariable("role", self::$defaultRole);
            }
            $grant->setVariable('budget', $row['coeus_total_cost_budget_period']);
            $grant->setVariable('total_budget', $row['coeus_total_cost_budget_period']);
            $grant->setVariable('direct_budget', $row['coeus_direct_cost_budget_period']);
        }
        $grant->setVariable('title', $row['coeus_title']);
        $grant->setVariable('sponsor', $row['coeus_direct_sponsor_name']);
        $grant->setVariable('sponsor_type', $row['coeus_direct_sponsor_type']);
        $grant->setVariable("institution", "Vanderbilt University Medical Center");

        # used in budgetary calculations
        $grant->setVariable('prime_sponsor_type', $row['coeus_prime_sponsor_type']);
        $grant->setVariable('prime_sponsor_name', $row['coeus_prime_sponsor_name']);
        $grant->setVariable('direct_sponsor_type', $row['coeus_direct_sponsor_type']);
        $grant->setVariable('direct_sponsor_name', $row['coeus_direct_sponsor_name']);

        $grant->setNumber($awardNo);
        $grant->setVariable("original_award_number", $row['coeus_sponsor_award_number']);
        $grant->setVariable('source', "coeus");
        $grant->setVariable('url', $url);
        $grant->setVariable('link', Links::makeLink($url, "See Grant"));
        $grant->setVariable('percent_effort', $row['coeus_percent_effort']);
        if ($row['coeus_nih_mechanism']) {
            $grant->setVariable('nih_mechanism', $row['coeus_nih_mechanism']);
        } else {
            $grant->setVariable('nih_mechanism', Grant::getActivityCode($awardNo));
        }
        $grant->setVariable('last_update', $row['coeus_last_update']);
        $grant->setVariable('flagged', $row['coeus_flagged'] ?? "");
        $grant->setVariable('pi_flag', $row['coeus_pi_flag']);

        $grant->putInBins();
        $grant->setVariable("pis", $this->getPIs($row));
        $this->grants[] = $grant;
    }
}

