<?php

namespace Vanderbilt\CareerDevLibrary;

# Helper class for GrantFactory.php

require_once(__DIR__ . '/ClassLoader.php');

class ExPORTERGrantFactory extends GrantFactory {
    public function getAwardFields() {
        return ['exporter_full_project_num'];
    }

    public function getPIFields()
    {
        return ["exporter_pi_names"];
    }

    public function processRow($row, $otherRows, $token = "")
    {
        $totalCosts = $row['exporter_total_cost'];
        if (!$totalCosts) {
            $totalCosts = $row['exporter_total_cost_sub_project'];
        }
        $isSubproject = ($row['exporter_subproject_id'] != "");
        list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
        $url = self::ROOT . "DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=exporter&instance={$row['redcap_repeat_instance']}";
        $awardNo = self::cleanAwardNo($row['exporter_full_project_num']);
        $grant = new Grant($this->lexicalTranslator);
        $grant->setVariable("pid", $pid);
        $grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
        $grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
        $grant->setVariable('person_name', $row['exporter_pi_names']);
        $grant->setVariable('start', RePORTERGrantFactory::getReporterDate($row['exporter_budget_start']));
        $grant->setVariable('end', RePORTERGrantFactory::getReporterDate($row['exporter_budget_end']));
        $grant->setVariable('project_start', RePORTERGrantFactory::getReporterDate($row['exporter_project_start']));
        $grant->setVariable('project_end', RePORTERGrantFactory::getReporterDate($row['exporter_project_end']));
        $grant->setVariable('title', $row['exporter_project_title']);
        $grant->setVariable('budget', $totalCosts);
        $grant->setVariable('total_budget', $totalCosts);
        $grant->setVariable('direct_budget', $row['exporter_direct_cost_amt']);
        $grant->setVariable('sponsor', $row['exporter_ic_name']);
        $grant->setVariable('sponsor_type', $row['exporter_ic_name']);
        $grant->setVariable('original_award_number', $row['exporter_full_project_num']);
        $grant->setVariable('finance_type', Grants::getFinanceType($awardNo));
        $grant->setNumber($awardNo);
        $grant->setVariable('source', "exporter");
        $grant->setVariable('nih_mechanism', Grant::getActivityCode($awardNo));
        $grant->setVariable('url', $url);
        $grant->setVariable('link', Links::makeLink($url, "See Grant"));
        $grant->setVariable('pi_flag', "Y");
        $grant->setVariable('subproject', $isSubproject);
        $grant->setVariable('last_update', $row['exporter_last_update']);
        $grant->setVariable('flagged', $row['exporter_flagged'] ?? "");

        $numNodes = self::numNodes("/\s*;\s*/", $row['exporter_pi_names']);
        if ($numNodes == 1) {
            $grant->setVariable("role", "PI");
        } else if ($numNodes > 1) {
            $grant->setVariable("role", "Co-PI");
        } else {    // 0
            $grant->setVariable("role", "");
        }

        $grant->putInBins();
        $grant->setVariable("pis", $this->getPIs($row));
        $this->grants[] = $grant;
    }
}

