<?php

namespace Vanderbilt\CareerDevLibrary;

# Helper class for GrantFactory.php

require_once(__DIR__ . '/ClassLoader.php');

class RePORTERGrantFactory extends GrantFactory {
    public function getAwardFields() {
        return ['reporter_projectnumber'];
    }

    public function getPIFields() {
        return ['reporter_contactpi', 'reporter_otherpis'];
    }

    public function processRow($row, $otherRows, $token = "") {
        list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
        $url = self::ROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=reporter&instance={$row['redcap_repeat_instance']}";
        $awardNo = self::cleanAwardNo($row['reporter_projectnumber']);
        $grant = new Grant($this->lexicalTranslator);
        $grant->setVariable("pid", $pid);
        $grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
        $grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
        $grant->setVariable('original_award_number', $row['reporter_projectnumber']);
        $grant->setVariable('person_name', $row['reporter_contactpi']);
        $grant->setVariable('start', self::getReporterDate($row['reporter_budgetstartdate']));
        $grant->setVariable('end', self::getReporterDate($row['reporter_budgetenddate']));
        $grant->setVariable('project_start', self::getReporterDate($row['reporter_projectstartdate']));
        $grant->setVariable('project_end', self::getReporterDate($row['reporter_projectenddate']));
        $grant->setVariable('title', $row['reporter_title']);
        $grant->setVariable('budget', $row['reporter_totalcostamount']);
        $grant->setVariable('total_budget', $row['reporter_totalcostamount']);
        $grant->setVariable('direct_budget', Grants::directCostsFromTotal($row['reporter_totalcostamount'], $awardNo, self::getReporterDate($row['reporter_budgetstartdate'])));
        // $grant->setVariable('fAndA', Grants::getFAndA($awardNo, self::getReporterDate($row['reporter_budgetstartdate'])));
        $grant->setVariable('finance_type', Grants::getFinanceType($awardNo));
        $grant->setVariable('sponsor', $row['reporter_agency']);
        $grant->setVariable('sponsor_type', $row['reporter_agency']);
        $grant->setVariable('last_update', $row['reporter_last_update']);
        $grant->setVariable('flagged', $row['reporter_flagged'] ?? "");
        $grant->setNumber($awardNo);
        $grant->setVariable('nih_mechanism', Grant::getActivityCode($awardNo));
        $grant->setVariable('source', "reporter");
        $grant->setVariable('url', $url);
        $grant->setVariable('link', Links::makeLink($url, "See Grant"));
        $grant->setVariable('pi_flag', "Y");

        if ($row["reporter_otherpis"]) {
            $grant->setVariable("role", "Co-PI");
        } else  {
            $grant->setVariable("role", "PI");
        }


        $grant->putInBins();
        $grant->setVariable("pis", $this->getPIs($row));
        $this->grants[] = $grant;
    }

    # gets the date from a RePORTER formatting (YYYY-MM-DDThh:mm:ss);
    # returns YYYY-MM-DD
    public static function getReporterDate($dt) {
        if (!$dt) {
            return "";
        }
        if (preg_match("/T/", $dt)) {
            $nodes = preg_split("/T/", $dt);
            return $nodes[0];
        }
        return $dt;
    }
}

