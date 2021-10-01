<?php

# provides a means to reassign catgories, start/end dates, etc. for outside grants
# to be run on web

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\ExcludeList;
use \Vanderbilt\CareerDevLibrary\Grant;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/baseSelect.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$daysForNew = 60;
if (isset($_GET['new']) && is_numeric($_GET['new'])) {
	$daysForNew = (int) REDCapManagement::sanitize($_GET['new']);
}
$GLOBALS['daysForNew'] = $daysForNew;

$url = Application::link("wrangler/index.php");
if (isset($_GET['headers']) && ($_GET['headers'] == "false")) {
	$url .= "&headers=false";
}

$records = Download::recordIds($token, $server);
# done to support leading 0s, which apparently aren't picked up by the POST
$requestedRecord = "";
if ($_POST['record']) {
    $requestedRecord = REDCapManagement::sanitize($_POST['record']);
} else if ($_GET['record']) {
    $requestedRecord = REDCapManagement::sanitize($_GET['record']);
}
foreach ($records as $record) {
    if ($record == $requestedRecord) {
        $requestedRecord = $record;
    }
}

if (isset($_POST['toImport']) && isset($_POST['record'])) {
	$toImport = REDCapManagement::sanitize($_POST['toImport']);
	if (($toImport == "") || ($toImport == "[]")) {
		$toImport = "{}";
	}

	$data = array();
	$data['record_id'] = $requestedRecord;
	$data['summary_calculate_to_import'] = $toImport;

	$outputData = Upload::oneRow($data, $token, $server);
	if (!$outputData['error'] && !$outputData['errors']) {
		echo "<p class='green centered shadow note'>";
		if (isset($outputData['count']) || isset($outputData['item_count'])) {
			echo "Upload Success!";
            Application::refreshRecordSummary($token, $server, $pid, $requestedRecord);
        }
		echo "</p>\n";
	} else {
		echo "<p class='red centered'>Error! ".json_encode($outputData)."</p>";
	}
}

$record = 1;
$requestedRecord = FALSE;
if (isset($_GET['record']) && is_numeric($_GET['record'])) {
	$requestedRecord = REDCapManagement::sanitize($_GET['record']);
}
if (isset($_POST['refresh'])) {
	# override GET parameter
    $requestedRecord = REDCapManagement::sanitize($_POST['record']);
} else if (isset($_POST['empty']) && $_POST['empty_record'] && is_numeric($_POST['empty_record'])) {
    $requestedRecord = (int) REDCapManagement::sanitize($_POST['empty_record']);
}
if ($requestedRecord) {
    foreach ($records as $r) {
        if ($requestedRecord == $r) {
            $record = $r;
        }
    }
}

$myFields = array(
"record_id",
"summary_calculate_order",
"summary_calculate_list_of_awards",
"summary_calculate_to_import",
"identifier_last_name",
"identifier_first_name",
);

$redcapData = Download::fields($token, $server, $myFields);

function findTakeovers($toImport) {
	$takeovers = array();
	foreach ($toImport as $awardno => $ary) {
		$action = $ary[0];
		$award = $ary[1];
		if ($award['takeover']) {
			array_push($takeovers, $award['base_award_no']);
		}
	}
	return $takeovers;
}

function getTransformArray() {
	$ary = array();
	$ary['redcap_type'] = "REDCap Type";
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
	$ary['takeover'] = "Took Over From Previous PI";
    $ary['link'] = "Link";
    $ary['role'] = "Role";
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
			return Grant::translateToBaseAwardNumber($value);
		}
	}
}

# input all REDCap Data
# returns next record with new data
# else returns ""
function getNextRecordWithNewData($includeCurrentRecord) {
	global $daysForNew, $token, $server;

	$calculateFields = array(
				"record_id",
				"summary_calculate_order",
				"summary_calculate_list_of_awards",
				"summary_calculate_to_import",
				);
	$grantAgeFields = array("coeus_budget_end_date", "reporter_budgetenddate", "exporter_budget_end");

	$myFields = $calculateFields;
	$myFields[] = "identifier_first_name";
	$myFields[] = "identifier_last_name";
	$myFields[] = "reporter_last_update";
	$myFields[] = "exporter_last_update";
	$myFields[] = "coeus_last_update";
	$myFields[] = "custom_last_update";
	foreach ($grantAgeFields as $field) {
		array_push($myFields, $field);
	}
	$myFields = \Vanderbilt\FlightTrackerExternalModule\filterForCoeusFields($myFields);

	$records = Download::recordIds($token, $server);
	$pullSize = 10;

	$record = $_GET['record'];
	if (!$record) {
		$record = 1;
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
			array_push($pullRecords, $records[$j]);
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
					foreach ($listOfAwards as $key => $specs) {
						$specsMinAge = getMinAgeOfUpdate($specs);
						$grantAge = getAgeOfGrant($specs);    // ensure that not in the distant past
						if (
						        ($specsMinAge <= $daysForNew)
                                && ($grantAge <= $daysForNew)
                                && (findNumberOfSimilarAwards($specs['base_award_no'], $key, $listOfAwards) == 0)
                        ) {
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
function getMinAgeOfGrants($row, $grantAgeFields) {
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


function transformAward($ary, $takeovers, $i = -1, $selectName = "") {
    $skip = ["url"];
    $elems = [];
    $startName = preg_replace("/redcap_type/", "start_date", $selectName);
    $endName = preg_replace("/redcap_type/", "end_date", $selectName);
    $awardTypes = \Vanderbilt\FlightTrackerExternalModule\getAwardTypes();
    $transform = getTransformArray();
    foreach ($ary as $key => $value) {
        $elem = "<th><b>".$transform[$key]."</b></th>";
        if ($selectName && ($key == "redcap_type")) {
            $elem .= "<td><select id='$selectName' onchange='toggleChange(\"$value\", $(this), $i);'>";
            foreach ($awardTypes as $type => $num) {
                if ($value == $type) {
                    $elem .= "<option value='$type' selected>$type</option>";
                } else {
                    $elem .= "<option value='$type'>$type</option>";
                }
            }
            $elem .= "</select></td>";
            $elems[] = $elem;
        } else if ($selectName && ($key == "start_date")) {
            $elems[] = $elem."<td><input type='text' class='dates' onkeydown='$(\"#change_$i\").show();' id='$startName' value='$value'></td>";
        } else if ($selectName && ($key == "end_date")) {
            $elems[] = $elem."<td><input type='text' class='dates' onkeydown='$(\"#change_$i\").show();' id='$endName' value='$value'></td>";
        } else if (!in_array($key, $skip)) {
            $elems[] = $elem."<td>'$value'</td>";
        }
    }
    $backgroundClass = "small_padding";
    if ($ary['takeover'] || in_array($ary['base_award_no'], $takeovers)) {
        $backgroundClass = "tookover";
    }
    return "<table style='width: 100%;'><tr class='$backgroundClass'>".implode("</tr><tr class='$backgroundClass'>", $elems)."</tr></table>";
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

$links = array();

# used in JavaScript
$lastNames = array();
$fullNames = array();
foreach ($redcapData as $row) {
	if ($row['identifier_last_name']) {
		$lastNames[$row['record_id']] = strtolower($row['identifier_last_name']);
		$fullNames[$row['record_id']] = strtolower($row['identifier_first_name'])." ".strtolower($row['identifier_last_name']);
	}
}

$blanks = array("", "[]");
$nextPageLink = "";
$emptyRecord = "";
$links[] = Links::makeGrantWranglingLink($pid, "Grant Wrangler", $record, FALSE, "green");
$links[] = Links::makePubWranglingLink($pid, "Publication Wrangler", $record, FALSE, "green");
$links[] = Links::makePatentWranglingLink($pid, "Patent Wrangler", $record, FALSE, "green");
$links[] = Links::makeProfileLink($pid, "Scholar Profile", $record, FALSE, "green");
$links[] = "<a class='yellow'>".getSelectRecord()."</a>";
$links[] = "<a class='yellow'>".getSearch()."</a>";
$getClause = "";
if (isset($_GET['new'])) {
	$getClause = "&new=$daysForNew";
}
foreach ($redcapData as $row) {
	if (($row['record_id'] == $record + 1) && (!$nextPageLink))  {
		$nextPageLink = $url."&record=".($record+1).$getClause;
		$links[] = "<a class='purple' href='$nextPageLink'>View Next Record</a>";
	}
	if (($row['record_id'] > $record) && !$emptyRecord && (in_array($row['summary_calculate_order'], $blanks)) && !isset($_GET['new'])) {
		$links[] = "<a class='blue' href='".$url."&record=".($row['record_id'])."'>View Next EMPTY Record</a>";
		$emptyRecord = $row['record_id'];
	}
}
if (isset($_GET['new'])) {
	$nextNewRecord = getNextRecordWithNewData(FALSE);
	if ($nextNewRecord && ($nextNewRecord > $record)) {
		$links[] = "<a class='purple' href='".$url."&record=$nextNewRecord&new=$daysForNew'>View Next Record with 'New' Grants</a>";
	}
}

$links[] = Links::makeSummaryLink($pid, $record, REDCapManagement::getEventIdForClassical($pid), "Summary Data for REDCap Record ".$record, "orange");
$links[] = Links::makeCustomGrantLink($pid, $record, REDCapManagement::getEventIdForClassical($pid), "Add New Grants for REDCap Record ".$record, 1, "orange");
if (isset($_GET['new'])) {
	array_push($links, "<a class='orange' href='".$url."&record=$record'>See All Grants Here</a>");
} else if (!isset($_GET['headers']) || ($_GET['headers'] != "false")) {
	$nextNewRecord = getNextRecordWithNewData(TRUE);
	if ($nextNewRecord) {
		array_push($links, "<a class='orange' href='$url&record=$nextNewRecord&new=$daysForNew'>See Only New Grants Here</a>");
	}

}

if (!isset($_GET['headers']) || ($_GET['headers'] != "false")) { 
	echo "<div class='subnav'>\n"; 
	echo implode("\n", $links)."\n";
	echo "</div>\n";
	echo "<div id='content'>\n";
}
?>
<script>
function refreshToDays() {
	var days = $("#newDaysForNew").val();
	if (isNaN(days) || (days === "")) {
		days = '<?= $daysForNew ?>';
	}

	var rec = '<?= $record ?>';
	if (!rec) {
		rec = '1';
	}

	if (days != '<?= $daysForNew ?>') {
		window.location.href = 'index.php?pid=<?= $pid ?>&record='+rec+'&new='+days;
	}
}

function toggleChange(dflt, ob, i) {
	if (ob.val() != dflt) {
		$("#change_"+i).show();
	} else {
		$("#change_"+i).hide();
	}
}

function takeoverAward(i, award) {
	$('#takeover_'+i).hide();
	award['takeover'] = "TRUE";
	addToImport(award, "TAKEOVER");
}

function changeAward(selectName, i, award) {
	$('#change_'+i).hide();
	var currVal = $('#'+selectName).val();
	award['redcap_type'] = $('#'+selectName).val();
	var startName = selectName.replace(/redcap_type/, "start_date");
	var endName = selectName.replace(/redcap_type/, "end_date");
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
	addToImport(award, "REMOVE");
}

function addAward(selectName, i, award) {
	$('#remove_'+i).show();
	$('#add_'+i).hide();

	var type = award['redcap_type'];
	if ($('#redcap_type_'+i).length > 0) {
		type = $('#redcap_type_'+i).val();
	}
	award['redcap_type'] = type;

	var startName = selectName.replace(/redcap_type/, "start_date");
	var endName = selectName.replace(/redcap_type/, "end_date");

	var date = award['start_date'];
	if ($('#'+startName+'_'+i).length > 0) {
		date = $('#'+startName+'_'+i).val();
	}
	award['start_date'] = date;

	var endDate = award['end_date'];
	if ($('#'+endName+'_'+i).length > 0) {
		endDate = $('#'+endName+'_'+i).val();
	}
	award['end_date'] = endDate;

	addToImport(award, "ADD");
}

function transformAwardJS(award) {
    award = cleanAward(award);
    var html = "";
    var cnt = 0;
    for (var key in award) {
        cnt++;
    }

    var transform = <?= json_encode(getTransformArray()); ?>;

    var i = 0;
    for (var key in award) {
        var value = award[key];
        if (key != 'url') {
            html += "<tr class='small_padding'><th><b>"+transform[key]+"</b></th><td>'"+value+"'</td></tr>";
            i++;
        }
    }
    html = "<table style='width: 100%;'>"+html+"</table>";

    return html;
}

function find_i(awardno) {
	var i = 0;
	var listOfi = new Array();
	while ($('#listOfAwards_'+i).length) {
		var awardno_i = $('#listOfAwards_'+i).val();
		if (awardno_i == awardno) {
			listOfi.push(i); 
		}
		i++;
	}
	return listOfi;
}

function removeFromImport(awardno) {
	var toImport = $('#toImport').val();
	var tI = JSON.parse(toImport);
	var tI2 = {};
	for (var index in tI) {
		if (tI[index][1]['sponsor_award_no'] != awardno) {
			tI2[index] = tI[index];
		} else {
			var i_list = find_i(awardno);
			if (tI[index][0] == "ADD") {
				for (var j = 0; j < i_list.length; j++) {
					var i = i_list[j];
					$('#remove_'+i).hide();
					$('#add_'+i).show();
				}
			} else if (tI[index][0] == "REMOVE") {
				for (var j = 0; j < i_list.length; j++) {
					var i = i_list[j];
					$('#remove_'+i).show();
					$('#add_'+i).hide();
				}
			} else if (tI[index][0] == "A_CHANGE") {
				for (var j = 0; j < i_list.length; j++) {
					var i = i_list[j];
					$('#remove_'+i).hide();
					$('#add_'+i).show();
					$('#change_'+i).show();
				}
			} else if (tI[index][0] == "R_CHANGE") {
				for (var j = 0; j < i_list.length; j++) {
					var i = i_list[j];
					$('#remove_'+i).show();
					$('#add_'+i).hide();
					$('#change_'+i).show();
				}
			}
		}
	}
	tI = tI2;

	$('#toImport').val(JSON.stringify(tI));

	html = "<ul>";
	for (index in tI) {
		var myawardno = tI[index][1]['sponsor_award_no'];
		if (isOkToShowJS(tI[index])) {
			html += "<li><span class='"+tI[index][0].toLowerCase()+"'><b>"+myawardno+"</b>: "+tI[index][0]+"</span> <a href='javascript:;' onclick='removeFromImport(\""+myawardno+"\");'>Remove from List</a>"+transformAwardJS(tI[index][1])+"</li>";
		}
	}
	html += "</ul>";
	$('#toImportDiv').html(html);
	toggleButtons();
}

function isOkToShowJS(row) {
	var ary = row[1];
<?php
	echo "\tvar isNew = ";
	if (isset($_GET['new'])) {
		echo "true";
	} else {
		echo "false";
	}
	echo ";\n";
	echo "\tvar daysForNew = ".$daysForNew.";\n";
?>
	if ((typeof ary['last_update'] != "undefined") && (typeof ary['end_date'] != "undefined") && (isNew)) {
		var dateLast = ary['last_update'];
		var dateEnd = ary['end_date'];
		if ((dateLast === "") || (dateEnd === "")) {
			return false;
		}
		var dLast = new Date(dateLast);
		var dEnd = new Date(dateEnd);
		var dLast_time = dLast.getTime();
		var dEnd_time = dEnd.getTime();
		var now = new Date();
		var now_time = now.getTime();
		if ((now_time - dLast_time <= daysForNew * 24 * 3600 * 1000)
			&& (now_time - dEnd_time <= daysForNew * 24 * 3600 * 1000)) {
			return true;
		}
		return false;
	}
	return true;
}

function toggleButtons() {
	console.log("toggleButtons");
	if ($('#toImport').val() == $('#origToImport').val()) {
		$('#enactRefresh').hide();
		$('#enact').hide();
		$('#enactEmpty').hide();
		$('#enactMDOnly').hide();
	} else {
		$('#enactRefresh').show();
		$('#enact').show();
		$('#enactEmpty').show();
		$('#enactMDOnly').show();
	}
}

function cleanAward(award) {
	for (var key in award) {
		if (typeof award[key] != "undefined") {
			award[key] = award[key].replace(/qqqqq/, "'");
		}
	}
	return award;
}

function getIndex(awardno, sponsor, startDate) {
	var sep = "____";
	return awardno+sep+sponsor+sep+startDate;
}

function addToImport(award, action) {
	var awardno = award['sponsor_award_no']
	if (award['redcap_type'] == 'N/A') { 
		alert("Adding a redcap_type of N/A");
	}
	var toImport = $('#toImport').val();
	var tI = JSON.parse(toImport);
	var index = getIndex(awardno, award['sponsor'], award['start_date']);
	console.log("index: "+index);
	if (typeof tI[index] == "undefined") {
		var tI2 = {};
		tI2[index] = new Array(action, award);
		for (var index2 in tI) {
			var re = RegExp("^"+award['sponsor_award_no']);
			if ((tI[index2]['sponsor_award_no'] == '000') || !index2.match(re)) {
				// copy over
				tI2[index2] = tI[index2];
			}
		}
		tI = tI2;
	} else {
		tI[index][0] = action;
		tI[index][1] = award;
	}
	console.log(JSON.stringify(tI));
	$('#toImport').val(JSON.stringify(tI));

	var pretag_open = "";
	var pretag_close = "";
	if (awardno == '000') {
		pretag_open = "<span class='000'>";
		pretag_close = "</span>";
	}
	html = "<ul>";
	for (index in tI) {
		var awardno = tI[index][1]['sponsor_award_no'];
		if (isOkToShowJS(tI[index])) {
			html += "<li><span class='"+tI[index][0].toLowerCase()+"'><b>"+pretag_open + awardno + pretag_close+"</b>: "+tI[index][0]+"</span> <a href='javascript:;' onclick='removeFromImport(\""+awardno+"\");'>Remove from List</a>"+transformAwardJS(tI[index][1])+"</li>";
		}
	}
	html += "</ul>";
	$('#toImportDiv').html(html);
	toggleButtons();
}
</script>
<?php
$excludeList = new ExcludeList("Grants", $pid);
foreach ($redcapData as $row) {
	if (($row['record_id'] == $record) && ($row['redcap_repeat_instrument'] == "")) {
		$takeovers = array();
		if (isset($_GET['new'])) {
			echo "<h1>";
			echo "<span class='red'>NEW</span> Grant Wrangler";
			echo "<div class='h3'>";
			echo "Last <input type='text' id='newDaysForNew' style='font-size:22px; width:50px;' onblur='refreshToDays();' value='$daysForNew'> Days";
			echo "<div style='text-align: center; background-color: white; font-weight: normal; font-size:11px;'>(You can change the number of days by editing the box and then by clicking outside of the box.)</div>";
			echo "</div>";
			echo "</h1>";
		} else {
			echo "<h1>Grant Wrangler</h1>";
		}
		echo $excludeList->makeEditForm($record);

		if (!isset($_GET['headers']) || ($_GET['headers'] != "false")) {
			echo \Vanderbilt\FlightTrackerExternalModule\makeHelpLink();
			echo "<h2>{$record}: {$row['identifier_first_name']} {$row['identifier_last_name']}</h2>";
		}

		foreach ($row as $field => $value) {
			if (($value === "") && preg_match("/^summary_calculate_to_import/", $field)) {
				$row[$field] = "{}";
			} else if (($value === "") && preg_match("/^summary_calculate_/", $field)) {
				$row[$field] = "[]";
			}
		}
		$order = json_decode($row["summary_calculate_order"], true);
		$inUse = array();
		echo "<div class='left'>";
		echo "<h3>Career Progression</h3>";
		if (empty($order)) {
			echo "<p>No awards.</p>";
		} else {
			echo "<ol>";
			foreach ($order as $award) {
				echo "<li>".transformAward($award, $takeovers)."</li>";
				$inUse[] = $award['sponsor_award_no'];
			}
			echo "</ol>";
		}
		echo "</div>";

		$toImport = json_decode($row["summary_calculate_to_import"], true);
		$takeovers = findTakeovers($toImport);
		$listOfAwards = json_decode($row["summary_calculate_list_of_awards"], true);
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

		echo "<div class='middle'>";
		if (isset($_GET['new'])) {
			echo "<h3>List of All New Awards</h3>";
		} else {
			echo "<h3>List of All Possible Awards</h3>";
		}


		$i = 0;
		$awardsSeen = array();
		$awardTypes = \Vanderbilt\FlightTrackerExternalModule\getAwardTypes();
		foreach ($awardTypes as $type => $num) {
			foreach ($listOfAwards as $idx => $award) {
				if ($award['redcap_type'] == $type) {
					if (isOkToShow($award, $idx, $listOfAwards)) {
						$seenStatement = "";
						if ($awardsSeen[generateAwardIndex($award['sponsor_award_no'], $award['sponsor'])]) {
							$seenStatement = " (duplicate)";
						}
						$awardsSeen[generateAwardIndex($award['sponsor_award_no'], $award['sponsor'])] = 1;

						echo "<div class='list'>";
						echo "<input type='hidden' id='listOfAwards_$i' value=''>";
?>
<script>
	var award_<?= $i ?> = <?= json_encode($award) ?>;
<?php
	if (preg_match("/____/", generateAwardIndex($award['sponsor_award_no'], $award['sponsor']))) {
		echo "$('#listOfAwards_".$i."').val(award_".$i."['sponsor']+'____'+award_".$i."['sponsor_award_no']);\n";
	} else {
		echo "$('#listOfAwards_".$i."').val(award_".$i."['sponsor_award_no']);\n";
	}
?>
</script>
<?php
						$awardHTML = array();
						foreach ($award as $key => $value) {
							$awardHTML[$key] = preg_replace("/'/", "qqqqq", $value);
						}

						echo "<div id='add_$i' class='add'><a href='javascript:;' onclick='addAward(\"redcap_type_$i\", $i, ".json_encode($awardHTML).");'>Click to Prepare to Add</a>$seenStatement</div>";
						echo "<div id='remove_$i' class='remove'><a href='javascript:;' onclick='removeAward($i, ".json_encode($awardHTML).");'>Click to Prepare to Remove</a>$seenStatement</div>";
						echo "<div id='change_$i' class='change'><a href='javascript:;' onclick='changeAward(\"redcap_type_$i\", $i, ".json_encode($awardHTML).");'>Click to Prepare to Change</a>$seenStatement</div>";
						// echo "<div id='takeover_$i' class='takeover'><a href='javascript:;' onclick='takeoverAward($i, ".json_encode($awardHTML).");'>Click to Note a PI-Take-Over at this Date</a>$seenStatement</div>";
						echo "<script>";
						if (in_array($award['sponsor_award_no'], $inUse)) {
							echo "$('#add_$i').hide();";
						} else {
							echo "$('#remove_$i').hide();";
						}
						echo "$('#change_$i').hide();";
						echo "</script>";
						echo transformAward($award, $takeovers, $i, "redcap_type_".$i);
						echo "</div>";
					}
					$i++;
				}
			}
		}
		echo "</div>";

		# key = award number
		# value = array [ ADD/REMOVE/A_CHANGE/R_CHANGE, award ]
		$toImport = json_decode($row["summary_calculate_to_import"], true);
		echo "<div class='right'>";
		echo "<h3>Awards to Handle Manually</h3>";
		echo "<div id='toImportDiv'>";
		if (empty($toImport)) {
			echo "<p>None.</p>";
		} else {
			echo "<ul>";
			foreach ($toImport as $awardno => $ary) {
				$action = $ary[0];
				$award = $ary[1];
				echo "<li><span class='".strtolower($action)."'><b>$awardno</b>: $action</span>  <a href='javascript:;' onclick='removeFromImport(\"$awardno\");'>Remove from List</a>".transformAward($award, $takeovers)."</li>";
			}
			echo "</ul>";
		}
		echo "</div>";

		echo "<form action='$nextPageLink' method='POST'>";
		echo "<input type='hidden' name='toImport' id='toImport' value=''>";
		echo "<input type='hidden' id='origToImport' value=''>";
		echo "<input type='hidden' name='record' id='record' value='$record'>";
		echo "<input type='hidden' name='empty_record' id='record' value='$emptyRecord'>";
		if (isset($_GET['headers']) && ($_GET['headers'] == "false")) {
			echo "<p style='text-align: center;'><input type='submit' class='yellow' style='display: none; font-size: 20px;' name='refresh' id='enactRefresh' value='Commit Change & Refresh'></p>";
		} else {
			echo "<p style='text-align: center;'><input type='submit' class='yellow' style='display: none; font-size: 20px;' name='next' id='enact' value='Change & Go To Next Record'></p>";
			if (isset($_GET['new'])) {
				echo "<p style='text-align: center;'><input class='purple' type='submit' style='display: none; font-size: 20px;' name='mdonly' id='enactMDOnly' value='Change & Go To Next Record with New Data'></p>";
			}
			if ($emptyRecord) {
				echo "<p style='text-align: center;'><input class='blue' type='submit' style='display: none; font-size: 20px;' name='empty' id='enactEmpty' value='Change & Go To Next EMPTY Record'></p>";
			}
		}
		echo "</form>";
		echo "</div>";
?>
<script>
	var s = <?= $row["summary_calculate_to_import"] ?>;
	$('#toImport').val(JSON.stringify(s));
	$('#origToImport').val(JSON.stringify(s));

	$('.dates').keydown(function(e) {
		var ob = this;
		setTimeout(function() {
			var val = $(ob).val();
			if (val === "") {
				$(ob).removeClass("yellow");
			} else if (val.match(/^\d\d\d\d-\d\d-\d\d$/)) {
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
			if ((val != "") && (!val.match(/^\d\d\d\d-\d+-\d+$/))) {
				alert("This value is not a valid date (YYYY-MM-DD) and cannot be used by REDCap!");
			}
		}, 100);
	});

</script>
<?php
	}
}

if (!isset($_GET['headers']) || ($_GET['headers'] != "false")) { 
	echo "</div>\n";     // #content
}
?>
