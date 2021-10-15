<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\CohortConfig;
use \Vanderbilt\CareerDevLibrary\Filter;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../wrangler/css.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$cohorts = new Cohorts($token, $server, CareerDev::getModule());
$numCols = 4;

echo \Vanderbilt\FlightTrackerExternalModule\getCohortHeaderHTML();

echo "<div id='content'>\n";
echo "<h1>Existing Cohorts</h1>\n";

?>
<style>
.label,.labelCentered { font-weight: bold; }
.labelCentered,.valueCentered { text-align: center; }
.label { text-align: right; }
.value,.valueCentered,.label,.labelCentered { font-size: 16px; }
.value { text-align: left; }
.profileHeader { vertical-align: middle; padding: 4px 8px 4px 8px; }
td.profileHeader div a { color: black; }
td.profileHeader div a:hover { color: #0000FF; }
</style>
<?php

if (isset($_POST['title'])) {
	$title = REDCapManagement::sanitize($_POST['title']);
	$metadata = Download::metadata($token, $server);
	$config = $cohorts->getCohort($title); 

	echo makeCohortSelectionHTML($cohorts);

	if ($config) {
		echo "<h2>Cohort: $title</h2>\n";
		echo $config->getHTML($metadata);

		$filter = new Filter($token, $server, $metadata);
		$records = $filter->getRecords($config);

		$totalBudgets = 0;
		$totalGrants = 0;
		$totalPubs = 0;
		$totalScholars = count($records);
		$redcapData = Download::fieldsForRecords($token, $server, array("record_id", "summary_total_budgets", "summary_publication_count", "summary_grant_count"), $records);
		foreach ($redcapData as $row) {
			if (in_array($row['record_id'], $records) && ($row['redcap_repeat_instance'] == "") && ($row['redcap_repeat_instrument'] == "")) {
				$totalBudgets += $row['summary_total_budgets'];
				$totalPubs += $row['summary_publication_count'];
				$totalGrants += $row['summary_grant_count'];
			}
		}
?>
<br><br>
<table class='blue' style='margin: 0px auto 0px auto; border-radius: 10px;'>
<tr>
	<td class='profileHeader label'>
		Total Number of Scholars
	</td>
	<td class='profileHeader value'>
		<?= \Vanderbilt\FlightTrackerExternalModule\pretty($totalScholars) ?>
	</td>
</tr>
<tr>
	<td class='profileHeader label'>
		Total Number of Grants
	</td>
	<td class='profileHeader value'>
		<?= \Vanderbilt\FlightTrackerExternalModule\pretty($totalGrants) ?>
	</td>
</tr>
<tr>
	<td class='profileHeader label'>
		Total Budgets
	</td>
	<td class='profileHeader value'>
		<?= \Vanderbilt\FlightTrackerExternalModule\prettyMoney($totalBudgets) ?>
	</td>
</tr>
<tr>
	<td class='profileHeader label'>
		Total Number of Confirmed<br>Original Publications
	</td>
	<td class='profileHeader value'>
		<?= \Vanderbilt\FlightTrackerExternalModule\pretty($totalPubs) ?>
	</td>
</tr>
</table>

<?php
	} else {
		echo "<p class='centered'>The cohort $title could not be found!</p>\n";
	}
} else {
	echo makeCohortSelectionHTML($cohorts);
}
echo "</div>\n";

function makeCohortSelectionHTML($cohorts) {
	$currTitle = "";
	if ($_POST['title']) {
		$currTitle = $_POST['title'];
	}

	$str = "";
	$str .= "<p class='centered'>Please choose a cohort.</p>\n";
	$cohortNames = $cohorts->getCohortNames();
	$str .= "<form method='POST' action='".CareerDev::link("cohorts/profile.php")."'>\n";
	$str .= "<p class='centered'><select name='title'>\n";
	$str .= "<option value=''>---SELECT---</option>\n";
	foreach ($cohortNames as $title) {
		$sel = "";
		if ($currTitle == $title) {
			$sel = " selected";
		}
		$str .= "<option value='$title'$sel>$title</option>\n";
	}
	$str .= "</select></p>\n";
	$str .= "<p class='centered'><input type='submit' value='View Profile'></p>\n";
	$str .= "</form>\n";
	return $str;
}
