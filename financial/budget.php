<?php

use Vanderbilt\CareerDevLibrary\Grants;
use Vanderbilt\CareerDevLibrary\Grant;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Cohorts;
use Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(APP_PATH_DOCROOT."Classes/System.php");

Application::increaseProcessingMax(1);
if ($_POST['date'] && REDCapManagement::isDate($_POST['date'])) {
	$date = Sanitizer::sanitizeDate($_POST['date']);
	$ts = strtotime($date);
} else {
	$ts = time();
}

if (isset($_GET['timespan']) && is_string($_GET['timespan'])) {
	$getTimespan = Sanitizer::sanitize($_GET['timespan']);
} else {
	$getTimespan = "active";
}
if ($getTimespan == "active") {
	$timespan = "Active";
} elseif ($getTimespan == "all") {
	$timespan = "All Time";
} else {
	$timespan = "Active";
}

if (isset($_GET['cohort'])) {
	$cohort = Sanitizer::sanitizeCohort($_GET['cohort']);
} else {
	$cohort = "";
}

if (isset($_GET['showFlagsOnly'])) {
	$grantType = "flagged";
} else {
	$grantType = "all_pis";
}

$metadata = Download::metadata($token, $server);
$fields = REDCapManagement::getMinimalGrantFields($metadata);
$names = Download::names($token, $server);
$choices = REDCapManagement::getChoices($metadata);
$recordsByDept = [];
if ($cohort) {
	$records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else {
	$records = Download::recordIds($token, $server);
}
$departments = Download::oneField($token, $server, "summary_primary_dept");
$hasDepartmentInfo = false;
foreach ($records as $recordId) {
	if ($departments[$recordId]) {
		if (isset($_GET['test'])) {
			echo "Record $recordId has ".$departments[$recordId]."<br>";
		}
		$hasDepartmentInfo = true;
		break;
	}
}
$metadataForms = REDCapManagement::getFormsFromMetadata($metadata);
if (Application::isVanderbilt() && in_array("ldap", $metadataForms) && !$hasDepartmentInfo) {
	if (isset($_GET['test'])) {
		echo "Downloading LDAP info<br>";
	}
	$ldapData = Download::fields($token, $server, ["record_id", "ldap_departmentnumber", "ldapds_departmentnumber"]);
	foreach ($ldapData as $row) {
		$dept = "";
		$recordId = $row['record_id'];
		if ($row['redcap_repeat_instrument'] == "ldap") {
			$dept = $row['ldap_departmentnumber'];
		} elseif ($row['redcap_repeat_instrument'] == "ldapds") {
			$dept = $row['ldapds_departmentnumber'];
		}
		if ($dept) {
			if (!isset($recordsByDept[$dept])) {
				$recordsByDept[$dept] = [];
			}
			$recordsByDept[$dept][] = $recordId;
		}
	}
} else {
	foreach ($records as $recordId) {
		$deptIdx = $departments[$recordId];
		if (isset($choices["summary_primary_dept"][$deptIdx])) {
			$dept = $choices["summary_primary_dept"][$deptIdx];
		} else {
			$dept = "Unspecified";
		}
		if (!isset($recordsByDept[$dept])) {
			$recordsByDept[$dept] = [];
		}
		$recordsByDept[$dept][] = $recordId;
	}
}

$headers = [
	"numFaculty" => "Number of Faculty",
	"numNIHGrants" => "Number of NIH Grants ($timespan)",
	"dollarsNIHDirect" => "Direct Dollars of NIH Grants ($timespan)",
	"dollarsNIHTotal" => "Total Dollars of NIH Grants ($timespan)",
	"numFederalGrants" => "Number of Federal Grants ($timespan)",
	"dollarsFederalDirect" => "Direct Dollars of Federal Grants ($timespan)",
	"dollarsFederalTotal" => "Total Dollars of Federal Grants ($timespan)",
	"numAllGrants" => "Number of All Grants ($timespan)",
	"dollarsAllDirect" => "Direct Dollars of All Grants ($timespan)",
	"dollarsAllTotal" => "Total Dollars of All Grants ($timespan)",
];
$table = [];
foreach ($recordsByDept as $dept => $records) {
	$table[$dept] = [];
	foreach (["NIH", "Federal", "All"] as $type) {
		$table[$dept]['num'.$type.'Grants'] = 0;
		$table[$dept]['dollars'.$type.'Direct'] = 0.0;
		$table[$dept]['dollars'.$type.'Total'] = 0.0;
	}
	$table[$dept]['grantsSeenDirect'] = [];
	$table[$dept]['grantsSeenTotal'] = [];
}
$table['Total'] = [];
foreach (array_keys($headers) as $key) {
	$table['Total'][$key] = 0;
}
$table["Total"]['grantsSeenDirect'] = [];
$table["Total"]['grantsSeenTotal'] = [];
$table["Total"]['numFaculty'] = 0;

$figures = [];
$notes = ["No-Cost Extensions are not represented in these data.", "The NIH RePORTER's API does not have Direct Dollars available."];
$d = array_values($recordsByDept);
foreach ($recordsByDept as $dept => $records) {
	$numRecords = count($records);
	if (is_numeric($numRecords)) {
		$table[$dept]['numFaculty'] = $numRecords;
		$table["Total"]['numFaculty'] += $numRecords;
	}
	foreach ($records as $recordId) {
		$redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
		$grants = new Grants($token, $server, $metadata);
		$grants->setRows($redcapData);
		$grants->compileGrants();
		$grantAry = $grants->getGrants($grantType);
		foreach ($grantAry as $grant) {
			$baseAwardNumber = $grant->getBaseAwardNumber();
			$figureRow = ["Record" => $recordId, "Name" => $names[$recordId], "Grant" => $baseAwardNumber];
			if ($timespan == "Active") {
				if (
					($grant->getActiveBudgetAtTime($redcapData, "Total", $ts) > 0)
					&& ($grant->getActiveBudgetAtTime($redcapData, "Direct", $ts) == 0.0)
					&& ($grant->getVariable("source") !== "nih_reporter")
				) {
					$notes[] = "No direct budget for Record $recordId ".$baseAwardNumber;
				}
			}
			foreach (["Direct", "Total"] as $type) {
				if ($timespan == "Active") {
					$dollars = $grant->getActiveBudgetAtTime($redcapData, $type, $ts);
				} elseif ($timespan == "All Time") {
					$dollars = $grant->getBudget($redcapData, $type);
				} else {
					throw new \Exception("Invalid timespan $timespan");
				}
				$figureRow[$type." $timespan Dollars"] = REDCapManagement::prettyMoney($dollars);
				$is = ["NIH" => $grant->isNIH(), "Federal" => $grant->isFederal(), ];
				foreach ([$dept, "Total"] as $tableRowName) {
					if (!in_array($baseAwardNumber, $table[$tableRowName]['grantsSeen'.$type])) {
						if ($dollars > 0) {
							if ($type == "Total") {    // count only once
								foreach ($is as $class => $b) {
									if ($b) {
										$table[$tableRowName]['num'.$class.'Grants']++;
									}
								}
								$table[$tableRowName]['numAllGrants']++;
							}
							foreach ($is as $class => $b) {
								if ($b) {
									$table[$tableRowName]['dollars' . $class . $type] += $dollars;
								}
							}
							$table[$tableRowName]['dollarsAll' . $type] += $dollars;
						}
						$table[$tableRowName]['grantsSeen'.$type][] = $baseAwardNumber;
					}
				}
			}
			$figures[] = $figureRow;
		}
	}
}
$currChosenDate = date("Y-m-d", $ts);
$mdyCurrDate = date("m-d-Y", $ts);
$link = Application::link("this");
$linkWithParams = $link.(isset($_GET['cohort']) ? "&cohort=".urlencode($cohort) : "").(isset($_GET['timespan']) ? "&timespan=".urlencode($getTimespan) : "");
echo Grants::makeFlagLink($pid, $linkWithParams);
echo "<h1>$timespan Grants in Project at $mdyCurrDate</h1>";
if ($timespan == "Active") {
	echo "<form action='$link' method='POST'>";
	echo Application::generateCSRFTokenHTML();
	echo "<p class='centered'>Active on <input type='date' name='date' value='$currChosenDate'></p>";
	echo "<p class='centered'><button>Recalibrate</button></p>";
	echo "</form>";
}

if (!empty($notes)) {
	echo "<p class='centered red'>".implode("<br>", $notes)."</p>";
} else {
	echo "<p class='centered'>No errors detected.</p>";
}

$cohorts = new Cohorts($token, $server, Application::getModule());
$thisUrl = Application::link("this");
echo "<p class='centered'>".$cohorts->makeCohortSelect($cohort, "location.href = \"$thisUrl&timespan=$getTimespan&cohort=\"+$(this).val();")."</p>";
echo "<table class='centered max-width bordered'>";
echo "<thead>";
echo "<tr>";
echo "<th>Group</th>";
foreach ($headers as $key => $header) {
	echo "<th>$header</th>";
}
echo "</tr>";
echo "</thead>";
echo "<tbody>";
foreach ($table as $dept => $data) {
	echo "<tr>";
	echo "<th>$dept</th>";
	foreach (array_keys($headers) as $key) {
		if (preg_match("/dollars/i", $key)) {
			echo "<td>" . REDCapManagement::prettyMoney($data[$key]) . "</td>";
		} else {
			echo "<td>" . REDCapManagement::pretty($data[$key]) . "</td>";
		}
	}
	echo "</tr>";
}
echo "</tbody>";
echo "</table>";

if (isset($_GET['test']) || isset($_GET['testTable'])) {
	echo "<br><br><br>";
	echo "<h2>Details...</h2>";
	echo "<table class='min-width centered bordered'>";
	$first = true;
	$figureHeaders = [];
	foreach ($figures as $figureRow) {
		if ($first) {
			$first = false;
			echo "<thead>";
			echo "<tr>";
			$figureHeaders = array_keys($figureRow);
			foreach ($figureHeaders as $header) {
				echo "<th>$header</th>";
			}
			echo "</tr>";
			echo "</thead>";
			echo "<tbody>";
		}
		echo "<tr>";
		foreach ($figureHeaders as $header) {
			echo "<td>{$figureRow[$header]}</td>";
		}
		echo "</tr>";
	}
	echo "</tbody></table>";
}
