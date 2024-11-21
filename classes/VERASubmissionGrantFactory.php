<?php

namespace Vanderbilt\CareerDevLibrary;

# Helper class for GrantFactory.php

require_once(__DIR__ . '/ClassLoader.php');

class VERASubmissionGrantFactory extends  GrantFactory {
    public function getAwardFields() {
        return [];
    }

    public function getPIFields() {
        return [];
    }

    public function processRow($row, $otherRows, $token = "")
    {
        list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
        $url = self::ROOT . "DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=vera_submission&instance={$row['redcap_repeat_instance']}";
        $awardNo = Grant::$noNameAssigned;

        $role = "";
        if ($row['verasubmission_project_role'] == "Other Professional") {
            return;
        } else if ($row['verasubmission_project_role'] == "PD/PI") {
            $role = "PI";
        } else if ($row['verasubmission_project_role'] == "Co-PD/PI") {
            $role = "Co-PI";
        }
        $directBudget = (int) $row['verasubmission_budget_period_direct'];
        $indirectBudget = (int) $row['verasubmission_budget_period_indirect'];
        $totalBudget = $directBudget + $indirectBudget;

        $grant = new Grant($this->lexicalTranslator);
        $grant->setVariable("pid", $pid);
        $grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
        $grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
        $grant->setVariable("person_name", $row['verasubmission_person_name']);
        $grant->setVariable("role", $role);
        # Their PI-flag field excludes Co-PIs
        $grant->setVariable('pi_flag', in_array($role, ["PI", "Co-PI"]) ? "Y" : "N");

        $grant->setVariable("project_start", $row['verasubmission_project_start_date']);
        $grant->setVariable("project_end", $row['verasubmission_project_end_date']);
        $grant->setVariable("start", $row['verasubmission_project_end_date']);
        $grant->setVariable("end", $row['verasubmission_project_end_date']);
        $grant->setVariable("title", $row['verasubmission_title']);
        $grant->setVariable("budget", $totalBudget);
        $grant->setVariable("total_budget", $totalBudget);
        $grant->setVariable("direct_budget", $row['verasubmission_budget_period_direct']);

        $grant->setVariable('status', $row['verasubmission_status']);
        $proposalType = in_array($row['verasubmission_proposal_type'], ["Resubmission", "Revision"]) ? "Resubmission" : "New";
        $grant->setVariable("proposal_type", $proposalType);
        $grant->setVariable("submission_date", $row['verasubmission_date_created']);
        $grant->setVariable("submission_id", $row['verasubmission_fp_id']);

        $grant->setVariable('sponsor', $row['verasubmission_direct_sponsor_name']);
        $grant->setVariable('sponsor_type', $row['verasubmission_direct_sponsor_type']);

        # blank if same
        $primeSponsorType = $row['verasubmission_prime_sponsor_type'] ?: $row['verasubmission_direct_sponsor_type'];
        $primeSponsorName = $row['verasubmission_prime_sponsor_name'] ?: $row['verasubmission_direct_sponsor_name'];
        $grant->setVariable('prime_sponsor_type', $primeSponsorType);
        $grant->setVariable('prime_sponsor_name', $primeSponsorName);
        $grant->setVariable('direct_sponsor_type', $row['verasubmission_direct_sponsor_type']);
        $grant->setVariable('direct_sponsor_name', $row['verasubmission_direct_sponsor_name']);

        $grant->setNumber($awardNo);
        $grant->setVariable('source', "vera");
        $grant->setVariable('url', $url);
        $grant->setVariable('link', Links::makeLink($url, "See Grant"));

        $grant->setVariable('last_update', $row['verasubmission_last_update']);

        $grant->putInBins();
        $grant->setVariable("pis", $this->getPIs($row));
        $this->grants[] = $grant;
    }
}

