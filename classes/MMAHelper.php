<?php

namespace Vanderbilt\CareerDevLibrary;

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(__DIR__ . '/ClassLoader.php');
require_once(APP_PATH_DOCROOT."/Classes/UserRights.php");

define('NEW_HASH_DESIGNATION', 'NEW');

class MMAHelper {
    public static function createHash($token, $server) {
        $newHash = bin2hex(random_bytes(self::getHashLength()));
        $recordIds = Download::recordIds($token, $server);
        $newRecordId = empty($recordIds) ? 1 : (max($recordIds) + 1);
        $uploadRow = [
            "record_id" => $newRecordId,
            "identifier_hash" => $newHash,
            "identifiers_complete" => "2",
        ];
        Upload::oneRow($uploadRow, $token, $server);
        return ["record" => $newRecordId, "hash" => $newHash];
    }

    public static function validateHash($proposedHash, $token, $server, $proposedRecordId = "") {
        if (self::isValidHash($proposedHash)) {
            $savedHashes = Download::oneField($token, $server, "identifier_hash");
            if ($proposedRecordId) {
                if (
                    $proposedHash
                    && isset($savedHashes[$proposedRecordId])
                    && ($savedHashes[$proposedRecordId] == $proposedHash)
                ) {
                    return ["record" => $proposedRecordId, "hash" => $proposedHash];
                }
            } else {
                foreach ($savedHashes as $recordId => $instanceHash) {
                    if (
                        $proposedHash
                        && $instanceHash
                        && ($instanceHash == $proposedHash)
                    ) {
                        return ["record" => $recordId, "hash" => $instanceHash];
                    }
                }
            }
        }
        return ["record" => FALSE, "hash" => FALSE];
    }

    private static function getHashLength() {
        return 32;
    }

    public static function isValidHash($str) {
        return (strlen($str) == self::getHashLength() * 2);
    }

    public static function makePublicApplicationForm($token, $server, $hash, $recordId = FALSE) {
        $html = "";

        $formUrl = Application::link("mentor/createHash.php")."&hash=".NEW_HASH_DESIGNATION;
        if ($hash == NEW_HASH_DESIGNATION) {
            $formStyle = "";
            $startStyle = "style='display: none; cursor: pointer;'";
            $startLink = "";
            $mentorLink = "";
            $menteeLink = "";
            $mentorName = "";
            $menteeName = "";
        } else if ($hash && $recordId) {
            $recordData = Download::records($token, $server, [$recordId]);
            if (isset($_GET['test'])) {
                echo "Record $recordId data: ".json_encode($recordData)."<br>";
            }
            $menteeName = REDCapManagement::findField($recordData, $recordId, "identifier_first_name")." ".REDCapManagement::findField($recordData, $recordId, "identifier_last_name");
            $mentorName = REDCapManagement::findField($recordData, $recordId, "mentor_first_name")." ".REDCapManagement::findField($recordData, $recordId, "mentor_last_name");

            $formStyle = "style='display: none;'";
            $startStyle = "style='cursor: pointer;'";
            $indexLink = Application::link("mentor/index.php")."&hash=$hash&menteeRecord=$recordId";
            $menteeLink = $indexLink."&instance=1";
            $mentorLink = $indexLink."&instance=2";
            if (self::isMentee($recordId)) {
                $startLink = Application::link("mentor/index_menteeview.php")."&hash=$hash&menteeRecord=$recordId";
            } else {
                $startLink = Application::link("mentor/index_mentorview.php")."&hash=$hash&menteeRecord=$recordId&index=2";
            }
        } else {
            die("Improper access!");
        }

        $menteeUrl = Application::link("mentor/index_menteeview.php");
        $mentorUrl = Application::link("mentor/index_mentorview.php");
        $blueBoxText = implode("<br>&rarr; ", ["Enter Mentee Preferences", "Discussion with Mentor", "Sign Final Agreement"]);
        if (Application::getProgramName() == "Flight Tracker Mentee-Mentor Agreements") {
            $oneMentorOnlyWarning = "<p class='centered'>For this agreement, in the case of multiple mentors, we suggest that one mentor take the ‘lead’ on this agreement process.  Certainly, we encourage the Mentors and Mentee to have open dialogue about the issues contained herein, but respectfully defer this responsibility to the lead Mentor on this Agreement and the Mentee.</p>";
        } else {
            $oneMentorOnlyWarning = "";
        }

        $html .= "<section class='bg-light'><div class='container'><div class='row'><div class='col-lg-12'>
<div class='blue-box'>
    <h2 style='color: #222222;'>Typical Workflow Starting<br/>Early in Relationship</h2>
    <h3>$blueBoxText</h3>
</div>

<style>
td.nameEntryLeft { text-align: right !important; adding-top: 10px; margin: 0; }
td.nameEntryLeft label { margin: 0; font-size: 0.9em; }
td.nameEntryRight { text-align: left; padding-top: 0 !important; padding-left: 10px !important; p}
.smaller { font-size: 0.8em; line-height: 1; }
</style>

<form id='form' $formStyle>
    $oneMentorOnlyWarning
    <table style='margin: 0 auto;'>
        <tr style='background-color: white;'>
            <td class='nameEntryLeft'><label for='menteeFirstName'>Mentee First Name:</label></td>
            <td class='nameEntryRight'><input type='text' id='menteeFirstName' /></td>
        </tr>
        <tr style='background-color: white;'>
            <td class='nameEntryLeft'><label for='menteeLastName'>Mentee Last Name:</label></td>
            <td class='nameEntryRight'><input type='text' id='menteeLastName' /></td>
        </tr>
        <tr style='background-color: white;'>
            <td class='nameEntryLeft'><label for='menteeEmail'>Mentee Email:</label></td>
            <td class='nameEntryRight'><input type='text' id='menteeEmail' /></td>
        </tr>
        <tr style='background-color: white;'><td colspan='2'>&nbsp;</td></tr>
        <tr style='background-color: white;'>
            <td class='nameEntryLeft'><label for='mentorFirstName'>Mentor First Name:</label></td>
            <td class='nameEntryRight'><input type='text' id='mentorFirstName' /></td>
        </tr>
        <tr style='background-color: white;'>
            <td class='nameEntryLeft'><label for='mentorLastName'>Mentor Last Name:</label></td>
            <td class='nameEntryRight'><input type='text' id='mentorLastName' /></td>
        </tr>
        <tr style='background-color: white;'>
            <td class='nameEntryLeft'><label for='mentorEmail'>Mentor Email:</label></td>
            <td class='nameEntryRight'><input type='text' id='mentorEmail' /></td>
        </tr>
        <tr style='background-color: white;'><td colspan='2' style='text-align: center; padding-top: 10px;'><button onclick='signUpForMMA(); return false;'>Sign Up</button></td></tr>
    </table>
    <input type='hidden' id='hash' value='' />
</form>

<div id='start' class='blue-box' onclick='startNow();' $startStyle>
    <h2>Start Now</h2>
    <p class='centered'>First, <a href='$menteeLink' id='menteeLink'>mentee <span id='menteeName'>$menteeName</span> can start here</a>.<br/>
    Then, <a href='$mentorLink' id='mentorLink'>mentor <span id='mentorName'>$mentorName</span> can start here</a>.</p>
    <p class='smaller centered'>The mentor's link will be emailed when the mentee completes the form. If you lose the mentee's link, you will no longer have access to these data for security reasons.</p>
    <input type='hidden' id='startLink' value='$startLink'/>
</div>

<script>
function signUpForMMA() {
    if ($('#hash').val() && $('#startLink').val()) {
        $('#start').show();
        $('#form').hide();
        $.sweetModal({
            content: 'You have already signed up!',
            icon: $.sweetModal.ICON_ERROR
        });
    } else {
        const vars = ['menteeFirstName', 'menteeLastName', 'menteeEmail', 'mentorFirstName', 'mentorLastName', 'mentorEmail'];
        const emailVars = ['menteeEmail', 'mentorEmail'];
        const post = { };
        const url = '$formUrl';
        const emailRegex =   /^(([^<>()[\]\.,;:\s@\"]+(\.[^<>()[\]\.,;:\s@\"]+)*)|(\".+\"))@(([^<>()[\]\.,;:\s@\"]+\.)+[^<>()[\]\.,;:\s@\"]{2,})$/i;
        for (let i=0; i < emailVars.length; i++) {
            const variable = emailVars[i];
            const email = $('#'+variable).val();
            if ((email === '') || !email.match(emailRegex)) {
                $.sweetModal({
                    content: 'You must provide a valid email address!',
                    icon: $.sweetModal.ICON_ERROR
                });
                return;
            }
        }
        for (let i=0; i < vars.length; i++) {
            const variable = vars[i];
            const value = $('#'+variable).val();
            if (value === '') {
                $.sweetModal({
                    content: 'You must provide all variables!',
                    icon: $.sweetModal.ICON_ERROR
                });
                return;
            }
            post[variable] = value;
        }
        $.post(url, post, function(json) {
            console.log(json);
            try {
                const data = JSON.parse(json);
                const recordId = data['record'];
                const hash = data['hash'];
                if (recordId && hash && !isNaN(recordId)) {
                    const paramStr = '&hash='+hash+'&menteeRecord='+recordId;
                    const menteeLink = '$menteeUrl&instance=1'+paramStr;
                    const mentorLink = '$mentorUrl&instance=2'+paramStr;
                    $('#menteeLink').attr('href', menteeLink);
                    $('#mentorLink').attr('href', mentorLink);
                    $('#menteeName').html(data['menteeFirstName']+' '+data['menteeLastName']);
                    $('#mentorName').html(data['mentorFirstName']+' '+data['mentorLastName']);
                    $('#startLink').val(menteeLink);
                    $('#hash').val(hash);
                    $('#start').show();
                    $('#form').hide();
                } else {
                    $.sweetModal({
                        content: 'Could not create new entry!',
                        icon: $.sweetModal.ICON_ERROR
                    });
                }
            } catch (e) {
                $.sweetModal({
                    content: 'Error: '+json,
                    icon: $.sweetModal.ICON_ERROR
                });
            }
        });
    }
}

function startNow() {
    const url = $('#startLink').val();
    if (url) {
        location.href = url;
    } else {
        $.sweetModal({
            content: 'No link provided! Unsure what to do next.',
            icon: $.sweetModal.ICON_ERROR
        });
    }
}
</script>
";
        return $html;
    }

    public static function makeMainTable($token, $server, $username, $metadata, $menteeRecordIds, $uidString = "") {
        $html = "";

        $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
        list($firstName, $lastName) = MMAHelper::getNameFromREDCap($username, $token, $server);
        $names = Download::names($token, $server);
        $userids = Download::userids($token, $server);
        $allMentorUids = Download::primaryMentorUserids($token, $server);
        $allMentors = Download::primaryMentors($token, $server);
        $redcapData = Download::fieldsForRecords($token, $server, array_unique(array_merge($metadataFields, ["record_id"])), $menteeRecordIds);

        $blueBoxText = implode("<br>&rarr; ", ["Enter Mentee Preferences", "Discussion with Mentor", "Sign Final Agreement", "Revisit Agreement"]);

        $html .= "<section class='bg-light'><div class='container'><div class='row'><div class='col-lg-12'>
<div class='blue-box'>
    <h2 style='color: #222222;'>Typical Workflow Starting<br/>Early in Relationship</h2>
    <h3>$blueBoxText</h3>
</div>
<h2>$firstName, here are your mentee-mentor relationships</h2>
<table id='quest1' class='table listmentors' style='margin-left: 0px;'>
    <thead>
    <tr>
        <th style='text-align: center;' scope='col'>latest update</th>
        <th style='text-align: center;' scope='col'>progress</th>
        <th style=''text-align: center;' scope='col'>status</th>
        <th scope='col'>mentee</th>
        <th scope='col'>mentor(s)</th>
        <th scope='col'>phase</th>
        <th scope='col'>send notification</th>
    </tr>
    </thead>
    <tbody>";

        $i = 1;
        foreach ($menteeRecordIds as $menteeRecordId) {
            $menteeName = $names[$menteeRecordId];
            $menteeUserids = self::getMenteeUserids($userids[$menteeRecordId]);
            $namesOfMentors = $allMentors[$menteeRecordId];
            $useridsOfMentors = $allMentorUids[$menteeRecordId];
            $myRow = self::getLatestRow($menteeRecordId, [$username], $redcapData);
            $mentorRow = self::getLatestRow($menteeRecordId, $allMentorUids[$menteeRecordId], $redcapData);
            if (empty($myRow)) {
                $instance = REDCapManagement::getMaxInstance($redcapData, "mentoring_agreement", $menteeRecordId) + 1;
                $percentComplete = 0;
                $mdy = date("m-d-Y");
                $lastMentorInstance = FALSE;
                $surveyText = "start";
            } else {
                $percentComplete = self::getPercentComplete($myRow, $metadata);
                $mdy = REDCapManagement::YMD2MDY($myRow['mentoring_last_update']);
                $instance = $myRow['redcap_repeat_instance'];
                $lastMentorInstance = $mentorRow['redcap_repeat_instance'];
                $surveyText = "edit";
            }
            $newMentorInstance = $instance + 1;
            $trailerURL = $uidString."&menteeRecord=$menteeRecordId&instance=$instance";
            if (in_array($username, $menteeUserids)) {
                $surveyPage = Application::link("mentor/index_menteeview.php").$trailerURL;
            } else {
                $surveyPage = Application::link("mentor/index_mentorview.php").$trailerURL;
            }
            if ($lastMentorInstance) {
                $completedTrailerURL = $uidString."&menteeRecord=$menteeRecordId&instance=$lastMentorInstance";
                $completedPage = Application::link("mentor/index_complete.php").$completedTrailerURL;
            } else {
                $completedPage = "";
            }
            $phaseHTMLAry = [
                "<input type='radio' id='phase1_$menteeRecordId' onclick='changePhase(this);' name='phases_$menteeRecordId' value='1'> <label for='phase1_$menteeRecordId'>0 - 6 months</label>",
                "<input type='radio' id='phase2_$menteeRecordId' onclick='changePhase(this);' name='phases_$menteeRecordId' value='2'> <label for='phase2_$menteeRecordId'>7 - 12 months</label>",
                "<input type='radio' id='phase3_$menteeRecordId' onclick='changePhase(this);' name='phases_$menteeRecordId' value='3'> <label for='phase3_$menteeRecordId'>12+ months</label>",
            ];
            $phaseText = implode("<br/>", $phaseHTMLAry);

            $html .= "<tr id='m$i'>\n";
            $html .= "<th scope='row'><a class='surveylink' href='$surveyPage'>$surveyText</a></th>\n";
            if ($percentComplete > 0) {
                $html .= "<td class='orange'>$percentComplete%<br><small>$mdy</small></td>\n";
            } else {
                $html .= "<td class='red incomplete'>NOT STARTED</td>\n";
            }
            if ($completedPage) {
                $html .= "<td><a href='$completedPage'>view last agreement</a></td>\n";
            } else {
                $html .= "<td>no prior agreements</td>\n";
            }
            $html .= "<td>$menteeName</td>\n";
            if (!empty($namesOfMentors)) {
                $mentorNameText = REDCapManagement::makeConjunction($namesOfMentors);
            } else {
                $mentorNameText = "None listed";
            }
            $changeMentorLink = "";
            if (self::isMentee($menteeRecordId, $username)) {
                $changeMentorLink = "<br><a href='".Application::link("mentor/addMentor.php")."&menteeRecord=$menteeRecordId$uidString'>Add a Mentor</a>";
            }
            $html .= "<td>$mentorNameText$changeMentorLink</td>\n";
            $html .= "<td>$phaseText</td>\n";
            $html .= "<script>let namesOfMentors_$menteeRecordId = ".json_encode($namesOfMentors)."; let useridsOfMentors_$menteeRecordId = ".json_encode($useridsOfMentors).";</script>\n";
            $html .= "<td><a href='javascript:void(0)' onclick='sendreminder(\"$menteeRecordId\", \"$newMentorInstance\", namesOfMentors_$menteeRecordId, useridsOfMentors_$menteeRecordId, \"$menteeName\");'>send reminder for mentor(s) to complete</a></td>\n";
            $html .= "</tr>\n";
            $i++;
        }
        $html .= "</tbody></table>";
        if (empty($menteeRecordIds)) {
            $html .= "<div style='text-align: center;'>No Mentees Active For You</div>";
        }
        $html .= "</div></div></div></section>";

        return $html;
    }

    public static function hasMentorAgreementRightsForPlugin($pid, $username) {
        $menteeRecord = FALSE;
        if (isset($_REQUEST['menteeRecord'])) {
            $menteeRecord = $_REQUEST['menteeRecord'];
        } else if (isset($_REQUEST['record'])) {
            $menteeRecord = $_REQUEST['record'];
        } else if (method_exists("\Vanderbilt\CareerDevLibrary\MMAHelper", "getRecordsAssociatedWithUserid")) {
            $records = self::getRecordsAssociatedWithUserid($username, $pid);
            if (!empty($records)) {
                return TRUE;
            }
        }

        list($redcapData, $useridField) = self::getUseridDataFromREDCap($pid);
        list($menteeUserids, $allMentorUserids) = self::getMenteeMentorUserids($redcapData, $useridField);

        $validUserids = [];
        if ($menteeRecord) {
            if (isset($menteeUserids[$menteeRecord])) {
                $validUserids[] = $menteeUserids[$menteeRecord];
            }
            if (isset($allMentorUserids[$menteeRecord])) {
                foreach ($allMentorUserids[$menteeRecord] as $mentorUserids) {
                    foreach ($mentorUserids as $mentorUserid) {
                        $validUserids[] = $mentorUserid;
                    }
                }
            }
        }
        if (in_array($username, $validUserids)) {
            return TRUE;
        }
        if (MMA_DEBUG && isset($_GET['uid']) && in_array($_GET['uid'], $validUserids)) {
            return TRUE;
        }
        return FALSE;
    }

    public static function getRecordsAssociatedWithUserid($username, $pidOrToken, $server = FALSE) {
        if (!$username || self::isValidHash($username) || ($username == NEW_HASH_DESIGNATION)) {
            return [];
        }

        if (is_numeric($pidOrToken)) {
            $pid = $pidOrToken;
        } else if (REDCapManagement::isValidToken($pidOrToken) && $server) {
            $token = $pidOrToken;
        } else {
            throw new \Exception("Invalid parameters");
        }

        if (isset($pid)) {
            list($redcapData, $useridField) = self::getUseridDataFromREDCap($pid);
            if (isset($_GET['test'])) {
                echo "Downloaded ".count($redcapData)." rows of REDCap data<br>";
            }
            list($menteeUserids, $allMentorUserids) = self::getMenteeMentorUserids($redcapData, $useridField);
        } else if (isset($token)) {
            $menteeUserids = Download::userids($token, $server);
            $allMentorUserids = Download::primaryMentorUserids($token, $server);
        } else {
            throw new \Exception("This should never happen - no token or pid");
        }

        if (isset($_GET['test'])) {
            echo count($menteeUserids)." mentee userids and ".count($allMentorUserids)." mentees with mentor userids<br>";
        }

        if (isset($menteeUserids) && isset($allMentorUserids)) {
            $menteeRecordIds = [];
            $username = strtolower(trim($username));
            foreach ($menteeUserids as $recordId => $menteeUserid) {
                $useridList = self::getUserids($menteeUserid);
                if (isset($_GET['test'])) {
                    echo "Record $recordId: Checking mentee ".json_encode($useridList)." vs. $username<br>";
                }
                foreach ($useridList as $userid) {
                    if ($username == trim($userid)) {
                        $menteeRecordIds[] = $recordId;
                        break;
                    }
                }
            }
            foreach ($allMentorUserids as $recordId => $mentorUserids) {
                foreach ($mentorUserids as $mentorUserid) {
                    if ($username == strtolower(trim($mentorUserid))) {
                        $menteeRecordIds[] = $recordId;
                    }
                }
            }
            $menteeRecordIds = array_unique($menteeRecordIds);
            if (isset($_GET['test'])) {
                echo "Looking for $username and found ".json_encode($menteeRecordIds)."<br>";
            }
            return $menteeRecordIds;
        } else {
            throw new \Exception("Could not find mentee/mentor userids");
        }
    }

    public static function getUserids($useridList) {
        $userids = preg_split("/\s*[,;]\s*/", strtolower($useridList));
        for ($i = 0; $i < count($userids); $i++) {
            $userids[$i] = trim($userids[$i]);
        }
        return $userids;
    }

    public static function getMenteeUserids($useridList) {
        return self::getUserids($useridList);
    }

    public static function getMenteeMentorUserids($redcapData, $useridField) {
        $menteeUserids = [];
        $mentorUserids = [];
        foreach ($redcapData as $row) {
            $recordId = $row['record_id'];
            if ($row[$useridField]) {
                $menteeUserids[$recordId] = $row[$useridField];
            }
            if ($row['summary_mentor_userid']) {
                $mentorUserids[$recordId] = self::getUserids($row['summary_mentor_userid']);
            }
        }
        return [$menteeUserids, $mentorUserids];
    }

    public static function getUseridDataFromREDCap($pid) {
        $json = \REDCap::getDataDictionary($pid, "json");
        $metadata = json_decode($json, TRUE);
        $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
        if (in_array("identifier_userid", $metadataFields)) {
            $useridField = "identifier_userid";
        } else if (in_array("identifier_vunet", $metadataFields)) {
            $useridField = "identifier_vunet";
        } else {
            throw new \Exception("Could not find userid field in ".implode(", ", $metadataFields)." from $json");
        }

        $json = \REDCap::getData($pid, "json", NULL, ["record_id", "summary_mentor_userid", $useridField]);
        $redcapData = json_decode($json, TRUE);
        return [$redcapData, $useridField];
    }

    public static function makePercentCompleteJS() {
        $html = "<script>
    function getPercentComplete() {
        var numer = 0;
        var denom = 0;
        var seen = {};
       // $('textarea.form-check-input').each(function(idx, ob) {
           // if ($(ob).val()) {
               // numer++;
           // }
           // denom++;
       // });
       // skip checkboxes as they can be all blank
       $('input[type=radio].form-check-input:visible').each(function(idx, ob) {
            let name = $(ob).attr('name');
            if (!name.match(/_mentee/) || window.location.href.match(/menteeview/)) {
                if (typeof seen[name] == 'undefined') {
                    denom++;
                    seen[name] = 0;
                }
                if (seen[name] === 0) {
                    if ($(ob).is(':checked')) {
                        numer++;
                        seen[name] = 1;
                    }
                }
            }
        });
        if ((denom === 0) || (numer === 0)) {
            return 0;
        }
        console.log(numer+' / '+denom+' = '+(numer * 100 / denom));
        return Math.ceil(numer * 100 / denom);
    }
    </script>";
        return $html;
    }

    public static function makePriorInstancesDropdown($instances, $currInstance) {
        $html = "";
        $html .= "<div style='margin: 0 auto; width: 100%;'>Open a Prior Instance: <select id='instances' name='instances' style='margin-left: 1em;'>";
        $html .= "<option value=''>--- new ---</option>";
        foreach ($instances as $instance => $date) {
            if ($instance == $currInstance) {
                $sel = " selected";
            } else {
                $sel = "";
            }
            $html .= "<option value='$instance'$sel>$instance: $date</option>";
        }

        $html .= "</select></div>";
        return $html;
    }

    public static function fieldValuesAgree($set1, $set2) {
        foreach ($set1 as $item) {
            if (!in_array($item, $set2)) {
                return FALSE;
            }
        }
        foreach ($set2 as $item) {
            if (!in_array($item, $set1)) {
                return FALSE;
            }
        }
        return TRUE;
    }

    public static function scheduleEmail($to, $from, $subject, $message, $datetime, $pid, $token, $server) {
        if ($datetime == "now") {
            \REDCap::email($to, $from, $subject, $message);
        } else {
            $ts = strtotime($datetime);
            $datetime = date("Y-m-d H:i", $ts);

            $metadata = Download::metadata($token, $server);
            $mgr = new EmailManager($token, $server, $pid, Application::getModule(), $metadata);
            $emailSetting = EmailManager::makeEmailSetting($datetime, $to, $from, $subject, $message, TRUE);
            $settingName = "MMA $subject $datetime TO:$to FROM:$from";
            $mgr->saveSetting($settingName, $emailSetting);
        }
        if (MMA_DEBUG) {
            $subject = "DUPLICATE: ".$to.": ".$subject." on ".$datetime;
            \REDCap::email("scott.j.pearson@vumc.org", $from, $subject, $message);
        }
    }

    public static function parseSectionHeader($sectionHeader) {
        $sectionHeaderLines =  preg_split("/>\s*<p/", $sectionHeader);
        if (count($sectionHeaderLines) > 1) {
            $sec_header = $sectionHeaderLines[0].">";
            $sectionDescriptionLines = [];
            for ($i = 1; $i < count($sectionHeaderLines); $i++) {
                $sectionDescriptionLines[] = $sectionHeaderLines[$i];
            }
            $sectionDescription = "";
            if (!empty($sectionDescriptionLines)) {
                $sectionDescription = "<p".implode("><p", $sectionDescriptionLines);
            }
            return [$sec_header, $sectionDescription];
        } else {
            return [$sectionHeader, ""];
        }
    }

    public static function isMentee($recordId = FALSE, $username = "") {
        global $token, $server;
        if ($username && !self::isValidHash($username)) {
            $userids = Download::userids($token, $server);
            $recordUserids = self::getUserids($userids[$recordId]);
            if (in_array(strtolower($username), $recordUserids)) {
                return TRUE;
            } else {
                return FALSE;
            }
        } else {
            $currPage = REDCapManagement::sanitize($_GET['page']);
            if ($currPage == "mentor/index_menteeview") {
                return TRUE;
            } else if ($currPage == "mentor/index_mentorview") {
                return FALSE;
            } else if ($recordId) {
                global $token, $server;
                $fields = ["record_id", "mentoring_userid"];
                $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
                $maxInstance = REDCapManagement::getMaxInstance($redcapData, "mentoring_agreement", $recordId);
                return ($maxInstance >= 1);
            } else {
                return FALSE;
            }
        }
    }

    public static function getNotesFields($fields) {
        $notesFields = [];
        foreach ($fields as $field) {
            if (preg_match("/_notes$/", $field)) {
                $notesFields[] = $field;
            }
        }
        return $notesFields;
    }

    public static function getLatestRow($recordId, $usernames, $redcapData) {
        if (!isset($usernames) || empty($usernames)) {
            return [];
        }
        $latestRow = [];
        $latestInstance = 0;
        foreach ($redcapData as $row) {
            if (($row['record_id'] == $recordId)
                && ($row['redcap_repeat_instrument'] = "mentoring_agreement")
                && in_array($row['mentoring_userid'], $usernames)
                && ($row['redcap_repeat_instance'] > $latestInstance)) {

                $latestRow = $row;
                $latestInstance = $row['redcap_repeat_instance'];
            }
        }
        return $latestRow;
    }

    public static function getMenteesAndMentors($menteeRecordId, $userid, $token, $server) {
        if (self::isValidHash($userid)) {
            if (isset($_GET['test'])) {
                echo "Public project<br>";
            }
            $hashes = Download::oneField($token, $server, "identifier_hash");
            foreach ($hashes as $recordId => $hash) {
                if ($userid == $hash) {
                    if (isset($_GET['test'])) {
                        echo "Found $hash at $recordId<br>";
                    }
                    $fields = [
                        "record_id",
                        "identifier_first_name",
                        "identifier_last_name",
                        "mentor_first_name",
                        "mentor_last_name",
                    ];
                    $recordData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
                    $normativeRow = REDCapManagement::getNormativeRow($recordData);
                    $myMentees = [$normativeRow['identifier_first_name'] . " " . $normativeRow['identifier_last_name']];
                    $myMentors = [$normativeRow['mentor_first_name'] . " " . $normativeRow['mentor_last_name']];
                    return [["name" => $myMentees], ["name" => $myMentors]];
                }
            }
            if (isset($_GET['test'])) {
                echo "Hash not found in ".json_encode($hashes)."<br>";
            }
            return [[], []];
        } else {
            if (isset($_GET['test'])) {
                echo "Userid project<br>";
            }
            $menteeUserids = Download::userids($token, $server);
            $allMentors = Download::primaryMentors($token, $server);
            $allMentorUserids = Download::primaryMentorUserids($token, $server);

            $menteeUids = self::getUserids($menteeUserids[$menteeRecordId]);
            $mentorUids = $allMentorUserids[$menteeRecordId];
            $myMentees = [];
            $myMentors = [];
            $myMentees["name"] = Download::menteesForMentor($token, $server, $userid);
            if (in_array(strtolower($userid), $menteeUids)) {
                # Mentee
                $myMentors["name"] = $allMentors[$menteeRecordId];
                $myMentors["uid"] = $allMentorUserids[$menteeRecordId];
            } else if (in_array(strtolower($userid), $mentorUids)) {
                # Mentor
                $myMentors["name"] = $allMentors[$menteeRecordId];
                $myMentors["uid"] = $allMentorUserids[$menteeRecordId];
                $myMentees["name"] = Download::menteesForMentor($token, $server, $userid);
                $myMentees["uid"] = [];
                foreach ($myMentees["name"] as $recordId => $name) {
                    $myMentees["uid"][$recordId] = $menteeUserids[$recordId];
                }
            } else {
                throw new \Exception("You do not have access!");
            }
            return [$myMentees, $myMentors];
        }
    }

    public static function cleanMentorName($mentor) {
        $mentor = str_replace(', PhD', '', $mentor);
        $mentor = str_replace('N/A', '', $mentor);
        $mentor = str_replace(',', '', $mentor);
        $mentor = str_replace('PhD', '', $mentor);
        $mentor = str_replace('/', ' and ', $mentor);
        $mentor = str_replace('none (currently)', '', $mentor);
        $mentor = str_replace('no longer in academia', '', $mentor);
        return $mentor;
    }

    public static function filterMetadata($metadata, $skipFields = TRUE) {
        $fieldsToSkip = ["mentoring_userid", "mentoring_last_update", "mentoring_phase"];
        $metadata = REDCapManagement::filterMetadataForForm($metadata, "mentoring_agreement");
        $newMetadata = [];
        foreach ($metadata as $row) {
            if (!in_array($row['field_name'], $fieldsToSkip) || !$skipFields) {
                $newMetadata[] = $row;
            }
        }
        return $newMetadata;
    }

    public static function getPercentComplete($row, $metadata) {
        $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
        $notesFields = self::getNotesFields($metadataFields);
        $numer = 0;
        $denom = count($metadataFields) - count($notesFields);

        $sectionTotals = [];
        $sectionWithData = [];

        $skip = ["checkbox", "notes"];
        $lastSectionHeader = "";
        foreach ($metadata as $metadataRow) {
            if ($metadataRow['section_header']) {
                $sectionTotals[$metadataRow['section_header']] = 0;
                $sectionWithData[$metadataRow['section_header']] = 0;
                $lastSectionHeader = $metadataRow['section_header'];
            }
            if (!in_array($metadataRow['field_name'], $notesFields)) {
                if (in_array($metadataRow['field_type'], $skip)) {
                    $denom--;
                } else {
                    if ($lastSectionHeader) {
                        $sectionTotals[$lastSectionHeader]++;
                    }
                    if ($row[$metadataRow['field_name']]) {
                        $numer++;
                        if ($lastSectionHeader) {
                            $sectionWithData[$lastSectionHeader]++;
                        }
                    }
                }
            }
        }

        foreach ($sectionWithData as $sectionHeader => $totalWithData) {
            if (($totalWithData === 0) && ($sectionTotals[$sectionHeader] > 0)) {
                # entire section was omitted => likely minimized
                $denom -= $sectionTotals[$sectionHeader];
            }
        }

        if ($denom == 0) {
            return 0;
        }
        return ceil($numer * 100 / $denom);
    }

    public static function pullInstanceFromREDCap($redcapData, $instance) {
        foreach ($redcapData as $redcapRow) {
            if (($redcapRow['redcap_repeat_instrument'] == "mentoring_agreement") && ($redcapRow["redcap_repeat_instance"] == $instance)) {
                return $redcapRow;
            }
        }
        return [];
    }

    public static function getNameFromREDCap($username, $token = "", $server = "") {
        if (isset($_GET['test'])) {
            echo "Username $username<br>";
        }
        if (self::isValidHash($username)) {
            $hashToLookFor = $username;
            if ($token && $server) {
                if (self::isMentee()) {
                    $firstNames = Download::firstnames($token, $server);
                    $lastNames = Download::lastnames($token, $server);
                } else {
                    $firstNames = Download::oneField($token, $server, "mentor_first_name");
                    $lastNames = Download::oneField($token, $server, "mentor_last_name");
                }
                $hashes = Download::oneField($token, $server, "identifier_hash");
                foreach ($hashes as $recordId => $recordHash) {
                    if ($recordHash == $hashToLookFor) {
                        return [$firstNames[$recordId], $lastNames[$recordId]];
                    }
                }
            }
        } else {
            if ($token && $server) {
                $firstNames = Download::firstnames($token, $server);
                $lastNames = Download::lastnames($token, $server);
                $userids = Download::userids($token, $server);
                foreach ($userids as $recordId => $userid) {
                    $recordUserids = self::getUserids($userid);
                    if (in_array(strtolower($username), $recordUserids)) {
                        return [$firstNames[$recordId], $lastNames[$recordId]];
                    }
                }
            }

            $sql = "select user_firstname, user_lastname from redcap_user_information WHERE username = '".db_real_escape_string($username)."'";
            $q = db_query($sql);
            if ($row = db_fetch_assoc($q)) {
                $firstName = $row['user_firstname'];
                $lastName = $row['user_lastname'];
                return [$firstName, $lastName];
            }
        }
        return ["", ""];
    }

    public static function getMaxInstanceForUserid($rows, $recordId, $userid) {
        $maxInstance = 0;
        foreach ($rows as $row) {
            if (($row['record_id'] == $recordId)
                && ($row['redcap_repeat_instrument'] == "mentoring_agreement")
                && ($row['redcap_repeat_instance'] > $maxInstance)
                && ($row['mentoring_userid'] == $userid)) {
                $maxInstance = $row['redcap_repeat_instance'];
            }
        }
        return $maxInstance;
    }

    public static function makePopupJS() {

        $resources = [];
        // TODO Additional, Custom Resources - put at top
        $resources[] = "Brown NJ. Developing Physician-Scientists. <i>Circ Res</i>. 2018 Aug 31;123(6):645-647. https://doi.org/10.1161/circresaha.118.313473";
        $resources[] = "Huskins WC, Silet K, Weber-Main AM, Begg MD, Fowler VG, Jr., Hamilton J and Fleming M. Identifying and aligning expectations in a mentoring relationship. <i>Clinical and translational science</i>. 2011;4:439-47. https://doi.org/10.1111/j.1752-8062.2011.00356.x";
        $resources[] = "Ramanan RA, Taylor WC, Davis RB and Phillips RS. Mentoring matters. Mentoring and career preparation in internal medicine residency training. <i>J Gen Intern Med</i>. 2006;21:340-5. https://doi.org/10.1111/j.1525-1497.2006.00346_1.x";
        $resources[] = "Ramanan RA, Phillips RS, Davis RB, Silen W and Reede JY. Mentoring in medicine: keys to satisfaction. <i>The American journal of medicine</i>. 2002;112:336-41. https://doi.org/10.1016/s0002-9343%2802%2901032-x";
        $resources[] = "Pololi L and Knight S. Mentoring faculty in academic medicine. A new paradigm? <i>J Gen Intern Med</i>. 2005;20:866-70. https://doi.org/10.1111/j.1525-1497.2005.05007.x";
        $resources[] = "Pololi LH, Knight SM, Dennis K and Frankel RM. Helping medical school faculty realize their dreams: An innovative, collaborative mentoring program. <i>Academic Medicine</i>. 2002;77:377-384. https://doi.org/10.1097/00001888-200205000-00005";
        $resources[] = "Johnston-Anumonwo I. Mentoring across difference: success and struggle in an academic geography career. <i>Gender Place Cult</i>. 2019;26:1683-1700. https://doi.org/10.1080/0966369x.2019.1681369";
        $resources[] = "Campbell KM and Rodriguez JE. Mentoring Underrepresented Minority in Medicine (URMM) Students Across Racial, Ethnic and Institutional Differences. <i>Journal of the National Medical Association</i>. 2018;110:421-423. https://doi.org/10.1016/j.jnma.2017.09.004";
        $resources[] = "Li SB, Malin JR and Hackman DG. Mentoring supports and mentoring across difference: insights from mentees. <i>Mentor Tutor</i>. 2018;26:563-584. https://doi.org/10.1080/13611267.2018.1561020";
        $resources[] = "Bickel J. When \"You're Not the Boss of Me\": Mentoring across Generational Differences. <i>Educ Compet Glob Wor</i>. 2009:143-152.";
        $resources[] = "Jackson VA, Palepu A, Szalacha L, Caswell C, Carr PL and Inui T. \"Having the right chemistry\": a qualitative study of mentoring in academic medicine. <i>Academic medicine : journal of the Association of American Medical Colleges</i>. 2003;78:328-34. https://doi.org/10.1097/00001888-200303000-00020";
        $resources[] = "Manuel SP and Poorsattar SP. Mentoring up: Twelve tips for successfully employing a mentee-driven approach to mentoring relationships. <i>Medical teacher</i>. 2020:1-4. https://doi.org/10.1080/0142159x.2020.1795098";
        $resources[] = "Koenig AM. Mentoring: Are we living up to our professional role as an educational leader? <i>Nurse Educ Today</i>. 2019;79:54-55. https://doi.org/10.1016/j.nedt.2019.04.007";
        $resources[] = "Hale RL and Phillips CA. Mentoring up: A grounded theory of nurse-to-nurse mentoring. <i>J Clin Nurs</i>. 2019;28:159-172. https://doi.org/10.1111/jocn.14636";
        $resources[] = "Mayer AP, Blair JE, Ko MG, Patel SI and Files JA. Long-term follow-up of a facilitated peer mentoring program. <i>Medical teacher</i>. 2014;36:260-6. https://doi.org/10.3109/0142159x.2013.858111";
        $resources[] = "Maruta T, Rotz P and Peter T. Setting up a structured laboratory mentoring programme. <i>Afr J Lab Med</i>. 2013;2:77. https://doi.org/10.4102/ajlm.v2i1.77";
        $resources[] = "Mentoring--a security blanket or a cover-up? <i>J Cell Sci</i>. 1999;112 ( Pt 20):3413-4.";
        $resources[] = "Cho CS, Ramanan RA and Feldman MD. Defining the ideal qualities of mentorship: a qualitative analysis of the characteristics of outstanding mentors. <i>The American journal of medicine</i>. 2011;124:453-8. https://doi.org/10.1016/j.amjmed.2010.12.007";
        $resources[] = "Carey EC and Weissman DE. Understanding and finding mentorship: a review for junior faculty. <i>Journal of palliative medicine</i>. 2010;13:1373-9. https://doi.org/10.1089/jpm.2010.0091";
        $resources[] = "Feldman AM. The National Institutes of Health Physician-Scientist Workforce Working Group report: a roadmap for preserving the physician-scientist. <i>Clinical and translational science</i>. 2014;7:289-90. https://doi.org/10.1111/cts.12209";
        $resources[] = "Bhagia J, Tinsley JA. The mentoring partnership. <i>Mayo Clin Proc</i>. 2000 May;75:535-7. https://doi.org/10.4065/75.5.535";
        $resources[] = "Carey EC, Weissman DE. Understanding and finding mentorship: a review for junior faculty. <i>J Palliat Med</i> 2010 Nov;13:1373-9. https://doi.org/10.1089/jpm.2010.0091";
        $resources[] = "Flores G, Mendoza FS, DeBaun MR, Fuentes-Afflick E, Jones VF, Mendoza JA, Raphael JL, Wang CJ. Keys to academic success for under-represented minority young investigators: recommendations from the Research in Academic Pediatrics Initiative on Diversity (RAPID) National Advisory Committee. <i>Int J Equity Health</i>. 2019 Jun;18;18(1):93. https://doi.org/10.1186/s12939-019-0995-1";
        $resources[] = "Geraci SA, Thigpen SC. A review of mentoring in academic medicine. <i>Am J Med Sci</i>. 2017 Feb;353(2):151-7. https://doi.org/10.1016/j.amjms.2016.12.002";
        $resources[] = "Sambunjak D, Straus SE, Marusic A. A systematic review of qualitative research on the meaning and characteristics of mentoring in academic medicine. <i>J Gen Intern Med</i>. 2010 Jan;25(1):72-8. https://doi.org/10.1007/s11606-009-1165-8";

        foreach ($resources as $i => $resource) {
            $resource = REDCapManagement::fillInLinks($resource);
            $resource = "<li>$resource</li>";
            $resources[$i] = $resource;
        }

        $close = "<div style='text-align: right; font-size: 13px; margin: 0;'><a href='javascript:;' onclick='$(this).parent().parent().slideUp(\"fast\");' style='text-decoration: none; color: black;'>X</a></div>";

        $html = "";

        $html .= "<div class='characteristics' id='mentor_characteristics' style='display: none;'>$close<h3>Characteristics of Successful Mentor</h3>
<ul style='list-style-type:disc'>
<li>Effectively provide intellectual guidance in the scientific topics of strength, to directly broaden the Mentee’s scientific, and overall academic, proficiency</li>
<li>Shares time with Mentee</li>
<li>Openly communicates with the Mentee how the Mentor can, and cannot, help</li>
<li>Shares openly but also listens attentively</li>
<ul style='list-style-type:circle'>
    <li>Encourages</li>
    <li>Helps to problem-solve</li>
    <li>Provides constructive critique and guidance</li>
</ul>
<li>Serves as an academic role model</li>
<li>Celebrates achievements</li>
<li>Advocate in the scientific 'theater' of study</li>
<ul style='list-style-type:circle'>
    <li>Provides the Mentee, both directly and indirectly, a platform to grain traction in the field at local, regional, national, and international levels as appropriate</li>
</ul>
<li>Recognizes when/where the Mentor’s expertise is limited or requires additional individuals to support for the Mentee</li>
<ul style='list-style-type:circle'>
    <li>Scientific areas (e.g., specific assays or research areas)</li>
    <li>Academic</li>
</ul>
</ul>

<p>Of course, in many circumstances, a single Mentor cannot adequately mentor an individual, due to many constraints, including availability, expertise, etc…  As a result, many scholars require several mentors, or a <a href='https://edgeforscholars.org/you-need-mentors-noun-plural/' target='_new'>mentor panel</a>.  <a href='https://edgeforscholars.org/what-you-should-expect-from-mentors/' target='_new'>Additional resources</a>.
</div>\n";

        $html .= "<div class='characteristics' id='mentee_characteristics' style='display: none;'>$close<h3>Characteristics of a Successful Mentee</h3>
<ul>
<li>Actively participates in the Mentor – Mentee relationship, recognizing that often the Mentor is busy and benefits from an active Mentee ('Mentor up')</li>
<li>Establish a mechanism for frequent contact with the mentor in an agreed upon manner</li>
<li>Honestly assesses one’s scientific and academic strengths and needs, including active pursuit of one- and five- year career plans</li>
<li>Engages the Mentor in career plan development discussion up front and over time</li>
<li>Monitors progress with honest assessments</li>
<li>Respects the mentor’s time</li>
<li>Openly discusses achievements and challenges with the Mentor(s)</li>
<li>Supports an environment receptive to feedback and coaching</li>
<li>Takes advantage of opportunities presented by the mentor</li>
<ul>
    <li>Not every opportunity must be pursued, but should be discussed</li>
</ul>
</ul></div>\n";

        $html .= "<div class='characteristics' id='resources_characteristics' style='display: none;'>$close<h3>References and Additional Resources</h3>
<ul>".implode("", $resources)."</ul></div>";

        $html .= "<script>
function characteristicsPopup(entity) {
    $('.characteristics').hide();
    $('#'+entity+'_characteristics').slideDown();
}
</script>";
        return $html;
    }

# one month prior
    public static function getDateToRemind($data, $recordId, $instance) {
        $dateToRevisit = self::getDateToRevisit($data, $recordId, $instance);
        $dateToRevisit = REDCapManagement::MDY2YMD($dateToRevisit);
        if (REDCapManagement::isDate($dateToRevisit)) {
            $tsToRevisit = strtotime($dateToRevisit);
            if ($tsToRevisit) {
                $dateToReturn = self::adjustDate($tsToRevisit, -1);
                if (isset($_GET['test'])) {
                    echo "dateToReturn: $dateToReturn<br>";
                }
                return $dateToReturn;
            } else {
                if (isset($_GET['test'])) {
                    echo "Could not transform $dateToRevisit into $tsToRevisit<br>";
                }
            }
        }
        return "";
    }

    public static function isFirstEntryForUser($data, $userid, $recordId, $instance) {
        $firstInstance = 1e6;
        $existingInstances = [];
        foreach ($data as $row) {
            if (($row['record_id'] == $recordId) && ($row['mentoring_userid'] == $userid)) {
                if ($firstInstance > $row['redcap_repeat_instance']) {
                    $firstInstance = $row['redcap_repeat_instance'];
                }
                $existingInstances[] = $instance;
            }
        }
        if (!in_array($instance, $existingInstances)) {
            return TRUE;
        } else {
            return ($instance == $firstInstance);
        }
    }

    # returns MDY
    public static function getDateToRevisit($data, $recordId, $instance) {
        $module = Application::getModule();
        $userid = $module->getUsername();
        if (self::isFirstEntryForUser($data, $userid, $recordId, $instance)) {
            $monthsInFuture = 6;
        } else {
            $monthsInFuture = 12;
        }
        $lastUpdate = REDCapManagement::findField($data, $recordId, "mentoring_last_update", "mentoring_agreement", $instance);
        if ($lastUpdate) {
            $ts = strtotime($lastUpdate);
        } else {
            $ts = FALSE;
        }
        if (!$ts) {
            $ts = time();
        }
        if ($monthsInFuture) {
            $dateToRevisit = self::adjustDate($ts, $monthsInFuture);
            if (isset($_GET['test'])) {
                echo "dateToRevisit: $dateToRevisit<br>";
            }
            return $dateToRevisit;
        } else {
            return "An Unspecified Date";
        }
    }

    public static function fixDate($month, $day, $year) {
        # check month
        while ($month > 12) {
            $month -= 12;
            $year++;
        }
        while ($month < 1) {
            $month += 12;
            $year--;
        }

        # check day
        if (!checkdate($month, $day, $year)) {
            $day = 1;
            $month++;
            while ($month > 12) {
                $month -= 12;
                $year++;
            }
        }

        return $month."-".$day."-".$year;
    }

# returns MDY
    public static function adjustDate($ts, $monthsInFuture) {
        $month = date("m", $ts);
        $year = date("Y", $ts);
        $day = date("d", $ts);
        $month += $monthsInFuture;
        return self::fixDate($month, $day, $year);
    }

    public static function makeSurveyHTML($partners, $partnerRelationship, $row, $metadata) {
        $html = "";
        $imageLink = Application::link("mentor/img/temp_image.jpg");
        $scriptLink = Application::link("mentor/vendor/jquery.easy-pie-chart/dist/jquery.easypiechart.min.js");
        $percComplete = self::getPercentComplete($row, $metadata);

        $html .= "
<p><div>
    <div style='float: right; margin-left: 39px; width: 147px; margin-top: 16px;'>
        <span class='chart' data-percent='$percComplete'>
            <span class='percent'></span>
        </span>
        <div style='text-align: center;margin-top: 0px;font-size: 13px;width: 115px;'>(complete)</div>
    </div>
</div></p>";
        $html .= "<p>Welcome to the Mentoring Agreement. The first step to completing the Mentoring Agreement is to reflect on what is important to you in a successful mentee-mentor relationship. Through a series of questions on topics such as meetings, communication, research, and approach to scholarly products, to name a few, this survey will help guide you through that process and provide you with a tool to capture your thoughts. The survey should take about 30 minutes to complete. Your $partnerRelationship ($partners) will also complete a survey.</p>";
        $html .= "<p><img src='$imageLink' style='float: left; margin-right: 39px;width: 296px;'>The mentee should complete the agreement first. An email will alert the mentor(s) whenever the agreement is submitted. The mentor(s) should arrange a time to meet with the mentee to fill out their part of the agreement, which will act as the final authorized/completed agreement. Then the completed agreement can be viewed, signed, and printed. A follow-up email will be scheduled for when the agreement should be revisited.</p>";
        $html .= "<p>Each section below will explore expectations and goals regarding relevant topics for the relationship, such as the approach to direct one-on-one meetings.</p>";
        if (Application::getProgramName() != "Flight Tracker Mentee-Mentor Agreements") {
            $html .= "<p>All sections recommended for you to fill out now are open, and other sections that aren't as timely are collapsed. You may revisit these collapsed sections as you wish by clicking on the header.</p>";
        }

        $html .= "<script src='$scriptLink'></script>";
        $html .= "<script>
    $(document).ready(function() {
        $('.chart').easyPieChart({
            easing: 'easeOutElastic',
            delay: 3000,
            barColor: function(percent) {
                return (percent < 50 ? '#d7431b' : percent < 90 ? '#d7ad1b' : '#4bc856');
            },
            backgroundColor: '#eeeeee',
            trackColor: '#efefef',
            scaleColor: false,
            lineWidth: 12,
            trackWidth: 12,
            lineCap: 'butt',
            onStep: function(from, to, percent) {
                $(this.el).find('.percent').text(Math.round(percent));
            }
        });
        var chart = window.chart = $('.chart').data('easyPieChart');
        $('.js_update').on('click', function() {
            chart.update($percComplete);
        });
    });
    </script>";

        return $html;
    }

    public static function agreementSigned($redcapData, $menteeRecordId, $currInstance) {
        $row = REDCapManagement::getRow($redcapData, $menteeRecordId, "mentoring_agreement", $currInstance);
        $fields = [
            "mentoring_sig_mentee",
            "mentoring_sig_mentee_date",
            "mentoring_sig_mentor",
            "mentoring_sig_mentor_date",
        ];
        foreach ($fields as $field) {
            if (!$row[$field]) {
                return FALSE;
            }
        }
        return TRUE;
    }

    public static function getMySurveys($username, $token, $server, $currentRecordId, $currentInstance) {
        $redcapData = Download::fields($token, $server, ["record_id", "mentoring_userid", "mentoring_last_update"]);
        $names = Download::names($token, $server);
        $userids = Download::userids($token, $server);
        $surveyLocations = [];
        foreach ($redcapData as $row) {
            if(($row['mentoring_userid'] == $username) && (($row['record_id'] != $currentRecordId) || ($row['redcap_repeat_instance'] != $currentInstance))) {
                $recordUserids = self::getUserids($userids[$row['record_id']]);
                if (in_array(strtolower($username), $recordUserids)) {
                    $menteeName = "yourself";
                } else {
                    $menteeName = "mentee ".$names[$row['record_id']];
                }
                $surveyLocations[$row['record_id'].":".$row['redcap_repeat_instance']] = "For ".$menteeName." (".$row['mentoring_last_update'].")";
            }
        }
        return $surveyLocations;
    }

    public static function makePriorNotesAndInstances($redcapData, $notesFields, $menteeRecordId, $instance) {
        $priorNotes = [];
        foreach ($notesFields as $field) {
            $priorNotes[$field] = "";
        }
        $instances = [];
        foreach ($redcapData as $row) {
            if (($row['record_id'] == $menteeRecordId) && ($row['redcap_repeat_instrument'] == "mentoring_agreement")) {
                if ($row['redcap_repeat_instance'] == $instance) {
                    foreach ($notesFields as $field) {
                        $priorNotes[$field] = $row[$field];
                    }
                }
                $instances[$row['redcap_repeat_instance']] = $row['mentoring_last_update'];
            }
        }
        return [$priorNotes, $instances];
    }

    public static function getBase64OfFile($recordId, $instance, $field, $pid) {
        if ($instance == 1) {
            $instanceClause = "instance IS NULL";
        } else {
            $instanceClause = "instance = '".db_real_escape_string($instance)."'";
        }
        $sql = "SELECT value FROM redcap_data
            WHERE project_id = '".db_real_escape_string($pid)."'
            AND record='".db_real_escape_string($recordId)."'
            AND field_name='".db_real_escape_string($field)."'
            AND $instanceClause";
        $q = db_query($sql);
        if ($error = db_error()) {
            return "";
        }
        if ($row = db_fetch_assoc($q)) {
            $fileId = $row['value'];
            $sql = "SELECT stored_name, mime_type from redcap_edocs_metadata WHERE doc_id = '" . db_real_escape_string($fileId) . "' LIMIT 1";
            $q = db_query($sql);
            if ($error = db_error()) {
                return "";
            }
            if ($row = db_fetch_assoc($q)) {
                $filename = EDOC_PATH . $row['stored_name'];
                $mimeType = $row['mime_type'];
                if (file_exists($filename)) {
                    $header = "data:$mimeType;base64,";
                    return $header . base64_encode(file_get_contents($filename));
                }
            }
        }
        return "";
    }

    public static function getUseridsForRecord($token, $server, $recordId, $recipientType) {
        $userids = [];
        if (in_array($recipientType, ["mentee", "all"])) {
            $menteeUserids = Download::userids($token, $server);
            if ($menteeUserids[$recordId]) {
                $userids = array_unique(array_merge($userids, self::getUserids($menteeUserids[$recordId])));
            }
        }
        if (in_array($recipientType, ["mentor", "mentors", "all"])) {
            $mentorUserids = Download::primaryMentorUserids($token, $server);
            if ($mentorUserids[$recordId]) {
                $userids = array_unique(array_merge($userids, $mentorUserids[$recordId]));
            }
        }
        return $userids;
    }

    public static function getEmailAddressesForRecord($userids) {
        $emails = [];
        foreach ($userids as $userid) {
            $email = REDCapManagement::getEmailFromUseridFromREDCap($userid);
            if ($email) {
                $emails[] = $email;
            }
        }
        return array_unique($emails);
    }

    public static function getSectionsToShow($username, $secHeaders, $redcapData, $menteeRecordId, $currInstance) {
        if (self::isValidHash($username)) {
            $sectionsToShow = [];
            foreach ($secHeaders as $secHeader) {
                $sectionsToShow[] = REDCapManagement::makeHTMLId($secHeader);
            }
            return $sectionsToShow;
        }

        $isFirstTime = self::isFirstEntryForUser($redcapData, $username, $menteeRecordId, $currInstance);
        $firstTimeSections = [
            "<h3>Mentee-Mentor 1:1 Meetings</h3>",
            "<h3>Lab Meetings</h3>",
            "<h3>Communication</h3>",
            "<h3>Mentoring Panel</h3>",
        ];
        $fillOutOnce = [
            "h3Mentoring_Panelh3" => "mentoring_panel_names",
        ];
        $sectionsToShow = [];
        if ($isFirstTime) {
            foreach ($firstTimeSections as $section) {
                $encodedSection = REDCapManagement::makeHTMLId($section);
                $sectionsToShow[] = $encodedSection;
            }
        } else {
            foreach ($secHeaders as $secHeader) {
                $encodedSection = REDCapManagement::makeHTMLId($secHeader);
                if (!in_array($encodedSection, $firstTimeSections) && !isset($fillOutOnce[$encodedSection])) {
                    $sectionsToShow[] = $encodedSection;
                }
            }
            foreach ($fillOutOnce as $section => $fieldToCheck) {
                $value = REDCapManagement::findField($redcapData, $menteeRecordId, $fieldToCheck, "mentoring_agreement", $currInstance);
                if (!$value && !in_array($section, $sectionsToShow)) {
                    $sectionsToShow[] = $section;
                }
            }
        }
        return $sectionsToShow;
    }

    public static function makeCommentJS($username, $menteeRecordId, $menteeInstance, $currentInstance, $priorNotes, $menteeName, $dateToRemind, $isMenteePage, $hasEvaluationComponent, $pid) {
        $html = "";
        $uidString = "";
        if (isset($_GET['uid'])) {
            $uidString = "&uid=$username";
        } else if (self::isValidHash($username)) {
            $uidString = "&hash=$username";
        }
        $verticalOffset = 50;
        $recordString = "&record=".$menteeRecordId;

        if (self::isValidHash($username)) {
            if (self::isMentee($menteeRecordId)) {
                $role = "mentee";
            } else {
                $role = "mentor";
            }
        } else {
            $role = $username;
        }

        if ($isMenteePage) {
            $functionToCall = "scheduleMentorEmail";
        } else {
            $functionToCall = "scheduleReminderEmail";
        }

        $mainScheduleEmailCall = "scheduleEmail('mentee', menteeRecord, subject, message, dateToSend, cb);";
        if ($hasEvaluationComponent && Application::isVanderbilt()) {
            # getSurveyLink has the fifth parameter in REDCap versions >= 10.4.0
            $menteeEvalLink = \REDCap::getSurveyLink($menteeRecordId, "mentoring_agreement_evaluations", NULL, self::getEvalInstance("mentee"), $pid);
            $mentorEvalLink = \REDCap::getSurveyLink($menteeRecordId, "mentoring_agreement_evaluations", NULL, self::getEvalInstance("mentor"), $pid);
            $menteeEvalMessage = self::makeEvaluationMessage($menteeEvalLink);
            $mentorEvalMessage = self::makeEvaluationMessage($mentorEvalLink);
            $evalSubject = "Short Feedback Survey for Mentee-Mentor Agreements";
            $evalSendTime = "now";
            $scheduleEmailHTML = "
            const mainCallback = function() {
                $mainScheduleEmailCall
            }
            const mentorCallback = function() {
                const evalSubject = '$evalSubject';
                const evalMessage = '$mentorEvalMessage';
                const evalSendTime = '$evalSendTime';
                scheduleEmail('mentor', menteeRecord, evalSubject, evalMessage, evalSendTime, mainCallback);            
            }
            
            const evalSubject = '$evalSubject';
            const evalMessage = '$menteeEvalMessage';
            const evalSendTime = '$evalSendTime';
            scheduleEmail('mentee', menteeRecord, evalSubject, evalMessage, evalSendTime, mentorCallback);
            ";
        } else {
            $scheduleEmailHTML = $mainScheduleEmailCall;
        }

        $agreementSaveURL = Application::link("mentor/_agreement_save.php").$uidString.$recordString;
        $priorNotesJSON = json_encode($priorNotes);

        if (self::isValidHash($username)) {
            $entryPageURL = Application::link("mentor/index_mentorview.php").$uidString."&menteeRecord=".$menteeRecordId;
            $menteeEntryPageURL = Application::link("mentor/index_menteeview.php").$uidString."&menteeRecord=".$menteeRecordId;
        } else {
            $entryPageURL = Application::link("mentor/index.php");
            $menteeEntryPageURL = $entryPageURL;
        }
        $completedPageURL = Application::link("mentor/index_complete.php").$uidString."&menteeRecord=".$menteeRecordId."&instance=2";
        $changeURL = Application::link("mentor/change.php").$uidString.$recordString;
        $html .="
<script>
    var currcomment = '0';
    var priorNotes = $priorNotesJSON;

    function toggleSectionTable(selector) {
        if ($(selector).is(':visible')) {
            $(selector).hide();
        } else {
            $(selector).show();
        }
        if (($('.chart').length > 0) && (typeof getPercentComplete === 'function')) {
            $('.chart').data('easyPieChart').update(getPercentComplete());
        }
    }
    
    function minutes_with_leading_zeros(dt) {
        return (dt.getMinutes() < 10 ? '0' : '') + dt.getMinutes();
    }
  
    showallcomments = function() {
        $('tr').each(function() {
            let id = $(this).attr('id')
            if (id && id.match(/-tr$/)) {
                showcomment(id, false)
            }
        })
    }

    dounbindenter=function(){
        $(document).keypress(function(e){
            if (e.which == 13) {
                return;
            }
        });
    }
    showcomment = function(servicerequest_id, insert_comment) {
        $('.fauxcomment').css('display', 'none');
        dounbindenter();
        var offset = $('#' + servicerequest_id + ' .tcomments').offset();
        let offsetleft = offset.left + 50;
        let offsettop = offset.top - $verticalOffset;
        let fieldName = servicerequest_id.replace(/-tr$/, '');
        let notesFieldName = fieldName + '_notes';
        let priorNote = priorNotes[notesFieldName] ? priorNotes[notesFieldName] : '';
        let commentcontent = '<div style=\"position: relative;height: 250px;\"><div class=\"closecomments\"><span style=\"float:left;color: #000000;font-weight: 700;font-size: 13px;margin-left: 6px;\">Notes/comments:</span><a style=\"float:right;\" href=\"javascript:$(\'.fauxcomment\').css(\'display\',\'none\');dounbindenter()\"><img src=\"".Application::link("mentor/images/x-circle.svg")."\"></a></div><div id=\"'+fieldName+'-lcomments\" class=\"listofcomments\" style=\"position: absolute;bottom: 0;height: 220px;display: inline-block;overflow: scroll;\">';

        for(var line of priorNote.split(/\\n/)) {
            if(line != ''){
                commentcontent += '<div class=\"acomment\">'+line+'</div>';
            }
        }

        if (insert_comment) {
            commentcontent += '</div></div><div class=\"insertcomment\"><input class=\"addcomment\" type=\"text\" placeholder=\"add comment...\" servicerequest=\"'+servicerequest_id+'\"><span><a href=\"javascript:addcomment(\'' + servicerequest_id + '\', $(\'[servicerequest='+servicerequest_id+']\'))\"><img src=\"".Application::link("mentor/images/at-sign.svg")."\" style=\"height: 18px;margin-left: 8px;\"></a></span></div>';
            //bind ENTER key to comment
            $(document).keypress(function(e){
                if (e.which == 13){
                    if (e.target && $(e.target).attr('servicerequest')) {
                        // binds at time of click, not at setup time
                        addcomment($(e.target).attr('servicerequest'), $(e.target));                    
                    }
                    if (!$(e.target).is('textarea.form-check-input')) {
                        return false;
                    }
                }
            });
        }
        $('.fauxcomment').css('top', offsettop + 'px').css('position', 'absolute').css('left', offsetleft + 'px').html(commentcontent);
        $('.fauxcomment').css('display', 'inline-block');

        currcomment = servicerequest_id;
        $('.acomment:odd').css('background-color', '#eceff5');
        var element = document.getElementById(fieldName+'-lcomments'); //scrolls to bottom
        if (element) {
            element.scrollTop = element.scrollHeight;
        }
        $('.addcomment').focus();
    }
    
    function pad2(n) { return n < 10 ? '0' + n : n }

    saveagreement=function(cb){
        let serialized = $('#tsurvey').serialize()
            .replace(/exampleRadiosh/g, '')
            .replace(/exampleChecksh/g, '')
            .replace(/exampleTextareash/g, '')
            .replace(/=on/g, '=1')
            .replace(/=off/g, '=0');
        $.ajax({
            url: '$agreementSaveURL',
            type : 'POST',
            //dataType : 'json', // data type
            data :  'record_id=$menteeRecordId&redcap_repeat_instance=$currentInstance&'+serialized,
            success : function(result) {
                console.log(result);
                $functionToCall(\"$menteeRecordId\", \"$menteeName\", \"$dateToRemind\", function(html) {
                    console.log(html);
                    $('.sweet-modal-overlay').remove();
                    if (cb) {
                        cb();
                    } else {
                        $.sweetModal({
                            content: 'We\'ve saved your agreement and notified your mentor. You can update your responses or return to Flight Tracker\'s Mentoring Agreement. Thank you!',
                            icon: $.sweetModal.ICON_SUCCESS
                        });
                    }
                });
            },
            error: function(xhr, resp, text) {
                console.log(xhr, resp, text);
            }
        });
    }

    addcomment = function(servicerequest_id, inputObj) {
        $('#' + servicerequest_id + ' .tcomments .timestamp').remove();
        let commentText = inputObj.val();
        if (commentText) {
            let d = new Date();
            let today = (d.getMonth() + 1)+'-'+d.getDate()+'-'+d.getFullYear();
            let latestcomment = commentText + '<span class=\"timestamp\">($role) '+today+' ' + d.getHours() + ':' + minutes_with_leading_zeros(d) + '</span>';
            $('<div class=\"acomment\">' + latestcomment + '</div>').appendTo('.listofcomments');
            let priorText = $('#' + servicerequest_id + ' .tcomments a').html();
            if (priorText.match(/add note/)) {
                $('#' + servicerequest_id + ' .tcomments a').html(latestcomment);
            } else {
                $('#' + servicerequest_id + ' .tcomments a').html(priorText + '<br>' + latestcomment);
            }
            inputObj.val('');
            $('.acomment:odd').css('background-color', '#eceff5');
            let fieldName = servicerequest_id.replace(/-tr$/, '');
            let notesFieldName = fieldName + '_notes';
            var element = document.getElementById(fieldName+'-lcomments'); //scrolls to bottom
            if (element) {
                element.scrollTop = element.scrollHeight;
            }

            if (priorNotes[notesFieldName]) {
                priorNotes[notesFieldName] += '\\n'+latestcomment;
            } else {
                priorNotes[notesFieldName] = latestcomment;
            }
            if ($menteeInstance > 0) {
                $.post('$changeURL', {
                    userid: '$username',
                    type: 'notes',
                    record: '$menteeRecordId',
                    instance: '$menteeInstance',
                    field_name: notesFieldName,
                    value: latestcomment
                }, function(html) {
                    console.log(html);
                });
            }
        }
    }
    
    function getLinkForCompletedPage() {
        return '$completedPageURL';
    }
    
    function getLinkForEntryPage() {
        return '$entryPageURL';
    }
    
    function getLinkForMenteeEntryPage() {
        return '$menteeEntryPageURL';
    }
    
    function scheduleMentorEmail(menteeRecord, menteeName, dateToRemind, cb) {
        const link = getLinkForEntryPage();
        const completedLink = getLinkForCompletedPage();
        const linktext = getLinkText(link);
        const subject = menteeName+'\'s Mentoring Agreement in Flight Tracker';
        const paragraph2 = '<p><a href=\"'+link+'\">'+linktext+'</a></p>';
        const completedParagraph = '<p>After the mentor completes the agreement, <a href=\"'+completedLink+'\">click here for the finalized agreement.</a></p>';
        ";
        if (Application::getProgramName() == "Flight Tracker Mentee-Mentor Agreements") {
            $html .= "  const paragraph1 = '<p>Your mentee ('+menteeName+') has completed an entry in a mentoring agreement and would like you to review the following Mentee-Mentor Agreement. Please schedule a time with your mentee to follow up and finalize this agreement.</p>';
                        const menteeEmailCB = function() {
                            const mentee_link = getLinkForMenteeEntryPage();
                            const mentee_linktext = 'Click here to revise.';
                            const menteeParagraph = '<p><a href=\"'+mentee_link+'\">Click here to revise.</a></p>';
                            const menteeMessage = '<p>You have completed an entry in a Mentee-Mentor Agreement. You can use the following link to revise your preferences.</p>' + menteeParagraph + completedParagraph;
                            scheduleEmail('mentee', menteeRecord, subject, menteeMessage, dateToRemind, cb);
                        }
                        const mentorMessage = paragraph1 + paragraph2 + completedParagraph;
                        scheduleEmail('mentor', menteeRecord, subject, mentorMessage, dateToRemind, menteeEmailCB);";
        } else {
            $html .= "  const paragraph1 = '<p>Your mentee ('+menteeName+') has completed an entry in a mentoring agreement and would like you to review the following Mentee-Mentor Agreement. Please schedule a time with your mentee (included on this email) to follow up and finalize this agreement.</p>';
                        const message = paragraph1 + paragraph2 + completedParagraph;
                        scheduleEmail('all', menteeRecord, subject, message, dateToRemind, cb);";
        }
        $html .= "
    }
    
    function getLinkText(link) {
        return (link.length > 40) ? 'Click Here to Begin' : link;
    }
    
    function scheduleReminderEmail(menteeRecord, menteeName, dateToSend, cb) {
        const link = getLinkForEntryPage();
        const linktext = getLinkText(link);
        const subject = 'Reminder: Your Mentoring Agreement';
        const dear = '<p>Dear '+menteeName+',</p>';
        const paragraph1 = '<p>A follow-up meeting with your mentor is requested. Please fill out a survey via the below link and then schedule a meeting to review your mentoring agreement with your mentor.</p>';
        const paragraph2 = '<p><a href=\"'+link+'\">'+linktext+'</p>';
        const message = dear + paragraph1 + paragraph2;
        $scheduleEmailHTML
    }
    
</script>";
        $html .= self::makeEmailJS($username);
        return $html;
    }

    public static function makeEmailJS($username) {
        $uidString = "";
        if (isset($_GET['uid'])) {
            $uidString = "&uid=$username";
        } else if (self::isValidHash($username)) {
            $uidString = "&hash=$username";
        }
        $recordString = "&record=";
        $emailSendURL = Application::link("mentor/schedule_email.php").$uidString.$recordString;
        $html = "<script>
    function scheduleEmail(recipientType, menteeRecord, subject, message, dateToSend, cb) {
        const datetimeToSend = (dateToSend === 'now') ? 'now' : dateToSend+' 09:00';
        const postdata = { menteeRecord: menteeRecord, recipients: recipientType, subject: subject, message: message, datetime: datetimeToSend };
        console.log(JSON.stringify(postdata));
        $.post('$emailSendURL'+menteeRecord,
            postdata,
            function(html) {
            if (cb) {
                cb(html);
            } else if (html.match(/error/i)) {
                $.sweetModal({
                    content: html,
                    icon: $.sweetModal.ICON_ERROR
                });
            } else {
                $.sweetModal({
                    content: html,
                    icon: $.sweetModal.ICON_SUCCESS
                });
            }
        });
    }
</script>";
        return $html;
    }

    public static function makeEvaluationMessage($evalLink) {
        # REMINDER: No single quotes because of the way the JS is formed
        return "Thank you for filling out a mentee-mentor agreement. Because this is a pilot, we are interested in your feedback. Can you fill out the following six-question survey?<br/><a href=\"$evalLink\">$evalLink</a><br/><br/>Thanks!<br/>The Flight Tracker Team";
    }

    # handle branching logic manually for now
    public static function getBranchingJS() {
        $html = "
    function doMMABranching() {
        const checkPrefix = 'exampleChecksh';
        const evalChecked = $('#'+checkPrefix+'mentoring_evaluation___99').is(':checked');
        if (evalChecked) {
            $('#mentoring_other_evaluation-tr').show();
        } else {
            $('#mentoring_other_evaluation-tr').hide();
        }
    }";
        return $html;
    }

    public static function getSectionHeadersWithMenteeQuestions($metadata) {
        $sectionHeaderCounts = [];
        $skipFieldTypes = ["notes", "file", "text"];
        if (!$metadata[0]['section_header']) {
            throw new \Exception("The first item of metadata should have a section header");
        }
        $lastSectionHeader = "";
        foreach ($metadata as $row) {
            if ($row['section_header']) {
                $sectionHeaderCounts[$row['section_header']] = 0;
                $lastSectionHeader = $row['section_header'];
            }
            if (!in_array($row['field_type'], $skipFieldTypes)) {
                $sectionHeaderCounts[$lastSectionHeader]++;
            }
        }
        $sectionHeaders = [];
        foreach ($sectionHeaderCounts as $header => $numMenteeItems) {
            if ($numMenteeItems > 0) {
                list($secHeader, $secDescript) = self::parseSectionHeader($header);
                $sectionHeaders[] = $secHeader;
            }
        }
        return $sectionHeaders;
    }

    public static function makeNotesHTML($field, $redcapData, $recordId, $instance, $notesFields) {
        $notesField = $field."_notes";
        $html = "";
        if (in_array($notesField, $notesFields)) {
            $html .= "<td class='tcomments'>\n";
            $notesData = REDCapManagement::findField($redcapData, $recordId, $notesField, "mentoring_agreement", $instance);
            if ($notesData == "") {
                $html .= "<a href='javascript:void(0)' onclick='showcomment($(this).closest(\"tr\").attr(\"id\"), true)'>add note</a>\n";
            } else {
                $notesData = preg_replace("/\n/", "<br>", $notesData);
                $html .= "<a href='javascript:void(0)' onclick='showcomment($(this).closest(\"tr\").attr(\"id\"), true)'><div class='tnote'>".$notesData."</div></a>\n";
            }
            $html .= "</td>\n";
        }
        return $html;
    }
    public static function makePrefillHTML($surveysAvailableToPrefill, $uidString = "") {
        $link = Application::link("mentor/importData.php").$uidString;
        $html = "";
        $html .= "<div style='margin: 0 auto; width: 100%;'>Pre-fill from Another Survey: ";
        $html .= "<select id='prefill' name='prefill' onchange='prefill();' style='margin-left: 1em;'>\n";
        $html .= "<option value=''>--- select ---</option>\n";
        foreach ($surveysAvailableToPrefill as $location => $description) {
            $html .= "<option value='$location'>$description</option>\n";
        }
        $html .= "</select></div>\n";
        $html .= "
<script>
    function clearAll() {
        $('input[type=radio]').each(function(idx, ob) {
            if (!$(ob).attr('name').match(/_menteeanswer/)) {
                $(ob).attr('checked', false);
            }
        });
        $('input[type=checkbox]').each(function(idx, ob) {
            if (!$(ob).attr('name').match(/_menteeanswer/)) {
                $(ob).attr('checked', false);
            }
        });
    }

    function prefill() {
        let sel = '#prefill';
        let location = $(sel).val();
        if (location) {
            let a = location.split(/:/);
            let recordId = a[0];
            let instance = a[1];
            $.post('$link', { record: recordId, instance: instance }, function(json) {
                let data = JSON.parse(json);
                clearAll();
                for (let field in data) {
                    let value = data[field];
                    if (field.match(/___/)) {
                        let b = field.split(/___/);
                        let checkboxField = b[0];
                        let checkboxValue = b[1];
                        let fieldSel = '#exampleChecksh'+checkboxField+'___'+checkboxValue;
                        if ((value === 0) || (value === '0') || (value === '')) {
                            $(fieldSel).attr('checked', false);
                        } else if ((value === 1) || (value === '1')) {
                            $(fieldSel).attr('checked', true);
                        } else {
                            $.sweetModal({
                                content: 'Invalid check value '+value,
                                icon: $.sweetModal.ICON_ERROR
                            });
                        }
                        updateData(fieldSel);
                    } else if (value !== '') {
                        let fieldSel = '#exampleRadiosh'+field+'___'+value;
                        $(fieldSel).attr('checked', true);
                        updateData(fieldSel);
                    }
                }
                $(sel).val('');
                $('input[type=checkbox].form-check-input').trigger('change');
                $('input[type=radio].form-check-input').trigger('change');
            });
        }
    }

</script>";
        return $html;
    }

    public static function getEmailFromREDCap($userid) {
        $sql = "select user_email from redcap_user_information WHERE username = '".db_real_escape_string($userid)."'";
        $q = db_query($sql);
        while ($row = db_fetch_assoc($q)) {
            if ($row['user_email']) {
                return $row['user_email'];
            }
        }
        return "";
    }

    public static function getSectionExpandMessage() {
        return "If desired, you may click on this header to toggle the section.";
    }

    public static function beautifyHeader($str) {
        $str = preg_replace("/Career and Professional Development/i", "Career Dev't", $str);
        $str = preg_replace("/Approach to Scholarly Products/i", "Scholarship", $str);
        $str = preg_replace("/Scientific Development/i", "Scientific Dev't", $str);
        $str = preg_replace("/Financial Support/i", "Financials", $str);
        $str = preg_replace("/Mentee-Mentor 1:1 Meetings/i", "Meetings", $str);
        $str = preg_replace("/Next/i", "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Next", $str);
        return $str;
    }

    public static function makeReminderJS($from) {
        $html = "";
        $html .= "<script>

    function makeList(names) {
        if (!names) {
            return '';
        }
        if (names.length == 1) {
            return names[0];
        } else if (names.length == 2) {
            return names[0]+' and '+names[1];
        } else if (names.length > 2) {
            let newNames = [];
            for (var i=0; i < names.length - 2; i++) {
                newNames.push(names[i]);
            }
            newNames.push(names[names.length - 2]+', and '+names[names.length - 1]);
            return newNames.join(', ');
        }
        return '';
    }

    sendreminder = function(recordId, instance, mentorNames, mentorUserids, menteeName) {
        let link = '".Application::link("mentor/index.php")."';

        let listOfMentorNames = makeList(mentorNames);
        if (mentorUserids) {
            $.sweetModal({
                title: 'Send reminder to ' + listOfMentorNames,
                content: '<div style=\"margin-bottom: 1em;font-weight: 500;color: #16a3b9;\">A customized link will be appended to the below email to your mentor(s). To edit the message, simply \"type\" your changes below:</div><div id=\"tnote\" class=\"tnoter\" contenteditable=\"true\">' + listOfMentorNames + ',<br><br> To expedite and facilitate our mentoring process, please fill out our mentoring agreement via the below link.<br><br><a href=\"'+link+'\">'+link+'</a><br><br>Thank you, <br>$from</div>',
                buttons: {
                    someOtherAction: {
                        label: 'send reminder',
                        classes: 'btnclear btn btn-info',
                        action: function() {
                            let note = $('#tnote').html();
                            if (note && mentorUserids) {
                                scheduleEmail('all', recordId, 'Mentee-Mentor Agreement with '+menteeName, note, 'now');
                            } else if (!note) {
                                $.sweetModal({
                                    content: 'Error! No note specified! No email sent!',
                                    icon: $.sweetModal.ICON_ERROR
                                });
                            }
                        }
                    }
                }
            });
        } else {
            $.sweetModal({
                content: 'No userid available for '+listOfMentorNames+'.',
                icon: $.sweetModal.ICON_ERROR
            });
        }
    }
    </script>";
        return $html;
    }

    public static function getEvalInstance($type) {
        if ($type == "mentee") {
            return 1;
        } else if ($type == "mentor") {
            return 2;
        } else {
            return "";
        }
    }

    public static function getREDCapUsers($pid) {
        $rights = \UserRights::getPrivileges($pid)[$pid];
        return array_keys($rights);
    }

    public static function transformCheckboxes($row, $metadata) {
        $indexedMetadata = REDCapManagement::indexMetadata($metadata);
        $newUploadRow = [];
        foreach ($row as $key => $value) {
            if ($indexedMetadata[$key]) {
                $metadataRow = $indexedMetadata[$key];
                if ($metadataRow['field_type'] == "checkbox") {
                    $key = $key."___".$value;
                    $value = "1";
                }
            }
            $newUploadRow[$key] = $value;
        }
        return $newUploadRow;
    }

    public static function handleTimestamps($row, $token, $server, $metadata) {
        $agreementFields = REDCapManagement::getFieldsFromMetadata($metadata, "mentoring_agreement");
        $instance = $row['redcap_repeat_instance'];
        $recordId = $row['record_id'];
        $redcapData = Download::fieldsForRecords($token, $server, $agreementFields, [$recordId]);
        if (REDCapManagement::findField($redcapData, $recordId, "mentoring_start", $instance)) {
            unset($row['mentoring_start']);
        }
        if (!REDCapManagement::findField($redcapData, $recordId, "mentoring_end", $instance)) {
            $row['mentoring_end'] = date("Y-m-d H:i:s");
        }
        return $row;
    }

    public static function hasDataInSection($metadata, $sectionHeader, $recordId, $instance, $instrument, $dataRow) {
        $sectionFields = REDCapManagement::getFieldsUnderSection($metadata, $sectionHeader);
        $indexedMetadata = REDCapManagement::indexMetadata($metadata);
        $choices = REDCapManagement::getChoices($metadata);
        foreach ($sectionFields as $field) {
            if ($indexedMetadata[$field]['field_type'] == "checkbox") {
                foreach ($choices[$field] as $index => $value) {
                    $value = REDCapManagement::findField([$dataRow], $recordId, $field."___".$index, $instrument, $instance);
                    if ($value) {
                        $hasAnswers = TRUE;
                        break; // choices
                    }
                }
            } else {
                $value = REDCapManagement::findField([$dataRow], $recordId, $field, $instrument, $instance);
                if ($value) {
                    return TRUE;
                }
            }
        }
        return FALSE;
    }

    public static function getTotalCount($ary) {
        $n = 0;
        foreach (array_values($ary) as $valueAry) {
            $n += count($valueAry);
        }
        return $n;
    }

    public static function makeAnswerTableRow($fieldLabel, $answerLabel, $positives, $n) {
        $html = "";
        $html .= "<tr>";
        $html .= "<th>$fieldLabel</th>";
        $html .= "<td>$answerLabel</td>";
        $html .= "<td>$positives</td>";
        $html .= "<td>$n</td>";
        if ($n > 0) {
            $frac = $positives / $n;
            $percentage = REDCapManagement::pretty($frac * 100, 1)."%";
            $html .= "<td>$percentage</td>";
        } else {
            $html .= "<td></td>";
        }
        $html .= "</tr>";
        return $html;
    }

    public static function makeDropdownTableRow($pid, $event_id, $title, $menteeOptions) {
        if (!empty($menteeOptions)) {
            $html = "";
            $html .= "<tr>";
            $html .= "<th>$title</th>";
            $html .= "<td colspan='2'>";
            $html .= "Select Mentees' Agreements<br>";

            $link = Links::makeMenteeAgreementUrl($pid, 1, $event_id);
            $link = preg_replace("/&id=\d+/", "", $link);
            $html .= "<select onchange='if ($(this).val() !== \"\") { location.href = \"$link&id=\"+$(this).val(); }'>";
            $html .= "<option value='' selected>---SELECT---</option>";
            foreach ($menteeOptions as $recordId => $name) {
                $html .= "<option value='$recordId'>$name</option>";
            }
            $html .= "</select>";
            $html .= "</td>";
            $html .= "</tr>";
            return $html;
        } else {
            return "";
        }
    }

    public static function makeGeneralTableRow($title, $values, $units = "", $makeAverage = FALSE, $names = [], $mentors = []) {
        if ($units && !preg_match("/^\s/", $units)) {
            $unitsWithSpace = " ".$units;
        } else {
            $unitsWithSpace = $units;
        }
        $html = "";
        $html .= "<tr>";
        $html .= "<th>$title</th>";
        if (is_numeric($values)) {
            $html .= "<td colspan='2' class='centered'>".REDCapManagement::pretty($values, 0).$unitsWithSpace."</td>";
        } else if (is_array($values)) {
            if (is_array($values["mentees"]) && is_array($values["mentors"])) {
                $valueNames = [];
                foreach ($values as $type => $records) {
                    $valueNames[$type] = [];
                    foreach ($records as $recordId) {
                        if ($type == "mentees") {
                            $valueNames[$type][] = $names[$recordId] ?? "";
                        } else if ($type == "mentors") {
                            $mentorNamesAry = $mentors[$recordId] ?? [];
                            $valueNames[$type][] = implode(", ", $mentorNamesAry);
                        } else {
                            throw new \Exception("Invalid type $type");
                        }
                    }
                }
                if (!empty($values['mentees'])) {
                    $menteeNameId = "mentees_".REDCapManagement::makeHTMLId($title);
                    if (!empty($names)) {
                        $menteeExplodeStr = "<br><a href='javascript:;' onclick='$(\"#$menteeNameId\").show(); $(this).hide();'>Show Names</a><span class='smaller' id='$menteeNameId' style='display: none;'>".implode("<br>", $valueNames["mentees"])."</span>";
                    } else {
                        $valuesWithUnits = [];
                        foreach ($values["mentees"] as $val) {
                            $valuesWithUnits[] = REDCapManagement::pretty($val, 1)." ".$units;
                        }
                        $menteeExplodeStr = "<br><a href='javascript:;' onclick='$(\"#$menteeNameId\").show(); $(this).hide();'>Show $units</a><span class='smaller' id='$menteeNameId' style='display: none;'>".implode("<br>", $valuesWithUnits)."</span>";
                    }
                } else {
                    $menteeExplodeStr = "";
                }
                if (!empty($values['mentors'])) {
                    $mentorNameId = "mentors_".REDCapManagement::makeHTMLId($title);
                    if (!empty($mentors)) {
                        $mentorExplodeStr = "<br><a href='javascript:;' onclick='$(\"#$mentorNameId\").show(); $(this).hide();'>Show Names</a><span class='smaller' id='$mentorNameId' style='display: none;'>".implode("<br>", $valueNames["mentors"])."</span>";
                    } else {
                        $valuesWithUnits = [];
                        foreach ($values["mentors"] as $val) {
                            $valuesWithUnits[] = REDCapManagement::pretty($val, 1)." ".$units;
                        }
                        $mentorExplodeStr = "<br><a href='javascript:;' onclick='$(\"#$mentorNameId\").show(); $(this).hide();'>Show $units</a><span class='smaller' id='$mentorNameId' style='display: none;'>".implode("<br>", $valuesWithUnits)."</span>";
                    }
                } else {
                    $mentorExplodeStr = "";
                }
                if ($makeAverage) {
                    if (count($values["mentees"]) > 0) {
                        $menteeVal = REDCapManagement::pretty(array_sum($values["mentees"]) / count($values["mentees"]), 1);
                    } else {
                        $menteeVal = "NA";
                    }
                    if (count($values["mentors"]) > 0) {
                        $mentorVal = REDCapManagement::pretty(array_sum($values["mentors"]) / count($values["mentors"]), 1);
                    } else {
                        $mentorVal = "NA";
                    }
                } else {
                    $menteeVal = REDCapManagement::pretty(count($values["mentees"]), 0);
                    $mentorVal = REDCapManagement::pretty(count($values["mentors"]), 0);
                }
                $html .= "<td>".$menteeVal.$unitsWithSpace.$menteeExplodeStr."</td>";
                $html .= "<td>".$mentorVal.$unitsWithSpace.$mentorExplodeStr."</td>";
            } else {
                $html .= "<td>".REDCapManagement::pretty($values["mentees"], 0).$unitsWithSpace."</td>";
                $html .= "<td>".REDCapManagement::pretty($values["mentors"], 0).$unitsWithSpace."</td>";
            }
        } else {
            $html .= "<td colspan='2' class='centered'>".$values.$unitsWithSpace."</td>";
        }
        $html .= "</tr>";
        return $html;
    }

    public static function getElementCount($elements) {
        $useridList = [];
        foreach (array_values($elements) as $relatedUserids) {
            if ($relatedUserids === "") {
                $relatedUserids = [];
            }
            if (is_string($relatedUserids)) {
                $relatedUserids = [$relatedUserids];
            }
            foreach ($relatedUserids as $relatedUserid) {
                if (($relatedUserid !== "") && !in_array($relatedUserid, $useridList)) {
                    $useridList[] = $relatedUserid;
                }
            }
        }
        return count($useridList);
    }
}