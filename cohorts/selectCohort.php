<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\CohortConfig;
use \Vanderbilt\CareerDevLibrary\Filter;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
  
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Cohorts.php");
require_once(dirname(__FILE__)."/../classes/CohortConfig.php");
require_once(dirname(__FILE__)."/../classes/Filter.php");
require_once(dirname(__FILE__)."/../wrangler/css.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$metadata = Download::metadata($token, $server);
$cohorts = new Cohorts($token, $server, CareerDev::getModule());
$numCols = 4;

echo \Vanderbilt\FlightTrackerExternalModule\getCohortHeaderHTML();
echo "<div id='content'>\n";

echo "<h1>Select a Cohort to View Its Metrics</h1>\n";

$cohortNames = $cohorts->getCohortNames();

if (empty($cohortNames)) {
	echo "<p class='centered'>No cohorts have been created. Please <a href='".CareerDev::link("cohorts/addCohort.php")."'>create a cohort</a> first.</p>\n";
} else {
	echo "<p class='centered'><select id='cohort'>\n";
	echo "<option value=''>---SELECT---</option>\n";
	foreach ($cohortNames as $title) {
		echo "<option value='$title'>$title</option>\n";
	}
	echo "</select></p>\n";
	
?>

<script>
$(document).ready(function() {
	$("#cohort").change(function() {
		var val = $(this).val();
		if (val) {
			window.location.href = '<?= CareerDev::link("dashboard/overall.php") ?>&cohort='+val;
		}
	});
});
</script>

<?php
}
