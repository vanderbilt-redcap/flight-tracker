<?php

use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Altmetric;
use \Vanderbilt\CareerDevLibrary\Cohorts;

if (!isset($_GET['showHeaders'])) {
    define("NOAUTH", TRUE);
}

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

$metadata = Download::metadata($token, $server);
if ($_GET['cohort'] && ($_GET['cohort'] != 'all')) {
    $recordIds = Download::cohortRecordIds($token, $server, Application::getModule(), $_GET['cohort']);
} else {
    $recordIds = Download::recordIds($token, $server);
}

if (isset($_GET['daysPrior']) && is_numeric($_GET['daysPrior']) && ($_GET['daysPrior'] >= 0)) {
    $daysPrior = $_GET['daysPrior'];
} else {
    $daysPrior = 180;
}

$startTs = time() - $daysPrior * 24 * 3600;
$endTs = FALSE;  // use only $startTs

if (isset($_GET['showHeaders'])) {
    require_once(dirname(__FILE__)."/charts/baseWeb.php");
    $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
    $url = preg_replace("/\&showHeaders=*/", "", $url);
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
            echo "<p class='centered'>".$cohorts->makeCohortSelect($_GET['cohort'])."</p>";
            ?>
            <?= isset($_GET['showHeaders']) ? "<input type='hidden' name='showHeaders' value='' />" : "" ?>
            <p class="centered">What Time Period Should Show? Days Prior: <input type="number" name="daysPrior" style="width: 75px;" value="<?= $daysPrior ?>"></p>
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
foreach ($recordIds as $recordId) {
    $redcapData = Download::fieldsForRecords($token, $server, Application::getCitationFields($metadata), array($recordId));
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

echo "<h4>".getTimespanHeader($daysPrior)."</h4>\n";
echo "<div style='padding: 8px;'>\n";
if (empty($citationsWithTs)) {
    echo "<p class='centered'>None</p>";
} else {
    foreach ($citationsWithTs as $citationStr => $ts) {
        echo "<p class='smaller'>".$citationStr."</p>\n";
    }
}
echo "</div>\n";
echo "<br><br><br>";
echo "<p class='smallest centered'>Citations from ".Application::getProgramName()." <img src='".Application::link("img/flight_tracker_icon_cropped.png")."' style='height: 52px; width: 33px;'></p>";


function getTimespanHeader($daysPrior) {
    return "All Citations in Previous $daysPrior Days";
}
