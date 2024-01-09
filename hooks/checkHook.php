<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/surveyHook.php");

# This is the hook used for the scholars' survey. It is referenced in the hooks file.

$prefix = "check";
if ($instrument == "initial_short_survey") {
    $prefix = "checkshort";
}

?>
<script>
$(document).ready(function() {
    $('.requiredlabel').html("* required field");
    showEraseValuePrompt = 0;    // for evalLogic prompt to erase values
});
</script>

<?php
$token = Application::getSetting("token", $project_id);
$server = Application::getSetting("server", $project_id);
$prefix = REDCapManagement::getPrefixFromInstrument($instrument);
$allFields = Download::metadataFieldsByPid($project_id);
$fields = ["record_id"];
foreach ($allFields as $field) {
    if (
        preg_match("/^$prefix/", $field)
        || preg_match("/^summary_/", $field)
        || preg_match("/^vfrs_/", $field)
        || preg_match("/^identifier_/", $field)
        || preg_match("/^init_import_/", $field)
        || preg_match("/^newman_/", $field)
    ) {
        $fields[] = $field;
    }
}
$grantFields = REDCapManagement::getAllGrantFieldsFromFieldlist($allFields);
$fields = array_unique(array_merge($fields, $grantFields));
$GLOBALS['data'] = Download::fieldsForRecords($token, $server, $fields, [$record]);

$grants = new Grants($token, $server, "empty");
$grants->setRows($GLOBALS['data']);
$grants->compileGrants();

# finds the value of a field
# fields is a prioritized list of fields to look through
# returns an array for existing coeus values (instance = value)
# returns "" when data is not there
function findInCheck($fields) {
	global $data;
    return REDCapManagement::findInData($data, $fields);
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

?>
<script>
function getCSRFToken() { return '<?= Application::generateCSRFToken() ?>'; }

$(document).ready(function() {
	presetValue("<?= $prefix ?>_name_first", "<?php echo findInCheck(['identifier_first_name', 'check_name_first', 'init_import_name_first']); ?>");
	presetValue("<?= $prefix ?>_name_middle", "<?php echo findInCheck(['identifier_middle', 'check_name_middle', 'init_import_name_middle']); ?>");
	presetValue("<?= $prefix ?>_name_last", "<?php echo findInCheck(['identifier_last_name', 'check_name_last', 'init_import_name_last']); ?>");
    presetValue("<?= $prefix ?>_email", "<?php echo findInCheck(['identifier_email', 'check_email', 'init_import_email']); ?>");
    presetValue("<?= $prefix ?>_personal_email", "<?php echo findInCheck(['identifier_personal_email', 'check_personal_email', 'init_import_personal_email']); ?>");
    presetValue("<?= $prefix ?>_phone", "<?php echo findInCheck(['identifier_phone', 'check_phone', 'init_import_phone']); ?>");
	presetValue("<?= $prefix ?>_date_of_birth", "<?php echo REDCapManagement::YMD2MDY(findInCheck(['summary_dob', 'check_date_of_birth', 'init_import_date_of_birth'])); ?>");
	$('#<?= $prefix ?>_date_of_birth-tr td .ui-button').hide();
	presetValue("<?= $prefix ?>_gender", "<?php echo findInCheck(['summary_gender', 'check_gender', 'init_import_gender']); ?>");
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
	    echo "  presetValue('$prefix"."_race', '{$raceTranslate[$re]}');\n";
	    echo "  presetValue('$prefix"."_ethnicity', '{$ethnTranslate[$re]}');\n";
	}
    ?>
	presetValue("<?= $prefix ?>_citizenship", "<?php echo findInCheck(['summary_citizenship', 'check_citizenship','init_import_citizenship']); ?>");
	presetValue("<?= $prefix ?>_primary_mentor", "<?php echo findInCheck(['summary_mentor', 'check_primary_mentor', 'init_import_primary_mentor']); ?>");
	presetValue("<?= $prefix ?>_institution", "<?php echo getInstitution(findInCheck(['identifier_institution', $prefix.'_institution', 'check_institution', 'init_import_institution'])); ?>");

<?php
	if (findInCheck("vfrs_graduate_degree")) {
	    $metadata = Download::metadata($token, $server);
	    $choices = REDCapManagement::getChoices($metadata);
		#VFRS
?>
        let base;
		<?php $curr = "vfrs_degree1"; $checkI = 1; ?>
		base = '<?php echo $prefix."_degree".$checkI; ?>';
		presetValue(base, "<?php echo translateFromVFRS("vfrs_graduate_degree" , $prefix.'_degree'.$checkI, $choices); ?>");
		<?php if (findInCheck("vfrs_graduate_degree") == 6) { echo "presetValue($prefix.'_degree".$checkI."_oth', '".findInCheck("vfrs_please_specify")."');\n"; } ?>
		presetValue(base+"_month", "<?php $v = findInCheck($curr.'_year'); $nodes = preg_split("/[\/\-]/", $v); echo $nodes[0]; ?>");
		presetValue(base+"_year", "<?php $v = findInCheck($curr.'_year'); $nodes = preg_split("/[\/\-]/", $v); echo $nodes[1]; ?>");
		presetValue(base+"_institution", "<?php echo findInCheck($curr.'_institution') ?>");
		presetValue(base+"_institution", "<?php echo findInCheck($curr.'_institution') ?>");
		presetValue(base+"_another", "<?php if (findInCheck('vfrs_degree2') != '') { echo "1"; } ?>");

		<?php $curr = "vfrs_degree2"; if (findInCheck($curr)) { $checkI++; } ?>
		base = '<?php echo $prefix."_degree".$checkI; ?>';
		presetValue(base, "<?php echo translateFromVFRS($curr, $prefix.'_degree'.$checkI, $choices); ?>");
		<?php if (findInCheck($curr) == 6) { echo "presetValue($prefix.'_degree".$checkI."_oth', '".findInCheck("vfrs_please_specify2")."');\n"; } ?>
		presetValue(base+"_month", "<?php $v = findInCheck($curr.'_year'); $nodes = preg_split("/[\/\-]/", $v); echo $nodes[0]; ?>");
		presetValue(base+"_year", "<?php $v = findInCheck($curr.'_year'); $nodes = preg_split("/[\/\-]/", $v); echo $nodes[1]; ?>");
		presetValue(base+"_institution", "<?php echo findInCheck($curr.'_institution') ?>");
		presetValue(base+"_another", "<?php if (findInCheck('vfrs_degree3') != '') { echo "1"; } ?>");

		<?php $curr = "vfrs_degree3"; if (findInCheck($curr)) { $checkI++; } ?>
        base = '<?php echo $prefix."_degree".$checkI; ?>';
		presetValue(base, "<?php echo translateFromVFRS($curr, $prefix.'_degree'.$checkI, $choices); ?>");
		<?php if (findInCheck($curr) == 6) { echo "presetValue($prefix.'_degree".$checkI."_oth', '".findInCheck("vfrs_please_specify3")."');\n"; } ?>
		presetValue(base+"_month", "<?php $v = findInCheck($curr.'_year'); $nodes = preg_split("/[\/\-]/", $v); echo $nodes[0]; ?>");
		presetValue(base+"_year", "<?php $v = findInCheck($curr.'_year'); $nodes = preg_split("/[\/\-]/", $v); echo $nodes[1]; ?>");
		presetValue(base+"_institution", "<?php echo findInCheck($curr.'_institution') ?>");
		presetValue(base+"_another", "<?php if (findInCheck('vfrs_degree4') != '') { echo "1"; } ?>");

		<?php $curr = "vfrs_degree4"; if (findInCheck($curr)) { $checkI++; } ?>
		base = '<?php echo $prefix."_degree".$checkI; ?>';
		presetValue(base, "<?php echo translateFromVFRS($curr, $prefix.'_degree'.$checkI, $choices); ?>");
		<?php if (findInCheck($curr) == 6) { echo "presetValue($prefix.'_degree".$checkI."_oth', '".findInCheck("vfrs_please_specify4")."');\n"; } ?>
		presetValue(base+"_month", "<?php $v = findInCheck($curr.'_year'); $nodes = preg_split("/[\/\-]/", $v); echo $nodes[0]; ?>");
		presetValue(base+"_year", "<?php $v = findInCheck($curr.'_year'); $nodes = preg_split("/[\/\-]/", $v); echo $nodes[1]; ?>");
		presetValue(base+"_institution", "<?php echo findInCheck($curr.'_institution') ?>");
		presetValue(base+"_another", "<?php if (findInCheck('vfrs_degree5') != '') { echo "1"; } ?>");

		<?php $curr = "vfrs_degree5"; if (findInCheck($curr)) { $checkI++; } ?>
		base = '<?php echo $prefix."_degree".$checkI; ?>';
		presetValue(base, "<?php echo translateFromVFRS($curr, $prefix.'_degree'.$checkI, $choices); ?>");
		<?php if (findInCheck($curr) == 6) { echo "presetValue($prefix.'_degree".$checkI."_oth', '".findInCheck("vfrs_please_specify5")."');\n"; } ?>
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
			if ($degrees[$i] != '') {
				echo "	presetValue('$prefix"."_degree{$index}', '{$degrees[$i]}');\n";
			}
			if ($degrees[$i+1] != '') {
				echo "	presetValue('$prefix"."_degree{$index}_another', '1');\n";
			} else {
				echo "	presetValue('$prefix"."_degree{$index}_another', '');\n";
			}
		}
		echo "\n";

        $residencyYears = [];
        $residencyInstitutions = [];
        $fellowYears = [];
        $fellowInstitutions = [];
        for ($index = 1; $index <= 5; $index++) {
            $year = findInCheck('vfrs_degree'.$index.'_residency');
            $institution = findInCheck('vfrs_degree'.$index.'_institution');
            if ($year) {
                $residencyYears[] = $year;
                $residencyInstitutions[] = $institution;
            }
            $year = findInCheck('vfrs_degree'.$index.'_clinfelyear');
            if ($year) {
                $fellowYears[] = $year;
                $fellowInstitutions[] = $institution;
            }
            $year = findInCheck('vfrs_degree'.$index.'_postdocyear');
            if ($year) {
                $fellowYears[] = $year;
                $fellowInstitutions[] = $institution;
            }
        }
        if ($institutionIdx = findInCheck("mstp_residency_institution")) {
            $institutionChoices = DataDictionaryManagement::getChoicesForField($pid, "mstp_residency_institution");
            $residencyInstitutions[] = $institutionChoices[$institutionIdx];
            $residencyYears = [];
        }
		while (count($residencyYears) < 5) {
			$residencyYears[] = "";
			$residencyInstitutions[] = "";
		}
		while (count($fellowYears) < 5) {
			$fellowYears[] = "";
			$fellowInstitutions[] = "";
		}
		for ($i = 0; $i < 5; $i++) {
			$index = $i + 1;

			if ($residencyYears[$i] != "") {
				$rDate = $residencyYears[$i];
				$rInst = $residencyInstitutions[$i];
				$rNodes = preg_split("/[\/\-]/", $rDate);
				if ((count($rNodes) >= 2) && ($rNodes[0])) {
					echo "	presetValue('$prefix"."_residency{$index}_month', '{$rNodes[0]}');\n";
				}
				if ((count($rNodes) >= 2) && ($rNodes[1])) {
					echo "	presetValue('$prefix"."_residency{$index}_year', '{$rNodes[1]}');\n";
				}
                echo "	presetValue('".$prefix."_residency{$index}_institution', '$rInst');\n";
				if (($i < 4) && ($residencyYears[$i + 1] != '')) {
					echo "	presetValue('$prefix"."_residency{$index}_another', '1');\n";
				}
			}

			if ($fellowYears[$i] != "") {
				$fDate = $fellowYears[$i];
				$fInst = $fellowInstitutions[$i];
				$fNodes = preg_split("/[\/\-]/", $fDate);
				if ((count($fNodes) >= 2) && ($fNodes[0])) {
					echo "	presetValue('$prefix"."_fellow{$index}_month', '{$fNodes[0]}');\n";
				}
				if ((count($fNodes) >= 2) && ($fNodes[1])) {
					echo "	presetValue('$prefix"."_fellow{$index}_year', '{$fNodes[1]}');\n";
				}
                echo "	presetValue('".$prefix."_fellow{$index}_institution', '$fInst');\n";
				if (($i < 4) && ($fellowYears[$i + 1] != '')) {
					echo "	presetValue('$prefix"."_fellow{$index}_another', '1');\n";
				}
			}
		}
	}
?>

	presetValue('<?= $prefix ?>_primary_dept', '<?php
            $value = findInCheck('summary_primary_dept');
            if ($value) {
                echo $value;
            } else if ($mstpValue = findInCheck("mstp_current_position_dept_institution")) {
                echo "999999');\npresetValue('$prefix"."_primary_dept_oth', '$mstpValue";
            }
        ?>');
	presetValue('<?= $prefix ?>_division', '<?php echo findInCheck(array('identifier_starting_division')); ?>');
    presetValue("<?= $prefix ?>_orcid_id", "<?php echo findInCheck(array('identifier_orcid')); ?>");
    presetValue("<?= $prefix ?>_twitter", "<?php echo findInCheck(['identifier_twitter']); ?>");
	presetValue("<?= $prefix ?>_disadvantaged", "<?php echo findInCheck(array('summary_disadvantaged')); ?>");
	presetValue("<?= $prefix ?>_disability", "<?php echo findInCheck(array('summary_disability')); ?>");

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
	foreach ($grants->getGrants("all_pis") as $grant) {
		if ($i <= Grants::$MAX_GRANTS) {
			echo "	presetValue('$prefix"."_grant{$i}_start', '".REDCapManagement::YMD2MDY($grant->getVariable("start"))."');\n";
			echo "	presetValue('$prefix"."_grant{$i}_end', '".REDCapManagement::YMD2MDY($grant->getVariable("end"))."');\n";
			echo "	presetValue('$prefix"."_grant{$i}_number', '".filterSponsorNumber($grant->getBaseNumber())."');\n";
			echo "	presetValue('$prefix"."_grant{$i}_title', '".preg_replace("/'/", "\\'", $grant->getVariable("title"))."');\n";
			echo "	presetValue('$prefix"."_grant{$i}_org', '".$grant->getVariable("sponsor")."');\n";
			echo "	presetValue('$prefix"."_grant{$i}_costs', '".Grant::convertToMoney($grant->getVariable("direct_budget"))."');\n";
			echo "	presetValue('$prefix"."_grant{$i}_role', '1');\n";
			if (($i < Grants::$MAX_GRANTS) && ($i < $grants->getNumberOfGrants("compiled"))) {
				echo "	presetValue('$prefix"."_grant{$i}_another', '1');\n";
			}
		}
		$i++;
	}
	# make .*_d-tr td background
	# also .*_d\d+

    $prefix = "/^init_import/";
    $initImportFields = [];
    foreach ($allFields as $field) {
        if (preg_match($prefix, $field)) {
            $initImportFields[] = $field;
        }
    }


    $surveyPrefix = "check";
    foreach ($initImportFields as $field) {
        $value = REDCapManagement::findField($GLOBALS['data'], $record, $field);
        if ($value !== "") {
            $field = preg_replace($prefix, $surveyPrefix, $field);
            $value = preg_replace("/'/", "\\'", $value);
            echo "  presetValue('$field', '$value');\n";
        }
    }

    if (isset($_GET['resetDate'])) {
        $today = date("Y-m-d");
        echo "   $('[name=check_date]').val('$today');\n";
    }
?>


	doBranching();
	$('[name="<?= $prefix ?>name_first"]').blur();
});
</script>
