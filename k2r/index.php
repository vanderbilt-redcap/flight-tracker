<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/base.php");

$cohort = Sanitizer::sanitizeCohort($_GET['cohort'] ?? $_POST['cohort'] ?? "");

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

$searchIfLeft = TRUE;

if (isset($_POST['average']) || isset($_POST['list'])) {
    $myFields = ["record_id", "identifier_last_name", "identifier_first_name", "identifier_email", "identifier_institution", "identifier_left_date"];
	$redcapData = Download::getFilteredREDCapData($token, $server, array_unique(array_merge(Application::$summaryFields, $myFields)), $cohort, CareerDev::getPluginModule());

    $startDate = Sanitizer::sanitizeDate($_POST['start'] ?? "");
    $endDate = Sanitizer::sanitizeDate($_POST['end'] ?? "");
    $unconvertedDate = Sanitizer::sanitizeDate($_POST['excludeUnconvertedKsBefore'] ?? "");
	if (isset($_POST['average'])) {
        $kType = Sanitizer::sanitize($_POST['k_type'] ?? "");
        $searchIfLeft = isset($_POST['excludeIfLeft']) && ($_POST['excludeIfLeft'] == "on");
		$kLength = Sanitizer::sanitizeInteger($_POST['k'] ?? "");
        $kNumber = Sanitizer::sanitize($_POST['k_number'] ?? "");
		$avgs = getAverages($redcapData, $kLength, $kNumber, $kType, $startDate, $endDate, $unconvertedDate, $searchIfLeft);

		$dateRange = "";
		if ($startDate) {
		    if ($endDate) {
		        $dateRange = "<br>".REDCapManagement::YMD2MDY($startDate)." - ".REDCapManagement::YMD2MDY($endDate);
		    } else {
                $dateRange = "<br>Starting at ".REDCapManagement::YMD2MDY($startDate);
            }
        } else if ($endDate) {
            $dateRange = "<br>Prior to ".REDCapManagement::YMD2MDY($endDate);
        }

		if ($cohort) {
			echo "<h2>Cohort $cohort Averages</h2>";
		} else {
			echo "<h2>Entire Population Averages</h2>";
		}
		// echo REDCapManagement::json_encode_with_spaces($avgs)."<br><br>";
		echo "<table class='centered'>";
		echo "<tr><th>Average K-To-R Conversion Ratio<br>({$options[$kType]})$dateRange";
		echo "<ul class='k2r'>";
		if ($kLength) {
			echo "<li class='k2r'>Omit anyone with a most-recent CDA less than $kLength years old</li>";
		}
		echo "<li class='k2r'>Omit anyone with no matched CDA</li>";
		echo "<li class='k2r'>Omit anyone with a K99/R00</li>";
		if ($searchIfLeft) {
            echo "<li class='k2r'>Omit anyone who has left ".INSTITUTION." who has not converted and who did not fill out an Initial Survey</li>";
            if (Application::isVanderbilt()) {
                echo "<li class='k2r'>Omit anyone who does not have a <strong>vanderbilt.edu</strong> or <strong>vumc.org</strong> email address who has not converted</li>";
            }
        }
		if ($kLength) {
			echo "<li class='k2r'>Omit anyone with a CDA of the given type that is less than $kLength years old</li>";
		}
		if ($unconvertedDate) {
            echo "<li class='k2r'>Omit anyone who hasn't converted with a K before $unconvertedDate</li>";
        }
		echo "</ul>";
		echo "</th><td>{$avgs['conversion']}</td></tr>";
		echo "<tr><th>Average Age</th><td>{$avgs['age']}</td></tr>";
		echo "<tr><th>Average Age at First CDA</th><td>{$avgs['age_at_first_cda']}</td></tr>";
		echo "<tr><th>Average Age at First R / R-Equivalent</th><td>{$avgs['age_at_first_r']}</td></tr>";
		echo "</table>";

		echo "<p class='centered'><a href='javascript:;' onclick='$(\"#names\").show(); $(\"#scrollDown\").show();'>Show Names</a> <span id='scrollDown' style='display: none;'>(Scroll Down)</span></p>";
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
		if ($_POST['show_names'] ?? FALSE) {
			$showNames = true;
		}
		$intKLength = Sanitizer::sanitizeInteger($_POST['internal_k'] ?? CareerDev::getInternalKLength());
		$indKLength = Sanitizer::sanitizeInteger($_POST['individual_k'] ?? CareerDev::getIndividualKLength());
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
					$lines[] = "<tr>";
					$lines[] = "<td>" . Links::makeSummaryLink($pid, $recordId, $event_id, $recordId . ": " . $name) . "</td>";
					$lines[] = "<td>" . getTypeOfLastK($redcapData, $recordId) . "</td>";
					$lines[] = "</tr>";
				}
				echo implode("\n", $lines);
				echo "</table>";
			}
		}
	}
} else {
	$cohortParams = "";
	if ($cohort) {
		$cohortParams = "&cohort=".$cohort;
	}
	$cohorts = new Cohorts($token, $server, Application::getModule());
?>

<form action='<?= Application::link("this").$cohortParams ?>' method='POST'>
<?= Application::generateCSRFTokenHTML() ?>
<h2>Conversion Ratio</h2>
<p class='centered'>Select Cohort (optional):<br><?= $cohorts->makeCohortSelect($cohort ? $cohort : "all") ?></p>
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
<p class='centered'>Start of Period for Ks: <input type="date" name="start">&nbsp;&nbsp;&nbsp;End of Period for Ks: <input type="date" name="end"></p>
<p class='centered'>Exclude Unconverted Ks Before: <input type="date" name="excludeUnconvertedKsBefore"></p>
<p class='centered'><input type='radio' name='r01equivtype' value='r01equiv' checked> R01 &amp; R01-Equivalents<br>
<input type='radio' name='r01equivtype' value='r01'> R01s only</p>
<p class="centered"><input type="checkbox" name="excludeIfLeft" id="excludeIfLeft" <?= $searchIfLeft ? "checked" : "" ?>> <label for="excludeIfLeft">Exclude from Analysis if the Scholar Has Left <?= INSTITUTION ?></label></p>
<p class='centered'><input type='submit' name='average' value='Calculate'></p>
</form>
<hr>
<form action='?pid=<?= Application::link("this").$cohortParams ?>' method='POST'>
<?= Application::generateCSRFTokenHTML() ?>
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
