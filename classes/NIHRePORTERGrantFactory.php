<?php

namespace Vanderbilt\CareerDevLibrary;

# Helper class for GrantFactory.php

require_once(__DIR__ . '/ClassLoader.php');

class NIHRePORTERGrantFactory extends  GrantFactory {
    public function getAwardFields() {
        return ['nih_project_num'];
    }

    public function getPIFields()
    {
        return ["nih_principal_investigators"];
    }

    public function processRow($row, $otherRows, $token = "")
    {
        list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
        $url = self::ROOT . "DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=nih_reporter&instance={$row['redcap_repeat_instance']}";
        $awardNo = self::cleanAwardNo($row['nih_project_num']);
        $isSubproject = ($row['nih_subproject_id'] !== "");
        $grant = new Grant($this->lexicalTranslator);
        $grant->setVariable("pid", $pid);
        $grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
        $grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
        $grant->setVariable('person_name', $row['nih_principal_investigators'] ?? $row['nih_contact_pi_name']);
        $grant->setVariable('project_start', $row['nih_project_start_date']);
        $grant->setVariable('project_end', $row['nih_project_end_date']);
        list ($budgetStartDate, $budgetEndDate) = self::calculateBudgetDates($row['nih_project_start_date'], $row['nih_project_end_date'], $row['nih_award_notice_date']);
        $grant->setVariable('start', $row['nih_budget_start'] ?: $budgetStartDate);
        $grant->setVariable('end', $row['nih_budget_end'] ?: $budgetEndDate);
        $grant->setVariable('title', $row['nih_project_title']);
        $grant->setVariable('budget', $row['nih_award_amount'] ?: $row['nih_direct_cost_amt']);
        $grant->setVariable('direct_budget', $row['nih_direct_cost_amt']);
        $grant->setVariable('total_budget', $row['nih_award_amount']);
        $grant->setVariable('sponsor', $row['nih_agency_ic_admin']);
        $grant->setVariable('sponsor_type', $row['nih_agency_ic_admin']);
        $grant->setVariable('original_award_number', $row['nih_project_num']);
        $grant->setVariable('finance_type', Grants::getFinanceType($awardNo));
        $grant->setVariable('subproject', $isSubproject);
        $grant->setVariable("institution", ucwords(strtolower($row['nih_org_name'])));
        $grant->setNumber($awardNo);
        $grant->setVariable('source', "nih_reporter");
        $grant->setVariable('nih_mechanism', Grant::getActivityCode($awardNo));
        $grant->setVariable('url', $url);
        $grant->setVariable('link', Links::makeLink($url, "See Grant"));
        $grant->setVariable('pi_flag', "Y");
        $grant->setVariable('last_update', $row['nih_last_update']);
        $grant->setVariable('flagged', $row['nih_flagged'] ?? "");

        $numNodes = self::numNodes("/\s*;\s*/", $row['nih_principal_investigators']);
        if ($numNodes === 1) {
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

    public static function calculateBudgetDates($projectStartDate, $projectEndDate, $awardNoticeDate) {
        $budgetStartTs = FALSE;
        $budgetEndTs = FALSE;
        if ($projectStartDate && $projectEndDate && $awardNoticeDate) {
            $projectStartTs = strtotime($projectStartDate);
            $projectEndTs = strtotime($projectEndDate);
            $awardNoticeTs = strtotime($awardNoticeDate);

            $startBudgetYear = date("Y", $awardNoticeTs);
            if (
                in_array(date("m", $awardNoticeTs), ["10", "11", "12"])
                && in_array(date("m", $projectStartTs), ["01", "02", "03"])
            ) {
                $startBudgetYear++;
            }
            $isStartLeapYear = ($startBudgetYear % 4 === 0);
            $startBudgetRemainingDate = date("-m-d", $projectStartTs);
            if ($startBudgetRemainingDate == "-02-29" && !$isStartLeapYear) {
                $startBudgetRemainingDate = "-02-28";
            }
            $budgetStartTs = strtotime($startBudgetYear.$startBudgetRemainingDate);

            $endBudgetYear = ((int) $startBudgetYear) + 1;
            $isEndLeapYear = ($endBudgetYear % 4 === 0);
            $endBudgetRemainingDate = date("-m-d", $projectStartTs);
            if ($endBudgetRemainingDate == "-02-29" && !$isEndLeapYear) {
                $endBudgetRemainingDate = "-02-28";
            }
            $oneDay = 24 * 3600;
            $budgetEndTs = strtotime($endBudgetYear.$endBudgetRemainingDate) - $oneDay;
            if ($budgetEndTs > $projectEndTs) {
                $budgetEndTs = $projectEndTs;
            }
        }

        $format = "Y-m-d";
        $budgetStartDate = $budgetStartTs ? date($format, $budgetStartTs) : "";
        $budgetEndDate = $budgetEndTs ? date($format, $budgetEndTs) : "";
        return [$budgetStartDate, $budgetEndDate];
    }
}

