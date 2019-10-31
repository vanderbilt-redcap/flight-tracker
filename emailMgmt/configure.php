<?php

use \Vanderbilt\CareerDevLibrary\EmailManager;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/EmailManager.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../CareerDev.php");

$realPost = getRealInput('POST');
if (count($_POST) > 0) {
	# saveSetting evokes a separate $mgr instance; must do first so that save will take effect
        $mgr = new EmailManager($token, $server, $pid, CareerDev::getModule());
	list($message, $settingName, $emailSetting) = translatePostToEmailSetting($realPost);
	if (($emailSetting == EmailManager::getBlankSetting())) {
		$html = "Some settings were left unfilled. Please check your configuration again. ".$message;
		$noteClass = "red";
	} else {
        	$feedback = $mgr->saveSetting($settingName, $emailSetting);
		if ($feedback['error']) {
			$html = "Error! ".$feedback['error'];
			$noteClass = "red";
		} else if ($feedback['errors']) {
			$html = "Errors! ".implode("; ", $feedback['errors']);
			$noteClass = "red";
		} else {
			$html = "Save successful. You may make modifications or scroll down to the bottom in order to test.";
			$noteClass = "green";
		}
	}
	echo "<div id='note' class='$noteClass centered padded'>$html</div>\n";
}

# defaults
$indivs = ""; $filteredGroup = "checked";
$all = "checked"; $some = "";
$surveyCompleteNo = "checked"; $surveyCompleteYes = "";
$r01No = ""; $r01Yes = ""; $r01Agnostic = "checked";
if ($_POST['recipient'] == "individuals") {
	$indivs = "checked"; $filteredGroup = "";
} else if ($_POST['recipient'] == "filtered_group") {
	if ($_POST['filter'] == "some") {
		$all = ""; $some = "checked";
		if ($_POST['survey_complete'] == "yes") {
			$surveyCompleteNo = ""; $surveyCompleteYes = "checked";
		}
		if ($_POST['r01_or_equiv'] == "yes") {
			$r01No = ""; $r01Yes = "checked"; $r01Agnostic = "";
		} else if ($_POST['r01_or_equiv'] == "no") {
			$r01No = "checked"; $r01Yes = ""; $r01Agnostic = "";
		}
	}
}

$metadata = Download::metadata($token, $server);
$mgr = new EmailManager($token, $server, $pid, CareerDev::getModule(), $metadata);

$selectName = \Vanderbilt\FlightTrackerExternalModule\getFieldForCurrentEmailSetting();
$messageSelectName = "message_select";
$spacing = "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
$messages = $mgr->getMessageHash();

$currSettingName = "";
if ($_POST['name']) {
	$currSettingName = $_POST['name'];
} else if ($_GET[$selectName]) {
	$currSettingName = $_GET[$selectName];
}
$currSetting = $mgr->getItem($currSettingName);
if ($currSetting["who"]["individuals"]) {
	$indivs = "checked"; $filteredGroup = "";
	$listOfEmailsToCheck = preg_split("/,/", $currSetting["who"]["individuals"]);
	foreach ($listOfEmailsToCheck as $email) {
		$realPost[$email] = "1";
	}
}

?>
<link href="<?= CareerDev::link("/css/quill.snow.css") ?>") rel="stylesheet">
<script src="<?= CareerDev::link("/js/quill.js") ?>"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
var messages = <?= json_encode($messages) ?>;

$(document).ready(function() {
	$('#<?= $selectName ?>').change(function() {
		var val = $('#<?= $selectName ?> option:selected').val();
		if (val) {
			window.location = '?pid=<?= $pid ?>&<?= $selectName ?>='+val;
		}
	});
	$('#<?= $messageSelectName ?>').change(function() {
		var val = $('#<?= $messageSelectName ?> option:selected').val();
		if (val && messages[val]) {
			$('#message .ql-editor').html(messages[val]);
		}
	});
	$('input').on('change', function() { updateAll(this, '<?= $pid ?>', <?= json_encode($realPost) ?>); });
	$("form").on("submit",function(){
		$("[name=message]").val($("#message .ql-editor").html());
	});
	updateNames('<?= $pid ?>', <?= json_encode($realPost) ?>);
	$(".datetime").flatpickr({ enableTime: true, dateFormat: '<?= EmailManager::getFormat() ?>' });
	$(".datetime").on('change', function() { var name = $(this).attr('name'); $('input[type=hidden][name='+name+']').val($(this).val()+'M'); });
});
</script>

<style>
.ql-picker-label { z-index: -1; }
</style>
<script src='<?= CareerDev::link("/js/emailMgmtNew.js") ?>'></script>
<div id='overlay'></div>

<h1>Send an Email</h1>

<form action='<?= CareerDev::link("configure.php") ?>' method='POST'>
	<h2 class='orange'>Specify Email Name</h2>
	<table class='centered' style='margin-bottom: 16px;'><tr>
		<td class='centered'>Email Name:<br><input type='text' name='name' class='long' value='<?= $currSettingName ?>'></td>
		<td class='centered'>--OR--</td>
		<td class='centered'>Load Existing Email:<br><?= $mgr->getSelectForExistingNames($selectName, $currSettingName) ?></td>
	</tr></table>

	<table style='width: 100%;'>
	<tr><td class='oneThird'>
		<h2 class='green'>Who?</h2>
			<h3 class='green'>From Email Address</h3>
			<p class='centered'><input type='text' name='from' class='long' value='<?= $_POST['from'] ? $_POST['from'] : $currSetting['who']['from'] ?>'></p>

			<h3 class='green'>To (Recipients)</h3>
			<p class='centered'>Who Do You Want to Receive Your Email?<br>
				<input class='who_to' type='radio' name='recipient' id='individuals' value='individuals' <?= $indivs ?>><label for='individuals'> Individual(s)</label><?= $spacing ?><input type='radio' class='who_to' name='recipient' id='filtered_group' value='filtered_group' <?= $filteredGroup ?>><label for='filtered_group'> Filtered Group</label>
			</p>
			<div id='checklist' <?php if ($indivs != "checked") { echo "style='display: none;'"; } ?>>
				<h4 style='margin-bottom: 0;'>List of Names<span class='namesCount'></span></h4>
				<p class='namesFiltered'></p>
			</div>
			<div id='filter' <?php if ($filteredGroup != "checked") { echo "style='display: none;'"; } ?>>
				<p class='centered' id='filter_scope'>Do You Want to Email All or Some Scholars?<br>
					<input class='who_to' type='radio' name='filter' id='all' value='all' <?= $all ?>><label for='all'> All</label><?= $spacing ?><input type='radio' class='who_to' name='filter' id='some' value='some' <?= $some ?>><label for='some'> Some</label>
				</p>
				<div id='filterItems' style='display: none;'>
					<p class='centered'>Filter: Have Any Surveys Been Completed?<br>
						<input class='who_to' type='radio' name='survey_complete' id='survey_no' value='no' <?= $surveyCompleteNo ?>><label for='survey_no'> No</label><?= $spacing ?><input class='who_to' type='radio' name='survey_complete' id='survey_yes' value='yes' <?= $surveyCompleteYes ?>><label for='survey_yes'> Yes (see next question)</label>
					</p>
					<p class='centered' id='whenCompleted' style='display: none;'>Filter: The Scholar Hasn't Filled Out a Survey in Last:<br>
						<input class='who_to' type='text' style='width: 50px;' name='last_complete_months' value='<?= $_POST['last_complete_months'] ? $_POST['last_complete_months'] : 12 ?>'> Months
					</p>

					<p class='centered'>Filter: What Are the Maximum Number of Emails (Including Follow-Ups) a Scholar Can Receive?<br>
						<input class='who_to' type='radio' name='max' id='max_unlimited' value='unlimited' <?= $_POST['max_emails'] ? "" : "checked" ?>><label for='max_unlimited'> Unlimited<?= $spacing ?><input class='who_to' type='radio' name='max' id='max_number' value='limited' <?= $_POST['max_emails'] ? "checked" : "" ?>><label for='max_number'> Limited to Number</label>
						<p class='centered' id='numEmails' style='display: none;'>
							<input class='who_to' type='text' style='width: 50px;' id='max_emails' name='max_emails' value='<?= $_POST['max_emails'] ? $_POST['max_emails'] : "5" ?>'> Emails
						</p>
					</p>

					<p class='centered'>Filter: Has the Scholar Received an R01-or-Equivalent Grant?<br>
						<input class='who_to' type='radio' name='r01_or_equiv' id='r01_no' value='no' <?= $r01No ?>><label for='r01_no'> No</label><?= $spacing ?><input class='who_to' type='radio' name='r01_or_equiv' id='r01_yes' value='yes' <?= $r01Yes ?>><label for='r01_yes'> Yes</label><?= $spacing ?><input class='who_to' type='radio' name='r01_or_equiv' id='r01_agnostic' value='agnostic' <?= $r01Agnostic ?>><label for='r01_agnostic'> Doesn't Matter</label>
					</p>
				</div>

				<h4 style='margin-bottom: 0;'>List of Filtered Names<span class='namesCount'></span></h4>
				<p class='namesFiltered'></p>
			</div>

	</td>
	<td class='oneThird'>
		<h2 class='yellow'>What?</h2>

<?php
		$surveySelectId = "survey";
		echo "<h3 class='yellow'>Format Message</h3>\n";
		echo "<p class='centered'>Subject: <input type='text' class='long' name='subject' value='".($_POST['subject'] ? $_POST['subject'] : $currSetting['what']['subject'])."'></p>\n";
		echo "<div style='text-align: center; margin: 16px 0px;'>\n";
		echo "<div style='display: inline-block;'>".$mgr->getSurveySelect($surveySelectId)."<br>\n";
		echo "<button onclick='insertSurveyLink(\"$surveySelectId\"); return false;'>Insert Survey Link</button></div>\n";
		echo "<div style='display: inline-block;'><button onclick='insertName(); return false;'>Insert Name</button></div>\n";
		echo "</div>\n";
		if (!empty($messages)) {
			echo "<div style='text-align: center; margin: 16px 0px;'>Load Prior Message:<br>".$mgr->getSelectForExistingNames($messageSelectName)."</div>\n";
		}
		if ($_POST['message']) {
			$mssg = $_POST['message'];
		} else if ($currSetting['what']['message']) {
			$mssg = $currSetting['what']['message'];
		} else {
			$mssg = "";
		}
		echo "<div id='message' style='background-color: white; z-index: 1;'>$mssg</div>\n";
		echo "<input type='hidden' name='message' value=''>\n";

if ($currSettingName) {
	echo "<div class='yellow padded' style='margin-top: 100px;' id='test'>\n";
	echo "<h2 class='yellow'>Test Your Email</h2>\n";
	echo "<p class='centered'><span class='tooltip'>Your Email Address<span class='tooltiptext'>You will receive one email per every recipient.</span></span>: <input type='text' id='test_to'></p>\n";
	echo "<p class='centered'><button onclick='sendTestEmails(\"$pid\", \"$selectName\", \"$currSettingName\");'>Test Saved Email</button></p>\n";
	echo "</div>\n";
}

?>
	</td>
	<td class='oneThird'>

		<h2 class='purple'>When?</h2>
			<h3 class='purple'>Initial Email</h3>
			<?= makeDateTime("initial_time", $currSetting['when']) ?>

			<h3 class='purple'>Follow-Up Email (Optional; Only to Non-Respondents)</h3>
			<?= makeDateTime("followup_time", $currSetting['when']) ?>

<?php
		// save/update
		if ($currSetting == EmailManager::getBlankSetting()) {
			$saveText = "Add Email Setting";
		} else {
			$saveText = "Update Email Setting";
		}
		echo "<div style='margin-top: 100px;' class='blue padded'>\n";
		echo "<h2 class='blue'>$saveText</h2>\n";
		echo "<p class='centered'><button>$saveText</button></p>\n";
		echo "</div>\n";
?>
	</td>
	</tr>
	</table>
</form>

<script>
var quill = new Quill('#message', {
        theme: 'snow'
});
</script>

<?php
function translatePostToEmailSetting($post) {
	$emailSetting = EmailManager::getBlankSetting();

	$settingName = "";
	if ($post['name']) {
		$settingName = $post['name'];
	} else {
		return array("A name for the setting was not specified", "", EmailManager::getBlankSetting());
	}

	# WHO
	if ($post['recipient']) {
		$checkedEmails = array();
		foreach ($post as $key => $value) {
			if ($value && isEmailAddress($key)) {
				array_push($checkedEmails, $key);
			}
		}
		if (!empty($checkedEmails)) {
			$emailSetting["who"]["individuals"] = implode(",", $checkedEmails);
		} else {
			return array("No individuals are checked ".json_encode($post), "", EmailManager::getBlankSetting());
		}
	} else {
		return array("No recipient is specified", "", EmailManager::getBlankSetting());
	}
	if ($post['recipient'] == "filter") {
		if ($post['filter']) {
			$emailSetting["who"]["filter"] = $post["filter"];
		} else {
			return array("The Filter for Some vs. All was not specified", "", EmailManager::getBlankSetting());
		}
		if ($post["survey_complete"]) {
			if ($post["last_complete_months"] && ($post["survey_complete"] == "yes")) {
				$emailSetting["who"]["last_complete"] = $post["last_complete_months"];
			} else if ($post["survey_complete"] == "no") {
				$emailSetting["who"]["none_complete"] = TRUE;
			} else {
				# only happens if the months are not specified; returns blank setting; better than throwing an exception
				return array("The Months were not specified", "", EmailManager::getBlankSetting());
			}
		}
		if ($post["max_emails"]) {
			$emailSetting["who"]["max_emails"] = $post["max_emails"];
		}
		if ($post["r01_or_equiv"]) {
			$emailSetting["who"]["converted"] = $post["r01_or_equiv"];
		}
	}
	if ($post["from"]) {
		$emailSetting["who"]["from"] = $post["from"];
	} else {
		return array("From address is not specified", "", EmailManager::getBlankSetting());
	}

	# WHAT
	if ($post["message"] && $post["subject"]) {
		$emailSetting["what"]["message"] = $post["message"];
		$emailSetting["what"]["subject"] = $post["subject"];
	} else {
		return array("The Message or Subject were not specified", "", EmailManager::getBlankSetting());
	}

	# WHEN
	if ($post["initial_time"]) {
		$emailSetting["when"]["initial_time"] = $post['initial_time'];
	} else {
		return array("The time for Initial Survey was not specified", "", EmailManager::getBlankSetting());
	}
	if ($post["followup_time"]) {
		$emailSetting["when"]["followup_time"] = $post['followup_time'];
	}

	return array("", $settingName, $emailSetting);
}

function makeDateTime($field, $when) {
	$displayValue = "";
	$hiddenValue = "";
	if ($_POST[$field]) {
		$hiddenValue = $_POST[$field];
	} else if ($when[$field]) {
		$hiddenValue = $when[$field];
	}
	$displayValue = preg_replace("/M$/", "", $hiddenValue);

	$html = "";
	$html .= "<input type='hidden' name='$field' value='$hiddenValue'>\n";
	$html .= "<p class='centered'><input type='text' class='datetime' name='$field' value='$displayValue'></p>";

	return $html;
}

function getRealInput($source) {
	$pairs = explode("&", $source == 'POST' ? file_get_contents("php://input") : $_SERVER['QUERY_STRING']);
	$vars = array();
	foreach ($pairs as $pair) {
		$nv = explode("=", $pair);
		$name = urldecode($nv[0]);
		$value = urldecode($nv[1]);
		$vars[$name] = $value;
	}
	return $vars;
}
