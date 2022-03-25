<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Links;
use Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

$pid = $_GET['pid'];
if (!$pid) {
    $pid = CareerDev::getSetting("pid");
}
if (!$module) {
        $module = CareerDev::getModule();
}
$validWranglerTypes = ["Patents", "Publications", "Grants"];
$token = CareerDev::getSetting("token", $pid);
$server = CareerDev::getSetting("server", $pid);
$event_id = CareerDev::getSetting("event_id", $pid);
$tokenName = CareerDev::getSetting("tokenName", $pid);
$adminEmail = CareerDev::getSetting("admin_email", $pid);
$eventId = $event_id;

$oneFieldAry = Download::arraysOfFields($token, $server, ["identifier_last_name", "identifier_first_name"]);
$lastNames = $oneFieldAry["identifier_last_name"];
$firstNames = $oneFieldAry["identifier_first_name"];
$allMyRecords = array_keys($firstNames);
$fullNames = [];
foreach ($lastNames as $rec => $ln) {
	$fn = $firstNames[$rec];
	$fullNames[$rec] = $fn." ".$ln;
}
if (CareerDev::isWrangler()) {
    $allMyRecords = CareerDev::filterOutCopiedRecords($allMyRecords);
}

function makeHeadersOfTables($type) {
	global $pid;

	$w = 250;
	$style = "text-align: center; vertical-align: middle; border-radius: 4px; background-color: #65bcff; width: $w"."px; border-spacing: 4px; padding-top: 8px; padding-bottom: 8px;";
	$closeType = preg_replace("/</", "</", $type);
	return "<script>
		function goToUrl(url) {
			window.location.href = url;
		}
	</script>";
}

?>
<title>Flight Tracker for Scholars</title>
<script src='<?= CareerDev::link("/js/jquery.min.js") ?>'></script>
<script src='<?= CareerDev::link("/js/jquery-ui.min.js") ?>'></script>
<script src='<?= CareerDev::link("/js/jquery.sweet-modal.min.js") ?>'></script>
<script src='<?= CareerDev::link("/js/base.js")."&".CareerDev::getVersion() ?>'></script>
<script src='<?= CareerDev::link("/js/autocomplete.js")."&".CareerDev::getVersion() ?>'></script>
<link rel="icon" type="image/png" href="<?= CareerDev::link("/img/flight_tracker_icon.png") ?>">
<link rel="stylesheet" href="<?= CareerDev::link("/css/jquery-ui.css") ?>">
<link rel="stylesheet" href="<?= CareerDev::link("/css/jquery.sweet-modal.min.css") ?>">
<link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.8.2/css/all.css" integrity="sha384-oS3vJWv+0UjzBfQzYUhtDYW+Pj2yciDJxpsK1OYPAYjqT085Qq/1cq5FLXAZQ7Ay" crossorigin="anonymous">
<link rel="stylesheet" href="<?= CareerDev::link("/css/career_dev.css")."&".CareerDev::getVersion() ?>">
<link rel="stylesheet" href="<?= CareerDev::link("/css/typekit.css").CareerDev::getVersion() ?>>">
<?= CareerDev::makeBackgroundCSSLink() ?>

<script>
$(document).ready(function() {
	var offset = $('table.fixedHeaders').offset();
	if (offset) {
		$('tr.fixed').hide();
		$(window).scroll(function() {
    			if ($(window).scrollTop() > offset.top) {
				$('tr.fixed').show();
    			} else {
				$('tr.fixed').hide();
    			}
		});
	}

	$("body").css({ "position": "relative" });
});

function refreshForRecord(page) {
	var rec = $('#refreshRecord').val();
	var newStr = "";
<?php
	if (isset($_GET['new'])) {
		if (is_numeric($_GET['new'])) {
			echo "  newStr = '&new=".REDCapManagement::sanitize($_GET['new'])."';";
		} else {
			echo "  newStr = '&new';";
		}
	}
	if (isset($_GET['wranglerType']) && in_array($_GET['wranglerType'], $validWranglerTypes)) {
        echo "let wranglerType = '&wranglerType=".REDCapManagement::sanitize($_GET['wranglerType'])."';\n";
	} else {
        echo "let wranglerType = '';\n";
    }
?>
	if (rec != '') {
		window.location.href = page + '?pid=<?= urlencode(REDCapManagement::sanitize($_GET['pid'])) ?>&page=<?= urlencode(REDCapManagement::sanitize($_GET['page'])) ?>&prefix=<?= urlencode(REDCapManagement::sanitize($_GET['prefix'])) ?>&record='+rec+newStr+wranglerType;
	}
}

function search(page, div, name) {
	$(div).html("");
	var name = name.toLowerCase();
	if (name != '') {
		var lastNames = <?= json_encode($lastNames) ?>;
		var fullNames = <?= json_encode($fullNames) ?>;
		var records = <?= json_encode($allMyRecords) ?>;
		var foundRecs = {};
		var numFoundRecs = 0;
		var re = new RegExp("^"+name);
		var rec;
		if (!name.match(/\s/)) {
			// last name only
			for (var i = 0; i < records.length; i++) {
				rec = records[i];
				var ln = lastNames[rec].toLowerCase();
				if (re.test(ln)) {
					foundRecs[rec] = ln;
					numFoundRecs++;
				}
			}
		} else {    // first and last name
			for (var i = 0; i < records.length; i++) {
				rec = records[i];
				var fn = fullNames[rec].toLowerCase();
				if (re.test(fn)) {
					foundRecs[rec] = fn;
					numFoundRecs++;
				}
			}
		}
		if (numFoundRecs == 1) {
			$('#searchDiv').html("Name found.");
			let wranglerType = '<?= (isset($_GET['wranglerType']) && in_array($_GET['wranglerType'], $validWranglerTypes)) ? REDCapManagement::sanitize($_GET['wranglerType']) : "" ?>';
			for (rec in foundRecs) {
				window.location.href = '?pid=<?= urlencode(REDCapManagement::sanitize($_GET['pid'])) ?>&prefix=<?= urlencode(REDCapManagement::sanitize($_GET['prefix'])) ?>&page='+encodeURIComponent(page)+'&record='+rec+wranglerType;
			}
		} else  if (numFoundRecs > 1) {
			var list = "";
			for (rec in foundRecs) {
				if (list != "") {
					list += "; ";
				}
				list += fullNames[rec];
			}
			$(div).html("Multiple found.<br>"+list);
		} else if (numFoundRecs === 0) {
			$(div).html("No names found.");
		}
	}
}



</script>
<?php

if (!isset($_GET['headers']) || ($_GET['headers'] != "false")) {
	echo makeHeaders(CareerDev::getModule(), $token, $server, $pid, $tokenName);
}
