<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Upload;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../wrangler/css.php");

?>
<style>
button,input[type='submit'] { font-size: 16px; }
textarea { font-size: 16px; }
</style>
<?php

if (isset($_GET['allPids'])) {
	$pids = REDCapManagement::getActiveProjects(Application::getPids());
	$allPidsGet = "&allPids";
} else {
	$pids = [$pid];
	$allPidsGet = "";
}

if (isset($_POST['date'])) {
	$requestedDate = REDCapManagement::sanitize($_POST['date']);
} else {
	$requestedDate = date("Y-m-d");
}

echo "<h1>Resource Participation Roster</h1>";

if (isset($_POST['resource']) && $_POST['resource'] && isset($_POST['matched']) && $_POST['matched']) {
	$resourceId = REDCapManagement::sanitize($_POST['resource']);
	$matched = REDCapManagement::sanitizeWithoutChangingQuotes($_POST['matched']);
	$records = getUploadAryFromRoster($matched, $pids);

	$numUploaded = 0;
	foreach ($records as $pidAndRecord) {
		if (preg_match("/:/", $pidAndRecord)) {
			list($currPid, $recordId, $mechanism) = explode(":", $pidAndRecord);
			$currToken = Application::getSetting("token", $currPid);
			$currServer = Application::getSetting("server", $currPid);
			if ($currToken && $currServer && in_array($currPid, $pids)) {
				$resourceChoices = DataDictionaryManagement::getChoicesForField($currPid, "resources_resource");
				$resource = false;
				foreach ($resourceChoices as $idx => $label) {
					$id = REDCapManagement::makeHTMLId($label);
					if ($id == $resourceId) {
						$resource = $idx;
						break;
					}
				}
				if ($resource) {
					$feedback = Upload::resource($recordId, $resource, $currToken, $currServer, $requestedDate, $mechanism);
					if ($feedback['count']) {
						$numUploaded += $feedback['count'];
					} elseif ($feedback['item_count']) {
						$numUploaded += $feedback['item_count'];
					}
				}
			}
		}
	}

	echo "<h3>Names Uploaded in ".count($records)." Records</h3>";
}

$names = [];
$resources = [];
$projects = [];
$allResourcesLabels = [];
foreach ($pids as $currPid) {
	$token = Application::getSetting("token", $currPid);
	$server = Application::getSetting("server", $currPid);
	if (!$token || !$server) {
		continue;
	}

	$resourceChoices = DataDictionaryManagement::getChoicesForField($currPid, "resources_resource");
	if (isset($resourceChoices['0'])) {
		unset($resourceChoices['0']);
	}

	foreach (array_values($resourceChoices) as $label) {
		if (!in_array($label, $allResourcesLabels)) {
			$allResourcesLabels[] = $label;
		}
	}

	$firstNames = Download::fastField($currPid, "identifier_first_name");
	$lastNames = Download::fastField($currPid, "identifier_last_name");
	$resourcesDates = Download::fastField($currPid, "resources_date");
	$resourcesUsed = Download::fastField($currPid, "resources_resource");
	$projectTitle = Download::shortProjectTitle($currPid);
	if (isset($_GET['test'])) {
		echo "$currPid: $projectTitle<br>";
	}

	$projects[$currPid] = $projectTitle;
	$names[$currPid] = [];
	$resources[$currPid] = [];
	foreach (array_values($resourceChoices) as $label) {
		$resourceId = REDCapManagement::makeHTMLId($label);
		$resources[$currPid][$resourceId] = [];
	}

	foreach ($lastNames as $recordId => $lastName) {
		$firstName = $firstNames[$recordId] ?? "";

		$name = [];
		$name['first'] = $firstName;
		$name['last'] = $lastName;
		$names[$currPid][$recordId] = $name;
	}

	foreach ($resourcesUsed as $recordId => $instanceValues) {
		$name = $names[$currPid][$recordId] ?? [];
		$firstName = $name['first'] ?? "";
		$lastName = $name['last'] ?? "";
		foreach ($instanceValues as $instance => $value) {
			$date = $resourcesDates[$recordId][$instance] ?? "";
			$resource = $resourceChoices[$value];
			$resourceId = REDCapManagement::makeHTMLId($resource);
			$fullName = $firstName." ".$lastName;
			if (!isset($resources[$currPid][$resourceId][$date])) {
				$resources[$currPid][$resourceId][$date] = [];
			}
			$resources[$currPid][$resourceId][$date][] = $fullName;
		}
	}

	foreach ($resources[$currPid] as $resourceId => $dateAry) {
		foreach ($dateAry as $date => $ary) {
			if (is_array($ary)) {
				$resources[$currPid][$resourceId][$date] = implode("<br>", $ary);
			}
		}
	}
}

$selectHeader = isset($_GET['allPids']) ? "Choose a resource from all potential resources. Only projects with that resource will store participation." : "Choose a resource for this project:";

?>
<form method='POST' action='<?= Application::link("resources/add.php").$allPidsGet ?>'>
    <?= Application::generateCSRFTokenHTML() ?>
    <p class="centered">Date: <input type="date" id="date" name="date" onchange="showSignIn(); showResource();" value="<?= $requestedDate ?>"></p>
    <p class='centered max-width'><?= $selectHeader ?><br>
        <select name='resource' id='resource' onchange='showSignIn(); showResource();'>
            <option value=''>---SELECT---</option>
<?php
sort($allResourcesLabels);
foreach ($allResourcesLabels as $choice) {
	$resourceId = REDCapManagement::makeHTMLId($choice);
	echo "<option value='$resourceId'>$choice</option>";
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
                    <h4 class='trim_lower_margin'>Sign in First and Last Names<br/>(with any associated grants trailing in square-brackets)</h4>
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
    const projects = <?= json_encode($projects) ?>;
    const resources = <?= json_encode($resources) ?>;
	const names = <?= json_encode($names) ?>;
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

<?php

# returns an ary of record id's that match list
function getUploadAryFromRoster($matched, $pids) {
	$records = [];

	# coordinated with JS
	$prefix = "Multiple Matches:";

	$matchedAry = preg_split("/[\r\n]+/", $matched);
	$matchedAry = reformatSplitLines($matchedAry);
	foreach ($pids as $currPid) {
		$firstNames = Download::fastField($currPid, "identifier_first_name");
		$middleNames = Download::fastField($currPid, "identifier_middle");
		$lastNames = Download::fastField($currPid, "identifier_last_name");

		$names = [];
		foreach ($lastNames as $recordId => $lastName) {
			$firstName = $firstNames[$recordId] ?? "";
			$middleName = $middleNames[$recordId] ?? "";
			if ($middleName) {
				$name = strtolower($firstName." ".$middleName." ".$lastName);
				$names[$name] = $recordId;
			}
			$name = strtolower($firstName." ".$lastName);
			$names[$name] = $recordId;
		}

		$roster = [];
		foreach ($matchedAry as $matchedName) {
			if (!preg_match("/$prefix/", $matchedName)) {
				if ($matchedName) {
					$name = preg_replace("/ \([^\)]+\)$/", "", $matchedName);
					list($name, $mechanism) = parseMechanism($name);
					$mechanismSuffix = $mechanism ? " [$mechanism]" : "";
					$name = trim(strtolower($name));
					if (!in_array($name, $roster)) {
						$roster[] = $name.$mechanismSuffix;
					}
				}
			}
		}

		foreach ($roster as $name) {
			list($name, $mechanism) = parseMechanism($name);
			$recordId = $names[$name];
			if ($recordId) {
				$pidAndRecord = "$currPid:$recordId:$mechanism";
				# for names that appear twice
				if (!in_array($pidAndRecord, $records)) {
					$records[] = $pidAndRecord;
				}
			}
		}
	}
	return $records;
}

function parseMechanism($name) {
	if (preg_match("/ \[(.+?)\]/", $name, $matches)) {
		if (count($matches) > 1) {
			$name = preg_replace("/ \[.+?\]/", "", $name);
			return [$name, strtoupper($matches[1])];
		}
	}
	return [$name, ""];
}

function reformatSplitLines($lines) {
	$newLines = [];
	for ($i = 0; $i < count($lines); $i++) {
		$line = $lines[$i];
		if ($line[0] === '\v') {
			$prevIdx = (count($newLines) > 0) ? count($newLines) - 1 : 0;
			$newLines[$prevIdx] .= '\n' . $line;
		} else {
			$newLines[] = $line;
		}
	}
	return $newLines;

}
