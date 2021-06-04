<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../wrangler/css.php");

?>
<style>
button,input[type='submit'] { font-size: 16px; }
textarea { font-size: 16px; }
th { background-color: #dddddd; padding: 8px; }
td { padding: 8px }
.small { font-weight: normal; font-size: 12px; }
</style>
<?php

# Select resource
$metadata = Download::metadata($token, $server);
$choices = Scholar::getChoices($metadata);
$redcapData = Download::fields($token, $server, array_unique(array_merge(CareerDev::$resourceFields, CareerDev::$summaryFields)));

echo "<h1>Resource Participation Analysis</h1>";
echo "<h2 style='margin-bottom: 0px;'>Select a Resource</h2>";
echo "<p class='centered' style='margin-top: 0px;'>(Within <input id='days' type='text' style='width: 50px;' value='730' onblur='displayResource();'> Days)<br>";
echo "<select onchange='displayResource();' id='resource'>";
echo "<option value='' selected>---SELECT---</option>";
$dates = array();
$dateTimes = array();
foreach ($choices['resources_resource'] as $value => $choice) {
	if ($value != "0") {
		$dates[$value] = array();
		$dateTimes[$value] = array();
		echo "<option value='$value'>$choice</option>";
		foreach ($redcapData as $row) {
			if (($row['redcap_repeat_instrument'] == "resources") && ($row['resources_resource'] == $value)) {
				$attendedDate = $row['resources_used'];
				if (!in_array($attendedDate, $dates[$value])) {
					array_push($dateTimes[$value], strtotime($attendedDate));
					array_push($dates[$value], $attendedDate);
				}
			}
		}
	}
}
echo "</select></p>";
echo "<p class='centered'>The total population consists of all the scholars in your database--<br>and not just of those who used the resource.</p>"; 

$experimental = array();
$control = array();
$sorted = array();
foreach ($redcapData as $row) {
	if (!isset($sorted[$row['record_id']])) {
		$sorted[$row['record_id']] = array();
	}
	array_push($sorted[$row['record_id']], $row);
}
$pubTimes = array();
$grantTimes = array();
foreach ($sorted as $recordId => $rows) {
	$found = FALSE;
	foreach ($rows as $row) {
		if (!$found && ($row['redcap_repeat_instrument'] == "resources")) {
			if ($row['resources_resource']) {
				$found = TRUE;
				# checked => experimental
				if (!isset($experimental[$index])) {
					$experimental[$index] = array();
				}
				if (!in_array($row['record_id'], $experimental[$index])) {
					array_push($experimental[$index], $recordId);
				}
			}
		}
	}
	if (!$found) {
		# unchecked => control
		if (!isset($control[$index])) {
			$control[$index] = array();
		}

		if (!in_array($recordId, $control[$index])) {
			array_push($control[$index], $recordId);
		}
	}

	$pubData = Download::fieldsForRecords($token, $server, Application::getCitationFields($metadata), array($recordId));
	$pubs = new Publications($token, $server, $metadata);
	$pubs->setRows($pubData);
	$pubTimes[$recordId] = array();
	foreach ($pubs->getCitations("Original Included") as $citation) {
		array_push($pubTimes[$recordId], $citation->getTimestamp());
	}

	$grants = new Grants($token, $server, $metadata);
	$grants->setRows($redcapData);
	$grants->compileGrants();
	if (!isset($grantTimes[$recordId])) {
		$grantTimes[$recordId] = array();
	}
	foreach ($grants->getGrants("prior") as $grant) {
		array_push($grantTimes[$recordId], strtotime($grant->getVariable("start")));
	}
}

?>
<script>
	var times = { 'Grant': <?= json_encode($grantTimes) ?>, 'Publication': <?= json_encode($pubTimes) ?> };;
	var records = { 'Experimental': <?= json_encode($experimental) ?>, 'Control': <?= json_encode($control) ?> };
	var dates = <?= json_encode($dates) ?>;
	var dateTimes = <?= json_encode($dateTimes) ?>;
</script>

<script src='../js/resourceAnalysis.js'></script>
<?php

echo "<div id='results' style='text-align: center;'>Please select a resource.</div>";
