<?php

use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;

if (!isset($_GET['showHeaders'])) {
    define("NOAUTH", TRUE);
}

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/classes/Publications.php");
require_once(dirname(__FILE__)."/classes/Citation.php");
require_once(dirname(__FILE__)."/classes/Download.php");
require_once(dirname(__FILE__)."/classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/Application.php");

$recordIds = Download::recordIds($token, $server);

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
    ?>
    <h3>Brag on Your Scholars' Publications!</h3>
    <h4>Include this Page as a Widget in Another Page</h4>
    <div class="max-width centered">
        <p class="centered">Copy the following HTML and place into another HTML webpage to display your scholars' publications. Your website must likely have cross-origin framing turned on (which is the default).</p>
        <div class="max-width centered"><code>&lt;iframe src="<?= $url ?>" title="Recent Publications" style="width: 400px; height: 400px;"&gt;&lt;/iframe&gt;</code></div>

        <h4>What Time Period Should Show?</h4>
        <form method="GET" action="<?= REDCapManagement::getPage(Application::link("brag.php")) ?>">
            <?= REDCapManagement::getParametersAsHiddenInputs(Application::link("brag.php")) ?>
            <?= isset($_GET['showHeaders']) ? "<input type='hidden' name='showHeaders' value='' />" : "" ?>
            <p class="centered">Days Prior: <input type="number" name="daysPrior" style="width: 75px;" value="<?= $daysPrior ?>"> <button>Reset</button></p>
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
foreach ($recordIds as $recordId) {
    $redcapData = Download::fieldsForRecords($token, $server, Application::$citationFields, array($recordId));
    $pubs = new Publications($token, $server, $pid);
    $pubs->setRows($redcapData);
    $recordCitations = $pubs->getSortedCitationsInTimespan($startTs, $endTs, "Included", FALSE);
    $allCitations = array_merge($recordCitations, $allCitations);
}

$citationsWithTs = [];
foreach ($allCitations as $citation) {
    $citationsWithTs[$citation->getCitation()] = $citation->getTimestamp();
}

if ($asc) {
    asort($citationsWithTs);
} else {
    arsort($citationsWithTs);
}

echo "<h1>".getTimespanHeader($daysPrior)."</h1>\n";
echo "<div style='padding: 8px;'>\n";
if (empty($citationsWithTs)) {
    echo "<p class='centered'>None</p>";
} else {
    foreach ($citationsWithTs as $citationStr => $ts) {
        echo "<p>".$citationStr."</p>\n";
    }
}
echo "</div>\n";
echo "<br><br><br>";
echo "<p class='smallest centered'>Citations from ".Application::getProgramName()." <img src='".Application::link("img/flight_tracker_icon_cropped.png")."' style='height: 52px; width: 33px;'></p>";


function getTimespanHeader($daysPrior) {
    return "All Citations in Previous $daysPrior Days";
}
