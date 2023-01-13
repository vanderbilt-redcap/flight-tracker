<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\EmailManager;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$allowFollowups = FALSE;
$realPost = getRealInput('POST');
$metadata = Download::metadata($token, $server);  // must load on save and reload after save
$hasErrors = FALSE;
if (count($_POST) > 0) {
	# saveSetting evokes a separate $mgr instance; must do first so that save will take effect
    $mgr = new EmailManager($token, $server, $pid, CareerDev::getModule(), $metadata);
	list($message, $settingName, $emailSetting) = translatePostToEmailSetting($realPost);
	if (($emailSetting == EmailManager::getBlankSetting())) {
		$html = "Some settings were left unfilled. Please check your configuration again. ".$message;
		$noteClass = "red";
		$hasErrors = TRUE;
	} else {
	    $feedback = $mgr->saveSetting($settingName, $emailSetting);
		if (isset($feedback['error'])) {
			$html = "Error! ".$feedback['error'];
			$noteClass = "red";
			$hasErrors = TRUE;
		} else if (isset($feedback['errors'])) {
			$html = "Errors! ".implode("; ", $feedback['errors']);
			$noteClass = "red";
			$hasErrors = TRUE;
		} else {
			$html = "Save successful. You may make modifications or scroll down to the bottom in order to test.";
			$noteClass = "green";
		}
	}
    if (!isset($_POST['noalert'])) {
        echo "<div id='note' class='$noteClass centered padded'>$html</div>\n";
    }
	$metadata = Download::metadata($token, $server);  // must load on save and reload after save
}

$mgr = new EmailManager($token, $server, $pid, CareerDev::getModule(), $metadata);

$selectName = EmailManager::getFieldForCurrentEmailSetting();
$messageSelectName = "message_select";
$spacing = "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
$messages = $mgr->getMessageHash();

$currSettingName = "";
if (isset($_POST['name'])) {
	$currSettingName = Sanitizer::sanitize($_POST['name']);
} else if (isset($_GET[$selectName])) {
	$currSettingName = Sanitizer::sanitize($_GET[$selectName]);
}
$currSetting = $mgr->getItem($currSettingName);

$isReadonly = "";
$isDisabled= "";
if ($currSetting['enabled']) {
	$isDisabled = " disabled";
	$isReadonly = " readonly";
}

# defaults
$indivs = "$isDisabled"; $filteredGroup = "checked";
$all = "checked"; $some = "$isDisabled";
$surveyCompleteNo = "$isDisabled"; $surveyCompleteYes = "$isDisabled"; $surveyCompleteNoMatter = "checked";
$lastCompleteMonths = 12; $maxEmails = 5; $newRecordsSince = 6;
$maxSpecified = "$isDisabled"; $newRecordsSinceSpecified = "$isDisabled";
$r01No = "$isDisabled"; $r01Yes = "$isDisabled"; $r01Agnostic = "checked";
$traineeClassAlumni = "";
$traineeClassCurrent = "";
$traineeClassAll = "";

if (isset($_POST['recipient'])) {
    if ($_POST['recipient'] == "individuals") {
        $indivs = "checked"; $filteredGroup = "$isDisabled";
    } else if ($_POST['recipient'] == "filtered_group") {
        if ($_POST['filter'] == "some") {
            $all = "$isDisabled"; $some = "checked";
            if ($_POST['survey_complete'] == "yes") {
                $surveyCompleteNo = "$isDisabled"; $surveyCompleteYes = "checked"; $surveyCompleteNoMatter = "$isDisabled";
                if ($_POST['last_complete_months']) {
                    $lastCompleteMonths = REDCapManagement::sanitize($_POST['last_complete_months']);
                }
            } else if ($_POST['survey_complete'] == "no") {
                $surveyCompleteNo = "checked"; $surveyCompleteYes = "$isDisabled"; $surveyCompleteNoMatter = "$isDisabled";
            }
            if ($_POST['r01_or_equiv'] == "yes") {
                $r01No = "$isDisabled"; $r01Yes = "checked"; $r01Agnostic = "$isDisabled";
            } else if ($_POST['r01_or_equiv'] == "no") {
                $r01No = "checked"; $r01Yes = "$isDisabled"; $r01Agnostic = "$isDisabled";
            }
            if ($_POST['trainee_class'] == "alumni") {
                $traineeClassAlumni = "checked"; $traineeClassCurrent = "$isDisabled"; $traineeClassAll = "$isDisabled";
            } else if ($_POST['trainee_class'] == "current") {
                $traineeClassAlumni = "$isDisabled"; $traineeClassCurrent = "current"; $traineeClassAll = "$isDisabled";
            } else {
                $traineeClassAlumni = "$isDisabled"; $traineeClassCurrent = "$isDisabled"; $traineeClassAll = "current";
            }
            if ($_POST['max_emails']) {
                $maxEmails = REDCapManagement::sanitize($_POST['max_emails']);
                $maxSpecified = "checked";
            }
            if (($_POST['newRecords'] == "new") && $_POST['new_records_since']) {
                $newRecordsSince = REDCapManagement::sanitize($_POST['new_records_since']);
                $newRecordsSinceSpecified = "checked";
            }
        }
    }
}

if (isset($currSetting["who"]["individuals"])) {
	$indivs = "checked"; $filteredGroup = "";
	$listOfEmailsToCheck = preg_split("/,/", $currSetting["who"]["individuals"]);
	foreach ($listOfEmailsToCheck as $email) {
		$realPost[$email] = "1";
	}
} else if (isset($currSetting["who"]["filter"])) {
	switch($currSetting["who"]["filter"]) {
		case "some":
			$all = "$isDisabled";
			$some = "checked";
			break;
		case "all":
			$all = "checked";
			$some = "$isDisabled";
			break;
		default:
			# go with POST
			break;
	}
    if ($currSetting["who"]["none_complete"] == "true") {
        $surveyCompleteNo = "checked";
        $surveyCompleteYes = "$isDisabled";
        $surveyCompleteNoMatter = "$isDisabled";
    } else if ($currSetting["who"]["none_complete"] == "false") {
        $surveyCompleteNo = "$isDisabled";
        $surveyCompleteYes = "checked";
        $surveyCompleteNoMatter = "$isDisabled";
        $lastCompleteMonths = $currSetting["who"]["last_complete"];
    } else if ($currSetting["who"]["none_complete"] == "nomatter") {
        $surveyCompleteNo = "$isDisabled";
        $surveyCompleteYes = "$isDisabled";
        $surveyCompleteNoMatter = "checked";
    }
	if ($currSetting["who"]["max_emails"]) {
		$maxEmails = $currSetting["who"]["max_emails"];
		$maxSpecified = "checked";
	}
	if (isset($currSetting["who"]["new_records_since"]) && $currSetting["who"]["new_records_since"]) {
		$newRecordsSince = $currSetting["who"]["new_records_since"];
		$newRecordsSinceSpecified = "checked";
	}
}

?>
<link href="<?= CareerDev::link("/css/quill.snow.css") ?>" rel="stylesheet">
<script src="<?= CareerDev::link("/js/quill.js") ?>"></script>
<link rel="stylesheet" href="<?= CareerDev::link("/css/flatpickr.min.css") ?>">
<script src="<?= CareerDev::link("/js/flatpickr.js") ?>"></script>

<script>
var messages = <?= json_encode($messages) ?>;

$(document).ready(function() {
	$('#<?= $selectName ?>').change(function() {
		var val = $('#<?= $selectName ?> option:selected').val();
		if (val) {
			window.location = '<?= CareerDev::link("/emailMgmt/configure.php")."&$selectName=" ?>'+val;
		} else {
			window.location = '<?= CareerDev::link("/emailMgmt/configure.php") ?>';
		}
	});
	$('#<?= $messageSelectName ?>').change(function() {
		var val = $('#<?= $messageSelectName ?> option:selected').val();
		if (val && messages[val]) {
			$('#message .ql-editor').html(messages[val]);
		}
	});
	$('input.who_to').on('change', function() { updateAll(this, '<?= $pid ?>', <?= json_encode($realPost) ?>); });
	$("form").on("submit",function(){
		if ($("#message .ql-editor").length > 0) {
			$("[name=message]").val($("#message .ql-editor").html());
		} else {
			$("[name=message]").val($("#message").html());
		}
	});
	if (typeof quill != "undefined") {
		quill.on("text-change", function(delta, oldDelta, source) {
			updateAll($("#message .ql-editor"), '<?= $pid ?>', <?= json_encode($realPost) ?>);
		});
	}

	updateNames('<?= $pid ?>', <?= json_encode($realPost) ?>);
<?php

	if (!$isDisabled) {
		echo "$('.datetime').flatpickr({ enableTime: true, dateFormat: '".EmailManager::getFormat()."' });\n";
	}

?>
	$(".datetime").on('change', function() { var name = $(this).attr('name'); $('input[type=hidden][name='+name+']').val($(this).val()+'M'); });
});
</script>

<style>
.ql-picker-label { z-index: -1; }
</style>
<script src='<?= CareerDev::link("/js/emailMgmtNew.js") ?>'></script>
<div id='overlay'></div>

<h1>Send an Email</h1>

<form action='<?= CareerDev::link("/emailMgmt/configure.php") ?>' method='POST'>
    <?= Application::generateCSRFTokenHTML() ?>
	<h2 class='orange'>Specify Email Name</h2>
	<table class='centered' style='margin-bottom: 16px;'><tr>
		<td class='centered'>Email Name:<br><input type='text' id='name' name='name' class='long' value='<?= $currSettingName ?>'></td>
		<td class='centered'>--OR--</td>
		<td class='centered'>Load Existing Email:<br><?= $mgr->getSelectForExistingNames($selectName, $currSettingName) ?></td>
	</tr></table>

	<table style='width: 100%;'>
	<tr><td class='oneThird'>
		<h2 class='green'>Who?</h2>
			<h3 class='green'>From Email Address</h3>
			<p class='centered'><input <?= $isReadonly ?> type='text' id='from' name='from' class='long' value='<?= isset($_POST['from']) ? REDCapManagement::sanitize($_POST['from']) : (isset($currSetting['who']['from']) ? $currSetting['who']['from'] : "") ?>'></p>

			<h3 class='green'>To (Recipients)</h3>
			<p class='centered'>Who Do You Want to Receive Your Email?<br>
				<span class='nowrap'><input class='who_to' type='radio' name='recipient' id='individuals' value='individuals' <?= $indivs ?>><label for='individuals'> Individual(s)</label></span><?= $spacing ?><span class='nowrap'><input type='radio' class='who_to' name='recipient' id='filtered_group' value='filtered_group' <?= $filteredGroup ?>><label for='filtered_group'> Filtered Group</label></span>
			</p>
			<div id='checklist' <?php if ($indivs != "checked") { echo "style='display: none;'"; } ?>>
				<h4 style='margin-bottom: 0;'>List of Names<span class='namesCount'></span></h4>
				<p class='namesFiltered'></p>
			</div>
			<div id='filter' <?php if ($filteredGroup != "checked") { echo "style='display: none;'"; } ?>>
				<p class='centered' id='filter_scope'>Do You Want to Email All or Some Scholars?<br>
					<span class='nowrap'><input class='who_to' type='radio' name='filter' id='all' value='all' <?= $all ?>><label for='all'> All</label></span><?= $spacing ?><span class='nowrap'><input type='radio' class='who_to' name='filter' id='some' value='some' <?= $some ?>><label for='some'> Some</label></span>
				</p>
				<div id='filterItems' style='display: none;'>
					<p class='centered'>Filter: Have Any Surveys Been Completed?<br>
                        <span class='nowrap'><input class='who_to' type='radio' name='survey_complete' id='survey_no' value='no' <?= $surveyCompleteNo ?>><label for='survey_no'> No</label></span><?= $spacing ?><span class='nowrap'><input class='who_to' type='radio' name='survey_complete' id='survey_yes' value='yes' <?= $surveyCompleteYes ?>><label for='survey_yes'> Yes</label></span><?= $spacing ?><span class='nowrap'><input class='who_to' type='radio' name='survey_complete' id='survey_no_matter' value='nomatter' <?= $surveyCompleteNoMatter ?>><label for='survey_no_matter'> Doesn't Matter</label></span>
					</p>
					<p class='centered' id='whenCompleted' style='display: none;'>Filter: Skip if the Scholar Has Filled Out a Survey in Last:<br>
						<input class='who_to' type='text' style='width: 50px;' id='last_complete_months' name='last_complete_months' value='<?= $lastCompleteMonths ?>' <?= $isReadonly ?>> Months
					</p>

					<p class='centered'>Filter: What Are the Maximum Number of Emails (Including Follow-Ups) a Scholar Can Receive?<br>
						<span class='nowrap'><input class='who_to' type='radio' name='max' id='max_unlimited' value='unlimited' <?= ($maxSpecified == "checked" ? $isDisabled : "checked") ?>><label for='max_unlimited'> Unlimited</label></span><?= $spacing ?><span class='nowrap'><input class='who_to' type='radio' name='max' id='max_number' value='limited' <?= $maxSpecified ?>><label for='max_number'> Limited to Number</label></span>
						<p class='centered' id='numEmails' style='display: none;'>
							<input class='who_to' type='text' style='width: 50px;' id='max_emails' name='max_emails' value='<?= $maxEmails ?>' <?= $isReadonly ?>> Emails
						</p>
					</p>

					<p class='centered'>Filter: Include Only New Records Since a Certain Date?<br>
						<span class='nowrap'><input class='who_to' type='radio' name='newRecords' id='new_records_all' value='all' <?= $newRecordsSinceSpecified == "checked" ? $isDisabled : "checked" ?>><label for='new_records_all'> All Relevant Records</label></span><?= $spacing ?><span class='nowrap'><input class='who_to' type='radio' name='newRecords' id='new_records_only' value='new' <?= $newRecordsSinceSpecified ?>><label for='new_records_only'> Only Newer Records</label></span>
						<p class='centered' id='newRecordsSinceDisplay' style='display: none;'>Filter: Include Only New Records Created in the Last
							<input <?= $isReadonly ?> class='who_to' type='number' style='width: 50px;' id='new_records_since' name='new_records_since' value='<?= $newRecordsSince ?>'> Months
						</p>
					</p>

                    <p class='centered'>Filter: Has the Scholar Received an R01-or-Equivalent Grant?<br>
                        <span class='nowrap'><input class='who_to' type='radio' name='r01_or_equiv' id='r01_no' value='no' <?= $r01No ?>><label for='r01_no'> No, Only K</label></span><?= $spacing ?><span class='nowrap'><input class='who_to' type='radio' name='r01_or_equiv' id='r01_yes' value='yes' <?= $r01Yes ?>><label for='r01_yes'> Yes</label></span><?= $spacing ?><span class='nowrap'><input class='who_to' type='radio' name='r01_or_equiv' id='r01_agnostic' value='agnostic' <?= $r01Agnostic ?>><label for='r01_agnostic'> Doesn't Matter</label></span>
                    </p>

                    <p class='centered'>Filter: Is the Scholar a Current Trainee or an Alumnus/Alumna?<br>
                        <span class='nowrap'><input class='who_to' type='radio' name='trainee_class' id='trainee_class_all' value='all' <?= $traineeClassAll ?>><label for='trainee_class_all'> All Scholars</label></span><?= $spacing ?><span class='nowrap'><input class='who_to' type='radio' name='trainee_class' id='trainee_class_current' value='current' <?= $traineeClassCurrent ?>><label for='trainee_class_current'> Current Trainee</label></span><?= $spacing ?><span class='nowrap'><input class='who_to' type='radio' name='trainee_class' id='trainee_class_alumni' value='alumni' <?= $traineeClassAlumni ?>><label for='trainee_class_alumni'> Alumni</label></span>
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
		echo "<p class='centered'>Subject: <input $isReadonly type='text' id='subject' class='long' name='subject' value='".(isset($_POST['subject']) ? REDCapManagement::sanitize($_POST['subject']) : (isset($currSetting['what']['subject']) ? $currSetting['what']['subject'] : ""))."'></p>\n";
		echo "<div style='text-align: center; margin: 16px 0px;'>\n";
		echo "<div style='display: inline-block;'>".$mgr->getSurveySelect($surveySelectId)."<br>\n";
		echo "<button $isDisabled onclick='insertSurveyLink(\"$surveySelectId\"); return false;'>Insert Survey Link</button></div>\n";
		echo "<div style='display: inline-block;'><button $isDisabled onclick='insertName(); return false;'>Insert Name</button></div>\n";
        echo "<div style='display: inline-block;'><button $isDisabled onclick='insertLastName(); return false;'>Insert Last Name</button></div>\n";
        echo "<div style='display: inline-block;'><button $isDisabled onclick='insertFirstName(); return false;'>Insert First Name</button></div>\n";
		if (CareerDev::has("mentoring_agreement")) {
            echo "<div style='display: inline-block;'><button $isDisabled onclick='insertMentoringLink(); return false;'>Insert Mentoring Agreement Link</button></div>\n";
        }
		echo "</div>\n";
		if (!empty($messages) && !$isDisabled) {
			echo "<div style='text-align: center; margin: 16px 0px;'>Load Prior Message:<br>".$mgr->getSelectForExistingNames($messageSelectName)."</div>\n";
		}
		if (isset($_POST['message'])) {
			$mssg = Sanitizer::sanitizeWithoutStrippingHTML($_POST['message'], FALSE);
		} else if (isset($currSetting['what']['message'])) {
			$mssg = $currSetting['what']['message'];
		} else {
			$mssg = "";
		}
		echo "<div id='message' style='background-color: white; z-index: 1;".($isReadonly ? " padding: 8px; font-size: 12px;" : "")."'>$mssg</div>\n";
		echo "<input type='hidden' name='message' value=''>\n";

		$emailWarning = "<p class='centered'>Emails are sent in batches. Times are approximate.</p>"; 

?>
	</td>
	<td class='oneThird'>

		<h2 class='purple'>When?</h2>
			<?= $emailWarning ?>
			<h3 class='purple'>Schedule Email</h3>
			<?= makeDateTime("initial_time", $currSetting['when'], $isReadonly) ?>


<?php
		if ($allowFollowups) {
			echo "<h3 class='purple'>Follow-Up Email (Optional; Only to Non-Respondants)</h3>\n";
			echo makeDateTime("followup_time", $currSetting['when'], $isReadonly)."\n";
		}

		$intro = "";
		if (($currSetting != EmailManager::getBlankSetting()) && !$currSetting['enabled']) {
			$testStyle = "";
			$intro = "Re-";
		} else {
			$testStyle = " display: none;";
		}
		if ($hasErrors) {
			$testStyle = " display: none;";
		}
		echo "<div style='margin-top: 50px;' id='status' class='blue padded'>\n";
		if (isset($currSetting['enabled']) && $currSetting['enabled']) {
			echo "<h2 class='blue'>Current Status: Activated</h2>\n";
			echo "<p class='centered'><button onclick='disableEmailSetting(); return true;'>Modify Email</button></p>\n";   // button should resubmit entire page
			$stageText = "Update &amp; Re-Stage to Test";
			$stageStyle = " display: none;";
		} else {
			echo "<h2 class='blue'>Current Status: Not Activated</h2>\n";
			$stageText = $intro."Stage to Test";
			$stageStyle = "";
		}
		echo "</div>\n";
		echo "<div style='margin-top: 50px;$stageStyle' id='save' class='blue padded'>\n";
		echo "<h2 class='blue'>Advance Process</h2>\n";
		echo "<input type='hidden' name='enabled' value='".($currSetting['enabled'] ? "true" : "false")."'>\n";
		echo "<h3 class='blue'>Step 1 of 3</h3>\n";
		echo "<p class='centered'><button>$stageText</button></p>\n";
		echo "<div class='padded' style='$testStyle' id='test'>\n";
		echo "<h3 class='blue'>Step 2 of 3</h3>\n";
		echo "<p class='centered'>You will receive one email per every recipient.</p>\n";
		echo "<p class='centered'>Your Email Address: <input type='text' id='test_to'></p>\n";
		echo "<p class='centered'><button onclick='sendTestEmails(\"$pid\", \"$selectName\", \"$currSettingName\"); return false;'>Test Email Setting</button></p>\n";
		echo "</div>\n";
		echo "<div id='enableEmail' style='display: none;' class='padded'>\n";
		echo "<h3 class='blue'>Step 3 of 3</h3>\n";
		echo "<p class='centered'><button onclick='enableEmailSetting(); return true;'>Activate Emails &amp; Enqueue to Send</button></p>\n";    // should resubmit entire page
		echo "</div>\n";
		echo "</div>\n";
?>
	</td>
	</tr>
	</table>
</form>

<?php

if (!$isDisabled) {
	echo "<script>var quill = new Quill('#message', { theme: 'snow' });</script>\n";
}

function decodeEmail($str) {
    return str_replace("_at_", "@", $str);
}

function translatePostToEmailSetting($post) {
    if (!is_array($post)) {
        return ["", "", []];
    }
	$emailSetting = EmailManager::getBlankSetting();

	$settingName = $post['name'] ?: "";
    if (!$settingName) {
		return ["A name for the setting was not specified", "", EmailManager::getBlankSetting()];
	}

	if (isset($post['enabled']) && ($post['enabled'] == "true")) {
		$emailSetting["enabled"] = TRUE;
	}

	# WHO
	if (isset($post['recipient'])) {
		if ($post['recipient'] == 'individuals') {
			$checkedEmails = array();
			foreach ($post as $key => $value) {
                $key = decodeEmail($key);
                if ($value && EmailManager::isEmailAddress($key)) {
					$checkedEmails[] = $key;
				}
			}
			if (!empty($checkedEmails)) {
				$emailSetting["who"]["individuals"] = implode(",", $checkedEmails);
			} else {
				return ["No individuals are checked", "", EmailManager::getBlankSetting()];
			}
		}
	} else {
		return ["No recipient is specified", "", EmailManager::getBlankSetting()];
	}
	if (isset($post['recipient']) && ($post['recipient'] == "filtered_group")) {
		if ($post['filter']) {
			$emailSetting["who"]["filter"] = $post["filter"];
		} else {
			return ["The Filter for Some vs. All was not specified", "", EmailManager::getBlankSetting()];
		}
		if (isset($post["survey_complete"])) {
			if (isset($post["last_complete_months"]) && ($post["survey_complete"] == "yes")) {
                $emailSetting["who"]["none_complete"] = "false";
				$emailSetting["who"]["last_complete"] = $post["last_complete_months"];
            } else if ($post["survey_complete"] == "no") {
                $emailSetting["who"]["none_complete"] = "true";
            } else if ($post["survey_complete"] == "nomatter" ) {
                $emailSetting["who"]["none_complete"] = "nomatter";
			} else {
				# only happens if the months are not specified; returns blank setting; better than throwing an exception
				return ["The Months were not specified", "", EmailManager::getBlankSetting()];
			}
		}
		if (isset($post["max_emails"])) {
			$emailSetting["who"]["max_emails"] = $post["max_emails"];
		}
        if (isset($post['newRecords']) && ($post['newRecords'] == "new") && isset($post['new_records_since'])) {
            $emailSetting["who"]["new_records_since"] = $post["new_records_since"];
        }
		if (isset($post["r01_or_equiv"])) {
			$emailSetting["who"]["converted"] = $post["r01_or_equiv"];
		}
        if (isset($post['trainee_class'])) {
		    $emailSetting["who"]["trainee_class"] = $post['trainee_class'];
        }
	}
	if (isset($post["from"])) {
		$emailSetting["who"]["from"] = $post["from"];
	} else {
		return ["From address is not specified", "", EmailManager::getBlankSetting()];
	}

	# WHAT
	if (isset($post["message"]) && isset($post["subject"])) {
		$emailSetting["what"]["message"] = $post["message"];
		$emailSetting["what"]["subject"] = $post["subject"];
	} else {
		return ["The Message or Subject were not specified", "", EmailManager::getBlankSetting()];
	}

	# WHEN
	if (isset($post["initial_time"])) {
		$emailSetting["when"]["initial_time"] = $post['initial_time'];
	} else {
		return ["The time for Initial Survey was not specified", "", EmailManager::getBlankSetting()];
	}
	if (isset($post["followup_time"])) {
		$emailSetting["when"]["followup_time"] = $post['followup_time'];
	}

	return ["", $settingName, $emailSetting];
}

function makeDateTime($field, $when, $isReadonly = "") {
	$value = "";
	if (isset($_POST[$field])) {
		$value = REDCapManagement::sanitize($_POST[$field]);
	} else if (isset($when[$field])) {
		$value = $when[$field];
	}

	$html = "";
	$html .= "<p class='centered'><input $isReadonly type='text' id='$field' class='datetime' name='$field' value='$value'></p>";

	return $html;
}

function getRealInput($source) {
	$pairs = explode("&", $source == 'POST' ? file_get_contents("php://input") : $_SERVER['QUERY_STRING'] ?? "");
	$vars = array();
	foreach ($pairs as $pair) {
		$nv = explode("=", $pair);
		$name = urldecode($nv[0]);
		if (isset($nv[1])) {
            $value = urldecode($nv[1]);
        } else {
		    $value = "";
        }
		$vars[$name] = $value;
	}
	return $vars;
}
