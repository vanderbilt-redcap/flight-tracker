<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Stats;
use \Vanderbilt\CareerDevLibrary\BarChart;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

define('NUM_BARS', 10);

$processPubs = FALSE;
if (isset($_GET['pubs'])) {
    $processPubs = TRUE;
}

$skipAwards = ["000"];
$records = Download::records($token, $server);
$metadata = Download::metadata($token, $server);
$choices = REDCapManagement::getChoices($metadata);
$allData = ["Converted in Less Than 5 Years" => [], "Converted in More Than 5 Years" => [], "Not Converted; Post-K" => [], ];
foreach ($records as $recordId) {
    $redcapData = Download::fieldsForRecords($token, $server, ["record_id", "coeus2_agency_grant_number", "coeus2_award_status", "coeus2_submitted_to_agency"], [$recordId]);
    $summaryData = Download::fieldsForRecords($token, $server, ["record_id", "summary_ever_last_any_k_to_r01_equiv", "summary_first_r01_or_equiv", "summary_last_any_k"], [$recordId]);

    $firstRDate = "";
    $orderedGrants = ["Unfunded" => [], "Awarded" => []];
    foreach ($redcapData as $row) {
        if ($row['redcap_repeat_instrument'] == "coeus2") {
            $awardNo = $row['coeus2_agency_grant_number'];
            $status = $row['coeus2_award_status'];
            $ts = strtotime($row['coeus2_submitted_to_agency']);
            $instance = $row['redcap_repeat_instance'];
            if ($ts && !in_array($awardNo, $skipAwards) && in_array($status, array_keys($orderedGrants))) {
                $orderedGrants[$status][$instance] = $ts;
            } else {
                // echo "Skipping $instance, ".date("Y-m-d", $ts).", $status, $awardNo<br><br>";
            }
        }
    }

    foreach ($orderedGrants as $status => $instances) {
        asort($orderedGrants[$status]);
    }

    // echo REDCapManagement::json_encode_with_spaces($orderedGrants)."<br><br>";

    $summaryRow = REDCapManagement::getNormativeRow($summaryData);
    $conversionStatusIdx = $summaryRow['summary_ever_last_any_k_to_r01_equiv'];
    $firstR01Date = $summaryRow['summary_first_r01_or_equiv'];
    $lastAnyKDate = $summaryRow['summary_last_any_k'];

    if (in_array($conversionStatusIdx, [1, 2, 4])) {
        if (in_array($conversionStatusIdx, [4])) {
            $bin = "Not Converted; Post-K";
        } else if (in_array($conversionStatusIdx, [1])) {
            $bin = "Converted in Less Than 5 Years";
        } else if (in_array($conversionStatusIdx, [2])) {
            $bin = "Converted in More Than 5 Years";
        } else {
            throw new \Exception("Could not match last status of $conversionStatusIdx for record $recordId! This should never happen.");
        }

        if ($firstR01Date) {
            $endString = "Conversion";
            $convertTs = strtotime($firstR01Date);
            $convertYears = REDCapManagement::datediff($lastAnyKDate, $firstR01Date, "y", FALSE);
        } else {
            $endString = "Today";
            $convertTs = time();
            $convertYears = REDCapManagement::datediff($lastAnyKDate, date("Y-m-d"), "y", FALSE);
        }
        if ($lastAnyKDate) {
            $startTs = strtotime($lastAnyKDate);
        } else {
            $startTs = 0;
            throw new \Exception("Could not find last any K date for record $recordId! This should never happen.");
        }

        $numUnfundedSubmissions = getNumBeforeTs($orderedGrants["Unfunded"], $convertTs);
        $datum = [
            "Number of Grant Submissions Until $endString" => $numUnfundedSubmissions,
            "Years after K Until $endString" => $convertYears,
        ];

        $firstSubmissionDate = getFirstUnfundedSubmissionDate($orderedGrants["Unfunded"]);
        if ($firstSubmissionDate) {
            $yearsToFirstSubmission = REDCapManagement::datediff($lastAnyKDate, $firstSubmissionDate, "y");
            $datum["Years Between K Award and First Unfunded Grant Submission"] = $yearsToFirstSubmission;
        } else {
            // echo "Record $recordId has no first submission date.<br><br>";
        }
        if ($processPubs) {
            $citationRows = Download::fieldsForRecords($token, $server, Application::getCitationFields($metadata), [$recordId]);
            $pubs = new Publications($token, $server, $metadata);
            $pubs->setRows($citationRows);
            $citations = $pubs->getSortedCitationsInTimespan($startTs, $convertTs);
            $datum["Number of Publications Before $endString"] = count($citations);
        }

        $allData[$bin][$recordId] = $datum;
    }
}

$cols = [];
$totalCount = 0;
foreach ($allData as $bin => $recordData) {
    $totalCount += count($recordData);
}

$averages = [];
$counts = [];
$stddev = [];
$dataPoints = [];
if ($totalCount > 0) {
    foreach ($allData as $bin => $recordData) {
        if (count($recordData) > 0) {
            $transformedData = [];
            $averages[$bin] = [];
            $counts[$bin] = [];
            $stddev[$bin] = [];
            $dataPoints[$bin] = [];
            $cols = [];
            foreach ($recordData as $recordId => $datum) {
                $cols = array_keys($datum);
                break;
            }
            foreach ($cols as $col) {
                $transformedData[$col] = [];
                foreach ($recordData as $recordId => $datum) {
                    if (isset($datum[$col])) {
                        $transformedData[$col][$recordId] = $datum[$col];
                    }
                }
                $stats = new Stats(array_values($transformedData[$col]));

                $counts[$bin][$col] = $stats->getN();
                $stddev[$bin][$col] = $stats->standardDeviation();
                $averages[$bin][$col] = $stats->mean();
                $dataPoints[$bin][$col] = $stats->getValues();
            }
        }
    }
}
echo "<script>
function toggleCharts() {
    if ($('.chartWrapper').is(':visible')) {
        $('.chartWrapper').hide();
    } else {
        $('.chartWrapper').show();
    }
}
</script>";
echo "<h1>Grant Submissions and K&rarr;R Conversion</h1>";
$toggleCharts = "<a href='javascript:;' onclick='toggleCharts();'>Show/Hide Charts</a>";
if ($processPubs) {
    echo "<p class='centered'><a href='" . Application::link("submissions.php") . "'>Turn off Publication Analysis</a>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;$toggleCharts</p>";
} else {
    echo "<p class='centered'><a href='".Application::link("submissions.php")."&pubs'>Turn on Publication Analysis</a>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;$toggleCharts</p>";
}

$firstBarChart = TRUE;
echo "<table class='centered'><tr>";
foreach ($averages as $bin => $binData) {
    echo "<td>";
    if (count($binData) > 0) {
        $i = 0;
        foreach ($binData as $col => $average) {
            $n = $counts[$bin][$col];
            $sigma = $stddev[$bin][$col];
            $data = $dataPoints[$bin][$col];
            $allData = [];
            foreach ($averages as $bin2 => $binData2) {
                $i2 = 0;
                foreach (array_keys($binData2) as $col2) {
                    if ($i == $i2) {
                        $allData = array_merge($allData, $dataPoints[$bin2][$col2]);
                    }
                    $i2++;
                }
            }
            if (!empty($allData)) {
                $min = (int) floor(min($allData));
                $max = (int) ceil(max($allData));
            } else {
                $min = 0;
                $max = 0;
            }
            if ($n > 0) {
                echo "<h2 class='blue'>$bin</h2>";
                echo "<h3>$col</h3>";
                echo "<p class='centered nomargin bolded' style='font-size: 60px;'>".REDCapManagement::pretty($average, 2)."</p>";
                echo "<h4 class='nomargin'>(n=$n; &sigma;=".REDCapManagement::pretty($sigma, 2).")</h4>";

                list ($cols, $labels) = makeHistogramData($dataPoints[$bin], NUM_BARS, $min, $max, $col);
                $chart = new BarChart($cols, $labels, REDCapManagement::makeHTMLId($bin." ".$col));
                $chart->setXAxisLabel($col);
                $chart->setYAxisLabel("Number of Scholars");
                if ($firstBarChart) {
                    echo $chart->getImportHTML();
                    $firstBarChart = FALSE;
                }
                echo $chart->getHTML(500, 300);

                echo "<br><br>";
            }
            $i++;
        }
    } else {
        echo "<p class='centered'>No data for $bin</p>";
    }
    echo "</td>";
}
echo "</tr></table>";

function makeHistogramData($binData, $bars, $min, $max, $thiscol) {
    $barWidth = ($max - $min) / $bars;
    $cols = [];
    $labels = [];
    for ($i = 0; $i < $bars; $i++) {
        $barMin = $min + $i * $barWidth;
        $barMax = $min + ($i + 1) * $barWidth;
        foreach (array_keys($binData) as $thisCol) {
            $numItems = 0;
            foreach ($binData[$thisCol] as $item) {
                if (($item >= $barMin) && ($item < $barMax)) {
                    $numItems++;
                }
                if ($item == $max) {
                    $numItems++;
                }
            }
            if ($thisCol == $thiscol) {
                $cols[] = $numItems;
                $labels[] = REDCapManagement::pretty($barMin, 1);
            }
        }
    }
    $labels[] = REDCapManagement::pretty($max, 1);
    return [$cols, $labels];
}

function getNumBeforeTs($orderedGrants, $thresholdTs) {
    $num = 0;
    $log = [];
    $log[] = REDCapManagement::json_encode_with_spaces($orderedGrants);
    $thresholdDate = date("Y-m-d", $thresholdTs);
    foreach ($orderedGrants as $instance => $ts) {
        $tsDate = date("Y-m-d", $ts);
        if (is_numeric($instance) && $tsDate && $thresholdDate) {
            $log[] = "Viewing $instance with ".$tsDate." and comparing to ".$thresholdDate;
            if ($ts <= $thresholdTs) {
                $num++;
                $log[] = "Less than: incrementing num to $num";
            }
        }
    }
    $log[] = "Returning $num";
    // echo "<p>".implode("<br>", $log)."</p>";
    return $num;
}

function getFirstUnfundedSubmissionDate($orderedGrants) {
    $earliestTs = FALSE;
    foreach ($orderedGrants as $instance => $ts) {
        if (!$earliestTs || ($ts < $earliestTs)) {
            $earliestTs = $ts;
        }
    }
    if ($earliestTs) {
        return date("Y-m-d", $earliestTs);
    }
    return "";
}