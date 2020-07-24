<?php

use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Links.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Grants.php");
require_once(dirname(__FILE__)."/../classes/Grant.php");
require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__).'/../../../redcap_connect.php');

# makes the stylized table of CDA awards
$warningsOn = FALSE;

$redcapData = Download::fields($token, $server, array_unique(array_merge(CareerDev::$summaryFields, CareerDev::$citationFields)));
$metadata = Download::metadata($token, $server);

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

function getTimeSpan($row, $i) {
	$start = $row['summary_award_date_'.$i];
	$end = $row['summary_award_date_'.($i + 1)];
	if ($start && $end) {
		return floor(REDCapManagement::datediff($start, $end, "y") * 10) / 10;
	}
	return "";
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

echo "<h1>Grants Awarded Over Time</h1>";
echo "<h3>Legend</h3>";
echo "<table>";
echo "<tr><td class='spacer'></td></tr>";
echo "<tr>";
echo "<td class='spacer'></td><td class='legendCell internalK type'>Internal K</td>"; 
echo "<td class='spacer'></td><td class='legendCell fellowship type'>Research Fellowship</td>"; 
echo "<td class='spacer'></td><td class='legendCell k12kl2 type'>K12/KL2</td>"; 
echo "<td class='spacer'></td><td class='legendCell individualK type'>Individual K</td>"; 
echo "<td class='spacer'></td><td class='legendCell kEquivalent type'>K Equivalent</td>"; 
echo "<td class='spacer'></td><td class='legendCell k99r00 type'>K99/R00</td>"; 
echo "<td class='spacer'></td><td class='legendCell r01 type'>R01</td>"; 
echo "<td class='spacer'></td><td class='legendCell rEquivalent type'>R01 Equivalent</td>"; 
echo "<td class='spacer'></td><td class='legendCell trainingAppt type'>Training Appointment</td>"; 
echo "<td class='spacer'></td><td class='legendCell trainingAdmin type'>Training Grant Admin</td>"; 
echo "<td class='spacer'></td>";
echo "</tr>";
echo "<tr><td class='spacer'></td></tr>";
echo "</table>";
echo "<br><br>";

# unit testing
foreach ($redcapData as $row) {
	if (($row['redcap_repeat_instrument'] == "") && !$row['summary_award_date_1'] && $warningsOn) {
		echo "<p class='red centered'>".Links::makeRecordHomeLink($pid, $row['record_id'], "Record ".$row['record_id'])." ({$row['identifier_first_name']} {$row['identifier_last_name']}) lacks any awards.</p>";
	}
	if ($row['redcap_repeat_instrument'] == "") {
		$firstR = $row['summary_first_r01'];
		$firstK = $row['summary_first_any_k'];
		if ($firstR && $firstK) {
			$firstR = strtotime($firstR);
			$firstK = strtotime($firstK);
			if (($firstR < $firstK) && $warningsOn) {
				echo "<p class='red centered'>".Links::makeRecordHomeLink($pid, $row['record_id'], "Record ".$row['record_id'])." ({$row['identifier_first_name']} {$row['identifier_last_name']}) has an R award before a K award.</p>";
			}
		} else if ($firstR && !$firstK) {
			$grants = new Grants($token, $server);
			$grants->setRows(array($row));
			$hasK99R00 = FALSE;
			foreach ($grants->getGrants("prior") as $grant) {
				if ($grant->getVariable("type") == "K99/R00") {
					$hasK99R00 = TRUE;
					break;
				}
			}
			if (!$hasK99R00 && $warningsOn) {
				echo "<p class='red centered'>".Links::makeRecordHomeLink($pid, $row['record_id'], "Record ".$row['record_id'])." ({$row['identifier_first_name']} {$row['identifier_last_name']}) lacks a K award.</p>";
			}
		}
	}
}



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
			);
$count = 0;
$transformedREDCapData = array();
foreach ($redcapData as $row) {
	if ($row['redcap_repeat_instrument'] == "") {
		$transformedREDCapData[$row['record_id']] = array($row);
	}
}

$instantiationTime = 0.0;
$processingTime = 0.0;
echo "<table>";
$maxCols = 0;
foreach ($transformedREDCapData as $recordId => $rows) {
	$grants = new Grants($token, $server, $metadata);
	$grants->setRows($rows);
	$currCols = 2 * count($grants->getGrants("prior"));
	if ($currCols > $maxCols) {
		$maxCols = $currCols;
	}
}
if ($maxCols > 12) {
	$maxCols = 12;
}
foreach ($transformedREDCapData as $recordId => $rows) {
	$time_a = microtime(TRUE);
	$grants = new Grants($token, $server, $metadata);
	$time_b = microtime(TRUE);
	$grants->setRows($rows);
	$time_c = microtime(TRUE);
	$instantiationTime += $time_b - $time_a;
	$processingTime += $time_c - $time_b;

	$normativeRow = array();
	foreach ($rows as $row) {
		if ($row['redcap_repeat_instrument'] == "") {
			$normativeRow = $row;
			break;
		}
	}

	$record = "<div class='record'>".Links::makeRecordHomeLink($pid, $recordId, "View Record ".$recordId)."</div>";
	$record .= "<div class='record'>".Links::makeProfileLink($pid, "View Profile", $recordId)."</div>";
	$name = "<div class='name'>{$normativeRow['identifier_first_name']} {$normativeRow['identifier_last_name']}</div>";
	$date_start = "";
	$date_end = "";
	foreach ($grants->getGrants("prior") as $grant) {
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
		$dates .= $dateStr."</div>";
	}
	if ($normativeRow['summary_dob'] != "") {
		$dates .= "<div class='dates'><span class='sideHeader'>Birth:</span> ".extractYear($normativeRow['summary_dob'])."</div>";
	} else {
		$dates .= "<div class='dates'><span class='sideHeader'>Birth:</span> N/A</div>";
	}
	// if ($normativeRow['summary_left_vanderbilt'] != "") {
		// $dates .= "<div class='dates red'><span class='sideHeader'>Left ".INSTITUTION.":</span> ".convertToMDY($normativeRow['summary_left_vanderbilt'])."</div>";
        // }
	$dates .= "<div class='spacer'>&nbsp;</div>";
	$noExtK = false;
	if ($normativeRow['summary_first_external_k'] != "") {
		$dates .= "<div class='dates'><span class='sideHeader'>First External K:</span> ".convertToMDY($normativeRow['summary_first_external_k'])."</div>";
	} else {
		$noExtK = true;
		$dates .= "<div class='dates'><span class='sideHeader'>First External K:</span> N/A</div>";
	}
	if ($normativeRow['summary_first_r01'] != "") {
		$dates .= "<div class='dates'><span class='sideHeader'>First R:</span> ".convertToMDY($normativeRow['summary_first_r01'])."</div>";
	} else if (!$noExtK) {
		$dates .= "<div class='dates'><span class='sideHeader'>First R:</span> N/A</div>";
	}
	$dates .= "<div class='spacer'>&nbsp;</div>";
	// $dates .= "<div class='record'>".Links::makePublicationsLink($pid, $recordId, $event_id, "View Publications")."</div>";
	$dates .= "<div class='record'><a href='javascript:;' onclick='showTimeline($recordId);'>Show Timeline</a></div>";
	if (!isset($_GET['uncategorized'])) {
		echo "<tr><td class='spacer'></td></tr>";
		echo "<tr>";
		echo "<td class='spacer'></td>";
		echo "<td class='cell leftBox'>$record$name$dates</td>";
		echo "<td class='spacer'></td>";
	}
	
	$i = 1;
	foreach ($grants->getGrants("prior") as $grant) {
		if ($grant->getVariable("start")) {
			$date = "<div class='date'>".convertToMDY($grant->getVariable("start"))."</div>";
			$date .= "<div class='source'>".$grant->getVariable("source")."</div>";
			$type = "";
			$rightBox = "";
			if ($grant->getVariable("type") != "N/A") {
				$type = "<div class='type'>".$grant->getVariable("type")."</div>";
				$rightBox  = $cssClasses[$grant->getVariable("type")];
			} else {
				$type = "<div class='awardno'>".$grant->getNumber()."</div>";
				$rightBox = "genericAward";
			}
			$mech = "";
			if ($grant->getVariable("nih_mechanism")) {
				$mech = "<div class='mechanism'>".$grant->getVariable("nih_mechansim")."</div>";
			}
			$smallLink = "";
			$link = "";
			$myAwardNo = $grant->getNumber();
			$baseAwardNo = $grant->getBaseNumber();
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
			$tooltipText .= "<br><br><b>Source</b>:<br>".Links::makeLink("https://grants.nih.gov/grants/funding/ac_search_results.htm", "NIH Activity Codes Search Results");
			$tooltipText .= "<br><br><b>Source</b>:<br>".Links::makeLink("https://era.nih.gov/sites/default/files/Deciphering_NIH_Application.pdf", "Deciphering NIH Application/Grant Numbers");
			$tooltipText .= "</span>";
			$link .= $baseAwardNo;
			if (!empty($details)) {
				$link .= "<span class='tooltiptext'>".$tooltipText."</span>";
			}
			$link .= "</div>";

			$budget = "";
			if ($grant->getVariable("direct_budget")) {
				$budget = "<div class='budget'>(".\Vanderbilt\FlightTrackerExternalModule\prettyMoney($grant->getVariable("budget")).")</div>";
			}

			if (isset($_GET['short'])) {
				if ($grant->getVariable("type") != "N/A") {
					echo "<td class='cell $rightBox'>$type$date$link</td>";
					echo "<td class='spacer'></td>";
				}
			} else if (isset($_GET['uncategorized'])) {
				# filtering path
				if ($grant->getVariable("type") != "N/A") {
					if (($myAwardNo !== "") && ($myAwardNo != "000")) {
						if (preg_match("/[Rr]03/", $myAwardNo)) {
							echo "<tr><td class='small'>R03 Sponsor No</td></tr>";
						} else if (preg_match("/[Rr]21/", $myAwardNo)) {
							echo "<tr><td class='small'>R21 Sponsor No</td></tr>";
						} else if (preg_match("/[Rr]34/", $myAwardNo)) {
							echo "<tr><td class='small'>R34 Sponsor No</td></tr>";
						} else if (preg_match("/^\d[Ff]\d\d/", $myAwardNo)) {
							echo "<tr><td class='small'>F Sponsor No</td></tr>";
						} else if (!preg_match("/[Rr]21/", $myAwardNo) && !preg_match("/[Rr]03/", $myAwardNo)) {
							$sponsor = "";
							$percentEffort = "";
							if ($grant->getVariable("percent_effort")) {
								$percentEffort = "(percent effort = ".$grant->getVariable("percent_effort").")";
							}
							echo "<tr><td>$count $sponsor Sponsor No: <b>$myAwardNo</b> $mech $budget $percentEffort&nbsp;&nbsp;&nbsp;$smallLink</td></tr>";
							$count++;
						}
					} else {
						echo "<tr><td class='small'>Blank Sponsor No</td></tr>";
					}
				}
			} else {
				# main path
				echo "<td class='cell $rightBox'>$type$date$mech$budget$link</td>";
				$ts = getTimeSpan($normativeRow, $i);
				if ($ts !== "") {
					echo "<td class='spacer'><div class='spacerYears'>$ts</div><div class='spacerYear'>years<br>b/w<br>starts</div></td>";
				} else {
					echo "<td class='spacer'></td>";
				}
			}
		}
		$i++;
	}
	if (!isset($_GET['uncategorized'])) {
		echo "</tr>";
		echo "<tr><td colspan='$maxCols'><iframe class='timeline' id='timeline_$recordId' style='display: none;'></iframe></td></tr>";
	}
}
echo "<tr><td class='spacer'></td></tr>";
echo "</table>";
