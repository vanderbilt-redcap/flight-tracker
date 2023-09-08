<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\CohortConfig;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Consortium;
use \Vanderbilt\CareerDevLibrary\CronManager;
use \Vanderbilt\CareerDevLibrary\Definitions;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\EmailManager;
use \Vanderbilt\CareerDevLibrary\Filter;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\GrantFactory;
use \Vanderbilt\CareerDevLibrary\GrantLexicalTranslator;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\LDAP;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\NavigationBar;
use \Vanderbilt\CareerDevLibrary\NIHExPORTER;
use \Vanderbilt\CareerDevLibrary\OracleConnection;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\iCite;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\ConnectionStatus;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\FeatureSwitches;
use \Vanderbilt\CareerDevLibrary\DateManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\URLManagement;
use \Vanderbilt\CareerDevLibrary\FileManagement;
use \Vanderbilt\CareerDevLibrary\Dashboard;
use \Vanderbilt\CareerDevLibrary\ReactNIHTables;
use \Vanderbilt\CareerDevLibrary\NIHTables;

require_once(__DIR__."/autoload.php");

ini_set("memory_limit", "4096M");
ini_set("auto_detect_line_endings", true);
if (!Application::isWebBrowser() && CareerDev::getPid()) {
    date_default_timezone_set(CareerDev::getTimezone());
}

$pid = Sanitizer::sanitize(isset($module) ? $module->getProjectId($_GET['pid'] ?? "") : Sanitizer::sanitizePid($_GET['pid'] ?? ""));
if (!$pid && !Application::isWebBrowser() && (count($argv) >= 2) && in_array($argv[1], Application::getPids())) {
    $pid = $argv[1];
    $_GET['pid'] = $pid;
}
if (!$pid) {
    if (isset($_GET['project_id'])) {
        $pid = CareerDev::getSetting("pid", $_GET['project_id']);
    } else {
        $pid = CareerDev::getSetting("pid");
    }
    $_GET['pid'] = $pid;
}
define('INSTITUTION', CareerDev::getInstitution($pid));
define('PROGRAM_NAME', CareerDev::getProgramName());
define("ENVIRONMENT", "prod");      // for Oracle database connectivity

if (!isset($module) || !$module) {
	$module = Application::getModule();
}
if (!$module) {
    throw new \Exception("The base class has no module!");
}

$token = Application::getSetting("token", $pid);
try {
    $token = Application::checkOrResetToken($token, $pid);
} catch (\Exception $e) {
    if (!$token && USERID && $module->canRedirectToInstall()) {
        header("Location: ".CareerDev::link("install.php"));
    } else if (Sanitizer::sanitize($_GET['page'] ?? "") != "install") {
        throw $e;
    }
}

$server = Application::getSetting("server", $pid);
$event_id = Application::getSetting("event_id", $pid);
$tokenName = Application::getSetting("tokenName", $pid);
$adminEmail = Application::getSetting("admin_email", $pid);
$grantClass = Application::getSetting("grant_class", $pid);

$GLOBALS['pid'] = $pid;
$GLOBALS['server'] = $server;
$GLOBALS['token'] = $token;
$GLOBALS['tokenName'] = $tokenName;
$GLOBALS['event_id'] = $event_id;
$GLOBALS['module'] = $module;
$GLOBALS['adminEmail'] = $adminEmail;
$GLOBALS['grantClass'] = $grantClass;

set_exception_handler('\Vanderbilt\FlightTrackerExternalModule\displayException');
// set_error_handler('\Vanderbilt\FlightTrackerExternalModule\displayError');

############# FUNCTIONS ################

function displayException($exception) {
    global $pid;
    $header = "Flight Tracker Error!";
    Application::log("Exception: ".$exception->getMessage(), $pid);
    echo "<div class='red padded max-width' style='text-align: center;'><strong>".$header."</strong><br/>".$exception->getMessage()."<br/>File: ".$exception->getFile()."; Line: ".$exception->getLine()."<br/><code class='alignLeft'>".$exception->getTraceAsString()."</code></div>";
}

function displayError($error) {
    # unimplemented
}

function getBaseAwardNumber($num) {
	return Grant::translateToBaseAwardNumber($num);
}

function getDepartmentChoices() {
    $choices2 = [];
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

function getReverseAwardTypes() {
	return Grant::getReverseAwardTypes();
}

function getAwardTypes() {
	return Grant::getAwardTypes();
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
	global $token, $server;
	return NameMatcher::matchName($first, $last, $token, $server);
}

# returns an array of recordId's that matches respective last, first
# returns "" if no match
function matchNames($firsts, $lasts) {
	global $token, $server;
	return NameMatcher::matchNames($firsts, $lasts, $token, $server);
}

function prettyMoney($n, $displayCents = TRUE) {
    return REDCapManagement::prettyMoney($n, $displayCents);
}

function pretty($n, $numDecimalPlaces = 3) {
    return REDCapManagement::pretty($n, $numDecimalPlaces);
}

function downloadURL($url) {
    global $pid;
    return REDCapManagement::downloadURL($url, $pid);
}

# given two timestamps (UNIX) $start, $end - let's call this duration.
# provide the timestamps of a larger period, $yearStart, $yearEnd
# figures the fraction of the year that is filled by the duration
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
    return REDCapManagement::getReporterDateInYMD($dt);
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
    return REDCapManagement::json_encode_with_spaces($data);
}

function YMD2MDY($ymd) {
	return REDCapManagement::YMD2MDY($ymd);
}

function MDY2YMD($mdy) {
	return REDCapManagement::MDY2YMD($mdy);
}

function getLabels($metadata) {
	return REDCapManagement::getLabels($metadata);
}

function getChoices($metadata) {
	return REDCapManagement::getChoices($metadata);
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
			if ($i + 1 < count($records)) {
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
	return REDCapManagement::indexREDCapData($redcapData);
}

function getIndexedRedcapData($token, $server, $fields, $cohort = "", $metadata = array()) {
	if ($token && $server && $fields && !empty($fields)) {
		if ($cohort) {
			$records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
		} else {
            $records = Download::recordIds($token, $server);
        }

		$redcapData = Download::fieldsForRecords($token, $server, $fields, $records);
		return indexREDCapData($redcapData);
	}
	return array();
}

function getCohortSelect($token, $server, $pid) {
    $page = Sanitizer::sanitize($_GET['page'] ?? "");
    $prefix = Sanitizer::sanitize($_GET['prefix'] ?? "");
	$html = "<select onchange='var base = \"?page=".urlencode($page)."&prefix=".urlencode($prefix)."&pid=$pid\"; if ($(this).val()) { window.location.href = base+\"&cohort=\" + $(this).val(); } else { window.location.href = base; }'>\n";
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
    return REDCapManagement::makeHTMLId($id);
}

function makeSafe($htmlStr) {
    $htmlStr = REDCapManagement::stripHTML($htmlStr);
    $htmlStr = REDCapManagement::sanitize($htmlStr);
	return $htmlStr;
}

function changeTextColorOfLink($str, $color) {
    return Links::changeTextColorOfLink($str, $color);
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
			for ($i = (int) ceil($size / 2); $i < $size; $i++) {
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
	    $idx = (int) ($size / 2);
		return ($ary[$idx - 1] + $ary[$idx]) / 2;
	} else {
        $idx = (int) (($size - 1) / 2);
		return $ary[$idx];
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
	return EmailManager::getFieldForCurrentEmailSetting();
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
    if (isset($_GET['test'])) {
        echo "findEligibleAward with ".$row['record_id']."<br>";
    }
	if ($row['redcap_repeat_instance'] !== "") {
		return false;
	}

	$rs = array(5, 6);
	$hasR = false;
	for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
		if (in_array($row['summary_award_type_'.$i], $rs)) {
			$hasR = true;
		}
	}
	if ($hasR) {
	    if (isset($_GET['test'])) {
	        echo $row['record_id']." has R<br>";
        }
		return false;
	}

	$extKs = array(3, 4);
	for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
		if (in_array($row['summary_award_type_'.$i], $extKs)) {
			$diff = Grants::datediff(date("Y-m-d"), $row['summary_award_date_'.$i], "y");
			$intendedYearSpan = Application::getIndividualKLength();
			if ($row['summary_award_end_date_'.$i]) {
				$intendedYearSpan = Grants::datediff($row['summary_award_end_date_'.$i], $row['summary_award_date_'.$i], "y");
			}
            if (isset($_GET['test'])) {
                echo $row['record_id']." comparing $diff and $intendedYearSpan for $i<br>";
            }
			if ($diff <= $intendedYearSpan) {
				return $i;
			}
		}
	}

	$intKs = array(1, 2);
	for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
		if (in_array($row['summary_award_type_'.$i], $intKs)) {
			$diff = Grants::datediff(date("Y-m-d"), $row['summary_award_date_'.$i], "y");
			$intendedYearSpan = 0;
			if ($row['summary_award_type_'.$i] == 1) {
                $intendedYearSpan = Application::getInternalKLength();
            } else if ($row['summary_award_type_'.$i] == 2) {
			    $intendedYearSpan = Application::getK12KL2Length();
            }
			if ($row['summary_award_end_date_'.$i]) {
				$intendedYearSpan = Grants::datediff($row['summary_award_end_date_'.$i], $row['summary_award_date_'.$i], "y");
			}
            if (isset($_GET['test'])) {
                echo $row['record_id']." comparing $diff and $intendedYearSpan for $i<br>";
            }
			if ($diff <= $intendedYearSpan) {
				return $i;
			}
		}
	}

	if (isset($_GET['test'])) {
        echo $row['record_id']." returning FALSE<br>";
    }
	return false;
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

function isHelpOn() {
	return  (isset($_SESSION['showHelp']) && $_SESSION['showHelp']);
}

function makeHelpLink() {
	return "<p class='smaller centered'>This page is complex. <a href='javascript:;' onclick='showHelp(\"".CareerDev::getHelpLink()."\", \"".CareerDev::getCurrPage()."\"); $(this).parent().hide();'>Click here to show help.</a></p>\n";
}

function getFieldsOfType($metadata, $fieldType, $validationType = "") {
    return REDCapManagement::getFieldsOfType($metadata, $fieldType, $validationType);
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
    return REDCapManagement::getRowForFieldFromMetadata($field, $metadata);
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
    return;    // turned off for now

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
		$emailManager = new EmailManager($token, $server, $pid, Application::getModule(), $metadata);
		$settingName = CareerDev::getEmailName($record);
		if (!$emailManager->hasItem($settingName)) {
			$links = EmailManager::getSurveyLinks($pid, array($record), "initial_survey");
			if (isset($links[$record])) {
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
				// DISABLED $feedback = $emailManager->saveSetting($settingName, $emailSetting);
			}
		}
	} else {
	    # make silent because someone might not save name and email
        Application::log("Could not queue up initial email for Record $record because the name and email are not specified!");
		// throw new \Exception("Could not queue up initial email for Record $record because the name and email are not specified!");
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

function resetRepeatingInstruments($srcToken, $srcServer, $destToken, $destServer, $metadata) {
    $destServer = Sanitizer::sanitizeURL($destServer);
    $srcServer = Sanitizer::sanitizeURL($srcServer);
    if (!$destServer || !$srcServer) {
        throw new \Exception("Invalid URL");
    }

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
	$output = (string) curl_exec($ch);
	curl_close($ch);

	$metadataForms = REDCapManagement::getFormsFromMetadata($metadata);
	Application::log("Metadata forms: ".implode(", ", $metadataForms));
	$data = json_decode($output, TRUE);
	$newData = [];
	foreach ($data as $row) {
	    if (in_array($row['form_name'], $metadataForms)) {
	        $newData[] = $row;
        }
    }

	$data = array(
		'token' => $destToken,
		'content' => 'repeatingFormsEvents',
		'format' => 'json',
		'data' => json_encode($newData),
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
	$output = (string) curl_exec($ch);
	curl_close($ch);
	return json_decode($output, TRUE);
}

function deleteRecords($token, $server, $records) {
    return Upload::deleteRecords($token, $server, $records);
}

function copyExternalModuleSettings($module, $srcPid, $destPid, $overwriteList, $omitList) {
    $projectSettings = $module->getProjectSettings($srcPid);
    $copiedKeys = [];
    foreach ($projectSettings as $key => $value) {
        if (!in_array($key, $omitList)) {
            $copiedKeys[] = $key;
            if (isset($overwriteList[$key])) {
                $module->setProjectSetting($key, $overwriteList[$key], $destPid);
            } else if (isset($value['value'])) {
                $module->setProjectSetting($key, $value['value'], $destPid);
            } else {
                $module->setProjectSetting($key, $value, $destPid);
            }
        }
    }
    Application::log("Copied External Module Settings from $srcPid to $destPid: ".implode(", ", $copiedKeys));
}

function uploadProjectSettings($token, $server, $title) {
    $redcapData = [
        "is_longitudinal" => 0,
        "surveys_enabled" => 1,
        "record_autonumbering_enabled" => 1,
        "project_title" => Application::getProgramName()." - ".$title,
        "custom_record_label" => "[identifier_first_name] [identifier_last_name]",
    ];
    $feedback = Upload::projectSettings($redcapData, $token, $server);
    return $feedback;
}


function getPIDFromToken($token, $server) {
    return REDCapManagement::getPIDFromToken($token, $server);
}

function getEventIdForClassical($projectId) {
    return REDCapManagement::getEventIdForClassical($projectId);
}

function getExternalModuleId($prefix) {
    return REDCapManagement::getExternalModuleId($prefix);
}

function setupModuleSettings($projectId, $fields) {
    foreach ($fields as $field => $value) {
        CareerDev::setSetting($field, $value, $projectId);
    }
}

function setupSurveys($projectId, $surveysAndLabels) {
    REDCapManagement::setupSurveys($projectId, $surveysAndLabels);
}

function copyEntireProject($srcToken, $destToken, $server, $metadata, $cohort) {
    $allFeedback = array();
    $destRecords = Download::recordIds($destToken, $server);
    if (!empty($destRecords)) {
        $feedback = Upload::deleteRecords($destToken, $server, $destRecords);
        CareerDev::log("Delete project: ".count($destRecords)." records: ".json_encode($feedback));
        $allFeedback[] = $feedback;
    }

    # turn off autonumbering in new project
    $projectSettings = [
        "record_autonumbering_enabled" => 0,
        "project_title" => Application::getProgramName()." - ".$cohort,
    ];
    Upload::projectSettings($projectSettings, $destToken, $server);

    $feedback = Upload::metadata(cleanOutJSONs($metadata), $destToken, $server);
    $calcFields = REDCapManagement::getFieldsOfType($metadata, "calc");
    $timeFields = REDCapManagement::getFieldsOfType($metadata, "text", "datetime_ymd");

    $feedback = \Vanderbilt\FlightTrackerExternalModule\resetRepeatingInstruments($srcToken, $server, $destToken, $server, $metadata);
    CareerDev::log("Reset Repeating Instruments: ".json_encode($feedback));
    echo "Reset Repeating Instruments: ".json_encode($feedback)."<br>";

    $cohortRecords = Download::cohortRecordIds($srcToken, $server, CareerDev::getModule(), $cohort);
    foreach ($cohortRecords as $record) {
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
                $newRecordData[] = $newRow;
            }
        }
        if (!empty($newRecordData)) {
            $feedback = Upload::rows($newRecordData, $destToken, $server);
            CareerDev::log("Copy project: Record $record: ".json_encode($feedback));
            $allFeedback[] = $feedback;
        }
    }
    return $allFeedback;
}

function copyBackAnyMentoringAgreements($srcToken, $destToken, $destServer, $destMetadata, $cohort = "all", $srcServer = NULL) {
    Application::log("copyBackAnyMentoringAgreements");
    if (!$srcServer) {
        $srcServer = $destServer;
    }
    $srcMetadata = Download::metadata($srcToken, $srcServer);
    $srcMetadataForms = REDCapManagement::getFormsFromMetadata($srcMetadata);
    $destMetadataForms = REDCapManagement::getFormsFromMetadata($destMetadata);

    $relevantFormsWithFields = [
        "mentoring_agreement" => REDCapManagement::getFieldsFromMetadata($srcMetadata, "mentoring_agreement"),
    ];
    $evalFields = [
        "mentoring_agreement" => ["mentoring_last_update", "mentoring_userid", "mentoring_start"],
    ];
    if (in_array("mentoring_agreement_evaluations", $srcMetadataForms)) {
        $fields = REDCapManagement::getFieldsFromMetadata($srcMetadata, "mentoring_agreement_evaluations");
        $relevantFormsWithFields["mentoring_agreement_evaluations"] = $fields;
        $evalFields["mentoring_agreement_evaluations"] = $fields;
    }
    $srcRecords = Download::recordIds($srcToken, $srcServer);
    $destRecords = Download::recordIds($destToken, $destServer);
    if ($cohort == "all") {
        $srcCohortRecords = $srcRecords;
        $destCohortRecords = $destRecords;
    } else {
        $srcCohorts = new Cohorts($srcToken, $srcServer, Application::getModule());
        if (in_array($cohort, $srcCohorts->getCohortNames())) {
            $srcCohortRecords = Download::cohortRecordIds($srcToken, $srcServer, Application::getModule(), $cohort);
        } else {
            $srcCohortRecords = $srcRecords;
        }
        $destCohortRecords = Download::cohortRecordIds($destToken, $destServer, Application::getModule(), $cohort);
    }
    foreach ($relevantFormsWithFields as $instrument => $fields) {
        if (in_array($instrument, $destMetadataForms)) {
            if (!in_array("record_id", $fields)) {
                $fields[] = "record_id";
                $fields[] = "summary_mentor";
                $fields[] = "summary_mentor_userid";
            }
            foreach ($srcCohortRecords as $recordId) {
                if (in_array($recordId, $destCohortRecords)) {
                    $destUploadRows = [];
                    $redcapData = [ "src" => [], "dest" => []];
                    $redcapData["src"] = Download::fieldsForRecords($srcToken, $srcServer, $fields, [$recordId]);
                    $redcapData["dest"] = Download::fieldsForRecords($destToken, $destServer, $fields, [$recordId]);

                    $srcMentor = REDCapManagement::findField($redcapData['src'], $recordId, "summary_mentor");
                    $destMentor = REDCapManagement::findField($redcapData['dest'], $recordId, "summary_mentor");
                    $srcMentorUserid = REDCapManagement::findField($redcapData['src'], $recordId, "summary_mentor_userid");
                    $destMentorUserid = REDCapManagement::findField($redcapData['dest'], $recordId, "summary_mentor_userid");

                    if (($srcMentor != $destMentor) || ($srcMentorUserid != $destMentorUserid)) {
                        $regex = "/\s*[,;]\s*/";
                        $mentors = [
                            "src" => $srcMentor ? preg_split($regex, $srcMentor) : [],
                            "dest" => $destMentor ? preg_split($regex, $destMentor) : [],
                        ];
                        $mentorUserids = [
                            "src" => $srcMentorUserid ? preg_split($regex, $srcMentorUserid) : [],
                            "dest" => $destMentorUserid ? preg_split($regex, $destMentorUserid) : [],
                        ];

                        $mentors['new'] = array_unique(array_merge($mentors['src'], $mentors['dest']));
                        $mentorUserids['new'] = array_unique(array_merge($mentorUserids['src'], $mentorUserids['dest']));
                        $blankRow = [
                            "record_id" => $recordId,
                            "redcap_repeat_instrument" => "",
                            "redcap_repeat_instance" => "",
                        ];
                        $uploadRow = [
                            'src' => $blankRow,
                            'dest' => $blankRow,
                        ];
                        foreach (array_keys($uploadRow) as $category) {
                            if (count($mentors['new']) > count($mentors[$category])) {
                                $uploadRow[$category]["summary_mentor"] = implode(", ", $mentors['new']);
                            }
                            if (count($mentorUserids['new']) > count($mentorUserids[$category])) {
                                $uploadRow[$category]["summary_mentor_userid"] = implode(", ", $mentorUserids['new']);
                            }
                        }
                        if (count($uploadRow['dest']) > count($blankRow)) {
                            $destUploadRows[] = $uploadRow['dest'];
                        }
                        if (count($uploadRow['src']) > count($blankRow)) {
                            Upload::oneRow($uploadRow['src'], $srcToken, $srcServer);
                        }
                    }

                    $instances = [];
                    foreach ($redcapData as $category => $rows) {
                        $instances[$category] = [];
                        foreach ($rows as $row) {
                            if ($row['redcap_repeat_instrument'] == $instrument) {
                                $instances[$category][] = $row['redcap_repeat_instance'];
                            }
                        }
                    }
                    foreach ($redcapData['src'] as $srcRow) {
                        if ($srcRow['redcap_repeat_instrument'] == $instrument) {
                            $foundRow = FALSE;
                            $fieldsToCompare = $evalFields[$instrument] ?? [];
                            foreach ($redcapData['dest'] as $destRow) {
                                $allFieldsSame = FALSE;
                                if ($destRow['redcap_repeat_instrument'] == $instrument) {
                                    $allFieldsSame = !empty($fieldsToCompare);
                                    foreach ($fieldsToCompare as $field) {
                                        if ($srcRow[$field] != $destRow[$field]) {
                                            $allFieldsSame = FALSE;
                                            break;
                                        }
                                    }
                                }
                                if ($allFieldsSame) {
                                    $foundRow = TRUE;
                                }
                            }
                            if (!$foundRow) {
                                # copy from src to dest
                                $newDestInstance = $srcRow['redcap_repeat_instance'];
                                if (in_array($newDestInstance, $instances['dest'])) {
                                    $destInstances = [];
                                    foreach ($instances['dest'] as $instance) {
                                        if (is_numeric($instance)) {
                                            $destInstances[] = $instance;
                                        }
                                    }
                                    if (!empty($destInstances)) {
                                        $destMax = max($destInstances);
                                    } else {
                                        $destMax = 0;
                                    }
                                    $newDestInstance = $destMax + 1;
                                }
                                $instances['dest'][] = $newDestInstance;
                                $destUploadRow = $srcRow;
                                $destUploadRow["redcap_repeat_instance"] = $newDestInstance;
                                $destUploadRow[$instrument."_complete"] = "2";
                                $destUploadRows[] = $destUploadRow;
                            }
                        }
                    }
                    if (!empty($destUploadRows)) {
                        $uploadInstances = [];
                        foreach ($destUploadRows as $row) {
                            if ($row['redcap_repeat_instance']) {
                                $uploadInstances[] = $row['redcap_repeat_instance'];
                            } else {
                                $uploadInstances[] = "[blank]";
                            }
                        }
                        Application::log("Record $recordId: Copying instances on $instrument to destination: ".implode(", ", $uploadInstances));
                        Upload::rows($destUploadRows, $destToken, $destServer);
                    }
                }
            }
        }
    }
}

function getValidProjectSettingsForUpload() {
    return [
        "project_title",
        "project_language",
        "purpose",
        "purpose_other",
        "project_notes",
        "custom_record_label",
        "secondary_unique_field",
        "is_longitudinal",
        "surveys_enabled",
        "scheduling_enabled",
        "record_autonumbering_enabled",
        "randomization_enabled",
        "project_irb_number",
        "project_grant_number",
        "project_pi_firstname",
        "project_pi_lastname",
        "display_today_now_button",
    ];
}

function copyProjectToNewServer($srcToken, $srcServer, $destToken, $destServer) {
    $metadata = Download::metadata($srcToken, $srcServer);

    $allFeedback = [];
    $destRecords = Download::recordIds($destToken, $destServer);
    if (!empty($destRecords)) {
        $feedback = Upload::deleteRecords($destToken, $destServer, $destRecords);
        Application::log("Delete project: ".count($destRecords)." records: ".json_encode($feedback));
        $allFeedback[] = $feedback;
    }

    $projectSettings = Download::getProjectSettings($srcToken, $srcServer);
    $validProjectSettingsForUpload = getValidProjectSettingsForUpload();
    foreach ($projectSettings as $key => $value) {
        if (!in_array($key, $validProjectSettingsForUpload)) {
            unset($projectSettings[$key]);
        }
    }
    Upload::projectSettings($projectSettings, $destToken, $destServer);
    $destProjectSettings = Download::getProjectSettings($destToken, $destServer);
    $destPid = $destProjectSettings['project_id'];

    $feedback = Upload::metadata(cleanOutJSONs($metadata), $destToken, $destServer);
    $calcFields = REDCapManagement::getFieldsOfType($metadata, "calc");
    $timeFields = REDCapManagement::getFieldsOfType($metadata, "text", "datetime_ymd");

    $feedback = \Vanderbilt\FlightTrackerExternalModule\resetRepeatingInstruments($srcToken, $srcServer, $destToken, $destServer, $metadata);
    Application::log("Reset Repeating Instruments: ".json_encode($feedback));

    $records = Download::recordIds($srcToken, $srcServer);
    foreach ($records as $record) {
        $recordData = Download::records($srcToken, $srcServer, [$record]);
        $newRecordData = [];
        foreach ($recordData as $row) {
            $newRow = [];
            foreach ($row as $field => $value) {
                if (!in_array($field, $calcFields) && !in_array($field, $timeFields)) {
                    $newRow[$field] = $value;
                }
            }
            if (!empty($newRow)) {
                $newRecordData[] = $newRow;
            }
        }
        if (!empty($newRecordData)) {
            $feedback = Upload::rows($newRecordData, $destToken, $destServer);
            Application::log("Copy project: Record $record: ".json_encode($feedback));
            $allFeedback[] = $feedback;
        }
    }
    return $destPid;
}

function getQuestionsForForm($token, $server, $form) {
	$formMetadata = Download::formMetadata($token, $server, array($form));
	$labels = array();
	foreach ($formMetadata as $row) {
		array_push($labels, $row['field_label']);
	}
	return $labels;
}

# coordinated with config.js's getDelim function
function getUploadDelim() {
	return "|";
}

function uploadOrderToMetadata($token, $server, $post) {
	$metadata = Download::metadata($token, $server);

	$metadata = produceNewOrderForMetadata($post, $metadata);
	$feedback = Upload::metadata($metadata, $token, $server);
	return $feedback;
}

function produceNewOrderForMetadata($post, $metadata) {
	$config = json_decode($post['config'], TRUE);
	$order = json_decode($post['order'], TRUE);
	if ($config && $order) {
		foreach ($config as $fieldForOrder => $sources) {
			# have to do this because JS does not ensure order
			$newSources = array();
			foreach ($order[$fieldForOrder] as $field) {
				$source = $sources[$field];
				$newSources[$field] = $source;
			}
			switch($fieldForOrder) {
				case "summary_race_ethnicity".getUploadDelim()."ethnicity":
				case "summary_race_ethnicity".getUploadDelim()."race":
					$type = str_replace("summary_race_ethnicity".getUploadDelim(), "", $fieldForOrder);
					$i = 0;
					foreach ($metadata as $row) {
						if ($row['field_name'] == "summary_race_ethnicity") {
							$modifiedSources = array();
							if ($row['field_annotation']) {
								 $modifiedSources = json_decode($row['field_annotation'], TRUE);
							}
							$modifiedSources[$type] = $newSources;
							$metadata[$i]['field_annotation'] = json_encode($modifiedSources);
							break;
						}
						$i++;
					}
					break;
				case "summary_degrees":
				default:
					$i = 0;
					foreach ($metadata as $row) {
						if ($row['field_name'] == $fieldForOrder) {
							$metadata[$i]['field_annotation'] = json_encode($newSources);
							break;
						}
						$i++;
					}
					break;
			}
		}
	} else {
		throw new \Exception("Improper config or order!");
	}
	return $metadata;
}

function produceSourcesAndTypes($scholar, $metadata) {
	$choices = Scholar::getChoices($metadata);
	if (isset($_GET['test'])) {
	    echo "metadata has ".count($metadata)." rows<br>";
    }
	$exampleField = Scholar::getExampleField();
	$orders = Scholar::getDefaultOrder("all");
	$sources = [];
	$sourceTypes = [];
	$delim = getUploadDelim();
	$allFields = REDCapManagement::getFieldsFromMetadata($metadata);
	foreach ($orders as $fieldForOrder => $order) {
		$newOrder = $scholar->getOrder($order, $fieldForOrder);
		foreach ($newOrder as $field => $source) {
			if (!isset($sources[$fieldForOrder])) {
			    if (isset($_GET['test'])) {
			        echo "Resetting sources and sourceTypes for $fieldForOrder under $field<br>";
                }
				$sources[$fieldForOrder] = [];
				$sourceTypes[$fieldForOrder] = [];
			}
			if (is_array($source)) {
				$sourceRow = $source;
				if (is_numeric($field)) {
					# summary_degrees
					$rowFields = array();
					$sourceType = "custom";
					$foundRowSource = "";
					foreach ($sourceRow as $rowSourceField => $rowSource) {
						if (in_array($rowSourceField, $allFields)) {
							$rowFields[] = $rowSourceField;
							$foundRowSource = $rowSource;
							if (isset($order[$field]) && isset($order[$field][$rowSourceField])) {
								$sourceType = "original";
							}
						}
					}
					if ($foundRowSource) {
						$delimRowFields = implode($delim, $rowFields);
						if ($choices[$exampleField][$foundRowSource]) {
							$sources[$fieldForOrder][$delimRowFields] = $choices[$exampleField][$foundRowSource];
						} else {
							$sources[$fieldForOrder][$delimRowFields] = $foundRowSource;
						}
						$sourceTypes[$fieldForOrder][$delimRowFields] = $sourceType;
					}
				} else {
					# race/ethnicity
					$type = $field;
					$sources[$fieldForOrder][$type] = [];
                    $sourceTypes[$fieldForOrder][$type] = [];
                    foreach ($sourceRow as $field2 => $source2) {
						if (in_array($field2, $allFields)) {
							if ($choices[$exampleField][$source2]) {
								$sources[$fieldForOrder][$type][$field2] = $choices[$exampleField][$source2];
							} else {
								$sources[$fieldForOrder][$type][$field2] = $source2;
							}

							$sourceType = "custom";
							if (isset($order[$type]) && isset($order[$type][$field2])) {
								$sourceType = "original";
							}
							$sourceTypes[$fieldForOrder][$type][$field2] = $sourceType;
						}
					}
				}
			} else {
				if (in_array($field, $allFields)) {
					if ($choices[$exampleField][$source]) {
						$sources[$fieldForOrder][$field] = $choices[$exampleField][$source];
					} else {
						$sources[$fieldForOrder][$field] = $source;
					}
					$sourceType = "custom";
					if (isset($order[$field])) {
						$sourceType = "original";
					}
					$sourceTypes[$fieldForOrder][$field] = $sourceType;
				} else if (strpos($field, getUploadDelim()) !== FALSE) {
					$fields = explode(getUploadDelim(), $field);
					$inAllFields = TRUE;
					foreach ($fields as $compoundField) {
						if (!in_array($compoundField, $allFields)) {
							$inAllFields = FALSE;
							break;
						}
					}
					if ($inAllFields) {
						if ($choices[$exampleField][$source]) {
							$sources[$fieldForOrder][$field] = $choices[$exampleField][$source];
						} else {
							$sources[$fieldForOrder][$field] = $source;
						}
						$sourceType = "custom";
						if (isset($order[$field])) {
							$sourceType = "original";
						}
						$sourceTypes[$fieldForOrder][$field] = $sourceType;
					}
				}
			}
		}
	}
	return [$sources, $sourceTypes];
}

function importCustomFields($filename, $token, $server, $pid) {
	$lines = [];
	$headers = [];
	$errors = [];
	if (is_uploaded_file($filename)) {
		$fp = fopen($filename, "rb");
		if (!$fp) {
			$errors[] = "Cannot find file.";
		}
		$i = 0;
		while ($line = fgetcsv($fp)) {
			foreach ($line as $j => $item) {
				$line[$j] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $item);
				if (DateManagement::isDate($line[$j]) && !DateManagement::isYMD($line[$j])) {
				    if (DateManagement::isMDY($line[$j])) {
                        $line[$j] = DateManagement::MDY2YMD($line[$j]);
                    } else if (DateManagement::isDMY($line[$j])) {
				        $line[$j] = DateManagement::DMY2YMD($line[$j]);
                    }
                }
			}
			if (($i > 0) && !REDCapManagement::isArrayBlank($line)) {
				$lines[$i] = $line;
			} else {
				$headers = $line;
			}
			$i++;
		}
		fclose($fp);
	}

	list($upload, $errors, $newCounts) = Upload::prepareFromCSV($headers, $lines, $token, $server, $pid);

	$html = "";
	if (!empty($errors)) {
		$html .= "<div class='red centered'>".implode("<br>", $errors)."</div>\n";
	} else if (!empty($upload)) {
	    try {
            $feedback = Upload::rows($upload, $token, $server);
            $hasCitation = FALSE;
            foreach ($upload as $row) {
                if ($row['redcap_repeat_instrument'] == "citation") {
                    $hasCitation = TRUE;
                    break;
                }
            }
            $pubsLine = "";
            if ($hasCitation) {
                $metadata = Download::metadata($token, $server);
                $indexedUpload = REDCapManagement::indexREDCapData($upload);
                $numPubsAffected = 0;
                foreach ($indexedUpload as $recordId => $rows) {
                    $numPubsAffected += Publications::uploadBlankPMCsAndPMIDs($token, $server, $recordId, $metadata, $rows);
                }
                $pubsLine = " $numPubsAffected publications downloaded.";
            }
            if ($feedback['error']) {
                $html .= "<div class='red centered'>Error: {$feedback['error']}</div>\n";
            } else if ($feedback['errors']) {
                $html .= "<div class='red centered'>Errors: " . implode("<br>", $feedback['errors']) . "</div>\n";
            } else {
                $html .= "<div class='green centered'>Success! Imported {$newCounts['new']} lines for new scholars and {$newCounts['existing']} lines for existing scholars.$pubsLine</div>\n";
            }
        } catch (\Exception $e) {
            $html .= "<div class='red centered'>Error: ".$e->getMessage()."</div>\n";
        }
    } else {
		$html .= "<div class='red centered'>No action taken!</div>\n";
	}
	return $html;
}

function importNIHTable($post, $filename, $token, $server) {
    $tableNum = Sanitizer::sanitizeInteger($post['tableNumber'] ?? "");
    $action = Sanitizer::sanitize($post['action'] ?? "");
    $importTrainees = in_array($action, ["importTrainees", "importBoth"]);
    $importFaculty = in_array($action, ["importFaculty", "importBoth"]);
    $html = "";
    if ($filename && file_exists($filename)) {
        $fp = fopen($filename, "r");
        if ($fp) {
            $headers = fgetcsv($fp);
            $cols = [];
            $colsToTest = [];
            if ($tableNum == 5) {
                if (preg_match("/Faculty/", $headers[0]) && $importFaculty) {
                    $cols[] = 0;
                }
                if (($headers[1] == "Trainee Name") && $importTrainees) {
                    $cols[] = 1;
                }
                $colsToTest = [2, 3];
            } else if ($tableNum == 8) {
                if (($headers[0] == "Trainee") && $importTrainees) {
                    $cols[] = 0;
                }
                if (preg_match("/Faculty/", $headers[1]) && $importFaculty) {
                    $cols[] = 1;
                }
                $colsToTest = [2];
            }
            if (!empty($cols)) {
                $html .= NIHTables::importNamesFromCSV($fp, $cols, $colsToTest, $token, $server);
            } else {
                $nihLink = NIHTables::NIH_LINK;
                $html .= "<div class='red padded'>ERROR! The header/first row must contain 'Trainee Name', 'Trainee', or 'Faculty Member' according to <a href='$nihLink'>NIH Formatting</a>.</div>\n";
            }
            fclose($fp);
        } else {
            $html .= "<div class='red padded'>ERROR! Could not read file.</div>\n";
        }
    } else {
        $html .= "<div class='red padded'>ERROR! Could not find file.</div>\n";
    }
    return $html;
}

require_once(dirname(__FILE__)."/cronLoad.php");
