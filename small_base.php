<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\CohortConfig;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Crons;
use \Vanderbilt\CareerDevLibrary\Definitions;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\EmailManager;
use \Vanderbilt\CareerDevLibrary\FederalExPORTER;
use \Vanderbilt\CareerDevLibrary\Filter;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\GrantFactory;
use \Vanderbilt\CareerDevLibrary\GrantLexicalTranslator;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\LDAP;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\ModuleUnitTester;
use \Vanderbilt\CareerDevLibrary\NIHExPORTER;
use \Vanderbilt\CareerDevLibrary\NavigationBar;
use \Vanderbilt\CareerDevLibrary\OracleConnection;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\SummaryGrants;
use \Vanderbilt\CareerDevLibrary\Survey;
use \Vanderbilt\CareerDevLibrary\UnitTester;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\iCite;


require_once(dirname(__FILE__)."/classes/Publications.php");
require_once(dirname(__FILE__)."/classes/Grants.php");
require_once(dirname(__FILE__)."/classes/EmailManager.php");
require_once(dirname(__FILE__)."/classes/Download.php");
require_once(dirname(__FILE__)."/classes/Upload.php");
require_once(dirname(__FILE__)."/classes/NavigationBar.php");
require_once(dirname(__FILE__)."/CareerDev.php");

ini_set("memory_limit", "4096M");
date_default_timezone_set(CareerDev::getTimezone());

define('INSTITUTION', CareerDev::getInstitution());
define('PROGRAM_NAME', CareerDev::getProgramName());
define("ENVIRONMENT", "prod");      // for Oracle database connectivity

if (!$module) {
	$module = CareerDev::getModule();
}
$token = CareerDev::getSetting("token");
$server = CareerDev::getSetting("server");
$pid = CareerDev::getSetting("pid");
$event_id = CareerDev::getSetting("event_id");
$tokenName = CareerDev::getSetting("tokenName");
$adminEmail = CareerDev::getSetting("admin_email");

if (!$module) {
	throw new \Exception("The base class has no module!");
}

if (!$token && USERID && $module->canRedirectToInstall()) {
	header("Location: ".CareerDev::link("install.php"));
}

$GLOBALS['pid'] = $pid;
$GLOBALS['server'] = $server;
$GLOBALS['token'] = $token;
$GLOBALS['tokenName'] = $tokenName;
$GLOBALS['namesForMatch'] = array();
$GLOBALS['event_id'] = $event_id;
$GLOBALS['module'] = $module;
$GLOBALS['adminEmail'] = $adminEmail;

############# FUNCTIONS ################

function getBaseAwardNumber($num) {
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

function getDepartmentChoices() {
		$choices2[104300] = "Anesthesiology [104300]";
		$choices2[104250] = "Biochemistry [104250]";
		$choices2[120450] = "Biological Sciences [120450]";
		$choices2[104785] = "Biomedical Informatics [104785]";
		$choices2[104286] = "Cancer Biology [104286]";
		$choices2[104280] = "Cell and Developmental Biology [104280]";
		$choices2[104226] = "Center for Human Genetics Research [104226]";
		$choices2[120430] = "Chemistry [120430]";
		$choices2[104791] = "Emergency Medicine/Administration [104791]";
		$choices2[104625] = "Health Policy [104625]";
		$choices2[104782] = "Hearing And Speech Sciences [104782]";
		$choices2[104216] = "Institute for Global Health [104216]";
		$choices2[130100] = "Kennedy Center Institute (MC) [130100]";
		$choices2[122450] = "Mechanical Engineering [122450]";
		$choices2[104368] = "Medicine [104368]";
		$choices2[104383] = "Medicine/Allergy Pulmonary & Critical Care [104383]";
		$choices2[104333] = "Medicine/Cardiovascular Medicine [104333]";
		$choices2[104342] = "Medicine/Clinical Pharmacology [104342]";
		$choices2[104348] = "Medicine/Dermatology [104348]";
		$choices2[104351] = "Medicine/Diabetes Endocrinology [104351]";
		$choices2[104370] = "Medicine/Epidemiology [104370]";
		$choices2[104355] = "Medicine/Gastroenterology [104355]";
		$choices2[104366] = "Medicine/General Internal Medicine [104366]";
		$choices2[104353] = "Medicine/Genetic Medicine [104353]";
		$choices2[104379] = "Medicine/Hematology Oncology [104379]";
		$choices2[104362] = "Medicine/Infectious Disease [104362]";
		$choices2[104375] = "Medicine/Nephrology [104375]";
		$choices2[104386] = "Medicine/Rheumatology [104386]";
		$choices2[104336] = "Medicine/Stahlman Cardio Research [104336]";
		$choices2[104270] = "Molecular Physiology & Biophysics [104270]";
		$choices2[104400] = "Neurology [104400]";
		$choices2[104407] = "Neurology/Cognitive Disorders [104407]";
		$choices2[104403] = "Neurology/Epilepsy [104403]";
		$choices2[104412] = "Neurology/Immunology [104412]";
		$choices2[104409] = "Neurology/Movement Disorders [104409]";
		$choices2[104415] = "Neurology/Neuromuscular [104415]";
		$choices2[104418] = "Neurology/Oncology [104418]";
		$choices2[104410] = "Neurology/Sleep Disorders [104410]";
		$choices2[104425] = "Obstetrics and Gynecology [104425]";
		$choices2[104450] = "Ophthalmology [104450]";
		$choices2[104481] = "Ortho - Oncology [104481]";
		$choices2[104475] = "Orthopaedics and Rehabilitation [104475]";
		$choices2[999999] = "Other (999999)";
		$choices2[104781] = "Otolaryngology [104781]";
		$choices2[104500] = "Pathology [104500]";
		$choices2[104555] = "Pediatrics/Adolescent Medicine [104555]";
		$choices2[104565] = "Pediatrics/Cardiology [104565]";
		$choices2[104570] = "Pediatrics/Child Development [104570]";
		$choices2[104568] = "Pediatrics/Clinical Research Office [104568]";
		$choices2[104582] = "Pediatrics/Emergency Medicine [104582]";
		$choices2[104580] = "Pediatrics/Endocrinology [104580]";
		$choices2[104585] = "Pediatrics/Gastroenterology [104585]";
		$choices2[104595] = "Pediatrics/General Pediatrics [104595]";
		$choices2[104590] = "Pediatrics/Genetics [104590]";
		$choices2[104598] = "Pediatrics/Hematology [104598]";
		$choices2[104623] = "Pediatrics/Hospital Medicine [104623]";
		$choices2[104606] = "Pediatrics/Infectious Disease [104606]";
		$choices2[104610] = "Pediatrics/Neonatology [104610]";
		$choices2[104600] = "Pediatrics/Neurology [104600]";
		$choices2[104621] = "Pediatrics/Pulmonary [104621]";
		$choices2[104592] = "Pediatrics/Vanderbilt-Meharry Center in Sickle Cell [104592]";
		$choices2[104290] = "Pharmacology [104290]";
		$choices2[104291] = "Pharmacology/Clin Pharm [104291]";
		$choices2[104795] = "Physical Medicine and Rehabilitation [104795]";
		$choices2[104529] = "Psychiatry/Adult Psychiatry [104529]";
		$choices2[104535] = "Psychiatry/Child & Adolescent Psychiatry [104535]";
		$choices2[120660] = "Psychology [120660]";
		$choices2[104675] = "Radiation Oncology [104675]";
		$choices2[104650] = "Radiology and Radiological Science [104650]";
		$choices2[106052] = "School of Nursing - Research Faculty [106052]";
		$choices2[104703] = "Section of Surgical Science [104703]";
		$choices2["SFS"] = "Service Free Stipends [SFS]";
		$choices2[126230] = "Special Education [126230]";
		$choices2[104477] = "Sports Medicine [104477]";
		$choices2[104705] = "Surgery [104705]";
		$choices2[104714] = "Surgery/Liver Transplant [104714]";
		$choices2[104760] = "Surgery/Pediatric Surgery [104760]";
		$choices2[104709] = "Surgery/Surgical Oncology [104709]";
		$choices2[104726] = "Surgery/Thoracic Surgery [104726]";
		$choices2[104717] = "Surgery/Trauma [104717]";
		$choices2[104775] = "Urologic Surgery [104775]";
		$choices2[104201] = "Vanderbilt Vaccine Center [104201]";
		$choices2[104268] = "Biostatistics (104268)";
		$choices2[104267] = "Biostatistics/Cancer Biostatistics (104267)";
		$choices2[104202] = "Center for Biomedical Ethics and Society (104202)";
		$choices2[104790] = "Emergency Medicine (104790)";
		$choices2[104204] = "Institute of Medicine and Public Health (104204)";
		$choices2[120727] = "Medicine, Health & Society (120727)";
}

function getCommentsForRecord($record) {
	global $pid;
	global $server;

	$ch = curl_init();
	$url = $server."../plugins/career_dev/getCommentsForRecord.php?pid=$pid&record=$record";
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_VERBOSE, 0);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_AUTOREFERER, true);
	curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
	curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
	$output = curl_exec($ch);
	curl_close($ch);
	$comments = json_decode($output, true);
	return $comments;
}

function submitCommentsForRecord($record, $field_name, $comment) {
	global $pid;
	global $server;

	$ch = curl_init();
	$url = $server."../plugins/career_dev/submitCommentsForRecord.php?pid=$pid&record=$record&field_name=$field_name&comment=".urlencode($comment);
	echo "URL: $url\n";
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_VERBOSE, 0);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_AUTOREFERER, true);
	curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
	curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
	$output = curl_exec($ch);
	echo "Output: $output\n";
	curl_close($ch);
}

function getReverseAwardTypes() {
	return Grant::getReverseAwardTypes();
}

function getAwardTypes() {
	return Grant::getAwardTypes();
}

function getRecordIdJson($recordId) {
	global $token, $server;

	$redcapData = array();

	if ($recordId) {
		$data = array(
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'records' => array($recordId),
			'type' => 'flat',
			'rawOrLabel' => 'raw',
			'rawOrLabelHeaders' => 'raw',
			'exportCheckboxLabel' => 'false',
			'exportSurveyFields' => 'false',
			'exportDataAccessGroups' => 'false',
			'returnFormat' => 'json'
		);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $server);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
		$output = curl_exec($ch);
		curl_close($ch);

		$redcapData = json_decode($output, true);
	}
	return $redcapData;
}

# return boolean
function coeusNameMatch($n1, $n2) {
	if (($n1 == "ho") || ($n2 == "ho") && (($n1 == "holden") || ($n2 == "holden"))) {
		if ($n1 == $n2) {
			return true;
		}
	} else {
		if (preg_match("/^".$n1."/", $n2) || preg_match("/^".$n2."/", $n1)) {
			return true;
		}
	}
	if (preg_match("/[\(\-]".$n1."/", $n2) || preg_match("/[\(\-]".$n2."/", $n1)) {
		return true;
	}
	return false;
}

# get rid of trailing initial
function coeusFixForMatch($n) {
	$n = preg_replace("/\s+\w\.$/", "", $n);
	$n = preg_replace("/\s+\w$/", "", $n);
	$n = str_replace("???", "", $n);
	return strtolower($n);
}

# returns true/false over whether the names "match"
function coeusMatch($fn1, $ln1, $fn2, $ln2) {
	if (preg_match("/\s+\(.+\)/", $fn1)) {
		$fn1 = preg_replace("/\s+\(.+\)/", "", $fn1);
	}
	$ln1s = array($ln1);
	if (preg_match("/[\s\-]/", $ln1)) {
		array_push($ln1s, preg_replace("/\-/", " ", $ln1), preg_replace("/\s/", "-", $ln1));
	}
	if (preg_match("/[a-z][A-Z]/", $ln1)) {
		array_push($ln1s, preg_replace("/([a-z])([A-z])/", "$1 $2", $ln1));
	}
	if (preg_match("/[a-z] [A-Z]/", $ln1)) {
		array_push($ln1s, preg_replace("/([a-z]) ([A-z])/", "$1$2", $ln1));
	}
	if (preg_match("/\s+\(.+\)/", $fn2)) {
		$fn2 = preg_replace("/\s+\(.+\)/", "", $fn2);
	}
	$ln2s =  array($ln2);
	if (preg_match("/[\s\-]/", $ln2)) {
		array_push($ln2s, preg_replace("/\-/", " ", $ln2), preg_replace("/\s/", "-", $ln2));
	}
	if (preg_match("/[a-z][A-Z]/", $ln2)) {
		array_push($ln2s, preg_replace("/([a-z])([A-z])/", "$1 $2", $ln2));
	}
	if (preg_match("/[a-z] [A-Z]/", $ln2)) {
		array_push($ln2s, preg_replace("/([a-z]) ([A-z])/", "$1$2", $ln2));
	}
	foreach ($ln1s as $ln1a) {
		foreach ($ln2s as $ln2a) {
			if ($fn1 && $ln1a && $fn2 && $ln2a) {
				$fn1 = coeusFixForMatch($fn1);
				$fn2 = coeusFixForMatch($fn2);
				$ln1a = coeusFixForMatch($ln1a);
				$ln2a = coeusFixForMatch($ln2a);
				if (coeusNameMatch($fn1, $fn2) && coeusNameMatch($ln1a, $ln2a)) {
					return true;
				}
			}
		}
	}
	return false;
}

# returns recordId that matches
# returns "" if no match
function matchName($first, $last) {
	$ary = matchNames(array($first), array($last));
	if (count($ary) > 0) {
		return $ary[0];
	}
	return "";
}

# returns an array of recordId's that matches respective last, first
# returns "" if no match
function matchNames($firsts, $lasts) {
	global $token, $server;
	global $namesForMatch;
	if (!$firsts || !$lasts) {
		return "";
	}
	if (!$namesForMatch || empty($namesForMatch)) {
		$data = array(
			'token' => $token,
			'content' => 'record',
			'format' => 'json',
			'fields' => array("record_id", "identifier_first_name", "identifier_last_name"),
			'type' => 'flat',
			'rawOrLabel' => 'raw',
			'rawOrLabelHeaders' => 'raw',
			'exportCheckboxLabel' => 'false',
			'exportSurveyFields' => 'false',
			'exportDataAccessGroups' => 'false',
			'returnFormat' => 'json'
		);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $server);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
		$output = curl_exec($ch);
		curl_close($ch);

		$redcapData = json_decode($output, true);
		$namesForMatch = $redcapData;
	} else {
		$redcapData = $namesForMatch;
	}
	$recordIds = array();
	for ($i = 0; $i < count($firsts) && $i < count($lasts); $i++) {
		$myFirst = strtolower($firsts[$i]);
		$myLast = strtolower($lasts[$i]);
		$found = false;
		foreach ($redcapData as $row) {
			$sFirst = strtolower($row['identifier_first_name']);
			$sLast = strtolower($row['identifier_last_name']);
			$matchFirst = false;
			if (preg_match("/".$sFirst."/", $myFirst) || preg_match("/".$myFirst."/", $sFirst)) {
				$matchFirst = true;
			}
			$matchLast = false;
			if (preg_match("/".$sLast."/", $myLast) || preg_match("/".$myLast."/", $sLast)) {
				$matchLast = true;
			}
			if ($matchFirst && $matchLast) {
				$recordIds[] = $row['record_id'];
				$found = true;
				break;
			}
		}
		if (!$found) {
			$recordIds[] = "";
		}
	}
	return $recordIds;
}

function prettyMoney($n, $displayCents = TRUE) {
	if ($displayCents) {
		return "\$".pretty($n, 2);
	} else {
		return "\$".pretty($n, 0);
	}
}
function pretty($n, $numDecimalPlaces = 3) {
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
	if ($decimal && is_int($numDecimalPlaces) && ($numDecimalPlaces > 0)) {
		$decimal = ".".floor($decimal * pow(10, $numDecimalPlaces));
	} else {
		$decimal = "";
	}
	if (!$s) {
		$s = "0";
	}
	if ($n < 0) {
		if (!$decimal) {
			return "-".$s;
		} else {
			return "-".$s.$decimal;
		}
	}
	if (!$decimal) {
		return $s;
	} else {
		return $s.$decimal;
	}
}

# given two timestamps (UNIX) $start, $end - let's call this duration.
# provide the timestamps of a larger period, $yearStart, $yearEnd
# figures the fraction of the year that is filled by the duraction
# returns value | 0 <= value <= 1
function calculateFractionEffort($start, $end, $yearStart, $yearEnd) {
	if ($start && $end) {
		$grantDur = $end - $start;
		$yearDur = $yearEnd - $yearStart;
		$currDur = 0;

		if (($start >= $yearStart) && ($start <= $yearEnd)) {
			if ($end > $yearEnd) {
				$currDur = $yearEnd - $start;
			} else {
				$currDur = $end - $start;
			}
		} else if (($end >= $yearStart) && ($end <= $yearEnd)) {
			# currStart before yearStart
			$currDur = $end - $yearStart;
		} else if (($end > $yearEnd) && ($start < $yearStart)) {
			$currDur = $yearDur;
		}
		return $currDur / $grantDur;
	}
	return 0;
}

# helper function to add to grant totals
# totals is the array with the totals (fields direct, vumc, nonvumc)
# row is the REDCap row with either COEUS or RePORTER/ExPORTER data
# instrument is coeus or exporter, or reporter, or custom
# fraction is the amount of the budget to be allocated for the current year. 0 <= value <= 1
# base award numbers are those base award numbers in reporter that do not need to be included from COEUS
function addToGrantTotals($totals, $row, $instrument, $fraction, $usedBaseAwardNumbers) {
	if ($instrument == "coeus") {
		$awardNo = $row['coeus_sponsor_award_number'];
		$baseAwardNumber = getBaseAwardNumber($awardNo);
		if ($row['coeus_direct_cost_budget_period']) {
			$part = floor($fraction * $row['coeus_direct_cost_budget_period']);
			$totals['direct'] += $part;
			// echo "SUMMARY GRANTS A $fraction direct {$row['coeus_direct_cost_budget_period']} Adding $part = {$totals['direct']} $awardNo\n";
		} else {
			// echo "SUMMARY GRANTS B\n";
		}
		if (!in_array($baseAwardNumber, $usedBaseAwardNumbers) && $row['coeus_total_cost_budget_period']) {
			$part = floor($fraction * $row['coeus_total_cost_budget_period']);
			$totals['vumc'] += $part;
			// echo "SUMMARY GRANTS C $fraction total  {$row['coeus_total_cost_budget_period']} Adding $part = {$totals['vumc']} $awardNo\n";
		} else {
			// echo "SUMMARY GRANTS D '{$row['coeus_total_cost_budget_period']}' $baseAwardNumber: ".json_encode($usedBaseAwardNumbers)."\n";
		}
	} else if ($instrument == "exporter") {
		$awardNo = $row['exporter_full_project_num'];
		$baseAwardNumber = getBaseAwardNumber($awardNo);
		if ($row['exporter_org_name'] && preg_match("/vanderbilt/", strtolower($row['exporter_org_name']))) {
			if (!in_array($baseAwardNumber, $usedBaseAwardNumbers) && $row['exporter_total_cost']) {
				$part = floor($fraction * $row['exporter_total_cost']);
				$totals['vumc'] += $part;
				// echo "SUMMARY GRANTS E $fraction total  {$row['exporter_total_cost']} Adding $part = {$totals['vumc']} $awardNo\n";
			} else {
				// echo "SUMMARY GRANTS F $awardNo seen before\n";
			}
		} else {
			$totals['nonvumc'] += floor($fraction * $row['exporter_total_cost']);
		}
	} else if ($instrument == "reporter") {
		$awardNo = $row['reporter_projectnumber'];
		$baseAwardNumber = getBaseAwardNumber($awardNo);
		if ($row['reporter_orgname'] && preg_match("/vanderbilt/", strtolower($row['reporter_orgname']))) {
			if (!in_array($baseAwardNumber, $usedBaseAwardNumbers) && $row['reporter_totalcostamount']) {
				$part = floor($fraction * $row['reporter_totalcostamount']);
				$totals['vumc'] += $part;
				// echo "SUMMARY GRANTS G $fraction total  {$row['reporter_totalcostamount']} Adding $part = {$totals['vumc']} $awardNo\n";
			} else {
				// echo "SUMMARY GRANTS H $awardNo seen before\n";
			}
		} else {
			$totals['nonvumc'] += floor($fraction * $row['reporter_totalcostamount']);
		}
	} else if ($instrument == "custom_grant") {
		$awardNo = $row['custom_number'];
		$baseAwardNumber = getBaseAwardNumber($awardNo);
		if (!in_array($baseAwardNumber, $usedBaseAwardNumbers) && $row['custom_costs']) {
			$totals['direct'] += floor($fraction * $row['custom_costs']);
			$totals['vumc'] += floor($fraction * $row['custom_costs']);
		}
	}
	return $totals;
}

# gets the time from a RePORTER formatting (YYYY-MM-DDThh:mm:ss);
function getReporterTime($dt) {
	if (!$dt) {
		return "";
	}
	$nodes = preg_split("/T/", $dt);
	if (count($nodes) != 2) {
		return "";
	}
	return $nodes[1];
}
# gets the date from a RePORTER formatting (YYYY-MM-DDThh:mm:ss);
# returns YYYY-MM-DD
function getReporterDate($dt) {
	if (!$dt) {
		return "";
	}
	$nodes = preg_split("/T/", $dt);
	if (count($nodes) != 2) {
		return $nodes[0];
	}
	return $nodes[0];
}

function getCohorts($row) {
	$ary = array();

	$years = getCohort($row);
	$ary[] = $years;

	$KL2s = array("VCTRSKL2", "KL2", "VPSD", "VCTRS");
	for ($i = 1; $i <= 15; $i++) {
		# KL2 or Internal K
		if (preg_match("/KL2/", $row['summary_award_sponsorno_'.$i]) || (in_array($row['summary_award_sponsorno_'.$i], $KL2s) && ($row['summary_award_type_'.$i] == 2)) || ($row['summary_award_type_'.$i] == 1)) {
			if (!in_array("KL2s + Int_Ks", $ary)) {
				$ary[] = "KL2s + Int_Ks";
			}
		} 
	}

	return $ary;
}

function getCohort($row) {
	$begins = array(1998, 2003, 2008, 2013);
	$ends = array(2002, 2007, 2012, 2017);
	for ($i = 1; $i <= 15; $i++) {
		if ($row['summary_award_date_'.$i] && ($row['summary_award_type_'.$i] >= 1) && ($row['summary_award_type_'.$i] <= 4)) {
			$nodes = preg_split("/-/", $row['summary_award_date_'.$i]);
			for ($j = 0; $j < count($begins); $j++) {
				if (($begins[$j] <= $nodes[0]) && ($ends[$j] >= $nodes[0])) {
					return $begins[$j]."-".$ends[$j];
				}
			}
		}
	}
	return "";
}

function json_encode_with_spaces($data) {
	$str = json_encode($data);
	$str = preg_replace("/,/", ", ", $str);
	return $str;
}

function YMD2MDY($ymd) {
	$nodes = preg_split("/[\/\-]/", $ymd);
	if (count($nodes) == 3) {
		$year = $nodes[0];
		$month = $nodes[1];
		$day = $nodes[2];
		return $month."-".$day."-".$year;
	}
	return "";
}

function MDY2YMD($mdy) {
	$nodes = preg_split("/[\/\-]/", $mdy);
	if (count($nodes) == 3) {
		$year = $nodes[2];
		if ($year < 100) {
			if ($year > 30) {
				$year += 1900; 
			} else {
				$year += 2000;
			}
		}
		return $year."-".$nodes[0]."-".$nodes[1];
	}
	return "";
}

function getLabels($metadata) {
	$labels = array();
	foreach ($metadata as $row) {
		$labels[$row['field_name']] = $row['field_label'];
	}
	return $labels;
}

function getChoices($metadata) {
	$choicesStrs = array();
	$multis = array("checkbox", "dropdown", "radio");
	foreach ($metadata as $row) {
		if (in_array($row['field_type'], $multis) && $row['select_choices_or_calculations']) {
			$choicesStrs[$row['field_name']] = $row['select_choices_or_calculations'];
		} else if ($row['field_type'] == "yesno") {
			$choicesStrs[$row['field_name']] = "0,No|1,Yes";
		} else if ($row['field_type'] == "truefalse") {
			$choicesStrs[$row['field_name']] = "0,False|1,True";
		}
	}
	$choices = array();
	foreach ($choicesStrs as $fieldName => $choicesStr) {
		$choicePairs = preg_split("/\s*\|\s*/", $choicesStr);
		$choices[$fieldName] = array();
		foreach ($choicePairs as $pair) {
			$a = preg_split("/\s*,\s*/", $pair);
			if (count($a) == 2) {
				$choices[$fieldName][$a[0]] = $a[1];
			} else if (count($a) > 2) {
				$a = preg_split("/,/", $pair);
				$b = array();
				for ($i = 1; $i < count($a); $i++) {
					$b[] = $a[$i];
				}
				$choices[$fieldName][trim($a[0])] = implode(",", $b);
			}
		}
	}
	return $choices;
}

function getAlphabetizedNames($token, $server) {
	$names = Download::names($token, $server);
	$lastNames = Download::lastnames($token, $server);
	asort($lastNames);

	$orderedNames = array();
	foreach ($lastNames as $recordId => $lastName) {
		$orderedNames[$recordId] = $names[$recordId];
	} 
	return $orderedNames;
}

# $data is JSON or array structure from JSON in REDCap format
# returns a set of REDCap data sorted by last name
# if a record does not have a name, it is appended at the end
function alphabetizeREDCapData($data) {
	if (!is_array($data)) {
		$data = json_decode($data, true);
	}
	if ($data) {
		$names = array();
		$excluded = array();
		if (!isAssoc($data)) {
			foreach ($data as $row) {
				if ($row['identifier_last_name'] && $row['identifier_first_name']) {
					$names[$row['record_id']] = $row['identifier_last_name'].", ".$row['identifier_first_name'];
				}
			}
			foreach ($data as $row) {
				if (!in_array($row['record_id'], $excluded) && !isset($names[$row['record_id']])) {
					$excluded[] = $row['record_id'];
				}
			}
		} else {
			foreach ($data as $recordId => $rows) {
				foreach ($rows as $row) {
					if ($row['identifier_last_name'] && $row['identifier_first_name']) {
						$names[$row['record_id']] = $row['identifier_last_name'].", ".$row['identifier_first_name'];
					}
				}
			}
			foreach ($data as $recordId => $rows) {
				foreach ($rows as $row) {
					if (!in_array($row['record_id'], $excluded) && !isset($names[$row['record_id']])) {
						$excluded[] = $row['record_id'];
					}
				}
			}
		}
		asort($names);
	
		$returnData = array();
		foreach ($names as $recordId => $name) {
			if (!isAssoc($data)) {
				foreach ($data as $row) {
					if ($recordId == $row['record_id']) {
						$returnData[] = $row;
					}
				}
			} else {
				foreach ($data as $recordIdData => $rows) {
					if ($recordId == $recordIdData) {
						$returnData[$recordId] = $rows;
					}
				}
			}
		}
		foreach ($excluded as $recordId) {
			if (!isAssoc($data)) {
				foreach ($data as $row) {
					if ($recordId == $row['record_id']) {
						$returnData[] = $row;
					}
				}
			} else {
				foreach ($data as $recordIdData => $rows) {
					if ($recordId == $recordIdData) {
						$returnData[$recordId] = $rows;
					}
				}
			}
		}
		return $returnData;
	}
	return $data;
}

function getPubTimestamp($citation, $recordId) {
	Publications::getPubTimestamp($citation, $recordId);
}

function getNamesForRow($row) {
	$firstNamesPre = preg_split("/[\s\-]/", $row['identifier_first_name']);
	$firstNames = array();
	foreach ($firstNamesPre as $firstName) {
		if (preg_match("/^\(.+\)$/", $firstName, $matches)) {
			$match = preg_replace("/^\(/", "", $matches[0]);
			$match = preg_replace("/\)$/", "", $match);
			$firstNames[] = $match;
		} else {
			$firstNames[] = $firstName;
		}
	}

	$lastNames = preg_split("/[\s\-]/", $row['identifier_last_name']);

	$names = array($row['identifier_first_name']." ".$row['identifier_last_name']);
	foreach ($firstNames as $firstName) {
		foreach ($lastNames as $lastName) {
			if (!in_array($firstName." ".$lastName, $names)) {
				$names[] = $firstName." ".$lastName;
			}
		}
	}
	return $names;
}

# returns two-item array;
# first item: from array("Converted", "On Internal K", "On External K", "Left", "Not Converted")
# second item: the time the event has happened or will happen. NULL if Not Converted
function getConvertedStatus($normativeRow) {
	if ($normativeRow['summary_first_r01']) {
		return array("Converted", $normativeRow['summary_first_r01']);
	}
	if ($normativeRow['summary_left_vanderbilt']) {
		return array("Left", $normativeRow['summary_left_vanderbilt']);
	}
	if ($normativeRow["summary_last_external_k"]) {
		$today = time();
		$start = strtotime($normativeRow['summary_last_external_k']);
		$endDate = date("m-d", $start);
		$endYear = date("Y", $start) + 5;
		$end = strtotime($endYear."-".$endDate);

		$extKs = array(3, 4);
		for ($i = 1; $i <= 15; $i++) {
			if (in_array($normativeRow['summary_award_type_'.$i], $extKs)) {
				if ($normativeRow['summary_award_end_date_'.$i]) {
					$currEndTs = strtotime($normativeRow['summary_award_end_date_'.$i]);
					if ($currEndTs > $end) {
						$end = $currEndTs;
					}
				}
			}
		} 

		if ($end > $today) {
			return array("On External K", date("Y-m-d", $end));
		}
	}
	if ($normativeRow["summary_last_any_k"]) {
		$today = time();
		$start = strtotime($normativeRow['summary_last_any_k']);
		$endDate = date("m-d", $start);
		$endYear = date("Y", $start) + 3;
		$end = strtotime($endYear."-".$endDate);

		$allKs = array(1, 2, 3, 4);
		for ($i = 1; $i <= 15; $i++) {
			if (in_array($normativeRow['summary_award_type_'.$i], $allKs)) {
				if ($normativeRow['summary_award_end_date_'.$i]) {
					$currEndTs = strtotime($normativeRow['summary_award_end_date_'.$i]);
					if ($currEndTs) {
						$end = $currEndTs;
					}
				}
			}
		}
		if ($end > $today) {
			return array("On Internal K", date("Y-m-d", $end));
		}
	}
	return array("Not Converted", null);
}

function getNextRecord($record) {
	global $token, $server;
	$records = Download::recordIds($token, $server);
	$i = 0;
	foreach ($records as $rec) {
		if ($rec == $record) {
			if ($i < count($records)) {
				return $records[$i + 1];
			} else {
				return $records[0];
			}
		}
		$i++;
	} 
}

function decodeCitations($citationStr) {
	$citationAry = array();
	if ($citationStr) {
		$citationAry = json_decode($citationStr, true);
	}
	return $citationAry;
}

function saveSetting($setting, $value) {
	global $module;
	$module->setProjectSetting($setting, $value);
}

function getSetting($setting) {
	global $module;
	return $module->getProjectSetting($setting);
}

function getSurveys() {
	$emailSurveys = getSetting(getSurveySettingName());
	if ($emailSurveys && !empty($emailSurveys)) {
		return $emailSurveys;
	} else {
		$default = array(
				"Initial Survey" => "check_date",
				"Follow-Up Survey(s)" => "followup_date",
				);
		$json = json_encode($default);
		saveEmailSetting(getSurveySettingName(), $default);
		return $default;
	}
}

function getSurveyTypes() {
	return getSurveys();
}

function getAllEmailSettings() {
	return getSurveySettings();
}

function getSurveySettings() {
	return getAllSettings();
}

function saveEmailSetting($setting, $value) {
	return saveSetting($setting, $value);
}

function getEmailSetting($setting) {
	return getSetting($setting);
}

function dateSort(&$data) {
	$newData = array();
	foreach ($data as $key => $date) {
		$newData[$key] = strtotime($date);
	}
	arsort($newData);

	$returnData = array();
	foreach ($newData as $key => $ts) {
		$returnData[$key] = $data[$key];
	}
	return $returnData;
}

function getSurveySettingName() {
	return "surveys";
}

function getAllSettings() {
	global $module;
	$data = $module->getProjectSetting(CareerDev::getGeneralSettingName());
	if (is_array($data)) {
		$isDate = TRUE;
		foreach ($data as $key => $value) {
			if (!preg_match("/^\d\d\d\d-\d+-\d+$/", $value)) {
				$isDate = FALSE;
				break;
			}
		}
		if ($isDate) {
			dateSort($data);
		}
		return $data;
	}
	return array();
}

function convertTo1DArray($ary) {
	$ary2 = array();
	foreach ($ary as $i => $id) {
		array_push($ary2, $id);
	}
	return $ary2;
}

function indexREDCapData($redcapData) {
	$indexedRedcapData = array();
	foreach ($redcapData as $row) {
		$recordId = $row['record_id'];
		if (!isset($indexedRedcapData[$recordId])) {
			$indexedRedcapData[$recordId] = array();
		}
		array_push($indexedRedcapData[$recordId], $row);
	}
	return $indexedRedcapData;
}

function getIndexedRedcapData($token, $server, $fields, $cohort = "", $metadata = array()) {
	if ($token && $server && $fields && !empty($fields)) {
		if ($cohort) {
			$records = Download::cohortRecordIds($token, $server, $metadata, $cohort);
		}
		if (!$records) {
			$records = Download::recordIds($token, $server);
		}

		$redcapData = Download::fieldsForRecords($token, $server, $fields, $records);
		return indexREDCapData($redcapData);
	}
	return array();
}

function getCohortSelect($token, $server, $pid) {
	$html = "";
	$html .= "<select onchange='var base = \"?page=".urlencode($_GET['page'])."&prefix=".$_GET['prefix']."&pid=$pid\"; if ($(this).val()) { window.location.href = base+\"&cohort=\" + $(this).val(); } else { window.location.href = base; }'>\n";
	$cohorts = new Cohorts($token, $server, CareerDev::getModule());
	$cohortTitles = $cohorts->getCohortTitles();
	$html .= "<option value=''>---ALL---</option>\n";
	foreach ($cohortTitles as $title) {
	       $html .= "<option value='$title'";
	       if ($title == $_GET['cohort']) {
		      $html .= " selected";
	       }
	       $html .= ">$title</option>\n";
	}
	$html .= "</select>\n";
	return $html;
}

function getCohortHeaderHTML() {
	global $pid;
	$html = "";
	$html .= "<div class='subnav'>\n";
	$html .= "<a class='yellow' href='".CareerDev::link("cohorts/addCohort.php")."'>Add a New Cohort</a>\n";
	$html .= "<a class='yellow' href='".CareerDev::link("cohorts/viewCohorts.php")."'>View Existing Cohorts</a>\n";
	$html .= "<a class='purple' href='".CareerDev::link("cohorts/manageCohorts.php")."'>Manage Cohorts</a>\n";
	$html .= "<a class='purple' href='".CareerDev::link("cohorts/exportCohort.php")."'>Export a Cohort</a>\n";
	$html .= "<a class='green' href='".CareerDev::link("cohorts/profile.php")."'>Cohort Profiles</a>\n";
	$html .= "<a class='green' href='".CareerDev::link("cohorts/selectCohort.php")."'>View Cohort Metrics</a>\n";
	$html .= "</div>\n";

	return $html;
}

function makeHTMLId($id) {
	$htmlFriendly = preg_replace("/\s+/", "_", $id);
	$htmlFriendly = preg_replace("/[\"'#<>\~\`\!\@\#\$\%\^\&\*\(\)]/", "", $htmlFriendly);
	return $htmlFriendly;
}

function makeSafe($htmlStr) {
	return preg_replace("/<[^>]+>/", "", $htmlStr);
}

function changeTextColorOfLink($str, $color) {
	if (preg_match("/<a /", $str)) {
		if (preg_match("/style\s*=\s*['\"]/", $str, $matches)) {
			$match = $matches[0];
			$str = str_replace($match, $match."color: $color; ", $str);
		} else {
			$str = preg_replace("/<a /", "<a style='color: $color;' ", $str);
		}
	}
	return $str;
}

# returns an ary of record id's that match list
function getUploadAryFromRoster($matched) {
	global $token, $server;

	$redcapData = Download::fields($token, $server, array("record_id", "identifier_first_name", "identifier_middle", "identifier_last_name"));

	$names = array();
	foreach ($redcapData as $row) {
		# matched with JavaScript
		if ($row['identifier_middle']) {
			$name = strtolower($row['identifier_first_name']." ".$row['identifier_middle']." ".$row['identifier_last_name']);
		} else {
			$name = strtolower($row['identifier_first_name']." ".$row['identifier_last_name']);
		}
		$names[$name] = $row['record_id'];
	}

	$roster = array();
	$matched = explode("\r\n", $matched);
	foreach ($matched as $name) {
		if ($name) {
			$roster[] = strtolower($name);
		}
	}

	$records = array();
	foreach ($roster as $name) {
		$recordId = $names[$name];
		if ($recordId) {
			# for names that appear twice
			if (!in_array($recordId, $records)) {
				array_push($records, $recordId);
			}
		}
	}
	return $records;
}

function isAssoc($ary) {
	if (empty($ary)) {
		return FALSE;
	}
	return array_keys($ary) !== range(0, count($ary) - 1);
}

function avg($ary) {
	return array_sum($ary) / count($ary);
}

function quartile($ary, $quartile) {
	if (!is_int($quartile)) {
		return FALSE;
	}
	if (($quartile < 0) || ($quartile > 4)) {
		return FALSE;
	}
	if (isAssoc($ary)) {
		return FALSE;
	}

	sort($ary);
	$size = count($ary);
	switch($quartile) {
		case 0:
			return $ary[0];
		case 1:
			$ary2 = array();
			for ($i = 0; $i < $size / 2; $i++) {
				array_push($ary2, $ary[$i]);
			}
			return getMedian($ary2);
		case 2:
			return getMedian($ary);
		case 3:
			$ary2 = array();
			for ($i = ceil($size / 2); $i < $size; $i++) {
				array_push($ary2, $ary[$i]);
			}
			return getMedian($ary2);
		case 4:
			return $ary[$size - 1];
	}
}

function getMedian($ary) {
	sort($ary);
	$size = count($ary);
	if ($size % 2 == 0) {
		return ($ary[$size / 2 - 1] + $ary[$size / 2]) / 2;
	} else {
		return $ary[($size - 1) / 2];
	}
}

function removeBrandLogo() {
	global $module;
	$module->removeProjectSetting(getBrandLogoName());
}

function saveBrandLogo($base64) {
	global $module;
	$module->setProjectSetting(getBrandLogoName(), $base64);
}

function getFieldForCurrentEmailSetting() {
	return "existingName";
}

function isEmailAddress($str) {
	if (preg_match("/^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,3})+$/", $str)) {
		return TRUE;
	}
	return FALSE;
}

function getBrandLogoName($currModule = NULL) {
	if ($currModule) {
		return $currModule->getBrandLogoName();
	}
	global $module;
	return $module->getBrandLogoName();
}

function getBrandLogo($currModule = NULL) {
	if ($currModule) {
		return $currModule->getBrandLogo();
	}
	global $module;
	return $module->getBrandLogo();
}

function makeHeaders($module = NULL, $token = NULL, $server = NULL, $pid = NULL, $tokenName = NULL) {
	if (!$module) { global $module; }
	if (!$token) { global $token; }
	if (!$server) { global $server; }
	if (!$pid) { global $pid; }
	if (!$tokenName) { global $tokenName; }
	if ($module) {
		return $module->makeHeaders($token, $server, $pid, $tokenName);
	}
	return "";
}

function isREDCap() {
	return CareerDev::isREDCap();
}

function isFAQ() {
	return CareerDev::isFAQ();
}

# eligibility determined by whether on K award (3 years for internal Ks; 5 years for external Ks)
# returns false if ineligible
# else returns index (1-15) of summary award
function findEligibleAward($row) {
	if ($row['redcap_repeat_instance'] !== "") {
		return false;
	}

	$rs = array(5, 6);
	$hasR = false;
	for ($i = 1; $i <= 15; $i++) {
		if (in_array($row['summary_award_type_'.$i], $rs)) {
			$hasR = true;
		}
	}
	if ($hasR) {
		return false;
	}

	$extKs = array(3, 4);
	for ($i = 1; $i <= 15; $i++) {
		if (in_array($row['summary_award_type_'.$i], $extKs)) {
			$diff = Grants::datediff(date("Y-m-d"), $row['summary_award_date_'.$i], "y");
			$intendedYearSpan = 5;
			if ($row['summary_award_end_date_'.$i]) {
				$intendedYearSpan = Grants::datediff($row['summary_award_end_date_'.$i], $row['summary_award_date_'.$i], "y");
			}
			if ($diff <= $intendedYearSpan) {
				return $i;
			}
		}
	}

	$intKs = array(1, 2);
	for ($i = 1; $i <= 15; $i++) {
		if (in_array($row['summary_award_type_'.$i], $intKs)) {
			$diff = Grants::datediff(date("Y-m-d"), $row['summary_award_date_'.$i], "y");
			$intendedYearSpan = 3;
			if ($row['summary_award_end_date_'.$i]) {
				$intendedYearSpan = Grants::datediff($row['summary_award_end_date_'.$i], $row['summary_award_date_'.$i], "y");
			}
			if ($diff <= $intendedYearSpan) {
				return $i;
			}
		}
	}

	return false;
}

function filterForCoeusFields($fields) {
	$hasCoeus = CareerDev::getSetting("hasCoeus");
	$newFields = $fields;
	if ($hasCoeus) {
		$newFields = array();
		foreach ($fields as $field) {
			if (!preg_match("/^coeus_/", $field)) {
				array_push($newFields, $field);
			}
		}
	}
	return $newFields;
}

function addLists($token, $server, $lists, $installCoeus = FALSE, $metadata = FALSE) {
	CareerDev::setSetting("departments", $lists["departments"]);
	CareerDev::setSetting("resources", $lists["resources"]);
	$others = array(
			"departments" => 999999,
			"resources" => FALSE,
			"institutions" => 5,
			);
	foreach ($lists as $type => $str) {
		$other = $others[$type];
		$lists[$type] = makeREDCapList($str, $other);
	}

	if (!$metadata) {
		$fp = fopen(dirname(__FILE__)."/metadata.json", "r");
		$json = "";
		$line = "";
		while ($line = fgets($fp)) {
			$json .= $line;
		}
		fclose($fp);
		$metadata = json_decode($json, true);
	}

	$fields = array();
	$fields["departments"] = array(
					"summary_primary_dept",
					"override_department1",
					"override_department1_previous",
					"check_primary_dept",
					"check_prev1_primary_dept",
					"check_prev2_primary_dept",
					"check_prev3_primary_dept",
					"check_prev4_primary_dept",
					"check_prev5_primary_dept",
					"followup_primary_dept",
					"followup_prev1_primary_dept",
					"followup_prev2_primary_dept",
					"followup_prev3_primary_dept",
					"followup_prev4_primary_dept",
					"followup_prev5_primary_dept",
					"promotion_department",
					);
	$fields["resources"] = array("resources_resource");
	$fields["institutions"] = array("check_institution", "followup_institution");

	$newMetadata = array();
	foreach ($metadata as $row) {
		$isCoeusRow = preg_match("/^coeus_/", $row['field_name']);
		if (($installCoeus && $isCoeusRow || !$isCoeusRow) && !preg_match("/___delete/", $row['field_name'])) {
			foreach ($fields as $type => $relevantFields) {
				if (in_array($row['field_name'], $relevantFields) && isset($lists[$type])) {
					$row['select_choices_or_calculations'] = $lists[$type];
					break;
				}
			}
			array_push($newMetadata, $row);
		}
	}

	return Upload::metadata($newMetadata, $token, $server);
}

function makeREDCapList($text, $otherItem = FALSE) {
	$list = explode("\n", $text);
	$newList = array();
	$i = 1;
	foreach ($list as $item) {
		$item = trim($item);
		if ($item) {
			if ($i == $otherItem) {
				$i++;
			}
			$newList[] = $i.",".$item;
			$i++;
		}
	}
	if ($otherItem) {
		$newList[] = $otherItem.",Other";
	}
	if (empty($newList)) {
		$newList[] = "999999,No Resource";
	}
	return implode("|", $newList);
}


function isHelpOn() {
	return  (isset($_SESSION['showHelp']) && $_SESSION['showHelp']);
}

function makeHelpLink() {
	return "<p class='smaller centered'>This page is complex. <a href='javascript:;' onclick='showHelp(\"".CareerDev::getHelpLink()."\", \"".CareerDev::getCurrPage()."\"); $(this).parent().hide();'>Click here to show help.</a></p>\n";
}

function getFieldsOfType($metadata, $fieldType, $validationType = "") {
	$fields = array();
	foreach ($metadata as $row) {
		if ($row['field_type'] == $fieldType) {
			if (!$validationType || ($validationType == $row['text_validation_type_or_show_slider_number'])) {
				array_push($fields, $row['field_name']);
			}
		}
	}
	return $fields;
}

function findMaxInstance($data, $instrument) {
	$max = 0;
	foreach ($data as $row) {
		if (($row['redcap_repeat_instrument'] == $instrument) && ($row['redcap_repeat_instance'] > $max)) {
			$max = $row['redcap_repeat_instance'];
		}
	}
	return $max;
}

function getMetadataRow($field, $metadata) {
	foreach ($metadata as $row) {
		if ($row['field_name'] == $field) {
			return $row;
		}
	}
	return array();
}

# return array
function filterFields($fields, $metadata) {
	$filtered = array();

	$metadataFields = array();
	foreach ($metadata as $row) {
		array_push($metadataFields, $row['field_name']);
	}

	foreach ($fields as $field) {
		if (in_array($field, $metadataFields)) {
			array_push($filtered, $field);
		}
	}
	return $filtered;
}

function queueUpInitialEmail($record) {
	global $token, $server, $pid;

	$dateToEmail1 = date("Y-m-d", 14 * 24 * 3600 + time())." 09:15:00";
	$dateToEmail2 = date("Y-m-d", 28 * 24 * 3600 + time())." 09:15:00";
	$recordData = Download::records($token, $server, array($record));
	$name = "";
	$email = "";
	foreach ($recordData as $row) {
		if ($row['identifier_last_name']) {
			if (!$name) {
				if ($row['identifier_first_name']) {
					$name = $row['identifier_first_name']." ".$row['identifier_last_name'];
				} else {
					$name = "Dr. ".$row['identifier_last_name'];
				}
			}
		}
		if (!$email) {
			$email = $row['identifier_email'];
		}
	}
	if ($name && $email) {
		$metadata = Download::metadata($token, $server);
		$emailManager = new EmailManager($token, $server, $pid, NULL, $metadata);
		$settingName = CareerDev::getEmailName($record);
		if (!$emailManager->hasItem($settingName)) {
                        $links = EmailManager::getSurveyLinks($pid, array($record), "initial_survey");
                        if ($isset($links[$record])) {
                                $link = $links[$record];
                        } else {
                                $link = "";
                                throw new \Exception("Could not make initial survey link for $name!");
                        }
			$message = CareerDev::getSetting("init_message");
			$from = CareerDev::getSetting("init_from");
			$subject = CareerDev::getSetting("init_subject");
			if ($message && $from && $subject) {
				$emailSetting = EmailManager::getBlankSetting();
				$emailSetting["who"]["individuals"] = $email;
				$emailSetting["who"]["from"] = $from;
				$emailSetting["what"]["message"] = $message;
				$emailSetting["what"]["subject"] = $subject;
				$emailSetting["when"]["initial_time"] = $dateToEmail1;
				$feedback = $emailManager->saveSetting($settingName, $emailSetting);
			}
		}
	} else {
		throw new \Exception("Could not queue up initial email because the name and email are not specified!");
	}
}

function cleanOutJSONs($metadata) {
	$fieldsToClean = array("identifier_vunet", "identifier_first_name");
	for ($i = 0; $i < count($metadata); $i++) {
		if (in_array($metadata[$i]['field_name'], $fieldsToClean)) {
			$metadata[$i]['field_annotation'] = "[]";
		}
	}
	return $metadata;
}

function resetRepeatingInstruments($srcToken, $srcServer, $destToken, $destServer) {
	$data = array(
		'token' => $srcToken,
		'content' => 'repeatingFormsEvents',
		'format' => 'json',
		'returnFormat' => 'json'
	);
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $srcServer);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_VERBOSE, 0);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_AUTOREFERER, true);
	curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
	curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
	$output = curl_exec($ch);
	curl_close($ch);

	$data = array(
		'token' => $destToken,
		'content' => 'repeatingFormsEvents',
		'format' => 'json',
		'data' => $output,
		'returnFormat' => 'json'
	);
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $destServer);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_VERBOSE, 0);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_AUTOREFERER, true);
	curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
	curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
	$output = curl_exec($ch);
	curl_close($ch);
	return json_decode($output, TRUE);
}

function deleteRecords($token, $server, $records) {
	if (!empty($records)) {
		$data = array(
			'token' => $token,
			'action' => 'delete',
			'content' => 'record',
			'records' => $records
		);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $server);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
		$output = curl_exec($ch);
		curl_close($ch);

		return json_decode($output, TRUE);
	}
	return array();
}

function copyEntireProject($srcToken, $destToken, $server, $metadata, $cohort) {
	$allFeedback = array();
	$destRecords = Download::recordIds($destToken, $server);
	if (!empty($destRecords)) {
		$feedback = deleteRecords($destToken, $server, $destRecords);
		$output = json_encode($feedback);
		CareerDev::log("Delete project: ".count($destRecords)." records: $output");
		array_push($allFeedback, $feedback);
	}

	resetRepeatingInstruments($srcToken, $server, $destToken, $server);

	$feedback = Upload::metadata(cleanOutJSONs($metadata), $destToken, $server);
	$calcFields = getFieldsOfType($metadata, "calc");
	$timeFields = getFieldsOfType($metadata, "text", "datetime_ymd");

	$records = Download::cohortRecordIds($srcToken, $server, $metadata, $cohort);
	foreach ($records as $record) {
		$recordData = Download::records($srcToken, $server, array($record));
		$newRecordData = array();
		foreach ($recordData as $row) {
			$newRow = array();
			foreach ($row as $field => $value) {
				if (!in_array($field, $calcFields) && !in_array($field, $timeFields)) {
					$newRow[$field] = $value;
				}
			}
			if (!empty($newRow)) {
				array_push($newRecordData, $newRow);
			}
		}
		if (!empty($newRecordData)) {
			$feedback = Upload::rows($newRecordData, $destToken, $server);
			CareerDev::log("Copy project: Record $record: ".json_encode($feedback));
			array_push($allFeedback, $feedback);
		}
	}
	return $allFeedback;
}

function getQuestionsForForm($token, $server, $form) {
	$formMetadata = Download::formMetadata($token, $server, array($form));
	$labels = array();
	foreach ($formMetadata as $row) {
		array_push($labels, $row['field_label']);
	}
	return $labels;
}

require_once(dirname(__FILE__)."/cronLoad.php");
