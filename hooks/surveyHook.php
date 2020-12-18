<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\CitationCollection;
use \Vanderbilt\CareerDevLibrary\Application;
use \ExternalModules\ExternalModules;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/Citation.php");

global $token, $server, $pid;

switch($instrument) {
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

$metadata = Download::metadata($token, $server);
$recordData = Download::fieldsForRecords($token, $server, Application::getCitationFields($metadata), array($record));
$pubs = new Publications($token, $server, $metadata);
$pubs->setRows($recordData);
$finalized = $pubs->getCitationCollection("Final");
$notDone = $pubs->getCitationCollection("Not Done");
$omitted = $pubs->getCitationCollection("Omitted");

$headerStyle = "text-align: center; margin: 16px 0; padding: 4px;";
$html = "";
$html .= "<script src='".Application::link("js/base.js")."&".CareerDev::getVersion()."'></script>\n";
$html .= "<script>
let extmod_base_url = '".ExternalModules::$BASE_URL."'
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
	$html .= makeCheckboxes($finalized, "readonly", "finalized", "display: none;");
} else {
	$html .= "</h4>\n";
	$html .= makeEmptyDiv("finalized")."\n";
}
$html .= "<div style='text-align: center;'><label for='pmid'>PMID</label>: <input type='number' id='pmid' value=''><br><button type='button' class='purple' onclick='addPMID($(\"#pmid\").val()); return false;'>Add PMID</button></div>\n";
$html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
?>
<script>
var checkedImg = "<?= Application::link("wrangler/checked.png") ?>";
var uncheckedImg = "<?= Application::link("wrangler/unchecked.png") ?>";
var omittedImg = "<?= Application::link("wrangler/omitted.png") ?>";
var surveyPrefix = "<?= $prefix ?>";

$(document).ready(function() {
	var html = <?= json_encode($html) ?>;
	var finalizedPubs = <?= json_encode($finalized->getIds()) ?>;
	var omittedPubs = <?= json_encode($omitted->getIds()) ?>;
	var skippedPubs = <?= json_encode($notDone->getIds()) ?>;

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
		$('#surveyinstructions').prepend('<img align="right" src="<?= Application::link("img/flight_tracker_logo_small.png") ?>">');
	} else {
		$('#surveytitlelogo').append('<img src="<?= Application::link("img/flight_tracker_logo_small.png") ?>"><br>');
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
	foreach ($coll->getCitations() as $citationObj) {
		$html .= "<div id='PMID".$citationObj->getPMID()."' style='margin: 8px 0; min-height: 26px;'><img align='left' style='margin: 2px; width: 26px; height: 26px;' src='".Application::link("/wrangler/".$img.".png")."' alt='$img' onclick='changeCheckboxValue(this);'> ".$citationObj->getCitationWithLink(FALSE, TRUE)."</div>\n";
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
