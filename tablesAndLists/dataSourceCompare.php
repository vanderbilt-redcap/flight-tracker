<style>
td.grants { vertical-align: top; padding: 5px; }
.blue { color: black; }
.smaller { font-size: 12px; }
.centered { text-align: center; }
.label { margin-top: 3px; margin-bottom: 3px; }
</style>

<?php

use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../classes/Grants.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Scholar.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../wrangler/baseSelect.php");

$records = Download::recordIds($token, $server);

if (isset($_GET['record'])) {
	$record = $_GET['record'];
} else {
	$record = 1;
}
$nextRecord = \Vanderbilt\FlightTrackerExternalModule\getNextRecord($record);

if (!isset($_GET['headers']) || ($_GET['headers'] != "false")) {
	echo "<table style='margin-left: auto; margin-right: auto;'><tr>\n";
	echo "<td class='yellow' style='text-align: center;'><a href='".Application::link('/tablesAndLists/dataSourceCompare.php')."&record=$nextRecord'>View Next Record</a></td>\n";
	echo "</tr></table>\n";
}

$recordIndex = 0;
$i = 0;
foreach ($records as $rec) {
	if ($rec == $record) {
		$recordIndex = $i;
		if ($i + 1 < count($records)) {
			$nextRecordIndex = $i + 1;
		} else {
			$nextRecordIndex = 0;
		}
		break;
	}
	$i++;
}

$nextRecord = $records[$nextRecordIndex];
$redcapData = Download::records($token, $server, array($record));

$metadata = Download::metadata($token, $server);
$scholar = new Scholar($token, $server, $metadata);
$scholar->setRows($redcapData);
$grants = new Grants($token, $server, $metadata);
$grants->setRows($redcapData);
$scholar->setGrants($grants);

$inUse = array();
foreach ($grants->getGrants("prior") as $grant) {
	$src = $grant->getVariable("source");
	if (!isset($inUse[$src])) {
		$inUse[$src] = array();
	}

	array_push($inUse[$src], $grant);
}

$native = array();
foreach ($grants->getGrants("native") as $grant) {
	$src = $grant->getVariable("source");
	if (!isset($native[$src])) {
		$native[$src] = array();
	}

	array_push($native[$src], $grant);
}

$name = $scholar->getNameAry();

# header
if (isset($_GET['header']) && (strtolower($_GET['header']) != "false")) {
	echo "<table style='margin-left: auto; margin-right: auto;'><tr>\n";
	echo "<td>".getCompareSelectRecord()."</td>\n";
	echo "<td><span style='font-size: 12px;'>Last/Full Name:</span><br><input id='search' type='text' style='width: 100px;'><br><div style='width: 100px; color: red; font-size: 11px;' id='searchDiv'></div></td>\n";

	$nextPageLink = Application::link("tablesAndLists/dataSourceCompare.php")."&record=".$nextRecord;
	# next record is in the same window => don't use Links class
	echo "<td class='yellow' style='text-align: center;'><a href='$nextPageLink'>View Next Record</a></td>\n";
	echo "<td class='blue'>".Links::makeSummaryLink($pid, $record, $event_id, "View REDCap")."</td>\n";
	echo "</tr></table>\n";
}

echo "<h1>Data Source Comparison</h1>\n";
echo "<h2>{$name['identifier_first_name']} {$name['identifier_last_name']}</h2>\n";
// echo "<h3 id='conflicts'></h3>\n";
echo "<table style='border: 1px dotted #888888; margin-left: auto; margin-right: auto;'>\n";
echo "<tr><td colspan='2' class='centered grants'><b>Legend</b></td></tr>\n";
echo "<tr><td class='green grants'>Grant In Use</td><td class='blue grants'>Conflict with Grant In Use</td></tr>\n";
echo "</table>\n";

$vars = array(
		"direct_budget" => "Direct Budget",
		"budget" => "Total Budget",
		"start" => "Start Date",
		"end" => "End Date",
		// "fAndA" => "F &amp; A",
		"finance_type" => "Finance Type",
		"type" => "Grant Type",
		);
echo "<table style='margin-left: auto; margin-right: auto;'><tr>\n";
$conflicts = 0;
foreach (Grants::getSourceOrderWithLabels() as $src => $label) {
	if (isset($native[$src])) {
		$grantsAry = $native[$src];
		$numGrants = 0;
		foreach ($grantsAry as $grant) {
			if ($grant->getNumber()) {
				$numGrants++;
			}
		}
		if ($numGrants > 0) {
			echo "<td class='grants'>\n";
			echo "<h4>$label</h4>\n";
			foreach ($grantsAry as $grant) {
				if ($grant->getNumber()) {
					echo "<div class='grant'>\n";
					$currGrantInUse = FALSE;
					foreach ($inUse[$src] as $usedGrant) {
						if ($usedGrant->getBaseAwardNumber() == $grant->getBaseAwardNumber()) {
							echo "<div class='green centered'>IN USE</div>\n";
							$currGrantInUse = TRUE;
							break;
						}
					}

					echo "<p class='label centered'><span class='smaller'>Sponsor No.:</span><br>".$grant->getNumber()."</p>\n";
					echo "<p class='label centered'><span class='smaller'>Base Award No.:</span><br><b>".$grant->getBaseAwardNumber()."</b></p>\n";
					foreach ($vars as $var => $label) {
						$currValue = $grant->getVariable($var);
						if (preg_match("/Budget/", $label)) {
							$currValue = \Vanderbilt\FlightTrackerExternalModule\prettyMoney($currValue);
						}

						$span = "";
						$closeSpan = "";
						if (!$currGrantInUse) {
							$matchesOneGrant = FALSE;
							$baseAwardNumbersSame = FALSE;
							foreach ($inUse as $usedSrc => $usedGrants) {
								# used groups are combined
								foreach ($usedGrants as $usedGrant) {
									# now loop through pre-combined grants 
									foreach ($grants->getGrants("native") as $otherGrant) {
										if ($grant->matchesGrant($otherGrant, $var)) {
											$matchesOneGrant = TRUE;
										}
									}
									if ($usedGrant->getBaseAwardNumber() == $grant->getBaseAwardNumber()) {
										$baseAwardNumbersSame = TRUE;
									}
								}
							}
							if (!$matchesOneGrant && $baseAwardNumbersSame) {
								$span = "<span class='blue'>";
								$closeSpan = "</span>";
								$conflicts++;
							}
						}
						echo "<p class='label centered'><span class='smaller'>".$label.":</span><br>".$span.$currValue.$closeSpan."</p>\n";
					}
					echo "</div>\n";
					echo "<hr>\n";
				}
			}
			echo "</td>\n";
		}
	}
}
echo "</tr></table>\n";

?>
<script>
$(document).ready(function() {
	$('#conflicts').html('<?= $conflicts ?> Conflicts');
});
</script>

<?php

function getCompareSelectRecord() {
	global $token, $server;

	$names = Download::names($token, $server);

	$html = "Record: <select id='refreshRecord' onchange='refreshForRecord(\"coeusFederalCompare.php\");'><option value=''>---SELECT---</option>";
	foreach ($names as $record => $name) {
	    $html .= "<option value='$record'>$record: $name</option>";
	}
	$html .= "</select>";
	return $html;
}
