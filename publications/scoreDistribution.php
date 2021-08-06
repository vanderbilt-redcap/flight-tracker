<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\BarChart;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$numBars = $_GET['numBars'] ?? 10;

$fields = [
    "record_id",
    "citation_rcr",
    "citation_altmetric_score",
];

$metadata = Download::metadata($token, $server);
$fieldLabels = REDCapManagement::getLabels($metadata);
$redcapData = Download::fields($token, $server, $fields);

$dist = [];
$skip = ["record_id", "redcap_repeat_instrument", "redcap_repeat_instance"];
foreach ($fields as $field) {
    if (!in_array($field, $skip)) {
        $dist[$field] = [];
        foreach ($redcapData as $row) {
            if ($row[$field]) {
                $dist[$field][] = $row[$field];
            }
        }
    }
}

echo "<h1>Publication Impact Measures</h1>";
$link = Application::link("this");
$baseLink = REDCapManagement::splitURL($link)[0];
echo "<form action='$baseLink' method='GET'>";
echo REDCapManagement::getParametersAsHiddenInputs($link);
echo "<p class='centered'><label for='numBars'>Number of Bars: </label><input type='number' id='numBars' name='numBars' value='$numBars' style='width: 60px;'><br>";
echo "<button>Re-Configure</button></p>";
echo "</form>";

$colorWheel = Application::getApplicationColors();
$i = 0;
foreach ($dist as $field => $values) {
    $label = $fieldLabels[$field];
    list($cols, $colLabels) = buildDistribution($values, $numBars);
    $colorIdx = $i % count($colorWheel);
    $color = $colorWheel[$colorIdx];

    echo "<h2>$label</h2>";
    $barChart = new BarChart($cols, $colLabels, $field);
    if ($i == 0) {
        $jsLocs = $barChart->getJSLocations();
        $cssLocs = $barChart->getCSSLocations();
        foreach ($jsLocs as $loc) {
            echo "<script src='$loc'></script>";
        }
        foreach ($cssLocs as $loc) {
            echo "<link rel='stylesheet' href='$loc'>";
        }
    }
    $barChart->setColor($color);
    $barChart->setXAxisLabel($label);
    $barChart->setYAxisLabel("Number of Articles");
    echo "<div class='centered max-width'>".$barChart->getHTML(800, 500)."</div>";
    $i++;
}

function buildDistribution($values, $numBars) {
    $low = floor(min($values));
    $high = ceil(max($values));
    $step = ($high - $low) / $numBars;
    if ($step > 3) {
        $step = ceil($step);
        $usePretty = FALSE;
    } else if (is_integer($step)) {
        $usePretty = FALSE;
    } else {
        $usePretty = TRUE;
    }

    $cols = [];
    $colLabels = [];
    $i = 0;
    $numDecimals = 2;
    for ($start = $low; $start < $high; $start += $step) {
        $end = $start + $step;

        $cols[$i] = 0;
        if ($high == $end) {
            $trailingFigure = "]";
        } else {
            $trailingFigure = ")";
        }
        if ($usePretty) {
            $colLabels[$i] = "[".REDCapManagement::pretty($start, $numDecimals).", ".REDCapManagement::pretty($end, $numDecimals).$trailingFigure;
        } else {
            $colLabels[$i] = "[$start, $end".$trailingFigure;
        }

        foreach ($values as $val) {
            if ((($val >= $start) && ($val < $end)) || (($val == $end) && ($trailingFigure == "]"))) {
                $cols[$i]++;
            }
        }
        $i++;
    }
    return [$cols, $colLabels];
}