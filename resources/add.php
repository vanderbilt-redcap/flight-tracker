<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");
require_once(dirname(__FILE__)."/../classes/Scholar.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../wrangler/css.php");

?>
<style>
button,input[type='submit'] { font-size: 16px; }
textarea { font-size: 16px; }
</style>
<?php

echo "<h1>Resource Participation Roster</h1>";

if (isset($_POST['resource']) && $_POST['resource'] && isset($_POST['matched']) && $_POST['matched']) {
	$records = getUploadAryFromRoster($_POST['matched']);

	$numUploaded = 0;
	foreach ($records as $recordId) {
		$feedback = Upload::resource($recordId, $_POST['resource'], $token, $server);
		if ($feedback['count']) {
			$numUploaded += $feedback['count'];
		} else if ($feedback['item_count']) {
			$numUploaded += $feedback['item_count'];
		}
	}

	echo "<h3>Names Uploaded</h3>";
}

$metadata = Download::metadata($token, $server);
$choices = Scholar::getChoices($metadata);
if (isset($choices['resources_resource']['0'])) {
	unset($choices['resources_resource']['0']);
}

$firstNames = Download::firstnames($token, $server);
$lastNames = Download::lastnames($token, $server);
$redcapData = Download::resources($token, $server);

$names = array();
$resources = array();
foreach ($choices['resources_resource'] as $value => $choice) {
	$resources[$value] = array();
}

foreach ($lastNames as $recordId => $lastName) {
	$firstName = $firstNames[$recordId];

	$name = array();
	$name['first'] = $firstName;
	$name['last'] = $lastName;
	$names[$recordId] = $name;
}
foreach ($redcapData as $row) {
	if ($row['redcap_repeat_instrument'] == "resources") {
		$date = $row['resources_date'];
		if ($date == date('Y-m-d')) {
			$name = $names[$row['record_id']];
			$value = $row['resources_resource'];
			$fullName = $name['first']." ".$name['last'];
			array_push($resources[$value], $fullName);
		}
	}
}

foreach ($resources as $value => $ary) {
	$resources[$value] = implode("<br>", $resources[$value]);
}

?>
<form method='POST' action='<?= CareerDev::link("add.php") ?>'>
<p class='centered'>Select a resource:<br>
<select name='resource' id='resource' onchange='showSignIn(); showResource();'>
<option value=''>---SELECT---</option>
<?php
foreach ($choices['resources_resource'] as $value => $choice) {
	if ($value != "0") {
		echo "<option value='$value'>$choice</option>";
	}
}
?>
</select></p>

<style>
.trim_lower_margin { margin-bottom: 0px; }
.sign_in { margin-top: 2px; border-radius: 10px; border: 1px dotted #888888; height: 400px; width: 100%; font-size: 14px; }
</style>

<p class='centered' id='note'>Select a Resource to Receive a Participation Roster</p>
<div id='attendance'>
<h2>Attendance Roster</h2>
<p class='centered'>(One per line.)</p>
<table style='margin-left: auto; margin-right: auto;'><tr>
	<td style='width: 33%;'>
		<h4 class='trim_lower_margin'>Sign in First and Last Names</h4>
		<textarea class='sign_in' id='roster' name='roster'></textarea>
	</td>
	<td style='width: 33%;'>
		<h4 class='trim_lower_margin'>Names Matched with Database</h4>
		<textarea class='sign_in' style='background-color: #dddddd;' id='matched' name='matched' readonly></textarea>
	</td>
	<td style='width: 34%;'>
		<h4 class='trim_lower_margin' id='prior_attendance_title'>&nbsp;</h4>
		<div class='sign_in' style='background-color: #eeeeee;' id='prior_attendance'></div>
	</td>
</tr></table>

<p class='centered'><input type='submit' value='Add Names'></p>
</form>
</div>

<script>
	var names = <?= json_encode($names) ?>;
</script>
<script>
	var resources = <?= json_encode($resources) ?>;
</script>
<script>
	$(document).ready(function() {
		$('#roster').keydown(function(e) {
			if (e.which == 13 || e.which == 8) {
				var txt = recalculateNames($("#roster").val());
				$("#matched").val(txt);
			}
		});
		$('#roster').blur(function(e) {
			var txt = recalculateNames($("#roster").val());
			$("#matched").val(txt);
      		});
		$("#attendance").hide();
		$("#note").show();
	});
</script>

<script src='<?= CareerDev::link("js/addNamesToResource.js") ?>'></script>
