<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$resourceField = "resources_resource";
$metadata = Download::metadata($token, $server);
$options = DataDictionaryManagement::getChoicesForField($pid, $resourceField);

if (isset($_POST['option'])) {
	$opt = $_POST['option'];
	$newOptions = $options;
	if ($_POST['action'] == "delete") {
		unset($newOptions[$opt]);
	} else if (($_POST['action'] == "add") && ($_POST['title'])) {
		$newOptions[$opt] = $_POST['title'];
	}

	$i = 0;
	foreach ($metadata as $row) {
		if ($row['field_name'] == $resourceField) {
			$metadata[$i]['select_choices_or_calculations'] = collapseChoices($newOptions);
		}
		$i++;
	} 
	$feedback = Upload::metadata($metadata, $token, $server);
	if ($feedback['error']) {
		echo "ERROR: ".$feedback['error'];
	} else if ($feedback['errors']) {
		echo "ERROR: ".implode("; ", $feedback['errors']).".";
	} else {
		if ($feedback['count']) {
			$cnt = $feedback['count'];
		} else if ($feedback['item_count']) {
			$cnt = $feedback['item_count'];
		} else if (is_numeric($feedback)) {
			$cnt = $feedback;
		} else {
			throw new \Exception("No count specified in ".json_encode($feedback));
		}
		echo "1 line affected.";
	}
} else {

	$redcapData = Download::fields($token, $server, ["record_id", $resourceField]);
	$names = Download::names($token, $server);

	$resourceInstances = [];
	foreach ($redcapData as $row) {
		if ($row[$resourceField]) {
			$resourceNum = $row[$resourceField];
			if (!isset($resourceInstances[$resourceNum])) {
				$resourceInstances[$resourceNum] = [];
			}
			$resourceInstances[$resourceNum][$row['redcap_repeat_instance']] = $row['record_id'];
		}
	}

	echo "<div id='overlay'></div>\n";
	echo "<h1>Resource Management</h1>\n";

	$max = 0;
	foreach ($options as $num => $option) {
		if ($num > $max) {
			$max = $num;
		}
	}
	$newOption = $max + 1;
?>
<script>
function deleteOption(opt) {
	presentScreen("Deleting...");
	$.post('<?= CareerDev::link("resources/manage.php") ?>', { 'redcap_csrf_token': getCSRFToken(), action: 'delete', option: opt }, function(str) {
		clearScreen();
		showMssg(str);
	});
}

var newOption = <?= $newOption ?>;
function addOption(name) {
	if (name) {
		presentScreen("Adding...");
		$.post('<?= CareerDev::link("resources/manage.php") ?>', { 'redcap_csrf_token': getCSRFToken(), action: 'add', option: newOption, title: name }, function(str) {
			clearScreen();
			showMssg(str);
			newOption++;
		});
	} else {
		showMssg("ERROR: You must specify a title!");
	}
}

function showMssg(str) {
	if (str) {
		var sel = "#note";
		if (str.match(/error/i)) {
			if ($(sel).hasClass("green")) {
				$(sel).removeClass("green");
			}
			$(sel).addClass("red");
		} else {
			if ($(sel).hasClass("red")) {
				$(sel).removeClass("red");
			}
			$(sel).addClass("green");
		}
		$(sel).html(str);
		$(sel).show();
		location.reload();
	}
}

</script>
<?php
    $notUsedMssg = "No instances used.";
	echo "<div id='note' class='centered' style='display: none;'></div>\n";
	echo "<h4>You must remove all instances of the resource in your data before deleting the resource!</h4>\n";

	echo "<table class='centered bordered'>";
	echo "<thead>";
	echo "<tr class='extraPaddedRow odd'><th>Resource</th><th>Number of Participants</th></tr>";
	echo "</thead><tbody>";
	$i = 0;
	foreach ($options as $num => $option) {
	    $buttonHTML = "<button onclick='deleteOption(\"$num\");'>Delete</button>";
	    $rowClass = ($i % 2 == 0) ? "even" : "odd";
        echo "<tr class='extraPaddedRow $rowClass'>";
        echo "<td class='centered'>$option</td>";
	    if (isset($resourceInstances[$num])) {
            $instances = $resourceInstances[$num];
            echo "<td>";
            if (count($instances) > 0) {
                echo "<div class='tooltip centered'>".count($instances)." participants<span class='widetooltiptext smaller'>";
                $namesForOption = array();
                foreach ($instances as $recordId) {
                    $name = $names[$recordId];
                    $nameWithLink = Links::makeProfileLink($pid, $name, $recordId);
                    array_push($namesForOption, $nameWithLink);
                }
                echo implode("<br>", $namesForOption);
                echo "</span></div>";
            } else {
                echo "$notUsedMssg<br/>".$buttonHTML;
            }
            echo "</td></tr>";
        } else {
	        echo "<td>$notUsedMssg<br/>$buttonHTML</td>";
        }
        echo "</tr>";
	    $i++;
	}
    $rowClass = ($i % 2 == 0) ? "even" : "odd";
	echo "<tr class='extraPaddedRow $rowClass'>";
	echo "<td class='centered'><input type='text' id='title' value='' /></td>";
	echo "<td class='centered'><button onclick='addOption($(\"#title\").val());'>Add</button></td>";
	echo "</tr>";
	echo "</tbody></table>";
}

function collapseChoices($choiceHash) {
	$choiceAry = array();
	foreach ($choiceHash as $key => $str) {
		array_push($choiceAry, "$key, $str");
	}
	return implode(" | ", $choiceAry);
}
