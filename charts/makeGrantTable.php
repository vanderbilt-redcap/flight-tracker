<?php

use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Links.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Grants.php");
require_once(dirname(__FILE__)."/../classes/Grant.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/../classes/Cohorts.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../Application.php");

$warningsOn = FALSE;

$metadata = Download::metadata($token, $server);
if (isset($_GET['CDA'])) {
    $title = "Career Development Awards Over Time";
    $grantReach = "prior";
    $allPossibleFields = Application::$summaryFields;
    $showTimeline = TRUE;
    $showTimeBetweenGrants = TRUE;
} else {
    $title = "Grants Awarded Over Time";
    $grantReach = "all";
    $smallIdentifierFields = ["record_id", "identifier_first_name", "identifier_last_name"];
    $smallSummaryFields = ["record_id", "summary_dob", "summary_first_r01", "summary_first_external_k"];
    $minimalDownloadedGrantFields = [
            "nih_project_num", "nih_project_start_date", "nih_project_end_date", "nih_agency_ic_fundings", "nih_principal_investigators",
            "reporter_totalcostamount", "reporter_budgetstartdate", "reporter_budgetenddate", "reporter_projectstartdate", "reporter_projectenddate", "reporter_projectnumber", "reporter_otherpis", "reporter_contactpi",
            "coeus2_role", "coeus2_award_status", "coeus2_agency_grant_number", "coeus2_current_period_start", "coeus2_current_period_end", "coeus2_current_period_total_funding", "coeus2_current_period_direct_funding",
            "coeus_pi_flag", "coeus_sponsor_award_number", "coeus_total_cost_budget_period", "coeus_direct_cost_budget_period", "coeus_budget_start_date", "coeus_budget_end_date", "coeus_project_start_date", "coeus_project_end_date",
            "exporter_total_cost", "exporter_total_cost_sub_project", "exporter_pi_names", "exporter_full_project_num", "exporter_budget_start", "exporter_budget_end", "exporter_project_start", "exporter_project_end", "exporter_direct_cost_amt",
    ];
    $allPossibleFields = array_unique(array_merge(
            $smallIdentifierFields,
            $smallSummaryFields,
            $minimalDownloadedGrantFields,
            Application::$customFields,
    ));
    $showTimeline = FALSE;
    $showTimeBetweenGrants = FALSE;
}
$module = Application::getModule();
if (!$module) {
    $module = CareerDev::getPluginModule();
}
if ($_GET['cohort']) {
    $records = Download::cohortRecordIds($token, $server, $module, $_GET['cohort']);
} else {
    $records = Download::recordIds($token, $server);
}
$fields = REDCapManagement::filterOutInvalidFields($metadata, $allPossibleFields);

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
<script src='<?= CareerDev::link("/js/makeCDATable.js") ?>'></script>
<link href='<?= CareerDev::link("/css/makeCDATable.css") ?>' rel='stylesheet' />
<?php

if ($showTimeBetweenGrants) {
    $tableClass = "";
} else {
    $tableClass = " class='noBorderCollapse'";
}

$cohorts = new Cohorts($token, $server, $module);

echo "<h1>$title</h1>";
echo "<p class='centered'>".$cohorts->makeCohortSelect($_GET['cohort'], "window.location = \"".Application::link("charts/makeGrantTable.php")."&cohort=\"+$(this).val();")."</p>";
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
echo "<td class='legendCell k99r00 type'>K99/R00</td>";
echo "<td class='legendCell r01 type'>R01</td>";
echo "<td class='legendCell rEquivalent type'>R01 Equivalent</td>";
echo "<td class='legendCell trainingAdmin type'>Training Grant Admin</td>";
echo "</tr>";
echo "<tr><td class='spacer'></td></tr>";
echo "</table>";
echo "<br><br>";

if (isset($_GET['uncategorized'])) {
	echo json_encode($typeChoices)."<br><br>";
}
$cssClasses = array(
			"Internal K" => "internalK",
			"Individual K" => "individualK",
			"K Equivalent" => "kEquivalent",
			"K12/KL2" => "k12kl2",
			"R01" => "r01",
			"R01 Equivalent" => "rEquivalent",
			"Training Appointment" => "trainingAppt",
			"K99/R00" => "k99r00",
			"Training Grant Admin" => "trainingAdmin",
			"Research Fellowship" => "fellowship",
            "N/A" => "genericAward",
			);
$count = 0;
$processingTime = 0.0;
$maxCols = 0;
echo "<div class='top-horizontal-scroll'><div class='inner-top-horizontal-scroll'></div></div>";
echo "<div class='horizontal-scroll'>";
echo "<table$tableClass>";
foreach ($records as $recordId) {
    $time_a = microtime(TRUE);
    $rows = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
    $grants = new Grants($token, $server, $metadata);
    $grants->setRows($rows);
    $grants->compileGrants();
    $time_c = microtime(TRUE);
    $processingTime += $time_c - $time_a;

    $normativeRow = REDCapManagement::getNormativeRow($rows);

    $record = "<div class='record'>" . Links::makeRecordHomeLink($pid, $recordId, "View Record " . $recordId) . "</div>";
    $record .= "<div class='record'>" . Links::makeProfileLink($pid, "View Profile", $recordId) . "</div>";
    $name = "<div class='name'>{$normativeRow['identifier_first_name']} {$normativeRow['identifier_last_name']}</div>";
    $date_start = "";
    $date_end = "";
    $arrayOfGrants = $grants->getGrants($grantReach);
    if ($maxCols < count($arrayOfGrants) * 2) {
        $maxCols = count($arrayOfGrants) * 2;
    }
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
    if ($showTimeline) {
        $dates .= "<div class='record'><a href='javascript:;' onclick='showTimeline($recordId);'>Show Timeline</a></div>";
    }
    echo "<tr><td class='spacer'></td></tr>";
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
            $type = "";
            $rightBox = "";
            if ($grant->getVariable("type") != "N/A") {
                $type = "<div class='type'>" . $grant->getVariable("type") . "</div>";
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
            $smallLink = "";
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
            $calculatedType = transformSelfRecord($myAwardNo);
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
        echo "<tr><td class='timelineCell'><iframe class='timeline' id='timeline_$recordId' style='display: none;'></iframe></td></tr>";
    }
}
echo "<tr><td class='spacer'></td></tr>";
echo "</table>";
echo "</div>";
echo "<script>
$(document).ready(function() {
    $('td.timelineCell').attr('colspan', '$maxCols');
    setupHorizontalScroll($('.horizontal-scroll table').width());
});
</script>";
