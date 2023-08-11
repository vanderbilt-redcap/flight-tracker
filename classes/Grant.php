<?php

namespace Vanderbilt\CareerDevLibrary;


# This file compiles all of the grants from various data sources and compiles them into an ordered list of grants.
# It should remove duplicate grants as well.
# Unit-testable.

require_once(__DIR__ . '/ClassLoader.php');

class Grant {
	public function __construct($lexicalTranslator) {
		$this->translator = $lexicalTranslator;
	}

    public static function trimApplicationType($awardNo) {
        if (preg_match("/^\d[A-Za-z]/", $awardNo)) {
            return substr($awardNo, 1);
        }
        return $awardNo;
    }

    public static function makeAryOfBaseAwardNumbers($awardNumbers) {
        $newAry = [];
        foreach ($awardNumbers as $awardNo) {
            if ($awardNo) {
                $newAry[] = strtoupper(self::translateToBaseAwardNumber($awardNo));
            }
        }
        return $newAry;
    }

	public function isInternalVanderbiltGrant() {
        return preg_match("/VUMC\s*\d+/", $this->getNumber());
    }

    public function getCurrentYearBudget($rows, $type) {
	    return $this->getActiveBudgetAtTime($rows, $type, time());
    }

    public function getBudget($rows, $type, $sourcesToExclude = []) {
        if ($type == "Direct") {
            return $this->getVariable("direct_budget");
        } else if ($type == "Total") {
            $total = $this->getVariable("total_budget");
            if ($total) {
                return $total;
            } else {
                $total = $this->getVariable("budget");
                if ($total) {
                    return $total;
                }
            }
        }
	    return 0.0;
    }

    # $type is an item of [Direct, Indirect, Total]
    # if $ts === FALSE, then calculate for all time
    public function getActiveBudgetAtTime($rows, $type, $ts, $sourcesToExclude = []) {
	    # Do not use Federal RePORTER because data are incomplete
        $orderedSources = ["coeus", "nih_reporter", "exporter", "reporter", "nsf", "ies_grant", "followup", "custom"];
        $sourcesToSkip = ["followup", "custom"]; // have numbers over all time period, not current budget
        $baseNumber = $this->getBaseNumber();
        $awardNo = $this->getNumber();
        if (self::getShowDebug()) {
            echo "Looking for $baseNumber<br>";
        }
        $runningTotal = 0.0;     // able to count supplements
        $sourceForRunningTotal = "";
        foreach ($orderedSources as $source) {
            if (!in_array($source, $sourcesToExclude) && !in_array($source, $sourcesToSkip)) {
                foreach ($rows as $row) {
                    if (($source == "coeus") && ($awardNo == $row['coeus_sponsor_award_number'])) {
                        if ($type == "Total") {
                            $field = "coeus_total_cost_budget_period";
                        } else if ($type == "Direct") {
                            $field = "coeus_direct_cost_budget_period";
                        } else {
                            throw new \Exception("Invalid type $type!");
                        }
                        if ($ts === FALSE) {
                            $currTotalFunding = $row[$field] ?? 0;
                            if (!$sourceForRunningTotal || ($source == $sourceForRunningTotal)) {
                                $runningTotal += $currTotalFunding;
                                $sourceForRunningTotal = $source;
                            }
                        } else if ($row['coeus_budget_start_date'] && $row['coeus_budget_end_date']) {
                            $startTs = strtotime($row['coeus_budget_start_date']);
                            $endTs = strtotime($row['coeus_budget_end_date']);
                            if (($ts >= $startTs) && ($ts <= $endTs)) {
                                $currTotalFunding = $row[$field] ?? 0;
                                if (!$sourceForRunningTotal || ($source == $sourceForRunningTotal)) {
                                    $runningTotal += $currTotalFunding;
                                    $sourceForRunningTotal = $source;
                                }
                            }
                        }
                    } else if (($source == "nih_reporter") && ($awardNo == $row['nih_project_num'])) {
                        if ($type == "Total") {
                            if ($ts === FALSE) {
                                if ($row['nih_award_amount']) {
                                    $currTotalFunding = $row['nih_award_amount'];
                                    if (!$sourceForRunningTotal || ($source == $sourceForRunningTotal)) {
                                        $runningTotal = $currTotalFunding;    // do not total
                                        $sourceForRunningTotal = $source;
                                    }
                                }
                            } else {
                                $fy = REDCapManagement::getCurrentFY("Federal", $ts);
                                $field = "nih_agency_ic_fundings";
                                if ($row[$field]) {
                                    $entries = RePORTER::decodeICFundings($row[$field]);
                                    foreach ($entries as $ary) {
                                        if (count($ary) >= 2) {
                                            $currFY = $ary['fy'] ?? "";
                                            $currTotalFunding = $ary['total_cost'] ?? "";
                                            if ($currFY == $fy) {
                                                if (!$sourceForRunningTotal || ($source == $sourceForRunningTotal)) {
                                                    $runningTotal += $currTotalFunding;
                                                    $sourceForRunningTotal = $source;
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    } else if (($source == "exporter") && ($awardNo == $row['exporter_full_project_num'])) {
                        if ($row['redcap_repeat_instrument'] == $source) {
                            if ($type == "Direct") {
                                $budgetField = 'exporter_direct_cost_amt';
                            } else if ($type == "Indirect") {
                                $budgetField = 'exporter_indirect_cost_amt';
                            } else if ($type == "Total") {
                                $budgetField = 'exporter_total_cost';
                                if (!$row[$budgetField]) {
                                    $budgetField = 'exporter_total_cost_sub_project';
                                }
                            } else {
                                $budgetField = "";
                            }
                            if ($ts === FALSE) {
                                if ($budgetField) {
                                    $dollars = $row[$budgetField];
                                } else {
                                    $dollars = 0;
                                }
                            } else {
                                $dollars = self::getDollarAmountFromRowAtTime($row, $baseNumber, "exporter_full_project_num", "exporter_budget_start", "exporter_budget_end", $budgetField, $ts);
                            }
                            if ($dollars && !$sourceForRunningTotal || ($source == $sourceForRunningTotal)) {
                                $runningTotal += $dollars;
                                $sourceForRunningTotal = $source;
                            }
                        }
                    } else if (($source == "coeus2") && ($awardNo == $row['coeus2_agency_grant_number'])) {
                        if (($row['redcap_repeat_instrument'] == $source) && ($row['coeus2_award_status'] == "Awarded")) {
                            if ($type == "Direct") {
                                $budgetField = 'coeus2_current_period_direct_funding';
                            } else if ($type == "Indirect") {
                                $budgetField = 'coeus2_current_period_indirect_funding';
                            } else if ($type == "Total") {
                                $budgetField = 'coeus2_current_period_total_funding';
                            } else {
                                $budgetField = "";
                            }
                            if ($ts === FALSE) {
                                if ($budgetField) {
                                    $dollars = $row[$budgetField];
                                } else {
                                    $dollars = 0;
                                }
                            } else {
                                $dollars = self::getDollarAmountFromRowAtTime($row, $baseNumber, "coeus2_agency_grant_number", "coeus2_current_period_start", "coeus2_current_period_end", $budgetField, $ts);
                            }
                            if ($dollars && !$sourceForRunningTotal || ($source == $sourceForRunningTotal)) {
                                $runningTotal += $dollars;
                                $sourceForRunningTotal = $source;
                            }
                        }
                    } else if (($source == "reporter") && ($awardNo == $row['reporter_projectnumber'])) {
                        if ($row['redcap_repeat_instrument'] == $source) {
                            if ($type == "Total") {
                                $budgetField = 'reporter_totalcostamount';
                            } else {
                                $budgetField = "";
                            }
                            if ($ts === FALSE) {
                                if ($budgetField) {
                                    $dollars = $row[$budgetField];
                                } else {
                                    $dollars = 0;
                                }
                            } else {
                                $dollars = self::getDollarAmountFromRowAtTime($row, $baseNumber, "reporter_projectnumber", "reporter_budgetstartdate", "reporter_budgetenddate", $budgetField, $ts);
                            }
                            if ($dollars && !$sourceForRunningTotal || ($source == $sourceForRunningTotal)) {
                                $runningTotal += $dollars;
                                $sourceForRunningTotal = $source;
                            }
                        }
                    } else if (($source == "custom") && ($awardNo == $row['custom_number'])) {
                        if ($type == "Direct") {
                            $budgetField = 'custom_costs';
                        } else if ($type == "Total") {
                            $budgetField = 'custom_costs_total';
                        } else {
                            $budgetField = "";
                        }
                        if ($ts === FALSE) {
                            if ($budgetField) {
                                $dollars = $row[$budgetField];
                            } else {
                                $dollars = 0;
                            }
                        } else {
                            $dollars = self::getDollarAmountFromRowAtTime($row, $baseNumber, "custom_number", "custom_start", "custom_end", $budgetField, $ts);
                        }
                        if ($dollars && !$sourceForRunningTotal || ($source == $sourceForRunningTotal)) {
                            $runningTotal += $dollars;
                            $sourceForRunningTotal = $source;
                        }
                    } else if ($source == "followup") {
                        for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
                            if ($row['followup_grant'.$i.'_number']) {
                                $currentBaseNumber = self::translateToBaseAwardNumber($row['followup_grant'.$i.'_number']);
                                if ($baseNumber == $currentBaseNumber) {
                                    if ($type == "Direct") {
                                        $budgetField = 'followup_grant'.$i.'_costs';
                                    } else {
                                        $budgetField = "";
                                    }
                                    if ($ts === FALSE) {
                                        if ($budgetField) {
                                            $dollars = $row[$budgetField];
                                        } else {
                                            $dollars = 0;
                                        }
                                    } else {
                                        $dollars = self::getDollarAmountFromRowAtTime($row, $baseNumber, "followup_grant".$i."_number", "followup_grant".$i."_start", "followup_grant".$i."_end", $budgetField, $ts);
                                    }
                                    if ($dollars && !$sourceForRunningTotal || ($source == $sourceForRunningTotal)) {
                                        $runningTotal += $dollars;
                                        $sourceForRunningTotal = $source;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return $runningTotal;
    }

    private static function getDollarAmountFromRowAtTime($row, $searchBaseNumber, $numberField, $startField, $endField, $budgetField, $ts) {
	    if (!$budgetField || !$row[$budgetField]) {
	        if (self::getShowDebug()) {
	            echo "No budget in $budgetField with {$row[$numberField]}<br>";
            }
	        return FALSE;
        }
        $rowBaseNumber = self::translateToBaseAwardNumber($row[$numberField]);
	    if (self::getShowDebug()) {
            echo "Comparing '$rowBaseNumber' from $numberField and '$searchBaseNumber'<br>";
        }
        if ($rowBaseNumber == $searchBaseNumber) {
            if (!$row[$startField] || !$row[$endField]) {
                if (self::getShowDebug()) {
                    echo "No start/end<br>";
                }
                return FALSE;
            }
            $startTs = strtotime($row[$startField]);
            $endTs = strtotime($row[$endField]);
            if (($startTs <= $ts) && ($endTs >= $ts)) {
                if (self::getShowDebug()) {
                    echo "Returning $budgetField: {$row[$budgetField]}<br>";
                }
                return $row[$budgetField];
            } else {
                if (self::getShowDebug()) {
                    echo "Not current time<br>";
                }
            }
        }
        if (self::getShowDebug()) {
            echo "Last<br>";
        }
        return FALSE;
    }

    public function isState() {
	    $fundingSource = $this->getFundingSource();
	    return preg_match("/State/", $fundingSource);
    }

    public function isIndustry() {
        $fundingSource = $this->getFundingSource();
        return preg_match("/Industry/", $fundingSource);
    }

    public function isFoundation() {
	    $type = $this->getVariable("type");
        return (
            !$this->isFederal()
            && !$this->isIndustry()
            && !$this->isState()
            && !$this->isInternalVanderbiltGrant()
            && !in_array($type, ["Internal K", "K12/KL2"])
        );
    }

	public function isNIH() {
	    $ary = self::parseNumber($this->getNumber());
        return isset($ary['institute_code']) && self::isMember($ary['institute_code'], "NIH");
    }

    public function isHHS() {
	    return self::isHHSGrant($this->getNumber());
	}

    public static function isHHSGrant($awardNo) {
	    return preg_match("/^HHS/", $awardNo);
    }

    public static function isMember($instituteCode, $group) {
	    if (!$instituteCode) {
	        return FALSE;
        }
	    $codes = [
            # from https://era.nih.gov/files/Deciphering_NIH_Application.pdf
            "NIH" => ["TW", "TR", "AT", "CA", "EY", "HG", "HL", "AG", "AA", "AI", "AR", "EB", "HD", "DA", "DC", "DE", "DK", "ES", "GM", "MH", "MD", "NS", "NR", "LM", "RR", "OD", "HC"],
            "PCORI" => [],
            "AHRQ" => ["HS"],
            "VA" => ["BX", "CX", "HX"],
            "DOD" => ["XW"],
        ];
	    $groupCodes = $codes[$group] ?? [];
        return in_array($instituteCode, $groupCodes);
    }

	public function getVariable($type) {
		if (($type == "type") && !isset($this->specs[$type])) {
			$this->putInBins();
		}
		if ($type == "total_budget") {
			$type = "budget";
		}
		if (isset($type) && isset($this->specs[$type])) {
            if (($type == "sponsor") && !$this->specs[$type]) {
                $type = "sponsor_type";
            } else if (($type == "sponsor_type") && !$this->specs[$type]) {
                $type = "sponsor";
            }
            $s = $this->specs[$type];
			if (preg_match("/budget/", $type) && is_numeric($s)) {
				$s = round($s * 100) / 100;
			}
			return $s;
		}
		return "";
	}

	public function getVariables() {
		return array_keys($this->specs);
	}

	public static function isDate($d) {
		if (preg_match("/^\d+-\d+-\d+$/", $d)) {
			return TRUE;
		}
		return FALSE;
	}

	public function getTotalCostsForTimespan($start, $end) {
		$dollars = $this->getVariable("budget");
		$grantStart = strtotime($this->getVariable("start"));
		$grantEnd = strtotime($this->getVariable("end"));
		return self::getCostsForTimespan($dollars, $grantStart, $grantEnd, $start, $end);
	}

	public function getDirectCostsForTimespan($start, $end) {
		$dollars = $this->getVariable("direct_budget");
		$grantStart = strtotime($this->getVariable("start"));
		$grantEnd = strtotime($this->getVariable("end"));
		return self::getCostsForTimespan($dollars, $grantStart, $grantEnd, $start, $end);
	}

	public function prettyMoney($n) {
		if (!$n) {
			return "\$0";
		}
		return "\$".REDCapManagement::pretty($n, 2);
	}

	public function pretty($n, $numDecimalPlaces = 3) {
		$s = "";
		$n2 = abs($n);
		$n2int = intval($n2);
		$decimal = $n2 - $n2int;
		while ($n2int > 0) {
			$s1 = ($n2int % 1000);
			$n2int = floor($n2int / 1000);
			if (($s1 < 100) && ($n2int > 0)) {
				if ($s1 < 10) {
					$s1 = "0".$s1;
				}
				$s1 = "0".$s1;
			}
			if ($s) {
				$s = $s1.",".$s;
			} else {
				$s = $s1;
			}
		}
		if ($decimal && is_int($numDecimalPlaces) && ($numDecimalPlaces >= 0)) {
			$decimal = ".".floor($decimal * pow(10, $numDecimalPlaces));
		} else {
			$decimal = "";
		}
		if (!$s) {
			$s = "0";
		}
		if ($n < 0) {
			return "-".$s.$decimal;
		}
		return $s.$decimal;
	}

	public function toHTML() {
		$html = "";

		$html .= "<div class='grant'>\n";
		$html .= "<div class='grantHeader'>\n";
		$html .= $this->getVariable("original_award_number");
		$html .= "</div>\n";
		$html .= "<div class='grantFull'>\n";
		$html .= "<div style='text-align: right;'><a href='javascript:;' class='minimize'>Minimize</a></div>\n";
		foreach ($this->specs as $var => $val) {
			$var = str_replace("_", " ", $var);
			$var = ucfirst($var);

			if (preg_match("/budget/i", $var)) {
				$val = self::prettyMoney($val);
			}

			if ($val) {
				$html .= "<div class='variable'><b>$var</b>: $val</div>\n";
			}
		}
		$html .= "</div>\n";
		$html .= "</div>\n";

		return $html;
	}

	# convert to month-overlap instead of days?
	private static function getCostsForTimespan($dollars, $grantStart, $grantEnd, $start, $end) {
		if ($dollars) {
			$fraction = 0;
			if ($start >= $grantStart) {
				if ($start < $grantEnd) {
					if ($end < $grantEnd) {
						$fraction = ($end - $start) / ($grantEnd - $grantStart);
					} else {
						$fraction = ($grantEnd - $start) / ($grantEnd - $grantStart);
					}
				} else {
					$fraction = 0;
				}
			} else {
				if ($end > $grantStart) {
					if ($end < $grantEnd) {
						$fraction = ($end - $grantStart) / ($grantEnd - $grantStart);
					} else {
						$fraction = 1;
					}
				} else {
					$fraction = 0;
				}
			}
			return ceil($fraction * $dollars);
		}
		return 0;
	}


	public static function isSameDate($d1, $d2) {
		if (self::isDate($d1) && self::isDate($d2)) {
			if (strtotime($d1) == strtotime($d2)) {
				return TRUE;
			}
		}
		return FALSE;
	}

	public function matchesGrant($grant, $var) {
		$thisStart = $this->getVariable("start");
		$thisEnd = $this->getVariable("end");
		$grantStart = $grant->getVariable("start");
		$grantEnd = $grant->getVariable("end");

		$datesMatch = self::isSameDate($grantStart, $thisStart);
		if ($datesMatch && $grantEnd && $thisEnd) {
			$datesMatch = self::isSameDate($grantEnd, $thisEnd);
		}

		if ($datesMatch) {
			if (($grant->getVariable("source") != $this->getVariable("source")) && ($grant->getBaseAwardNumber() == $this->getBaseAwardNumber())) {
				$grantVar = $grant->getVariable($var);
				$thisVar = $this->getVariable($var);
				if (is_numeric($grantVar) && is_numeric($thisVar)) {
					# number
					if ($grantVar == $thisVar) {
						return TRUE;
					} else {
						return FALSE;
					}
				} else if (self::isDate($grantVar) && self::isDate($thisVar)) {
					# date
					if (self::isSameDate($grantVar, $thisVar)) {
						return TRUE;
					} else {
						return FALSE;
					}
				} else {
					# string
					if ($grantVar == $thisVar) {
						return TRUE;
					} else {
						return FALSE;
					}
				}
			}
		}
		return FALSE;
	}

	public function setVariable($type, $value) {
		if (isset($type) && isset($value)) {
			$this->specs[$type] = $value;
		}
	}

    public function getSpecs() {
        return $this->specs;
    }

	public function getBaseAwardNumber() {
		return $this->getBaseNumber();
	}

	public function getBaseNumber() {
		return $this->getVariable("base_award_no");
	}

    public function getAwardNumber() {
        return $this->getNumber();
    }

	public function getNumber() {
		return $this->getVariable("sponsor_award_no");
	}

	public function setNumber($awardNo) {
		$this->setVariable("sponsor_award_no", $awardNo);
		$this->setVariable("base_award_no", self::translateToBaseAwardNumber($awardNo));
		$ary = self::parseNumber($awardNo);
		foreach ($ary as $key => $value) {
			$this->setVariable($key, $value);
		}
	}

	public function toArray() {
		return $this->specs;
	}

	public static function parseNumber($awardNo) {
		$awardNo = preg_replace("/[\s\-]+/", "", $awardNo);
		$ary = [];
        if (preg_match("/[A-Z][A-Z\d]\d[A-Z][A-Z]\d{6}/", $awardNo)) {
            if (preg_match("/^\d[A-Z][A-Z\d]\d[A-Z][A-Z]\d{6}/", $awardNo)) {
                $ary["application_type"] = self::getApplicationType($awardNo);
            } else {
                $awardNo = "0" . $awardNo;
            }
            $ary["activity_code"] = self::getActivityCode($awardNo);
            $ary["activity_type"] = self::getActivityType($ary["activity_code"]);
            $ary["funding_institute"] = self::getFundingInstitute($awardNo);
            $ary["institute_code"] = self::getInstituteCode($awardNo);
            $ary["serial_number"] = self::getSerialNumber($awardNo);
            $ary["support_year"] = self::getSupportYear($awardNo);
            $ary["other_suffixes"] = self::getOtherSuffixes($awardNo);
        } else if (preg_match("/^[A-Z][A-Z]\d{6}$/", $awardNo)) {
		    $ary['institute_code'] = substr($awardNo, 0, 2);
		    $ary['serial_number'] = substr($awardNo, 2, 6);
        } else if (preg_match("/^[A-Z][A-Z]\d{5}$/", $awardNo)) {
            $ary['institute_code'] = substr($awardNo, 0, 2);
            $ary['serial_number'] = "0".substr($awardNo, 2, 5);
        }
		foreach ($ary as $type => $value) {
			if ($value === "") {
				unset($ary[$type]);
			}
		}

		return $ary;
	}

	private static function getActivityType($activityCode) {
		if ($activityCode == "") {
			return "";
		}

		# https://grants.nih.gov/grants/funding/ac_search_results.htm
		switch ($activityCode) {
			case "C06":
				return "Research Facilities Construction Grant";
			case "D43":
				return "International Research Training Grants";
			case "D71":
				return "International Research Training Planning Grant";
			case "DP1":
				return "NIH Director’s Pioneer Award (NDPA)";
			case "DP2":
				return "NIH Director’s New Innovator Awards";
			case "DP3":
				return "Type 1 Diabetes Targeted Research Award";
			case "DP4":
				return "NIH Director’s Pathfinder Award - Multi-Yr Funding";
			case "DP5":
				return "Early Independence Award";
			case "DP7":
				return "NIH Director’s Workforce Innovation Award";
			case "E11":
				return "Grants for Public Health Special Projects";
			case "F05":
				return "International Research Fellowships (FIC)";
			case "F30":
				return "Individual Predoctoral NRSA for M.D./Ph.D. Fellowships";
			case "F31":
				return "Predoctoral Individual National Research Service Award";
			case "F32":
				return "Postdoctoral Individual National Research Service Award";
			case "F33":
				return "National Research Service Awards for Senior Fellows";
			case "F37":
				return "Medical Informatics Fellowships";
			case "F38":
				return "Applied Medical Informatics Fellowships";
			case "F99":
				return "Pre-doc to Post-doc Transition Award";
			case "FI2":
				return "Intramural Postdoctoral Research Associate";
			case "G07":
				return "Resources Improvement Grant";
			case "G08":
				return "Resources Project Grant (NLM)";
			case "G11":
				return "Extramural Associate Research Development Award (EARDA)";
			case "G12":
				return "Research Centers in Minority Institutions Award";
			case "G13":
				return "Health Sciences Publication Support Awards (NLM)";
			case "G20":
				return "Grants for Repair, Renovation and Modernization of Existing Research Facilities";
			case "G94":
				return "Administrative Support for Public Health Service Agency Foundations";
			case "H13":
				return "Conferences Active";
			case "H25":
				return "Venereal Disease Control";
			case "H50":
				return "Maternal and Child Health Services Project, RB Funds Active";
			case "H57":
				return "Indian Health Service Loan Repayment Program";
			case "H62":
				return "Services or Education on AIDS";
			case "H64":
				return "State and Community-Based Childhood Lead Poisoning Prevention Program";
			case "H75":
				return "Health Investigations/Assessments of Control/Preven. Methods";
			case "H79":
				return "Mental Health and/or Substance Abuse Services Grants";
			case "HD4":
				return "Drug Use/Alcohol Abuse Prevention Demo: Community Partnership Study";
			case "I01":
				return "Non-HHS Research Projects";
			case "I80":
				return "DoD Research Project Grant Program (I80)";
			case "IK3":
				return "Non-DHHS Nursing Research Initiative";
			case "K00":
				return "Post-doctoral Transition Award";
			case "K01":
				return "Research Scientist Development Award - Research & Training";
			case "K02":
				return "Research Scientist Development Award - Research";
			case "K05":
				return "Research Scientist Award";
			case "K06":
				return "Research Career Awards";
			case "K07":
				return "Academic/Teacher Award (ATA)";
			case "K08":
				return "Clinical Investigator Award (CIA)";
			case "K12":
				return "Physician Scientist Award (Program) (PSA)";
			case "K14":
				return "Minority School Faculty Development Awards";
			case "K18":
				return "The Career Enhancement Award";
			case "K21":
				return "Scientist Development Award";
			case "K22":
				return "Career Transition Award";
			case "K23":
				return "Mentored Patient-Oriented Research Career Development Award";
			case "K24":
				return "Midcareer Investigator Award in Patient-Oriented Research";
			case "K25":
				return "Mentored Quantitative Research Career Development Award";
			case "K26":
				return "Midcareer Investigator Award in Biomedical and Behavioral Research";
			case "K30":
				return "Clinical Research Curriculum Award (CRCA)";
			case "K38":
				return "Early Stage Mentored Research and Career Development";
			case "K43":
				return "International Research Career Development Award";
			case "K76":
				return "Emerging Leaders Career Development Award";
			case "K99":
				return "Career Transition Award";
			case "KD1":
				return "Mental Health and/or Substance Abuse KD&A Grants";
			case "KL1":
				return "Linked Research Career Development Award";
			case "KL2":
				return "Mentored Career Development Award";
			case "KM1":
				return "Institutional Career Enhancement Awards - Multi-Yr Funding";
			case "L30":
				return "Loan Repayment Program for Clinical Researchers";
			case "L32":
				return "Loan Repayment Program for Clinical Researchers from Disadvantaged Backgrounds";
			case "L40":
				return "Loan Repayment Program for Pediatric Research";
			case "L50":
				return "Loan Repayment Program for Contraception and Infertility Research";
			case "L60":
				return "Loan Repayment Program for Health Disparities Research";
			case "M01":
				return "General Clinical Research Centers Program";
			case "OT1":
				return "Pre-Application for an Other Transaction Award";
			case "OT2":
				return "Research Project-Other Transaction Award";
			case "OT3":
				return "Other Transaction Multiple-Component Research Award";
			case "P01":
				return "Research Program Projects";
			case "P20":
				return "Exploratory Grants";
			case "P2C":
				return "Resource-Related Research Multi-Component Projects and Centers";
			case "P30":
				return "Center Core Grants";
			case "P40":
				return "Animal (Mammalian and Nonmammalian) Model, and Animal and Biological Material Resource Grants";
			case "P41":
				return "Biotechnology Resource Grants";
			case "P42":
				return "Hazardous Substances Basic Research Grants Program (NIEHS)";
			case "P50":
				return "Specialized Center";
			case "P51":
				return "Primate Research Center Grants";
			case "P60":
				return "Comprehensive Center";
			case "PL1":
				return "Linked Center Core Grant";
			case "PM1":
				return "Program Project or Center with Complex Structure";
			case "PN1":
				return "Concept Development Award";
			case "PN2":
				return "Research Development Center";
			case "R00":
				return "Research Transition Award";
			case "R01":
				return "Research Project";
			case "R03":
				return "Small Research Grants";
			case "R13":
				return "Conference";
			case "R15":
				return "Academic Research Enhancement Awards (AREA)";
			case "R18":
				return "Research Demonstration and Dissemination Projects";
			case "R21":
				return "Exploratory/Developmental Grants";
			case "R24":
				return "Resource-Related Research Projects";
			case "R25":
				return "Education Projects";
			case "R28":
				return "Resource-Related Research Projects";
			case "R30":
				return "Preventive Health Service - Venereal Disease Research, Demonstration, and Public Information and Education Grants";
			case "R33":
				return "Exploratory/Developmental Grants Phase II";
			case "R34":
				return "Planning Grant";
			case "R35":
				return "Outstanding Investigator Award";
			case "R36":
				return "Dissertation Award";
			case "R37":
				return "Method to Extend Research in Time (MERIT) Award";
			case "R38":
				return "Mentored Research Pathway in Residency";
			case "R41":
				return "Small Business Technology Transfer (STTR) Grants - Phase I";
			case "R42":
				return "Small Business Technology Transfer (STTR) Grants - Phase II";
			case "R43":
				return "Small Business Innovation Research Grants (SBIR) - Phase I";
			case "R44":
				return "Small Business Innovation Research Grants (SBIR) - Phase II";
			case "R49":
				return "Injury Control Research and Demonstration Projects and Injury Prevention Research Centers";
			case "R50":
				return "Research Specialist Award";
			case "R55":
				return "James A. Shannon Director's Award";
			case "R56":
				return "High Priority, Short Term Project Award";
			case "R61":
				return "Phase 1 Exploratory/Developmental Grant";
			case "R90":
				return "Interdisciplinary Regular Research Training Award";
			case "RC1":
				return "NIH Challenge Grants and Partnerships Program";
			case "RC2":
				return "High Impact Research and Research Infrastructure Programs";
			case "RC3":
				return "Biomedical Research, Development, and Growth to Spur the Acceleration of New Technologies (BRDG-SPAN) Program";
			case "RC4":
				return "High Impact Research and Research Infrastructure Programs—Multi-Yr Funding";
			case "RF1":
				return "Multi-Year Funded Research Project Grant";
			case "RL1":
				return "Linked Research project Grant";
			case "RL2":
				return "Linked Exploratory/Development Grant";
			case "RL5":
				return "Linked Education Project";
			case "RL9":
				return "Linked Research Training Award";
			case "RM1":
				return "Research Project with Complex Structure";
			case "RS1":
				return "Programs to Prevent the Emergence and Spread of Antimicrobial Resistance in the United States";
			case "S06":
				return "Minority Biomedical Research Support - MBRS";
			case "S07":
				return "Biomedical Research Support Grants";
			case "S10":
				return "Biomedical Research Support Shared Instrumentation Grants";
			case "S11":
				return "Minority Biomedical Research Support Thematic Project Grants";
			case "S21":
				return "Research and Institutional Resources Health Disparities Endowment Grants -Capacity Building";
			case "S22":
				return "Research and Student Resources Health Disparities Endowment Grants - Educational Programs";
			case "SB1":
				return "Commercialization Readiness Program";
			case "SC1":
				return "Research Enhancement Award";
			case "SC2":
				return "Pilot Research Project";
			case "SC3":
				return "Research Continuance Award";
			case "SI2":
				return "Intramural Clinical Scholar Research Award";
			case "T01":
				return "Graduate Training Program";
			case "T02":
				return "Undergraduate Training Program";
			case "T09":
				return "Scientific Evaluation";
			case "T14":
				return "Conferences";
			case "T15":
				return "Continuing Education Training Grants";
			case "T32":
				return "Institutional National Research Service Award";
			case "T34":
				return "Undergraduate NRSA Institutional Research Training Grants";
			case "T35":
				return "NRSA Short -Term Research Training";
			case "T37":
				return "Minority International Research Training Grants (FIC)";
			case "T42":
				return "Educational Resource Center Training Grants";
			case "T90":
				return "Interdisciplinary Research Training Award";
			case "TL1":
				return "Linked Training Award";
			case "TL4":
				return "Undergraduate NRSA Institutional Research Training Grants";
			case "TU2":
				return "Institutional National Research Service Award with Involvement of NIH Intramural Faculty";
			case "U01":
				return "Research Project--Cooperative Agreements";
			case "U09":
				return "Scientific Review and Evaluation--Cooperative Agreements";
			case "U10":
				return "Cooperative Clinical Research--Cooperative Agreements";
			case "U11":
				return "Study (in China) of Periconceptional Vitamin Supplements to Prevent Spina Bifida and Anencephaly Cooperative Agreements";
			case "U13":
				return "Conference--Cooperative Agreements";
			case "U17":
				return "Applied Methods in Violence-Related or Accidental Injury Surveillance Cooperative Agreements";
			case "U18":
				return "Research Demonstration--Cooperative Agreements";
			case "U19":
				return "Research Program--Cooperative Agreements";
			case "U1A":
				return "Capacity Building for Core Components of Tobacco Prevention and Control Programs Cooperative Agreements";
			case "U1B":
				return "Cooperative Agreement for Research and Surveillance Activities to Reduce the Incidence of HIV/AIDS";
			case "U1Q":
				return "Emergency Disaster Relief Relating to CDC Programs Cooperative Agreement";
			case "U1V":
				return "Capacity Building for Core Components of Tobacco Prevention and Control Programs Cooperative Agreements";
			case "U21":
				return "Immunization Service for Racial and Ethnic Minorities, Cooperative Agreements";
			case "U22":
				return "HIV/STD Preventive Services for Racial and Minorities";
			case "U23":
				return "TB Prevention and Control Services for Racial and Ethnic Minorities Cooperative Agreements";
			case "U24":
				return "Resource-Related Research Projects--Cooperative Agreements";
			case "U27":
				return "Surveillance of Complications of Hemophilia Cooperative Agreements";
			case "U2C":
				return "Resource-Related Research Multi-Component Projects and Centers Cooperative Agreements";
			case "U2G":
				return "Global HIV/AIDS Non-Research Cooperative Agreements";
			case "U2R":
				return "International Research Training Cooperative Agreements";
			case "U30":
				return "Prev. Health Services: Venereal Disease Research, Demonstration, and Public Information and Education Projects";
			case "U32":
				return "State-based Diabetes Control Programs";
			case "U34":
				return "Planning Cooperative Agreement";
			case "U36":
				return "Program Improvements for Schools of Public Health";
			case "U38":
				return "Uniform National Health Program Reporting System";
			case "U41":
				return "Biotechnology Resource Cooperative Agreements";
			case "U42":
				return "Animal (Mammalian and Nonmammalian) Model, and Animal and Biological Materials Resource Cooperative Agreements";
			case "U43":
				return "Small Business Innovation Research (SBIR) Cooperative Agreements - Phase I";
			case "U44":
				return "Small Business Innovation Research (SBIR) Cooperative Agreements - Phase II";
			case "U45":
				return "Hazardous Waste Worker Health and Safety Training Cooperative Agreements (NIEHS)";
			case "U47":
				return "Laboratory/Other Diagnostic Medical Quality Improvement Cooperative Agreements";
			case "U48":
				return "Health Promotion and Disease Prevention Research Centers";
			case "U49":
				return "Coop: Injury Control Res. and Demo and Injury Prevention";
			case "U50":
				return "Special Cooperative Investigations/Assessment of Control/Prevention Methods";
			case "U51":
				return "Health Planning Strategies/National Academy of Sciences Activities";
			case "U52":
				return "Cooperative Agreement for Tuberculosis Control";
			case "U53":
				return "Capacity Bldg: Occupational Safety/Community Environmental Health";
			case "U54":
				return "Specialized Center--Cooperative Agreements";
			case "U55":
				return "Core Support For American Council on Transplantation Active";
			case "U56":
				return "Exploratory Grants--Cooperative Agreements";
			case "U57":
				return "State-Based Comprehensive Breast/Cervical Cancer Control Program Cooperative Agreements";
			case "U58":
				return "Chronic Disease Control Cooperative Agreement";
			case "U59":
				return "Disabilities Prevention Cooperative Agreement Program";
			case "U60":
				return "Cooperative Agreements in Occupational Safety and Health Research, Demonstrations, Evaluation and Education Research, Demonstrations, Evaluation and Education";
			case "U61":
				return "Preventive Health Activities Regarding Hazardous Substances";
			case "U62":
				return "Prevention/Surveillance Activities/Studies of AIDS";
			case "U65":
				return "Minority/Other Community-based HIV Prevention Project, Cooperative Agreements";
			case "U66":
				return "Immunization Demonstration Projects Cooperative Agreements";
			case "U75":
				return "National Cancer Registries Cooperative Agreements";
			case "U79":
				return "Mental Health and/or Substance Abuse Services Cooperative Agreements";
			case "U81":
				return "Injury Community Demonstration Projects: Evaluation of Youth Violence Prevention Program";
			case "U82":
				return "Enhancement of State and Local Capacity to Assess the Progress toward Healthy People 2010 Objectives";
			case "U83":
				return "Research to Advance the Understanding of the Health of Racial and Ethnic Populations or Subpopulations Cooperative Agreements";
			case "U84":
				return "Cooperative Agreements for Fetal Alcohol Syndrome Prevention Research Programs";
			case "U90":
				return "Cooperative Agreements for Special Projects of National Significance (SPNS)";
			case "UA1":
				return "AIDS Research Project Cooperative Agreement";
			case "UA5":
				return "Academic Research Enhancement Award (AREA) Cooperative Agreements";
			case "UB1":
				return "Commercialization Readiness Program – Cooperative Agreement";
			case "UC1":
				return "NIH Challenge Grants and Partnerships Program - Phase II-Coop.Agreement";
			case "UC2":
				return "High Impact Research and Research Infrastructure Cooperative Agreement Programs";
			case "UC3":
				return "Biomedical Research, Development, and Growth to Spur the Acceleration of New Technologies (BRDG-SPAN) Cooperative Agreement Program";
			case "UC4":
				return "High Impact Research and Research Infrastructure Cooperative Agreement Programs—Multi-Yr Funding";
			case "UC6":
				return "Construction Cooperative Agreement";
			case "UC7":
				return "National Biocontainment Laboratory Operation Cooperative Agreement";
			case "UD1":
				return "Mental Health and/or Substance Abuse KD&A Cooperative Agreements";
			case "UE1":
				return "Studies of Environmental Hazards and Health Effects";
			case "UE2":
				return "Emergency and Environmental Health Services";
			case "UE5":
				return "Education Projects - Cooperative Agreements";
			case "UF1":
				return "Multi-Year Funded Research Project Cooperative Agreement";
			case "UF2":
				return "Rape Prevention and Education Cooperative Agreement";
			case "UG1":
				return "Clinical Research Cooperative Agreements - Single Project";
			case "UG3":
				return "Phase 1 Exploratory/Developmental Cooperative Agreement";
			case "UG4":
				return "National Network of Libraries of Medicine";
			case "UH1":
				return "HBCU Research Scientist Award";
			case "UH2":
				return "Exploratory/Developmental Cooperative Agreement Phase I";
			case "UH3":
				return "Exploratory/Developmental Cooperative Agreement Phase II";
			case "UH4":
				return "Hazmat Training at DOE Nuclear Weapons Complex";
			case "UL1":
				return "Linked Specialized Center Cooperative Agreement";
			case "UM1":
				return "Research Project with Complex Structure Cooperative Agreement";
			case "UM2":
				return "Program Project or Center with Complex Structure Cooperative Agreement";
			case "UP5":
				return "Early Independence Award Cooperative Agreement";
			case "UR6":
				return "Prevention Intervention Research on Substance Abuse in Children Cooperative Agreements";
			case "UR8":
				return "Applied Research in Emerging Infections-(including Tick-borne Diseases) Cooperative Agreements";
			case "US3":
				return "Hantaviral Reservoir Studies Cooperative Agreements";
			case "US4":
				return "Community-Based Primary Prevention Programs: Intimate Partner Violence Cooperative Agreements";
			case "UT1":
				return "Small Business Technology Transfer (STTR) – Cooperative Agreements - Phase I";
			case "UT2":
				return "Small Business Technology Transfer (STTR) – Cooperative Agreements - Phase II";
			case "VF1":
				return "Rape Prevention and Education Grants";
			case "VF1":
				return "Rape Prevention and Education Grants";
			case "X01":
				return "Resource Access Program";
			case "X02":
				return "Preapplication";
			case "X98":
				return "Protection and Advocacy for Mentally Ill Individuals";
			case "X99":
				return "National All Schedules Prescription Electronic Reporting (NASPER)";
			default:
				return "";
		}
	}

	public static function getActivityCode($awardNo) {
		if (preg_match("/^\d[A-Z][A-Z\d]\d/", $awardNo)) {
			return substr($awardNo, 1, 3);
		} else if (preg_match("/^[A-Z][A-Z\d]\d/", $awardNo)) {
			return substr($awardNo, 0, 3);
		} else {
			$baseAwardNo = self::translateToBaseAwardNumber($awardNo);
			if (preg_match("/^[A-Z][A-Z\d]\d/", $baseAwardNo)) {
				return substr($baseAwardNo, 0, 3);
			}
		}
		return "";
	}

	# https://www.nlm.nih.gov/bsd/grant_acronym.html
	public static function getInstituteCode($awardNo) {
	    $activityCode = self::getActivityCode($awardNo);
		if (preg_match("/^\d$activityCode/", $awardNo)) {
			return substr($awardNo, 4, 2);
		} else if (preg_match("/^$activityCode/", $awardNo)) {
			return substr($awardNo, 3, 2);
		} else {
			$baseAwardNo = self::translateToBaseAwardNumber($awardNo);
			if (preg_match("/^[A-Z][A-Z\d]\d/", $baseAwardNo)) {
				return substr($baseAwardNo, 3, 2);
			}
		}
		return "";
	}

    public static function getFundingInstituteAbbreviation($awardNo) {
        return self::getFundingInstitute($awardNo, TRUE);
    }

	# https://www.nlm.nih.gov/bsd/grant_acronym.html
	public static function getFundingInstitute($awardNo, $abbreviated = FALSE) {
		$instituteCode = self::getInstituteCode($awardNo);
		switch ($instituteCode) {
            case "NH":
                if ($abbreviated) {
                    return "NIH";
                } else {
                    return "National Institutes of Health";
                }
            case "AA":
                if ($abbreviated) {
                    return "NIAAA";
                } else {
                    return "National Institute on Alcohol Abuse and Alcoholism";
                }
            case "AG":
                if ($abbreviated) {
                    return "NIA";
                } else {
                    return "National Institute on Aging";
                }
            case "AI":
                if ($abbreviated) {
                    return "NIAID";
                } else {
                    return "National Institute of Allergy and Infectious Diseases Extramural Activities";
                }
            case "AO":
                if ($abbreviated) {
                    return "NIAID";
                } else {
                    return "National Institute of Allergy and Infectious Diseases Research Support";
                }
            case "AM":
                if ($abbreviated) {
                    return "NIADDK";
                } else {
                    return "National Institute of Arthritis, Diabetes, and Digestive and Kidney Diseases";
                }
            case "AR":
                if ($abbreviated) {
                    return "NIAMS";
                } else {
                    return "National Institute of Arthritis and Musculoskeletal and Skin Diseases";
                }
            case "AT":
                if ($abbreviated) {
                    return "NCCIH";
                } else {
                    return "National Center for Complementary and Integrative Health";
                }
            case "CA":
                if ($abbreviated) {
                    return "NCI";
                } else {
                    return "National Cancer Institute";
                }
            case "CO":
                if ($abbreviated) {
                    return "NCI";
                } else {
                    return "NCI Office of the Director";
                }
            case "BC":
                if ($abbreviated) {
                    return "NCI";
                } else {
                    return "NCI Division of Basic Sciences";
                }
            case "CN":
                if ($abbreviated) {
                    return "NCI";
                } else {
                    return "NCI Division of Cancer Prevention and Control";
                }
            case "CB":
                if ($abbreviated) {
                    return "NCI";
                } else {
                    return "NCI Division of Cancer Biology and Diagnosis";
                }
            case "CP":
                if ($abbreviated) {
                    return "NCI";
                } else {
                    return "NCI Division of Cancer Epidemiology and Genetics";
                }
            case "CM":
                if ($abbreviated) {
                    return "NCI";
                } else {
                    return "NCI Division of Cancer Treatment";
                }
            case "PC":
                if ($abbreviated) {
                    return "NCI";
                } else {
                    return "NCI Division of Cancer Control and Population Science";
                }
            case "SC":
                if ($abbreviated) {
                    return "NCI";
                } else {
                    return "NCI Division of Clinical Sciences";
                }
            case "CL":
                if ($abbreviated) {
                    return "CLC";
                } else {
                    return "Clinical Center";
                }
            case "CT":
                if ($abbreviated) {
                    return "CIT";
                } else {
                    return "Center for Information Technology";
                }
            case "DA":
                if ($abbreviated) {
                    return "NIDA";
                } else {
                    return "National Institute on Drug Abuse";
                }
            case "DC":
                if ($abbreviated) {
                    return "NIDCD";
                } else {
                    return "National Institute on Deafness and other Communication Disorders";
                }
            case "DE":
                if ($abbreviated) {
                    return "NIDCR";
                } else {
                    return "National Institute of Dental and Craniofacial Research";
                }
            case "DK":
                if ($abbreviated) {
                    return "NIDDK";
                } else {
                    return "National Institute of Diabetes and Digestive and Kidney Diseases";
                }
            case "DS":
                if ($abbreviated) {
                    return "DS";
                } else {
                    return "Division of Safety, Office of Research Services";
                }
            case "EB":
                if ($abbreviated) {
                    return "NIBIB";
                } else {
                    return "National Institute of Biomedical Imaging and Bioengineering";
                }
            case "ES":
                if ($abbreviated) {
                    return "NIEHS";
                } else {
                    return "National Institute of Environmental Health Sciences";
                }
            case "EY":
                if ($abbreviated) {
                    return "NEI";
                } else {
                    return "National Eye Institute";
                }
            case "GF":
                if ($abbreviated) {
                    return "NIH";
                } else {
                    return "Gift Fund";
                }
            case "GM":
                if ($abbreviated) {
                    return "NIGMS";
                } else {
                    return "National Institute of General Medical Sciences";
                }
            case "GW":
                if ($abbreviated) {
                    return "GAS";
                } else {
                    return "Genome Association Studies";
                }
            case "HD":
                if ($abbreviated) {
                    return "NICHD";
                } else {
                    return "National Institute of Child Health and Human Development";
                }
            case "HG":
                if ($abbreviated) {
                    return "NHGRI";
                } else {
                    return "National Human Genome Research Institute";
                }
            case "HL":
                if ($abbreviated) {
                    return "NHLBI";
                } else {
                    return "National Heart, Lung, and Blood Institute";
                }
            case "HV":
                if ($abbreviated) {
                    return "NHLBI";
                } else {
                    return "NHLBI Division of Heart and Vascular Diseases";
                }
            case "HB":
                if ($abbreviated) {
                    return "NHLBI";
                } else {
                    return "NHLBI Division of Blood Diseases and Resources";
                }
            case "HR":
                if ($abbreviated) {
                    return "NHLBI";
                } else {
                    return "NHLBI Division of Lung Diseases";
                }
            case "HI":
                if ($abbreviated) {
                    return "NHLBI";
                } else {
                    return "NHLBI Division of Intramural Research";
                }
            case "HO":
                if ($abbreviated) {
                    return "NHLBI";
                } else {
                    return "NHLBI Office of the Director";
                }
            case "HC":
                if ($abbreviated) {
                    return "NHLBI";
                } else {
                    return "NHLBI Division of Epidemiology and Clinical Applications";
                }
            case "JT":
                if ($abbreviated) {
                    return "NIH";
                } else {
                    return "Joint Grant and Contract Sponsorship";
                }
            case "LM":
                if ($abbreviated) {
                    return "NLM";
                } else {
                    return "National Library of Medicine";
                }
            case "MD":
                if ($abbreviated) {
                    return "NIMHD";
                } else {
                    return "National Institute on Minority Health and Health Disparities";
                }
            case "MH":
                if ($abbreviated) {
                    return "NIMH";
                } else {
                    return "National Institute of Mental Health";
                }
            case "NB":
                if ($abbreviated) {
                    return "NB";
                } else {
                    return "Neuroscience Blueprint";
                }
            case "NR":
                if ($abbreviated) {
                    return "NINR";
                } else {
                    return "National Institute of Nursing Research";
                }
            case "NS":
                if ($abbreviated) {
                    return "NINDS";
                } else {
                    return "National Institute of Neurological Disorders and Stroke";
                }
            case "OD":
                if ($abbreviated) {
                    return "NIH";
                } else {
                    return "Office of the Director";
                }
            case "OF":
                if ($abbreviated) {
                    return "ORFDO";
                } else {
                    return "Office of Research Facilities Development and Operations";
                }
            case "OL":
                if ($abbreviated) {
                    return "OLAO";
                } else {
                    return "Office of Logistics and Acquisition Operations";
                }
            case "OP":
                if ($abbreviated) {
                    return "OppNet";
                } else {
                    return "NIH Basic Behavioral and Social Science Opportunity Network";
                }
            case "OR":
                if ($abbreviated) {
                    return "ORS";
                } else {
                    return "Office of Research Services";
                }
            case "RA":
                if ($abbreviated) {
                    return "ARRA";
                } else {
                    return "American Reinvestment and Recovery Act of 2009";
                }
            case "RC":
                if ($abbreviated) {
                    return "CCR";
                } else {
                    return "Center for Cancer Research";
                }
            case "RG":
                if ($abbreviated) {
                    return "CSR";
                } else {
                    return "Center for Scientific Review";
                }
            case "RI":
                if ($abbreviated) {
                    return "ORIP";
                } else {
                    return "Office of Research Infrastructure Programs";
                }
            case "RM":
                if ($abbreviated) {
                    return "RMOD";
                } else {
                    return "NIH Roadmap Initiative, Office of the Director";
                }
            case "RR":
                if ($abbreviated) {
                    return "NCRR";
                } else {
                    return "National Center for Research Resources";
                }
            case "RS":
                if ($abbreviated) {
                    return "DRS";
                } else {
                    return "Division of Research Services";
                }
            case "SF":
                if ($abbreviated) {
                    return "SBRP";
                } else {
                    return "Superfund Basic Research Program";
                }
            case "TR":
                if ($abbreviated) {
                    return "NCATS";
                } else {
                    return "National Center for Advancing Translational Sciences";
                }
            case "TW":
                if ($abbreviated) {
                    return "FIC";
                } else {
                    return "Fogarty International Center";
                }
            case "WH":
                if ($abbreviated) {
                    return "WHI";
                } else {
                    return "Women's Health Initiative";
                }
            case "WT":
                if ($abbreviated) {
                    return "WETP";
                } else {
                    return "Worker Education Training Program";
                }
            case "HS":
                if ($abbreviated) {
                    return "AHRQ";
                } else {
                    return "Agency for Healthcare Research and Quality";
                }
            case "AD":
                if ($abbreviated) {
                    return "ADAMHA";
                } else {
                    return "Alcohol, Drug Abuse, and Mental Health Administration";
                }
            case "CC":
                if ($abbreviated) {
                    return "CDC";
                } else {
                    return "Centers for Disease Control and Prevention";
                }
            case "CD":
                if ($abbreviated) {
                    return "ODCDC";
                } else {
                    return "Office of the Director";
                }
            case "CE":
                if ($abbreviated) {
                    return "NCIPC";
                } else {
                    return "National Center for Injury Prevention and Control";
                }
            case "CH":
                if ($abbreviated) {
                    return "OID";
                } else {
                    return "Office of Infectious Disease";
                }
            case "CI":
                if ($abbreviated) {
                    return "NCPDCID";
                } else {
                    return "National Center for Preparedness, Detection, and Control of Infectious Diseases";
                }
            case "CK":
                if ($abbreviated) {
                    return "NCEZID";
                } else {
                    return "National Center for Emerging and Zoonotic Infectious Diseases";
                }
            case "DD":
                if ($abbreviated) {
                    return "NCBDD";
                } else {
                    return "National Center on Birth Defects and Developmental Disabilities";
                }
            case "DP":
                if ($abbreviated) {
                    return "NCCDPHP";
                } else {
                    return "National Center for Chronic Disease Prevention and Health Promotion";
                }
            case "EH":
                if ($abbreviated) {
                    return "NCEH";
                } else {
                    return "National Center for Environmental Health";
                }
            case "EP":
                if ($abbreviated) {
                    return "EAPO";
                } else {
                    return "Epidemiology and Analytic Methods Program Office";
                }
            case "GD":
                if ($abbreviated) {
                    return "OGDP";
                } else {
                    return "Office of Genomics and Disease Prevention";
                }
            case "GH":
                if ($abbreviated) {
                    return "CGH";
                } else {
                    return "Center for Global Health";
                }
            case "HK":
                if ($abbreviated) {
                    return "PHITPO";
                } else {
                    return "Public Health Informatics and Technology Program Office";
                }
            case "HM":
                if ($abbreviated) {
                    return "NCHM";
                } else {
                    return "National Center for Health Marketing";
                }
            case "HY":
                if ($abbreviated) {
                    return "OHS";
                } else {
                    return "Office of Health and Safety";
                }
            case "IP":
                if ($abbreviated) {
                    return "NCIRD";
                } else {
                    return "National Center for Immunization and Respiratory Diseases";
                }
            case "LS":
                if ($abbreviated) {
                    return "LSPPPO";
                } else {
                    return "Laboratory Science, Policy, and Practice Program Office";
                }
            case "MN":
                if ($abbreviated) {
                    return "OMHHE";
                } else {
                    return "Office of Minority Health and Health Equity";
                }
            case "ND":
                if ($abbreviated) {
                    return "ONDIEH";
                } else {
                    return "Office of Non-communicable Diseases, Injury, and Environmental Health";
                }
            case "OE":
                if ($abbreviated) {
                    return "OSELS";
                } else {
                    return "Office of Surveillance, Epidemiology and Laboratory Services";
                }
            case "OH":
                if ($abbreviated) {
                    return "NIOSH";
                } else {
                    return "National Institute for Occupational Safety and Health";
                }
            case "OT":
                if ($abbreviated) {
                    return "OSTLTS";
                } else {
                    return "Office for State, Tribal, and Local and Territorial Support";
                }
            case "OW":
                if ($abbreviated) {
                    return "OWH";
                } else {
                    return "Office of Women’s Health";
                }
            case "PH":
                if ($abbreviated) {
                    return "PHPPO";
                } else {
                    return "Public Health Practice Program Office";
                }
            case "PR":
                if ($abbreviated) {
                    return "OCPHP";
                } else {
                    return "Office of Chief Public Health Practice";
                }
            case "PS":
                if ($abbreviated) {
                    return "NCHHSTP";
                } else {
                    return "National Center for HIV, Viral Hepatitis, STDs and Tuberculosis Prevention";
                }
            case "SE":
                if ($abbreviated) {
                    return "SEPDPO";
                } else {
                    return "Scientific Education and Professional Development Program Office";
                }
            case "SH":
                if ($abbreviated) {
                    return "NCHS";
                } else {
                    return "National Center for Health Statistics";
                }
            case "SO":
                if ($abbreviated) {
                    return "PHSPO";
                } else {
                    return "Public Health Surveillance Program Office";
                }
            case "TP":
                if ($abbreviated) {
                    return "OPHPR";
                } else {
                    return "Office of Public Health Preparedness and Response";
                }
            case "TS":
                if ($abbreviated) {
                    return "ATSDR";
                } else {
                    return "Agency for Toxic Substances and Disease Registry";
                }
            case "WC":
                if ($abbreviated) {
                    return "OWCD";
                } else {
                    return "Office of Workforce and Career Development";
                }
            case "HH":
                if ($abbreviated) {
                    return "HHS";
                } else {
                    return "Department of Health and Human Services";
                }
            case "AE":
                if ($abbreviated) {
                    return "ASPE";
                } else {
                    return "Assistant Secretary of Planning and Evaluation";
                }
            case "OC":
                if ($abbreviated) {
                    return "ONCHIT";
                } else {
                    return "Office of the National Coordinator for Health Information Technology";
                }
            case "FD":
                if ($abbreviated) {
                    return "FDA";
                } else {
                    return "Food and Drug Administration";
                }
            case "BA":
            case "BJ":
                if ($abbreviated) {
                    return "FDA";
                } else {
                    return "FDA Center for Biologics Evaluation and Research-Bacterial Products";
                }
            case "BB":
                if ($abbreviated) {
                    return "FDA";
                } else {
                    return "FDA Center for Biologics Evaluation and Research-Biochemistry and Biophysics";
                }
            case "BD":
            case "BL":
                if ($abbreviated) {
                    return "FDA";
                } else {
                    return "FDA Center for Biologics Evaluation and Research-Cytokine Biology";
                }
            case "BE":
            case "BR":
                if ($abbreviated) {
                    return "FDA";
                } else {
                    return "FDA Center for Biologics Evaluation and Research-Product Quality Control";
                }
            case "BF":
                if ($abbreviated) {
                    return "FDA";
                } else {
                    return "FDA Center for Biologics Evaluation and Research-Virology";
                }
            case "BG":
                if ($abbreviated) {
                    return "FDA";
                } else {
                    return "FDA Center for Biologics Evaluation and Research-Transfusion";
                }
            case "BH":
            case "BQ":
                if ($abbreviated) {
                    return "FDA";
                } else {
                    return "FDA Center for Biologics Evaluation and Research-Hematology";
                }
            case "BI":
                if ($abbreviated) {
                    return "FDA";
                } else {
                    return "FDA Center for Biologics Evaluation and Research-Allergenic Products and Parasitology";
                }
            case "BK":
                if ($abbreviated) {
                    return "FDA";
                } else {
                    return "FDA Center for Biologics Evaluation and Research-Viral Products";
                }
            case "BM":
                if ($abbreviated) {
                    return "FDA";
                } else {
                    return "FDA Center for Biologics Evaluation and Research-Cellular and Gene Therapies";
                }
            case "BN":
                if ($abbreviated) {
                    return "FDA";
                } else {
                    return "FDA Center for Biologics Evaluation and Research-Hematologic Products";
                }
            case "BO":
                if ($abbreviated) {
                    return "FDA";
                } else {
                    return "FDA Center for Biologics Evaluation and Research-Monoclonal Antibodies";
                }
            case "BP":
                if ($abbreviated) {
                    return "FDA";
                } else {
                    return "FDA Center of Biologics Evaluation and Research-Transfusion Transmitted Diseases";
                }
            case "BS":
                if ($abbreviated) {
                    return "FDA";
                } else {
                    return "FDA Division of Biologics Standards";
                }
            case "BT":
                if ($abbreviated) {
                    return "FDA";
                } else {
                    return "FDA Center for Biologics Evaluation and Research-Immunology and Infectious Diseases";
                }
            case "BU":
                if ($abbreviated) {
                    return "FDA";
                } else {
                    return "FDA Center for Biologics Evaluation and Research-Clinical Pharmacology and Toxicology";
                }
            case "AH":
            case "DH":
                if ($abbreviated) {
                    return "BHP";
                } else {
                    return "HRSA Division of Associated & Dental Health Professions";
                }
            case "MB":
                if ($abbreviated) {
                    return "BHP";
                } else {
                    return "HRSA Division of Disadvantaged Assistance";
                }
            case "NU":
                if ($abbreviated) {
                    return "BHP";
                } else {
                    return "HRSA Division of Nursing";
                }
            case "PE":
                if ($abbreviated) {
                    return "BHP";
                } else {
                    return "HRSA Division of Medicine";
                }
            case "SA":
                if ($abbreviated) {
                    return "BHP";
                } else {
                    return "HRSA Division of Student Assistance";
                }
            case "ST":
                if ($abbreviated) {
                    return "OHS";
                } else {
                    return "Office of Healthy Start";
                }
            case "AS":
                if ($abbreviated) {
                    return "ASC";
                } else {
                    return "Administrative Services Center, OASH";
                }
            case "FP":
                if ($abbreviated) {
                    return "OFP";
                } else {
                    return "Office of Family Planning";
                }
            case "MP":
                if ($abbreviated) {
                    return "OMH";
                } else {
                    return "Office of Minority Health";
                }
            case "PG":
                if ($abbreviated) {
                    return "OAPP";
                } else {
                    return "Office of Adolescent Pregnancy Programs";
                }
            case "OA":
                if ($abbreviated) {
                    return "SAMHSA";
                } else {
                    return "SAMHSA Office of the Administration";
                }
            case "SP":
                if ($abbreviated) {
                    return "CSAP";
                } else {
                    return "Center for Substance Abuse Prevention";
                }
            case "SM":
                if ($abbreviated) {
                    return "CMHS";
                } else {
                    return "Center for Mental Health Services";
                }
            case "SU":
                if ($abbreviated) {
                    return "SAMHSA";
                } else {
                    return "Substance Abuse and Mental Health Services Administration";
                }
            case "TI":
                if ($abbreviated) {
                    return "CSAT";
                } else {
                    return "Center for Substance Abuse Treatment";
                }
            case "VA":
                if ($abbreviated) {
                    return "VA";
                } else {
                    return "Department of Veterans Affairs";
                }
            case "BX":
                if ($abbreviated) {
                    return "BLRD";
                } else {
                    return "VA Biomedical Laboratory Research and Development";
                }
            case "CU":
                if ($abbreviated) {
                    return "CSP";
                } else {
                    return "VA Cooperative Studies Program";
                }
            case "CX":
                if ($abbreviated) {
                    return "CSRD";
                } else {
                    return "VA Clinical Science Research and Development";
                }
            case "HX":
                if ($abbreviated) {
                    return "HSRD";
                } else {
                    return "VA Health Services Research and Development";
                }
            case "RD":
                if ($abbreviated) {
                    return "ORD";
                } else {
                    return "VA Office of Research and Development";
                }
            case "RX":
                if ($abbreviated) {
                    return "RRD";
                } else {
                    return "VA Rehabilitation Research and Development";
                }
            default:
                return "";
        }
    }

	private static function getSerialNumber($awardNo) {
		if (preg_match("/^\d[A-Z][A-Z\d]\d/", $awardNo)) {
			return substr($awardNo, 6, 6);
		} else if (preg_match("/^[A-Z][A-Z\d]\d/", $awardNo)) {
			return substr($awardNo, 5, 6);
		} else {
			$baseAwardNo = self::translateToBaseAwardNumber($awardNo);
			if (preg_match("/^[A-Z][A-Z\d]\d/", $baseAwardNo)) {
				return substr($baseAwardNo, 5, 6);
			}
		}
		return "";
	}

	public static function getSupportYear($awardNo) {
		if (preg_match("/\-/", $awardNo)) {
			$nodes = preg_split("/\-/", $awardNo);
			$tail = $nodes[1];
			if (strlen($tail) >= 2) {
				return substr($tail, 0, 2);
			}
		}
		return "";
	}

	public static function getOtherSuffixes($awardNo) {
		if (preg_match("/\-/", $awardNo)) {
			$nodes = preg_split("/\-/", $awardNo);
			$tail = $nodes[1];
			if (strlen($tail) >= 4) {
				$suffix = substr($tail, 2, 2);
				$letter = substr($suffix, 0, 1);
				$number = substr($suffix, 1, 1);
				switch ($letter) {
					case "A":
						return "Amendment Number ".$number;
					case "S":
						return "Revision Record ".$number;
					default:
						return $suffix;
				}
			}
		}
		return "";
	}

	public static function getApplicationType($awardNo) {
		$appType = substr($awardNo, 0, 1);
		switch ($appType) {
			case 1:
				return "New";
			case 2:
				return "Renewal";
			case 3:
				return "Revision";
			case 4:
				return "Extension";
			case 5:
				return "Non-competing Continuation";
			case 6:
				return "Change of Organization Status (Successor-In-Interest)";
			case 7:
				return "Change of Grantee or Training Institution";
			case 8:
            case 9:
				return "Change of Institute or Center";
			default:
				return "";
		}
	}

	public function isFederal() {
		$src = $this->getVariable("source");
        $isFederal = [
            "Non-Profit - Foundations/ Associations" => "Non-Federal",
            "DOD" => "Federal",
            "NASA" => "Federal",
            "ED" => "Federal",
            "NSF" => "Federal",
            "VA" => "Federal",
            "Federal" => "Federal",
            "Institutional Funds" => "Non-Federal",
            "Non-Profit - Other" => "Non-Federal",
            "State - Tennessee" => "Non-Federal",
            "Non-Profit - Education" => "Non-Federal",
            "State - Other" => "Non-Federal",
            "DOE" => "Federal",
            "NIH" => "Federal",
            "Profit" => "Non-Federal",
            "PHS" => "Federal",
            "Local Government" => "Non-Federal",
            "Endowment" => "Non-Federal",
            "Non-Profit - Hospital" => "Non-Federal",
        ];
        $federalAgencies = [
            "Patient-Centered Outcomes Research Institute",
            "National Science Foundation",
            "National Oceanic and Atmospheric Administration",
            "National Library of Medicine",
            "National Institutes of Health/Unknown",
            "National Institutes of Health/Office of the Director",
            "National Institute on Minority Health and Health Disparities",
            "National Institute on Minority Health & Health Disparities",
            "National Institute on Drug Abuse",
            "National Institute on Deafness and Communication Disorders",
            "National Institute on Deafness and Other Communication Disor",
            "National Institute on Deafness and Other Communication Disorders",
            "National Institute on Alcohol Abuse and Alcoholism",
            "National Institute on Alcohol Abuse & Alcoholism",
            "National Institute on Aging",
            "National Institute of Nursing Research",
            "National Institute of Neurological Disorders and Stroke",
            "National Institute of Neurological Disorders & Stroke",
            "National Institute of Mental Health",
            "National Institute of General Medical Sciences",
            "National Institute of Environmental Health Sciences",
            "National Institute of Diabetes & Digestive & Kidney Disease",
            "National Institute of Diabetes and Digestive and Kidney Disease",
            "National Institute of Child Health and Human Development",
            "National Institute of Child Health & Human Development",
            "National Institute of Dental and Craniofacial Research",
            "National Institute of Dental & Craniofacial Research",
            "National Institute of Biomedical Imaging and Bioengineering",
            "National Institute of Biomedical Imaging & Bioengineering",
            "National Institute of Arthritis, Musculoskeletal and Skin",
            "National Institute of Arthritis and Musculoskeletal and Skin Diseases",
            "National Institute of Arthritis & Musculoskeletal & Skin Diseases",
            "National Institute of Allergy and Infectious Diseases",
            "National Institute of Allergy & Infectious Diseases",
            "National Human Genome Research Institute",
            "National Heart, Lung, and Blood Institute",
            "National Heart, Lung, & Blood Institute",
            "National Eye Institute",
            "National Center for Research Resources",
            "National Center for Complementary and Integrative Health",
            "National Center for Complementary & Integrative Health",
            "National Center for Advancing Translational Sciences",
            "National Cancer Institute",
            "NIH Clinical Center",
            "Center for Information Technology",
            "Center for Scientific Review",
            "Fogarty International Center",
            "Food and Drug Administration/Other",
            "Food and Drug Administration",
            "Food & Drug Administration",
            "Department of Defense",
            "Congressionally Directed Medical Research Programs",
            "Centers for Medicare and Medicaid Services",
            "Centers for Medicare & Medicaid Services",
            "Centers For Disease Control and Prevention (CDC)",
            "Centers For Disease Control and Prevention",
            "Agency for Healthcare Research and Quality",
            "Agency for Healthcare Research & Quality",
            "Department of Health and Human Services",
            "Department of Health & Human Services",
            "NIH National Research Service Award",
            "NIH Office of the Director",
        ];
		if ($this->isNIH()) {
		    return TRUE;
        }
		if (in_array($src, ["exporter", "reporter", "nih_reporter", "nsf", "ies_grant"])) {
			return TRUE;
		} else if ($src == "coeus") {
			$directSponsorType = $this->getVariable("direct_sponsor_type");
			$primeSponsorType = $this->getVariable("prime_sponsor_type");
			if (($isFederal[$primeSponsorType] == "Federal") && ($directSponsorType != "State - Tennessee")) {
				return TRUE;
			}
		} else if ($src == "coeus2") {
		    $agency = $this->getVariable("agency_name");
            if (in_array($agency, $federalAgencies) && ($agency != "State of Tennessee")) {
                return TRUE;
            }
        } else {
            # Try to hack a guess
            $sponsor = $this->getVariable("sponsor");
            if (
                $sponsor
                && (
                    ($isFederal[$sponsor] == "Federal")
                    || in_array($sponsor, $federalAgencies)
                )
            ) {
                return TRUE;
            }
        }
        return FALSE;
	}

	public static function removeCommas($num) {
		return preg_replace("/,/", "", $num);
	}

	public static function autocalculateGrantLength($type) {
	    if (!is_numeric($type)) {
	        # convert string to number
	        $awardTypes = self::getAwardTypes();
	        $type = $awardTypes[$type];
        }
        if ($type == 1) {
            return Application::getInternalKLength();
        } else if ($type == 2) {
            return Application::getK12KL2Length();
        } else if (in_array($type, [3, 4])) {
            return Application::getIndividualKLength();
        } else {
            throw new \Exception("Invalid type ($type) for year length");
        }
    }

	# 0, Computer-Generated
	# 1, Self-Reported
	# 2, Manually Entered
	public function getSourceType() {
		$src = $this->getVariable("source");
		$translate = self::getSourceTypeTranslation();
		if (isset($translate[$src])) {
			return $translate[$src];
		}
		return "";
	}

	public function isSelfReported() {
	    return ($this->getSourceType() == 1);
    }

	private static function getSourceTypeTranslation() {
		return [
            "modify" => 2,
            "custom" => 2,
            "coeus" => 0,
            "coeus2" => 0,
            "exporter" => 0,
            "nih_reporter" => 0,
            "vera" => 0,
            "local_gms" => 0,
            "reporter" => 0,
            "ldap" => 0,
            "nsf" => 0,
            "ies_grant" => 0,
            "followup" => 1,
            "scholars" => 1,
            "override" => 2,
            "manual" => 2,
            "data" => 2,
            "sheet2" => 2,
            "new2017" => 2,
            ];
	}


	public static function translateToBaseAwardNumber($num) {
		$num = preg_replace("/^Individual K - Rec\. \d+ /", "", $num);
		if (preg_match("/^Internal K/", $num)) {
			return $num;
		} else if (preg_match("/^K12/", $num)) {
			return $num;
		} else if (preg_match("/^KL2/", $num)) {
			return $num;
		} else if (preg_match("/^Individual K/", $num)) {
			return $num;
		} else if (preg_match("/^Unknown R01 - Rec. \d+/", $num)) {
			return $num;
		} else if (preg_match("/^Unknown/", $num)) {
			return $num;
		}
		$numWithoutSpaces = preg_replace("/\s+/", "", $num);
		$specialActivityCodes = array(
						"L1C",     // CMS
						"C1C",     // CMS
						"U2G",     // CDC
                        "U2C",     // Cooperative Agreements
						);
		foreach ($specialActivityCodes as $activityCode) {
			if (preg_match("/".$activityCode."[A-Z][A-Z]\d\d\d\d\d\d/", $numWithoutSpaces, $matches)) {
				return $matches[0];
			}
		}
		if (preg_match("/HHS/", $numWithoutSpaces)) {
			# HHS, e.g., HHSF22301012T, HHSF223201400042I, HHSN268200900034C
			if (preg_match("/HHS[A-Z]\d\d\d\d\d\d\d\d[A-Z]/", $numWithoutSpaces, $matches)) {
				return $matches[0];
			} else if (preg_match("/HHS[A-Z]\d\d\d\d\d\d\d\d\d\d\d\d[A-Z]/", $numWithoutSpaces, $matches)) {
				return $matches[0];
			}
		}
		if (preg_match("/[A-Z][A-Z\d]\d[A-Z][A-Z]\d+/", $numWithoutSpaces, $matches)) {
			$ary = self::parseNumber($matches[0]);
			$activityCode = $ary['activity_code'] ?? "";
			$instituteCode = $ary['institute_code'] ?? "";
			$serialNumber = $ary['serial_number'] ?? "";
			return $activityCode.$instituteCode.$serialNumber;
		} else if (preg_match("/^VUMC\d+\(.+\)$/", $numWithoutSpaces)) {
			$numWithoutSpaces = preg_replace("/^VUMC\d+\(/", "", $numWithoutSpaces);
			$numWithoutSpaces = preg_replace("/\)$/", "", $numWithoutSpaces);
			$ary = self::parseNumber($numWithoutSpaces);
			if (!empty($ary)) {
                $activityCode = $ary['activity_code'] ?? "";
                $instituteCode = $ary['institute_code'] ?? "";
                $serialNumber = $ary['serial_number'] ?? "";
                return $activityCode.$instituteCode.$serialNumber;
			} else {
				return $numWithoutSpaces;
			}
		}
		return $num;
	}

	# The "type" (which is the bin into which we place the grant) is calculated in many cases.
	# we get the "type" from the properties of the grant in getCurrentType. 
	public function putInBins() {
		$type = $this->getCurrentType();
		$fundingSource = $this->getFundingSource();

		$this->setVariable("type", $type);
		if ($fundingSource && ($fundingSource != "N/A")) {
			$this->setVariable("funding_source", $fundingSource);
		}
	}

	# returns blank if doesn't match regular expression
	private function lexicallyTranslate($awardNo) {
		return $this->translator->getCategory($awardNo);
	}

	public static function getFundingSourceAbbreviations() {
		$agencies = array(
				"CDC" => "Centers for Disease Control and Prevention",
				"AHRQ" => "Agency for Healthcare Research and Quality",
				"HRSA" => "Health Resources and Services Administration",
				"DOD" => "Department of Defense",
				"NIH" => "National Institutes of Health",
				"FDA" => "Food and Drug Administration",
				"ONC" => "Office of the National Coordinator for Health Information Technology",
				"HHS" => "Department of Health and Human Services",
				"VA" => "Department of Veterans Affairs",
				"CMS" => "Centers for Medicare and Medicaid Services",
				);
		return $agencies;
	}

	private static function tellIfSubcontract($agency, $primeSponsorType, $directSponsorType) {
		if ($agency) {
			if ($primeSponsorType == $directSponsorType) {
				return $agency;
			}
			return $agency." Subcontract";
		}
		return "";
	}

	# coordinated with wrangler/index.php
	public static function getIndex($awardno, $sponsor, $startDate) {
        $sep = "____";
        return $awardno . $sep . $sponsor . $sep . $startDate;
    }

    # NIH, AHRQ, NSF, Other Federal (Other Fed), University (Univ), Foundation (Fdn), None, or Other
    # Coordinated with MainGroup::getFundingSource in React Tables 2-4
    public function getTable4AbbreviatedFundingSource() {
        $acceptedFederalCategories = ["NIH", "AHRQ", "NSF"];
	    $fundingSource = $this->getFundingSource();
	    if ($fundingSource) {
	        foreach (self::getFundingSourceAbbreviations() as $abbreviation => $name) {
	            if (($fundingSource == $name) || ($fundingSource == $abbreviation)) {
	                if (in_array($abbreviation, $acceptedFederalCategories)) {
	                    return $abbreviation;
                    }
                }
            }
            if (in_array($fundingSource, ["NIH", "National Institutes of Health", "National Institute of Health"])) {
                return "NIH";
            } else if (in_array($fundingSource, ["NSF", "National Science Foundation"])) {
                return "NSF";
            } else if (in_array($fundingSource, ["AHRQ", "Agency for Healthcare Research and Quality", "Agency for Healthcare Research & Quality"])) {
                return "AHRQ";
            } else if ($this->isFederal()) {
                return "Other Fed";
            } else if ($this->getCurrentType() == "Internal K") {
                return "Univ";
            } else {
                $type = self::$fdnOrOther;    // Cannot tell difference
                if (isset($this->specs['sponsor'])) {
                    return $type."<br/>".$this->specs['sponsor'];
                } else {
                    return $type;
                }
            }
        }
        if ($this->isNIH()) {
            return "NIH";
        }
        return NULL;
    }

    # uses private variable specs
	public function getFundingSource() {
		$specs = $this->specs;
        $agencies = [
            "CDC" => "CDC",
            "Centers for Medicare and Medicaid Services" => "CMS",
            "Agency for Healthcare Research and Quality" => "AHRQ",
            "Health and Human Services" => "HHS",
            "Health Resources and Services Administration" => "HRSA",
            "Health Services Research Administration" => "HRSA",   // old name
            "Food and Drug Administration" => "FDA",
            "Health Information Technology" => "ONC",
        ];

		if ($specs["source"] == "coeus") {
			if (preg_match("/\b000\b/", $specs['sponsor'])) {
				return "N/A";
			}
			$primeSponsorType = $specs['prime_sponsor_type'];
			$primeSponsorName = $specs['prime_sponsor_name'];
			$directSponsorType = $specs['direct_sponsor_type'];
			$isFederal = $this->isFederal();

			if ($isFederal) {
				switch($primeSponsorType) {
					case "DOD":
                    case "NIH":
                        return Grant::tellIfSubcontract($primeSponsorType, $primeSponsorType, $directSponsorType);
					case "PHS":
                        $matchedAgency = "";
						foreach ($agencies as $agency => $abbreviation) {
							if (preg_match("/".$agency."/", $primeSponsorName)) {
								$matchedAgency = $abbreviation;
								break;   // from for
							}
						}
						if ($matchedAgency) {
							return Grant::tellIfSubcontract($matchedAgency, $primeSponsorType, $directSponsorType);
						}
						break;
					case "VA":
						return "VA";
				}
				if (!preg_match("/DO NOT USE/", $primeSponsorName)) {
					return "Federal: Other";
				}
			} else {
				switch($primeSponsorType) {
					case "Non-Profit - Foundations/ Associations":
						return "Foundation/Non-Profit";
					case "State - Tennessee":
						return "State";
					case "PHS":
						if ($directSponsorType == "State - Tennessee") {
							return "State";
						}
						break;
					case "Non-Profit - Education":
						return "Non-Federal: Other";
					case "Profit":
						if ($directSponsorType == "Profit") {
							return "Industry: Contract";
						}
						return "Industry: Grant";
				}
				return "Non-Federal: Other";
			}
		} else {
            # Very inexact - best guess
            $sponsor = $specs['sponsor'];
            $sponsorType = $specs['sponsor_type'] ?? "";

            foreach ([$sponsor, $sponsorType] as $type) {
                switch($type) {
                    case "DOD":
                    case "NIH":
                    case "VA":
                        return $type;
                    case "PHS":
                        foreach ($agencies as $agency => $abbreviation) {
                            if (preg_match("/" . $agency . "/i", $type)) {
                                return $abbreviation;
                            }
                        }
                        break;
                    default:
                        foreach ($agencies as $agency => $abbreviation) {
                            if (
                                preg_match("/" . $agency . "/i", $type)
                                || (strtoupper($abbreviation) == strtoupper($type))
                            ) {
                                return $abbreviation;
                            }
                        }
                }
            }
            if ($sponsor && $this->isFederal()) {
                return "Federal: Other";
            } else if ($sponsor) {
                return "Non-Federal: Other";
            }
        }
		return "N/A";
	}

	public static function getCoeusSources() {
	    return ["coeus", "coeus2"];
    }

	# uses private variable specs
	# Finds the award type
	# difficult
	private function getCurrentType()
    {
        $specs = $this->specs;
        $awardNo = $this->getNumber();

        if ($specs['pi_flag'] == 'N') {
            if (self::getShowDebug()) { Application::log($awardNo.": pi_flag is N"); }
            return "N/A";
        }
        if (isset($specs['type']) && ($specs['type'] !== "")) {
            if (self::getShowDebug()) { Application::log($awardNo.": preset type ".$specs['type']); }
            return $specs['type'];
        }

        if (self::getShowDebug()) { Application::log($awardNo.": First Pass"); }
        if ($type = $this->lexicallyTranslate($awardNo)) {
            return $type;
        }

        return self::calculateAwardType($specs, $awardNo);
    }

    public static function calculateAwardType($specs, $awardNo) {
        $coeusSources = self::getCoeusSources();
        $r01EquivYearlyThreshold = 250000;
        $r01EquivNumberOfYears = 3;

        if ($specs['source'] == 'nsf') {
            if (preg_match("/REU Site/", $specs['title'])) {
                return "Training Grant Admin";
            } else if (preg_match("/CAREER/", $specs['title'])) {
                return "K Equivalent";
            } else {
                return "R01 Equivalent";
            }
        }

		if (self::getShowDebug()) { Application::log($awardNo.": Second Pass"); }
		$trainingGrantSources = ["coeus", "reporter", "exporter", "nih_reporter", "nsf"];
		if ($awardNo == "") {
			return "N/A";
		} else if (($specs['pi_flag'] == "N") && !(preg_match("/\d[Kk][1L]2/", $awardNo))) {
			return "N/A";
		} else if (preg_match("/^\d?[Kk][1L]2/", $awardNo)) {
			if (preg_match("/\d[Kk][1L]2/", $awardNo) || preg_match("/^[Kk][1L]2/", $awardNo) || preg_match("/[Kk][1L]2$/", $awardNo)) {
				if (($specs['pi_flag'] == "N") && (in_array($specs['source'], $coeusSources))) {
					// return "K12/KL2";
				} else if (($specs['pi_flag'] == "Y") && (in_array($specs['source'], $trainingGrantSources ))) {
					return "Mentoring/Training Grant Admin";
				} else {
					return "K12/KL2";
				}
			} else {
				return "K12/KL2";
			}
		} else if (preg_match("/VUMC/", $awardNo)) {
			return "N/A";
		} else if (preg_match("/Unknown individual/", $awardNo)) {
			return "K Equivalent";
		} else if (preg_match("/^\d?[Rr]00/", $awardNo) || preg_match("/^\d?[Kk]\s*99/", $awardNo)) {
			return "K99/R00";
        } else if (preg_match("/^\d?[Rr]01/", $awardNo)) {
            return "R01";
		} else if (preg_match("/^\d?[Tt]\d\d/", $awardNo) || preg_match("/^\d?[Dd]43/", $awardNo)) {
			return "Mentoring/Training Grant Admin";
		} else {
            # not R01 or R00
            $budgetField = "";
            // TODO ['direct_budget', 'budget', 'total_budget']
            foreach (['direct_budget'] as $specField) {
                if (isset($specs[$specField]) && ($specs[$specField] > 0)) {
                    $budgetField = $specField;
                    break;
                }
            }

            if ($budgetField) {
                if ($specs['project_start'] && $specs['project_end']) {
                    $projStart = strtotime($specs['project_start']);
                    $projEnd = strtotime($specs['project_end']);
                    if (self::getShowDebug()) {
                        Application::log("$awardNo with project {$specs['project_start']} and {$specs['project_end']}");
                    }
                } else if ($specs['start'] && $specs['end']) {
                    $projStart = strtotime($specs['start']);
                    $projEnd = strtotime($specs['end']);
                    if (self::getShowDebug()) {
                        Application::log("$awardNo with budget {$specs['start']} and {$specs['end']}");
                    }
                } else {
                    $projStart = FALSE;
                    $projEnd = FALSE;
                }
                if ($projStart && $projEnd) {
                    $yearspan = ($projEnd - $projStart) / (365 * 24 * 3600);
                    $isR01EquivEligible = ($specs[$budgetField] > $r01EquivYearlyThreshold * $yearspan) && ($specs[$budgetField] >= $r01EquivYearlyThreshold * $r01EquivNumberOfYears);
                    if (self::getShowDebug()) {
                        Application::log("$awardNo with $budgetField \${$specs[$budgetField]} and {$specs['num_grants_combined']} / $yearspan years");
                    }
                    if (
                        !$isR01EquivEligible
                        && isset($specs['num_grants_combined'])
                        && ($specs['num_grants_combined'] >= 3)
                    ) {
                        $isR01EquivEligible = ($specs[$budgetField] / $specs['num_grants_combined'] > $r01EquivYearlyThreshold);
                        if (self::getShowDebug()) {
                            Application::log("Route B: $awardNo with $budgetField \${$specs[$budgetField]} and {$specs['num_grants_combined']} years");
                        }
                    }
                    if (($yearspan >= $r01EquivNumberOfYears) && $isR01EquivEligible) {
                        if (!preg_match("/^\d?[Kk]\d\d/", $awardNo)) {
                            if (self::getShowDebug()) { Application::log($awardNo.": Second Pass - R01 Equivalent ".(($projEnd - $projStart) / (365 * 24 * 3600))); }
                            return "R01 Equivalent";
                        } else {
                            if (self::getShowDebug()) { Application::log($awardNo.": Second Pass - exit D"); }
                        }
                    } else {
                        if (self::getShowDebug()) { Application::log($awardNo.": Second Pass - exit C: ".REDCapManagement::pretty($yearspan, 1)." years"); }
                    }
                } else {
                    if (self::getShowDebug()) { Application::log($awardNo.": Second Pass - exit B"); }
                }
            } else {
                if (self::getShowDebug()) { Application::log("$awardNo has no direct budget ".REDCapManagement::json_encode_with_spaces($specs)); }
            }
        }

		if (self::getShowDebug()) { Application::log($awardNo.": Third Pass"); }
		if (preg_match("/^[Kk]23 - /", $awardNo)) {
			return "Individual K";
		} else if (preg_match("/^\d?[Kk]24/", $awardNo)) {
			return "Mentoring/Training Grant Admin";
		} else if (preg_match("/^\d?[Rr]03/", $awardNo)) {
			return "N/A";
		} else if (preg_match("/^\d?I01[BC]X/", $awardNo)) {
			return "R01 Equivalent";
		} else if (preg_match("/^\d?IK2[BC]X/", $awardNo)) {
			return "K Equivalent";
        } else if (preg_match("/^\d?R37/", $awardNo)) {
            return "R01 Equivalent";
        } else if (preg_match("/^\d?R35/", $awardNo)) {
            return "R01 Equivalent";
        } else if (preg_match("/^\d?[Dd][Pp]1/", $awardNo)) {
            return "R01 Equivalent";
        } else if (preg_match("/^\d?DP7/", $awardNo) || preg_match("/^\d?[Rr]25/", $awardNo) || preg_match("/^\d?[Tt]90/", $awardNo)) {
		    return "Mentoring/Training Grant Admin";
		} else if (preg_match("/Internal K/", $awardNo)) {
			return "Internal K";
		} else if (preg_match("/K12\/KL2/", $awardNo)) {
			return "K12/KL2";
		} else if (preg_match("/Individual K/", $awardNo)) {
			return "Individual K";
		} else if (preg_match("/^\d?[Kk]99/", $awardNo)) {
			return "K99/R00";
		} else if (preg_match("/^\d?[Kk]\d\d/", $awardNo)) {
			if (!preg_match("/[Kk]99/", $awardNo)) {
				# after all other special cases for K
				return "Individual K";
			}
		} else if (isset($specs['sponsor']) && $specs['sponsor'] == "Veterans Administration, Tennessee") {
			return "K Equivalent";
		} else if (isset($specs['sponsor_type']) && ($specs['sponsor_type'] == "Non-Profit - Foundations/ Associations")) {
			if (($specs['percent_effort'] >= 50) && ($specs['direct_budget'] >= 50000)) {
				return "K Equivalent";
			}
		}

		if (self::getShowDebug()) {
		    Application::log($awardNo.": Final Pass");
		    Application::log($awardNo.": ".json_encode($specs));
        }
		return "N/A";
	}

	# returns key/value pair; key = REDCap variable name for $i-th variable; value = value in REDCap
	public function getREDCapVariables($i) {
		# key = name of summary_award in REDCap
		# value = variable name in $specs
		$variables = array(
					"date" => "start",
					"end_date" => "end",
					"title" => "title",
					"last_update" => "last_update",
					"type" => "type",
					"source" => "source",
					"sourcetype" => "sourcetype",
					"sponsorno" => "sponsor_award_no",
					"nih_mechanism" => "nih_mechanism",
					"total_budget" => "budget",
					"direct_budget" => "direct_budget",
					"percent_effort" => "effort",
                    "role" => "role",
					);
		$ary = array();
		$awardTypeConversion = self::getAwardTypes();
		foreach ($variables as $redcapVar => $specsVar) {
			$fullREDCapVar = "summary_award_".$redcapVar."_".$i;
            if ($specsVar == "last_update") {
                if (isset($this->specs[$specsVar])) {
                    $ts = strtotime($this->specs[$specsVar]);
                    $ary[$fullREDCapVar] = date("Y-m-d", $ts);
                } else {
                    $ary[$fullREDCapVar] = "";
                }
            } else if ($specsVar == "type") {
				$ary[$fullREDCapVar] = (isset($this->specs[$specsVar]) ? "{$awardTypeConversion[$this->specs[$specsVar]]}" : "");
			} else if (preg_match("/budget/", $redcapVar)) {
				$ary[$fullREDCapVar] = (isset($this->specs[$specsVar]) ? self::convertToMoney($this->specs[$specsVar]) : "");
			} else if ($redcapVar == "sourcetype") {
				$ary[$fullREDCapVar] = $this->getSourceType();
			} else {
				$ary[$fullREDCapVar] = (isset($this->specs[$specsVar]) ? $this->specs[$specsVar] : "");
			}
		}
		return $ary;
	}

	public static function convertToMoney($float) {
        if ($float === "") {
            return "";
        }
		$str = (string) round($float, 2);
		if (preg_match("/^\d+\.\d$/", $str)) {
			return $str."0";
		}
		return $str;
	}

	public static function getReverseAwardTypes() {
		$awardTypes = self::getAwardTypes();
		return REDCapManagement::reverseArray($awardTypes);
	}

	public static function getReverseFundingSources() {
		$sources = self::getFundingSources();
		return REDCapManagement::reverseArray($sources);
	}

	public static function getReverseIndustries() {
		$industries = self::getIndustries();
		return REDCapManagement::reverseArray($industries);
	}

	public static function getIndustries() {
		$industries = array(
					"N/A" => 99,
					);
		return $industries;
	}

	public static function getFundingSources($type = "All") {
		$federalFundingSources = array(
						"NIH" => 1,
						"NIH Subcontract" => 2,
						"CDC" => 3,
						"CDC Subcontract" => 4,
						"CMS" => 5,
						"CMS Subcontract" => 6,
						"DOD" => 7,
						"DOD Subcontract" => 8,
						"VA" => 9,
						"AHRQ" => 10,
						"AHRQ Subcontract" => 11,
						"HHS" => 12,
						"HHS Subcontract" => 13,
						"HRSA" => 14,
						"HRSA Subcontract" => 15,
						"FDA" => 16,
						"FDA Subcontract" => 17,
						"ONC" => 18,
						"ONC Subcontract" => 19,
						"Federal: Other" => 21,
						);
		$industryFundingSources = array(
						"Foundation/Non-Profit" => 22,
						"State" => 23,
						"Non-Federal: Other" => 24,
						"Industry: Contract" => 25,
						"Industry: Grant" => 26,
						);
		$na = array(
				"N/A" => 99,
				);
		if ($type == "Federal") {
			return array_merge($federalFundingSources, $na);
		} else if (($type == "Non-Federal") || ($type == "Industry")) {
			return array_merge($industryFundingSources, $na);
		} else {
			# All
			return array_merge($federalFundingSources, $industryFundingSources, $na);
		}
		return $fundingSources;
	}

	public static function convertGrantTypesToStrings($ary) {
	    $awardTypes = self::getReverseAwardTypes();
	    $newAry = [];
	    foreach ($ary as $item) {
	        $newAry[] = $awardTypes[$item];
        }
	    return $newAry;
    }

	public static function getAwardTypes() {
		$awardTypes = array (
				"Internal K" => 1,
				"K12/KL2" => 2,
				"Individual K" => 3,
				"K Equivalent" => 4,
				"R01" => 5,
				"R01 Equivalent" => 6,
				"Training Appointment" => 10,
				"Research Fellowship" => 7,
                "Mentoring/Training Grant Admin" => 8,
                "Training Grant Admin" => 8,
				"K99/R00" => 9,
				"N/A" => 99,
		);
		return $awardTypes;
	}

	public static function setShowDebug($b) {
	    self::$showDebug = $b;
    }

    public static function getShowDebug() {
	    return self::$showDebug;
    }

	private $specs = array();
	private $translator;
	private static $showDebug = FALSE;
	public static $fdnOrOther = "Fdn-or-Other";
	public static $noNameAssigned = "No Title Assigned";
}

