<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Publications;
use Vanderbilt\CareerDevLibrary\Citation;
use Vanderbilt\CareerDevLibrary\CitationCollection;
use Vanderbilt\CareerDevLibrary\Application;
use ExternalModules\ExternalModules;
use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

global $token, $server, $pid;

switch ($instrument) {
	case "initial_survey":
		$prefix = "check";
		break;
	case "followup":
		$prefix = "followup";
		break;
	default:
		$prefix = "";
		break;
}

$metadata = Download::metadataByPid($pid, Application::$citationFields);
$recordData = Download::fieldsForRecordsByPid($pid, Application::getCitationFields($metadata), [$record]);
$pubs = new Publications($token, $server, $metadata);
$pubs->setRows($recordData);
$finalized = $pubs->getCitationCollection("Final");
$notDone = $pubs->getCitationCollection("Not Done");
$omitted = $pubs->getCitationCollection("Omitted");

$baseURL = ExternalModules::$BASE_URL ?? APP_URL_EXTMOD_RELATIVE;
$headerStyle = "text-align: center; margin: 16px 0; padding: 4px;";
$html = "";
$html .= "<script>
let extmod_base_url = '$baseURL'
</script>\n";
$html .= "<h3 class='header toolbar'><font size='+1'>Publications</font></h3>\n";

$html .= "<h4 style='$headerStyle'><span id='notDoneCount'>".$notDone->getCount()."</span> Citations to Review (Check to Confirm as Your Paper)</h4>\n";
if ($notDone->getCount() > 0) {
	$html .= makeCheckboxes($notDone, "unchecked", "notDone");
} else {
	$html .= makeEmptyDiv("notDone")."\n";
}

$html .= "<h4 style='$headerStyle'><span id='omittedCount'>".$omitted->getCount()."</span> Citations to Omit";
if ($omitted->getCount() > 0) {
	$html .= " ".makeShow("omitted")."</h4>\n";
	$html .= makeCheckboxes($omitted, "omitted", "omitted", "display: none;");
} else {
	$html .= "</h4>\n";
	$html .= makeEmptyDiv("omitted")."\n";
}

$html .= "<h4 style='$headerStyle'><span id='finalizedCount'>".$finalized->getCount()."</span> Citations Already Accepted and Finalized";
if ($finalized->getCount() > 0) {
	$html .= " ".makeShow("finalized")."</h4>\n";
	$html .= makeCheckboxes($finalized, "checked", "finalized", "display: none;");
} else {
	$html .= "</h4>\n";
	$html .= makeEmptyDiv("finalized")."\n";
}
$certifyPubURL = Application::link("wrangler/certifyPub.php")."&NOAUTH";
$loadingImageUrl = Application::link("img/loading.gif");
$html .= "<div style='text-align: center;'><label for='pmid'>PMID</label>: <input type='number' id='pmid' value=''><br><button type='button' class='purple' onclick='addPMID($(\"#pmid\").val(), \"$certifyPubURL\"); return false;'>Add PMID</button></div>\n";
$html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');

echo "<script src='".Application::link("js/base.js")."&".CareerDev::getVersion()."'></script>\n";
echo "<script src='".Application::link("js/jquery.sweet-modal.min.js")."&".CareerDev::getVersion()."'></script>\n";
?>
<script>
const checkedImg = "<?= Application::getBase64("wrangler/checked.png") ?>";
const uncheckedImg = "<?= Application::getBase64("wrangler/unchecked.png") ?>";
const omittedImg = "<?= Application::getBase64("wrangler/omitted.png") ?>";
const surveyPrefix = "<?= $prefix ?>";

// in case of multiple surveys
function getLoadingImageUrlOverride() {
    return "<?= $loadingImageUrl ?>";
}

$(document).ready(function() {
    const html = <?= json_encode(mb_convert_encoding($html, 'UTF-8')) ?>;
    const finalizedPubs = <?= json_encode($finalized->getIds()) ?>;
    const omittedPubs = <?= json_encode($omitted->getIds()) ?>;
    const skippedPubs = <?= json_encode($notDone->getIds()) ?>;

	$('#<?= $prefix ?>_accepted_pubs-sh-tr').hide();
	$('#<?= $prefix ?>_accepted_pubs-tr').hide();
	$('[name=<?= $prefix ?>_accepted_pubs]').val(JSON.stringify(finalizedPubs));
	$('#<?= $prefix ?>_not_associated_pubs-tr').hide();
	$('[name=<?= $prefix ?>_not_associated_pubs]').val(JSON.stringify(omittedPubs));
	$('#<?= $prefix ?>_not_addressed_pubs-tr').hide();
	$('[name=<?= $prefix ?>_not_addressed_pubs]').val(JSON.stringify(skippedPubs));
	$('#<?= $prefix ?>_not_addressed_pubs-tr').after("<tr><td colspan='3' id='publications_wrangler' style='padding-bottom: 8px;'></td></tr>");
	$('#publications_wrangler').html(html);
	$('#publications_wrangler').show();
	if ($('#surveyinstructions').length > 0) {
		$('#surveyinstructions').prepend('<img align="right" src="<?= Application::getBase64("img/flight_tracker_logo_small.png") ?>"><br/>');
	} else {
		$('#surveytitlelogo').append('<img src="<?= Application::getBase64("img/flight_tracker_logo_small.png") ?>"><br/>');
	}
});
</script>
<?php

function makeCheckboxes($coll, $img, $divId, $style = "") {
	$styleFiller = "";
	if ($style) {
		$styleFiller = " style='$style'";
	}
	$html = "";
	$html .= "<div id='$divId'$styleFiller>\n";
	$certifyPubURL = Application::link("wrangler/certifyPub.php")."&NOAUTH";
	$imgURL = Application::getBase64("/wrangler/".$img.".png");
	foreach ($coll->getCitations() as $citationObj) {
		$pmid = $citationObj->getPMID();
		$citationLink = $citationObj->getCitationWithLink(false, true);
		$html .= "<div id='PMID$pmid' style='margin: 8px 0; min-height: 26px;'><img style='margin: 2px; width: 26px; height: 26px;' src='$imgURL' alt='$img' data-original='$divId' onclick='changeCheckboxValue(this, \"$certifyPubURL\");'> $citationLink</div>\n";
	}
	$html .= "</div>\n";
	return $html;
}

function makeShow($divId) {
	return "<span style='font-size: 12px;'>(<a href='javascript:;' onclick='$(\"#$divId\").slideDown(); $(this).parent().hide();'>Show</a>)</span>";
}

function makeEmptyDiv($divId) {
	return "<div id='$divId'></div>\n";
}
