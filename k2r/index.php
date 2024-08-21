<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Conversion;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\DateManagement;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(__DIR__."/../charts/baseWeb.php");

$cohort = Sanitizer::sanitizeCohort($_GET['cohort'] ?? $_POST['cohort'] ?? "");
$options = [
    "1234" => "Any &amp; All Ks",
    "12" => "Internal Ks, K12s, and KL2s",
    "34" => "All External Ks",
    "1" => "Internal Ks",
    "2" => "K12s / KL2s",
    "3" => "Individual Ks",
    "4" => "K Equivalents",
];
$conversionTypes = Sanitizer::sanitizeArray($_POST['conversions'] ?? []);
$showK2R = in_array("K2R", $conversionTypes) || empty($conversionTypes);
$defaultTFLength = 3;
$defaultKLength = Application::getIndividualKLength($pid);
$appointLink = Application::link("appointScholars.php")."&input=grant_types";

?>
<style>
main { font-size: 16px; }
main .smaller,main .smaller a { font-size: 15px; }
input[type=text] { font-size: 16px; width: 50px; }
input[type=submit] { font-size: 16px; width: 150px; }
input[type=checkbox] { font-size: 16px; }
select { font-size: 16px; width: 300px; }
td { text-align: center; }
th { text-align: left; width: 250px; padding-right: 20px; padding-top: 20px; padding-bottom: 20px;}
ul.stipulations { margin-top: 0; margin-bottom: 0; }
li.stipulations { font-weight: normal; font-size: 14px; }
</style>

<main>
<h1>Conversion Calculator</h1>
<p class="centered max-width smaller"><?= Conversion::CONVERSION_EXPLANATION ?></p>
<p class="centered max-width smaller">Are the right people not showing up? Look at who is appointed to your T &amp; K grants and make revisions on <a href="<?= $appointLink ?>">this page</a>.</p>
<?php
echo \Vanderbilt\FlightTrackerExternalModule\makeHelpLink();

$searchIfLeft = TRUE;
$action = Sanitizer::sanitize($_POST['action'] ?? "");
if ($action) {
    $convertor = new Conversion($conversionTypes, $pid, $event_id);
    if ($cohort) {
        $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
    } else {
        $records = Download::recordIdsByPid($pid);
    }
    $startDate = Sanitizer::sanitizeDate($_POST['start'] ?? "");
    $endDate = Sanitizer::sanitizeDate($_POST['end'] ?? "");
    # 2024-07-31 $unconvertedDate used to provide an input for an absolute stop date that someone had to be converted by.
    # It's now hard-coded as blank because I deemed it too complex for the interface and unused.
    # The code remains in case it helps down the road.
    $unconvertedDate = "";
	if (($action == "average") && !empty($conversionTypes)) {
        $kType = Sanitizer::sanitize($_POST['k_type'] ?? "");
        $searchIfLeft = isset($_POST['excludeIfLeft']) && ($_POST['excludeIfLeft'] == "on");
		$kLength = Sanitizer::sanitizeInteger($_POST['k_length'] ?? $defaultKLength);
		$tLength = Sanitizer::sanitizeInteger($_POST['tf_length'] ?? $defaultTFLength);
        $trainingGrantStartTime = Sanitizer::sanitize($_POST['training_grant_time'] ?? "last");
        $avgsToShow = [];
        if (in_array("K2R", $conversionTypes)) {
            $avgsToShow["K &rarr; R"] = $convertor->getK2RAverages($records, $kLength, $trainingGrantStartTime, $kType, $startDate, $endDate, ($unconvertedDate === ""), $searchIfLeft);
        }
        if (in_array("TF2K", $conversionTypes)) {
            $avgsToShow["T/F &rarr; K"] = $convertor->getTF2KAverages($records, $tLength, $trainingGrantStartTime, $startDate, $endDate, $searchIfLeft);
        }
        if (in_array("TF2R", $conversionTypes)) {
		    $avgsToShow["T/F &rarr; R"] = $convertor->getTF2RAverages($records, $tLength + $kLength, $trainingGrantStartTime, $startDate, $endDate, $searchIfLeft);
        }

		$dateRange = "";
		if ($startDate) {
		    if ($endDate) {
		        $dateRange = "<br/>".DateManagement::YMD2MDY($startDate)." - ".DateManagement::YMD2MDY($endDate);
		    } else {
                $dateRange = "<br/>Starting at ".DateManagement::YMD2MDY($startDate);
            }
        } else if ($endDate) {
            $dateRange = "<br/>Prior to ".DateManagement::YMD2MDY($endDate);
        }

        foreach ($avgsToShow as $title => $avgs) {
            if ($title == "K &rarr; R") {
                $firstGrant = "K";
                $firstLength = $kLength;
            } else {
                $firstGrant = "T/F";
                if ($title == "T/F &rarr; K") {
                    $firstLength = $tLength;
                } else {
                    $firstLength = $tLength + $kLength;
                }
            }
            if ($title == "T/F &rarr; K") {
                $lastGrant = "K";
            } else {
                $lastGrant = "R";
            }
            if ($cohort) {
                echo "<h2>Cohort $cohort Averages for $title</h2>";
            } else {
                echo "<h2>Entire Population Averages for $title</h2><h4>$firstLength Years to Convert</h4>";
            }
		    echo "<table class='centered max-width'>";
		    echo "<tr><th>Average $title Conversion Ratio<br/>({$options[$kType]})$dateRange";
		    echo "<ul class='stipulations'>";
		    if ($firstLength) {
			    echo "<li class='stipulations'>Omit anyone with a most-recent $firstGrant less than $firstLength years old</li>";
		    }
		    echo "<li class='stipulations'>Omit anyone with no matched $firstGrant</li>";
		    echo "<li class='stipulations'>Omit anyone with a Bridge Award (e.g., F99/K00, K99/R00)</li>";    // TODO
		    if ($searchIfLeft) {
                echo "<li class='stipulations'>Omit anyone who has left ".INSTITUTION." who has not converted and who did not fill out an Initial Survey</li>";
                if (Application::isVanderbilt()) {
                    echo "<li class='stipulations'>Omit anyone who does not have a <strong>vanderbilt.edu</strong> or <strong>vumc.org</strong> email address who has not converted</li>";
                }
            }
		    if ($firstLength) {
			    echo "<li class='stipulations'>Omit anyone with a $firstGrant of the given type that is less than $firstLength years old</li>";
		    }
		    if ($unconvertedDate) {
                echo "<li class='stipulations'>Omit anyone who hasn't converted to a $lastGrant with a $firstGrant before $unconvertedDate</li>";
            }
		    echo "</ul>";
		    echo "</th><td>{$avgs['conversion']}</td></tr>";
		    echo "<tr><th>Average Age</th><td>{$avgs['age']}</td></tr>";
            if ($title == "K &rarr; R") {
		        echo "<tr><th>Average Age at First K</th><td>{$avgs['age_at_first_k']}</td></tr>";
		        echo "<tr><th>Average Age at First R</th><td>{$avgs['age_at_first_r']}</td></tr>";
            } else if ($title == "T/F &rarr; K") {
		        echo "<tr><th>Average Age at First T</th><td>{$avgs['age_at_first_t']}</td></tr>";
		        echo "<tr><th>Average Age at First K</th><td>{$avgs['age_at_first_k']}</td></tr>";
            } else if ($title == "T/F &rarr; R") {
		        echo "<tr><th>Average Age at First T</th><td>{$avgs['age_at_first_t']}</td></tr>";
		        echo "<tr><th>Average Age at First R</th><td>{$avgs['age_at_first_r']}</td></tr>";
            }
		    echo "</table>";

            echo "<p class='centered'><a href='javascript:;' onclick='$(\".names\").show(); $(\".scrollDown\").show();'>Show Names</a> <span class='scrollDown' style='display: none;'>(Scroll Down)</span></p>";
            echo "<table class='centered max-width names' style='display: none;'>";
            echo "<tr><th class='centered'>Converted</th><th class='centered'>Not Converted</th><th class='centered'>Omitted</th></tr>";
            echo "<tr>";
            echo "<td class='centered' style='vertical-align: top;'>".implode("<br/>", $avgs['converted'])."</td>";
            echo "<td class='centered' style='vertical-align: top;'>".implode("<br/>", $avgs['not_converted'])."</td>";
            echo "<td class='centered' style='vertical-align: top;'>".implode("<br/>", $avgs['omitted'])."</td>";
            echo "</tr>";
            echo "</table>";
        }
	} else if ($action == "list") {
		$showNames = false;
		if ($_POST['show_names'] ?? FALSE) {
			$showNames = true;
		}
		$intKLength = Sanitizer::sanitizeInteger($_POST['internal_k'] ?? CareerDev::getInternalKLength());
		$indKLength = Sanitizer::sanitizeInteger($_POST['individual_k'] ?? CareerDev::getIndividualKLength());
		if ($intKLength && $indKLength && is_numeric($intKLength) && is_numeric($indKLength)) {
			echo "<h2>Number of Scholars on a K Award</h2>";
            $redcapData = Download::fieldsForRecordsByPid($pid, array_unique(array_merge(Conversion::getSummaryFields(), Conversion::LEFT_FIELDS)), $records);
			$kAwardees = $convertor->getKAwardees($redcapData, (int) $intKLength, (int) $indKLength);
			echo "<p class='centered'><b>".count($kAwardees)."</b> people are on K Awards.</p>";
			echo "<p class='centered'>No R/R-Equivalent Awards.<br/>";
			echo "Individual-K / K-Equivalent lasts $indKLength years.<br/>";
			echo "Internal-K / K12 / KL2 lasts $intKLength years.</p>"; 
			if ($showNames) {
				echo "<table class='centered'>";
				$lines = array();
				foreach ($kAwardees as $recordId => $name) {
					$lines[] = "<tr>";
					$lines[] = "<td>" . Links::makeSummaryLink($pid, $recordId, $event_id, $recordId . ": " . $name) . "</td>";
					$lines[] = "<td>" . $convertor->getTypeOfLastK($redcapData, $recordId) . "</td>";
					$lines[] = "</tr>";
				}
				echo implode("\n", $lines);
				echo "</table>";
			}
		}
	} else if (empty($conversionTypes)) {
        echo "<p class='centered'>No conversions have been checked!</p>";
	} else {
        echo "<p class='centered'>Unsupported action $action!</p>";
	}
} else {
	$cohortParams = "";
	if ($cohort) {
		$cohortParams = "&cohort=".$cohort;
	}
	$cohorts = new Cohorts($token, $server, Application::getModule());
    $k2RStyle = $showK2R ? "" : " style='display: none;' ";
?>

<form action='<?= Application::link("this").$cohortParams ?>' method='POST'>
<?= Application::generateCSRFTokenHTML() ?>
<input type="hidden" name="action" value="average" />
<h2>Conversion Ratio</h2>
<p class="centered max-width">
<?php
    $checkboxes = [];
    foreach (Conversion::CONVERSION_TYPES as $id => $label) {
        $js = ($id == "K2R") ? "onclick='toggleK2R();'" : "";
        if (in_array($id, $conversionTypes)) {
            $checked = "checked";
        } else if (($id == "K2R") && empty($conversionTypes)) {
            $checked = "checked";
        } else {
            $checked = "";
        }
        $checkboxes[] = "<input type='checkbox' name='conversions[]' id='conversion_$id' value='$id' $checked $js /><label for='conversion_$id'> $label</label>";
    }
    echo implode("&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;", $checkboxes);
 ?>
</p>
<p class='centered max-width'>Select Cohort (optional):<br/><?= $cohorts->makeCohortSelect($cohort ?: "all") ?></p>
<p class="centered max-width">
    <span class="smaller">We will exclude all scholars who are currently on a T, F, or K as a 'grace period.'<br/>You can define the length of this grace period below:</span><br/>
    <label for="tf_length">Expected Length of T/F: </label><input type="number" step="1" id="tf_length" name="tf_length" value="<?= $defaultTFLength ?>" /> Years<br/>
    <label for="k_length">Expected Length of K: </label><input type="number" step="1" id="k_length" name="k_length" value="<?= $defaultKLength ?>" /> Years
</p>
<div class="k2rOnly" <?= $k2RStyle ?>>
    <p class='centered max-width'><label for="k_type">Type of K: </label><select id="k_type" name='k_type' style="width: 220px;">
    <?php
        $selected = "selected";
        foreach ($options as $value => $descr) {
            echo "<option value='$value' $selected>$descr</option>";
            $selected = "";
        }
    ?>
    </select></p>
</div>
<p class='centered max-width'><label for="training_grant_time">Start Countdown At: </label><select id='training_grant_time' name='training_grant_time'><option value='first'>First Training Grant/Appointment</option><option value='last' selected>Last Training Grant/Appointment</option></select></p>
<p class='centered max-width'>
    <label for="start">Start Date for Training Grants (Optional): </label><input type="date" id='start' name="start" /><br/>
    <label for="end">End Date for Training Grants (Optional): </label><input type="date" id="end" name="end">
</p>
<p class="centered max-width"><input type="checkbox" name="excludeIfLeft" id="excludeIfLeft" <?= $searchIfLeft ? "checked" : "" ?>><label for="excludeIfLeft"> Exclude from Analysis if the Scholar Has Left <?= INSTITUTION ?></label></p>
<p class='centered max-width'><button>Calculate</button></p>
</form>
<form action='?pid=<?= Application::link("this").$cohortParams ?>' method='POST' class="k2rOnly" <?= $k2RStyle ?>>
<input type="hidden" name="action" value="list" />
<?= Application::generateCSRFTokenHTML() ?>
<h2>Who is on a K Award?</h2>
<p class='centered max-width'><label for="internal_k">Length of Internal-K / K12 / KL2 Award: </label><input type='text' id='internal_k' name='internal_k' value='<?= CareerDev::getInternalKLength($pid) ?>' /> years</p>
<p class='centered max-width'><label for="individual_k">Length of Individual-K / K-Equivalent Award: </label><input type='text' id='individual_k' name='individual_k' value='<?= CareerDev::getIndividualKLength($pid) ?>' /> years</p>
<p class='centered max-width'><input type='checkbox' name='show_names' id="show_names" checked /><label for="show_names"> Show Names</label></p>
<p class='centered max-width'><button>Calculate</button></p>
</form>
<script>
function toggleK2R() {
    if ($('#conversion_K2R').is(":checked")) {
        $('.k2rOnly').slideDown();
    } else {
        $('.k2rOnly').slideUp();
    }
}
</script>

<?php
}
echo "</main>";
