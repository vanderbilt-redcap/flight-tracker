<?php

use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Filter;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

# rename, delete, reorder

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../wrangler/css.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

echo \Vanderbilt\FlightTrackerExternalModule\getCohortHeaderHTML();

?>
<script>
function rename(selector, button = false) {
	if (button) {
		const showSelector = selector+"_div";
        const hideSelector = selector+"_title";
        const inputSelector = selector+"_input";
		$(showSelector).val($(hideSelector).html());
		$(showSelector).show();
		$(hideSelector).hide();
		$(inputSelector).focus();
	} else {
        const inputSelector = selector;
        const newVal = $(inputSelector).val();
		if (newVal) {
			if (!newVal.match(/[#'"]/)) {
                const showSelector = inputSelector.replace(/_input/, "_title");
                const hideSelector = inputSelector.replace(/_input/, "_div");
                const processingSelector = inputSelector.replace(/_input/, "_processing");
                const oldVal = $(showSelector).html();

				$(hideSelector).hide();
				$(showSelector).hide();  // initially, hide
				$(processingSelector).show();
				presentScreen('Processing...');
				$.post("<?= CareerDev::link("cohorts/renameCohort.php") ?>", { 'redcap_csrf_token': getCSRFToken(), oldValue: oldVal, newValue: newVal }, function(data) {
					clearScreen();
					if (data.match(/success/)) {
						$(showSelector).html(newVal);
						$(hideSelector).val("");
						$(showSelector).show();
						$(processingSelector).hide();
					} else {
						alert("Rename failed: "+data);
					}
				}); 
			} else {
				alert("The title cannot contain #, ', and \".");
			}
		} else {
			alert("You must specify a title.");
		}
	}
}

function deleteCohort(cohort, selector) {
    const delSelector = selector+"_delete";
    const divSelector = selector+"_div";
    const titleSelector = selector+"_title";
	$(delSelector).show();
	$(divSelector).hide();
	$(titleSelector).hide();

	presentScreen('Deleting...');
	$.post("<?= CareerDev::link("cohorts/deleteCohort.php") ?>", { 'redcap_csrf_token': getCSRFToken(), cohort: cohort }, function(data) {
		clearScreen();
		if (data.match(/success/)) {
			$(selector).remove();
			alert("Delete successful!");
		} else {
			$(delSelector).hide();
			$(titleSelector).show();
			alert("Delete failed: "+data);
		}
	});
}

function cancel(selector) {
    const divSelector = selector+"_div";
    const inputSelector = selector+"_input";
    const titleSelector = selector+"_title";
	$(divSelector).hide();
	$(inputSelector).val("");
	$(titleSelector).show();
}
</script>

<style>
.processing { color: red; text-align: center; font-style: italic; font-size: 16px; }
</style>
<div id='content'>
<h1>Manage Cohorts</h1>

<?php

$cohorts = new Cohorts($token, $server, CareerDev::getModule());
$cohortTitles = $cohorts->getCohortTitles();
$redcapData = array();
if (!empty($cohortTitles)) {
	$allFields = $cohorts->getAllFields();
	$redcapData = Download::getIndexedRedcapData($token, $server, $allFields);
}
if (count($cohortTitles) > 0) {
    echo "<input type='hidden' id='cohortAPIKey' value='' />";
    echo "<div id='cohortDialog' title='Create Cohort'><p>Are you sure that you want to create a project for Cohort <span id='cohortTitle' class='bolded'></span>? It will delete any data in the project with the API key.</p>
<p><button onclick='createCohortProject($(\"#cohortTitle\").html(), $(\"#cohortAPIKey\").val(), \"#cohortDialog\");'>Yes</button> <button onclick='$(\"#cohortDialog\").dialog(\"close\");'>Cancel</button></p></div>";
    echo "<p class='centered max-width'>A cohort project is a <strong>read-only</strong> project that gets completely overwritten every week with a fresh set of data from the source project. It should never be used to capture new data or configured further.</p>";
    echo "<table class='centered'>";
	echo "<tr class='paddedRow borderedRow whiteRow centeredRow'><td></td><th>Cohort Size</th><th>Delete</th><th>Rename</th>";
	echo "<th>Make Cohort Project</th>";
	echo "</tr>";
	$metadata = Download::metadata($token, $server);
	foreach ($cohortTitles as $title) {
		$filter = new Filter($token, $server, $metadata);
		$config = $cohorts->getCohort($title);
		$records = $filter->getRecords($config, $redcapData);
		$htmlTitle = \Vanderbilt\FlightTrackerExternalModule\makeHTMLId($title);
		echo "<tr id='$htmlTitle' class='ui-widget-content paddedRow whiteRow borderedRow centeredRow'>";
		$colWidth = 150;
		echo "<th style='width: ".$colWidth."px;'><div id='".$htmlTitle."_title'>$title</div><div style='display: none;' id='".$htmlTitle."_div'><input style='width: ".$colWidth."px;' id='".$htmlTitle."_input'><br><button class='green' onclick='rename(\"#".$htmlTitle."_input\");'>&check;</button>&nbsp;<button class='red' onclick='cancel(\"#".$htmlTitle."\");'>X</button></div><div id='".$htmlTitle."_processing' class='processing' style='display: none;'>Processing...</div><div class='processing' style='display: none;' id='".$htmlTitle."_delete'>Deleting...</div></th>";
		echo "<td>".count($records)." Scholars</td>";
		echo "<td><button class='red biggerButton' onclick='deleteCohort(\"$title\", \"#$htmlTitle\");' style='font-weight: bold;'>X</button></td>";
		echo "<td><button class='biggerButton' onclick='rename(\"#$htmlTitle\", this);'>Rename</button></td>";
        if ($cohortPid = $cohorts->getReadonlyPortalValue($title, "pid")) {
            echo "<td>Project Enabled (".Links::makeProjectHomeLink($cohortPid, "PID $cohortPid").")</td>";
        } else {
            $id = REDCapManagement::makeHTMLId($title);
            echo "<td><input type='text' id='api_$id' value='' onchange='if ($(this).val().length === 32) { $(\"#button_$id\").show(); } else { $(\"#button_$id\").hide(); }' placeholder='API Key with Import/Export Rights' style='width: 270px;'/><br/>
<button style='display: none;' id='button_$id' onclick='$(\"#cohortTitle\").html(\"$title\"); $(\"#cohortAPIKey\").val($(\"#api_$id\").val()); if ($(\"#cohortAPIKey\").val().length === 32) { $(\"#cohortDialog\").dialog(\"open\"); } else { $.sweetModal({content: \"Invalid API Key\", icon: $.sweetModal.ICON_ERROR}); } return false;'>Take Over Project</button></td>";
        }
		echo "</tr>";
	}
	echo "</table>";
	echo "<script>$(document).ready(function() { $(\"#cohortDialog\").dialog({ autoOpen: false }); });</script>";
} else {
	echo "<p class='centered'>No Cohorts Available</p>\n";
}
echo "</div>\n";
