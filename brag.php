<?php

use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Altmetric;
use \Vanderbilt\CareerDevLibrary\Cohorts;

if (!isset($_GET['showHeaders'])) {
    define("NOAUTH", TRUE);
}

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

# https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: X-PINGOTHER, Content-Type, Access-Control-Allow-Headers, X-Requested-With");

$metadata = Download::metadata($token, $server);
if ($_GET['cohort']) {
    $cohort = REDCapManagement::sanitize($_GET['cohort']);
} else {
    $cohort = "";
}
if ($cohort && ($cohort != 'all')) {
    $recordIds = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else {
    $recordIds = Download::recordIds($token, $server);
}

if (isset($_GET['daysPrior']) && is_numeric($_GET['daysPrior']) && ($_GET['daysPrior'] >= 0)) {
    $daysPrior = (int) REDCapManagement::sanitize($_GET['daysPrior']);
} else {
    $daysPrior = 180;
}

if (isset($_GET['daysAfterTraining']) && is_numeric($_GET['daysAfterTraining']) && ($_GET['daysAfterTraining'] >= 0)) {
    $daysAfterTraining = (int) REDCapManagement::sanitize($_GET['daysAfterTraining']);
} else {
    $daysAfterTraining = "";
}

$oneDay = 24 * 3600;
$startTs = time() - $daysPrior * $oneDay;
$endTs = FALSE;  // use only $startTs

if (isset($_GET['showHeaders'])) {
    require_once(dirname(__FILE__)."/charts/baseWeb.php");
    $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
    $url = preg_replace("/\&showHeaders[^\&]*/", "", $url);
    $url = preg_replace("/showHeaders[^\&]*\&/", "", $url);
    $url .= "&NOAUTH";
    ?>
    <h3>Brag on Your Scholars' Publications!</h3>
    <?= Altmetric::makeClickText(); ?>
    <h4>Include this Page as a Widget in Another Page</h4>
    <div class="max-width centered">
        <p class="centered">Copy the following HTML and place into another HTML webpage to display your scholars' publications. Your website must likely have cross-origin framing turned on (which is the default).</p>
        <div class="max-width centered"><code>&lt;iframe src="<?= $url ?>" title="Recent Publications" style="width: 100%; height: 400px;"&gt;&lt;/iframe&gt;</code></div>

        <h4>Further Configurations</h4>
        <form method="GET" action="<?= REDCapManagement::getPage(Application::link("brag.php")) ?>">
            <?= REDCapManagement::getParametersAsHiddenInputs(Application::link("brag.php")) ?>
            <?php
            $cohorts = new Cohorts($token, $server, Application::getModule());
            echo "<p class='centered'>".$cohorts->makeCohortSelect($cohort)."</p>";
            ?>
            <?= isset($_GET['showHeaders']) ? "<input type='hidden' name='showHeaders' value='' />" : "" ?>
            <p class="centered">What Time Period Should Show? Days Prior: <input type="number" name="daysPrior" style="width: 75px;" value="<?= $daysPrior ?>"></p>
            <p class="centered"><strong>-OR-</strong> Track Only Training And Days After Training: <input type="number" name="daysAfterTraining" style="width: 75px;" value="<?= $daysAfterTraining ?>"></p>
            <p class="centered"><button>Reset</button></p>
        </form>
    </div>
    <hr>
    <?php
} else {
    echo "<link rel='stylesheet' href='".Application::link("/css/career_dev.css")."'>\n";
}


if (isset($_GET['asc'])) {
    $asc = TRUE;
} else {
    $asc = FALSE;
}
$allCitations = [];
$allPMIDs = [];
$multipleScholarPMIDs = [];
$firstNames = Download::firstnames($token, $server);
$lastNames = Download::lastnames($token, $server);
$trainingFields = [
    "record_id",
    "identifier_start_of_training",
    "identifier_left_date",
];
for ($i = 1; $i <= 5; $i++) {
    $trainingFields[] = "summary_award_type_".$i;
    $trainingFields[] = "summary_award_date_".$i;
    $trainingFields[] = "summary_award_end_date_".$i;
}
$trainingFields = REDCapManagement::filterOutInvalidFields($metadata, $trainingFields);
$citationFields = Application::getCitationFields($metadata);
if (isset($_GET['test'])) {
    echo "Records: ".json_encode($recordIds)."<br>";
}
foreach ($recordIds as $recordId) {
    if ($daysAfterTraining !== "") {
        $trainingData = Download::fieldsForRecords($token, $server, $trainingFields, [$recordId]);
        $trainingStartDate = getTrainingStartDate($trainingData, $recordId);
        $trainingEndDate = getTrainingEndDate($trainingData, $recordId);
        if (isset($_GET['test'])) {
            echo "Record $recordId: start $trainingStartDate and end $trainingEndDate<br>";
        }
        if ($trainingStartDate) {
            $startTs = strtotime($trainingStartDate);
        } else {
            continue;
        }
        if ($trainingEndDate) {
            $endTs = strtotime($trainingEndDate) + $daysAfterTraining * $oneDay;
        } else {
            $endTs = FALSE;
        }
    } else {
        if (isset($_GET['test'])) {
            echo "No days after training for Record $recordId<br>";
        }
    }
    if (isset($_GET['test'])) {
        echo "Downloading for Record $recordId<br>";
    }

    $redcapData = Download::fieldsForRecords($token, $server, $citationFields, [$recordId]);
    $pubs = new Publications($token, $server, $pid);
    $pubs->setRows($redcapData);
    $recordCitations = $pubs->getSortedCitationsInTimespan($startTs, $endTs, "Included", FALSE);
    foreach ($recordCitations as $citation) {
        $pmid = $citation->getPMID();
        if (isset($allPMIDs[$pmid])) {
            if (!isset($multipleScholarPMIDs[$pmid])) {
                $multipleScholarPMIDs[$pmid] = [];
                $multipleScholarPMIDs[$pmid][] = $allPMIDs[$pmid];
            }
            $multipleScholarPMIDs[$pmid][] = ["lastName" => $lastNames[$recordId], "firstName" => $firstNames[$recordId]];
        } else {
            $allCitations[] = $citation;
            $allPMIDs[$pmid] = ["lastName" => $lastNames[$recordId], "firstName" => $firstNames[$recordId]];
        }
    }
}

$citationsWithTs = [];
foreach ($allCitations as $citation) {
    if (isset($_GET['altmetrics'])) {
        $citationStr = $citation->getImage("left");
    } else {
        $citationStr = "";
    }
    $pmid = $citation->getPMID();
    if (isset($multipleScholarPMIDs[$pmid])) {
        // Application::log("Calling getCitation $pmid with multiple: ".REDCapManagement::json_encode_with_spaces($multipleScholarPMIDs[$pmid]));
        $citationStr .= $citation->getCitation($multipleScholarPMIDs[$pmid]);
    } else {
        $citationStr .= $citation->getCitationWithLink(FALSE, TRUE);
    }
    $citationsWithTs[$citationStr] = $citation->getTimestamp();
}

if ($asc) {
    asort($citationsWithTs);
} else {
    arsort($citationsWithTs);
}

$classInfo = "";
if (!defined("NOAUTH")) {
    $classInfo = "class='max-width'";
}

if ($daysAfterTraining !== "") {
    echo "<h4>All Publications During Training and $daysAfterTraining Days After</h4>\n";
} else {
    echo "<h4>".getTimespanHeader($daysPrior)."</h4>\n";
}
echo "<div $classInfo style='padding: 8px;'>\n";
if (empty($citationsWithTs)) {
    echo "<p class='centered'>None</p>";
} else {
    foreach ($citationsWithTs as $citationStr => $ts) {
        echo "<p class='smaller' style='padding: 2px 0;'>".$citationStr."</p>\n";
    }
}
echo "</div>\n";
echo "<br><br><br>";
echo "<p class='smallest centered'>Publications from ".Application::getProgramName()." <img src='".Application::link("img/flight_tracker_icon_cropped.png")."' style='height: 52px; width: 33px;'></p>";


function getTimespanHeader($daysPrior) {
    return "All Publications in Previous $daysPrior Days";
}

function getTrainingStartDate($redcapData, $recordId) {
    $earliestDate = "";
    $startAtInstitution = REDCapManagement::findField($redcapData, $recordId, "identifier_start_of_training");
    if ($startAtInstitution) {
        $earliestDate = $startAtInstitution;
    }
    $kTypes = [1, 2, 3, 4, 9];
    for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
        $awardType = REDCapManagement::findField($redcapData, $recordId, "summary_award_type_".$i);
        $awardStartDate = REDCapManagement::findField($redcapData, $recordId, "summary_award_date_".$i);
        if (
                $awardType
                && $awardStartDate
                && in_array($awardType, $kTypes)
                && (
                    !$earliestDate
                    || REDCapManagement::dateCompare($awardStartDate, "<", $earliestDate)
                )
        ) {
                $earliestDate = $awardStartDate;
        }
    }
    return $earliestDate;
}

function getTrainingEndDate($redcapData, $recordId) {
    $latestDate = "";
    $kTypes = [1, 2, 3, 4, 9];
    for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
        $awardType = REDCapManagement::findField($redcapData, $recordId, "summary_award_type_".$i);
        $awardEndDate = REDCapManagement::findField($redcapData, $recordId, "summary_award_end_date_".$i);
        if (
            $awardType
            && $awardEndDate
            && in_array($awardType, $kTypes)
            && (
                !$latestDate
                || REDCapManagement::dateCompare($awardEndDate, ">", $latestDate)
            )
        ) {
            $latestDate = $awardEndDate;
        }
    }
    if (!$latestDate) {
        # only best guess if no other data exist
        $latestDate = REDCapManagement::findField($redcapData, $recordId, "identifier_left_date");
    }
    return $latestDate;
}
