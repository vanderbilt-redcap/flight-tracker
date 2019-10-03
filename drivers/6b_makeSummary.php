<?php

# used every time that the summaries are recalculated 
# 15-30 minute runtimes

$sendEmail = false;
$screen = true;
$br = "\n";
if (isset($_GET['pid']) || (isset($argv[1]) && ($argv[1] == "prod_cron"))) {
	$br = "<br>";
	$sendEmail = true;
	$screen = false;
}

require_once(dirname(__FILE__)."/../classes/Grants.php");
require_once(dirname(__FILE__)."/../classes/SummaryGrants.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");
require_once(dirname(__FILE__)."/../small_base.php");
define("NOAUTH", true);
require_once(dirname(__FILE__)."/../../../redcap_connect.php");
require_once(APP_PATH_DOCROOT.'/ProjectGeneral/math_functions.php');

if ($tokenName == $info['unit']['name']) {
	require_once(dirname(__FILE__)."/setup6.php");
}
$lockFile = APP_PATH_TEMP."6_makeSummary.lock";
$lockStart = time();
if (file_exists($lockFile)) {
	$mssg = "Another instance is already running.";
	\REDCap::email($victrEmail, "no-reply@vanderbilt.edu", "CareerDev makeSummary script die", $mssg);
	die($mssg);
}
$fp = fopen($lockFile, "w");
fwrite($fp, date("Y-m-d h:m:s"));
fclose($fp);

# if this is specified as the 2nd command-line argument, it will only run for one record
$selectRecord = "";
if (isset($argv[2])) {
	$selectRecord = $argv[2];
}
$GLOBALS['selectRecord'] = $selectRecord;

$echoToScreen .= "SERVER: ".$server."$br";
$echoToScreen .= "TOKEN: ".$token."$br";
$echoToScreen .= "PID: ".$pid."$br";
if ($selectRecord) {
	$echoToScreen .= "RECORD: ".$selectRecord."$br";
}
$echoToScreen .= "$br";

if (!isset($_GET['pid'])) {
	if (!isset($argv[1]) || (($pid == 66635) && isset($argv[1]) && ($argv[1] != "prod_override") && ($argv[1] != "prod_cron"))) {
		$a = readline("Are you sure? > ");
		if ($a != "y") {
			unlink($lockFile);
			die();
		}
	}
}

$errors = array();
$_GLOBAL["errors"] = $errors;
$_GLOBAL["echoToScreen"] = $echoToScreen;

# tests the JSON string to see if there are errors
function testOutput($jsonStr) {
	global $errors;

	$data = json_decode($jsonStr, true);
	if ($data && isset($data['count'])) {
		return;
	} else if ($jsonStr) {
		$errors[] = $jsonStr;
	}
}

# get NIH mechanism from the sponsor award number
function getMechanismFromData($recordId, $sponsorNo, $data) {
	$baseAwardNo = getBaseAwardNumber($sponsorNo);
	foreach ($data as $row) {
		if ($row['record_id'] == $recordId) {
			if (($sponsorNo == $row['coeus_sponsor_award_number']) || ($baseAwardNo == getBaseAwardNumber($row['coeus_sponsor_award_number']))) {
				return getMechanism($row, $data);
			}
		}
	}
	return "";
}

# gets the NIH mechanism
function getMechanism($row, $data) {
	$recordId = $row['record_id'];
	$baseAwardNo = getBaseAwardNumber($row['coeus_sponsor_award_number']);
	foreach ($data as $row2) {
		if ($row2['record_id'] == $recordId) {
			$baseAwardNo2 = getBaseAwardNumber($row2['coeus_sponsor_award_number']);
			if ($baseAwardNo2 == $baseAwardNo) {
				if ($row2['coeus_nih_mechanism']) {
					return $row2['coeus_nih_mechanism'];
				}
			}
		}
	}
	return "";
}

# transforms a degree select box to something usable by other variables
function transformSelectDegree($num) {
	if (!$num) {
		return "";
	}
	$transform = array(
			1 => 5,   #MS
			2 => 4,   # MSCI
			3 => 3,   # MPH
			4 => 6,   # other
			);
	return $transform[$num];
}

# if someone puts @vanderbilt for the domain of an email, this puts @vanderbilt.edu
function formatEmail($email) {
	if (preg_match("/@/", $email)) {
		return preg_replace("/vanderbilt$/", "vanderbilt.edu", $email);
	}
	return "";
}

# returns an array of (variableName, variableType) for when they left VUMC
# used for the Scholars' survey and Follow-Up surveys
function findVariableWhenLeftVU($normativeRow, $rows) {
	$followupRows = selectFollowupRows($rows);
	foreach ($followupRows as $instance => $row) {
		$prefices = array(
					"followup_prev1" => "followup",
					"followup_prev2" => "followup",
					"followup_prev3" => "followup",
					"followup_prev4" => "followup",
					"followup_prev5" => "followup",
				);
		foreach ($prefices as $prefix => $type) {
			$variable = $prefix."_institution";
			$variable_date = $prefix."_academic_rank_enddt";
			if (isset($row[$variable]) &&
				(preg_match("/vanderbilt/", strtolower($row[$variable])) || preg_match("/vumc/", strtolower($row[$variable]))) &&
				isset($row[$variable_date]) &&
				($row[$variable_date] != "")) {

				return array($variable_date, $type);
			}
		}
	}

	if ($normativeRow['check_institution'] != 1) {
		$prefices = array(
					"check_prev1" => "scholars",
					"check_prev2" => "scholars",
					"check_prev3" => "scholars",
					"check_prev4" => "scholars",
					"check_prev5" => "scholars",
				);
		foreach ($prefices as $prefix => $type) {
			$variable = $prefix."_institution";
			$variable_date = $prefix."_academic_rank_enddt";
			if (isset($normativeRow[$variable]) &&
				(preg_match("/vanderbilt/", strtolower($normativeRow[$variable])) || preg_match("/vumc/", strtolower($normativeRow[$variable]))) &&
				isset($normativeRow[$variable_date]) &&
				($normativeRow[$variable_date] != "")) {

				return array($variable_date, $type);
			}
		}
	}

	return array("", "");
}

# key = instance; value = REDCap data row
function selectFollowupRows($rows) {
	foreach ($rows as $row) {
		if ($row['redcap_repeat_instrument'] == "followup") {
			$followupRows[$row['redcap_repeat_instance']] = $row;
		}
	}
	krsort($followupRows);		// start with most recent survey
	return $followupRows;
}

# find the next maximum for instrument $instrument
function findNextMax($rows, $instrument, $skip) {
	$max = 0;
	foreach ($rows as $rowForNextMax) {
		if (($row['redcap_repeat_instrument'] == $instrument) && ($row['redcap_repeat_instance'] > $max) && (!in_array($row['redcap_repeat_instance'], $skip))) {
			$max = $row['redcap_repeat_instance'];
		}
	}
	return $max;
}

# calculate the primary mentor
function calculatePrimaryMentor($rows) {
	$repeatingForms = array("followup");
	$order = array(
			"override_mentor" => "override",
			"followup_primary_mentor" => "followup",
			"check_primary_mentor" => "scholars",
			"vfrs_mentor1" => "vfrs",
			"newman_data_mentor1" => "data",
			"newman_sheet2_mentor1" => "sheet2",
			"newman_new_mentor1" => "new2017",
			);
	foreach ($order as $field => $src) {
		if (in_array($src, $repeatingForms)) {
			$processed = array();	// keep track of prior instances processed
			$max = findNextMax($rows, $src, $processed);

			// stop when all instances processed   ==> stop when $max == 0
			while ($max) {
				foreach ($rows as $row) {
					if (($row['redcap_repeat_instrument'] == $src) && ($row['redcap_repeat_instance'] == $max)) {
						$processed[] = $row['redcap_repeat_instance'];
						if ($row[$field]) {
							return array($row[$field], $src);
						} else {
							$max = findNextMax($rows, $src, $processed);
						}
						break;
					}
				}
			}
		} else {
			foreach ($rows as $row) { 
			 	if ($row['redcap_repeat_instrument'] == "") {
					# normative row
					if ($row[$field]) {
						return array($row[$field], $src);
					}
					break;
				}
			}
		}
	}
	return array("", "");
}

# calculate when left Vanderbilt
function calculateLeftVanderbilt($row, $rows) {
	$order = array();
	$leftVUAry = findVariableWhenLeftVU($row, $rows);
	$leftVU = $leftVUAry[0];
	$leftVUType = $leftVUAry[1];
	if ($leftVU != "") {
		$order[$leftVU] = $leftVUType;
	}
	$order["newman_data_date_left_vanderbilt"] = "data";
	$order["newman_sheet2_left_vu"] = "sheet2";
	$order["overrides_left_vu"] = "override";
	foreach ($order as $field => $type) {
		if ($row[$field] && preg_match("/^\d\d\d\d-\d\d-\d\d$/", $row[$field])) {
			return array($row[$field], $type);
		}
	}
	return array("", "");
}

# calculate preferred email address
function calculateEmail($normativeRow, $rows) {
	$order = array(
			"override_email" => "override",
			"followup_email" => "followup",
			"check_email" => "scholars",
			"vfrs_email" => "vfrs",
			"newman_data_email" => "data",
			"newman_sheet2_project_email" => "sheet2",
			);
	foreach ($order as $field => $type) {
		if ($type == "followup") {
			foreach ($rows as $row) {
				if ($row['redcap_repeat_instrument'] == "followup") {
					if ($row[$field]) {
						return array(formatEmail($row[$field]), $type);
					}
				}
			}
		} else if ($normativeRow[$field]) {
			return array(formatEmail($normativeRow[$field]), $type);
		}
	}
	return array("", "");
}

# calculates the first degree
function calculateFirstDegree($row) {
	# move over and then down
	$order = array(
			array("override_degrees" => "override"),
			array("check_degree1" => "scholars", "check_degree2" => "scholars", "check_degree3" => "scholars", "check_degree4" => "scholars", "check_degree5" => "scholars"),
			array("vfrs_graduate_degree" => "vfrs", "vfrs_degree2" => "vfrs", "vfrs_degree3" => "vfrs", "vfrs_degree4" => "vfrs", "vfrs_degree5" => "vfrs", "vfrs_please_select_your_degree" => "vfrs"),
			array("newman_new_degree1" => "new2017", "newman_new_degree2" => "new2017", "newman_new_degree3" => "new2017"),
			array("newman_data_degree1" => "data", "newman_data_degree2" => "data", "newman_data_degree3" => "data"),
			array("newman_demographics_degrees" => "demographics"),
			array("newman_sheet2_degree1" => "sheet2", "newman_sheet2_degree2" => "sheet2", "newman_sheet2_degree3" => "sheet2"),
			);

	# combines degrees and sets up for translateFirstDegree
	$value = "";
	$degrees = array();
	foreach ($order as $variables) {
		foreach ($variables as $variable => $type) {
			if ($variable == "vfrs_please_select_your_degree") {
				$row[$variable] = transformSelectDegree($row[$variable]);
			}
			if ($row[$variable] && !in_array($row[$variable], $degrees)) {
				$degrees[] = $row[$variable];
			}
		}
	}
	if (empty($degrees)) {
		return "";
	} else if (in_array(1, $degrees) || in_array(9, $degrees) || in_array(10, $degrees) || in_array(7, $degrees) || in_array(8, $degrees) || in_array(14, $degrees) || in_array(12, $degrees)) { #MD
		if (in_array(2, $degrees) || in_array(9, $degrees) || in_array(10, $degrees)) {
			$value = 10;  # MD/PhD
		} else if (in_array(3, $degrees) || in_array(16, $degrees) || in_array(18, $degrees)) { #MPH
			$value = 7;
		} else if (in_array(4, $degrees) || in_array(7, $degrees)) { #MSCI
			$value = 8;
		} else if (in_array(5, $degrees) || in_array(8, $degrees)) { # MS
			$value = 9;
		} else if (in_array(6, $degrees) || in_array(13, $degrees) || in_array(14, $degrees)) { #Other
			$value = 7;     # MD + other
		} else if (in_array(11, $degrees) || in_array(12, $degrees)) { #MHS
			$value = 12;
		} else {
			$value = 1;   # MD only
		}
	} else if (in_array(2, $degrees)) { #PhD
		if (in_array(11, $degrees)) {
			$value = 10;  # MD/PhD
		} else if (in_array(3, $degrees)) { # MPH
			$value = 2;
		} else if (in_array(4, $degrees)) { # MSCI
			$value = 2;
		} else if (in_array(5, $degrees)) { # MS
			$value = 2;
		} else if (in_array(6, $degrees)) { # Other
			$value = 2;
		} else {
			$value = 2;     # PhD only
		}
	} else if (in_array(6, $degrees)) {  # Other
		if (in_array(1, $degrees)) {   # MD
			$value = 7;  # MD + other
		} else if (in_array(2, $degrees)) {  #PhD
			$value = 2;
		} else {
			$value = 6;
		}
	} else if (in_array(3, $degrees)) {  # MPH
		$value = 6;
	} else if (in_array(4, $degrees)) {  # MSCI
		$value = 6;
	} else if (in_array(5, $degrees)) {  # MS
		$value = 6;
	} else if (in_array(15, $degrees)) {  # PsyD
		$value = 6;
	}
	return $value;
}

# List of reclassifications
# "Internal K" => 1,
# "K12/KL2" => 2,
# "Individual K" => 3,
# "K Equivalent" => 4,
# "R01" => 5,
# "R01 Equivalent" => 6,
function maybeReclassifyIntoType($awardNo) {
	$translate = array(
		"Human Frontiers in Science CDA" => "K Equivalent",
		"VA Merit" => "R01 Equivalent",
		"VA Career Development Award" => "K Equivalent",
		"VA Career Dev. Award" => "K Equivalent",
		"K award VCRS" => "K12/KL2",
		"VACDA" => "K Equivalent",
		"1I01BX00219001" => "R01 Equivalent",
		"1I01BX002223-01A1 (VA Merit)" => "R01 Equivalent",
		"Robert Wood Johnson Faculty Development Award" => "K Equivalent",
		"VPSD" => "Internal K",
		"VCRS" => "K12/KL2",
		"ACS Scholar (R01-equiv)" => "K Equivalent",
		"Major DOD award" => "R01 Equivalent",
		"Dermatology Foundation Physician-Scientist Career Development Award" => "K Equivalent",
		"Damon Runyon Cancer Research Foundation Clinical Investigator award" => "K Equivalent",
		"LUNGevity CDA" => "K Equivalent",
		"ACS Scholar Award" => "K Equivalent",
		"VCRS" => "K12/KL2",
		"VA Career Dev" => "K Equivalent",
		"Peds K12" => "K12/KL2",
		"VA Merit Award" => "R01 Equivalent",
		"BIRCWH: ACS Mentored Research Scholar Grant" => "K12/KL2",
		"AHA FtF" => "K Equivalent",
		"AHA FtF|1R01HL128983-01A1" => "K Equivalent",
		"DOD grant (R01-equivalent)" => "R01 Equivalent",
		"Burroughs Wellcome CAMS 2013" => "K Equivalent",
		"VCTRS" => "Internal K",
		"1IK2BX002498-01_(VACDA)" => "K Equivalent",
		"VCORCDP" => "K12/KL2",
		"VCORCPD" => "K12/KL2",
		"VCRS/VCTRS" => "Internal K",
		"VEHSS" => "K12/KL2",
		"VPSD" => "Internal K",
		"VPSD - P30 ARRA" => "Internal K",
		"VPSD" => "Internal K",
		"VEHSS" => "K12/KL2",
		"K award" => "Individual K",
		"VFRS" => "Internal K",
		"VCRS" => "Internal K",
		"1IK2BX002126-01 (VA CDA)" => "K Equivalent",
		"1U01HG004798-01" => "R01 Equivalent",
		"VPSD" => "Internal K",
		"Kaward" => "Individual K",
		"K 99" => "Individual K",
		"NIEHS" => "K12/KL2",
		"1U01CA182364-01(MPI)" => "R01 Equivalent",
		"VPSD" => "Internal K",
		"1IK2BX001701-01A2 (VA CDA)" => "K Equivalent",
		"VA CDA" => "K Equivalent",
		"1U01CA143072-01 (MPI)" => "R01 Equivalent",
		"1IK2HX000758-01 (VA CDA)" => "K Equivalent",
		"VEMRT" => "K12/KL2",
		"1U01IP000464-01" => "R01 Equivalent",
		"VPSD" => "Internal K",
		"LUNGevity Foundation" => "K Equivalent",
		"VICMIC" => "K12/KL2",
		"V-POCKET" => "K12/KL2",
		"BIRCWH" => "K12/KL2",
		"1UM1CA186704-01" => "R01 Equivalent",
		"5P50CA098131-12" => "R01 Equivalent",
		"1IK2HX000988-01A1 (VACDA)" => "Individual K",
		"1IK2BX002929-01A2" => "K Equivalent",
		"VA CDA" => "K Equivalent",
		"NASPGHAN-CDHNF Fellow to Faculty Transition Award in Inflammatory Bowel Diseases" => "K Equivalent",
		"Robert Wood Johnson Faculty Development Award" => "K Equivalent",
		"VACDA" => "K Equivalent",
		"VCTRS-RWJF" => "Internal K",
		"VACDA" => "K Equivalent",
		"PhARMA foundation award" => "K Equivalent",
		"1U01AI096186-01 (MPI)" => "R01 Equivalent",
		"1U2GGH000812-01" => "R01 Equivalent",
		"5IK2BX002797-02" => "K Equivalent",
		"1RC4MH092755-01" => "R01 Equivalent",
	);

	if (isset($translate[$awardNo])) {
		return $translate[$awardNo];
	}

	return "";
}

# translates from innate ordering into new categories in summary_degrees
function translateFirstDegree($num) {
	$translate = array(
			"" => "",
			1 => 1,
			2 => 4,
			6 => 6,
			7 => 3,
			8 => 3,
			9 => 3,
			10 => 2,
			11 => 2,
			12 => 3,
			13 => 3,
			14 => 6,
			15 => 3,
			16 => 6,
			17 => 6,
			18 => 6,
			3 => 6,
			4 => 6,
			5 => 6,
			);
	return $translate[$num];
}

# as name states
function calculateGender($row) {
	$order = array(
			"override_gender" => "override",
			"check_gender" => "scholars",
			"vfrs_gender" => "vfrs",
			"newman_new_gender" => "new2017",
			"newman_demographics_gender" => "demographics",
			"newman_data_gender" => "data",
			"newman_nonrespondents_gender" => "nonrespondents",
			);

	$tradOrder = array("override_gender", "check_gender");
	foreach ($order as $variable => $type) {
		if ($row[$variable] !== "") {
			if (in_array($variable, $tradOrder)) {
				return array($row[$variable], $type);
			}
			if ($row[$variable] == 1) {  # Male
				return array(2, $type);
			} else if ($row[$variable] == 2) {   # Female
				return array(1, $type);
			}
			# forget no-reports and others
		}
	}
	return array("", "");
}

# returns array of 3 (overall classification, race type, ethnicity type)
function calculateAndTranslateRaceEthnicity($row) {
	$orderRace = array(
				"override_race" => "override", 
				"check_race" => "scholars", 
				"vfrs_race" => "vfrs", 
				"newman_new_race" => "new2017",
				"newman_demographics_race" => "demographics",
				"newman_data_race" => "data",
				"newman_nonrespondents_race" => "nonrespondents",
				);
	$orderEth = array(	
				"override_ethnicity" => "override",
				"check_ethnicity" => "scholars",
				"vfrs_ethnicity" => "vfrs",
				"newman_new_ethnicity" => "new2017",
				"newman_demographics_ethnicity" => "demographics",
				"newman_data_ethnicity" => "data",
				"newman_nonrespondents_ethnicity" => "nonrespondents",
				);
	$race = "";
	$raceType = "";
	foreach ($orderRace as $variable => $type) {
		if (($row[$variable] !== "") && ($row[$variable] != 8)) {
			$race = $row[$variable];
			$raceType = $type;
			break;
		}
	}
	if ($race === "") {
		return array("", "", "");
	}
	$eth = "";
	$ethType = "";
	foreach ($orderEth as $variable => $type) {
		if (($row[$variable] !== "") && ($row[$variable] != 4)) {
			$eth = $row[$variable];
			$ethType = $type;
			break;
		}
	}
	$val = "";
	if ($race == 2) {   # Asian
		$val = 5;
	}
	if ($eth == "") {
		return array("", "", "");
	}
	if ($eth == 1) { # Hispanic
		if ($race == 5) { # White
			$val = 3;
		} else if ($race == 4) { # Black
			$val = 4;
		}
	} else if ($eth == 2) {  # non-Hisp
		if ($race == 5) { # White
			$val = 1;
		} else if ($race == 4) { # Black
			$val = 2;
		}
	}
	if ($val === "") {
		$val = 6;  # other
	}
	return array($val, $raceType, $ethType);
}

# convert date
function convertToYYYYMMDD($date) {
	$nodes = preg_split("/[\-\/]/", $date);
	if (($nodes[0] == 0) || ($nodes[1] == 0)) {
		return "";
	}
	if ($nodes[0] > 1900) {
		return $nodes[0]."-".$nodes[1]."-".$nodes[2];
	}
	if ($nodes[2] < 1900) {
		if ($nodes[2] < 20) {
			$nodes[2] = 2000 + $nodes[2];
		} else {
			$nodes[2] = 1900 + $nodes[2];
		}
	}
	// from MDY
	return $nodes[2]."-".$nodes[0]."-".$nodes[1];
}

# finds date-of-birth
function calculateDOB($row) {
	$order = array(
			"check_date_of_birth" => "scholars",
			"vfrs_date_of_birth" => "vfrs",
			"newman_new_date_of_birth" => "new2017",
			"newman_demographics_date_of_birth" => "demographics",
			"newman_data_date_of_birth" => "data",
			"newman_nonrespondents_date_of_birth" => "nonrespondents",
			);

	foreach ($order as $variable => $type) {
		if ($row[$variable] !== "") {
			$date = convertToYYYYMMDD($row[$variable]);
			if ($date) {
				return array($date, $type);
			}
		}
	}
	return array("", "");
}

# VFRS did not use the 6-digit classification, so we must translate
function transferVFRSDepartment($dept) {
	$exchange = array(
				1	=> 104300,
				2	=> 104250,
				3	=> 104785,
				4	=> 104268,
				5	=> 104286,
				6	=> 104705,
				7	=> 104280,
				8	=> 104791,
				9	=> 999999,
				10	=> 104782,
				11	=> 104368,
				12	=> 104270,
				13	=> 104400,
				14	=> 104705,
				15	=> 104425,
				16	=> 104450,
				17	=> 104366,
				18	=> 104475,
				19	=> 104781,
				20	=> 104500,
				21	=> 104709,
				22	=> 104595,
				23	=> 104290,
				24	=> 104705,
				25	=> 104625,
				26	=> 104529,
				27	=> 104675,
				28	=> 104650,
				29	=> 104705,
				30	=> 104726,
				31	=> 104775,
				32	=> 999999,
				33	=> 106052,
				34	=> 104400,
				35	=> 104353,
				36	=> 120430,
				37	=> 122450,
				38	=> 120660,
				39	=> 999999,
				40	=> 104705,
				41	=> 104366,
				42	=> 104625,
				43	=> 999999,
			);
	if (isset($exchange[$dept])) {
		return $exchange[$dept];
	}
	return "";
}

# calculates a secondary department
# not used because primary department is only one needed
# may be out of date
function calculateSecondaryDept($row) {
	$order = array(
			"newman_demographics_department2" => "demographics",
			"newman_data_departments2" => "data",
			"newman_sheet2_department2" => "sheet2",
			"vfrs_department" => "vfrs",
			"newman_new_department" => "new2017",
			"newman_demographics_department1" => "demographics",
			"newman_data_department1" => "data",
			"newman_sheet2_department1" => "sheet2",
			);

	$ary = calculatePrimaryDept($row);
	$primDept = $ary[0];
	foreach ($order as $variable => $type) {
		$dept = $row[$variable];
		if (preg_match("/^vfrs_/", $variable)) {
			$dept = transferVFRSDepartment($row[$variable]);
		}
		if (($row[$variable] !== "") && ($primDept != $dept)) {
			return array($dept, $type);
		}
	}
	return array("", "");
}

# calculates a primary department
function calculatePrimaryDept($row) {
	$order = array(
			"override_department1" => "override",
			"check_primary_dept" => "scholars",
			"vfrs_department" => "vfrs",
			"newman_new_department" => "new2017",
			"newman_demographics_department1" => "demographics",
			"newman_data_department1" => "data",
			"newman_sheet2_department1" => "sheet2",
			);
	$default = array();
	$overridable = array(104368 => 104366, 999999 => "ALL");

	foreach ($order as $variable => $type) {
		$dept = $row[$variable];
		if (preg_match("/^vfrs_/", $variable)) {
			$dept = transferVFRSDepartment($row[$variable]);
		}
		if ($row[$variable] !== "") {
			if (empty($default)) {
				$default = array($dept, $type);
			} else {
				foreach ($overridable as $oldNo => $newNo) {
					if ($oldNo == $default[0]) {
						if (($newNo == "ALL") || ($newNo == $dept)) {
							$default = array($dept, $type);
						}
					}
				}
			}
			
		}
	}
	if (empty($default)) {
		return array("", "");
	}
	return $default;
}

# calculates if citizenship asserted
function calculateCitizenship($row) {
	$order = array(
			"check_citizenship" => "scholars",
			);

	foreach ($order as $variable => $type) {
		if ($row[$variable] !== "") {
			return array($row[$variable], $type);
		}
	}
	return array("", "");
}

# calculate current rank of academic appointment
function calculateAppointments($normativeRow, $rows) {
	$followupRows = selectFollowupRows($rows);
	$outputRow = array();
	$calculatePrevious = false;

	# setup search variables
	$vars = array(
			"followup_primary_dept" => array("summary_primary_dept", "followup"),
			"check_primary_dept" => array("summary_primary_dept", "scholars"),
			"followup_division" => array("summary_current_division", "followup"),
			"check_division" => array("summary_current_division", "scholars"),
			"override_rank" => array("summary_current_rank", "override"),
			"followup_academic_rank" => array("summary_current_rank", "followup"),
			"check_academic_rank" => array("summary_current_rank", "scholars"),
			"newman_new_rank" => array("summary_current_rank", "new2017"),
			"followup_academic_rank_dt" => array("summary_current_start", "followup"),
			"check_academic_rank_dt" => array("summary_current_start", "scholars"),
			"followup_tenure_status" => array("summary_current_tenure", "followup"),
			"check_tenure_status" => array("summary_current_tenure", "scholars"),
			);
	if ($calculatePrevious) {
		for ($i = 1; $i <= 5; $i++) {
			$vars['check_prev'.$i.'_primary_dept'] = array("summary_prev".$i."_dept", "scholars");
			$vars['check_prev'.$i.'_division'] = array("summary_prev".$i."_division", "scholars");
			$vars['check_prev'.$i.'_institution'] = array("summary_prev".$i."_institution", "scholars");
			$vars['check_prev'.$i.'_academic_rank'] = array("summary_prev".$i."_rank", "scholars");
			$vars['check_prev'.$i.'_academic_rank_stdt'] = array("summary_prev".$i."_start", "scholars");
			$vars['check_prev'.$i.'_academic_rank_enddt'] = array("summary_prev".$i."_end", "scholars");
		}
		for ($i = 1; $i <= 5; $i++) {
			$vars['followup_prev'.$i.'_primary_dept'] = array("summary_prev".$i."_dept", "followup");
			$vars['followup_prev'.$i.'_division'] = array("summary_prev".$i."_division", "followup");
			$vars['followup_prev'.$i.'_institution'] = array("summary_prev".$i."_institution", "followup");
			$vars['followup_prev'.$i.'_academic_rank'] = array("summary_prev".$i."_rank", "followup");
			$vars['followup_prev'.$i.'_academic_rank_stdt'] = array("summary_prev".$i."_start", "followup");
			$vars['followup_prev'.$i.'_academic_rank_enddt'] = array("summary_prev".$i."_end", "followup");
		}
	}

	# fill
	if ($row['check_institution'] != "") {
		$outVar = "summary_current_institution";
		if ($row['check_institution'] == 1) {
			$outputRow[$outVar] = array("Vanderbilt", "scholars");
		} else if ($row['check_institution'] == 2) {
			$outputRow[$outVar] = array("Meharry", "scholars");
		} else if ($row['check_institution_oth']) {
			$outputRow[$outVar] = array($row['check_institution_oth'], "scholars");
		} else {
			$outputRow[$outVar] = array("Other", "scholars");
		}
	}
	$indexedRows = array();
	foreach ($vars as $src => $ary) {
		$dest = $ary[0];
		$srcType = $ary[1];
		if ($srcType == "followup") {
			foreach ($followupRows as $instance => $row) {
				# only use most recent follow-up survey
				$indexedRows[$src] = $row;
				break;
			}
		} else {
			$indexedRows[$src] = $normativeRow;
		}
	}
	foreach ($vars as $src => $ary) {
		$dest = $ary[0];
		$srcType = $ary[1];
		$row = $indexedRows[$src];
		if ($row[$src] != "") {
			if (preg_match("/_end$/", $dest) || preg_match("/_start$/", $dest)) {
				$outputRow[$dest] = array(convertToYYYYMMDD($row[$src]), $srcType);
			} else {
				$seen = false;
				foreach ($vars as $src2 => $ary2) {
					$dest2 = $ary2[0];
					$srcType2 = $ary2[1];
					if ($src2 == $src) {
						break;
					}
					if ($dest2 == $dest) {
						# before src2 == src matched
						$seen = true;
					}
				}
				if (!$seen) {
					$outputRow[$dest] = array($row[$src], $srcType);
				} else if (!isset($outputRow[$dest]) || ($outputRow[$dest] == "")) {
					# blank => overwrite
					$outputRow[$dest] = array($row[$src], $srcType);
				}
			}
		}
	}

	return $outputRow;
}

# Finds the type of the CDA for the award at $index
function calculateCDAType($data, $row, $index) {
	global $echoToScreen, $br;
	$awardTypes = getAwardTypes();
	$cdav = getOrderedCDAVariables($data, $row['record_id']);
	$variables = $cdav[0];
	if (count($variables) <= $index) {
		return array($awardTypes["N/A"], getBlankSpecs());
	}
	if ($index < 0) {
		return array($awardTypes["N/A"], getBlankSpecs());
	}
	$skip = array("modify", "custom");
	if (in_array($variables[$index]["source"], $skip)) {
		$awardType = array($variables[$index]["redcap_type"], $variables[$index]);
	} else {
		// $echoToScreen .= "calculateCDAType 1: ".json_encode($variables[$index])."$br";
		$awardType = calculateAwardType($variables[$index]);
		// $echoToScreen .= "calculateCDAType 2: ".json_encode($awardType)."$br";
	}
	if (isset($awardTypes[$awardType[0]])) {
		return array($awardTypes[$awardType[0]], $awardType[1]);
	}
	return array($awardTypes["N/A"], getBlankSpecs());
}

function makeUpper($str) {
	$uclist = "\t\r$br\f\v(-";
	$str = ucwords($str, $uclist);
	while (preg_match("/ [a-z]\.? /", $str, $matches)) {
		$orig = $matches[0];
		$upper = strtoupper($orig);
		$str = str_replace($orig, $upper, $str);
	}
	if (preg_match("/ [a-z]\.?$/", $str, $matches)) {
		$orig = $matches[0];
		$upper = strtoupper($orig);
		$str = str_replace($orig, $upper, $str);
	}
	return $str;
}

# Returns the COEUS person-name for the first instance that contains it inside of Record $recordId
# Assume $data is ordered, as in downloaded REDCap data
function getCoeusPersonName($recordId, $data) {
	foreach ($data as $row) {
		if (($row['record_id'] == $recordId) && isset($row['redcap_repeat_instrument']) && ($row['redcap_repeat_instrument'] == "coeus") && $row['coeus_person_name']) {
			return $row['coeus_person_name'];
		}
	}
	return "";
}

# converted to R01/R01-equivalent in $row for $typeOfK ("any" vs. "external")
# return value:
# 		1, Converted K to R01-or-Equivalent While on K
#		2, Converted K to R01-or-Equivalent Not While on K
#		3, Still On K; No R01-or-Equivalent
#		4, Not On K; No R01-or-Equivalent
#		5, No K, but R01-or-Equivalent
#		6, No K; No R01-or-Equivalent
function converted($row, $typeOfK) {
	if ($row['summary_'.$typeOfK.'_k'] && $row['summary_first_r01']) {
		if (preg_match('/last_/', $typeOfK)) {
			$prefix = "last";
		} else {
			$prefix = "first";
		}
		if ($row['summary_'.$prefix.'_external_k'] == $row['summary_'.$prefix.'_any_k']) {
			if (datediff($row['summary_'.$typeOfK.'_k'], $row['summary_first_r01'], "y") <= 5) {
				return 1;
			} else {
				return 2;
			}
		} else {
			if (datediff($row['summary_'.$typeOfK.'_k'], $row['summary_first_r01'], "y") <= 3) {
				return 1;
			} else {
				return 2;
			}
		}
	} else if ($row['summary_'.$typeOfK.'_k']) {
		if (preg_match('/last_/', $typeOfK)) {
			$prefix = "last";
		} else {
			$prefix = "first";
		}
		if ($row['summary_".$prefix."_external_k'] == $row['summary_".$prefix."_any_k']) {
			if (datediff($row['summary_'.$typeOfK.'_k'], date('Y-m-d'), "y") <= 5) {
				return 3;
			} else {
				return 4;
			}
		} else {
			if (datediff($row['summary_'.$typeOfK.'_k'], date('Y-m-d'), "y") <= 3) {
				return 3;
			} else {
				return 4;
			}
		}
	} else {
		if ($row['summary_first_r01']) {
			return 5;
		} else {
			return 6;
		}
	}
}

# downloads the record id's for a list
$recordIds = Download::recordIds($token, $server);
if ($selectRecord) {
	$recordIds = array($selectRecord);
}
error_log("6b CareerDev downloaded ".json_encode($recordIds));

$echoToScreen .= count($recordIds)." record ids (max ".max($recordIds).").$br";
if (count($recordIds) == 0) {
	$echoToScreen .= "$output$br";
}

# uses all of the above functions to assign to REDCap variables
# done in batches
$total = array();
$found = array();
$total['demographics'] = 0; $found['demographics'] = 0;
$total['CDAs'] = 0; $found['CDAs'] = 0;
$cdaType = array();
for ($i = 1; $i <= 7; $i++) {
	$cdaType[$i] = 0;
}
$cdaType[99] = 0;
$manuals = array();
$awards = array();

if (!$selectRecord) {
	$echoToScreen .= "Changing metadata (downloading)...$br";
	$metadata = Download::metadata($token, $server);
	error_log("6b CareerDev downloaded metadata: ".count($metadata));
	$awardTypes = getAwardTypes();
	$awardTypesIntermediate = array();
	foreach ($awardTypes as $label => $value) {
		$awardTypesIntermediate[] = $value.", ".$label;
	}
	$awardTypesStr = implode(" | ", $awardTypesIntermediate);
	$i = 0;
	foreach ($metadata as $row) {
		if (preg_match("/^summary_award_type/", $row['field_name'])) {
			$metadata[$i]['select_choices_or_calculations'] = $awardTypesStr;
		}
		$i++;
	}

	error_log("6b CareerDev changing metadata");
	$echoToScreen .= "Changing metadata (uploading)...$br";
	$result = Upload::metadata($metadata, $token, $server);
	$output = json_encode($result);
	error_log("6b CareerDev changed metadata: ".$output);
}

# done in batches of 10 records
$pullSize = 10;
$numPulls = ceil(count($recordIds) / $pullSize);
if ($selectRecord) {
	$numPulls = 1;
}
for ($pull = 0; $pull < $numPulls; $pull++) {
	$records = array();
	$thisPullSize = $pullSize;
	if (count($recordIds) < $thisPullSize * ($pull + 1)) {
		$thisPullSize = count($recordIds) % $pullSize;
	}
	if ($selectRecord) {
		$records[] = $selectRecord;
	} else {
		for ($j = 0; $j < $thisPullSize; $j++) {
			$records[] = $recordIds[$pull * $pullSize + $j];
		}
	}

	$echoToScreen .= ($pull + 1)." of $numPulls) Getting data ($thisPullSize) ".json_encode($records)."$br";
	error_log("6b CareerDev ".($pull + 1).") Getting data ($thisPullSize) ".json_encode($records));
	$redcapData = Download::records($token, $server, $records);

	$echoToScreen .= "Pull ".($pull + 1)." downloaded ".count($redcapData)." rows of REDCap data.$br";

	$i = 0;
	$newData = array();
	// $echoToScreen .= "Making data set$br";
	$maxAwards = 15;
	$redcapDataRows = array();
	foreach ($redcapData as $row) {
		if (!isset($redcapDataRows[$row['record_id']])) {
			$redcapDataRows[$row['record_id']] = array();
		}
		$redcapDataRows[$row['record_id']][] = $row;
	}

	foreach ($redcapDataRows as $recordId => $rows) {
		$grants = new Grants($token, $server, $metadata);
		$grants->setRows($rows);
		$grants->compileGrants();
		$grants->uploadGrants();

		$summaryGrants = new SummaryGrants($token, $server);
		$summaryGrants->setGrants($recordId, $grants, $metadata);
		$summaryGrants->process();
		$summaryGrants->upload();

		# update rows with new data
		error_log("6b CareerDev downloading all data for record $recordId");
		$rows = Download::records($token, $server, array($recordId));
		$newData = array();
		foreach ($rows as $row) {
			if (isset($row['redcap_repeat_instrument']) && ($row['redcap_repeat_instrument'] == "custom_grant")) {
				// $newData[] = $row;
			} else if (isset($row['redcap_repeat_instrument']) && ($row['redcap_repeat_instrument'] == "summary_grants")) {
				// $newData[] = $row;
			} else if (isset($row['redcap_repeat_instrument']) && ($row['redcap_repeat_instrument'] == "reporter")) {
				// $newData[] = $row;
			} else if (isset($row['redcap_repeat_instrument']) && ($row['redcap_repeat_instrument'] == "exporter")) {
				// $newData[] = $row;
			} else if (isset($row['redcap_repeat_instrument']) && ($row['redcap_repeat_instrument'] == "followup")) {
				// $newData[] = $row;
			} else if (isset($row['redcap_repeat_instrument']) && ($row['redcap_repeat_instrument'] == "coeus")) {
				// $newData[] = $row;
			} else if (!isset($row['redcap_repeat_instrument']) || ($row['redcap_repeat_instrument'] == "")) {
				$comments = array();

				$na = 99;
				$row2 = $row;
				$row2['summary_last_calculated'] = date("Y-m-d H:i");
				if ($row['check_name_first'] || $row['check_name_last']) {
					$row2['summary_survey'] = 1;
				} else {
					$row2['summary_survey'] = 0;
				}

				if ($row['identifier_vunet']) {
					$row2['identifier_vunet'] = strtolower($row['identifier_vunet']);
				} else if ($row['vunetid']) {
					$row2['identifier_vunet'] = strtolower($row['vunetid']);
				} else if ($row['vfrs_vunet_id']) {
					$row2['identifier_vunet'] = strtolower($row['vfrs_vu_net_id']);
				} else {
					$row2['identifier_vunet'] = "";
				}

				$row2['identifier_coeus'] = getCoeusPersonName($row['record_id'], $redcapData);

				$ary = calculateCitizenship($row);
				$row2['summary_citizenship'] = $ary[0];
				$row2['summary_citizenship_source'] = $ary[1];
				$total['demographics']++; if ($row2['summary_citizenship']) { $found['demographics']++; }

				# Appointments
				$values = calculateAppointments($row2);
				foreach ($values as $variable => $ary) {
					$value = $ary[0];
					$source = $ary[1];
					if (!isset($comments[$variable])) {
						$row2[$variable] = $value;
					}
					$row2[$variable."_source"] = $source;
					$total['demographics']++; if ($row2[$variable]) { $found['demographics']++; }
				}

				if (!isset($comments['summary_degrees'])) {
					$val = calculateFirstDegree($row);
					$val = translateFirstDegree($val);
					$row2['summary_degrees'] = $val;
				}
				$total['demographics']++; if ($row2['summary_degrees']) { $found['demographics']++; }
				if (!isset($comments['summary_gender'])) {
					$ary = calculateGender($row);
					$row2['summary_gender'] = $ary[0];
					$row2['summary_gender_source'] = $ary[1];
				}
				$total['demographics']++; if ($row2['summary_gender']) { $found['demographics']++; }
				if (!isset($comments['summary_mentor'])) {
					$ary = calculatePrimaryMentor($redcapDataRows[$row['record_id']]);
					$row2['summary_mentor'] = $ary[0];
					$row2['summary_mentor_source'] = $ary[1];
				}
				$total['demographics']++; if ($row2['summary_']) { $found['demographics']++; }
				if (!isset($comments['summary_race_ethnicity'])) {
					$ary = calculateAndTranslateRaceEthnicity($row);
					$row2['summary_race_ethnicity'] = $ary[0];
					$row2['summary_race_source'] = $ary[1];
					$row2['summary_ethnicity_source'] = $ary[2];
				}
				$total['demographics']++; if ($row2['summary_race_ethnicity']) { $found['demographics']++; }
				if (!isset($comments['summary_dob'])) {
					$ary = calculateDOB($row);
					$row2['summary_dob'] = $ary[0];
					$row2['summary_dob_source'] = $ary[1];
				}
				$total['demographics']++; if ($row2['summary_dob']) { $found['demographics']++; }
				if (!isset($comments['summary_primary_dept'])) {
					$ary = calculatePrimaryDept($row);
					$row2['summary_primary_dept'] = $ary[0];
					$row2['summary_primary_dept_source'] = $ary[1];
				}
				$total['demographics']++; if ($row2['summary_primary_dept']) { $found['demographics']++; }
				if (!isset($comments['summary_left_vanderbilt'])) {
					$ary = calculateLeftVanderbilt($row, $redcapDataRows[$row['record_id']]);
					$row2['summary_left_vanderbilt'] = $ary[0];
					$row2['summary_left_vanderbilt_source'] = $ary[1];
				}
				$selfReported = array("scholars", "vfrs");
				$newman = array( "data", "sheet2", "demographics", "new2017", "k12", "nonrespondents", "override" );
				foreach ($row2 as $field => $value) {
					$newfield = "";
					if (preg_match("/_source$/", $field)) {
						$newfield = $field."type";
					} else if (preg_match("/summary_award_source_/", $field)) {
						$newfield = preg_replace("/summary_award_source_/", "summary_award_sourcetype_", $field);
					}
					if ($newfield) {
						$newvalue = "";
						if ($value == "") {
							$newvalue = "";
						} else if (in_array($value, $selfReported)) {
							$newvalue = "1";
						} else if (in_array($value, $newman)) {
							$newvalue = "2";
						} else {
							$newvalue = "0";
						}
						$row2[$newfield] = $newvalue;
					}
				}
				$newData[] = $row2;
			}
			$i++;
		}
	
		foreach ($found as $countType => $count) {
			$echoToScreen .= "'$countType'$br";
			$echoToScreen .= ($pull + 1).") $countType fill percentage (cumulative): {$found[$countType]} / {$total[$countType]} = ".floor(($found[$countType] * 100) / $total[$countType])."%$br";
		}

		# upload data
		$echoToScreen .= ($pull + 1)." of $numPulls) $type Uploading ".count($newData)." rows of data$br";
		error_log("6b uploading ".count($newData)." rows of data");
		$result = Upload::rows($newData, $token, $server);
		$output = json_encode($result);
		$echoToScreen .= "Data results $type: ".$output."$br";
		testOutput($output);
	}
}

foreach ($found as $countType => $count) {
	$echoToScreen .= "Final) $countType fill percentage: {$found[$countType]} / {$total[$countType]} = ".floor(($found[$countType] * 100) / $total[$countType])."%$br";
}

foreach ($awardTypes as $type => $i) {
	$echoToScreen .= "$type: {$cdaType[$i]}$br";
}

$successMessage = "";
if ($screen) {
	if (empty($errors)) {
		$successMessage = "SUCCESS$br$br";
	} else {
		$successMessage = "ERRORS$br".implode($br, $errors).$br.$br;
	}
	echo $successMesssage;
 	echo $echoToScreen;
} else {
	if (count($errors) === 0) {
		$successMessage = "SUCCESS";
	} else {
		$successMessage = "ERRORs ".count($errors)."<ol>".implode($br."<li>", $errors)."</ol>";
		if ($sendEmail) {
			\REDCap::email($victrEmail, "no-reply@vanderbilt.edu", "CareerDev makeSummary script ERRORs", "$server$br$br<h2>".count($errors)." ERRORs</h2><ol><li>".implode($br."<li>", $errors)."</ol>");
		}
	}
	echo $successMesssage;
}
if ($sendEmail) {
	\REDCap::email($victrEmail, "no-reply@vanderbilt.edu", "CareerDev makeSummary script run", $successMessage.$br.$br.$echoToScreen);
}

if ($tokenName == $info['unit']['name']) {
	require_once(dirname(__FILE__)."/test6.php");
}

# close the lockFile
unlink($lockFile);
