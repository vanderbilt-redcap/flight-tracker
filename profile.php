<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Patents;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\FeatureSwitches;
use \Vanderbilt\CareerDevLibrary\Portal;
use \Vanderbilt\CareerDevLibrary\HonorsAwardsActivities;

if (!empty($_POST)) {
    require_once(__DIR__."/small_base.php");
    require_once(__DIR__."/classes/Autoload.php");
    $switches = new FeatureSwitches($token, $server, $pid);
    $data = $switches->savePost($_POST);
    echo json_encode($data);
    exit;
}

require_once(__DIR__."/small_base.php");
require_once(__DIR__."/classes/Autoload.php");

$recordIds = Download::recordIds($token, $server);
if (!isset($_GET['record']) && (count($recordIds) > 0)) {
    $record = $recordIds[0];
    $thisUrl = Application::link("profile.php", $pid);
    header("Location: $thisUrl&record=".urlencode($record));
}

require_once(dirname(__FILE__)."/charts/baseWeb.php");
if (isset($_GET['record']) && is_numeric($_GET['record'])) {
	$record = REDCapManagement::getSanitizedRecord($_GET['record'], $recordIds);
} else {
	if (count($recordIds) > 0) {
        $record = $recordIds[0];
        $_GET['record'] = $record;
	} else {
	    echo "<p class='centered'>No records stored.</p>";
	    exit;
	}
}

$nextRecord = REDCapManagement::getNextRecord($record, $token, $server);
$redcapData = Download::records($token, $server, [$record]);
$metadata = Download::metadata($token, $server);
$metadataFields = DataDictionaryManagement::getFieldsFromMetadata($metadata);
$institutions = Download::institutionsAsArray($token, $server);

$grants = new Grants($token, $server, $metadata);
$grants->setRows($redcapData);
$scholar = new Scholar($token, $server, $metadata);
$scholar->setRows($redcapData);
$scholar->setGrants($grants);
$firstName = $scholar->getName("first");
$lastName = $scholar->getName("last");

$pubs = new Publications($token, $server, $metadata);
$pubs->setRows($redcapData);
$patents = new Patents($record, $pid, $firstName, $lastName, $institutions[$record]);
$patents->setRows($redcapData);

$switches = new FeatureSwitches($token, $server, $pid);

$trainingStats = [];
$trainingStartDate = REDCapManagement::findField($redcapData, $record, "summary_training_start");
if ($trainingStartDate) {
    $trainingStartTs = strtotime($trainingStartDate);
    $trainingEndDate = REDCapManagement::findField($redcapData, $record, "summary_training_end");
    if (!$trainingEndDate) {
        $trainingEndTs = time();
    } else {
        $trainingEndTs = strtotime($trainingEndDate);
    }
    $citations = $pubs->getSortedCitationsInTimespan($trainingStartTs, $trainingEndTs);
    $trainingStats["Number of Publications During Training"] = count($citations);
    $trainingStats["Number of First-Author Publications During Training"] = Publications::getNumberFirstAuthor($citations, $pubs->getName());
    $trainingStats["Number of Last-Author Publications During Training"] = Publications::getNumberLastAuthor($citations, $pubs->getName());
}

$iCiteHIndex = REDCapManagement::findField($redcapData, $record, "summary_icite_h_index");
$wosHIndex = REDCapManagement::findField($redcapData, $record, "summary_wos_h_index");
$scopusHIndex = REDCapManagement::findField($redcapData, $record, "summary_scopus_h_index");
$altmetricRange = $pubs->getAltmetricRange("Original Included");
$avgRCR = $pubs->getAverageRCR("Original Included");
$HI = REDCapManagement::findField($redcapData, $record, "summary_hi");
$HINorm = REDCapManagement::findField($redcapData, $record, "summary_hi_norm");
$HIAnnual = REDCapManagement::findField($redcapData, $record, "summary_hi_annual");
$gIndex = REDCapManagement::findField($redcapData, $record, "summary_g_index");

$normativeRow = array();
foreach ($redcapData as $row) {
	if ($row['redcap_repeat_instrument'] == "") {
		$normativeRow = $row;
	}
}

$imgBase64 = $scholar->getImageBase64();
$name = $scholar->getName("full");
$email = $scholar->getEmail();
if ($email) {
	$email = "<a href='mailto:$email'>$email</a>";
} else {
	$email = "None specified";
}
$status = $scholar->getEmploymentStatus();
if (preg_match("/^\d\d\d\d-\d+-\d+$/", $status)) {
	$status = "Left ".INSTITUTION." on ".REDCapManagement::YMD2MDY($status);
}
$institution = $scholar->getInstitutionText();
$division = $scholar->getCurrentDivisionText();
$degrees = $scholar->getDegreesText();
$dept = $scholar->getPrimaryDepartmentText();
$mentors = $scholar->getAllMentors();
$resources = $scholar->getResourcesUsed();
$converted = $scholar->isConverted();
$numGrants = $grants->getNumberOfGrants("prior");
$numPublications = $pubs->getNumber("Original Included");
$numFirstAuthors = $pubs->getNumberFirstAuthors();
$numLastAuthors = $pubs->getNumberLastAuthors();
$numMentorArticles = $pubs->getNumberWithPeople($mentors);
$mentorArticles = $pubs->getIndividualCollaborations($mentors);
$numCitations = $pubs->getNumberOfCitationsByOthers("Original Included");
$dollarsSummaryTotal = $grants->getTotalDollars("prior");
$dollarsSummaryDirect = $grants->getDirectDollars("prior");
$grants->compileGrants("Financial");
$dollarsCompiledTotal = $grants->getTotalDollars("compiled");
$dollarsCompiledDirect = $grants->getDirectDollars("compiled");
$numPatents = $patents->getCount();

$mentorsWithArticles = [];
foreach ($mentors as $mentor) {
    if ($mentorArticles[$mentor] > 0) {
        $mentorsWithArticles[] = $mentor." (".$mentorArticles[$mentor]." pubs)";
    } else {
        $mentorsWithArticles[] = $mentor;
    }
}

$choices = DataDictionaryManagement::getChoices($metadata);
$metadataLabels = DataDictionaryManagement::getLabels($metadata);
$optionalRows = [];
$optionalSettings = REDCapManagement::getOptionalSettings();
foreach (REDCapManagement::getOptionalFields() as $field) {
    $numSettings = REDCapManagement::getOptionalFieldsNumber($field);
    for ($i = 1; $i <= $numSettings; $i++) {
        $field = REDCapManagement::getOptionalFieldSetting($field, $i);
        if (in_array($field, $metadataFields)) {
            $setting = REDCapManagement::turnOptionalFieldIntoSetting($field);
            $label = $optionalSettings[$setting] ?? $metadataLabels[$field] ?? $field;
            $value = REDCapManagement::findField($redcapData, $record, $field);
            if (isset($choices[$field]) && isset($choices[$field][$value])) {
                $value = $choices[$field][$value];
            }
            if ($label && $value) {
                $optionalRows[] = [
                    "label" => $label,
                    "value" => $value,
                ];
            }
        }
    }
}

$optionalRowsHTML = "";
for ($i = 0; $i < count($optionalRows); $i += 2) {
    $optionalRowsHTML .= "<tr>";
    $optionalRowsHTML .= "<td class='label profileHeader'>{$optionalRows[$i]['label']}:</td>";
    $optionalRowsHTML .= "<td class='value profileHeader'>{$optionalRows[$i]['value']}</td>";
    if ($i + 1 < count($optionalRows)) {
        $optionalRowsHTML .= "<td class='label profileHeader'>{$optionalRows[$i+1]['label']}:</td>";
        $optionalRowsHTML .= "<td class='value profileHeader'>{$optionalRows[$i+1]['value']}</td>";
    }
    $optionalRowsHTML .= "</tr>";
}

?>
<style>
h1 { text-align: center; margin: 0px; }
.centered { text-align: center; margin: 0px; }
.label,.labelCentered { font-weight: bold; }
.labelCentered,.valueCentered { text-align: center; }
.label { text-align: right; }
.value,.valueCentered,.label,.labelCentered { font-size: 16px; }
.value { text-align: left; }
.allcaps { text-transform: uppercase; }
iframe.centered { width: 1000px; margin-left: auto; margin-right: auto; display: block; border-radius: 20px; }
.profileHeader { vertical-align: middle; padding: 4px 8px 4px 8px; }
td.profileHeader div a { color: black; }
td.profileHeader div a:hover { color: #0000FF; }
.header { padding: 8px; }
</style>

<script>
function resizeIframe(obj) {
	obj.style.height = obj.contentWindow.document.body.scrollHeight + 'px';

	// remove onload event or it will refresh forever
	obj.onload = function() { };

	obj.src = obj.src;
}

function refreshProfile(page) {
	var rec = $('#refreshRecord').val();
	if (rec) {
		window.location.href = page+"?pid=<?= $pid ?>&record="+rec;
	}
}

$(document).ready(function() {
    $('#search').keydown(function(e) {
		if ((e.keyCode == 13) || (e.keyCode == 9)) {
			var page = '<?= REDCapManagement::sanitize($_GET['page']) ?>';
			var name = $(this).val();
			search(page, '#searchDiv', name);
		}
	});
});
</script>

<div class='subnav'>
	<?= Links::makeProfileLink($pid, "View Profile for Next Record", $nextRecord, FALSE, "green") ?>
	<?= Links::makeDataWranglingLink($pid, "Grant Wrangler", $record, FALSE, "green") ?>
	<?= Links::makePubWranglingLink($pid, "Publication Wrangler", $record, FALSE, "green") ?>
	<a class='blue'><?= getSelectRecordForProfile() ?></a>
	<a class='blue'><?= getSearchForProfile() ?></a>
</div>

<div id='content'>
<h1><?= $name ?></h1>
    <?php
        $lines = [];
        if (Portal::isLive()) {
            $scholarPortalUrl = Application::getScholarPortalLink()."&match=$pid:$record";
            $lines[] = Links::makeLink($scholarPortalUrl, "Spoof This Scholar in the Scholar Portal for This Project Only")."<br/>Spoofing or mimicking a scholar allows you to see what they might see or to troubleshoot issues as they arise.";
        }
        if ($imgBase64) {
            $lines[] = "<img src='$imgBase64' class='thumbnail' alt='Picture for $name' />";
        }
        if (!empty($lines)) {
            echo "<p class='centered max-width'>".implode("<br/>", $lines)."</p>";
        }
    ?>
    <div style='margin: 0 auto; max-width: 600px; padding: 4px 0;' class='blueBorder translucentBG'>
        <?= $switches->makeHTML("record", $record) ?>
    </div>

    <table style='margin-left: auto; margin-right: auto; border-radius: 10px; padding: 8px;' class='blue'>
	<tr>
		<td class='label profileHeader'>First Name:</td>
		<td class='value profileHeader'><?= $firstName ?></td>
		<td class='label profileHeader'>Primary Department:</td>
		<td class='value profileHeader'><?= $dept.($division ? "<br>".$division : "") ?></td>
	</tr>
	<tr>
		<td class='label profileHeader'>Last Name:</td>
		<td class='value profileHeader'><?= $lastName ?></td>
		<td class='label profileHeader'>Degrees:</td>
		<td class='value profileHeader'><?= $degrees ?></td>
	</tr>
	<tr>
		<td class='label profileHeader'>Email:</td>
		<td class='value profileHeader'><?= $email ?></td>
<?php
if (CareerDev::getInstitutionCount() == 1) {
	echo "<td class='label profileHeader'>Status:</td>\n";
	echo "<td class='value profileHeader'>$status</td>\n";
} else {
	echo "<td class='label profileHeader'>Institution(s):</td>\n";
	echo "<td class='value profileHeader'>$institution</td>\n";
}
?>
	</tr>
	<tr>
		<td class='label profileHeader'>REDCap:</td>
		<td class='value profileHeader'><?= Links::makeSummaryLink($pid, $record, $event_id, "Record ".$record) ?></td>
		<td class='label profileHeader'>Converted?:</td>
		<td class='value profileHeader allcaps'><?= $converted ?></td>
	</tr>
        <?= $optionalRowsHTML ?>
	<tr>
		<td class='label profileHeader'>Number of First-Author Articles:</td>
		<td class='value profileHeader'><?= REDCapManagement::pretty($numFirstAuthors) ?></td>
		<td class='label profileHeader'>Number of Last-Author Articles:</td>
		<td class='value profileHeader'><?= REDCapManagement::pretty($numLastAuthors) ?></td>
	</tr>
	<tr>
		<td class='label profileHeader'>Confirmed Original<br>Research Articles:</td>
		<td class='value profileHeader'><?= REDCapManagement::pretty($numPublications) ?></td>
	</tr>
	<tr>
		<td class='label profileHeader'>Grants:</td>
		<td class='value profileHeader'><?= REDCapManagement::pretty($numGrants) ?></td>
		<td class='label profileHeader'>Citations by Others:</td>
		<td class='value profileHeader'><?= REDCapManagement::pretty($numCitations) ?></td>
	</tr>
	<tr>
<?php
if ($dollarsCompiledTotal) {
	echo "<td class='label profileHeader'>Total Dollars<br>All Grants<br>(Internal and External;<br>recorded in COEUS):</td>\n";
	echo "<td class='value profileHeader'>".REDCapManagement::prettyMoney($dollarsCompiledTotal)."</td>\n";
}

$numMentorArticlesHTML = "";
if (!empty($mentors)) {
    $numMentorArticlesHTML = "<br>[Collaborating on ".REDCapManagement::pretty($numMentorArticles)." articles]";
}
?>
		<td class='label profileHeader'>Total Dollars<br>from Grants<br>(External Sources Only):</td>
		<td class='value profileHeader'><?= REDCapManagement::prettyMoney($dollarsSummaryTotal) ?></td>
	</tr>
    <tr>
        <td class='label profileHeader'>Mentors:</td>
        <td class='value profileHeader'><?= printList($mentorsWithArticles).$numMentorArticlesHTML ?></td>
        <td class='label profileHeader'>Resources Used:</td>
        <td class='value profileHeader'><?= printList($resources) ?></td>
    </tr>
    <tr>
        <td class='label profileHeader'>Number of Confirmed Patents:</td>
        <td class='value profileHeader'><?= $numPatents ?></td>
    </tr>
    <?php

    $bibliometricScores = [];
    if ($wosHIndex) { $bibliometricScores[Links::makeLink("https://support.clarivate.com/ScientificandAcademicResearch/s/article/Web-of-Science-h-index-information?language=en_US", "H Index", TRUE)." calculated<br>from ".Links::makeLink("https://www.webofknowledge.com/", "Web of Science", TRUE)] = $wosHIndex; }
    if ($scopusHIndex) { $bibliometricScores[Links::makeLink("https://blog.scopus.com/topics/h-index", "H Index", TRUE)."<br>from".Links::makeLink("https://www.scopus.com/", "Scopus", TRUE)] = $scopusHIndex; };
    if ($altmetricRange) { $bibliometricScores["Range of ".Links::makeLink("https://www.altmetric.com/", "Altmetric", TRUE)." Scores"] = $altmetricRange; }
    if ($avgRCR) { $bibliometricScores["Average ".Links::makeLink("https://dpcpsi.nih.gov/sites/default/files/iCite%20fact%20sheet_0.pdf", "Relative Citation<br> Ratio", TRUE)." from ".Links::makeLink("https://icite.od.nih.gov/", "iCite", TRUE)." Scores"] = $avgRCR; }
    if ($iCiteHIndex) { $bibliometricScores["H-Index, calculated from iCite figures<br/>from ".Links::makeLink("https://icite.od.nih.gov/", "iCite (NIH)", TRUE)] = $iCiteHIndex; }
    if ($HI) { $bibliometricScores["HI, calculated from iCite figures<br/>(hIndex / [average number of authors in contributing pubs])"] = $HI; }
    if ($HINorm) { $bibliometricScores["HI,norm, calculated from iCite figures<br/>(normalizes each H-Index input to [number of citations] / [number of co-authors])"] = $HINorm; }
    if ($HIAnnual) { $bibliometricScores["HI,annual, calculated from iCite figures<br/>(HI,norm / [number of years of publications])"] = $HIAnnual; }
    if ($gIndex) { $bibliometricScores["G-Index, calculated from iCite figures<br/>(the largest integer such that the most-cited g articles received together at least g^2 citations)"] = $gIndex; }
    echo makeStatsHTML($trainingStats);
    echo makeStatsHTML($bibliometricScores);

    echo "</table><br/><br/>";

    echo "<h2>Timelines</h2>";
    require_once(__DIR__."/charts/timeline.php");
    echo "<br/><br/>";

    echo "<h2>Publication Research Topic Timelines</h2>";
    echo "<div class='centered max-width-1000' style='height: 500px; overflow-y: scroll; overflow-x: hidden; background-color: white;'>";
    $_GET['hideHeaders'] = TRUE;
    require_once(__DIR__."/charts/publicationSubjects.php");
    echo "</div>";

    echo "<h2>Reported Grant Funding (Total Dollars; PI/Co-PI only)</h2>";
    require_once(__DIR__."/charts/scholarGrantFunding.php");
    echo "<br/><br/>";

    echo "<h2 class='nomargin'>Who is $name Publishing With?</h2>";
    echo "<iframe class='centered' style='height: 725px;' id='coauthorship' src='".Application::link("socialNetwork/collaboration.php")."&record=$record&field=record_id&cohort=all&headers=false&mentors=on'></iframe>";
    echo "<br/><br/>";

    $honors = new HonorsAwardsActivities($redcapData, $pid, $record);
    echo "<h2>Recorded Honors, Awards &amp; Activities</h2>";
    echo $honors->getHTML();
    echo "<br/><br/>";

    echo "</div>";


function getSearchForProfile() {
	return Publications::getSearch();
}

function getSelectRecordForProfile() {
	return Publications::getSelectRecord();
}

function printList($list) {
	if (empty($list)) {
		return "(None specified.)";
	}
	return implode("<br>", $list);
}

function makeStatsHTML($stats) {
    $i = 0;
    $html = "";
    foreach ($stats as $label => $value) {
        if ($i % 2 == 0) {
            $html .= "<tr>\n";
        }
        $html .= "<td class='label profileHeader'>$label:</td>\n";
        if (is_numeric($value)) {
            $value = REDCapManagement::pretty($value);
        }
        $html .= "<td class='value profileHeader'>$value</td>\n";
        if ($i % 2 == 1) {
            $html .= "</tr>\n";
        }
        $i++;
    }
    if (count($stats) % 2 == 1) {
        $html .= "</tr>\n";
    }
    return $html;
}
