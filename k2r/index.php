<?php

use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Links.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/../CareerDev.php");

$options = array(
		"1234" => "Any &amp; All Ks",
		"12" => "Internal Ks, K12s, and KL2s",
		"34" => "All External Ks",
		"1" => "Internal Ks",
		"2" => "K12s / KL2s",
		"3" => "Individual Ks",
		"4" => "K Equivalents",
		);

?>
<style>
#main { font-size: 18px; }
input[type=text] { font-size: 18px; width: 50px; }
input[type=submit] { font-size: 18px; width: 150px; }
input[type=checkbox] { font-size: 18px; }
select { font-size: 18px; width: 250px; }
.small { font-size: 13px; }
.normal { font-size: 14px; }
td { text-align: center; }
th { text-align: left; width: 250px; padding-right: 20px; padding-top: 20px; padding-bottom: 20px;}
ul.k2r { margin-top: 0px; margin-bottom: 0px; }
li.k2r { font-weight: normal; font-size: 13px; }
</style>

<div id='main'>
<h1>K2R Conversion Calculator</h1>
<?php
echo \Vanderbilt\FlightTrackerExternalModule\makeHelpLink();

$ind_ks = array(3, 4);
$_GLOBAL['ind_ks'] = $ind_ks;
$int_ks = array(1, 2);
$_GLOBAL['int_ks'] = $int_ks;
if ($_POST['r01equivtype'] == "r01") {
	$rs = array(5);
} else {
	$rs = array(5, 6);
}
$_GLOBAL['rs'] = $rs;

function getTypeOfLastK($data, $recordId) {
	$ks = array(
			1 => "Internal K",
			2 => "K12/KL2",
			3 => "Individual K",
			4 => "K Equivalent",
			);
	foreach ($data as $row) {
		if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "")) {
			for ($i = MAX_GRANTS; $i >= 1; $i--) {
				if (in_array($row['summary_award_type_'.$i], array_keys($ks))) {
					return $ks[$row['summary_award_type_'.$i]];
				}
			}
		}
	}
	return "";
}

function getKAwardees($data, $intKLength, $indKLength) {
	global $ind_ks, $int_ks, $rs;

	$qualifiers = array();
	$today = date("Y-m-d");

	foreach ($data as $row) {
		if ($row['redcap_repeat_instrument'] === "") {
			$person = $row['identifier_first_name']." ".$row['identifier_last_name'];
			$first_r = "";
			for ($i = 1; $i <= 15; $i++) {
				if (in_array($row['summary_award_type_'.$i], $rs)) {
					$first_r = $row['summary_award_date_'.$i];
					break;
				}
			}
	
			$first_k = "";
			if (!$first_r) {
				for ($i = 1; $i <= 15; $i++) {
					if (in_array($row['summary_award_type_'.$i], $ind_ks)) {
						$first_k = $row['summary_award_date_'.$i];
						if (REDCapManagement::datediff($row['summary_award_date_'.$i], $today, "y") <= $indKLength) {
							$qualifiers[$row['record_id']] = $person;
						}
						break;
					}
				}
			}
	
			if (!$first_k && !$first_r) {
				$first_int_k = "";
				for ($i = 1; $i < 15; $i++) {
					if (in_array($row['summary_award_type_'.$i], $int_ks)) {
						$first_int_k = $row['summary_award_date_'.$i];
						if (REDCapManagement::datediff($row['summary_award_date_'.$i], $today, "y") <= $intKLength) {
							$qualifiers[$row['record_id']] = $person;
						}
						break;
					}
				}
			}
		}
	}
	return $qualifiers;
}

function isConverted($row, $kLength, $orderK, $kType) {
	global $ind_ks, $int_ks, $rs;
	$kPre = preg_split("//", $kType);
	$ks = array();
	$k99r00 = 9;
	foreach ($kPre as $k) {
		if ($k !== "") {
			$ks[] = $k;
		}
	}
	$today = date("Y-m-d");
 
	$k = "";
	$first_r = "";
	$last_k = "";
	for ($i = 1; $i <= 15; $i++) {
		if (in_array($row['summary_award_type_'.$i], $ks)) {
			$last_k = $row['summary_award_date_'.$i];
		}
		if (in_array($row['summary_award_type_'.$i], $ks)) {
			if (!$k) {
				$k = $row['summary_award_date_'.$i];
			} else if ($orderK == "last_k") {
				$k = $row['summary_award_date_'.$i];
			}
		} else if (!$first_r && in_array($row['summary_award_type_'.$i], $rs)) {
			$first_r = $row['summary_award_date_'.$i];
		} else if ($row['summary_award_type_'.$i] == $k99r00) {
			// omit
			return false;
		}
	}
	if (!$k) {
		# no CDA
		// echo "A ".$row['record_id']." ".$row['identifier_first_name']." ".$row['identifier_last_name']."<br>";
		return false;
	}
	if (!$first_r) {
		if ($kLength && (REDCapManagement::datediff($k, $today, "y") <= $kLength)) {
			# K < X years old
			// echo "B".REDCapManagement::datediff($k, $today, "y")." ".$k." ".$row['record_id']." ".$row['identifier_first_name']." ".$row['identifier_last_name']."<br>";
			return false;
		}
		# did not convert
		if ($kLength && ($orderK == "last_k") && (REDCapManagement::datediff($last_k, $today, "y") <= $kLength)) {
			# no R (not converted) and last K < X years old
			// echo "C ".$row['record_id']." ".$row['identifier_first_name']." ".$row['identifier_last_name']."<br>";
			return false;
		}
		if ($row['identifier_institution'] || $row['identifier_left_date']) {
			// echo "D ".$row['record_id']." ".$row['identifier_first_name']." ".$row['identifier_last_name']."<br>";
			return false;
		}
		# no R and no reason to throw out => not converted
		return "denom";
	}
	# leftovers have an R => converted
	return "numer";
}

function getAverages($data, $kLength, $orderK, $kType) {
	global $rs, $pid, $event_id;

	$avgs = array(
			"conversion" => 0,
			"age" => 0,
			"age_at_first_cda" => 0,
			"age_at_first_r" => 0,
            "converted" => [],
            "not_converted" => [],
            "omitted" => [],
			);
	$sums = array();
	foreach ($avgs as $key => $value) {
        if (!is_array($avgs[$key])) {
            $sums[$key] = array();
        }
	}

	foreach ($data as $row) {
		if ($row['redcap_repeat_instrument'] === "") {
			$c = isConverted($row, $kLength, $orderK, $kType);
			if ($c == "numer") {
				// echo "Numer ".$row['record_id']." ".$row['identifier_first_name']." ".$row['identifier_last_name']."<br>";
				$sums["conversion"][] = 100;   // percent
                $avgs["converted"][] = Links::makeSummaryLink($pid, $row['record_id'], $event_id, $row['identifier_first_name']." ".$row['identifier_last_name']);
			} else if ($c == "denom") {
				// echo "Denom ".$row['record_id']." ".$row['identifier_first_name']." ".$row['identifier_last_name']."<br>";
				$sums["conversion"][] = 0;
                $avgs["not_converted"][] = Links::makeSummaryLink($pid, $row['record_id'], $event_id, $row['identifier_first_name']." ".$row['identifier_last_name']);
            } else {
                $avgs["omitted"][] = Links::makeSummaryLink($pid, $row['record_id'], $event_id, $row['identifier_first_name']." ".$row['identifier_last_name']);
			}
			if ($row['summary_dob']) {
				$today = date("Y-m-d");
				$sums["age"][] = REDCapManagement::datediff($row['summary_dob'], $today, "y");
				if ($row['summary_award_date_1']) {
					$sums["age_at_first_cda"][] = REDCapManagement::datediff($row['summary_dob'], $row['summary_award_date_1'], "y");
				}
				for ($i = 1; $i <= 15; $i++) {
					if ($row['summary_award_date_'.$i] && in_array($row['summary_award_type_'.$i], $rs)) {
						$sums["age_at_first_r"][] = REDCapManagement::datediff($row['summary_dob'], $row['summary_award_date_'.$i], "y");
						break;
					}
				}
			}
		}
	}

	foreach ($sums as $key => $ary) {
		# one decimal place
		$perc = "";
		if ($key == "conversion") {
			$perc = "%";
		}
		$avgs[$key] = (floor(10 * array_sum($ary) / count($ary)) / 10)."$perc<br><span class='small'>(n=".count($ary).")</span>";
	}

	return $avgs;
}

$metadata = Download::metadata($token, $server);

if (isset($_POST['average']) || isset($_POST['list'])) {
	$myFields = array("record_id", "identifier_last_name", "identifier_first_name", "identifier_institution", "identifier_left_date");
	$redcapData = Download::getFilteredREDCapData($token, $server, array_unique(array_merge(Application::$summaryFields, $myFields)), $_GET['cohort'], $metadata);

	if (isset($_POST['average'])) {
		$kLength = '';
		if (isset($_POST['k'])) {
			$kLength = $_POST['k'];
		}
		$avgs = getAverages($redcapData, $kLength, $_POST['k_number'], $_POST['k_type']);

		if ($_GET['cohort']) {
			echo "<h2>Cohort {$_GET['cohort']} Averages</h2>";
		} else {
			echo "<h2>Entire Population Averages</h2>";
		}
		echo "<table class='centered'>";
		echo "<tr><th>Average K-To-R Conversion Rate<br>({$options[$_POST['k_type']]})";
		echo "<ul class='k2r'>";
		if ($kLength) {
			echo "<li class='k2r'>Omit anyone with a most-recent CDA less than $kLength years old</li>";
		}
		echo "<li class='k2r'>Omit anyone with no matched CDA</li>";
		echo "<li class='k2r'>Omit anyone with a K99/R00</li>";
		echo "<li class='k2r'>Omit anyone who has left ".INSTITUTION." who has not converted and who did not fill out a Initial Survey</li>";
		if ($kLength) {
			echo "<li class='k2r'>Omit anyone with a CDA of the given type that is less than $kLength years old</li>";
		}
		echo "</ul>";
		echo "</th><td>{$avgs['conversion']}</td></tr>";
		echo "<tr><th>Average Age</th><td>{$avgs['age']}</td></tr>";
		echo "<tr><th>Average Age at First CDA</th><td>{$avgs['age_at_first_cda']}</td></tr>";
		echo "<tr><th>Average Age at First R / R-Equivalent</th><td>{$avgs['age_at_first_r']}</td></tr>";
		echo "</table>";

        echo "<p class='centered'><a href='javascript:;' onclick='$(\"#names\").show();'>Show Names</a></p>";
        echo "<table class='centered' id='names' style='display: none;'>";
        echo "<tr><th class='centered'>Converted</th><th class='centered'>Not Converted</th><th class='centered'>Omitted</th></tr>";
        echo "<tr>";
        echo "<td class='centered' style='vertical-align: top;'>".implode("<br>", $avgs['converted'])."</td>";
        echo "<td class='centered' style='vertical-align: top;'>".implode("<br>", $avgs['not_converted'])."</td>";
        echo "<td class='centered' style='vertical-align: top;'>".implode("<br>", $avgs['omitted'])."</td>";
        echo "</tr>";
        echo "</table>";
    } else if (isset($_POST['list'])) {
		$showNames = false;
		if ($_POST['show_names']) {
			$showNames = true;
		}
		$intKLength = $_POST['internal_k'];
		$indKLength = $_POST['individual_k'];
		if ($intKLength && $indKLength && is_numeric($intKLength) && is_numeric($indKLength)) {
			echo "<h2>Number of Scholars on a K Award</h2>";
			$kAwardees = getKAwardees($redcapData, $intKLength, $indKLength);
			echo "<p class='centered'><b>".count($kAwardees)."</b> people are on K Awards.</p>";
			echo "<p class='centered'>No R/R-Equivalent Awards.<br>";
			echo "Individual-K / K-Equivalent lasts $indKLength years.<br>";
			echo "Internal-K / K12 / KL2 lasts $intKLength years.</p>"; 
			if ($showNames) {
				echo "<table class='centered'>\n";
				$lines = array();
				foreach ($kAwardees as $recordId => $name) {
					array_push($lines, "<tr>");
					array_push($lines, "<td>".Links::makeSummaryLink($pid, $recordId, $event_id, $recordId.": ".$name)."</td>");
					array_push($lines, "<td>".getTypeOfLastK($redcapData, $recordId)."</td>");
					array_push($lines, "</tr>");
				}
				echo implode("\n", $lines);
				echo "</table>";
			}
		}
	}
} else {
	$cohortParams = "";
	if ($_GET['cohort']) {
		$cohortParams = "&cohort=".$_GET['cohort'];
	}
?>

<form action='<?= CareerDev::link("/k2r/index.php").$cohortParams ?>' method='POST'>
<h2>Conversion Rate</h2>
<p class='centered'>Select Cohort (optional):<br><?= \Vanderbilt\FlightTrackerExternalModule\getCohortSelect($token, $server, $pid, $metadata) ?></p>
<p class='centered'>Exclude those within <input type='text' name='k' value='5'> years of receipt of most recent K who have not converted<br>
<span class='small'>(leave blank if you want <b>all</b> conversions)</span></p>
<p class='centered'>Type of K: <select name='k_type'>
<?php
	$i = 0;
	foreach ($options as $value => $descr) {
		$selected = "";
		if ($i === 0) {
			$selected = " selected";
		}
		echo "<option value='$value'".$selected.">$descr</option>";
		$i++;
	}
?>
</select></p>
<p class='centered'>Start Countdown At: <select name='k_number'><option value='first_k'>First K</option><option value='last_k' selected>Last K</option></select></p>
<p class='centered'><input type='radio' name='r01equivtype' value='r01equiv' checked> R01 &amp; R01-Equivalents<br>
<input type='radio' name='r01equivtype' value='r01'> R01s only</p>
<p class='centered'><input type='submit' name='average' value='Calculate'></p>
</form>
<hr>
<form action='<?= CareerDev::link("/k2r/index.php").$cohortParams ?>' method='POST'>
<h2>Who is on a K Award?</h2>
<p class='centered'>Length of Internal-K / K12 / KL2 Award: <input type='text' name='internal_k' value='3'> years</p>
<p class='centered'>Length of Individual-K / K-Equivalent Award: <input type='text' name='individual_k' value='5'> years</p>
<p class='centered'><input type='checkbox' name='show_names' checked> Show Names</p>
<p class='centered'><input type='submit' name='list' value='Calculate'></p>
</form>

<?php
}
echo "</div>\n";   // #main
?>
