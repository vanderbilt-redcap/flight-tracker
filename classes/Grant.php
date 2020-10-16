<?php

namespace Vanderbilt\CareerDevLibrary;


# This file compiles all of the grants from various data sources and compiles them into an ordered list of grants.
# It should remove duplicate grants as well.
# Unit-testable.

require_once(dirname(__FILE__)."/Download.php");
require_once(dirname(__FILE__)."/GrantLexicalTranslator.php");
require_once(dirname(__FILE__)."/Links.php");
require_once(dirname(__FILE__)."/../Application.php");

define('SHOW_GRANT_DEBUG', FALSE);

class Grant {
	public function __construct($lexicalTranslator) {
		$this->translator = $lexicalTranslator;
	}

	public function isInternalVanderbiltGrant() {
        return preg_match("/VUMC\s*\d+/", $this->getNumber());
    }

	public static function transformToBaseAwardNumber($num) {
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
		if (preg_match("/^\d+[A-Za-z]\d/", $num)) {
			$num = preg_replace("/^\d+/", "", $num);
		}
		if (preg_match("/\s\d+[A-Za-z]\d/", $num)) {
			$num = preg_replace("/\s\d+([A-Za-z]\d)/", "\\1", $num);
		}
		if (preg_match("/\S+[\(]\d*[A-Za-z]\d/", $num)) {
			$num = preg_replace("/^\S+\(\d*([A-Za-z]\d)/", "\\1", $num);
			$num = preg_replace("/(\d)\).*$/", "\\1", $num);
		}
		if (preg_match("/\d[A-Za-z]\d/", $num)) {
			$num = preg_replace("/\s/", "", $num);
		}
		$num = preg_replace("/-[^\-]*$/", "", $num);
		$num = preg_replace("/\s/", "", $num);
		return $num;
	}

	public function isNIH() {
	    # from https://era.nih.gov/files/Deciphering_NIH_Application.pdf
        $nihInstituteCodes = ["TW", "TR", "AT", "CA", "EY", "HG", "HL", "AG", "AA", "AI", "AR", "EB", "HD", "DA", "DC", "DE", "DK", "ES", "GM", "MH", "MD", "NS", "NR", "LM"];
	    $ary = self::parseNumber($this->getNumber());
        return in_array($ary['institute_code'], $nihInstituteCodes);
    }

	public function getVariable($type) {
		if (($type == "type") && !isset($this->specs[$type])) {
			$this->putInBins();
		}
		if ($type == "total_budget") {
			$type = "budget";
		}
		if (isset($type) && isset($this->specs[$type])) {
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
			return "$0";
		}
		return "\$".pretty($n, 2);
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
			} else {
			}
		}
		return FALSE;
	}

	public function setVariable($type, $value) {
		if (isset($type) && isset($value)) {
			$this->specs[$type] = $value;
		}
	}

	public function getBaseAwardNumber() {
		return $this->getBaseNumber();
	}

	public function getBaseNumber() {
		return $this->getVariable("base_award_no");
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
		$ary = array();
		if (preg_match("/[A-Z][A-Z\d]\d[A-Z][A-Z]\d\d\d\d\d\d/", $awardNo)) {
			if (preg_match("/^\d[A-Z][A-Z\d]\d[A-Z][A-Z]\d\d\d\d\d\d/", $awardNo)) {
				// $ary["application_type"] = self::getApplicationType($awardNo);
			} else {
				$awardNo = "0".$awardNo;
			}
			$ary["activity_code"] = self::getActivityCode($awardNo);
			$ary["activity_type"] = self::getActivityType($ary["activity_code"]);
			$ary["funding_institute"] = self::getFundingInstitute($awardNo);
			$ary["institute_code"] = self::getInstituteCode($awardNo);
			$ary["serial_number"] = self::getSerialNumber($awardNo);
			// $ary["support_year"] = self::getSupportYear($awardNo);
			// $ary["other_suffixes"] = self::getOtherSuffixes($awardNo);
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
		if (preg_match("/^\d[A-Z][A-Z\d]\d/", $awardNo)) {
			return substr($awardNo, 4, 2);
		} else if (preg_match("/^[A-Z][A-Z\d]\d/", $awardNo)) {
			return substr($awardNo, 3, 2);
		} else {
			$baseAwardNo = self::translateToBaseAwardNumber($awardNo);
			if (preg_match("/^[A-Z][A-Z\d]\d/", $baseAwardNo)) {
				return substr($baseAwardNo, 3, 2);
			}
		}
		return "";
	}

	# https://www.nlm.nih.gov/bsd/grant_acronym.html
	private static function getFundingInstitute($awardNo) {
		$instituteCode = self::getInstituteCode($awardNo);
		switch ($instituteCode) {
			case "TW":
				return "John E. Fogarty International Center";
				break;
			case "TR":
				return "National Center for Advancing Translational Sciences (NCATS)";
				break;
			case "AT":
				return "National Center for Complementary and Integrative Health";
				break;
			case "CA":
				return "National Cancer Institute";
				break;
			case "EY":
				return "National Eye Institute";
				break;
			case "HG":
				return "National Human Genome Research Institute";
				break;
			case "HL":
				return "National Heart, Lung, and Blood Institute";
				break;
			case "AG":
				return "National Institute on Aging";
				break;
			case "AA":
				return "National Institute on Alcohol Abuse and Alcoholism";
				break;
			case "AI":
				return "National Institute of Allergy and Infectious Diseases";
				break;
			case "AR":
				return "National Institute of Arthritis and Musculoskeletal and Skin Diseases"; 
				break;
			case "EB":
				return "National Institute of Biomedical Imaging and Bioengineering";
				break;
			case "HD":
				return "Eunice Kennedy Shriver National Institute of Child Health and Human Development";
				break;
			case "DA":
				return "National Institute on Drug Abuse";
				break;
			case "DC":
				return "National Institute on Deafness and Other Communication Disorders";
				break;
			case "DE":
				return "National Institute of Dental and Craniofacial Research";
				break;
			case "DK":
				return "National Institute of Diabetes and Digestive and Kidney Diseases";
				break;
			case "ES":
				return "National Institute of Environmental Health Sciences";
				break;
			case "GM":
				return "National Institute of General Medical Sciences";
				break;
			case "MH":
				return "National Institute of Mental Health";
				break;
			case "MD":
				return "National Institute on Minority Health and Health Disparities";
				break;
			case "NS":
				return "National Institute of Neurological Disorders and Stroke";
				break;
			case "NR":
				return "National Institute of Nursing Research";
				break;
			case "LM":
				return "National Library of Medicine";
				break;
			case "BX":
				return "Veterans Administration";
				break;
			case "CX":
				return "Veterans Administration";
				break;
			case "XW":
				return "Department of Defense";
			default:
				return "";
				break;
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

	private static function getSupportYear($awardNo) {
		if (preg_match("/\-/", $awardNo)) {
			$nodes = preg_split("/\-/", $awardNo);
			$tail = $nodes[1];
			if (strlen($tail) >= 2) {
				return substr($awardNo, 0, 2);
			}
		}
		return "";
	}

	private static function getOtherSuffixes($awardNo) {
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
						break;
					case "S":
						return "Revision Record ".$number;
						break;
					default:
						return $suffix;
						break;
				}
			}
		}
		return "";
	}

	private static function getApplicationType($awardNo) {
		$appType = substr($awardNo, 0, 1);
		switch ($appType) {
			case "":
				return "";
				break;
			case 1:
				return "New";
				break;
			case 2:
				return "Renewal";
				break;
			case 3:
				return "Revision";
				break;
			case 4:
				return "Extension";
				break;
			case 5:
				return "Non-competing Continuation";
				break;
			case 6:
				return "Change of Organization Status (Successor-In-Interest)";
				break;
			case 7:
				return "Change of Grantee or Training Institution";
				break;
			case 8:
				return "Change of Institute or Center";
				break;
			case 9:
				return "Change of Institute or Center";
				break;
			default:
				return "";
				break;
		}
	}

	public function isFederal() {
		$src = $this->getVariable("source");
		if (in_array($src, array("exporter", "reporter"))) {
			return TRUE;
		} else if ($src == "coeus") {
			$isFederal = array(
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
					);
			$directSponsorType = $this->getVariable("direct_sponsor_type");
			$primeSponsorType = $this->getVariable("prime_sponsor_type");
			if (($isFederal[$primeSponsorType] == "Federal") && ($directSponsorType != "State - Tennessee")) {
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
            "reporter" => 0,
            "ldap" => 0,
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
			return $ary['activity_code'].$ary['institute_code'].$ary['serial_number'];
		} else if (preg_match("/^VUMC\d+\(.+\)$/", $numWithoutSpaces)) {
			$numWithoutSpaces = preg_replace("/^VUMC\d+\(/", "", $numWithoutSpaces);
			$numWithoutSpaces = preg_replace("/\)$/", "", $numWithoutSpaces);
			$ary = self::parseNumber($numWithoutSpaces);
			if (!empty($ary)) {
				return $ary['activity_code'].$ary['institute_code'].$ary['serial_number'];
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

	# uses private variable specs
	public function getFundingSource() {
		$specs = $this->specs;

		if ($specs["source"] == "coeus") {
			if (preg_match("/\b000\b/", $specs['sponsor'])) {
				return "N/A";
			}
			$primeSponsorType = $specs['prime_sponsor_type'];
			$primeSponsorName = $specs['prime_sponsor_name'];
			$directSponsorType = $specs['direct_sponsor_type'];
			$directSponsorName = $specs['direct_sponsor_name'];
			$isFederal = $this->isFederal();

			if ($isFederal) {
				$matchedAgency = "";
				switch($primeSponsorType) {
					case "DOD":
						return Grant::tellIfSubcontract("DOD", $primeSponsorType, $directSponsorType);
					case "NIH":
						return Grant::tellIfSubcontract("NIH", $primeSponsorType, $directSponsorType);
					case "PHS":
						$agencies = array(
								"CDC" => "CDC",
								"Centers for Medicare and Medicaid Services" => "CMS",
								"Agency for Healthcare Research and Quality" => "AHRQ",
								"Health and Human Services" => "HHS",
								"Health Resources and Services Administration" => "HRSA",
								"Health Services Research Administration" => "HRSA",   // old name
								"Food and Drug Administration" => "FDA",
								"Health Information Technology" => "ONC",
								);
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
		}
		return "N/A";
	}

	public static function getCoeusSources() {
	    return ["coeus", "coeus2"];
    }

	# uses private variable specs
	# Finds the award type
	# difficult
	private function getCurrentType() {
		$specs = $this->specs;
		$coeusSources = self::getCoeusSources();
		$awardNo = $this->getNumber();

		if (SHOW_GRANT_DEBUG) { Application::log($awardNo.": First Pass"); }
		if ($type = $this->lexicallyTranslate($awardNo)) {
			return $type;
		}

		if (SHOW_GRANT_DEBUG) { Application::log($awardNo.": Second Pass"); }
		$trainingGrantSources = array("coeus", "reporter", "exporter");
		if (($awardNo == "") || preg_match("/\b000\b/", $awardNo)) {
			return "N/A";
		} else if (($specs['pi_flag'] == "N") && !(preg_match("/\d[Kk][1L]2/", $awardNo))) {
			return "N/A";
		} else if (preg_match("/^\d?[Kk][1L]2/", $awardNo)) {
			if (preg_match("/\d[Kk][1L]2/", $awardNo) || preg_match("/^[Kk][1L]2/", $awardNo) || preg_match("/[Kk][1L]2$/", $awardNo)) {
				if (($specs['pi_flag'] == "N") && (in_array($specs['source'], $coeusSources))) {
					// return "K12/KL2";
				} else if (($specs['pi_flag'] == "Y") && (in_array($specs['source'], $trainingGrantSources ))) {
					return "Training Grant Admin";
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
			return "Training Grant Admin";
		} else if ($specs['direct_budget'] && ($specs['direct_budget'] >= 750000)) {
			# not R01 or R00
			if ($specs['project_start'] && $specs['project_end']) {
				$projStart = strtotime($specs['project_start']);
				$projEnd = strtotime($specs['project_end']);
				if ($projStart && $projEnd) {
					# 3 years
					$yearspan = ($projEnd - $projStart) / (365 * 24 * 3600);
					if (($yearspan >= 3) && ($specs['direct_budget'] / $yearspan > 250000)) {
						if (!preg_match("/^\d?[Kk]\d\d/", $awardNo)) {
							if (SHOW_GRANT_DEBUG) { Application::log($awardNo.": Second Pass - R01 Equivalent ".(($projEnd - $projStart) / (365 * 24 * 3600))); }
							return "R01 Equivalent";
						} else {
							if (SHOW_GRANT_DEBUG) { Application::log($awardNo.": Second Pass - exit D"); }
						}
					} else {
						if (SHOW_GRANT_DEBUG) { Application::log($awardNo.": Second Pass - exit C"); }
					}
				} else {
					if (SHOW_GRANT_DEBUG) { Application::log($awardNo.": Second Pass - exit B"); }
				}
			} else {
				if (SHOW_GRANT_DEBUG) { Application::log($awardNo.": Second Pass - exit A"); }
			}
		}

		if (SHOW_GRANT_DEBUG) { Application::log($awardNo.": Third Pass"); }
		if (preg_match("/^[Kk]23 - /", $awardNo)) {
			return "Individual K";
		} else if (preg_match("/^\d?[Kk]24/", $awardNo)) {
			return "N/A";
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
		} else if ($specs['sponsor_type'] && ($specs['sponsor_type'] == "Non-Profit - Foundations/ Associations")) {
			if (($specs['percent_effort'] >= 50) && ($specs['direct_budget'] >= 50000)) {
				return "K Equivalent";
			}
		}

		if (SHOW_GRANT_DEBUG) {
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
			if ($specsVar == "type") {
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
		$str = (string) round($float, 2);
		if (preg_match("/^\d+\.\d$/", $str)) {
			return $str."0";
		}
		return $str;
	}

	public static function getReverseAwardTypes() {
		$awardTypes = self::getAwardTypes();
		return self::reverseArray($awardTypes);
	}

	public static function getReverseFundingSources() {
		$sources = self::getFundingSources();
		return self::reverseArray($sources);
	}

	public static function getReverseIndustries() {
		$industries = self::getIndustries();
		return self::reverseArray($industries);
	}

	private static function reverseArray($ary) {
		$reverse = array();
		foreach ($ary as $type => $val) {
			$reverse[$val] = $type;
		}
		return $reverse;
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
				"Training Grant Admin" => 8,
				"K99/R00" => 9,
				"N/A" => 99,
		);
		return $awardTypes;
	}

	private $specs = array();
	private $translator;
}

