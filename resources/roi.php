<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\Stats;
use Vanderbilt\CareerDevLibrary\CohortStudy;
use Vanderbilt\CareerDevLibrary\ComparisonStudy;
use Vanderbilt\CareerDevLibrary\Cohorts;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$metadata = Download::metadata($token, $server);
if (isset($_GET['cohort'])) {
	$cohort = REDCapManagement::sanitizeCohort($_GET['cohort']);
	$records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else {
	$records = Download::records($token, $server);
	$cohort = "";
}
$choices = REDCapManagement::getChoices($metadata);
$today = date("Y-m-d");
$maxKLength = max([Application::getIndividualKLength(), Application::getK12KL2Length(), Application::getInternalKLength(), ]);
$pThreshold = 0.05;

$fields = ["record_id", "summary_last_any_k", "summary_first_r01_or_equiv", "summary_ever_last_any_k_to_r01_equiv", "identifier_left_date", "citation_include", "citation_rcr", "resources_resource"];
$data = [];
$resources = [];
foreach ($records as $recordId) {
	$rcrs = [];
	$resourcesUsed = [];
	$conversionStatus = "";
	$conversionTime = "";
	$yearsToConvert = "";
	$numIncludedPubs = 0;
	$redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
	$leftAndDidntConvert = false;
	$stillOnK = false;
	foreach ($redcapData as $row) {
		if (($row['redcap_repeat_instrument'] == "citation") && ($row['citation_include'] == "1")) {
			$numIncludedPubs++;
			if ($row['citation_rcr']) {
				$rcrs[] = $row['citation_rcr'];
			}
		} elseif ($row['redcap_repeat_instrument'] == "") {
			$conversionStatus = $row['summary_ever_last_any_k_to_r01_equiv'];
			if ($row['summary_first_r01_or_equiv'] && $row['summary_last_any_k']) {
				$yearsToConvert = REDCapManagement::datediff($row['summary_last_any_k'], $row['summary_first_r01_or_equiv'], "y", false);
			}
			if ($row['summary_last_any_k']) {
				$stillOnK = (REDCapManagement::datediff($row['summary_last_any_k'], $today, "y", false) <= $maxKLength);
			} else {
				$stillOnK = true;
			}
			$leftAndDidntConvert = (in_array($row['summary_ever_last_any_k_to_r01_equiv'], [4, 6]) && $row['identifier_left_date']);
		} elseif (($row['redcap_repeat_instrument'] == "resources") && $row['resources_resource']) {
			$resource = $choices['resources_resource'][$row['resources_resource']];
			if (!in_array($resource, $resourcesUsed)) {
				$resourcesUsed[] = $resource;
			}
		}
	}
	$dataRow = [];
	if (!$leftAndDidntConvert && !$stillOnK) {
		$dataRow["Conversion Status"] = $conversionStatus;
		$dataRow["Years to Convert"] = $yearsToConvert;
	}
	$dataRow["Number of Publications"] = $numIncludedPubs;
	if (!empty($rcrs)) {
		$dataRow["Average Relative Citation Ratio"] = array_sum($rcrs) / count($rcrs);
	}
	$data[$recordId] = $dataRow;
	$resources[$recordId] = $resourcesUsed;
}

$allResources = $choices['resources_resource'];
$dataByResource = [];
foreach ($allResources as $resourceIdx => $resource) {
	$dataByResource[$resource] = ["Control" => [], "Treatment" => []];
	foreach ($resources as $recordId => $resourcesUsed) {
		if (in_array($resource, $resourcesUsed)) {
			$group = "Treatment";
		} else {
			$group = "Control";
		}
		foreach ($data[$recordId] as $measure => $value) {
			if (!isset($dataByResource[$resource][$group][$measure])) {
				$dataByResource[$resource][$group][$measure] = [];
			}
			if ($value) {
				$dataByResource[$resource][$group][$measure][] = $value;
			}
		}
	}
}

$measuresInOrder = ["Conversion Ratio", "Years to Convert", "Number of Publications", "Average Relative Citation Ratio"];
echo "<h1>Return on Investment for Resources</h1>";
$cohorts = new Cohorts($token, $server, Application::getModule());
$link = Application::link("this");
echo "<p class='centered'>".$cohorts->makeCohortSelect($cohort, "if ($(this).val()) { window.location.href = \"$link&cohort=\"+$(this).val(); } else { window.location.href = \"$link\"; }")."</p>";
$groupsInOrder = ["Control" => "Did <u>Not</u> Use Resource", "Treatment" => "Used Resource", ];
foreach ($dataByResource as $resource => $groups) {
	$results = [];
	foreach (array_keys($groupsInOrder) as $group) {
		$results[$group] = [];
		foreach ($dataByResource[$resource][$group] as $measure => $values) {
			if (in_array($measure, ["Conversion Status"])) {
				$newLabel = "Conversion Ratio";
				$result = calculateConversionRate($values);
				$results[$group][$newLabel] = $result;
			} else {
				$result = calculateAverages($values);
				$results[$group][$measure] = $result;
			}
		}
	}

	echo "<h3>Results for $resource</h3>";
	if (resultsPresentForAllGroups($results)) {
		echo "<table class='centered bordered'><tr><th>Result</th>";
		foreach (array_values($groupsInOrder) as $label) {
			echo "<th>$label</th>";
		}
		echo "</tr>";
		foreach ($measuresInOrder as $measure) {
			$isDiscrete = ($measure == "Conversion Ratio");
			echo "<tr><td colspan='3' class='green'><h4 class='nomargin'>Effect of $resource on $measure</h4></td></tr>";
			echo "<tr>";
			echo "<th>Mean (&mu;) for $measure</th>";
			foreach (array_keys($groupsInOrder) as $group) {
				$mu = REDCapManagement::pretty($results[$group][$measure]['mu'], 1);
				echo "<td class='centered bolded'>&mu; = $mu</td>";
			}
			echo "</tr>";
			if (($results["Control"][$measure]['n'] > 0) && ($results["Treatment"][$measure]['n'] > 0)) {
				if ($isDiscrete) {
					$oddsRatio = "";
					$study = new ComparisonStudy($results["Control"][$measure]["values"], $results["Treatment"][$measure]["values"]);
					$oddsRatio = $study->getOddsRatio();
					// echo "<tr>";
					// echo "<th>Observations</th>";
					// foreach (array_keys($groupsInOrder) as $group) {
					// $n = json_encode($results[$group][$measure]["values"]);
					// echo "<td class='centered bolded'>$n</td>";
					// }
					// echo "</tr>";

					echo "<tr>";
					echo "<th>Cases (n) for $measure</th>";
					foreach (array_keys($groupsInOrder) as $group) {
						$n = REDCapManagement::pretty($study->getN($group));
						echo "<td class='centered'>n = $n</td>";
					}
					echo "</tr>";

					echo "<tr>";
					echo "<th>Number Successful</th>";
					foreach (array_keys($groupsInOrder) as $group) {
						$n = REDCapManagement::pretty($study->getNumberHealthy($group), 0);
						echo "<td class='centered bolded'>$n</td>";
					}
					echo "</tr>";
					echo "<tr>";
					echo "<th>Number Not Successful</th>";
					foreach (array_keys($groupsInOrder) as $group) {
						$n = REDCapManagement::pretty($study->getNumberDiseased($group), 0);
						echo "<td class='centered bolded'>$n</td>";
					}
					echo "</tr>";

					echo "<tr>";
					echo "<th><a href='https://www.ncbi.nlm.nih.gov/pmc/articles/PMC2938757/'>Odds Ratio</a></th>";
					echo "<td class='centered' colspan='2'>OR = ".REDCapManagement::pretty($oddsRatio, 2)."</td>";
					echo "</tr>";

					echo "<tr>";
					echo "<th>Interpretation</th>";
					echo "<td class='centered' colspan='2' style='max-width: 500px;'>Those using the resource are ".REDCapManagement::pretty($oddsRatio, 2)." times more likely to have a good outcome.</td>";
					echo "</tr>";
				} else {
					$study = new CohortStudy($results["Control"][$measure]["values"], $results["Treatment"][$measure]["values"]);
					$t = $study->getControl()->unpairedTTest($study->getTreatment());
					$df = $study->getControl()->getDegreesOfFreedom() + $study->getTreatment()->getDegreesOfFreedom();
					$p = $study->getP("unpaired");
					$sigma = ["Control" => $study->getControl()->getSigma(), "Treatment" => $study->getTreatment()->getSigma(), ];
					$ciPercents = [95];
					$ci = [];
					$mean = "";
					if ($p != Stats::$nan) {
						foreach ($ciPercents as $ciPercent) {
							$ci[$ciPercent] = $study->getTreatmentCI($ciPercent);
						}

						if ($p <= $pThreshold) {
							$interpretation = "There is a statistically significant difference in outcomes between those who used the resource and those who did not. (p <= ".REDCapManagement::pretty($pThreshold, 2).")";
						} else {
							$interpretation = "There is not a statistically significant difference in outcomes between those who used the resource and those who did not. Perhaps having a larger sample-size (n) - i.e., more power - would provide more insight. (p > ".REDCapManagement::pretty($pThreshold, 2).")";
						}
					} else {
						$p = Stats::$nan;
						$interpretation = "";
					}

					// echo "<tr>";
					// echo "<th>Null Hypothesis (H<sub>0</sub>)</th>";
					// echo "<td class='centered' colspan='2'>Using $resource has no effect on the Scholars' $measure</td>";
					// echo "</tr><tr>";
					// echo "<th>Alternative Hypothesis (H<sub>A</sub>)</th>";
					// echo "<td class='centered' colspan='2'>Using $resource has an effect on the Scholars' $measure</td>";
					// echo "</tr>";
					echo "<tr>";
					echo "<th>Size (n) for $measure</th>";
					foreach (array_keys($groupsInOrder) as $group) {
						$n = REDCapManagement::pretty($study->getN($group));
						echo "<td class='centered'>n = $n</td>";
					}
					echo "</tr>";

					echo "<tr>";
					echo "<th>Standard Deviation (&sigma;) for $measure</th>";
					foreach (array_keys($groupsInOrder) as $group) {
						$stddev = REDCapManagement::pretty($sigma[$group], 1);
						echo "<td class='centered'>&sigma; = $stddev</td>";
					}
					echo "</tr>";
					$lines = [];
					$lines[] = "p = " . REDCapManagement::pretty($p, 3);
					if ($p != Stats::$nan) {
						$lines[] = "t = ".REDCapManagement::pretty($t, 3);
						$lines[] = "df = ".REDCapManagement::pretty($df);
					}
					echo "<tr>";
					echo "<th>Probability (p) of H<sub>0</sub></th>";
					echo "<td class='centered' colspan='2'>" . implode("<br>", $lines) . "</td>";
					echo "</tr>";
					if ($p != Stats::$nan) {
						foreach ($ci as $ciPercent => $ciAry) {
							echo "<tr>";
							echo "<th>$ciPercent% Confidence Interval (CI)</th>";
							echo "<td class='centered' colspan='2'>CI = [" . REDCapManagement::pretty($ciAry[0], 2) . ", " . REDCapManagement::pretty($ciAry[1], 2) . "]</td>";
							echo "</tr>";
						}
					}
					if ($interpretation) {
						echo "<tr>";
						echo "<th>Interpretation</th>";
						echo "<td class='centered' colspan='2' style='max-width: 500px;'>$interpretation</td>";
						echo "</tr>";
					}
				}
			}
		}
		echo "</table>";
	} else {
		echo "<p class='centered'>One of the groups (control or treatment) is empty.</p>";
	}
}

function calculateAverages($values) {
	$stats = new Stats($values);
	$n = $stats->getN();
	$mu = $stats->mean();
	return ["n" => $n, "mu" => $mu, "values" => $values];
}

function calculateConversionRate($conversionStatuses) {
	$convertedStatus = [1, 2];
	$excludedStatus = [3];       // TODO Left institution
	$converted = [];
	$included = [];
	$values = [];
	foreach ($conversionStatuses as $status) {
		if (in_array($status, $convertedStatus)) {
			$converted[] = $status;
			$values[] = 100;
		}
		if (!in_array($status, $excludedStatus)) {
			$included[] = $status;
			$values[] = 0;
		}
	}
	$n = count($included);
	if ($n > 0) {
		$mu = REDCapManagement::pretty(100 * count($converted) / count($included), 1)."%";
	} else {
		$mu = Stats::$nan;
	}
	return ["n" => $n, "mu" => $mu, "values" => $values];
}

function resultsPresentForAllGroups($results) {
	foreach ($results as $group => $groupData) {
		$total = 0;
		foreach ($results[$group] as $measure => $result) {
			$total += $result['n'];
		}
		if ($total == 0) {
			return false;
		}
	}
	return true;
}
