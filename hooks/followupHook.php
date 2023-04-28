<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\CitationCollection;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

# This is the hook used for the scholars' followup survey. It is referenced in the hooks file.
require_once(dirname(__FILE__)."/../small_base.php");

?>
<script>
$(document).ready(function() {
	$('.requiredlabel').html("* required field");
	showEraseValuePrompt = 0;    // for evalLogic prompt to erase values
});

function getCSRFToken() { return '<?= Application::generateCSRFToken() ?>'; }
</script>

<?php

require_once(dirname(__FILE__)."/surveyHook.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$GLOBALS['data'] = Download::records($token, $server, array($record));
$metadata = Download::metadata($token, $server);
$choices = Scholar::getChoices($metadata);

$grants = new Grants($token, $server, $metadata);
$grants->setRows($GLOBALS['data']);
$grants->compileGrants();

# finds the value of a field
# fields is a prioritized list of fields to look through
# returns an array for existing coeus values (instance = value)
# returns "" when data is not there
function findInFollowup($fields) {
	global $data;
    return REDCapManagement::findInData($data, $fields);
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

# returns the citizenship autofill
function getCitizenship($value) {
	$value = strtolower($value);
	$usVariants = array("us", "usa", "u.s.", "u.s.a.", "united states", "america");
	if (in_array($value, $usVariants)) {
		return 1;
	}
	return "";
}
?>
<script>
$(document).ready(function() {
    presetValue("followup_ecommons_id", "<?php echo findInFollowup(['check_ecommons_id', 'init_import_ecommons_id']); ?>");
    presetValue("followup_twitter", "<?php echo findInFollowup(['identifier_twitter']); ?>");
    presetValue("followup_orcid_id", "<?php echo findInFollowup(['identifier_orcid', 'followup_orcid_id', 'check_orcid_id', 'init_import_orcid_id']); ?>");
 	presetValue("followup_disability", "<?php echo findInFollowup(['summary_disability']); ?>");
 	presetValue("followup_disadvantaged", "<?php echo findInFollowup(['summary_disadvantaged']); ?>");
 	presetValue("followup_name_first", "<?php echo findInFollowup(['identifier_first_name', 'followup_name_first', 'check_name_first', 'init_import_name_first']); ?>");
 	presetValue("followup_name_middle", "<?php echo findInFollowup(['newman_data_middle_name', 'followup_name_middle', 'check_name_middle', 'init_import_name_middle']); ?>");
    presetValue("followup_name_last", "<?php echo findInFollowup(['identifier_last_name', 'followup_name_last', 'check_name_last', 'init_import_name_last']); ?>");
    presetValue("followup_name_maiden", "<?php echo findInFollowup(['followup_name_maiden', 'check_name_maiden', 'init_import_name_maiden']); ?>")
    presetValue("followup_name_maiden_enter", "<?php echo findInFollowup(['followup_name_maiden_enter', 'check_name_maiden_enter', 'init_import_name_maiden_enter']); ?>");
    presetValue("followup_name_preferred", "<?php echo findInFollowup(['followup_name_preferred', 'check_name_preferred', 'init_import_name_preferred']); ?>")
    presetValue("followup_name_preferred_enter", "<?php echo findInFollowup(['followup_name_preferred_enter', 'check_name_preferred_enter', 'init_import_name_preferred_enter']); ?>");
 	presetValue("followup_email", "<?php echo findInFollowup(['identifier_email', 'followup_email', 'check_email', 'init_import_email']); ?>");
 	presetValue("followup_primary_mentor", "<?php echo findInFollowup(['followup_primary_mentor', 'check_primary_mentor', 'init_import_primary_mentor', 'summary_mentor']); ?>");

	presetValue('followup_primary_dept', '<?php echo findInFollowup(['followup_primary_dept', 'summary_primary_dept', 'check_primary_dept', 'init_import_primary_dept', "mstp_current_position_dept_institution"]); ?>');
	presetValue('followup_academic_rank', '<?php
	$vfrs = findInFollowup('vfrs_current_appointment');
	if ($vfrs != '') {
		$translate = array(
					1 => 3,
					2 => 4,
					3 => 3,
					4 => 5,
					5 => 8,
					);
		echo $translate[$vfrs];
	} else {
		echo findInFollowup(array('followup_academic_rank', 'check_academic_rank', 'init_import_academic_rank'));
	}
    ?>');
    presetValue('followup_division', '<?php echo findInFollowup(['followup_division', 'check_division', 'init_import_division', 'identifier_starting_division']); ?>');
    presetValue('followup_alumni_assoc1', '<?php echo findInFollowup(['followup_alumni_assoc1', 'check_alumni_assoc1', 'init_import_alumni_assoc1']); ?>');
    presetValue('followup_alumni_assoc2', '<?php echo findInFollowup(['followup_alumni_assoc2', 'check_alumni_assoc2', 'init_import_alumni_assoc2']); ?>');
    presetValue('followup_alumni_assoc3', '<?php echo findInFollowup(['followup_alumni_assoc3', 'check_alumni_assoc3', 'init_import_alumni_assoc3']); ?>');
    presetValue('followup_alumni_assoc4', '<?php echo findInFollowup(['followup_alumni_assoc4', 'check_alumni_assoc4', 'init_import_alumni_assoc4']); ?>');
    presetValue('followup_alumni_assoc5', '<?php echo findInFollowup(['followup_alumni_assoc5', 'check_alumni_assoc5', 'init_import_alumni_assoc5']); ?>');

<?php
# Get rid of my extra verbiage
function filterSponsorNumber($name) {
	$name = preg_replace("/^Internal K - Rec. \d+ /", "", $name);
	$name = preg_replace("/^Individual K - Rec. \d+ /", "", $name);
	$name = preg_replace("/^Unknown R01 - Rec. \d+$/", "R01", $name);
	$name = preg_replace("/- Rec. \d+$/", "", $name);
	return $name;
}

function getTenureStatus($value) {
	# VFRS: 1, Non-tenure track | 2, Tenure track
	# Followup: 1, Not Tenure-track | 2, Tenure-track | 3, Tenured
	switch($value) {
		case 1:
			return 1;
		case 2:
			return 2;
	}
	return "";
}

	$priorDate = (date("Y") - 1).date("-m-d");
	$priorTs = strtotime($priorDate);
	$j = 1;
	foreach ($grants->getGrants("compiled") as $grant) {
		$beginDate = $grant->getVariable("start");
		$endDate = $grant->getVariable("end");
		if (($j <= Grants::$MAX_GRANTS) && (($beginDate && strtotime($beginDate) >= $priorTs) || ($endDate && strtotime($endDate) >= $priorTs))) {
			if ($j == 1) {
				echo "	presetValue('followup_grant0_another', '1');\n";
			}
			echo "	presetValue('followup_grant{$j}_start', '".REDCapManagement::YMD2MDY($grant->getVariable("start"))."');\n";
			if ($endDate) {
				echo "	presetValue('followup_grant{$j}_end', '".REDCapManagement::YMD2MDY($grant->getVariable("end"))."');\n";
			}
			echo "	presetValue('followup_grant{$j}_number', '".filterSponsorNumber($grant->getBaseNumber())."');\n";
			echo "	presetValue('followup_grant{$j}_title', '".preg_replace("/'/", "\\'", $grant->getVariable("title"))."');\n";
			echo "	presetValue('followup_grant{$j}_org', '".$grant->getVariable("sponsor")."');\n";
			echo "	presetValue('followup_grant{$j}_costs', '".Grant::convertToMoney($grant->getVariable("direct_budget"))."');\n";
			echo "	presetValue('followup_grant{$j}_role', '1');\n";
			if (($j < Grants::$MAX_GRANTS) && ($j < $grants->getNumberOfGrants("compiled"))) {
				echo "	presetValue('followup_grant{$j}_another', '1');\n";
			}
			$j++;
		}
	}
	# make .*_d-tr td background
	# also .*_d\d+
?>
	presetValue('followup_institution', '<?php
            $value = findInFollowup(['check_institution', 'init_import_institution']);
            if (($value === "") && ($mstpValue = findInFollowup('mstp_current_position_dept_institution'))) {
                $value = '5';
                echo "$value');\npresetValue('followup_institution_oth', '$mstpValue";
            } else if ($value === "") {
                $value = '1';
                echo $value;
            } else if ($value == 5) {
                echo "$value')\npresetValue('followup_institution_oth', '".(findInFollowup(['check_institution_oth', 'init_import_institution_oth']) ?: '1');
            } else {
                echo $value;
            }
        ?>');
	<?php
    ?>
    presetValue('followup_job_title', '<?php echo (findInFollowup(['check_job_title', 'init_import_job_title', 'mstp_career_type_current_position_title']) ?: '1'); ?>');
    presetValue('followup_job_category', '<?php echo findInFollowup(['check_job_category', 'init_import_job_category']) ?: '1' ?>');
    presetValue('followup_academic_rank', '<?php echo findInFollowup(['check_academic_rank', 'init_import_academic_rank']); ?>');
    presetValue('followup_academic_rank_dt', '<?php echo REDCapManagement::YMD2MDY(findInFollowup(['vfrs_current_appointment', 'check_academic_rank_dt', 'init_import_academic_rank_dt'])); ?>');
    presetValue('followup_left_institution', '<?php echo REDCapManagement::YMD2MDY(findInFollowup(['check_left_institution', 'init_import_left_institution'])); ?>');
	presetValue('followup_tenure_status', '<?php
        $status = getTenureStatus(findInFollowup('vfrs_tenure'));
        if ($status) {
            echo $status;
        } else {
            echo findInFollowup(['check_tenure_status', 'init_import_tenure_status']);
        }
        ?>');

	doBranching();
	$('[name="followup_name_first"]').blur();
});
</script>
