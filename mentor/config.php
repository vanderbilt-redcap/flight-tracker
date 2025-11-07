<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__."/../classes/Autoload.php");
require_once(__DIR__."/../small_base.php");

define("DEFAULT_RESOURCE_FIELD", "resources_resource");

if (!Application::has("mentoring_agreement")) {
	throw new \Exception("The Mentoring Agreement is not set up in this project.");
}

if (($_POST['action'] == "updateField") && in_array($_POST['field'], ['mentor_name', 'mentor_userids', 'mentee_userid'])) {
	$records = Download::recordIdsByPid($pid);
	$recordId = Sanitizer::getSanitizedRecord($_POST['record'], $records);
	$value = Sanitizer::sanitizeWithoutChangingQuotes($_POST['value']);
	$uploadRow = [
		"record_id" => $recordId,
	];
	if ($_POST['field'] == "mentor_name") {
		$uploadRow["summary_mentor"] = $value;
		if (Application::isPluginProject($pid)) {
			$uploadRow["override_mentor"] = $value;
		} else {
			$uploadRow["imported_mentor"] = $value;
		}
	} elseif ($_POST['field'] == "mentor_userids") {
		$uploadRow["summary_mentor_userid"] = $value;
		if (Application::isPluginProject($pid)) {
			$uploadRow["override_mentor_userid"] = $value;
		} else {
			$uploadRow["imported_mentor_userid"] = $value;
		}
	} elseif ($_POST['field'] == "mentee_userid") {
		# Every project but one uses identifier_userid; the original Newman project uses identifier_vunet
		# I probably should convert all data into the new convention, but I've never done so
		$useridField = Download::getUseridField($token, $server);
		if ($useridField) {
			$uploadRow[$useridField] = $value;
		}
	}
	if (count($uploadRow) > 1) {
		$feedback = Upload::oneRow($uploadRow, $token, $server);
	} else {
		$feedback = ["error" => "Could not locate field."];
	}
	echo json_encode($feedback);
	exit;
} elseif (($_POST['action'] == "notifyMentees") && !empty($_POST['records'] ?? [])) {
	$result = MMAHelper::sendInitialEmails($_POST['records'], $pid, $token, $server, $_POST['customAgreement'] === 'true', $_POST['mentorUserIds']);
	if (!is_int($result) && $result['error']) {
		echo json_encode($result);
		exit;
	}
	echo json_encode(["result" => $result." emails sent."]);
	exit;
}
if (isset($_POST['custom_questions_data']) && !empty($_POST['custom_questions_data'])) {
	$customQuestionData = json_decode($_POST['custom_questions_data'], true);
	foreach ($customQuestionData as $questionNumber => &$question) {
		$question['questionSource'] = MMAHelper::CUSTOM_QUESTIONS_SOURCE_KEY;
		$question['questionNumber'] = $questionNumber;
	}
	Application::saveSetting("adminCustomQuestions_mma", $customQuestionData, $pid);
}
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$metadataFields = Download::metadataFieldsByPid($pid);
$resourceField = DataDictionaryManagement::getMentoringResourceField($metadataFields);
$choices = [
	DEFAULT_RESOURCE_FIELD => DataDictionaryManagement::getChoicesForField($pid, DEFAULT_RESOURCE_FIELD),
	$resourceField => DataDictionaryManagement::getChoicesForField($pid, $resourceField),
];

$userids = Download::userids($token, $server);
$mentorUserids = Download::primaryMentorUserids($token, $server);
$mentors = Download::primaryMentors($token, $server);
$names = Download::names($token, $server);
$menteeCheckboxes = [];
foreach ($names as $recordId => $name) {
	$id = "record_$recordId";
	$menteeCheckboxes[] = "<input type='checkbox' class='menteeRecord' value='1' id='$id' name='$id' /> <label for='$id'>$recordId: $name</label>";
}

if (DataDictionaryManagement::isInitialSetupForResources($choices[$resourceField])) {
	if (isset($choices[DEFAULT_RESOURCE_FIELD])) {
		$resourceChoices = $choices[DEFAULT_RESOURCE_FIELD];
	} else {
		$resourceChoices = [];
	}
} else {
	$resourceChoices = $choices[$resourceField] ?? [];
}
$savedList = Application::getSetting("mentoring_local_resources", $pid);
$defaultList = implode("\n", array_values($resourceChoices));
if (!$defaultList) {
	$defaultList = $savedList;
}
$rightWidth = 500;
$defaultLink = Application::getSetting("mentee_agreement_link", $pid);

$mssg = "";
if (
	isset($_POST['action'])
	&& ($_POST['action'] == "save")
	&& isset($_POST['linkForResources'])
	&& isset($_POST['linkForIDP'])
) {
	$linkForResources = Sanitizer::sanitizeURL($_POST['linkForResources']);
	$linkForIDP = Sanitizer::sanitizeURL($_POST['linkForIDP']);
	$sanitizedList = Sanitizer::sanitize($_POST['resourceList'] ?? "");
	MMAHelper::updateAgreementSectionsEnabledStatusForProject($pid, $_POST['enabled_mentee_section']);
	$mssg = saveResourceLinks($linkForResources, $linkForIDP, $sanitizedList, $choices, $resourceField, $savedList, $defaultList, $pid);
}
$vanderbiltLinkText = "";
if (Application::isVanderbilt()) {
	$vumcLink = Application::getDefaultVanderbiltMenteeAgreementLink();
	$vanderbiltLinkText = "<br/><a href='$vumcLink'>Default for Vanderbilt Medical Center</a>";
}

echo $mssg;


$dashboardLink = Application::link("mentor/dashboard.php");
$homeLink = Application::getMenteeAgreementLink($pid);
$menteeProgressLink = Application::link("mentor/menteeProgress.php", $pid, true);
$redcapLookupUrl = Application::link("mentor/lookupREDCapUseridFromREDCap.php");
$driverUrl = Application::link("this");


list($firstHalfMenteeCheckboxes, $secondHalfMenteeCheckboxes) = array_chunk($menteeCheckboxes, ceil(count($menteeCheckboxes) / 2));
$agreementSectionsEnabledStatus = MMAHelper::getAgreementSectionsEnabledStatusForProject($pid);
$customAdminQuestions = Application::getSetting("adminCustomQuestions_mma", $pid);
if (empty($menteeCheckboxes)) {
    $firstHalfMenteeCheckboxes = [];
    $secondHalfMenteeCheckboxes = [];
} else if (count($menteeCheckboxes) == 1) {
    $firstHalfMenteeCheckboxes = $menteeCheckboxes;
    $secondHalfMenteeCheckboxes = [];
} else {
    list($firstHalfMenteeCheckboxes, $secondHalfMenteeCheckboxes) = array_chunk($menteeCheckboxes, intval(ceil(count($menteeCheckboxes) / 2)));
}

?>

<style>
    .resultsBox:empty { display: none; }
</style>
<script src="<?=Application::link("/mentor/js/mentorConfigure.js")?>"></script>


<h1>Start Mentee-Mentor Agreements</h1>
<h2>Step 1: Configure Agreements</h2>

<p class="centered max-width"><a id="configureOpen" href="javascript:;" onclick="$('#configForm').slideDown();">Click here to configure</a>.</p>

<form id="configForm" action="<?= Application::link("this") ?>" method="POST" style="display: none;">
    <?= Application::generateCSRFTokenHTML() ?>
    <input type="hidden" name="action" id="action" value="save" />
    <table class="max-width centered padded">
        <tr>
            <td class="left-align bolded"><label for="resourceList">Institutional Resources for Mentoring<br/>(One Per Line Please.)<br/>These will be offered to the mentee.</label></td>
            <td><textarea name="resourceList" id="resourceList" style="width: <?= $rightWidth ?>px; height: 300px;"><?= $defaultList ?></textarea></td>
        </tr>
        <tr>
            <td class="left-align bolded"><label for="linkForResources">Link to Further Resources<?= $vanderbiltLinkText ?></label></td>
            <td><input type="text" style="width: <?= $rightWidth ?>px;" name="linkForResources" id="linkForResources" value="<?= $defaultLink ?>"></td>
        </tr>
        <tr>
            <td class="left-align"><label for="linkForIDP" class="bolded">Link for Further Questions for the Individual Development Plan (IDP) - optional</label><div class="smaller">If you have some program-specific questions, you can turn them into a REDCap Survey in a separate project and add the Public Survey Link here.</div></td>
            <td><input type="text" style="width: <?= $rightWidth ?>px;" name="linkForIDP" id="linkForIDP" value="<?= $defaultLink ?>"></td>
        </tr>
        <tr>
            <td class="left-align"><label class="bolded">Questionnaire Sections To Display</label><div class="cetnered smaller">If some sections of the agreement aren't applicable to your program you can disable them here.</div></td>
            <td>
            <input type="checkbox" name="enabled_mentee_section[]" value="Mentee_Mentor_11_Meetings" <?php echo $agreementSectionsEnabledStatus['Mentee_Mentor_11_Meetings'] ? 'checked' : '' ?>> <label>Mentee-Mentor 1:1 Meetings</label><br>
            <input type="checkbox" name="enabled_mentee_section[]" value="Lab_Meetings" <?php echo $agreementSectionsEnabledStatus['Lab_Meetings'] ? 'checked' : '' ?>> <label>Lab Meetings</label><br>
            <input type="checkbox" name="enabled_mentee_section[]" value="Communication" <?php echo $agreementSectionsEnabledStatus['Communication'] ? 'checked' : '' ?>> <label>Communication</label><br>
            <input type="checkbox" name="enabled_mentee_section[]" value="Mentoring_Panel" <?php echo $agreementSectionsEnabledStatus['Mentoring_Panel'] ? 'checked' : '' ?>> <label>Mentoring Panel</label><br>
            <input type="checkbox" name="enabled_mentee_section[]" value="Financial_Support" <?php echo $agreementSectionsEnabledStatus['Financial_Support'] ? 'checked' : '' ?>> <label>Financial Support</label><br>
            <input type="checkbox" name="enabled_mentee_section[]" value="Scientific_Development" <?php echo $agreementSectionsEnabledStatus['Scientific_Development'] ? 'checked' : '' ?>> <label>Scientific Development</label><br>
            <input type="checkbox" name="enabled_mentee_section[]" value="Approach_to_Scholarly_Products" <?php echo $agreementSectionsEnabledStatus['Approach_to_Scholarly_Products'] ? 'checked' : '' ?>> <label>Approach to Scholarly Products</label><br>
            <input type="checkbox" name="enabled_mentee_section[]" value="Career_and_Professional_Development" <?php echo $agreementSectionsEnabledStatus['Career_and_Professional_Development'] ? 'checked' : '' ?>> <label>Career and Professional Development</label><br>
            <input type="checkbox" name="enabled_mentee_section[]" value="Individual_Development_Plan" <?php echo $agreementSectionsEnabledStatus['Individual_Development_Plan'] ? 'checked' : '' ?>> <label>Individual Development Plan</label><br>
            </td>
        </tr>
        <tr class="row">
            <td colspan="2">
                <label class="bolded">Custom Questions</label><div class="cetnered smaller">If You'd like to configure some custom questions that appear on all mentor/mentee agreements you can add them here.</div>
            </td>
        </tr>
        <tr class="row">
            <td colspan="2">
                <label for="customQuestionsNum">How many custom questions would you like to ask your mentees?</label>
                <select id="customQuestionsNum" name="num_custom_questions">
                    <?php
					for ($i = 0; $i <= MMAHelper::NUM_CUSTOM_QUESTIONS; $i++) {
						echo "<option value='$i'>$i</option>";
					}
?>
                </select>
            </td>
        </tr>
        <tr class="row">
            <td colspan="2">
                <div id="customQuestionArea" class="centered">
                </div>
            </td>
        </tr>
    </table>
    <p class="centered"><button class="green">Change Configuration</button></p>
    <input type="hidden" name="custom_questions_data" id="custom_questions_data" value="<?= htmlspecialchars(json_encode($customAdminQuestions)) ?>" />
</form>

<h2>Step 2: Select &amp; Contact Mentees</h2>

<p class="centered max-width">Mentees are the first to fill out the mentoring agreement. Then the mentor will be contacted second to fill it out with the mentee. Both mentees and mentors will need access to this REDCap system. To proceed, you will need to:</p>

<div class="centered max-width-400"><ol class="left-align">
        <li>Select the mentee(s) to contact</li>
        <li>Secure the mentee(s) user-ids through a search</li>
        <li>Identify the mentor and the mentor's user-id</li>
</ol></div>

<p class="centered"><button class='green' onclick="$('.setupSteps').hide(); $('#menteeNames').slideDown(); $(this).removeClass('green'); $(this).html('Restart');">Start Now</button></p>

<div class="centered max-width-600 setupSteps" id="menteeNames" style="display: none;">
    <h3 class="halfMargin"><?= count($menteeCheckboxes) ?> Mentees to Launch Process</h3>
    <p class="centered">Please select mentees for whom you would like to start a new agreement. You will be given a chance to view and adjust their mentors momentarily.</p>
    <p class="centered"><button class='green' onclick="const checkSel = 'input[type=checkbox].menteeRecord:checked'; if ($(checkSel).length === 0) { alertForBlankChecks(); return false; } $('#menteeNames').slideUp(); fillInfoBoxes(checkSel, '#menteeInfo', '#mentorInfo'); $('#menteeUserids').slideDown();">Next Sub-Step: Secure Mentee User-IDs</button></p>
    <p class="left-align" style="width: 50%; float: left;"><?= implode("<br/>", $firstHalfMenteeCheckboxes) ?></p>
    <p class="left-align" style="width: 50%; float: left;"><?= implode("<br/>", $secondHalfMenteeCheckboxes) ?></p>
    <p class="centered"><button class='green' onclick="$('#menteeNames').slideUp(); fillInfoBoxes('input[type=checkbox].menteeRecord:checked', '#menteeInfo', '#mentorInfo'); $('#menteeUserids').slideDown();">Next Sub-Step: Secure Mentee User-IDs</button></p>
</div>

<div class="centered max-width setupSteps" id="menteeUserids" style="display: none; min-height: 550px;">
    <?= makeLookupHTML($redcapLookupUrl, "_mentee") ?>
    <h3 class="halfMargin">Mentee User-IDs</h3>
    <p class="centered">Each mentee must have a REDCap user-id to proceed. You can look up REDCap users' emails using the box on the right.</p>
    <div style="width: 500px;">
        <p class="left-align" id="menteeInfo"></p>
        <p class="centered"><button class='green' onclick="if (!verifyFieldsNotBlank('#menteeInfo input[type=text]')) { alertForBlankFields(); return false; } $('#menteeUserids').slideUp(); $('#mentors').slideDown();">Next Sub-Step: Identify Mentors</button></p>
    </div>
</div>

<div class="centered max-width setupSteps" id="mentors" style="display: none; min-height: 550px;">
    <?= makeLookupHTML($redcapLookupUrl, "_mentor") ?>
    <h3 class="halfMargin">Mentor User-IDs</h3>
    <p class="centered">To proceed, each mentee must have a mentor with a REDCap user-id. You can look up REDCap users' emails using the box on the right.</p>
    <div style="width: 500px;">
        <p class="left-align" id="mentorInfo"></p>
        <p class="centered">If you want to allow mentors to add custom questions to their Mentor Agreements, click Notify <strong>Mentors</strong>. If you wish to skip this step, click Notify <strong>Mentees</strong>.</p>
        <p><strong>Final Sub-Step: Notify by Email</strong></p>
        <p class="centered">
            <button class='ft-blue-background' onclick="if (!verifyFieldsNotBlank('#mentorInfo input[type=text]')) { alertForBlankFields(); return false; } $('#mentors').slideUp(); notifyMentees('<?= $driverUrl ?>', true, () => { $('#notify').slideDown(); }); "">Notify Mentors</button>
            OR
            <button class='green' onclick="if (!verifyFieldsNotBlank('#mentorInfo input[type=text]')) { alertForBlankFields(); return false; } $('#mentors').slideUp(); notifyMentees('<?= $driverUrl ?>', false, () => { $('#notify').slideDown(); }); ">Notify Mentees</button>
        </p>
    </div>
</div>

<div class="centered max-width-600 green padded" id="notify" style="display: none;">
    <p class="centered bolded">Your mentee(s) have been notified via email. You do not need to contact them further. Please continue to monitor them through the <a href="<?= $dashboardLink ?>">monitoring dashboard</a>.</p>
</div>

<h2>Step 3: Monitor Progress</h2>

<p class="centered max-width"><a href="<?= $dashboardLink ?>">Click here to access the monitoring dashboard</a>. (It's also available from the Mentors menu.)</p>

<div class="yellow padded">
    <h3>Quick Links</h3>
    <p class="centered max-width">These links are provided as helpful tools, but are not required to be sent unless needed or desired.</p>
    <p class='centered max-width-600' style="margin-bottom: 0;"><label for="homeurl">This link will provide mentees with access to their Mentee-Mentor Agreement anytime:</label><br/>
        <input type='text' id='homeurl' value='<?= $homeLink ?>' onclick='this.select();' readonly='readonly' style='width: 98%; margin-right: 5px; margin-left: 5px;' /></p>
    <p style="margin-top: 0;" class='max-width-600 alignright smaller'><a href='javascript:;' onclick='copyToClipboard($("#homeurl"));'>Copy</a></p>
    <p class='centered max-width-600' style="margin-bottom: 0;"><label for="progressurl">This link will provide mentors with the ability to track their mentee:</label><br/>
        <input type='text' id='progressurl' value='<?= $menteeProgressLink ?>' onclick='this.select();' readonly='readonly' style='width: 98%; margin-right: 5px; margin-left: 5px;' /></p>
    <p style="margin-top: 0;" class='max-width-600 alignright smaller'><a href='javascript:;' onclick='copyToClipboard($("#progressurl"));'>Copy</a></p>
    <a href="<?php echo Application::link('mentor/index_mentorcustomquestions.php')?>">Test Link to custom page</a>
</div>

    <script>
    const allMenteeNames = <?= json_encode($names) ?>;
    const allMenteeUserids = <?= json_encode($userids) ?>;
    const allMentorNames = <?= json_encode($mentors) ?>;
    const allMentorUserids = <?= json_encode($mentorUserids) ?>;
    const redcap_csrf_token = '<?= Application::generateCSRFToken() ?>';

    function notifyMentees(url, customAgreement, cb) {
        const records = [];
        $('input[type=checkbox].menteeRecord:checked').each((idx, ob) => {
            const recordId = $(ob).attr("id").replace(/^record_/, '');
            records.push(recordId);
        });

        const mentorUserIds = [];
        $('input.mentorUserIdField').each((idx, ob) => {
            if ($(ob).val() !== '') {
                mentorUserIds.push($(ob).val());
            }
        })

        const postdata = {
            'redcap_csrf_token': redcap_csrf_token,
            action: "notifyMentees",
            records: records,
            customAgreement: customAgreement,
            mentorUserIds: mentorUserIds,
        }
        console.log(JSON.stringify(postdata));
        $.post(url, postdata, (json) => {
            console.log(json);
            processJSON(json, cb);
        });
    }

    function alertForBlankChecks() {
        $.sweetModal({
            content: 'No mentees are checked. Please check one to proceed.',
            icon: $.sweetModal.ICON_ERROR
        });
    }

    function alertForBlankFields() {
        $.sweetModal({
            content: 'Some fields are blank. Please fill these in or restart the process.',
            icon: $.sweetModal.ICON_ERROR
        });
    }

    function verifyFieldsNotBlank(selector) {
        let returnValue = true;
        $(selector).each((idx, ob) => {
            if ($(ob).val() === '') {
                returnValue = false;
            }
        });
        return returnValue;
    }

    function fillInfoBoxes(checkboxSel, menteeInfoSel, mentorInfoSel) {
        const menteeHTML = [];
        const mentorHTML = [];
        $(checkboxSel).each((idx, ob) => {
            const recordId = $(ob).attr("id").replace(/^record_/, '');
            const menteeName = allMenteeNames[recordId] ?? "Unknown";
            const menteeUserid = allMenteeUserids[recordId] ?? "";
            const mentorNameAry = allMentorNames[recordId] ?? [];
            const mentorNames = mentorNameAry.join(", ");
            const mentorUseridAry = allMentorUserids[recordId] ?? [];
            const mentorUserids = mentorUseridAry.join(", ");
            menteeHTML.push(makeMenteeInfo(recordId, menteeName, menteeUserid));
            mentorHTML.push(makeMentorInfo(recordId, mentorNames, mentorUserids));
        });
        $(menteeInfoSel).html(menteeHTML.join(''));
        $(mentorInfoSel).html(mentorHTML.join(''));
    }

    function makeMenteeInfo(recordId, menteeName, menteeUserid) {
        let html = '<div style="margin: 1em 0;" class="centered">';
        html += "<label for='record_"+recordId+"_mentee_userid'>User-id for "+menteeName+":</label><br/><input type='text' id='record_"+recordId+"_mentee_userid' value='"+menteeUserid+"' onblur='updateField(\"<?= $driverUrl ?>\", \""+recordId+"\", \"mentee_userid\", this);' />"
        html += '</div>';
        return html;
    }

    function makeMentorInfo(recordId, mentorNames, mentorUserids) {
        const menteeName = allMenteeNames[recordId] ?? "Unknown";
        let html = '<div style="margin: 1em 0;" class="centered">';
        html += "<strong>Mentee: "+menteeName+"</strong><br/>";
        html += "<label for='record_"+recordId+"_mentors'>Mentor Name(s):</label><br/><input type='text' style='width: 400px;' id='record_"+recordId+"_mentors' value='"+mentorNames+"' onblur='updateField(\"<?= $driverUrl ?>\", \""+recordId+"\", \"mentor_name\", this);' /><br/>"
        html += "<label for='record_"+recordId+"_mentor_userids'>Mentor User-id(s) <span class='smaller'>[Comma-Separated]</span>:</label><br/><input type='text' class='mentorUserIdField' id='record_"+recordId+"_mentor_userids' value='"+mentorUserids+"' onblur='updateField(\"<?= $driverUrl ?>\", \""+recordId+"\", \"mentor_userids\", this);' />"
        html += '</div>';
        return html;
    }

    function updateField(url, recordId, field, ob) {
        const value = $(ob).val();
        if (
            ((field === "mentee_userid") && (allMenteeUserids[recordId] === value))
            || ((field === "mentor_userid") && (allMentorUserids[recordId].join(", ") === value))
            || ((field === "mentor_name") && (allMentorNames[recordId].join(", ") === value))
        ) {
            // No change
            return;
        }
        const postdata = {
            'redcap_csrf_token': redcap_csrf_token,
            'record': recordId,
            'action': 'updateField',
            'field': field,
            'value': value,
        };
        console.log(JSON.stringify(postdata));
        $.post(url, postdata, (json) => {
            console.log(json);
            processJSON(json, () => {
                // multiple checks might appear; I'm not worried about that
                const id = 'check'+Date.now();
                $(ob).after('<span id="'+id+'" style="color: #8dc63f;">&#x2713;</span>');
                setTimeout(() => {
                    $('#'+id).fadeOut(500);
                }, 3000);

                // update JS data stores
                if (field === "mentee_userid") {
                    allMenteeUserids[recordId] = value;
                } else if (field === "mentor_userid") {
                    allMentorUserids[recordId] = value.split(/\s*,\s*/);
                } else if (field === "mentor_name") {
                    allMentorNames[recordId] = value.split(/\s*,\s*/);
                }
            });
        });
    }

    function processJSON(json, cb) {
        try {
            const data = JSON.parse(json);
            if ((typeof data['error'] !== 'undefined') && (data['error'] !== "")) {
                console.error(data['error']);
                $.sweetModal({
                    content: data['error'],
                    icon: $.sweetModal.ICON_ERROR
                });
            } else if ((typeof data['errors'] !== "undefined") && (data['errors'].length > 0)) {
                console.error(data['errors'].join("\n"));
                $.sweetModal({
                    content: data['errors'].join('<br/><br/>'),
                    icon: $.sweetModal.ICON_ERROR
                });
            } else if (cb) {
                cb(data);
            }
        } catch(e) {
            console.error(e);
            $.sweetModal({
                content: e,
                icon: $.sweetModal.ICON_ERROR
            });
        }
    }

</script>

<?php

function saveResourceLinks($linkForResources, $linkForIDP, $sanitizedList, $choices, $resourceField, $savedList, &$defaultList, $pid) {
	$linksToSet = [
		"mentee_agreement_link" => [
			"link" => $linkForResources,
			"description" => "Link for Further Resources",
		],
		"idp_link" => [
			"link" => $linkForIDP,
			"description" => "Link for IDP",
		],
	];
	foreach ($linksToSet as $settingName => $ary) {
		if (isset($ary['link']) && is_string($ary['link'])) {
			$link = $ary['link'];
		} else {
			$link = "";
		}
		$descript = $ary['description'] ?? "";
		if ($link) {
			if (!preg_match("/^https?:\/\//i", $link)) {
				$link = "https://".$link;
			}
			if (REDCapManagement::isValidURL($link)) {
				Application::saveSetting($settingName, $link, $pid);
			} else {
				return "<p class='red centered max-width'>Improper URL $descript</p>";
			}
		} else {
			Application::saveSetting($settingName, "", $pid);
		}
	}

	$resources = preg_split("/[\n\r]+/", $sanitizedList);
	$resourceList = implode("\n", $resources);
	Application::saveSetting("mentoring_resources", $resourceList);

	$reverseResourceChoices = [];
	foreach ($choices[$resourceField] ?? [] as $idx => $label) {
		$reverseResourceChoices[$label] = $idx;
	}

	$mssg = "";
	$newResources = [];
	$existingResources = [];
	foreach ($resources as $resource) {
		if ($resource !== "") {
			if (!isset($reverseResourceChoices[$resource])) {
				$newResources[] = $resource;
			} else {
				$existingResources[] = $resource;
			}
		}
	}
	$deletedResourceIndexes = [];
	foreach ($choices[$resourceField] as $idx => $label) {
		if (!in_array($label, $existingResources)) {
			$deletedResourceIndexes[] = $idx;
		}
	}
	if (!empty($newResources) || !empty($deletedResourceIndexes)) {
		$resourceIndexes = array_keys($choices[$resourceField] ?? []);
		if (REDCapManagement::isArrayNumeric($resourceIndexes)) {
			$maxIndex = !empty($resourceIndexes) ? max($resourceIndexes) : 0;
		} else {
			$numericResourceIndexes = [];
			foreach ($resourceIndexes as $idx) {
				if (is_numeric($idx)) {
					$numericResourceIndexes[] = $idx;
				}
			}
			$maxIndex = !empty($numericResourceIndexes) ? max($numericResourceIndexes) : 0;
		}
		$resourcesByIndex = $choices[$resourceField];
		foreach ($deletedResourceIndexes as $idx) {
			unset($resourcesByIndex[$idx]);
		}
		$nextIndex = ((int) $maxIndex) + 1;
		foreach ($newResources as $resource) {
			$resourcesByIndex[$nextIndex] = $resource;
			$nextIndex++;
		}
		if (empty($resourcesByIndex) && Application::isVanderbilt()) {
			$resourcesByIndex = DataDictionaryManagement::getMenteeAgreementVanderbiltResources();
			$resourceStr = DataDictionaryManagement::makeChoiceStr($resourcesByIndex);
		} elseif (empty($resourcesByIndex) && $savedList) {
			$savedLabels = preg_split("/[\n\r]+/", $savedList);
			$pairs = [];
			foreach ($savedLabels as $i => $label) {
				$pairs[] = ($i + 1).", $label";
			}
			$resourceStr = implode(" | ", $pairs);
		} elseif (empty($resourcesByIndex) && !empty($choices[DEFAULT_RESOURCE_FIELD])) {
			$resourceStr = REDCapManagement::makeChoiceStr($choices[DEFAULT_RESOURCE_FIELD]);
		} elseif (empty($resourcesByIndex) && !isset($choices[DEFAULT_RESOURCE_FIELD])) {
			$resourceStr = "1, Institutional Resources Here";
		} else {
			$resourceStr = REDCapManagement::makeChoiceStr($resourcesByIndex);
		}
		$metadata = Download::metadataByPid($pid);
		for ($i = 0; $i < count($metadata); $i++) {
			if ($metadata[$i]['field_name'] == $resourceField) {
				$metadata[$i]['select_choices_or_calculations'] = $resourceStr;
			}
		}
		$feedback = Upload::metadataNoAPI($metadata, $pid);
		if (!is_array($feedback) || (!$feedback['errors'] && !$feedback['error'])) {
			$mssg = "<p class='max-width centered green'>Changes made.</p>";
		} else {
			if ($feedback['errors']) {
				$error = implode("<br/>", $feedback['errors']);
			} elseif ($feedback['error']) {
				$error = $feedback['error'];
			} else {
				$error = implode("<br/>", $feedback);
			}
			$mssg = "<p class='max-width centered red'>Error $error</p>";
		}
		$resourceLabels = array_values($resourcesByIndex);
		for ($i = 0; $i < count($resourceLabels); $i++) {
			$resourceLabels[$i] = (string) $resourceLabels[$i];
		}
		$defaultList = implode("\n", $resourceLabels);
	} else {
		$mssg = "<p class='max-width centered green'>No changes needed.</p>";
	}
	return $mssg;
}

function makeLookupHTML($redcapLookupUrl, $suffix) {
	return "
<div class='centered' style='width: 300px; float: right; background-color: rgba(191,191,191,0.5);'>
<h4>Lookup a REDCap User ID</h4>
<p class='centered nomargin smaller'>Please remember that some users might employ nicknames or maiden names.</p>
<p class='green padded resultsBox' id='message$suffix'></p>
<p><label class='smaller' for='first_name$suffix'>First Name</label>: <input type='text' id='first_name$suffix' /><br>
<label class='smaller' for='last_name$suffix'>Last Name</label>: <input type='text' id='last_name$suffix' /><br>
<button onclick='lookupREDCapUserid(\"$redcapLookupUrl\", $(\"#message$suffix\"), \"#first_name$suffix\", \"#last_name$suffix\"); return false;'>Look up name</button>
</div>";
}
