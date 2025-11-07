<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Conversion;
use Vanderbilt\CareerDevLibrary\KaplanMeierCurve;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use Vanderbilt\CareerDevLibrary\URLManagement;

require_once(dirname(__FILE__)."/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$showRealGraphs = KaplanMeierCurve::getParam("showRealGraphs");
$measType = KaplanMeierCurve::getParam("measType");
$startDateSource = KaplanMeierCurve::getParam("startDateSource");
$meas = KaplanMeierCurve::getParam("meas");
$showAllResources = KaplanMeierCurve::getParam("showAllResources");
$showAllResourcesText = KaplanMeierCurve::getParam("showAllResourcesText");

$serialTimes = [];
$names = [];
$statusAtSerialTime = [];    // event or censored
$resourcesUsedIdx = [];
$groups = [];
$curveData = [];
$graphsToDraw = KaplanMeierCurve::getParam("graphTypes");
$cohort = Sanitizer::sanitizeCohort($_GET['cohort']);
if ($showRealGraphs && !empty($graphsToDraw)) {
    if (!in_array($cohort, ["", "all"])) {
        $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
    } else {
        $records = Download::recordIdsByPid($pid);
    }
    $names = Download::namesByPid($pid);
    $resourceChoices = DataDictionaryManagement::getChoicesForField($pid, "resources_resource");

    foreach (array_values(KaplanMeierCurve::getGraphTypes()) as $graphType) {
        if (in_array($graphType, $graphsToDraw)) {
            list(
                $curveData[$graphType],
                $serialTimes[$graphType],
                $statusAtSerialTime[$graphType],
                $resourcesUsedIdx[$graphType],
                $groups[$graphType]
                ) = KaplanMeierCurve::makeData($pid, $graphType, $records, $resourceChoices);
        }
    }
}



$fullURL = Application::link("charts/kaplanMeierCurve.php");
list($url, $params) = URLManagement::splitURL($fullURL);
$cohorts = new Cohorts($token, $server, Application::getModule());
$grantClass = Application::getSetting("grant_class");
$defaultGrantClass = ["K_to_R"];
if ($grantClass == "T") {
    $defaultGrantClass = ["TF_to_K"];
}
$configureApplicationLink = Application::link("config.php");
$grantAppointmentLink = Application::link("appointScholars.php")."&input=grant_type";

echo "<h1>Kaplan-Meier Conversion Success Curves</h1>";
echo "<p class='centered max-width'>A <a href='https://www.ncbi.nlm.nih.gov/pmc/articles/PMC3932959/'>Kaplan-Meier survival plot</a> is used in epidemiology to track deaths over time due to a disease. It's a good way to track the effectiveness of a treatment. In Career Development, deaths are not tracked, but rather whether someone converts to the next step (e.g., from T/F to K or from K to R, an 'event'), is lost to follow-up ('censored'), or is still active ('censored'). This curve will hopefully allow you to gauge the effectiveness of scholarship-promoting efforts. The momentum plot, based off a hazard plot, is the derivative of the Kaplan-Meier success curve; a high momentum indicates a high rate of conversions at that time-point.</p>";
echo "<p class='centered max-width'><strong>Your current grant class is $grantClass</strong>. K-grant projects support K&rarr;R conversion; T-grant &amp; Other projects support T/F&rarr;K, K&rarr;R, and T/F&rarr;R conversions. This value can be changed in the <a href='$configureApplicationLink'>Configure Application</a> page. T-class grants ('Training Appointment' grant type) and non-federal K-class grants ('Internal K', 'K12/KL2' &amp; some 'K Equivalent' grant types) can be added via <a href='$grantAppointmentLink'>adding a record of a scholar's appointment</a>.</p>";
echo "<p class='centered max-width'>".Conversion::CONVERSION_EXPLANATION."</p>";
echo "<form action='$url' method='GET'>";
echo REDCapManagement::makeHiddenInputs($params);
$checkboxes = [];
foreach (KaplanMeierCurve::getGraphTypes() as $id => $graphType) {
    $selectedGraphs = !empty($graphsToDraw) ? $graphsToDraw : $defaultGrantClass;
    $checked = in_array($id, $selectedGraphs) ? "checked" : "";
    $checkboxes[] = "<input type='checkbox' name='graphTypes[]' id='graphType_$id' value='$id' $checked /><label for='graphType_$id'> $graphType</label>";
}
echo "<p class='centered skinnymargines'>".implode("&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;", $checkboxes)."</p>";
echo "<p class='centered skinnymargins'>".$cohorts->makeCohortSelect($cohort, "", TRUE)."</p>";

// $measurements = ["days", "months", "years"];
$measurements = ["months", "years"];
echo "<p class='centered skinnymargins'>Measurement Granularity: <select name='measurement'>";
foreach ($measurements as $measurement) {
    $sel = "";
    if ($measurement == $meas) {
        $sel = " selected";
    }
    echo "<option value='$measurement'$sel>$measurement</option>";
}
echo "</select></p>";

$measurementTypes = ["Publications" => "Publishing Publications", "Grants" => "Receiving Grant Awards", "Both" => "Both Grant and Publication Activity"];
echo "<p class='centered skinnymargins'>Test for Continued Activity: <select name='measType'>";
foreach ($measurementTypes as $measurementType => $measurementLabel) {
    $sel = "";
    if ($measurementType == $measType) {
        $sel = " selected";
    }
    echo "<option value='$measurementType'$sel>$measurementLabel</option>";
}
echo "</select></p>";
echo "<p class='centered skinnymargins'><input type='checkbox' name='showAllResources' id='showAllResources' $showAllResourcesText> <label for='showAllResources'>Show All Resources</label></p>";

$startDateOptions = [
    "end_last_training_grant" => "End of Last Training Grant",
    "first_any" => "Either First Grant or First Publication Activity",
    "first_grant" => "First Grant Activity",
    "first_publication" => "First Publication Activity",
    ];
echo "<p class='centered skinnymargins'>Start Date: <select name='startDateSource'>";
foreach ($startDateOptions as $val => $label) {
    $sel = "";
    if ($startDateSource == $val) {
        $sel = " selected";
    }
    echo "<option value='$val'$sel>$label</option>";
}
echo "</select></p>";

echo "<p class='centered skinnymargins'><button>Re-Configure</button></p>";
echo "</form>";

if ($showRealGraphs) {
    $curve = new KaplanMeierCurve($curveData, $serialTimes, $graphsToDraw, $cohort);
    foreach ($graphsToDraw as $graphType) {
        if ($curve->getTotalDataPoints($graphType) > 0) {
            foreach (KaplanMeierCurve::PLOTS as $curveType => $titles) {
                $id = KaplanMeierCurve::makeHTMLId($graphType, $curveType);
                echo "<h2>$graphType: {$titles['title']}</h2>";
                echo "<canvas class='kaplanMeier' id='$id' width='800' height='600' style='width: 800px !important; height: 600px !important;'></canvas>";
                echo "<div class='alignright'><button onclick='html2canvas(document.getElementById(\"$id\"), { onrendered: (canvas) => { downloadCanvas(canvas, \"kaplan-meier.png\"); } }); return false;' class='smallest'>Save</button></div>";
            }
        } else {
            echo "<h2>$graphType</h2>";
            echo "<p class='centered'>No data exist for the plot $graphType.</p>";
        }
    }
    echo KaplanMeierCurve::getJSHTML();
    echo $curve->getSuccessCurves();

    foreach ($graphsToDraw as $graphType) {
        if ($curve->getTotalDataPoints($graphType) > 0) {
            echo "<h2>Source Data for $graphType</h2>";
            echo "<table class='centered bordered max-width'>";
            echo "<thead><tr><th>Record</th><th>Serial Time</th><th>Status</th>";
            if ($showAllResources) {
                echo "<th>Resources Used</th>";
            }
            echo "</tr></thead>";
            echo "<tbody>";
            foreach ($serialTimes[$graphType] ?? [] as $recordId => $serialTime) {
                echo "<tr>";
                echo "<td>$recordId ({$names[$recordId]})</td>";
                echo "<td>" . REDCapManagement::pretty($serialTime, 2) . "</td>";
                if (!empty($statusAtSerialTime[$graphType]) && !empty($resourcesUsedIdx[$graphType])) {
                    $status = $statusAtSerialTime[$graphType][$recordId] ?? "";
                    echo "<td>$status</td>";
                    if ($showAllResources) {
                        $resources = [];
                        foreach ($resourcesUsedIdx[$graphType][$recordId] ?? [] as $resourceIdx) {
                            if (!empty($groups[$graphType])) {
                                $resources[] = $groups[$graphType][$resourceIdx] ?? KaplanMeierCurve::UNKNOWN_RESOURCE;
                            } else {
                                $resources[] = KaplanMeierCurve::UNKNOWN_RESOURCE;
                            }
                        }
                        echo "<td>" . implode(", ", $resources) . "</td>";
                    }
                } else {
                    echo "<td></td>";
                    if ($showAllResources) {
                        echo "<td></td>";
                    }
                }
                echo "</tr>";
            }
            echo "</tbody>";
            echo "</table>";
        }
    }
}

