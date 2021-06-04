<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../wrangler/css.php");

?>
<style>
button,input[type='submit'] { font-size: 16px; }
textarea { font-size: 16px; }
</style>
<?php


if ($_POST['date']) {
    $requestedDate = $_POST['date'];
} else {
    $requestedDate = date("Y-m-d");
}

echo "<h1>Resource Participation Roster</h1>";

if (isset($_POST['resource']) && $_POST['resource'] && isset($_POST['matched']) && $_POST['matched']) {
	$records = \Vanderbilt\FlightTrackerExternalModule\getUploadAryFromRoster($_POST['matched']);

	$numUploaded = 0;
	foreach ($records as $recordId) {
		$feedback = Upload::resource($recordId, $_POST['resource'], $token, $server, $requestedDate);
		if ($feedback['count']) {
			$numUploaded += $feedback['count'];
		} else if ($feedback['item_count']) {
			$numUploaded += $feedback['item_count'];
		}
	}

    echo "<h3>Names Uploaded in ".count($records)." Records</h3>";
}

$metadata = Download::metadata($token, $server);
$choices = REDCapManagement::getChoices($metadata);
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
        $name = $names[$row['record_id']];
        $value = $row['resources_resource'];
        $fullName = $name['first']." ".$name['last'];
        if (!isset($resources[$value][$date])) {
            $resources[$value][$date] = [];
        }
        array_push($resources[$value][$date], $fullName);
	}
}

foreach ($resources as $value => $dateAry) {
    foreach ($dateAry as $date => $ary) {
    	$resources[$value][$date] = implode("<br>", $resources[$value][$date]);
    }
}

?>
<form method='POST' action='<?= Application::link("resources/add.php") ?>'>
    <p class="centered">Date: <input type="date" id="date" name="date" onchange="showSignIn(); showResource();" value="<?= $requestedDate ?>"></p>
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
        </select>
    </p>

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

<script src='<?= Application::link("js/addNamesToResource.js") ?>'></script>
