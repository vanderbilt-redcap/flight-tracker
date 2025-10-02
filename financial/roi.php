<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Grants;
use Vanderbilt\CareerDevLibrary\Cohorts;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(APP_PATH_DOCROOT."Classes/System.php");

Application::increaseProcessingMax(1);

$totalClassName = "Non-K Sources";
if (isset($_POST['cohort']) && isset($_POST['yearsToConvert'])) {
	require_once(dirname(__FILE__)."/../small_base.php");
	$yearsToConvert = $_POST['yearsToConvert'];
	$cohort = REDCapManagement::sanitizeCohort($_POST['cohort']);
	if ($cohort == "all") {
		$records = Download::records($token, $server);
	} else {
		$records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
	}
	if (isset($_GET['showFlagsOnly'])) {
		$grantType = "flagged";
	} else {
		$grantType = "all_pis";
	}
	$names = Download::names($token, $server);
	$records = excludeRecentKs($token, $server, $records, $yearsToConvert);
	$metadata = Download::metadata($token, $server);
	$fields = REDCapManagement::getMinimalGrantFields($metadata);
	$grantsDirectDollars = getBlankGrantArray();
	$grantsTotalDollars = getBlankGrantArray();
	foreach ($records as $recordId) {
		$redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
		$grants = new Grants($token, $server, $metadata);
		$grants->setRows($redcapData);
		$grants->compileGrants();
		foreach ($grants->getGrants($grantType) as $grant) {
			$direct = $grant->getVariable("direct_budget") ?? 0;
			$total = $grant->getVariable("budget") ?? 0;
			$grantClass = $grant->getVariable("type");
			$grantClasses = [
				"Internal K" => ["Internal K"],
				"K12/KL2" => ["K12/KL2"],
				"Individual K" => ["Individual K"],
				"K Equivalent" => ["K Equivalent"],
				"All Internal K Resources (Internal K and K12/KL2)" => ["Internal K", "K12/KL2"],
				"All K Sources" => ["Internal K", "K12/KL2", "Individual K", "K Equivalent"],
				"Non-K Sources" => "all",
			];
			foreach ($grantClasses as $className => $classTypes) {
				if (($classTypes == "all") || in_array($grantClass, $classTypes)) {
					if ($direct) {
						if (!isset($grantsDirectDollars[$className][$recordId])) {
							$grantsDirectDollars[$className][$recordId] = [];
						}
						$grantsDirectDollars[$className][$recordId][] = $direct;
					}
					if ($total) {
						if (!isset($grantsTotalDollars[$className][$recordId])) {
							$grantsTotalDollars[$className][$recordId] = [];
						}
						$grantsTotalDollars[$className][$recordId][] = $total;
					} elseif ($direct) {
						if (!isset($grantsTotalDollars[$className][$recordId])) {
							$grantsTotalDollars[$className][$recordId] = [];
						}
						$grantsTotalDollars[$className][$recordId][] = $direct;
					}
				}
			}
		}
	}

	$data = [];
	$data['foldIncreaseDirect'] = getBlankGrantArray();
	$data['foldIncreaseTotal'] = getBlankGrantArray();
	$data['textDirect'] = getBlankGrantArray();
	$data['textTotal'] = getBlankGrantArray();
	$naValue = "N/A";
	foreach (array_keys(getBlankGrantArray()) as $className) {
		if ($className != $totalClassName) {
			$classDirectDollars = sumAmounts($grantsDirectDollars[$className]);
			$classTotalDollars = sumAmounts($grantsTotalDollars[$className]);
			$classDirectCounts = countEntries($grantsDirectDollars[$className], "all");
			$classTotalCounts = countEntries($grantsTotalDollars[$className], "all");
			$classDirectRecords = getRecords($grantsDirectDollars[$className]);
			$classTotalRecords = getRecords($grantsTotalDollars[$className]);

			$totalDirectDollars = sumAmounts($grantsDirectDollars[$totalClassName], $classDirectRecords);
			$totalTotalDollars = sumAmounts($grantsTotalDollars[$totalClassName], $classTotalRecords);
			$totalDirectCounts = countEntries($grantsDirectDollars[$totalClassName], $classDirectRecords);
			$totalTotalCounts = countEntries($grantsTotalDollars[$totalClassName], $classTotalRecords);

			$directNames = [];
			$totalNames = [];
			foreach ($classDirectRecords as $recordId) {
				$directNames[] = $names[$recordId];
			}
			foreach ($classTotalRecords as $recordId) {
				$totalNames[] = $names[$recordId];
			}

			$htmlId = REDCapManagement::makeHTMLId($className);
			$data['foldIncreaseTotal'][$className] = (($totalTotalDollars > 0) && ($classTotalDollars > 0)) ? $totalTotalDollars / $classTotalDollars : $naValue;
			$data['foldIncreaseDirect'][$className] = (($totalDirectDollars > 0) && ($classDirectDollars > 0)) ? $totalDirectDollars / $classDirectDollars : $naValue;
			$data['textDirect'][$className] = "Based on $classDirectCounts K grants &amp; $totalDirectCounts PI/Co-PI grants <a href='javascript:;' onclick='$(\"#directNames$htmlId\").show();'>across ".count($classDirectRecords)." scholars</a>.<br/>(" . REDCapManagement::prettyMoney($totalDirectDollars) . " / " . REDCapManagement::prettyMoney($classDirectDollars) . ")<span id='directNames$htmlId' class='smaller' style='display: none;'><br/><strong>Names</strong><br/>".(empty($directNames) ? "None" : implode("<br/>", $directNames))."</span>";
			$data['textTotal'][$className] = "Based on $classTotalCounts K grants &amp; $totalTotalCounts PI/Co-PI grants <a href='javascript:;' onclick='$(\"#totalNames$htmlId\").show();'>across ".count($classTotalRecords)." scholars</a>.<br/>(" . REDCapManagement::prettyMoney($totalTotalDollars) . " / " . REDCapManagement::prettyMoney($classTotalDollars) . ")<span id='totalNames$htmlId' class='smaller' style='display: none;'><br/><strong>Names</strong><br/>".(empty($totalNames) ? "None" : implode("<br/>", $totalNames))."</span>";
		}
	}
	echo json_encode($data);
	exit();
} else {
	require_once(dirname(__FILE__)."/../charts/baseWeb.php");
	$yearsToConvert = [
		Application::getIndividualKLength(),
		Application::getK12KL2Length(),
		Application::getInternalKLength(),
	];
	$maxKLength = max($yearsToConvert);

	$cohorts = new Cohorts($token, $server, Application::getModule());
	$thisLink = Application::link("this");
	$html = "";
	$html .= "<style>
.finalNumber { font-size: 40px; font-weight: bold; }
</style>";
	$html .= Grants::makeFlagLink($pid);
	$html .= "<h1>Financial Return on Investment</h1>";
	$html .= "<p class='centered max-width'>Only includes grants in which the scholar is a PI or a Co-PI (i.e., no co-investigator awards or subcontracts). To calculate, this page requires that dollar figures be assigned to all relevant Internal K and K12/KL2 grants. Also, note that a bias exists in these results in that the longer a scholar has been active, the higher the results.</p>";
	$html .= "<p class='centered max-width'>".$cohorts->makeCohortSelect("all", "", true)."</p>";
	$html .= "<p class='centered max-width'>Exclude scholars in training by giving <input style='width: 50px;' type='number' id='yearsSinceK' value='$maxKLength'> years to convert.</p>";
	$html .= "<p class='centered max-width red'>Warning! This can take a significant amount of time to complete!</p>";
	$html .= "<p class='centered'><button onclick='calculateROI(\"#cohort\", \"#results\", $(\"#yearsSinceK\").val()); return false;'>Calculate ROI</button></p>";
	$html .= "<div id='results'></div>";
	$blankGrantArrayKeysJSON = json_encode(array_keys(getBlankGrantArray()));
	$html .= "<script>
function calculateROI(cohortOb, resultsOb, yearsToConvert) {
    const cohort = $(cohortOb).val();
    const startTime = new Date().getTime();
    presentScreen('Calculating... (May take some time)');
    $.post('$thisLink', { 'redcap_csrf_token': getCSRFToken(), cohort: cohort, yearsToConvert: yearsToConvert }, function(json) {
        clearScreen();
        const endTime = new Date().getTime();
        const elapsedMins = Math.round((endTime - startTime) / (60 * 1000));
        console.log(json);
        console.log('Took '+elapsedMins+' minutes');
        const data = JSON.parse(json);
        const blankGrantAryKeys = $blankGrantArrayKeysJSON;
        const totalClassName = '$totalClassName';
        
        let html = '';
        html += '<table class=\"centered max-width\"><tbody>';
        for (let i = 0; i < blankGrantAryKeys.length; i++) {
            const className = blankGrantAryKeys[i];
            if (className !== totalClassName) {
                const increaseDirect = prettyWithDecimals(data['foldIncreaseDirect'][className], 1);
                const increaseTotal = prettyWithDecimals(data['foldIncreaseTotal'][className], 1);
                const textDirect = data['textDirect'][className];
                const textTotal = data['textTotal'][className];

                html += '<tr><td><h3>'+className+'</h3></td></tr>';
                html += '<tr style=\"margin-bottom: 20px;\">';
                html += '<td><h4>X-Fold Increase in Direct Dollars</h4><p class=\"centered smaller nomargin\">(Includes all grants with PI/Co-PI, including K grants.)</p><p class=\"centered\"><span class=\"finalNumber\">'+increaseDirect+'</span></p><p class=\"centered smaller\">'+textDirect+'</p></td>';
                html += '<td><h4>X-Fold Increase in Total Dollars</h4><p class=\"centered smaller nomargin\">(Total dollars preferred source; if missing in data, use direct dollars. Includes all grants with PI/Co-PI, including K grants.)</p><p class=\"centered\"><span class=\"finalNumber\">'+increaseTotal+'</span></p><p class=\"centered smaller\">'+textTotal+'</p></td>';
                html += '</tr>';
            }
        }
        html += '</tbody></table>';
        
        $(resultsOb).html(html);
    });
}

function prettyWithDecimals(amt, decimals) {
    if (isNaN(amt) || isNaN(decimals)) {
        return amt;
    }
    const factor = Math.pow(10, decimals);
    const base = Math.round(amt * factor);
    return base / factor;
}

</script>";
	echo $html;
}

function sumAmounts($classAry, $records = "all") {
	$total = 0;
	foreach ($classAry as $recordId => $amounts) {
		if (($records == "all") || in_array($recordId, $records)) {
			$total += array_sum($amounts);
		}
	}
	return $total;
}

function countEntries($classAry, $recordsToCount) {
	$total = 0;
	foreach ($classAry as $recordId => $amounts) {
		if (($recordsToCount == "all") || in_array($recordId, $recordsToCount)) {
			$total += count($amounts);
		}
	}
	return $total;
}

function getRecords($classAry) {
	return array_keys($classAry);
}

function getBlankGrantArray() {
	return [
		"Internal K" => [],
		"K12/KL2" => [],
		"Individual K" => [],
		"K Equivalent" => [],
		"All Internal K Resources (Internal K and K12/KL2)" => [],
		"All K Sources" => [],
		"Non-K Sources" => [],
	];
}

function excludeRecentKs($token, $server, $records, $yearsToConvert) {
	if (!is_numeric($yearsToConvert)) {
		return $records;
	}
	$lastKs = Download::oneField($token, $server, "summary_last_any_k");
	$thresholdDate = date("Y-m-d", strtotime("-$yearsToConvert years"));
	$newRecords = [];
	foreach ($records as $recordId) {
		if ($lastKs[$recordId] === "") {
			$newRecords[] = $recordId;
		} elseif (!REDCapManagement::isDate($lastKs[$recordId])) {
			$newRecords[] = $recordId;
		} elseif (REDCapManagement::dateCompare($lastKs[$recordId], "<=", $thresholdDate)) {
			$newRecords[] = $recordId;
		}
	}
	return $newRecords;
}
