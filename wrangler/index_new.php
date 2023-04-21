<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\DateManagement;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\ExcludeList;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Publications;

# provides a means to reassign categories, start/end dates, etc. for outside grants
# to be run on web

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/basePHP.php");

$updateLink = Application::link("wrangler/update.php");
$daysForNew = 60;
if (isset($_GET['new']) && is_numeric($_GET['new'])) {
	$daysForNew = Sanitizer::sanitizeInteger($_GET['new']);
}

$cookieName = ADVANCED_MODE."_$pid";
if (isset($_POST['hideBubble']) && $_POST['hideBubble']) {
    $oneWeek = 7 * 24 * 3600;
    if (REDCapManagement::versionGreaterThanOrEqualTo(phpversion(), "7.3.0")) {
        setcookie($cookieName, "1", ["expires" => time() + $oneWeek]);
    } else {
        setcookie($cookieName, "1", time() + $oneWeek);
    }
    echo "Done.";
    exit();
}

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/baseSelect.php");

?>
    <script src="<?= Application::link("js/vis.test.js") ?>"></script>
    <link href="<?= Application::link("css/vis.test.css") ?>" rel="stylesheet" type="text/css" />
    <link href='<?= Application::link("css/career_dev.css") ?>' rel="stylesheet" type type='text/css' />
    <link href="<?= Application::link("css/jquery.sweet-modal.min.css") ?>" rel="stylesheet" type="text/css" />
    <script src="<?= Application::link("js/jquery.sweet-modal.min.js") ?>"></script>
    <script src="<?= Application::link("js/jquery.inputmask.min.js") ?>"></script>
    <script src="<?= Application::link("js/jquery.equalheights.min.js") ?>"></script>
    <script src="<?= Application::link("js/Sortable.js")."&v=1" ?>"></script>
    <link rel="stylesheet" type="text/css" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css" integrity="sha384-Gn5384xqQ1aoWXA+058RXPxPg6fy4IWvTNh0E263XmFcJlSAwiGgFAW/dAiS6JXm" crossorigin="anonymous" />
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js" integrity="sha384-JZR6Spejh4U02d8jOt6vLEHfe/JQGiRRSQQxSfFWpi1MquVdAyjUar5+76PVCmYl" crossorigin="anonymous"></script>
<?php

$records = Download::recordIds($token, $server);
$record = Sanitizer::getSanitizedRecord($_GET['record'] ?? 1, $records);

$myFields = [
    "record_id",
    "summary_last_calculated",
    "summary_calculate_order",
    "summary_calculate_list_of_awards",
    "summary_calculate_to_import",
    "summary_calculate_flagged_grants",
    "identifier_last_name",
    "identifier_first_name",
];

$redcapData = Download::fieldsForRecords($token, $server, $myFields, [$record]);
$flaggedGrantsJSON = REDCapManagement::findField($redcapData, $record, "summary_calculate_flagged_grants") ?: "[]";
$flaggedGrants = json_decode($flaggedGrantsJSON, TRUE);
$excludeList = new ExcludeList("Grants", $pid);

function getTransformArray() {
	$ary = [];
	$ary['redcap_type'] = "Type / Bin";
	$ary['person_name'] = "Assigned Person";
	$ary['start_date'] = "Budget Start Date";
	$ary['end_date'] = "Budget End Date";
	$ary['project_start_date'] = "Project Start Date";
	$ary['project_end_date'] = "Project End Date";
	$ary['budget'] = "Budget (Total)";
	$ary['direct_budget'] = "Budget (Direct)";
	$ary['title'] = "Title";
	$ary['sponsor'] = "Sponsor";
	$ary['sponsor_type'] = "Sponsor Type";
	$ary['direct_sponsor_type'] = "Direct Sponsor Type";
	$ary['prime_sponsor_type'] = "Prime Sponsor Type";
	$ary['source'] = "Source";
	$ary['sponsor_award_no'] = "Sponsor Award No.";
	$ary['base_award_no'] = "Base Award No.";
	$ary['percent_effort'] = "Percent Effort";
	$ary['nih_mechanism'] = "NIH Mechanism";
	$ary['pi_flag'] = "PI Flag";
	$ary['link'] = "Link";
	$ary['last_update'] = "Last Update";
	$ary['fAndA'] = "F and A";
	$ary['finance_type'] = "Finance Type";
	$ary['original_award_number'] = "Original Award Number";
	$ary['funding_source'] = "Funding Source";
	$ary['industry'] = "Industry";

	$ary['application_type'] = "Application Type";
	$ary['activity_code'] = "Activity Code";
	$ary['activity_type'] = "Activity Type";
	$ary['funding_institute'] = "Funding Institute";
	$ary['institute_code'] = "Institute Code";
	$ary['serial_number'] = "Serial Number";
	$ary['support_year'] = "Support Year";
	$ary['other_suffixes'] = "Other Suffixes";

	return $ary;
}

function getAwardNumber($row) {
	$awardFields = array( "coeus_sponsor_award_number", "custom_number", "reporter_projectnumber",);
	foreach ($row as $field => $value) {
		if ($value && in_array($field, $awardFields)) {
			return getBaseAwardNumber($value);
		}
	}
}

# input all REDCap Data
# returns next record with new data
# else returns ""
function getNextRecordWithNewData($currentRecord, $includeCurrentRecord) {
	global $daysForNew, $token, $server;

	$calculateFields = array(
				"record_id",
				"summary_calculate_order",
				"summary_calculate_list_of_awards",
				"summary_calculate_to_import",
				);
	$grantAgeFields = getGrantAgeFields();
    $metadataFields = Download::metadataFields($token, $server);

	$myFields = $calculateFields;
    $myFields[] = "identifier_first_name";
    $myFields[] = "identifier_last_name";
    foreach ($metadataFields as $field) {
        if (preg_match("/_last_update$/", $field)) {
            $myFields[] = $field;
        }
    }
	$myFields = array_merge($myFields, $grantAgeFields);

	$records = Download::recordIds($token, $server);
	$records = Application::filterOutCopiedRecords($records);
	$pullSize = 10;

	$record = $currentRecord;
	if (!$record) {
		$record = $records[0];
	}
	$i = 0;
	foreach ($records as $currRecord) {
		if ($currRecord == $record) {
			break;
		}
		$i++;
	}
	while ($i < count($records)) {
		$pullRecords = array();
		for ($j = $i; ($j < $i + $pullSize) && ($j < count($records)); $j++) {
			$pullRecords[] = $records[$j];
		}

		$data = Download::fieldsForRecords($token, $server, $myFields, $pullRecords);
		$normativeRow = array();
		foreach ($data as $row) {
			if ($includeCurrentRecord) {
				$isEligibleRecord = ($record <= $row['record_id']);
			} else {
				$isEligibleRecord = ($record < $row['record_id']);
			}

			if ($isEligibleRecord && ($row['redcap_repeat_instrument'] === "")) {
				$normativeRow = $row;
			} else if ($isEligibleRecord) {
				$minAgeUpdate = getMinAgeOfUpdate($row);
				$minAgeGrant = getMinAgeOfGrants($row, $grantAgeFields);
				if ((($minAgeUpdate <= $daysForNew) && ($minAgeGrant <= $daysForNew))
					&& ($normativeRow['record_id'] == $row['record_id'])) {
					$listOfAwards = json_decode($normativeRow['summary_calculate_list_of_awards'], true);
					foreach ($listOfAwards as $idx => $specs) {
						$specsMinAge = getMinAgeOfUpdate($specs);
						$grantAge = getAgeOfGrant($specs);    // ensure that not in the distant past
						if (($specsMinAge <= $daysForNew) && ($grantAge <= $daysForNew) && (findNumberOfSimilarAwards($specs['base_award_no'], $idx, $listOfAwards) == 0)) {
							// echo "Record ".$row['record_id'].": ".$specs['base_award_no']." with age of $specsMinAge (".$specs['last_update'].") has ".findNumberOfSimilarAwards($specs['base_award_no'], $idx, $listOfAwards)." similar awards<br>\n";
							return $row['record_id'];
						}
					}
				}
			}
		}
		$i += count($pullRecords);
	}
	return "";
}

# gets the minimum age of grant in the current award
# returns number of days since the most recent update
function getAgeOfGrant($award) {
	if ($award['end_date']) {
		$ts = strtotime($award['end_date']);
		if ($ts) {
			return floor((time() - $ts) / (3600 * 24)) + 1;
		}
	}
	return 1000000;
}

# gets the minimum age of all grants in the current row
# returns number of days since the most recent update
function getMinAgeOfGrants($row, $grantAgeFields = array()) {
	if (empty($grantAgeFields)) {
		$grantAgeFields = getGrantAgeFields();
	}
	$minDays = 1000000;
	foreach ($row as $field => $value) {
		if (in_array($field, $grantAgeFields) && $value) {
			$ts = strtotime($value);
			$daysOld = floor((time() - $ts) / (24 * 3600)) + 1;
			if ($daysOld < $minDays) {
				$minDays = $daysOld;
			}
		}
	}
	return $minDays;
}

# gets the minimum age of all last updates in the current row
# returns number of days since the most recent update
function getMinAgeOfUpdate($row) {
	$minDays = 1000000;
	foreach ($row as $field => $value) {
		if (preg_match("/last_update$/", $field) && $value) {
			$ts = strtotime($value);
			$daysOld = floor((time() - $ts) / (24 * 3600)) + 1;
			if ($daysOld < $minDays) {
				$minDays = $daysOld;
			}
		}
	}
	return $minDays;
}

function getGrantAgeFields() {
	return [
        "coeus_budget_end_date",
        "reporter_budgetenddate",
        "exporter_budget_end",
        'nsf_expdate',
        'ies_end',
    ];
}

function isOkToShow($ary, $idxOfCurrentAward, $listOfAwards) {
	global $daysForNew;

	if (isset($_GET['new'])) {
		if (!isset($ary['last_update'])) {
			return false;
		}
		if ($ary['last_update'] === "") {
			return false;
		}
		if (!isset($ary['end_date'])) {
			return false;
		}
		if ($ary['end_date'] === "") {
			return false;
		}

		$dLast = $ary['last_update'];
		$dEnd = $ary['end_date'];
		$newDaysLast = floor((time() - strtotime($dLast)) / (24 * 3600));
		$newDaysEnd = floor((time() - strtotime($dEnd)) / (24 * 3600));
		// echo "Comparing $newDaysLast and $newDaysEnd for ".$ary['sponsor_award_no']." with ".findNumberOfSimilarAwards($ary['base_award_no'], $idxOfCurrentAward, $listOfAwards)." matches<br>\n";
		if (($newDaysLast <= $daysForNew) && ($newDaysEnd <= $daysForNew) && (findNumberOfSimilarAwards($ary['base_award_no'], $idxOfCurrentAward, $listOfAwards) == 0)) {
			return true;
		} else {
			return false;
		}
	}
	return true;
}

function generateAwardIndex($awardno, $sponsor) {
	if ($awardno == '000') {
		return $sponsor."____".$awardno;
	}
	return $awardno;
}

function transformAward($ary, $i, $pid, $flaggedGrants = []) {
    $selectName = "redcap_type_".$i;
    $tbuttons = makeStatusButtons($i, $ary);
    $elems = [];
    $startName = preg_replace("/redcap_type/", "start_date", $selectName);
    $endName = preg_replace("/redcap_type/", "end_date", $selectName);
    $projectStartName = preg_replace("/redcap_type/", "project_start_date", $selectName);
    $projectEndName = preg_replace("/redcap_type/", "project_end_date", $selectName);
	$awardTypes = Grant::getAwardTypes();
    $source = $ary['source'] ?? "";
    $awardNo = $ary['sponsor_award_no'] ?? "";
    $startdate = "<input type='text' class='dates' onkeydown='enactChange($i, this, \"start_date\", \"$source\", \"$awardNo\");' id='$startName' value='' />";
    $enddate = "<input type='text' class='dates' onkeydown='enactChange($i, this, \"end_date\", \"$source\", \"$awardNo\");' id='$endName' value='' />";
    $project_start_date = "<input type='text' class='dates' onkeydown='enactChange($i, this, \"project_start_date\", \"$source\", \"$awardNo\");' id='$projectStartName' value='' />";
    $project_end_date = "<input type='text' class='dates' onkeydown='enactChange($i, this, \"project_end_date\", \"$source\", \"$awardNo\");' id='$projectEndName' value='' />";
	$tablenum = 0;
	$doclass = "class_empty";
    $class_baseaward = "";

    $d_ck_original_award_number = "";
    $d_ck_base_award_no = "";
    $d_direct_budget = BLANK_VALUE;
    $d_budget_total = BLANK_VALUE;
    $d_direct_sponsor_type = "";
    $d_direct_sponsor_name = "";
    $d_prime_sponsor_type = "";
    $d_prime_sponsor_name = "";
    $d_sponsor_award_no = "";
    $piName = "";
    $telem = "";
    $flagsOn = Grants::areFlagsOn($pid);
    if ($flagsOn) {
        $fontAwesomeFlag = "<i class='far fa-flag'></i>";
    } else {
        $fontAwesomeFlag = "";
    }

    foreach ($ary as $key => $value) {
		if ($key == "redcap_type") {
            list($doclass, $ftype) = getClassAndFType($value);
            if ($selectName) {
                $telem = "<select id='$selectName' onchange='toggleChange(\"$value\", $(this), $i, $(this).closest(\"table\").attr(\"class\"), \"$source\", \"$awardNo\");' style='font-size: 13px;padding: 1px;'>";
                foreach ($awardTypes as $type => $num) {
                    if ($value == $type) {
                        $telem .= "<option value='$type' selected>$type</option>";
                    } else {
                        $telem .= "<option value='$type'>$type</option>";
                    }

                }
                $telem .= "</select>";
            } else {
                $telem = $value;
            }
		} else if ($key == "start_date") {
		 	if($value == ''){
		 		$startdate = "<input type='text' class='dates' onkeydown='enactChange($i, this, \"start_date\", \"$source\", \"$awardNo\");' id='$startName' value='' />";
		 	} else {
                $mdyValue = DateManagement::YMD2MDY($value);
                $startdate = "<input type='text' class='dates' onkeydown='enactChange($i, this, \"start_date\", \"$source\", \"$awardNo\");' id='$startName' value='$mdyValue' />";
            }
		} else if ($key == "end_date") {
			if($value == ''){
		 		$enddate = "<input type='text' class='dates' onkeydown='enactChange($i, this, \"end_date\", \"$source\", \"$awardNo\");' id='$endName' value='' />";
		 	} else {
                $mdyValue = DateManagement::YMD2MDY($value);
                $enddate = "<input type='text' class='dates' onkeydown='enactChange($i, this, \"end_date\", \"$source\", \"$awardNo\");' id='$endName' value='$mdyValue' />";
            }
        } else if($key == "project_start_date"){
            if($value == ''){
                $project_start_date = "<input type='text' class='dates' onkeydown='enactChange($i, this, \"project_start_date\", \"$source\", \"$awardNo\");' id='$projectStartName' value='' />";
            } else {
                $mdyValue = DateManagement::YMD2MDY($value);
                $project_start_date = "<input type='text' class='dates' onkeydown='enactChange($i, this, \"project_start_date\", \"$source\", \"$awardNo\");' id='$projectStartName' value='$mdyValue' />";
            }
        } else if($key == "project_end_date"){
            if($value == ''){
                $project_end_date = "<input type='text' class='dates' onkeydown='enactChange($i, this, \"project_end_date\", \"$source\", \"$awardNo\");' id='$projectEndName' value='' />";
            } else {
                $mdyValue = DateManagement::YMD2MDY($value);
                $project_end_date = "<input type='text' class='dates' onkeydown='enactChange($i, this, \"project_end_date\", \"$source\", \"$awardNo\");' id='$projectEndName' value='$mdyValue' />";
            }
        } else if(($key == "person_name") && $value){
            if (strlen($value) > 100) {
                $piName = substr($value, 0, 100)."...";
            } else {
                $piName = $value;
            }
        } else if($key == "sponsor"){
			if($value == ''){
				$d_sponsor = BLANK_VALUE;
			} else $d_sponsor = $value;
		} else if($key == "sponsor_type"){
			if($value == ''){
				$d_sponsor_type = '';
			} else $d_sponsor_type = " (".$value.")";
		} else if($key == "original_award_number"){
			if($value == ''){
				$d_original_award_number_no = '';
				$d_ck_original_award_number = '';
			} else {
                if ($value !== "000") {
                    $d_ck_original_award_number = Grant::trimApplicationType($value);
                } else {
                    $d_ck_original_award_number = $value;
                }
				$d_original_award_number_no = $value;
			}
		} else if($key == "finance_type"){
			if($value == ''){
				$d_finance_type = '';
			} else $d_finance_type = "<div class='fl wd49 sect'><div class='fwb'>Finance Type</div><div class='display_data'>".ucfirst($value)."</div></div>";
		} else if($key == "sponsor_award_no"){
			if($value == ''){
				$d_sponsor_award_no = '';
			} else {
                addSpacesIfRelevant($value);
                $d_sponsor_award_no = "<div class='fl wd49 sect'><div class='fwb'>Sponsor Award #</div><div class='display_data'>$value</div></div>";
            }
		} else if($key == "base_award_no"){
			if($value == ''){
				$d_base_award_no = '';
				$d_ck_base_award_no = '';
			} else {
				$class_baseaward = $value;
				$d_ck_base_award_no = $value;
				$d_base_award_no = "<div class='fl wd49 sect' style=''><div class='fwb'>Base Award #</div><div class='display_data'>$value</div></div>";
			}
		} else if($key == "prime_sponsor_type"){
			if($value == ''){
				$d_prime_sponsor_type = BLANK_VALUE;
			} else $d_prime_sponsor_type = "$value";
		} else if($key == "direct_sponsor_type"){
			if($value == '' || $value == null){
				$d_direct_sponsor_type = BLANK_VALUE;
			} else $d_direct_sponsor_type = ' ('.$value.')';
		}  else if($key == "direct_sponsor_name"){
			if($value == ''){
				$d_direct_sponsor_name = BLANK_VALUE;
			} else $d_direct_sponsor_name = $value;
		}  else if($key == "prime_sponsor_name"){
			if($value == ''){
				$d_prime_sponsor_name = BLANK_VALUE;
			} else $d_prime_sponsor_name = ', '.$value;
		}  else if($key == "role"){
			if($value == ''){
				$d_role = '';
			} else {$d_role = $value;}
		}  else if($key == "source"){
			if($value == ''){
				$d_source = BLANK_VALUE;
			} else $d_source = str_replace("_", " ", $value);
		}   else if($key == "nih_mechanism"){
			if($value == ''){
				$d_nih_mechanism = BLANK_VALUE;
			} else $d_nih_mechanism = $value;
		} else if($key == "url"){
			if($value == ''){
				$d_redcap_url = BLANK_VALUE;
			} else $d_redcap_url = $value;
		}  else if($key == "application_type"){
			if($value == ''){
				$d_application_type = '';
			} else $d_application_type = "<div class='fl wd49 sect' style=''><div class='fwb'>Application Type</div><div class='display_data'>$value</div></div>";
		}   else if($key == "funding_source"){
			if($value == ''){
				$d_funding_source = BLANK_VALUE;
			} else $d_funding_source = str_replace("/", " / ", $value);
		} else if(in_array($key, ["budget", "total_budget"])) {
			if($value == ''){
				$d_budget_total = BLANK_VALUE;
			} else $d_budget_total = '$'.number_format(intval($value));
		} else if(in_array($key, ["direct_budget", "budget"])){
			if(($value == '') && in_array($d_direct_budget, ['', BLANK_VALUE])) {
				$d_direct_budget = BLANK_VALUE;
			} else $d_direct_budget = '$'.number_format(intval($value));
		} else if($key == "percent_effort"){
			if($value == ''){
				$d_percent_effort = BLANK_VALUE;
			} else $d_percent_effort = $value.'%';
		} else if($key == "last_update"){
			if($value == ''){
				$d_last_update = '';
			} else $d_last_update = "<span class='fwb'>Last Updated:</span><br/>".DateManagement::YMD2MDY($value);
        }
    }

	$backgroundClass = "small_padding";
    $d_role = $d_role ?? BLANK_VALUE;
    $d_source = $d_source ?? BLANK_VALUE;
    $d_percent_effort = $d_percent_effort ?? BLANK_VALUE;
    $d_funding_source = $d_funding_source ?? BLANK_VALUE;
    $d_sponsor = $d_sponsor ?? "";
    $d_sponsor_type = $d_sponsor_type ?? "";
    $d_finance_type = $d_finance_type ?? "";
    $d_application_type = $d_application_type ?? "";
    $d_base_award_no = $d_base_award_no ?? "";
    $d_redcap_url = $d_redcap_url ?? "";
    $d_nih_mechanism = $d_nih_mechanism ?? "";
    $telem = $telem ?? "";
    $ftype = $ftype ?? "";
    $d_last_update = $d_last_update ?? "";
    $d_original_award_number_no = $d_original_award_number_no ?? "";

    $redcapDiv = "<div class='fl wd49 sect' style=''>";
    if ($d_redcap_url) {
        $redcapDiv .= "<div class='fwb'><a href='".$d_redcap_url."' target='_NEW'>View REDCap</a></div>";
    } else {
        $redcapDiv .= "<div class='fwb'>REDCap Instrument</div>";
    }
    if ($d_source) {
        if ($d_redcap_url) {
            $redcapDiv .= "<div class='display_data'><strong>Instrument</strong>: $d_source</div>";
        } else {
            $redcapDiv .= "<div class='display_data'>$d_source</div>";
        }
    }
    $redcapDiv .= "</div>";
    if (!$d_redcap_url && !$d_source) {
        $redcapDiv = "";
    }

    $awardNoWithoutApplicationType = $d_ck_original_award_number ?: Grant::trimApplicationType($ary['sponsor_award_no']) ?: $d_ck_base_award_no;
    if ($awardNoWithoutApplicationType == "000") {
        $awardNoWithoutApplicationType = "<i>".Grant::$noNameAssigned."</i> (000)";
    }
    $show_anawardno = REDCapManagement::makeHTMLId($d_ck_original_award_number ?: $d_ck_base_award_no);
    $show_anawardno .= "___".$d_source;
    if ($flagsOn && in_array($show_anawardno, $flaggedGrants)) {
        $fontAwesomeFlag = "<i class='fas redtext fa-flag'></i>";
    }

    $primeSponsorDiv = "";
    $directSponsorDiv = "";
    if ($d_prime_sponsor_type || $d_prime_sponsor_name) {
        $primeSponsorDiv = "<div class='fl wd49 sect'>".
                "<div class='fwb'>Prime Sponsor Type</div>".
                "<div class='display_data'>".$d_prime_sponsor_type.$d_prime_sponsor_name."</div>".
            "</div>";
    }
    if ($d_direct_sponsor_type || $d_direct_sponsor_name) {
        $directSponsorDiv = "<div class='fl wd49 sect'>".
                "<div class='fwb'>Direct Sponsor Type</div>".
                "<div class='display_data'>".$d_direct_sponsor_name.$d_direct_sponsor_type."</div>".
            "</div>";
    }
    $awardJSON = json_encode($ary);

	return "<table group='".$show_anawardno."' class='tn".$tablenum." ".$doclass." tlayer_".$show_anawardno." awardt rr".$d_original_award_number_no."' style='width: 380px; margin: 0 auto -12px auto; padding-top: 12px;'>".
			"<tr class='".$backgroundClass."'>".
                "<td style='padding: 3px 12px !important;'><div style='text-align: left;font-size: 13px;margin-top: 2px;margin-bottom: -3px; float: left; width:30%;'><strong>Role</strong>: ".$d_role."<br/><strong>Effort</strong>: ".$d_percent_effort."</div><div style='text-align: right;font-size: 13px;margin-top: 2px;margin-bottom: -3px; float: right; width:30%'> ".$d_last_update."</div><div style='float: right; width: 40%; text-align: center;'>$piName</div></td></tr>".
            "<tr class='$backgroundClass'><td><h3 class='withFlag'>$awardNoWithoutApplicationType</h3><span title='Flag to manually choose' class='flag' onclick='toggleFlag(this, \"$awardNo\", \"$source\");'>$fontAwesomeFlag</span></td></tr>".
			"<tr class='$backgroundClass'><td><div class='row' style='margin-bottom:10px; margin-top: 7px;   padding-left: 15px;padding-right: 15px;'>".
							"<div class='col-md-7 align-self-center' style='max-width:49%;padding-left: 0px;padding-right: 0px; background-color:#55555536;margin-right: 4px;'>".
								"<div class='fwb'>PROJECT</div>".
								"<div style='border-bottom: 1px solid;margin-right: 5px;margin-left: 5px;'>".
									"<div class='fwb' style='width:50%;display: inline-block;'>START</div>".
									"<div class='fwb' style='width:50%;display: inline-block;'>END</div>".
								"</div>".
							"</div>".
							"<div class='col-md-7 align-self-center' style='padding-left: 0px;padding-right: 0px; background-color:#55555536;max-width: 49%;'>".
							"<div class='fwb'>BUDGET</div>".
								"<div style='border-bottom: 1px solid;margin-right: 5px;margin-left: 5px;'>".
									"<div class='fwb' style='width:50%;display: inline-block;'>START</div>".
									"<div class='fwb' style='width:50%;display: inline-block;'>END</div>".
								"</div>".
							"</div></div>".
							"<div class='row' style='margin-top: -10px; margin-bottom: 10px;    padding-left: 15px;padding-right: 15px;'>".
							"<div class='col-md-7 align-self-center dateEntryDiv' style='margin-right: 4px;'>".
                            "<div>".
                                "<div style='width:50%;display: inline-block;' class='display_data'>$project_start_date</div>".
                                "<div style='width:50%;display: inline-block;' class='display_data'>$project_end_date</div>".
                            "</div>".
							"</div>".
							"<div class='col-md-7 align-self-center dateEntryDiv'>".
								"<div>".
									"<div style='width:50%;display: inline-block;' class='display_data'>$startdate</div>".
									"<div style='width:50%;display: inline-block;' class='display_data'>$enddate</div>".
								"</div>".
							"</div>".
							"<div class='col-md-12 align-self-center' style='margin-top: 5px;margin-bottom: -8px;'><div class='row'>".
									"<div class='align-self-center' style='height: 18px;float:left;padding-left: 0px;padding-right: 0px; width: 49%;' class='display_data'><span class='fwb'>Budget (Total):</span> ".$d_budget_total."</div>".
									"<div class='align-self-center' style='height: 18px;float:left;padding-left: 0px;padding-right: 0px; width: 49%;' class='display_data'><span class='fwb'>Budget (Direct):</span> ".$d_direct_budget."</div>".
								"</div></div>".
							"</div></td></tr>".
			"<tr class='$backgroundClass'><td>".
					"<div class='sect'>".
						"<div class='fwb'>Sponsor</div>".
						"<div class='display_data'>".$d_sponsor.$d_sponsor_type."</div>".
					"</div>".
			"</td></tr>".
            "<tr class='$backgroundClass'><td>".$d_sponsor_award_no.$d_finance_type.$d_application_type.$redcapDiv."</td></tr>".
           "<tr class='$backgroundClass'><td>".$d_base_award_no.
				"<div class='fl wd49 sect'>".
					"<div class='fwb'>NIH Mechanism</div>".
                    "<div class='display_data'>".$d_nih_mechanism."</div>".
                "</div>".
                $primeSponsorDiv.$directSponsorDiv.
			"</td></tr>".
			"<tr class='$backgroundClass'><td>".
                    "<div class='fl sect' style='width:40%'>".
                        "<div class='fwb'>Funding Source</div>".
                        "<div class='display_data' style='text-transform:uppercase;'>".$d_funding_source."</div>".
                    "</div>".
					"<div class='fl sect' style='width:60%'>".
						"<div class='fwb'>Type / Bin</div>".
						"<div class='display_data' style='text-transform:uppercase;'>".$telem."</div>".
					"</div>".
			"</td></tr>".

            "<tr class='$backgroundClass'><td>".
            "</td></tr>".
            implode("</tr><tr class='$backgroundClass'>", $elems)."</tr><tr><td><div>".$tbuttons."</div><div class='centered' style='float: right; width: calc(50% - 5px); font-size: 12px; margin: 8px auto;'><a href='javascript:;' class='button setNAButton' onclick='dissociate($i, $awardJSON); return false;'>not their grant</a></div></td></tr></table><div class='at ".$doclass." at".$class_baseaward."' style='position:relative;'><div class='ftype'>".$ftype."</div></div>";
}

function findNumberOfSimilarAwards($baseAwardNo, $originalKey, $listOfAwards) {
	$numberOfSimilarAwards = 0;
	foreach ($listOfAwards as $key => $specs) {
		if (($key != $originalKey) && ($specs['base_award_no'] == $baseAwardNo)) {
			$numberOfSimilarAwards++;
		}
	}
	return $numberOfSimilarAwards;
}

$blanks = ["", "[]"];
$nextPageLink = "";
$getClause = isset($_GET['new']) ? "&new=$daysForNew" : "";
$thisURL = Application::link("this")."&record=".urlencode($record).$getClause;
$nextRecord = $records[0] ?? $record;
foreach ($records as $i => $myRecordId) {
    if ($myRecordId == $record) {
        $nextRecord = $records[$i + 1] ?? $records[0];
        $nextPageLink = Application::link("this")."&record=".urlencode($nextRecord).$getClause;
        break;
    }
}
$nextNewRecordButton = "";
if (isset($_GET['new'])) {
	$nextNewRecord = getNextRecordWithNewData($record, FALSE);
	if ($nextNewRecord && ($nextNewRecord > $record)) {
        $url = Application::link("this");
        $nextNewRecord = urlencode($nextNewRecord);
        $nextNewRecordButton = "<br/><a href='"."$url&record=$nextNewRecord$getClause' class='button'>view next <u>new</u> record</a>";
	} else {
        $nextNewRecordButton = "<br/><div class='centered'>No more new grants.</div>";
    }
}
?>

<div id='content' style='margin-left:auto;'>
<?php
    if (function_exists("makeHelpLink")) {
        echo makeHelpLink();
    } else {
        echo \Vanderbilt\FlightTrackerExternalModule\makeHelpLink();
    }
?>
<script>
function refreshToDays() {
	let days = $("#newDaysForNew").val();
	if (isNaN(days) || (days === "")) {
		days = '<?= $daysForNew ?>';
	}

	let rec = '<?= $record ?>';
	if (!rec) {
		rec = '1';
	}

	if (days !== <?= $daysForNew ?>) {
		window.location.href = '<?= Application::link("this") ?>&record='+rec+'&new='+days;
	}
}

function toggleFlags(newState) {
    displayToImport();
    $.post(
        '<?= $updateLink."&flags" ?>',
        { newState: newState },
        (json) => {
            console.log(json);
            if (json.match(/^</)) {
                alert("ERROR: "+json);
            } else {
                const data = JSON.parse(json);
                if (data['error']) {
                    alert("ERROR: Could not turn "+newState+" flags. " + data['error']);
                } else {
                    // so that flag icons will show, refresh page
                    window.location.href = '<?= $thisURL ?>';
                }
            }
        }
    );
}

function toggleFlag(spanObj, awardNo, source) {
    const grantID = $(spanObj).closest('table').attr('group');
    const oldValue = $(spanObj).html();
    let newValue = oldValue;
    let flagOnOff = "off";
    let newFlagValue = '0';
    if (oldValue.match(/fas redtext/)) {
        newValue = oldValue.replace("fas redtext", "far");
        flagOnOff = "off";
        newFlagValue = '0';
    } else if (oldValue.match(/far/)) {
        newValue = oldValue.replace("far", "fas redtext");
        flagOnOff = "on";
        newFlagValue = '1';
    }

    $(spanObj).html(newValue);
    const newToImport = updateToImport(awardNo, source, 'flagged', newFlagValue, true);
    $.post(
        '<?= $updateLink."&flag" ?>',
        { record: '<?= $record ?>', grant: grantID, value: flagOnOff, toImport: newToImport, redcap_csrf_token: getCSRFToken() },
        (json) => {
            console.log(json);
            if (json.match(/^</)) {
                $(spanObj).html(oldValue);
                alert("ERROR: "+json);
            } else {
                const data = JSON.parse(json);
                if (data['error']) {
                    $(spanObj).html(oldValue);
                    alert("ERROR: Could not toggle flag. " + data['error']);
                } else if (data['error_summary']) {
                    processSummaryError(data['error_summary'], saveCurrentState);
                } else {
                    refreshVisualization();
                }
            }
        }
    );
}

function hideBubble() {
    $('.bubble').hide();
    $('.closeX').hide();
    $.post('<?= Application::link("this") ?>', { hideBubble: true, redcap_csrf_token: getCSRFToken() }, (html) => { console.log(html); });
}

function toggleChange(dflt, ob, i, tclasses, source, awardNo){
	const tclass = tclasses.split(' ');
	const el = tclass.find(a =>a.includes("rr"));
	const remclass = tclass.find(a =>a.includes("class_"));
	const getclass = ob.val();

    let doclass, ftype;
	if(getclass === 'Individual K'){
		doclass = 'class_c0';  ftype='K';
	} else if(getclass === 'Internal K'){
		doclass = 'class_c1';  ftype='K';
	} else if(getclass === 'K12/KL2'){
		doclass = 'class_c2';  ftype='K';
	} else if(getclass === 'K Equivalent'){
		doclass = 'class_c3';   ftype='K';
	} else if(getclass === 'R01'){
		doclass = 'class_c4';  ftype='R';
	} else if(getclass === 'R01 Equivalent'){
		doclass = 'class_c5'; ftype='R';
	} else if(getclass === 'Training Appointment'){
		doclass = 'class_c6';   ftype='TA';
	} else if(getclass === 'Research Fellowship'){
		doclass = 'class_c7';  ftype='RF';
	} else if(getclass === 'Mentoring/Training Grant Admin'){
		doclass = 'class_c8';  ftype='TG';
	} else if(getclass === 'K99/R00'){
		doclass = 'class_c9';   ftype='KR';
	} else if(getclass === 'N/A'){
		doclass = 'class_c10';   ftype='N';
	} else {
		doclass = 'class_grey';  ftype='?';
	}
	$('.'+el).removeClass(remclass).addClass(doclass);
	$('.'+(el.replace("rr", "at"))+' .ftype').html(ftype);

	if (ob.val() !== dflt) {
        enactChange(i, ob, 'redcap_type', source, awardNo);
    } else {
		$("#change_"+i).hide();
	}
    if (isInTrainingMode()) {
        alert('We\'ve updated the RedCap Type for this project from <strong>' + dflt + '</strong> to <strong>' + getclass + '</strong>.');
    }
	setTimeout(function(){ $('.sweet-modal-overlay').css('display','none'); }, 3000);
}

function isInList(listID, i) {
    return ($('#'+listID+' #add_'+i).length > 0);
}

function enactChange(i, ob, field, source, awardNo) {
    $('#change_'+i).show();
    changeChangeText(i);
    if (ob && field && source && awardNo) {
        let val = '';
        if ($(ob).prop('tagName') === "SELECT") {
            val = $(ob).find(':selected').text();
        } else {
            val = $(ob).val();
        }
        if ($(ob).attr('type') === 'text') {
            if (val.match(/^\d\d[\-\/]\d\d[\-\/]\d\d\d\d$/)) {
                updateToImport(awardNo, source, field, mdy2ymd(val));
            }
        } else {
            updateToImport(awardNo, source, field, val);
        }
    }
}

function getIFromObj(item) {
    return parseInt($(item).find(".change").attr("id").replace(/^change_/, ""));
}

function changeChangeText(i) {
    if (isInList("llist", i)) {
        $('#change_'+i+' .thestatus').html("[ drag right to enact data change ]");
    } else {
        $('#change_'+i+' .thestatus').html("[ data changes made ]");
    }
}

function prioritizeAward(i, award) {
    changeAward("redcap_type_"+i, i, award);
    moveToRightColumn(i, award);
    removeAward(i, award, true);
}

function dissociate(i, award) {
    moveToRightColumn(i, award);
    removeAward(i, award, true);
}

function moveToRightColumn(i, award) {
    const awardno = award['original_award_number'] ?? award['sponsor_award_no'] ?? "<?= JS_UNDEFINED ?>";
    const awardnoWithoutLead = makeHTMLId(awardno.match(/^\d[A-Za-z]/) ? awardno.replace(/^\d/, '') : awardno);
    const source = award['source'].replace(/_/g, ' ') ?? "<?= JS_UNDEFINED ?>";
    const sep = "___";
    const group = awardnoWithoutLead+sep+source;
    $('table[group=\"'+group+'\"]').parent().appendTo('#ldrop');
}

function changeAward(selectName, i, award) {
	$('#change_'+i).hide();
	award['redcap_type'] = $('#'+selectName).val();
	const startName = selectName.replace(/redcap_type/, "start_date");
	const endName = selectName.replace(/redcap_type/, "end_date");
	award['start_date'] = $('#'+startName).val();
	award['end_date'] = $('#'+endName).val();
	if ($('#add_'+i).is(":visible")) {
		addToImport(award, "A_CHANGE");
	} else {
		addToImport(award, "R_CHANGE");
	}
}

function removeAward(i, award) {
	$('#remove_'+i).hide();
	$('#add_'+i).show();
    $('#left_'+i).hide();
    changeChangeText(i);
    $('select#redcap_type_'+i+' option:last-child').attr('selected', true).click();
    addToImport(award, "REMOVE");
}

function addAward(selectName, i, award) {
	$('#left_'+i).show();
	$('#add_'+i).hide();
    changeChangeText(i);

    const typeObj = $('#redcap_type_'+i);
    award['redcap_type'] = (typeObj.length > 0) ? typeObj.val() : award['redcap_type'];

    const fieldsToReplace = ["start_date", "end_date", "project_start_date", "project_end_date"];
    for (let j=0; j < fieldsToReplace.length; j++) {
        const field = fieldsToReplace[j];
        const selector = '#'+field+'_'+i;
        let date = award[field];
        if ($(selector).length > 0) {
            const newDate = $(selector).val();
            if (newDate.match(/^\d+[\-\/]\d+[\-\/]\d{4}/)) {
                date = mdy2ymd(newDate);
            }
        }
        award[field] = date;
    }

    if (award['redcap_type'] === 'N/A') {
        $.sweetModal.confirm("Just to confirm, you're adding a Redcap Type of \"N/A\". This type of grant is usually omitted from the career progression. <strong>Do you want to Continue?</strong>", function(){
            // confirm
            $('.sweet-modal-overlay').addClass('modal'+i);
            addToImport(award, "ADD");
            setTimeout(function(){
                $('.modal'+i).remove();
            }, 3*1000);

        }, function(){
            // decline
            removeAward(i, award);
            $('.modal'+i).remove();
        });
    } else {
        addToImport(award, "ADD");
    }
}

function mdy2ymd(date) {
    const myDate = date.trim();
    if (myDate.match(/^\d+[\-\/]\d+[\-\/]\d{4}$/)) {
        const nodes = myDate.split(/[\-\/]/);
        if (nodes.length === 3) {
            return nodes[2]+'-'+nodes[0]+'-'+nodes[1];
        }
    }
    return myDate;
}

function getLongMonth(monthNum) {
    if (monthNum === 1) {
        return "January";
    } else if (monthNum === 2) {
        return "February";
    } else if (monthNum === 3) {
        return "March";
    } else if (monthNum === 4) {
        return "April";
    } else if (monthNum === 5) {
        return "May";
    } else if (monthNum === 6) {
        return "June";
    } else if (monthNum === 7) {
        return "July";
    } else if (monthNum === 8) {
        return "August";
    } else if (monthNum === 9) {
        return "September";
    } else if (monthNum === 10) {
        return "October";
    } else if (monthNum === 11) {
        return "November";
    } else if (monthNum === 12) {
        return "December";
    }
    return monthNum;
}

function getLongDate(date) {
    let hours = date.getHours();
    let minute = date.getMinutes();
    if (minute < 10) {
        minute = "0"+minute;
    }
    let ampm = "am";
    if (hours === 0) {
        hours = "12";
        ampm = "am";
    } else if (hours > 12) {
        hours -= 12;
        ampm = "pm";
    } else if (hours === 12) {
        ampm = "pm";
    }
    const month = getLongMonth(date.getMonth() + 1);
    return month+" "+date.getDate()+", "+date.getFullYear()+", "+hours+":"+minute+" "+ampm;
}

function processSummaryError(errorMssg, cb) {
    if (errorMssg.match(/Script is locked/i)) {
        // alert("ERROR: Could regenerate summary. This will automatically happen later.");
        if (cb) {
            setTimeout(cb, 30000);
        }
    } else {
        alert("ERROR: Could regenerate summary. This will automatically happen later. "+errorMssg);
    }
}

function displayToImport() {
    const toImport = $('#tmainform #toImport').val();
    console.log("toImport: "+toImport);
}

function saveCurrentState() {
    // displayToImport();
    $.post(
        "<?= $updateLink ?>",
        $("#tmainform").serialize(),
        (json) => {
            console.log("saveCurrentState: "+json);
            try {
                if (json.match(/^</)) {
                    alert("ERROR: "+json);
                } else {
                    const data = JSON.parse(json);
                    if (data['error_save']) {
                        alert("ERROR: Could not save data. " + data['error_save']);
                    } else if (data['error_summary']) {
                        processSummaryError(data['error_summary'], saveCurrentState);
                    } else {
                        refreshVisualization();
                    }
                }
            } catch (e) {
                alert("ERROR: Something weird happened when saving data. "+e.message);
            }
        }
    );
}

function refreshVisualization() {
    $.post(
        "<?= $updateLink."&viz" ?>",
        { record: '<?= $record ?>', redcap_csrf_token: getCSRFToken() },
        (json) => {
            console.log("Refreshing progression: "+json);
            if (json.match(/^</)) {
                alert("ERROR: "+json);
            } else {
                const careerProgressionData = JSON.parse(json);
                if (careerProgressionData['error']) {
                    alert("ERROR: Could not refresh timeline. " + data['error']);
                } else {
                    const subTitle = '<h4 class="nomargin">Calculated on ' + getLongDate(new Date(Date.now())) + '</h4>';
                    setupVisualization(careerProgressionData, subTitle);
                }
            }
        }
    );
}

function find_i(awardno) {
	let i = 0;
	const listOfi = new Array();
	while ($('#listOfAwards_'+i).length) {
		const awardno_i = $('#listOfAwards_'+i).val();
		if (awardno_i === awardno) {
			listOfi.push(i);
		}
		i++;
	}
	return listOfi;
}

function removeFromImport(awardno) {
	const toImport = $('#tmainform #toImport').val();
	let tI = JSON.parse(toImport);
	const tI2 = {};
	for (let index in tI) {
		if (tI[index][1]['sponsor_award_no'] !== awardno) {
			tI2[index] = tI[index];
		} else {
			const i_list = find_i(awardno);
			if (tI[index][0] === "ADD") {
				for (let j = 0; j < i_list.length; j++) {
					const i = i_list[j];
					$('#remove_'+i).hide();
					$('#add_'+i).show();
				}
			} else if (tI[index][0] === "REMOVE") {
				for (let j = 0; j < i_list.length; j++) {
					const i = i_list[j];
					$('#remove_'+i).show();
					$('#add_'+i).hide();
				}
			} else if (tI[index][0] === "A_CHANGE") {
				for (let j = 0; j < i_list.length; j++) {
					let i = i_list[j];
					$('#remove_'+i).hide();
					$('#add_'+i).show();
                    enactChange(i);
				}
			} else if (tI[index][0] === "R_CHANGE") {
				for (let j = 0; j < i_list.length; j++) {
					const i = i_list[j];
					$('#remove_'+i).show();
					$('#add_'+i).hide();
                    enactChange(i);
				}
			}
		}
	}
	tI = tI2;

	$('#tmainform #toImport').val(JSON.stringify(tI));
    saveCurrentState();
}

// no need to search through the list of awards for other versions
function isOkToShowJS(row) {
	const ary = row[1];
<?php
	echo "\tconst isNew = ";
	if (isset($_GET['new'])) {
		echo "true";
	} else {
		echo "false";
	}
	echo ";\n";
	echo "\tconst daysForNew = ".$daysForNew.";\n";
?>
	if ((typeof ary['last_update'] != "undefined") && (typeof ary['end_date'] != "undefined") && (isNew)) {
		const dateLast = ary['last_update'];
		const dateEnd = ary['end_date'];
		if ((dateLast === "") || (dateEnd === "")) {
			return false;
		}
        const dLast = new Date(dateLast);
        const dEnd = new Date(dateEnd);
        const dLast_time = dLast.getTime();
        const dEnd_time = dEnd.getTime();
        const now = new Date();
        const now_time = now.getTime();
		if ((now_time - dLast_time <= daysForNew * 24 * 3600 * 1000)
			&& (now_time - dEnd_time <= daysForNew * 24 * 3600 * 1000)) {
			return true;
		}
		return false;
	}
	return true;
}

function cleanAward(award) {
	for (let key in award) {
		if (typeof award[key] != "undefined") {
			award[key] = award[key].replace(/qqqqq/, "'");
		}
	}
	return award;
}

// Coordinated with Grant class
function getIndex(awardno, sponsor, startDate) {
	const sep = "____";
	return awardno+sep+sponsor+sep+startDate;
}

function updateDateFields(award) {
    const dateFields = ["start_date", "end_date", "project_start_date", "project_end_date"];
    for (let i=0; i < dateFields.length; i++) {
        const field = dateFields[i];
        if (award[field]) {
            award[field] = mdy2ymd(award[field]);
        }
    }
    return award;
}

function updateToImport(awardNo, source, field, value, returnValue) {
    const toImport = $('#tmainform #toImport').val();
    let tI = JSON.parse(toImport);

    let changed = false;
    for (let index in tI) {
        const award = tI[index][1];
        if (
            (award['source'] === source)
            && (award['sponsor_award_no'] === awardNo)
            && (tI[index][1][field] !== value)
        ) {
            console.log("Updating "+field+" from "+tI[index][1][field]+" to "+value);
            tI[index][1][field] = value;
            changed = true;
        }
    }

    if (returnValue) {
        return JSON.stringify(tI);
    } else if (changed) {
        $('#tmainform #toImport').val(JSON.stringify(tI));
        saveCurrentState();
    }
}

function addToImport(award, action) {
    award = updateDateFields(award);
    const awardno = award['sponsor_award_no'];

    const toImport = $('#tmainform #toImport').val();
    let tI = JSON.parse(toImport);
    const index = getIndex(awardno, award['sponsor'], award['start_date']);
    if (typeof tI[index] == "undefined") {
        const tI2 = {};
        tI2[index] = [action, award];
        for (let index2 in tI) {
            const re = RegExp("^"+award['sponsor_award_no']);
            if ((tI[index2]['sponsor_award_no'] === '000') || !index2.match(re)) {
                // copy over
                tI2[index2] = tI[index2];
            }
        }
        tI = tI2;
    } else if (action === "REMOVE") {
        delete tI[index];
    } else {
        tI[index] = [action, award];
    }
    $('#tmainform #toImport').val(JSON.stringify(tI));
	saveCurrentState();
}
</script>
<?php

$lastUpdate = "";
$row = REDCapManagement::getNormativeRow($redcapData);
if (isset($_GET['new'])) {
    echo "<div class='trow'><h1><span>Grant Wrangler for ";
    echo "Last <input type='text' id='newDaysForNew' style='font-size:22px; width:50px;' onblur='refreshToDays();' value='$daysForNew'> Days";
    echo "</span></h1>";
} else {
    echo "<div class='trow'><h1><span>Grant Wrangler</span></h1>";
}

$switchFlagStatus = Grants::areFlagsOn($pid) ? "off" : "on";  // deliberately reversed


echo "<br/>";
if ($row['identifier_last_name'] && $row['identifier_first_name']) {
    echo "<h2 style='width: 400px; display: inline-block; z-index: 0;'><span>{$row['identifier_last_name']}</span>, {$row['identifier_first_name']}</h2>";
} else if ($row['identifier_first_name']) {
    echo "<h2 style='width: 400px; display: inline-block; z-index: 0;'>{$row['identifier_first_name']}</h2>";
} else if ($row['identifier_last_name']) {
    echo "<h2 style='width: 400px; display: inline-block; z-index: 0;'><span>{$row['identifier_last_name']}</span></h2>";
} else {
    echo "<h2 style='width: 400px; display: inline-block; z-index: 0;'>No name specified</h2>";
}
echo "<div id='dsearch'>";

if (($row['record_id'] == ((int) $record) + 1) && (!$nextPageLink))  {
    $nextPageLink = Application::link("this")."&record=".($record+1).$getClause;
}
$summaryLink = Links::makeSummaryLink($pid, $record, $event_id, "REDCap Summary");
$addNewGrantLink = Links::makeCustomGrantLink($pid, $record, $event_id, "Add New Grant");
if (!isset($_GET['new'])) {
    $toggleLink = "<a href='$thisURL&new=$daysForNew'>New Grants Only</a>";
} else {
    $allURL = str_replace($getClause, "", $thisURL);
    $toggleLink = "<a href='$allURL'>View All Grants</a>";
}
$excludeListHTML = $excludeList->makeEditForm($record);
$excludeListHTML = str_replace("<button", "<a class='button' href='javascript:;'", $excludeListHTML);
$excludeListHTML = str_replace("</button>", "</a>", $excludeListHTML);
$excludeListHTML = str_replace("Update", "update list", $excludeListHTML);

echo "<div class='tsearch'><div>Enter Last Name:</div><div><input type='text' id='search'></div><div id='searchDiv'></div></div>";
echo "<div class='tor'>or</div>";
echo "<div class='tsearch'><div>Choose Record:</div><div style='color:#ffffff;'>".str_replace("Record:", "", Publications::getSelectRecord(TRUE))."</div></div>";
echo "<div class='tflag' title='Flagging grants only uses the ones you choose. If turned off, the computer calculates results.'><a href='javascript:;' class='button' style='padding-left: 3px !important; padding-right: 3px !important; margin-top: 2px !important; line-height: 1.1em;' onclick='toggleFlags(\"$switchFlagStatus\"); return false;'>turn flags $switchFlagStatus</a></div>";
echo "<div class='tsearch' style='clear: both;'><a href='$nextPageLink' class='button'>view next record</a>$nextNewRecordButton</div>";
echo "<div class='tor'></div>";
echo "<div class='tsearch' style='border: 1px solid #888888; padding: 2px; width: calc(30% + 72px); margin-top: 12px; font-size: 12px;'>".$excludeListHTML."</div>";
echo "<div class='tgrantse'><a href='$thisURL'>Refresh Page</a>$summaryLink$addNewGrantLink$toggleLink</div>";
echo "</div>";
echo "<div style='clear:both;'></div></div>";

echo "<div id='visualization'></div>";


foreach ($row as $field => $value) {
    if (($value === "") && preg_match("/^summary_calculate_to_import/", $field)) {
        $row[$field] = "{}";
    } else if (($value === "") && preg_match("/^summary_calculate_/", $field)) {
        $row[$field] = "[]";
    }
}
$lastUpdate = $row['summary_last_calculated'] ? "<h4 class=\"nomargin\">Calculated on ".DateManagement::datetime2LongDateTime($row['summary_last_calculated'])."</h4>" : "";
$order = json_decode($row["summary_calculate_order"], true);
$inUse = [];
$careerProgressionAry = [];
if (!empty($order)) {
    $ai = 0;
    foreach ($order as $award) {
        $inUse[] = $award['sponsor_award_no'];
        $careerProgressionAry[] = \Vanderbilt\FlightTrackerExternalModule\careerprogression($award, $ai++);
    }
}
$careerProgressionJSON = json_encode($careerProgressionAry);

$toImport = json_decode($row["summary_calculate_to_import"], true);
$listOfAwards = json_decode($row["summary_calculate_list_of_awards"], true);
$awardDescript = Grants::areFlagsOn($pid) ? "Flagged" : "Career-Defining";

?>
    <script>
        domodal=function(obj){
            $.sweetModal({
                content: $(".rr"+obj)[0].outerHTML+$(".at"+obj)[0].outerHTML//,
                //icon: $.sweetModal.ICON_SUCCESS
            });
        }

        function getEarliestStartDate(cp) {
            let ts = Date.now();
            for (let i=0; i < cp.length; i++) {
                const currStartDate = cp[i]['start'];
                const currTs = Date.parse(currStartDate+"T00:00:00.000Z");
                if (currTs < ts) {
                    ts = currTs;
                }
            }
            const oneDay = 24 * 3600 * 1000;
            ts -= <?= getSpacingDays() ?> * oneDay;
            const toReturn = new Date();
            toReturn.setTime(ts);
            return toReturn.getFullYear()+"-"+(toReturn.getMonth()+1)+"-"+toReturn.getDate();
        }

        function getLatestEndDate(cp) {
            let ts = Date.now();
            for (let i=0; i < cp.length; i++) {
                const currEndDate = cp[i]['end'];
                const currTs = Date.parse(currEndDate+"T00:00:00.000Z");
                if (currTs > ts) {
                    ts = currTs;
                }
            }
            const oneDay = 24 * 3600 * 1000;
            ts += <?= getSpacingDays() ?> * oneDay;
            const toReturn = new Date();
            toReturn.setTime(ts);
            return toReturn.getFullYear()+"-"+(toReturn.getMonth()+1)+"-"+toReturn.getDate();
        }

        window.onload = function() {
            setupVisualization(<?= $careerProgressionJSON ?>, '<?= $lastUpdate ?>');
        }

        let timeline = null;

        function setupVisualization(career_progression, subTitle) {
            const container = document.getElementById('visualization');
            container.innerHTML = "";
            const items = new vis.DataSet(career_progression);
            const options = { start: getEarliestStartDate(career_progression), end: getLatestEndDate(career_progression) };
            timeline = new vis.Timeline(container, items, options);

            const re = /\((.*)\)/i;
            $("#visualization .vis-item-content").each(function() {
                let classWithParens = $(this).html();//console.log(classWithParens);
                const getclass = classWithParens.match(re)[1];
                let doclass = '';
                let ftype = '';

                if(getclass === 'Individual K'){
                    doclass = 'class_c0';  ftype='K';
                } else if(getclass === 'Internal K'){
                    doclass = 'class_c1';  ftype='K';
                } else if(getclass === 'K12/KL2'){
                    doclass = 'class_c2';  ftype='K';
                } else if(getclass === 'K Equivalent'){
                    doclass = 'class_c3';   ftype='K';
                } else if(getclass === 'R01'){
                    doclass = 'class_c4';  ftype='R';
                } else if(getclass === 'R01 Equivalent'){
                    doclass = 'class_c5'; ftype='R';
                } else if(getclass === 'Training Appointment'){
                    doclass = 'class_c6';   ftype='TA';
                } else if(getclass === 'Research Fellowship'){
                    doclass = 'class_c7';  ftype='RF';
                } else if(getclass === 'Training Grant Admin'){
                    doclass = 'class_c8';  ftype='TG';
                } else if(getclass === 'K99/R00'){
                    doclass = 'class_c9';   ftype='KR';
                } else if(getclass === 'N/A'){
                    doclass = 'class_c10';   ftype='N';
                } else {
                    doclass = 'class_grey';  ftype='?';
                }

                const tawardno = $(this).html();
                const tawardno1 = tawardno.split(" ")[0];
                $(this).addClass(doclass);
                $(this).closest('.vis-item').click(function() {
                    domodal(tawardno1);
                    //$(this).attr('onclick','domodal(\'+$(this).html()+'\')');
                });
            });
            const title = '<h2 style="text-align: center;margin: auto;padding: 30px 0 0 0;font-weight: 700;color: #00000078;"><?= $awardDescript ?> Awards</h2>'+subTitle;
            if ($('#visualizationTitle').length > 0) {
                $('#visualizationTitle').html(title);
            } else {
                $('#visualization').before('<div id="visualizationTitle">'+title+'</div>');
            }
        };



        <?php

        echo "</script>";

        $skip = array("redcap_type", "start_date", "end_date");
        foreach ($listOfAwards as $awardno => $award) {
            foreach ($toImport as $index => $ary) {
                $action = $ary[0];
                $award2 = $ary[1];
                $different = false;
                foreach ($award2 as $type2 => $value2) {
                    $awardValue = preg_replace("/qqqqq/", "'", $award[$type2]);
                    $award2Value = preg_replace("/qqqqq/", "'", $value2);
                    if (!in_array($type2, $skip)) {
                        if ($award2Value != $awardValue) {
                            $different = true;
                            break;
                        }
                    }
                }
                if (!$different) {
                    foreach ($skip as $field) {
                        $listOfAwards[$awardno][$field] = $award2[$field];
                    }
                }
            }
        }

        echo "<div class='bwrap'><div class='middle'>";
        if (isset($_GET['new'])) {
            $title = "New Awards (<span id='allPossibleAwards'></span>)";
        } else {
            $title = "Auto-Processed Awards (<span id='allPossibleAwards'></span>)";
        }
        echo "<div style='font-size: 14px;letter-spacing: -0.4px;text-align: center;line-height: 16px;padding: 20px;'>These awards will be given normal weight when the computer automatically figures out career-defining awards.</div><h2 style='text-align: center;margin: auto;padding: 0;font-weight: 700;color: #00000078;'>$title</h2>";
        echo "<div id='llist' class='list-group col'>";

        $i = 0;
        $awardsSeen = array();
        $awardTypes = Grant::getAwardTypes();
        foreach ($awardTypes as $type => $num) {
            foreach ($listOfAwards as $idx => $award) {
                if (
                    ($award['redcap_type'] == $type)
                    && !isAwardInToImport($toImport, $award)
                ) {
                    if (isOkToShow($award, $idx, $listOfAwards)) {
                        $seenStatement = "";
                        if ($awardsSeen[generateAwardIndex($award['sponsor_award_no'], $award['sponsor'])]) {
                            $seenStatement = " (duplicate)";
                        }
                        $awardsSeen[generateAwardIndex($award['sponsor_award_no'], $award['sponsor'])] = 1;

                        echo "<li class='list list-group-item'>";
                        echo "<input type='hidden' id='listOfAwards_$i' value=''>";
                        echo "<script>\n";
                        $awardJSON = json_encode($award);
                        echo "const award_$i = $awardJSON;\n";
                        if (preg_match("/____/", generateAwardIndex($award['sponsor_award_no'], $award['sponsor']))) {
                            echo "$('#listOfAwards_".$i."').val(award_".$i."['sponsor']+'____'+award_".$i."['sponsor_award_no']);\n";
                        } else {
                            echo "$('#listOfAwards_".$i."').val(award_".$i."['sponsor_award_no']);\n";
                        }
                        echo "</script>\n";
                        echo transformAward($award, $i, $pid, $flaggedGrants);

                        echo "<script>$(document).ready(() => {";
                        if (in_array($award['sponsor_award_no'], $inUse)) {
                            echo "$('#add_$i').hide();";
                        } else {
                            echo "$('#remove_$i').hide();";
                        }
                        echo "$('#left_$i').hide();";
                        echo "$('#change_$i').hide();";
                        echo "});</script>";

                        echo "</li>";
                    }
                    $i++;
                }
            }
        }
        echo "</div></div>";

        # key = award number
        # value = array [ ADD/REMOVE/A_CHANGE/R_CHANGE, award ]
        $toImport = json_decode($row["summary_calculate_to_import"], true);
        echo "<div class='right'>";
        echo "<div style='font-size: 14px;letter-spacing: -0.4px;text-align: center;line-height: 16px;padding: 20px;'>Adjusted / Preferred awards will be adapted next time the computer automatically figures out career-defining awards.</div><h2 style='text-align: center;margin: auto;padding: 0px;font-weight: 700;color: #00000078; padding-right: 16px;'>Adjusted / Preferred Awards (<span id='awardsToPrefer'></span>)</h2>";
        echo "<div id='toImportDiv'>";
        if (empty($toImport)) {
            echo "<ul id='ldrop' class='list-group col' style='list-style: none;'>";
            echo "</ul>";
        } else {
            echo "<ul id='ldrop' class='list-group col' style='list-style: none;'>";
            foreach ($toImport as $index => $ary) {
                $action = $ary[0];
                $award = $ary[1];
                $awardno = $award['sponsor_award_no'] ?? $award['original_award_number'] ?? JS_UNDEFINED;
                echo "<li class='list-group-item'>".transformAward($award, $i, $pid, $flaggedGrants)."</li>";
                echo "<script>$(document).ready(() => {";
                echo "$('#add_$i').hide();";
                echo "$('#remove_$i').hide();";
                echo "$('#left_$i').show();";
                echo "$('#change_$i').hide();";
                echo "});</script>";
                $i++;
            }
            echo "</ul>";

        }
        echo "</div></div>";

        echo "<form id='tmainform' action='$nextPageLink' method='POST'>";
        echo "<input type='hidden' name='toImport' id='toImport' value=''>";
        echo "<input type='hidden' id='origToImport' value=''>";
        echo "<input type='hidden' name='record' id='record' value='$record'>";
        $csrfToken = Application::generateCSRFToken();
        echo "<input type='hidden' name='redcap_csrf_token' id='redcap_csrf_token' value='$csrfToken'>";
        echo "</form>";
        echo "</div>";
?>
    <script>
        $(document).ready(() => {
            const s = <?= $row["summary_calculate_to_import"] ?: "[]" ?>;
            $('#tmainform #toImport').val(JSON.stringify(s));
            $('#tmainform #origToImport').val(JSON.stringify(s));
        });

        $('.dates').keydown(function(e) {
            var ob = this;
            setTimeout(function() {
                var val = $(ob).val();
                if (val === "") {
                    $(ob).removeClass("yellow");
                } else if (val.match(/^\d\d-\d\d-\d{4}$/)) {
                    $(ob).removeClass("yellow");
                } else {
                    $(ob).addClass("yellow");
                }
            }, 100);
        });
        $('.dates').blur(function(e) {
            var ob = this;
            setTimeout(function() {
                var val = $(ob).val();
                if ((val !== "") && (!val.match(/^\d\d-\d\d-\d{4}$/))) {
                    alert("This value is not a valid date (MM-DD-YYYY) and cannot be used by REDCap!");
                }
            }, 100);
        });
    </script>
</div>

<style>
.dropzonep{
	border-top: dashed !important;
	border-left: dashed !important;
	border-bottom: dashed !important;
	border-right: dashed !important;

    border-color: #000000 !important;
    opacity: 0.35;
}

h3.withFlag {
    display: inline-block !important;
    text-align: left !important;
    padding-left: 32px !important;
    width: calc(100% - 21px);
}

span.flag {
    font-size: 20px;
    width: 20px;
}

.w3-dropdown-content {
    z-index: 2 !important;    /* to show in front of today's line on timeline */
}

.vis-item {
    z-index: unset !important;    /* otherwise shows in front of menus */
}

#llist .list-group-item, #ldrop .list-group-item {
    position: unset;
    display: unset;
    padding: unset;
    margin-bottom: unset;
    background-color: unset;
    border: unset;
}
.remove, .add, .change, .moveleft {
    text-align: center;
    background-color: unset;
    font-weight: bold;
    color: unset;
    font-size: 16px;
    display: flex;
    margin: unset;
    letter-spacing: -0.7px;
}
.sweet-modal-buttons a, .tbutton{
    color: #ffffff;
    margin: 10px auto 9px auto;
    border-radius: 3px;
    padding: 5px 13px;
    display: block;
    text-align: center;
    text-decoration: none;
    font-size: 13px !important;
    text-transform: lowercase;
    font-weight: 400;
    letter-spacing: -.2px;
    font-family: europa, Arial, Helvetica, sans-serif;
    border: 0 !important;
}
.refreshButton {
    background-color: #71a3d4;
}
.setNAButton {
    background-color: #ca4a4aff;
    font-size: 14px !important;
    border: 0 !important;
    font-weight: 400;
    color: #ffffffff;
    letter-spacing: -.2px;
    border-radius: 3px;
    padding: 5px 7px;
    opacity: 1.0 !important;
}
.sweet-modal-buttons a:hover, .tbutton:hover{
	color: #ffffff;
}
.sweet-modal-buttons{
	text-align: center;
}
.addbutton{
	background-color: #71a3d4;
}
.removebutton{
background-color: #ca4a4a;
}
.changebutton{
background-color: #f1b217;
}

a.preremove::after {
	display: none !important;
    margin-right: -158px;
    content: '.';
    width: 117px;
    float: right;
    opacity: 0.5;
    padding-right: 0;
    background-size: contain;
    background-repeat: no-repeat;
}

.thestatus{
    font-weight: 700;
    opacity: 0.2;
    display: inline-block;
    width: 100%;
    text-align: center;
    margin-bottom: 5px;
    margin-top: -5px;
    }

.sect{
    margin-bottom: 8px;
   }
.dates {
    font-size: 12px !important;
    width: 75px;
    background: unset !important;
    border: 0;
    color: unset;
    margin-left: -7px;
}

.display_data{
	color: #000000;
	font-size: 13px;
	min-height: 14px;
}

.fwb{
    font-weight: bold;
    font-size: 13px;
    letter-spacing: -0.3px;
}
.align-self-center .fwb{
    font-size: 13px;
}

.fwb a{
    color: #0056b3;
}

tbody .small_padding td{
    padding-left: 12px !important;
    padding-right: 12px !important;
}
tbody .small_padding:first-of-type td{
    padding-left: 24px !important;
    padding-right: 24px !important;
}
.fl{
	float: left;
}
.fr{
	float: right;
}
.wd49{
	width: 49%;
}

.align-self-center{
	text-align: center;
}
.removeaward
{
	display: none;
    height: 40px;
    float: right;
  	filter: drop-shadow(0.35rem 0.35rem 0.4rem rgba(0, 0, 0, 0.5));    margin-right: -34px;
}

#content h1 {
    text-align: left;
    display: inline-block;
    margin-top: 1em;
    margin-left: 2.4em;
    font-family: europa, Arial, Helvetica, sans-serif;
    font-size: 23px;
    letter-spacing: -0.5px;
    color: #818181;
    margin-bottom: 0;

}
#content h1 span {
	font-weight: 100;
}

#content h2::before {
    border-top: 0 !important;
}
#content h2 {
    text-align: left;
    margin-left: 2.4em;
    font-family: europa, Arial, Helvetica, sans-serif;
    background-color: unset;
    margin-top: 0px;
    font-weight: 100;
    letter-spacing: -1px;
}
#content h3 {
    text-align: center;
    margin-left: 0;
    font-family: europa, Arial, Helvetica, sans-serif;
    margin-top: 0px;
    font-weight: 700;
    letter-spacing: -1px;
    background-color: unset;
}
#content h2 span{
	font-weight: 700;
}
.sweet-modal-content{
    font-family: europa, Arial, Helvetica, sans-serif !important;
}
.display_data a{
	    color: #0056b3;
    text-decoration: underline;
}
#dsearch{
	float: right;
	width: 600px;
	font-size: 13px;
    letter-spacing: 0.5px;
    margin-right: 2em;
    margin-top: -3em;
    z-index: 1;
    position: relative;
}
#dsearch .tsearch{
	width: 30%;
	float: right;
}

#dsearch .tsearch input[type=text] {
    width: 95% !important;
}

#dsearch .tsearch select{
    font-family: europa, Arial, Helvetica, sans-serif;
    font-size: 10px;
    color: #a2a2a2;
    border-color: #e8e8e8;
    margin-top: -6px;
    letter-spacing: 0.5px;
    border-radius: 1px;
    padding: 2px;
}
#dsearch .tsearch input{
    font-family: europa, Arial, Helvetica, sans-serif;
    font-size: 10px;
    color: #a2a2a2;
    letter-spacing: 0.5px;
    border-radius: 1px;
    padding: 2px;
    width: 95%;
    border: 1px solid #e8e8e8;

}
.tor{
	width: 32px;
    text-align: center;
    float: right;
    text-transform: uppercase;
    font-size: 9px;
    font-weight: 700;
    margin-top: 20px;
}
.tflag {
    width: 72px;
    text-align: left;
    float: right;
    font-size: 10px;
    margin-top: 0;
}

#dsearch .tsearch a.button, #dsearch .tflag a.button {
	background-color: #71a3d4;
    color: #ffffff;
    border-radius: 3px;
    padding: 5px;
    margin-top: 10px;
    display: block;
    text-align: center;
    text-decoration: none;
    letter-spacing: 0;
    font-size: 13px;
    margin-right: 4px;
    margin-left: 4px;
}
.tgrantse{
	width: 100%;
    display: inline-block;
    margin-top: 1em;
}
.tgrantse a {
    width: 20%;
    float: right;
    color: #71a3d4;
    text-align: center;
    letter-spacing: 0;
    font-size: 13px;
}

.dateEntryDiv {
    height: 21px;
    max-width: 49%;
    background-color: #55555536;
    padding: 1px 0;
}

table.awardt{
	position: relative;
}
.bwrap{
	    margin: auto;
    text-align: center;margin-bottom: 8em;
    max-width: 1000px;
}
.class_c0>div {content: 'K';}
.class_c1>div {content: 'K';}
.class_c2>div {content: 'K';}
.class_c3>div {content: 'K';}
.class_c4>div {content: 'R';}
.class_c5>div {content: 'R';}
.class_c6>div {content: 'TA';}
.class_c7>div {content: 'RF';}
.class_c8>div {content: 'TG';}
.class_c9>div {content: 'K';}
.class_c10>div {content: 'N';}

table.awardt tbody{
	position: relative;
	z-index: 1;
}
div.at>div{
    font-weight: 900;
    opacity: 0.1;
    top: -4px;
    display: inline-block;
    margin-top: -332px;
    font-size: 261px;
    position: absolute;
    left: 200px;
    letter-spacing: -25.5px;
}
.class_grey {
    border: 2px solid #7b7b7b;
    padding: 6px;
    background-color: #7b7b7b36;
}
.class_orange {
    border: 2px solid #7b7b7b;
    padding: 6px;
    background-color: #eeeeee;
}
.class_c0{
    border: 2px solid #9ecde1;
    padding: 6px;
    background-color: #9ecde150;
}
.class_c1{
    border: 2px solid #9db9e1;
    padding: 6px;
    background-color: #9db9e150;
}
.class_c2{
    border: 2px solid #9ba3e2;
    padding: 6px;
    background-color: #9ba3e250;
}
.class_c3{
    border: 2px solid #c19de3;
    padding: 6px;
    background-color: #c19de350;
}
.class_c4{
    border: 2px solid #d79ee3;
    padding: 6px;
    background-color: #d79ee350;
}
.class_c5{
    border: 2px solid #e39fda;
    padding: 6px;
    background-color: #e39fda50;
}
.class_c6{
    border: 2px solid #de9ec3;
    padding: 6px;
    background-color: #de9ec350;
}
.class_c7{
    border: 2px solid #e49fb1;
    padding: 6px;
    background-color: #e49fb150;
}
.class_c8{
    border: 2px solid #e39f9e50;
    padding: 6px;
    background-color: #e39f9e50;
}
.class_c9{
    border: 2px solid #e5b39c;
    padding: 6px;
    background-color: #e5b39c50;
}
.class_c10{
    border: 2px solid #e2c9a0;
    padding: 6px;
    background-color: #e2c9a050;
}
.class_empty{
    border: 2px solid #aeaeae;
    padding: 6px;
    background-color: #55555517;
}

#visualization .vis-time-axis {
    background-color: white;
}

#visualization .vis-item-content{
	padding: 0 2px 0 5px;
    border: 1px;
}
.at {
    border: 0px;
    background-color: unset;
}
#visualization{
    font-size: 13px;
    width: 80%;
    margin: auto auto 3em auto;
    border-bottom: 0px;
}
table .small_padding td {
    font-size: 13px;
    text-align: left;
    width:  unset !important;
    background-color: unset !important;
    padding:  unset !important;
    padding-left: 12px !important;
    padding-right: 12px !important;
}
table .small_padding td {
    background-color: unset !important;
    padding: 0px;
}
.trow{
	    width: 80%;
    margin: auto;
}
div.middle, div.right {
     border-right: 0px;
     border-left: 0px;
    width: 50% !important;
    }

.vis-group{background-color: #efefef75;}
.yellow{background-color: #efad12;}
.greenB{background-color: #444444;}
.redB{background-color: #444444;}

.sweet-modal-buttons a.greenB.button, .sweet-modal-buttons button.greenB {
     background: #17ad2c;
     border-color: #ffffff00;
}
.blue {
    background-color: #7eb6cc;
}
@keyframes fadeIn {
  from { opacity: 0.3; }
}

.bubble {
    width:165px;
    height:165px;
    border-radius:200px;
    border:1px solid #eeeeee;
    position: fixed;
    top: 130px;
    font-size: 12px;transform: rotate(-3deg);

    color: #555;
    text-align: center;

    animation: fadeIn 2s infinite alternate;
    background-color:#d4d4eb;
    z-index: 100;
    <?= $_COOKIE[$cookieName] ? "display: none;" : "" ?>
}

.closeX {
    top: 130px;
    font-size: 10px;
    text-decoration: none;
    text-align: right;
    width: 165px;
    color: black;
    position: fixed;
    z-index: 100;
    <?= $_COOKIE[$cookieName] ? "display: none;" : "" ?>
}

.closeX a {
    text-decoration: none;
    color: black !important;
}


.bubble>span{
  margin-top:30px;color:#000000;display:inline-block;margin-left:10px;margin-right:10px;line-height:13px;
}


/* default css for layering duplicates */

.list-group-item{
	width: 378px;
	background-color: #ffffff !important;
    margin: 12px auto -12px auto;
}
.awardlayers{
	position: relative !important;
	top: -345px;
    left: 24px;
    z-index: 1;
}
.awardlayers + .list-group-item:not(.awardlayers){
	margin-top: -345px;
}

</style>

<div class="closeX"><a href="javascript:;" onclick="hideBubble();" title="Close Bubble for One Week">X</a></div>
<p class='bubble'><span>To <strong>update an award</strong>, simply 'drag' the award to the appropriate column ('Auto-Processed Awards' or 'Adjusted / Preferred Awards') once you've reviewed or updated the award.</span></p>
<script type="text/javascript">

function isInTrainingMode() {
    return $('.bubble').is(":visible");
}

function find_duplicate_in_array(array1) {
    const object = {};
    const result = [];

    array1.forEach(function (item) {
        if(!object[item])
            object[item] = 0;
        object[item] += 1;
    })

    for (let prop in object) {
        if(object[prop] >= 2) {
            result.push(prop);
            //$('.tlayer_'+prop).addClass('awardlayers');
        }
    }
    return result;
}

$(document).ready(function() {
	$('select#refreshRecord > option:first-child').text('---- select a scholar ----');
	$('.awardt').each(function( index, element ){
		$(this).addClass('temp'+index);
    });
    $('#allPossibleAwards').html($(".middle .list").length);
    $('#awardsToPrefer').html($(".right #toImportDiv li").length);

///*   code for layering duplicate entries

	$('.middle table.awardt').addClass('tmiddle');

	const groups = [];
	$('table.tmiddle').each(function( index, element ){
		groups.push($(this).attr('group'));
	});

	$(find_duplicate_in_array(groups)).each(function( index, element ){
		if(groups === undefined || groups.length == 0){
			$('.tlayer_'+element+'').closest('.list-group-item').addClass('awardlayers').click(function() {
				$('.tlayer_'+element+'').closest('.list-group-item.awardlayers').css('z-index','1');
				$(this).css('z-index','2');
			});
			$('.tlayer_'+element+':first').closest('.list-group-item').css('top','0px').css('left','0px').css('position','absolute');
		}
	});


	window.alert = function(x) {
        $.sweetModal({
            content: x,
        });
    }

	const llist = document.getElementById('llist');
    const ldrop = document.getElementById('ldrop');

	new Sortable(ldrop, {
		group: 'shared',
		animation: 150,
		dragoverBubble: true,
		onRemove: function (evt) {
            resetCounts();
            changeChangeText(getIFromObj(evt.item));
            if (isInTrainingMode()) {
                alert('This award is <strong>removed</strong> from your "Adjusted / Preferred Awards" list. Re-calculating awards in background...');
            }
		},
		onEnd: function (evt) {
			const itemEl = evt.item;
            changeChangeText(getIFromObj(itemEl));
            resetCounts();
            new Function($(itemEl).find('.thebuttons .removebutton').attr('onclick'))();
		},
	});
	new Sortable(llist, {
		group: 'shared', // set both lists to same group
		animation: 150,
		dragoverBubble: true,
		onRemove: function (evt) {
            resetCounts();
            changeChangeText(getIFromObj(evt.item));
            if (isInTrainingMode()) {
                alert('This award is <strong>added</strong> from your "Adjusted / Preferred Awards" list. Re-calculating awards in background...');
            }
		},
		onEnd: function (evt) {
			const itemEl = evt.item;
            ldrop.append(itemEl);
            changeChangeText(getIFromObj(itemEl));
            resetCounts();
            new Function($(itemEl).find('.thebuttons .addbutton').attr('onclick'))();
		},
	});

	$('.middle, .right').equalHeights();
	$('.adate').inputmask("99-99-9999");
});

function resetCounts() {
    $('#allPossibleAwards').html($(".middle .list-group-item").length);
    $('#awardsToPrefer').html($(".right #toImportDiv .list-group-item").length);
}
</script>

<?php

function getClassAndFType($type) {
    if($type == 'Individual K'){
        $doclass = 'class_c0';  $ftype='K';
    } elseif($type == 'Internal K'){
        $doclass = 'class_c1';   $ftype='K';
    } else if($type == 'K12/KL2'){
        $doclass = 'class_c2';  $ftype='K';
    } else if($type == 'K Equivalent'){
        $doclass = 'class_c3';   $ftype='K';
    } else if($type == 'R01'){
        $doclass = 'class_c4';  $ftype='R';
    } else if($type == 'R01 Equivalent'){
        $doclass = 'class_c5';   $ftype='R';
    } else if($type == 'Training Appointment'){
        $doclass = 'class_c6';   $ftype='TA';
    } else if($type == 'Research Fellowship'){
        $doclass = 'class_c7';   $ftype='RF';
    } else if($type == 'Training Grant Admin'){
        $doclass = 'class_c8';  $ftype='TG';
    } else if($type == 'K99/R00'){
        $doclass = 'class_c9';    $ftype='KR';
    } else if($type == 'N/A'){
        $doclass = 'class_c10';   $ftype='N';
    } else {$doclass = 'class_grey';  $ftype='?';}
    return [$doclass, $ftype];
}

function getEarliestStartDate($listOfAwards) {
    $oneDay = 24 * 3600;
    $startTs = time();
    foreach ($listOfAwards as $key => $award) {
        if ($award['start_date']) {
            $ts = strtotime($award['start_date']);
            if ($ts < $startTs) {
                $startTs = $ts;
            }
        }
    }
    return date("Y-m-d", $startTs - getSpacingDays() * $oneDay);
}

function getSpacingDays() {
    return 90;
}

function getLatestEndDate($listOfAwards) {
    $oneDay = 24 * 3600;
    $endTs = time();
    foreach ($listOfAwards as $key => $award) {
        if ($award['end_date']) {
            $ts = strtotime($award['end_date']);
            if ($ts > $endTs) {
                $endTs = $ts;
            }
        }
    }
    return date("Y-m-d", $endTs + getSpacingDays() * $oneDay);
}

function makeStatusButtons($i, $award) {
    foreach ($award as $key => $value) {
        $award[$key] = preg_replace("/'/", "qqqqq", $value);
    }

    $awardJSON = json_encode($award);
    return "<div class='thebuttons'>".
        "<div id='add_$i' class='add'><span class='thestatus'>[ drag right to prefer ]</span></div>".
        "<a style='display:none;' class='tbutton addbutton' href='javascript:;' onclick='addAward(\"redcap_type_$i\", $i, $awardJSON);'>process award</a>".
        "<div id='left_$i' class='moveleft'><span class='thestatus'>[ drag left to backtrack ]</span></div>".
        "<div id='remove_$i' class='remove'><span class='thestatus' style='margin-left: 5px; margin-right: 5px; width: 50%;'>[ currently used ]</span><span style='width: 50%; text-align: center;'><button class='setNAButton' onclick='$(\"select#redcap_type_$i option:last-child\").attr(\"selected\", true); const award = $awardJSON; prioritizeAward($i, award); removeAward($i, award); return false;'>don't use this</button></span></div>".
        "<a style='display:none;' class='tbutton removebutton' href='javascript:;' onclick='removeAward($i, $awardJSON);'>remove award</a>".
        "<div id='change_$i' class='change'><span class='thestatus'>[ data changes made ]</span></div>".
        "</div>";
}

function isAwardInToImport($toImport, $award) {
    $awardNo = $award['sponsor_award_no'] ?? JS_UNDEFINED;
    $startDate = $award['start_date'] ?? JS_UNDEFINED;
    $sponsor = $award['sponsor'] ?? JS_UNDEFINED;
	$sep = "____";
	$index = "$awardNo$sep$sponsor$sep$startDate";
    return isset($toImport[$index]);
}

function addSpacesIfRelevant(&$value) {
    if (!preg_match("/\s/", $value) && preg_match("/\(/", $value)) {
        $value = str_replace("(", " (", $value);
    }
}