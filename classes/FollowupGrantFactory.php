<?php

namespace Vanderbilt\CareerDevLibrary;

# Helper class for GrantFactory.php

require_once(__DIR__ . '/ClassLoader.php');

class FollowupGrantFactory extends GrantFactory {
    public function getAwardFields() {
        $prefix = "followup";
        $fields = [];
        for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
            $fields[] = $prefix.'_grant'.$i.'_number';
        }
        return $fields;
    }

    public function getPIFields() {
        return [];
    }

    public function processRow($row, $otherRows, $token = "") {
        list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
        for ($i=1; $i <= Grants::$MAX_GRANTS; $i++) {
            if (($row["followup_grant$i"."_start"] != "")
                && ($row["followup_grant$i"."_notmine"] != '1')
                && in_array($row["followup_grant$i"."_role"], [1, 2])) {

                $url = self::ROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=followup&instance={$row['redcap_repeat_instance']}";
                $awardno = $row['followup_grant'.$i.'_number'];

                $grant = new Grant($this->lexicalTranslator);
                $grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
                $grant->setVariable("pid", $pid);
                $grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
                $grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
                $grant->setVariable('start', $row['followup_grant'.$i.'_start']);
                $grant->setVariable('end', $row['followup_grant'.$i.'_end']);
                $grant->setVariable('project_start', $row['followup_grant'.$i.'_start']);
                $grant->setVariable('project_end', $row['followup_grant'.$i.'_end']);
                $grant->setVariable('source', "followup");
                $costs = Grant::removeCommas($row['followup_grant'.$i.'_costs']);
                $grant->setVariable('budget', $costs);
                $grant->setVariable('total_budget', $costs);
                // $grant->setVariable('fAndA', Grants::getFAndA($awardno, $row['followup_grant'.$i.'_start']));
                $grant->setVariable('finance_type', Grants::getFinanceType($awardno));
                $grant->setVariable('direct_budget', $costs);
                $grant->setVariable('sponsor', $row['followup_grant'.$i.'_org']);
                $grant->setVariable('flagged', $row['followup_grant'.$i.'_flagged'] ?? "");
                $grant->setVariable('url', $url);
                $grant->setVariable('link', Links::makeLink($url, "See Grant"));
                # Co-PI or PI, not Co-I or Other
                if (in_array($row['followup_grant'.$i.'_role'], [1, 2, ''])) {
                    $grant->setVariable('pi_flag', 'Y');
                } else {
                    $grant->setVariable('pi_flag', 'N');
                }
                if (empty($this->choices)) {
                    $field = "followup_grant".$i."_role";
                    $fieldChoices = DataDictionaryManagement::getChoicesForField($pid, $field);
                    $grant->setVariable("role", $fieldChoices[$row[$field]]);
                } else {
                    $grant->setVariable("role", $this->choices["followup_grant".$i."_role"][$row["followup_grant".$i."_role"]]);
                }
                $grant->setNumber($awardno);
                $grant->setVariable("original_award_number", $awardno);
                if (preg_match("/^\d?[A-Z]\d\d/", $awardno, $matches)) {
                    $match = preg_replace("/^\d/", "", $matches[0]);
                    $grant->setVariable('nih_mechanism', $match);
                }
                $grant->putInBins();
                $grant->setVariable("pis", $this->getPIs($row));
                $grant->setVariable("last_update", $row['followup_date']);
                $this->grants[] = $grant;
            }
        }
    }
}

