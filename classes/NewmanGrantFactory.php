<?php

namespace Vanderbilt\CareerDevLibrary;

# Helper class for GrantFactory.php

require_once(__DIR__ . '/ClassLoader.php');

class NewmanGrantFactory extends GrantFactory {
    public function getAwardFields() {
        return [];
    }

    public function getPIFields() {
        return [];
    }

    public function processRow($row, $otherRows, $token = "") {
        $this->processNewmanData($row);
        $this->processSheet2($row);
        $this->processNew2017($row);
    }

    private static function addYearsToDate($date, $years) {
        $ts = strtotime($date);
        $yearOfTs = date("Y", $ts);
        $yearOfNewTs = $yearOfTs + $years;
        $restOfTs = date("-m-d", $ts);
        return $yearOfNewTs.$restOfTs;
    }

    private function processNewmanData($row) {
        global $pid, $event_id;
        $internalKAwardLength = Application::getInternalKLength();
        $externalKAwardLength = Application::getIndividualKLength();
        $k12kl2AwardLength = Application::getK12KL2Length();

        $date1 = "";
        if (!preg_match("/none/", $row['newman_data_date_first_institutional_k_award_newman'])) {
            $date1 = $row['newman_data_date_first_institutional_k_award_newman'];
        }
        if ($date1) {
            foreach (self::getNewmanFirstType($row, "data_internal") as $type) {
                $url = self::ROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=data";
                $grant = new Grant($this->lexicalTranslator);
                $grant->setVariable("pid", $pid);
                $grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
                $grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
                $grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
                $grant->setVariable('pi_flag', "Y");
                $grant->setVariable("role", self::$defaultRole);
                $grant->setVariable('start', $date1);
                $grant->setVariable('project_start', $date1);
                $grant->setVariable('budget', 0);
                $grant->setVariable('total_budget', 0);
                $grant->setVariable('direct_budget', 0);
                $grant->setVariable('source', "data");
                $grant->setVariable('sponsor_type', $type);
                $grant->setVariable('url', $url);
                $grant->setVariable('link', Links::makeLink($url, "See Grant"));

                $isk12kl2 = FALSE;
                if (preg_match("/K12/", $type) || preg_match("/KL2/", $type)) {
                    $grant->setNumber($type);
                    $include = TRUE;
                    $isk12kl2 = TRUE;
                } else {
                    if ($type) {
                        $grant->setNumber($type);
                        $include = TRUE;
                    } else {
                        $grant->setNumber("K12/KL2 - Rec. {$row['record_id']}");
                        $include = FALSE;
                        $isk12kl2 = TRUE;
                    }
                }
                if ($isk12kl2) {
                    $endDate = self::addYearsToDate($date1, $k12kl2AwardLength);
                } else {
                    $endDate = self::addYearsToDate($date1, $internalKAwardLength);
                }
                $grant->setVariable("end", $endDate);
                $grant->setVariable("project_end", $endDate);
                if ($include) {
                    $grant->putInBins();
                    $grant->setVariable("pis", $this->getPIs($row));
                    $this->grants[] = $grant;
                }
            }
        }

        $date2 = "";
        if (!preg_match("/none/", $row['newman_data_individual_k_start'])) {
            $date2 = $row['newman_data_individual_k_start'];
        }
        if ($date2) {
            foreach (self::getNewmanFirstType($row, "data_individual") as $type) {
                $url = self::ROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=data";
                $grant = new Grant($this->lexicalTranslator);
                $grant->setVariable("pid", $pid);
                $grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
                $grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
                $grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
                $grant->setVariable('pi_flag', "Y");
                $grant->setVariable("role", self::$defaultRole);
                $grant->setVariable('source', "data");
                $grant->setVariable('url', $url);
                $grant->setVariable('link', Links::makeLink($url, "See Grant"));
                $grant->setVariable('start', $date2);
                $grant->setVariable('project_start', $date2);
                $endDate = self::addYearsToDate($date2, $externalKAwardLength);
                $grant->setVariable('end', $endDate);
                $grant->setVariable('project_end', $endDate);
                $grant->setVariable('budget', 0);
                $grant->setVariable('total_budget', 0);
                $grant->setVariable('direct_budget', 0);
                $grant->setVariable('sponsor_type', $type);
                if ($type) {
                    $grant->setNumber($type);
                } else {
                    $grant->setNumber("Individual K - Rec. {$row['record_id']}");
                }
                $grant->putInBins();
                $grant->setVariable("pis", $this->getPIs($row));
                $this->grants[] = $grant;
            }
        }

        $date3 = "";
        if (!preg_match("/none/", $row['newman_data_r01_start'])) {
            $date3 = $row['newman_data_r01_start'];
        }
        if ($date3) {
            $url = self::ROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=data";
            $grant = new Grant($this->lexicalTranslator);
            $grant->setVariable("pid", $pid);
            $grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
            $grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
            $grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
            $grant->setVariable('pi_flag', "Y");
            $grant->setVariable("role", self::$defaultRole);
            $grant->setVariable('start', $date3);
            $grant->setVariable('project_start', $date3);
            $grant->setVariable('end', "");
            $grant->setVariable('project_end', "");
            $grant->setVariable('budget', 0);
            $grant->setVariable('total_budget', 0);
            $grant->setVariable('direct_budget', 0);
            $grant->setVariable('source', "data");
            $grant->setVariable('url', $url);
            $grant->setVariable('link', Links::makeLink($url, "See Grant"));
            $grant->setVariable('sponsor_type', "R01");
            $grant->setNumber("R01");
            $grant->putInBins();
            $grant->setVariable("pis", $this->getPIs($row));
            $this->grants[] = $grant;
        }
    }

    # get the first type of a Newman data entry
    private static function getNewmanFirstType($row, $dataSource) {
        $current = "";
        $previous = "";
        if ($dataSource == "data_individual") {
            $previous = $row['newman_data_previous_nih_grant_funding_newman'];
            $current = $row['newman_data_nih_current'];
        } else if ($dataSource == "data_internal") {
            $previous = $row['newman_data_previous_program_funding_newman'];
            $current = $row['newman_data_current_program_funding_newman'];
        } else if ($dataSource == "sheet2_internal") {
            $previous = $row['newman_sheet2_previous_program_funding_2'];
            $current = $row['newman_sheet2_current_program_funding_2'];
        } else if ($dataSource == "sheet2_noninst") {
            $previous = $row['newman_sheet2_previous_funding'];
            $current = $row['newman_sheet2_current_funding'];
        }
        if ((preg_match("/none/", $current) || ($current == "")) && (preg_match("/none/", $previous) || ($previous == ""))) {
            return array();
        } else {
            $previous = preg_replace("/none/", "", $previous);
            $current = preg_replace("/none/", "", $current);
            if ($previous && $current) {
                return self::splitAwards($previous);
            } else if ($previous) {
                return self::splitAwards($previous);
            } else if ($current) {
                return self::splitAwards($current);
            } else {
                // individual K
                return array();
            }
        }
    }

    # splits multiple awards into an array for one Newman data entry
    private static function splitAwards($en) {
        $a = preg_split("/\s*[\|;,]\s*/", $en);
        return $a;
    }


    # sheet 2 into specs
    # sheet 2 is of questionable origin and is the least reliable of the data sources
    # we do not know the origin or author of sheet 2
    private function processSheet2($row) {
        global $pid, $event_id;

        $internalKAwardLength = Application::getInternalKLength();
        $k12kl2AwardLength = Application::getK12KL2Length();
        $externalKAwardLength = Application::getIndividualKLength();

        $internalKDate = "";
        if (!preg_match("/none/", $row['newman_sheet2_institutional_k_start'])) {
            $internalKDate = $row['newman_sheet2_institutional_k_start'];
        }
        if ($internalKDate) {
            foreach (self::getNewmanFirstType($row, "sheet2_internal") as $type) {
                $url = self::ROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=sheet2";
                $grant = new Grant($this->lexicalTranslator);
                $grant->setVariable("pid", $pid);
                $grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
                $grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
                $grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
                $grant->setVariable('start', $internalKDate);
                $grant->setVariable('project_start', $internalKDate);
                $grant->setVariable('source', "sheet2");
                $grant->setVariable('url', $url);
                $grant->setVariable('link', Links::makeLink($url, "See Grant"));
                $grant->setVariable('budget', 0);
                $grant->setVariable('total_budget', 0);
                $grant->setVariable('direct_budget', 0);
                $grant->setVariable('sponsor_type', $type);

                $include = FALSE;
                $isk12kl2 = FALSE;
                if (preg_match("/K12/", $type) || preg_match("/KL2/", $type)) {
                    $grant->setNumber($type);
                    $include = TRUE;
                    $isk12kl2 = TRUE;
                } else {
                    if ($type) {
                        $grant->setNumber($type);
                        $include = TRUE;
                        $isk12kl2 = FALSE;
                    } else {
                        $grant->setNumber("K12/KL2 - Rec. {$row['record_id']}");
                        $include = FALSE;
                        $isk12kl2 = TRUE;
                    }
                }
                if ($isk12kl2) {
                    $endDate = self::addYearsToDate($internalKDate, $k12kl2AwardLength);
                } else {
                    $endDate = self::addYearsToDate($internalKDate, $internalKAwardLength);
                }
                $grant->setVariable("end", $endDate);
                $grant->setVariable("project_end", $endDate);
                if ($include) {
                    $grant->setVariable('pi_flag', "Y");
                    $grant->setVariable("role", self::$defaultRole);
                    $grant->putInBins();
                    $grant->setVariable("pis", $this->getPIs($row));
                    $this->grants[] = $grant;
                }
            }
        }

        $noninstDate = "";
        if (!preg_match("/none/", $row['newman_sheet2_noninstitutional_start'])) {
            $noninstDate = $row['newman_sheet2_noninstitutional_start'];
        }
        if ($noninstDate) {
            foreach (self::getNewmanFirstType($row, "sheet2_noninst") as $awardno) {
                $url = self::ROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=sheet2";
                $grant = new Grant($this->lexicalTranslator);
                $grant->setVariable("pid", $pid);
                $grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
                $grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
                $grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
                $grant->setVariable('start', $noninstDate);
                $grant->setVariable('project_start', $noninstDate);
                $endDate = self::addYearsToDate($noninstDate, $externalKAwardLength);
                $grant->setVariable('end', $endDate);
                $grant->setVariable('project_end', $endDate);
                $grant->setVariable('source', "sheet2");
                $grant->setVariable('url', $url);
                $grant->setVariable('link', Links::makeLink($url, "See Grant"));
                $grant->setVariable('budget', 0);
                $grant->setVariable('total_budget', 0);
                $grant->setVariable('direct_budget', 0);
                $grant->setVariable('pi_flag', "Y");
                # for this, the type = the award no
                if (!$awardno) {
                    $grant->setNumber("Unknown individual - Rec. {$row['record_id']}");
                } else {
                    $grant->setNumber($awardno);
                }
                if (!$row['newman_sheet2_first_r01_date'] || preg_match("/none/", $row['newman_sheet2_first_r01_date']) || !preg_match("/[Rr]01/", $awardno)) {
                    $grant->putInBins();
                    $grant->setVariable("pis", $this->getPIs($row));
                    $this->grants[] = $grant;
                }
            }
        }

        $r01Date = "";
        if (!preg_match("/none/", $row['newman_sheet2_first_r01_date'])) {
            $r01Date = $row['newman_sheet2_first_r01_date'];
        }
        if ($r01Date) {
            $url = self::ROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=sheet2";
            $grant = new Grant($this->lexicalTranslator);
            $grant->setVariable("pid", $pid);
            $grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
            $grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
            $grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
            $grant->setVariable('start', $r01Date);
            $grant->setVariable('project_start', $r01Date);
            $grant->setVariable('end', "");
            $grant->setVariable('project_end', "");
            $grant->setVariable('pi_flag', "Y");
            $grant->setVariable("role", self::$defaultRole);
            $grant->setVariable('source', "sheet2");
            $grant->setVariable('url', $url);
            $grant->setVariable('link', Links::makeLink($url, "See Grant"));
            $grant->setVariable('budget', 0);
            $grant->setVariable('total_budget', 0);
            $grant->setVariable('direct_budget', 0);

            $previous = $row['newman_sheet2_previous_funding'];
            $current = $row['newman_sheet2_current_funding'];

            if (preg_match("/[Rr]01/", $previous)) {
                $grant->setNumber(self::findR01($previous));
            } else if (preg_match("/[Rr]01/", $current)) {
                $grant->setNumber(self::findR01($current));
            } else {
                $grant->setNumber("R01");
            }
            $grant->putInBins();
            $grant->setVariable("pis", $this->getPIs($row));
            $this->grants[] = $grant;
        }
    }

    # This puts the new2017 folks into grants
    private function processNew2017($row) {
        global $pid, $event_id;
        $internalKDate = "";

        $internalKAwardLength = Application::getInternalKLength();
        $externalKAwardLength = Application::getIndividualKLength();
        $k12kl2AwardLength = Application::getK12KL2Length();

        if (!preg_match("/none/", $row['newman_new_first_institutional_k_award'])) {
            $internalKDate = $row['newman_new_first_institutional_k_award'];
        }
        if ($internalKDate) {
            $grant = new Grant($this->lexicalTranslator);
            $grant->setVariable("pid", $pid);
            $grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
            $grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
            $url = self::ROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=new_2017";
            $grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
            $grant->setVariable('start', $internalKDate);
            $grant->setVariable('project_start', $internalKDate);
            $grant->setVariable('source', "new2017");
            $grant->setVariable('url', $url);
            $grant->setVariable('link', Links::makeLink($url, "See Grant"));
            $grant->setVariable('budget', 0);
            $grant->setVariable('total_budget', 0);
            $grant->setVariable('direct_budget', 0);
            $sponsorType = $row["newman_new_current_program_funding"];
            $grant->setVariable('sponsor_type', $sponsorType);

            $include = FALSE;
            $isk12kl2 = FALSE;
            if ($sponsorType) {
                $grant->setNumber($sponsorType);
                $include = TRUE;
                if (preg_match("/K12/", $sponsorType) || preg_match("/KL2/", $sponsorType)) {
                    $isk12kl2 = TRUE;
                } else {
                    $isk12kl2 = FALSE;
                }
            } else {
                $grant->setNumber("K12/KL2 - Rec. {$row['record_id']}");
                $include = FALSE;
                $isk12kl2 = TRUE;
            }

            $endDate = "";
            if ($isk12kl2) {
                $endDate = self::addYearsToDate($internalKDate, $k12kl2AwardLength);
            } else {
                $endDate = self::addYearsToDate($internalKDate, $internalKAwardLength);
            }
            $grant->setVariable("end", $endDate);
            $grant->setVariable("project_end", $endDate);
            if ($include) {
                $grant->setVariable('pi_flag', "Y");
                $grant->setVariable("role", self::$defaultRole);
                $grant->putInBins();
                $grant->setVariable("pis", $this->getPIs($row));
                $this->grants[] = $grant;
            }
        }

        $noninstDate = "";
        if (!preg_match("/none/", $row['newman_new_first_individual_k_award'])) {
            $noninstDate = $row['newman_new_first_individual_k_award'];
        }
        if ($noninstDate) {
            $url = self::ROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=new_2017";
            $grant = new Grant($this->lexicalTranslator);
            $grant->setVariable("pid", $pid);
            $grant->setVariable("instrument", $row["redcap_repeat_instrument"] ?: "Normative");
            $grant->setVariable("instance", $row["redcap_repeat_instance"] ?: "1");
            $grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
            $grant->setVariable('start', $noninstDate);
            $grant->setVariable('project_start', $noninstDate);
            $endDate = self::addYearsToDate($noninstDate, $externalKAwardLength);
            $grant->setVariable('end', $endDate);
            $grant->setVariable('project_end', $endDate);
            $grant->setVariable('source', "new2017");
            $grant->setVariable('url', $url);
            $grant->setVariable('link', Links::makeLink($url, "See Grant"));
            $grant->setVariable('budget', 0);
            $grant->setVariable('total_budget', 0);
            $grant->setVariable('direct_budget', 0);
            $grant->setVariable('pi_flag', "Y");
            # for this, the type = the award no
            $awardno = $row['newman_new_current_nih_funding'];
            if (!$awardno) {
                $grant->setNumber("Unknown individual - Rec. {$row['record_id']}");
            } else {
                $grant->setNumber($awardno);
            }
            $grant->putInBins();
            $grant->setVariable("pis", $this->getPIs($row));
            $this->grants[] = $grant;
        }
    }

    # finds the R01 out of a compound grant list
    private static function findR01($sn) {
        if (preg_match("/\d[Rr]01\S+/", $sn, $matches)) {
            return $matches[0];
        }
        if (preg_match("/[Rr]01\S+/", $sn, $matches)) {
            return $matches[0];
        }
        return $sn;
    }
}
