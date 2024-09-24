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
    } else if ($_POST['field'] == "mentor_userids") {
        $uploadRow["summary_mentor_userid"] = $value;
        if (Application::isPluginProject($pid)) {
            $uploadRow["override_mentor_userid"] = $value;
        } else {
            $uploadRow["imported_mentor_userid"] = $value;
        }
    } else if ($_POST['field'] == "mentee_userid") {
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
} else if (($_POST['action'] == "notifyMentees") && !empty($_POST['records'] ?? [])) {
    $requestedRecords = $_POST['records'];
    $allRecords = Download::recordIdsByPid($pid);
    $userids = Download::userids($token, $server);
    $menteeNames = Download::names($token, $server);
    $menteeEmails = Download::emails($token, $server);
    $primaryMentors = Download::primaryMentors($token, $server);
    $matchedRecords = [];
    if (is_array($requestedRecords)) {
        foreach ($requestedRecords as $recordId) {
            $sanitizedRecord = Sanitizer::getSanitizedRecord($recordId, $allRecords);
            if ($sanitizedRecord) {
                $matchedRecords[] = $sanitizedRecord;
            } else {
                echo json_encode(["error" => "Not all records could be matched. There might be multiple users accessing these data at the same time."]);
                exit;
            }
        }
    }
    $emails = [];
    foreach ($matchedRecords as $recordId) {
        $userid = $userids[$recordId] ?? "";
        if (!$userid) {
            echo json_encode(["error" => "Not all user-ids are available. This should never happen. You might want to restart the process."]);
            exit;
        }
        if (($menteeNames[$recordId] ?? "") && REDCapManagement::isEmailOrEmails($menteeEmails[$recordId] ?? "")) {
            $name = $menteeNames[$recordId];
            $email = $menteeEmails[$recordId];
        } else {
            $lookup = new REDCapLookupByUserid($userid);
            $name = $lookup->getName();
            $email = $lookup->getEmail();
        }
        $emails[$email] = [
            "name" => $name,
            "mentors" => $primaryMentors[$recordId] ?? [],
        ];
    }
    $homeLink = Application::getMenteeAgreementLink($pid);
    if (Application::isLocalhost()) {
        error_log("Notifying with $homeLink: ".implode(", ", array_keys($emails)));
    } else {
        $defaultFrom = Application::getSetting("default_from", $pid) ?: "noreply.flighttracker@vumc.org";
        $base64 = Application::getBase64("img/flight_tracker_logo_medium_white_bg.png");
        $logo = "<p><img src='$base64' alt='Flight Tracker for Scholars' /></p>";
        foreach ($emails as $email => $info) {
            $mentorNames = REDCapManagement::makeConjunction($info['mentors']);
            $name = $info['name'];
            $mssg = "$logo<p>Dear $name,</p><p>You have been requested to form a mentee-mentor agreement with $mentorNames. To configure your custom REDCap-based agreement, please use the following link to customize it electronically. Thank you!</p><p><a href='$homeLink'>$homeLink</a></p>";
            \REDCap::email($email, $defaultFrom, "Mentee-Mentor Agreement", $mssg);
        }
    }
    echo json_encode(["result" => count($emails)." emails sent."]);
    exit;
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
$menteeProgressLink = Application::link("mentor/menteeProgress.php", $pid, TRUE);
$redcapLookupUrl = Application::link("mentor/lookupREDCapUseridFromREDCap.php");
$driverUrl = Application::link("this");

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

<h1>Start Mentee-Mentor Agreements</h1>
<h2>Step 1: Configure Agreements</h2>

<p class="centered max-width"><a href="javascript:;" onclick="$('#configForm').slideDown();">Click here to configure</a>.</p>

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
    </table>
    <p class="centered"><button class="green">Change Configuration</button></p>
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
        <p class="centered"><button class='green' onclick="if (!verifyFieldsNotBlank('#mentorInfo input[type=text]')) { alertForBlankFields(); return false; } $('#mentors').slideUp(); notifyMentees('<?= $driverUrl ?>', () => { $('#notify').slideDown(); }); ">Final Sub-Step: Notify Mentees by Email</button></p>
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
</div>

    <script>
    const allMenteeNames = <?= json_encode($names) ?>;
    const allMenteeUserids = <?= json_encode($userids) ?>;
    const allMentorNames = <?= json_encode($mentors) ?>;
    const allMentorUserids = <?= json_encode($mentorUserids) ?>;
    const redcap_csrf_token = '<?= Application::generateCSRFToken() ?>';

    function notifyMentees(url, cb) {
        const records = [];
        $('input[type=checkbox].menteeRecord:checked').each((idx, ob) => {
            const recordId = $(ob).attr("id").replace(/^record_/, '');
            records.push(recordId);
        });

        const postdata = {
            'redcap_csrf_token': redcap_csrf_token,
            action: "notifyMentees",
            records: records,
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
        html += "<label for='record_"+recordId+"_mentor_userids'>Mentor User-id(s) <span class='smaller'>[Comma-Separated]</span>:</label><br/><input type='text' id='record_"+recordId+"_mentor_userids' value='"+mentorUserids+"' onblur='updateField(\"<?= $driverUrl ?>\", \""+recordId+"\", \"mentor_userids\", this);' />"
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
        } else if (empty($resourcesByIndex) && $savedList) {
            $savedLabels = preg_split("/[\n\r]+/", $savedList);
            $pairs = [];
            foreach ($savedLabels as $i => $label) {
                $pairs[] = ($i+1).", $label";
            }
            $resourceStr = implode(" | ", $pairs);
        } else if (empty($resourcesByIndex) && !empty($choices[DEFAULT_RESOURCE_FIELD])) {
            $resourceStr = REDCapManagement::makeChoiceStr($choices[DEFAULT_RESOURCE_FIELD]);
        } else if (empty($resourcesByIndex) && !isset($choices[DEFAULT_RESOURCE_FIELD])) {
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
            } else if ($feedback['error']) {
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