<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\CitationCollection;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Scholar;

# This is the hook used for the scholars' followup survey. It is referenced in the hooks file.
require_once(dirname(__FILE__)."/../small_base.php");

?>
<script>
$(document).ready(function() {
	$('.requiredlabel').html("* required field");
	showEraseValuePrompt = 0;    // for evalLogic prompt to erase values
});
</script>

<?php

require_once(dirname(__FILE__)."/../classes/Grants.php");
require_once(dirname(__FILE__)."/../classes/Grant.php");
require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/Citation.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Scholar.php");

$GLOBALS['data'] = Download::record($token, $server, array($record));
$metadata = Download::metadata($token, $server);
$choices = Scholar::getChoices($metadata);

$grants = new Grants($token, $server, $pid, $metadata);
$grants->setRows($GLOBALS['data']);
$grants->compileGrants();

$pubs = new Publications($token, $server, $pid, $metadata);
$pubs->setRows($GLOBALS['data']);

# from YMD
function makeMMDDYYYY($d) {
	if ($d == "") {
		return $d;
	}
	$nodes = preg_split("/[\-\/]/", $d);
	if (count($nodes) == 2) {
		# mm/yyyy
		return $nodes[0]."-01-".$nodes[1];
	} else if (count($nodes) == 3) {
		return $nodes[1]."-".$nodes[2]."-".$nodes[0]; 
	}
	return $d;
}

# finds the value of a field
# fields is a prioritized list of fields to look through
# returns an array for existing coeus values (instance = value)
# returns "" when data is not there
function find($fields) {
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
					$values[$instance] = preg_replace("/'/", "\\'", $row[$field]);
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

# YYYY-MM-DD to MM-DD-YYYY for better user experience
function YMD2MDY($d) {
	$nodes = preg_split("/[\/\-]/", $d);
	$toReturn = $nodes[1]."-".$nodes[2]."-".$nodes[0];
	if ($toReturn == "--") {
		return "";
	}
	return $toReturn;
}
?>
<script>
$(document).ready(function() {
	function presetValue(name, value) {
		if ($('[name='+name+']').is("textarea")) {
			$('[name='+name+']').html(value);
		} else if (($('[name="'+name+'"]').val() == "") && (value != "")) {
			$('[name="'+name+'"]').val(value);
			$('[name="'+name+'___radio"][value="'+value+'"]').attr('checked', true);
		}
	}

	presetValue("followup_name_first", "<?php echo find('identifier_first_name', 'check_name_first'); ?>");
	presetValue("followup_name_middle", "<?php echo find('identifier_middle'); ?>");
	presetValue("followup_name_last", "<?php echo find('identifier_last_name'); ?>");
	presetValue("followup_email", "<?php echo find('identifier_email'); ?>");
	presetValue("followup_primary_mentor", "<?php echo find('summary_mentor'); ?>");

	presetValue('followup_primary_dept', '<?php echo find('summary_primary_dept'); ?>');
	presetValue('followup_academic_rank', '<?php
        $vfrs = find('vfrs_current_appointment');
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
            echo find('summary_current_rank');
        }
    ?>');
	presetValue('followup_division', '<?php echo find(array('check_division', 'vfrs_division', 'newman_data_division', 'newman_sheet2_division')); ?>');

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
		if (($j <= MAX_GRANTS) && (($beginDate && strtotime($beginDate) >= $priorTs) || ($endDate && strtotime($endDate) >= $priorTs))) {
			if ($j == 1) {
				echo "	presetValue('followup_grant0_another', '1');\n";
			}
			echo "	presetValue('followup_grant{$j}_start', '".makeMMDDYYYY($grant->getVariable("start"))."');\n";
			if ($endDate) {
				echo "	presetValue('followup_grant{$j}_end', '".makeMMDDYYYY($grant->getVariable("end"))."');\n";
			}
			echo "	presetValue('followup_grant{$j}_number', '".filterSponsorNumber($grant->getBaseNumber())."');\n";
			echo "	presetValue('followup_grant{$j}_title', '".preg_replace("/'/", "\\'", $grant->getVariable("title"))."');\n";
			echo "	presetValue('followup_grant{$j}_org', '".$grant->getVariable("sponsor")."');\n";
			echo "	presetValue('followup_grant{$j}_costs', '".Grant::convertToMoney($grant->getVariable("direct_budget"))."');\n";
			echo "	presetValue('followup_grant{$j}_role', '1');\n";
			if (($j < MAX_GRANTS) && ($j < $grants->getNumberOfGrants("compiled"))) {
				echo "	presetValue('followup_grant{$j}_another', '1');\n";
			}
			$j++;
		}
	}
	# make .*_d-tr td background
	# also .*_d\d+
	// publications
	$pubsWithinLastYear = $pubs->getPubsInRange($priorTs, FALSE, "All");
?>
	presetValue('followup_prior_pubs', <?= json_encode(implode("\n\n", $pubsWithinLastYear)) ?>);
	presetValue('followup_institution', '1');
	presetValue('followup_academic_rank_dt', '<?php echo makeMMDDYYYY(find('vfrs_current_appointment')); ?>');
	presetValue('followup_tenure_status', <?php echo getTenureStatus(find('vfrs_tenure')); ?>);

	doBranching();
	$('[name="followup_name_first"]').blur();
});
</script>
