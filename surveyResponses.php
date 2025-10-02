<?php

use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Links;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

$fields = array_unique(array_merge(CareerDev::$followupFields, ["check_date", "initial_survey_complete"]));
$redcapData = Download::fields($token, $server, $fields);
$firstNames = Download::firstnames($token, $server);
$lastNames = Download::lastnames($token, $server);

$origDate = "pre 2018-09";
$surveyCompletes = [];
foreach ($redcapData as $row) {
	if ($row['initial_survey_complete'] == '2') {
		if (!isset($row['record_id'])) {
			$surveyCompletes[$row['record_id']] = [];
		}
		if ($row['check_date']) {
			$surveyCompletes[$row['record_id']]["initial_survey"] = $row['check_date'];
		} else {
			$surveyCompletes[$row['record_id']]["initial_survey"] = $origDate;
		}
	}
	if ($row['redcap_repeat_instrument'] == "followup") {
		if (!isset($row['record_id'])) {
			$surveyCompletes[$row['record_id']] = [];
		}
		if ($row['followup_date']) {
			$surveyCompletes[$row['record_id']]["followup"] = $row['followup_date'];
		} else {
			$surveyCompletes[$row['record_id']]["followup"] = $origDate;
		}
	}
}
?>

<style>
.red { color: red; }
</style>

<h1>Survey Completion Dates</h1>

<table class='centered'>
<tr class='even'>
	<th>Name</th>
	<th>Initial Survey Completion</th>
	<th>Followup Survey Completion</th>
	<th>Latest Any Survey Completion</th>
</tr>
<?php
	$i = 1;
foreach ($firstNames as $recordId => $firstName) {
	$rowClass = "even";
	if ($i % 2 == 1) {
		$rowClass = "odd";
	}
	$lastName = $lastNames[$recordId];
	echo "<tr class='$rowClass'>\n";

	echo "<td style='text-align: left;'>".Links::makeRecordHomeLink($pid, $recordId, "$firstName $lastName")."</td>\n";

	if (isset($surveyCompletes[$recordId]) && isset($surveyCompletes[$recordId]["initial_survey"])) {
		echo "<td class='centered'>".$surveyCompletes[$recordId]["initial_survey"]."</td>\n";
	} else {
		echo "<td class='red centered'>Missing</td>\n";
	}

	if (isset($surveyCompletes[$recordId]) && isset($surveyCompletes[$recordId]["followup"])) {
		echo "<td class='centered'>".$surveyCompletes[$recordId]["followup"]."</td>\n";
	} else {
		echo "<td class='centered'></td>\n";
	}

	if (isset($surveyCompletes[$recordId])) {
		if (isset($surveyCompletes[$recordId]["followup"])) {
			echo "<td class='centered'>".$surveyCompletes[$recordId]["followup"]."</td>\n";
		} elseif (isset($surveyCompletes[$recordId]['initial_survey'])) {
			echo "<td class='centered'>".$surveyCompletes[$recordId]["initial_survey"]."</td>\n";
		} else {
			echo "<td class='red centered'>Missing</td>";
		}
	} else {
		echo "<td class='red centered'>Missing</td>\n";
	}

	echo "</tr>\n";
	$i++;
}
?>
</table>
