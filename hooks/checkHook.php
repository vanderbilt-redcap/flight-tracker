<?php

use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Download;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/surveyHook.php");

# This is the hook used for the scholars' survey. It is referenced in the hooks file.

?>
<script>
$(document).ready(function() {
    $('.requiredlabel').html("* required field");
    showEraseValuePrompt = 0;    // for evalLogic prompt to erase values
});
</script>

<?php
$json = \REDCap::getData($project_id, 'json', array($record));
$GLOBALS['data'] = json_decode($json, true); 

$grants = new Grants($token, $server);
$grants->setRows($GLOBALS['data']);
$grants->compileGrants();

# finds the value of a field
# fields is a prioritized list of fields to look through
# returns an array for existing coeus values (instance = value)
# returns "" when data is not there
function findInCheck($fields) {
	global $data;
	if (!is_array($fields)) {
		$fields = array($fields);
	}

	foreach ($fields as $field) {
		if (preg_match("/^coeus_/", $field)) {
			$values = array();
			foreach ($data as $row) {
				$instance = $row['redcap_repeat_instance'];
				if (isset($row[$field]) && ($row[$field] != "")) {
					$values[$instance] = preg_replace("/'/", "\\'", $row[$field]);;
				}
			}
			if (!empty($values)) {
				return $values;
			}
		} else {
			foreach ($data as $row) {
				if (($row['redcap_repeat_instrument'] == "") && isset($row[$field]) && ($row[$field] != "")) {
					return preg_replace("/'/", "\\'", $row[$field]);
				}
			}
		}
	}
	return "";
}

function translateFromVFRS($vfrsField, $destField, $choices) {
    $vfrsValue = findInCheck($vfrsField);
    if ($vfrsValue && isset($choices[$vfrsField])) {
        $vfrsLabel = $choices[$vfrsField][$vfrsValue];
        if (isset($choices[$destField])) {
            foreach ($choices[$destField] as $destValue => $destLabel) {
                if ($destLabel == $vfrsLabel) {
                    return $destValue;
                }
            }
            return "";
        }
    }
    return "";
}

# returns the value for $coeusField in the first COEUS repeatable instance that matches
# the sponsor number
# if sponsor number is unmatched, it returns ""
# sponsor number may vary by -####+ at the end of the sponsor number
function findCOEUSEntry($sponsorNoField, $coeusField) {
	global $data;
	$sponsorNo = "";
	foreach ($data as $row) {
		$instance = $row['redcap_repeat_instance'];
		if ($instance == "") {
			$sponsorNo = $row[$sponsorNoField]; 
		}
	}
	if ($sponsorNo != "") {
		foreach ($data as $row) {
			$instance = $row['redcap_repeat_instance'];
			if ($instance != "") {
				$instanceSponsorNo = $row['coeus_sponsor_award_number'];
				if (($instanceSponsorNo != "") && (preg_match("/".$sponsorNo."/", $instanceSponsorNo))) {
					if ($row[$coeusField]) {
						# return the first with a value
						return preg_replace("/'/", "\\'", $row[$coeusField]);
					}
				}
			} 
		}
	}
	return "";
}

function getInstitution($value) {
	# Scholar's: 1, Vanderbilt | 2, Meharry | 5, Other
	# VFRS: 1, Yes. I am at Vanderbilt | 2, Yes. I am at Meharry | 3, No, I am not yet at Vanderbilt or Meharry
	switch($value) {
		case 1:
			return 1;
		case 2:
			return 2;
		case 3:
			return 5;
	}
	return 1;    // default is at VUMC
}

function getDisadvantaged($value) {
	# Scholar's: 1, Yes | 2, No | 3, Prefer not to answer
	# VFRS: 1, Yes | 2, No | 3, Prefer not to answer
	return $value;
}

function getDisability($value) {
	# Scholar's: 1, Yes | 0, No
	# VFRS: 1, Yes | 2, No | 3, Prefer not to answer
	switch($value) {
		case 1:
			return 1;
		case 2:
			return 0;
		case 3:
			return "";
	}
	return "";
}
?>
<script>
$(document).ready(function() {
	presetValue("check_name_first", "<?php echo findInCheck('identifier_first_name'); ?>");
	presetValue("check_name_middle", "<?php echo findInCheck('identifier_middle'); ?>");
	presetValue("check_name_last", "<?php echo findInCheck('identifier_last_name'); ?>");
	presetValue("check_email", "<?php echo findInCheck('identifier_email'); ?>");
	presetValue("check_date_of_birth", "<?php echo REDCapManagement::YMD2MDY(findInCheck('summary_dob')); ?>");
	$('#check_date_of_birth-tr td .ui-button').hide();
	presetValue("check_gender", "<?php echo findInCheck('summary_gender'); ?>");
    <?php
	$re = findInCheck('summary_race_ethnicity');
	if ($re != '') {
	    $raceTranslate = array(
				    1 => 5,
				    2 => 4,
				    3 => 5,
				    4 => 4,
				    5 => 2,
				    6 => 7
				    );
	    $ethnTranslate = array(
				    1 => 2,
				    2 => 2,
				    3 => 1,
				    4 => 1,
				    5 => 2,
				    6 => 2,
				    );
	    echo "  presetValue('check_race', '{$raceTranslate[$re]}');\n";
	    echo "  presetValue('check_ethnicity', '{$ethnTranslate[$re]}');\n";
	}
    ?>
	presetValue("check_citizenship", "<?php echo findInCheck('summary_citizenship'); ?>");
	presetValue("check_primary_mentor", "<?php echo findInCheck('summary_mentor'); ?>");
	presetValue("check_institution", "<?php echo getInstitution(findInCheck('identifier_institution', 'check_institution')); ?>");

<?php
	if (findInCheck("vfrs_graduate_degree")) {
	    $metadata = Download::metadata($token, $server);
	    $choices = REDCapManagement::getChoices($metadata);
		#VFRS
?>
		<?php $curr = "vfrs_degree1"; $checkI = 1; ?>
		var base = '<?php echo "check_degree".$checkI; ?>';
		presetValue(base, "<?php echo translateFromVFRS("vfrs_graduate_degree" , 'check_degree'.$checkI, $choices); ?>");
		<?php if (findInCheck("vfrs_graduate_degree") == 6) { echo "presetValue('check_degree".$checkI."_oth', '".findInCheck("vfrs_please_specify")."');\n"; } ?>
		presetValue(base+"_month", "<?php $v = findInCheck($curr.'_year'); $nodes = preg_split("/[\/\-]/", $v); echo $nodes[0]; ?>");
		presetValue(base+"_year", "<?php $v = findInCheck($curr.'_year'); $nodes = preg_split("/[\/\-]/", $v); echo $nodes[1]; ?>");
		presetValue(base+"_institution", "<?php echo findInCheck($curr.'_institution') ?>");
		presetValue(base+"_institution", "<?php echo findInCheck($curr.'_institution') ?>");
		presetValue(base+"_another", "<?php if (findInCheck('vfrs_degree2') != '') { echo "1"; } ?>");

		<?php $curr = "vfrs_degree2"; if (findInCheck($curr)) { $checkI++; } ?>
		var base = '<?php echo "check_degree".$checkI; ?>';
		presetValue(base, "<?php echo translateFromVFRS($curr, 'check_degree'.$checkI, $choices); ?>");
		<?php if (findInCheck($curr) == 6) { echo "presetValue('check_degree".$checkI."_oth', '".findInCheck("vfrs_please_specify2")."');\n"; } ?>
		presetValue(base+"_month", "<?php $v = findInCheck($curr.'_year'); $nodes = preg_split("/[\/\-]/", $v); echo $nodes[0]; ?>");
		presetValue(base+"_year", "<?php $v = findInCheck($curr.'_year'); $nodes = preg_split("/[\/\-]/", $v); echo $nodes[1]; ?>");
		presetValue(base+"_institution", "<?php echo findInCheck($curr.'_institution') ?>");
		presetValue(base+"_another", "<?php if (findInCheck('vfrs_degree3') != '') { echo "1"; } ?>");

		<?php $curr = "vfrs_degree3"; if (findInCheck($curr)) { $checkI++; } ?>
		var base = '<?php echo "check_degree".$checkI; ?>';
		presetValue(base, "<?php echo translateFromVFRS($curr, 'check_degree'.$checkI, $choices); ?>");
		<?php if (findInCheck($curr) == 6) { echo "presetValue('check_degree".$checkI."_oth', '".findInCheck("vfrs_please_specify3")."');\n"; } ?>
		presetValue(base+"_month", "<?php $v = findInCheck($curr.'_year'); $nodes = preg_split("/[\/\-]/", $v); echo $nodes[0]; ?>");
		presetValue(base+"_year", "<?php $v = findInCheck($curr.'_year'); $nodes = preg_split("/[\/\-]/", $v); echo $nodes[1]; ?>");
		presetValue(base+"_institution", "<?php echo findInCheck($curr.'_institution') ?>");
		presetValue(base+"_another", "<?php if (findInCheck('vfrs_degree4') != '') { echo "1"; } ?>");

		<?php $curr = "vfrs_degree4"; if (findInCheck($curr)) { $checkI++; } ?>
		var base = '<?php echo "check_degree".$checkI; ?>';
		presetValue(base, "<?php echo translateFromVFRS($curr, 'check_degree'.$checkI, $choices); ?>");
		<?php if (findInCheck($curr) == 6) { echo "presetValue('check_degree".$checkI."_oth', '".findInCheck("vfrs_please_specify4")."');\n"; } ?>
		presetValue(base+"_month", "<?php $v = findInCheck($curr.'_year'); $nodes = preg_split("/[\/\-]/", $v); echo $nodes[0]; ?>");
		presetValue(base+"_year", "<?php $v = findInCheck($curr.'_year'); $nodes = preg_split("/[\/\-]/", $v); echo $nodes[1]; ?>");
		presetValue(base+"_institution", "<?php echo findInCheck($curr.'_institution') ?>");
		presetValue(base+"_another", "<?php if (findInCheck('vfrs_degree5') != '') { echo "1"; } ?>");

		<?php $curr = "vfrs_degree5"; if (findInCheck($curr)) { $checkI++; } ?>
		var base = '<?php echo "check_degree".$checkI; ?>';
		presetValue(base, "<?php echo translateFromVFRS($curr, 'check_degree'.$checkI, $choices); ?>");
		<?php if (findInCheck($curr) == 6) { echo "presetValue('check_degree".$checkI."_oth', '".findInCheck("vfrs_please_specify5")."');\n"; } ?>
		presetValue(base+"_month", "<?php $v = findInCheck($curr.'_year'); $nodes = preg_split("/[\/\-]/", $v); echo $nodes[0]; ?>");
		presetValue(base+"_year", "<?php $v = findInCheck($curr.'_year'); $nodes = preg_split("/[\/\-]/", $v); echo $nodes[1]; ?>");
		presetValue(base+"_institution", "<?php echo findInCheck($curr.'_institution') ?>");
<?php
	} else {
		# Newman

		function findDegreeIndexList($indices) {
			$list = array();
			foreach ($indices as $ind) {
				if ($ind == 7) {
					if (!in_array(1, $list)) { $list[] = 1; } 
					if (!in_array(4, $list)) { $list[] = 4; }
				} else if ($ind == 8) {
					if (!in_array(1, $list)) { $list[] = 1; } 
					if (!in_array(5, $list)) { $list[] = 5; }
				} else if ($ind == 9) {
					if (!in_array(1, $list)) { $list[] = 1; } 
					if (!in_array(2, $list)) { $list[] = 2; }
				} else if ($ind == 10) {
					if (!in_array(1, $list)) { $list[] = 1; } 
					if (!in_array(2, $list)) { $list[] = 2; }
					if (!in_array(5, $list)) { $list[] = 5; }
				} else if ($ind == 12) {
					if (!in_array(1, $list)) { $list[] = 1; } 
					if (!in_array(11, $list)) { $list[] = 11; }
				} else if ($ind == 14) {
					if (!in_array(1, $list)) { $list[] = 1; } 
					if (!in_array(13, $list)) { $list[] = 13; }
				} else if ($ind == 16) {
					if (!in_array(3, $list)) { $list[] = 3; }
					if (!in_array(5, $list)) { $list[] = 5; }
				} else if ($ind != "") {
					if (!in_array($ind, $list)) { $list[] = $ind; }
				}
			}
			while (count($list) < 5) {
				$list[] = "";
			}
			return $list;
		}

		$degreeIndices = array();
		if (findInCheck("newman_data_degree1") != "") {
			$degreeIndices[] = findInCheck("newman_data_degree1");
			$degreeIndices[] = findInCheck("newman_data_degree2");
			$degreeIndices[] = findInCheck("newman_data_degree3");
		} else if (findInCheck("newman_sheet2_degree1") != "") {
			$degreeIndices[] = findInCheck("newman_sheet2_degree1");
			$degreeIndices[] = findInCheck("newman_sheet2_degree2");
			$degreeIndices[] = findInCheck("newman_sheet2_degree3");
		} else {
			$degreeIndices[] = findInCheck("newman_demographics_degrees");
		}
		$degrees = findDegreeIndexList($degreeIndices);

		for ($i = 0; $i < 5; $i++) {
			$index = $i + 1;
			if ($degree[$i] != '') {
				echo "	presetValue('check_degree{$index}', '{$degrees[$i]}');\n";
			}
			if ($degree[$i+1] != '') {
				echo "	presetValue('check_degree{$index}_another', '1');\n";
			} else {
				echo "	presetValue('check_degree{$index}_another', '');\n";
			}
		}
		echo "\n";

		$residencyYears = array();
		$fellowYears = array();
		for ($i = 0; $i < 5; $i++) {
			$index = $i + 1;
			$year = findInCheck('vfrs_degree{$index}_residency');
			if ($year) { $residencyYears[] = $year; }
			$year = findInCheck('vfrs_degree{$index}_clinfelyear');
			if ($year) { $fellowYears[] = $year; }
			$year = findInCheck('vfrs_degree{$index}_postdocyear');
			if ($year) { $fellowYears[] = $year; }
		}
		while (count($residencyYears) < 5) {
			$residencyYears[] = "";
		}
		while (count($fellowYears) < 5) {
			$fellowYears[] = "";
		}
		for ($i = 0; $i < 5; $i++) {
			$index = $i + 1;

			if ($residencyYears[$i] != "") {
				$rDate = $residencyYears[$i];
				$rNodes = preg_split("/[\/\-]/", $rDate);
				if ((count($rNodes) >= 2) && ($rNodes[0])) {
					echo "	presetValue('check_residency{$index}_month', '{$rNodes[0]}');\n";
				}
				if ((count($rNodes) >= 2) && ($rNodes[1])) {
					echo "	presetValue('check_residency{$index}_year', '{$rNodes[1]}');\n";
				}
				if (($i < 4) && ($residencyYears[$i + 1] != '')) {
					echo "	presetValue('check_residency{$index}_another', '1');\n";
				}
			}

			if ($fellowYears[$i] != "") {
				$fDate = $fellowYears[$i];
				$fNodes = preg_split("/[\/\-]/", $fDate);
				if ((count($fNodes) >= 2) && ($fNodes[0])) {
					echo "	presetValue('check_fellow{$index}_month', '{$fNodes[0]}');\n";
				}
				if ((count($fNodes) >= 2) && ($fNodes[1])) {
					echo "	presetValue('check_fellow{$index}_year', '{$fNodes[1]}');\n";
				}
				if (($i < 4) && ($fellowYears[$i + 1] != '')) {
					echo "	presetValue('check_fellow{$index}_another', '1');\n";
				}
			}
		}
	}
?>

	presetValue('check_primary_dept', '<?php echo findInCheck('summary_primary_dept'); ?>');
	presetValue('check_division', '<?php echo findInCheck(array('identifier_starting_division')); ?>');
	presetValue("check_orcid_id", "<?php echo findInCheck(array('identifier_orcid')); ?>");
	presetValue("check_disadvantaged", "<?php echo findInCheck(array('summary_disadvantaged')); ?>");
	presetValue("check_disability", "<?php echo findInCheck(array('summary_disability')); ?>");

<?php
# Get rid of my extra verbiage
function filterSponsorNumber($name) {
	$name = preg_replace("/^Internal K - Rec. \d+ /", "", $name);
	$name = preg_replace("/^Individual K - Rec. \d+ /", "", $name);
	$name = preg_replace("/^Unknown R01 - Rec. \d+$/", "R01", $name);
	$name = preg_replace("/- Rec. \d+$/", "", $name);
	return $name;
}

	$i = 1;
	foreach ($grants->getGrants("compiled") as $grant) {
		if ($i <= Grants::$MAX_GRANTS) {
			echo "	presetValue('check_grant{$i}_start', '".REDCapManagement::YMD2MDY($grant->getVariable("start"))."');\n";
			echo "	presetValue('check_grant{$i}_end', '".REDCapManagement::YMD2MDY($grant->getVariable("end"))."');\n";
			echo "	presetValue('check_grant{$i}_number', '".filterSponsorNumber($grant->getBaseNumber())."');\n";
			echo "	presetValue('check_grant{$i}_title', '".preg_replace("/'/", "\\'", $grant->getVariable("title"))."');\n";
			echo "	presetValue('check_grant{$i}_org', '".$grant->getVariable("sponsor")."');\n";
			echo "	presetValue('check_grant{$i}_costs', '".Grant::convertToMoney($grant->getVariable("direct_budget"))."');\n";
			echo "	presetValue('check_grant{$i}_role', '1');\n";
			if (($i < Grants::$MAX_GRANTS) && ($i < $grants->getNumberOfGrants("compiled"))) {
				echo "	presetValue('check_grant{$i}_another', '1');\n";
			}
		}
		$i++;
	}
	# make .*_d-tr td background
	# also .*_d\d+
?>
	doBranching();
	$('[name="check_name_first"]').blur();
});
</script>
