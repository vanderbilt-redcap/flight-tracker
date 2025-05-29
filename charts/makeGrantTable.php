<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

Application::increaseProcessingMax(1);

$warningsOn = FALSE;

$metadata = Download::metadata($token, $server);
$thisUrlWithParams = Application::link("this");
if (isset($_GET['showFlagsOnly'])) {
    $thisUrlWithParams .= "&showFlagsOnly";
}
if (isset($_GET['CDA'])) {
    $thisUrlWithParams .= "&CDA";
    $title = "Stylized Table of Career-Defining Awards";
    $maxCols = 15;
    $grantReach = "prior";
    $allPossibleFields = Application::$summaryFields;
    $showTimeline = TRUE;
    $showTimeBetweenGrants = TRUE;
} else {
    $title = "Stylized Table of Grants";
    $maxCols = 25;
    if (isset($_GET['showFlagsOnly'])) {
        $grantReach = "flagged";
    } else {
        $grantReach = "all";
    }
    $smallIdentifierFields = ["record_id", "identifier_first_name", "identifier_last_name"];
    $smallSummaryFields = ["record_id", "summary_dob", "summary_first_r01", "summary_first_external_k", "summary_calculate_to_import"];
    $minimalDownloadedGrantFields = REDCapManagement::getMinimalGrantFields($metadata);
    $allPossibleFields = array_unique(array_merge(
            $smallIdentifierFields,
            $smallSummaryFields,
            $minimalDownloadedGrantFields,
            Application::$customFields
    ));
    $showTimeline = FALSE;
    $showTimeBetweenGrants = FALSE;
    $titleFields = DataDictionaryManagement::getGrantTitleFields($metadata);
}
$module = Application::getModule();

if (isset($_GET['cohort'])) {
    $cohort = Sanitizer::sanitizeCohort($_GET['cohort']);
    $thisUrlWithParams .= "&cohort=".urlencode($cohort);
} else {
    $cohort = "";
}
if (isset($_GET['plain'])) {
    $thisUrlWithParams .= "&plain";
}

if (!empty($_POST['records']) && !empty($_POST['fields'])) {
    $fields = Sanitizer::sanitizeArray($_POST['fields']);
    $requestedRecords = is_array($_POST['records']) ? Sanitizer::sanitizeArray($_POST['records']) : [];
    $allRecords = Download::recordIds($token, $server);
    $records = [];
    foreach ($requestedRecords as $recordId) {
        $sanitizedRecordId = Sanitizer::getSanitizedRecord($recordId, $allRecords);
        if ($sanitizedRecordId) {
            $records[] = $sanitizedRecordId;
        }
    }
    foreach ($records as $recordId) {
        printRowsForRecord($recordId, $fields, $token, $server, $pid, $grantReach, $showTimeBetweenGrants, $showTimeline);
    }
    exit;
}

require_once(dirname(__FILE__)."/baseWeb.php");

if ($cohort) {
    $records = Download::cohortRecordIds($token, $server, $module, $cohort);
} else {
    $records = Download::recordIds($token, $server);
}



$fields = REDCapManagement::filterOutInvalidFields($metadata, $allPossibleFields);
$titleFields = REDCapManagement::filterOutInvalidFields($metadata, $titleFields ?? []);

# transform verbose to the simpler name
function transformSelfRecord($num) {
	$nums = preg_split("/\s*[;,\|]\s*/", $num);
	$numsOut = array();
	foreach ($nums as $num2) {
		$num2 = preg_replace("/^Internal K - Rec\. \d+/", "", $num2);
		$num2 = preg_replace("/^KL2 - Rec\. \d+/", "KL2", $num2);
		$num2 = preg_replace("/^K12 - Rec\. \d+/", "K12", $num2);
		$num2 = preg_replace("/^Individual K - Rec\. \d+/", "", $num2);
		$num2 = preg_replace("/^Unknown R01 - Rec\. \d+/", "", $num2);
		$num2 = preg_replace("/\-\d+$/", "", $num2);
		$num2 = preg_replace("/\-[^\-\s]+\s/", " ", $num2);
		$num2 = preg_replace("/\-[^\-\s]+ \(MPI\)$/", "", $num2);
		$numsOut[] = $num2;
	}
	if (count($numsOut) == 1) {
		if (preg_match("/[KkRr]\d\d/", $numsOut[0], $matches)) {
			return $matches[0];
		} else {
			return $numsOut[0];
		}
	} else {
		return implode("; ", $numsOut);
	}
}

# takes YMD and returns year
function extractYear($d) {
	$nodes = preg_split("/-/", $d);
	return $nodes[0];
}

# takes YMD and converts to MM-DD-YYYY
function convertToMDY($d) {
	$nodes = preg_split("/-/", $d);
	if (count($nodes) >= 3) {
		return $nodes[1]."-".$nodes[2]."-".$nodes[0];
	}
	return $d;
}

$typeChoices = Grant::getReverseAwardTypes();

# gets the year from YMD
function getYear($d) {
	if (preg_match("/^\d\d\d\d-\d+-\d+$/", $d)) {
		return preg_replace("/-\d+-\d+$/", "", $d);
	}
	return $d;
}

# gets the date for the span or just for the start if no $end
function produceDateString($start, $end) {
	if (!$start && !$end) {
		return "";
	}
	if (!$end) {
		return "[".getYear($start)."]";
	} else {
		$startYear = getYear($start);
		$endYear = getYear($end);
		if ($startYear == $endYear) {
			return "[$startYear]";
		} else {
			return "[$startYear - $endYear]";
		}
	}
}

function getTimeSpanFromGrants($grant, $arrayOfGrants) {
    $awardNo = $grant->getNumber();
    $found = FALSE;
    $nextGrant = NULL;
    foreach ($arrayOfGrants as $myGrant) {
        if ($found) {
            $nextGrant = $myGrant;
            $start = $grant->getVariable("start");
            $end = $nextGrant->getVariable("start");
            return processDateDifference($start, $end);
        }
        if ($myGrant->getNumber() == $awardNo) {
            $found = TRUE;
        }
    }
    return "";
}

function processDateDifference($date1, $date2) {
    if ($date1 && $date2) {
        return round(REDCapManagement::datediff($date1, $date2, "y") * 10) / 10;
    }
    return "";
}

function getTimeSpan($row, $i) {
	$start = $row['summary_award_date_'.$i];
	$end = $row['summary_award_date_'.($i + 1)];
    return processDateDifference($start, $end);
}

function appendCitationLabel($num) {
	if ($num == 1) {
		return $num." citation";
	}
	return $num." citations";
}

?>
<link href='<?= CareerDev::link("/css/makeCDATable.css") ?>' rel='stylesheet' />
<?php

if ($showTimeBetweenGrants) {
    $tableClass = "";
} else {
    $tableClass = " class='noBorderCollapse'";
}

$cohorts = new Cohorts($token, $server, $module);

if (!isset($_GET['CDA'])) {
    echo Grants::makeFlagLink($pid, $thisUrlWithParams);
}
echo "<h1>$title</h1>";
$cda = isset($_GET['CDA']) ? "&CDA" : "";
echo "<p class='centered'>".$cohorts->makeCohortSelect($cohort, "window.location = \"".Application::link("charts/makeGrantTable.php")."$cda&cohort=\"+$(this).val();")."</p>";
if (isset($_GET['plain'])) {
    $entries = [];
    $fields = array_unique(array_merge($fields, $titleFields ?? []));
    foreach ($records as $recordId) {
        $rows = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
        $normativeRow = REDCapManagement::getNormativeRow($rows);

        $grants = new Grants($token, $server, $metadata);
        $grants->setRows($rows);
        $grants->compileGrants();
        $arrayOfGrants = $grants->getGrants($grantReach);
        $name = $normativeRow['identifier_first_name']." ".$normativeRow['identifier_last_name'];
        foreach ($arrayOfGrants as $grant) {
            $start = $grant->getVariable("start");
            $end = $grant->getVariable("end");
            if (!$start) {
                $start = $grant->getVariable("project_start");
            }
            if (!$end) {
                $end = $grant->getVariable("project_end");
            }
            $span = "Begins ".REDCapManagement::YMD2MDY($start);
            if ($start && $end) {
                $span = REDCapManagement::YMD2MDY($start)." - ".REDCapManagement::YMD2MDY($end);
            } else if ($end) {
                $span = "Ends ".REDCapManagement::YMD2MDY($end);
            }
            $title = $grant->getVariable("title");
            $titleHTML = ($title ? "<br>".$title : "");
            $entries[] = "<div class='max-width'><p class='centered'>$name: ".$grant->getBaseNumber()." $span$titleHTML</p></div>";
        }
    }
    $grantCount = count($entries);
    echo "<h4>$grantCount Grants (Total)</h4>";
    echo implode("", $entries);
    echo "<br><br>";
} else {
    echo "<style>";
    echo ".tooltip .tooltiptext { width: 300px; }\n";
    echo "</style>";
    if (isset($_GET['CDA']) && isset($_GET['showFlagsOnly'])) {
        echo "<p class='centered max-width'>Because Career-Defining Awards are pre-computed, not all records might be uploaded if you enabled flags recently. If flags have been enabled for over one week, then all data should be current.</p>";
    }
    echo "<h3>Legend</h3>";
    echo "<table class='noBorderCollapse'>";
    echo "<tr><td class='spacer'></td></tr>";
    echo "<tr>";
    echo "<td class='legendCell trainingAppt type'>Training Appt</td>";
    if (!isset($_GET['CDA'])) {
        echo "<td class='legendCell genericAward type'>Generic Award</td>";
        echo "<td class='legendCell fellowship type'>Research Fellowship</td>";
    }
    echo "<td class='legendCell internalK type'>Internal K</td>";
    echo "<td class='legendCell k12kl2 type'>K12/KL2</td>";
    echo "<td class='legendCell individualK type'>Individual K</td>";
    echo "<td class='legendCell kEquivalent type'>K Equivalent</td>";
    echo "<td class='legendCell bridge type'>Bridge Award</td>";
    echo "<td class='legendCell r01 type'>R01</td>";
    echo "<td class='legendCell rEquivalent type'>R01 Equivalent</td>";
    echo "<td class='legendCell trainingAdmin type'>Mentoring / Training Grant Admin</td>";
    echo "</tr>";
    echo "<tr><td class='spacer'></td></tr>";
    echo "</table>";
    echo "<br><br>";

    if (isset($_GET['uncategorized'])) {
        echo json_encode($typeChoices)."<br><br>";
    }
    $count = 0;
    echo "<div class='loading'></div>";
    echo "<div class='top-horizontal-scroll'><div class='inner-top-horizontal-scroll'></div></div>";
    echo "<div class='horizontal-scroll'>";
    echo "<table$tableClass><tbody id='mainBody'>";
    echo "<tr><td class='spacer'></td></tr>";
    echo "</tbody></table>";
    echo "</div>";
    echo "<div class='loading'></div>";
    $recordsJSON = json_encode($records);
    $fieldsJSON = json_encode($fields);
    $csrfToken = Application::generateCSRFToken();
    echo "<script>
const records = $recordsJSON;
const fields = $fieldsJSON;

function loadRecords(nextI) {
    const recordList = [];
    const numRecords = 5;
    for (let i = nextI; (i < numRecords + nextI) && (i < records.length); i++) {
        recordList.push(records[i]);
    }
    if (recordList.length > 0) {
       console.log('loadRecords '+nextI+' with '+recordList.join(', '));
        const displayRecordList = [...recordList];
        if (displayRecordList.length > 2) {
            displayRecordList[displayRecordList.length - 1] = 'and ' + displayRecordList[displayRecordList.length - 1];
           $('.loading').html(getSmallLoadingMessage('Loading Records '+displayRecordList.join(', ')));
        } else if (displayRecordList.length == 2) {
            $('.loading').html(getSmallLoadingMessage('Loading Records '+displayRecordList.join(' and ')));
        } else {
            $('.loading').html(getSmallLoadingMessage('Loading Record '+displayRecordList[0]));
        }
        const postdata = { records: recordList, fields: fields, redcap_csrf_token: '$csrfToken' };
        $.post('$thisUrlWithParams', postdata, function(html) {
            if (html) {
                $('#mainBody').append(html);
                setupHorizontalScroll($('.horizontal-scroll table').width());
                loadRecords(nextI + numRecords);
            } else {
                console.log('Blank return.');
                $('.loading').html('');
            }
        });
    } else {
        console.log('Done.');
        $('.loading').html('');
    }
}

$(document).ready(function() {
    $('td.timelineCell').attr('colspan', '$maxCols');
    loadRecords(0);
});
</script>";
}

function printRowsForRecord($recordId, $fields, $token, $server, $pid, $grantReach, $showTimeBetweenGrants, $showTimeline) {
    $cssClasses = [
        "Internal K" => "internalK",
        "Individual K" => "individualK",
        "K Equivalent" => "kEquivalent",
        "K12/KL2" => "k12kl2",
        "R01" => "r01",
        "R01 Equivalent" => "rEquivalent",
        "Training Appointment" => "trainingAppt",
        "K99/R00" => "bridge",
        "Bridge Award" => "bridge",
        "Mentoring/Training Grant Admin" => "trainingAdmin",
        "Training Grant Admin" => "trainingAdmin",
        "Research Fellowship" => "fellowship",
        "N/A" => "genericAward",
    ];

    $rows = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
    $grants = new Grants($token, $server, "empty");
    $grants->setRows($rows);
    $grants->compileGrants();

    $normativeRow = REDCapManagement::getNormativeRow($rows);

    $record = "<div class='record'>" . Links::makeRecordHomeLink($pid, $recordId, "View Record " . $recordId) . "</div>";
    $record .= "<div class='record'>" . Links::makeProfileLink($pid, "View Profile", $recordId) . "</div>";
    $name = "<div class='name'>{$normativeRow['identifier_first_name']} {$normativeRow['identifier_last_name']}</div>";
    $date_start = "";
    $date_end = "";
    $arrayOfGrants = $grants->getGrants($grantReach);
    foreach ($arrayOfGrants as $grant) {
        if ($grant->getVariable("start")) {
            if (!$date_start) {
                $date_start = $grant->getVariable("start");
            } else {
                $date_end = $grant->getVariable("end");
            }
        }
    }
    $dateStr = produceDateString($date_start, $date_end);
    $dates = "";
    $dates .= "<div class='spacer'>&nbsp;</div>";
    if ($dateStr) {
        $dates .= "<div class='dates'>";
    }
    if (preg_match("/-/", $dateStr)) {
        $dates .= "<span class='sideHeader'>Years of Awards:</span> ";
    } else if ($dateStr) {
        $dates .= "<span class='sideHeader'>Year of Award:</span> ";
    }
    if ($dateStr) {
        $dates .= $dateStr . "</div>";
    }
    if ($normativeRow['summary_dob'] != "") {
        $dates .= "<div class='dates'><span class='sideHeader'>Birth:</span> " . extractYear($normativeRow['summary_dob']) . "</div>";
    } else {
        $dates .= "<div class='dates'><span class='sideHeader'>Birth:</span> N/A</div>";
    }
    // if ($normativeRow['summary_left_vanderbilt'] != "") {
    // $dates .= "<div class='dates red'><span class='sideHeader'>Left ".INSTITUTION.":</span> ".convertToMDY($normativeRow['summary_left_vanderbilt'])."</div>";
    // }
    $dates .= "<div class='spacer'>&nbsp;</div>";
    $noExtK = false;
    if ($normativeRow['summary_first_external_k'] != "") {
        $dates .= "<div class='dates'><span class='sideHeader'>First External K:</span> " . convertToMDY($normativeRow['summary_first_external_k']) . "</div>";
    } else {
        $noExtK = true;
        $dates .= "<div class='dates'><span class='sideHeader'>First External K:</span> N/A</div>";
    }
    if ($normativeRow['summary_first_r01'] != "") {
        $dates .= "<div class='dates'><span class='sideHeader'>First R:</span> " . convertToMDY($normativeRow['summary_first_r01']) . "</div>";
    } else if (!$noExtK) {
        $dates .= "<div class='dates'><span class='sideHeader'>First R:</span> N/A</div>";
    }
    $dates .= "<div class='spacer'>&nbsp;</div>";
    // $dates .= "<div class='record'>".Links::makePublicationsLink($pid, $recordId, $event_id, "View Publications")."</div>";
    if ($showTimeline && !empty($arrayOfGrants)) {
        $timelineUrl = Application::link("charts/timeline.php");
        $dates .= "<div class='record'><a href='javascript:;' onclick='showTimeline(\"$recordId\", \"$timelineUrl\");'>Show Timeline</a></div>";
    }
    echo "<tr>";
    echo "<td class='spacer'></td>";
    echo "<td class='cell leftBox'>$record$name$dates<div class='record'>".count($arrayOfGrants)." grants</div></td>";
    echo "<td class='spacer'></td>";

    $i = 1;
    foreach ($arrayOfGrants as $grant) {
        if ($grant->getVariable("start")) {
            $isPI = in_array($grant->getVariable("role"), ["PI", "Co-PI"]);
            $date = "<div class='date'>" . convertToMDY($grant->getVariable("start")) . "</div>";
            $date .= "<div class='source'>" . $grant->getVariable("source") . "</div>";
            if ($grant->getVariable("type") != "N/A") {
                $type = "<div class='type'>" . shortenLongerTypes($grant->getVariable("type")) . "</div>";
            } else {
                $type = "<div class='type'>Generic Award</div>";
            }
            $piNote = "";
            if (!$isPI) {
                $piNote = "<div class='role'>Neither PI nor Co-PI</div>";
            }
            $rightBox = $cssClasses[$grant->getVariable("type")];
            $mech = "";
            if ($grant->getVariable("nih_mechanism")) {
                $mech = "<div class='mechanism'>" . $grant->getVariable("nih_mechanism") . "</div>";
            }
            $link = "";
            $myAwardNo = $grant->getNumber();
            $baseAwardNo = $grant->getBaseNumber();
            if ($baseAwardNo == "000") {
                $baseAwardNo = Grant::$noNameAssigned;
            }
            if ($baseAwardNo == Grant::$noNameAssigned) {
                if ($sponsor = $grant->getVariable("sponsor")) {
                    $baseAwardNo = "from $sponsor";
                }
            }
            if ($grant->getVariable("link")) {
                $smallLink = $grant->getVariable("link");
                $link = "<div class='link'>$smallLink</div>";
            }
            $details = Grant::parseNumber($myAwardNo);
            $link .= "<div class='awardno_large";
            if (!empty($details)) {
                $link .= " tooltip";
            }
            $link .= "'>";
            $tooltipText = "<span class='header'>Details</span>";
            foreach ($details as $key => $value) {
                $key = preg_replace("/_/", " ", $key);
                $key = ucfirst($key);
                $tooltipText .= "<br><br><b>$key</b>:<br>$value";
            }
            $tooltipText .= "<span class='smaller'>";
            $tooltipText .= "<br><br><b>Source</b>:<br>" . Links::makeLink("https://grants.nih.gov/grants/funding/ac_search_results.htm", "NIH Activity Codes Search Results");
            $tooltipText .= "<br><br><b>Source</b>:<br>" . Links::makeLink("https://era.nih.gov/sites/default/files/Deciphering_NIH_Application.pdf", "Deciphering NIH Application/Grant Numbers");
            $tooltipText .= "</span>";
            $link .= $baseAwardNo;
            if (!empty($details)) {
                $link .= "<span class='tooltiptext'>" . $tooltipText . "</span>";
            }
            $link .= "</div>";

            $budget = "";
            if ($grant->getVariable("budget")) {
                $budget = "<div class='budget'>(" . REDCapManagement::prettyMoney($grant->getVariable("budget")) . ")</div>";
            } else if ($grant->getVariable("direct_budget")) {
                $budget = "<div class='budget'>(" . REDCapManagement::prettyMoney($grant->getVariable("direct_budget")) . ")</div>";
            }

            $printCell = TRUE;
            if (isset($_GET['CDA']) && in_array($grant->getVariable("type"), ["Research Fellowship", "N/A"])) {
                $printCell = FALSE;
            }
            if ($printCell) {
                echo "<td class='cell $rightBox'>$type$date$mech$budget$piNote$link</td>";
                if ($showTimeBetweenGrants) {
                    if ($grantReach == "prior") {
                        $timespan = getTimeSpan($normativeRow, $i);
                    } else {
                        $timespan = getTimeSpanFromGrants($grant, $arrayOfGrants);
                    }
                    if ($timespan !== "") {
                        echo "<td class='spacer'><div class='spacerYears'>$timespan</div><div class='spacerYear'>years<br>b/w<br>starts</div></td>";
                    } else {
                        echo "<td class='spacer'></td>";
                    }
                }
            }
        }
        $i++;
    }
    echo "</tr>";
    if ($showTimeline) {
        echo "<tr><td class='timelineCell' colspan='100' style='display: none;'><div class='timeline' id='timeline_$recordId' style='display: none; width: 800px;'></iframe></td></tr>";
    }
    echo "<tr><td class='spacer'></td></tr>";
}

function shortenLongerTypes($type) {
    if ($type == "Mentoring/Training Grant Admin") {
        return "Mentoring Admin";
    }
    return $type;
}
