<?php

use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Patents;
use \Vanderbilt\CareerDevLibrary\Download;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$module = Application::getModule();
if ($_GET['cohort']) {
    $records = Download::cohortRecordIds($token, $server, $module, $_GET['cohort']);
} else {
    $records = Download::recordIds($token, $server);
}
$firstNames = Download::firstnames($token, $server);
$lastNames = Download::lastnames($token, $server);
$institutions = Download::institutionsAsArray($token, $server);
$metadata = Download::metadata($token, $server);

$numPatents = 0;
$html = "";
foreach ($records as $recordId) {
    $patentFields = Application::getPatentFields($metadata);
    $redcapData = Download::fieldsForRecords($token, $server, $patentFields, [$recordId]);
    $patents = new Patents($recordId, $pid, $firstNames[$recordId], $lastNames[$recordId], $institutions[$recordId]);
    $patents->setRows($redcapData);
    $html .= $patents->getHTML();
    $numPatents += $patents->getCount();
}
if ($numPatents == 0) {
    $html = "<p class='centered'>No patents have been wrangled and included.</p>";
}
$cohorts = new Cohorts($token, $server, $module);
$defaultCohort = $_GET['cohort'] ? $_GET['cohort'] : "all";
$link = Application::link("patents/view.php");
$cohortSelect = $cohorts->makeCohortSelect($defaultCohort, "window.location = \"$link&cohort=\"+encodeURIComponent($(this).val());");

?>

<h1>Confirmed Patents (<?= $numPatents ?>)</h1>
<p class="centered"><?= $cohortSelect ?></p>
<div class="max-width centered">
    <?= $html ?>
</div>
