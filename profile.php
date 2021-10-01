<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\Patents;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\CitationCollection;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

$recordIds = Download::recordIds($token, $server);
if (isset($_GET['record']) && is_numeric($_GET['record'])) {
	$record = REDCapManagement::getSanitizedRecord($_GET['record'], $recordIds);
} else {
	if (count($recordIds) > 0) {
        	$record = $recordIds[0];
	} else {
	    echo "<p class='centered'>No records stored.</p>";
	    exit;
	}
}

$nextRecord = REDCapManagement::getNextRecord($record, $token, $server);
$redcapData = Download::records($token, $server, [$record]);
$metadata = Download::metadata($token, $server);
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
$numCitations = $pubs->getNumberOfCitationsByOthers("Original Included");
$dollarsSummaryTotal = $grants->getTotalDollars("prior");
$dollarsSummaryDirect = $grants->getDirectDollars("prior");
$grants->compileGrants("Financial");
$dollarsCompiledTotal = $grants->getTotalDollars("compiled");
$dollarsCompiledDirect = $grants->getDirectDollars("compiled");
$numPatents = $patents->getCount();

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
	<?= Links::makeProfileLink($pid, "View Profile for Next Record", $nextRecord, FALSE, "purple") ?>
	<?= Links::makeEmailMgmtLink($pid, "Survey Distribution", FALSE, "purple") ?>
	<?= Links::makeDataWranglingLink($pid, "Grant Wrangler", $record, FALSE, "green") ?>
	<?= Links::makePubWranglingLink($pid, "Publication Wrangler", $record, FALSE, "green") ?>

	<a class='yellow'><?= getSelectRecordForProfile() ?></a>
	<a class='yellow'><?= getSearchForProfile() ?></a>
</div>

<div id='content'>
<h1><?= $name ?></h1>
    <?php
        if ($imgBase64) {
            echo "<p class='centered'><img src='$imgBase64' class='thumbnail' alt='Picture for $name'></p>";
        }
    ?>
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
        <td class='value profileHeader'><?= printList($mentors).$numMentorArticlesHTML ?></td>
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
    if ($iCiteHIndex) { $bibliometricScores["iCite H Index, calculated<br>from ".Links::makeLink("https://icite.od.nih.gov/", "iCite (NIH)", TRUE)] = $iCiteHIndex; }
    echo makeStatsHTML($trainingStats);
    echo makeStatsHTML($bibliometricScores);

    ?>
</table><br><br>

<h2>Contents</h2>
<table style='margin-left: auto; margin-right: auto; max-width: 800px; border-radius: 10px; padding: 8px;' class='blue'>
	<tr>
		<td class='profileHeader'>
			<div class='labelCentered'><a href='#grant_wrangler'>Grant Wrangler</a></div>
			<div class='valueCentered'>The Grant Wrangler helps you make manual changes to the structure of grants that is computed. You can change which grants are included or excluded. You can also change some of the properties in each grant. This information will be fed back into the computed summaries next time that script is run in the background.</div>
		</td>
	</tr>
	<tr>
		<td class='profileHeader'>
			<div class='labelCentered'><a href='#pub_wrangler'>Publication Wrangler</a></div>
			<div class='valueCentered'>The Publication Wrangler helps you filter through each publication to see if names are mismatched. Since names can sometimes be mis-identified in publications, the step of authenticating the citation is required.</div>
		</td>
	</tr>
	<tr>
		<td class='profileHeader'>
			<div class='labelCentered'><a href='#data_sources'>Data Source Comparison</a></div>
			<div class='valueCentered'>This allows you to see all of your data about grants at one glance. The information to the left is preferred over the information to the write. The computer automatically picks the data which is most preferred. Items in green are being used while items in red disagree with the information in the preferred grant. This helps you see where the information comes from.</div>
		</td>
	</tr>
</table><br><br>

<?php

require_once(dirname(__FILE__)."/charts/timeline.php");
echo "<br><br>\n";
echo "<h2 class='nomargin'>Who is $name Publishing With?</h2>\n";
echo "<iframe class='centered' style='height: 725px;' id='coauthorship' src='".Application::link("socialNetwork/collaboration.php")."&record=$record&field=record_id&cohort=all&headers=false&mentors=on'></iframe><br><br>\n";

if (!CareerDev::isCopiedProject()) {
	echo "<iframe class='centered' style='height: 600px;' id='grant_wrangler' src='".Application::link("wrangler/index.php")."&record=$record&headers=false'></iframe><br><br>\n";
    echo "<iframe class='centered' style='height: 600px;' id='pub_wrangler' src='".Application::link("wrangler/include.php")."&wranglerType=Publications&record=$record&headers=false'></iframe><br><br>\n";
}
echo "<iframe class='centered' style='height: 600px;' id='data_sources' src='".Application::link("tablesAndLists/dataSourceCompare.php")."&record=$record&headers=false'></iframe><br><br>\n";

?>

</div>
<?php


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
