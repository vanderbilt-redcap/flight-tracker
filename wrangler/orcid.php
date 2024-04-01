<?php

use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\NameMatcher;
use Vanderbilt\CareerDevLibrary\ORCID;
use Vanderbilt\CareerDevLibrary\Links;
use Vanderbilt\CareerDevLibrary\Sanitizer;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Upload;

require_once(__DIR__."/../small_base.php");
require_once(__DIR__."/../classes/Autoload.php");

$allRecords = Download::recordIdsByPid($pid);
$records = $allRecords;
if (isset($_GET['record']) && ($_GET['record'] !== "all")) {
    $recordId = Sanitizer::getSanitizedRecord($_GET['record'], $allRecords);
    if ($recordId) {
        $records = [$recordId];
    }
}
$action = $_POST['action'] ?? "";
if ($action == "downloadORCIDs") {
    $recordId = Sanitizer::getSanitizedRecord($_POST['record'] ?? "", $allRecords);
    $data = [];
    if ($recordId) {
        $firstName = Sanitizer::sanitize($_POST['firstName'] ?? "");
        $middleName = Sanitizer::sanitize($_POST['middleName'] ?? "");
        $lastName = Sanitizer::sanitize($_POST['lastName'] ?? "");
        $institutionList = Sanitizer::sanitize($_POST['institutionList'] ?? "");
        list($orcid, $mssg) = ORCID::downloadORCID($recordId, $firstName, $middleName, $lastName, $institutionList, $pid);
        if ($orcid) {
            $data['orcids'] = [$orcid];
            $data['message'] = "";
        } else {
            $data['orcids'] = [];
            $data['message'] = $mssg;
        }
    } else {
        $data['error'] = "Invalid record.";
    }
    echo json_encode($data);
    exit;
} else if ($action == "changeBlocking") {
    $recordId = Sanitizer::getSanitizedRecord($_POST['record'] ?? "", $allRecords);
    $newValue = "UNSET";
    if ($_POST['value'] == "on") {
        $newValue = "1";
    } else if ($_POST['value'] == "off") {
        $newValue = "0";
    }
    $data = [];
    if ($recordId && ($newValue !== "UNSET")) {
        $uploadRow = [
            "record_id" => $recordId,
            "redcap_repeat_instrument" => "",
            "redcap_repeat_instance" => "",
            "identifier_block_orcid" => $newValue,
        ];
        $data['feedback'] = Upload::oneRow($uploadRow, $token, $server);
    } else {
        $data['error'] = "No action.";
    }
    echo json_encode($data);
    exit;
} else if (in_array($action, ["addORCID", "removeORCID", "excludeORCID"])) {
    $data = [];
    $recordId = Sanitizer::getSanitizedRecord($_POST['record'] ?? "", $allRecords);
    $orcid = Sanitizer::sanitize($_POST['orcid'] ?? "");
    if ($recordId && $orcid) {
        $priorORCIDList = Download::oneFieldForRecordByPid($pid, "identifier_orcid", $recordId);
        $orcids = $priorORCIDList ? preg_split("/\s*[,;]\s*/", $priorORCIDList) : [];
        $priorExcludeList = Download::oneFieldForRecordByPid($pid, "exclude_orcid", $recordId);
        $excludeORCIDs = $priorExcludeList ? preg_split("/\s*[,;]\s*/", $priorExcludeList) : [];
        if ($action == "addORCID") {
            if (!in_array($orcid, $orcids)) {
                $orcids[] = $orcid;
            }
            if (in_array($orcid, $excludeORCIDs)) {
                $pos = array_search($orcid, $excludeORCIDs);
                array_splice($excludeORCIDs, $pos, 1);
            }
        } else if (($action == "excludeORCID") && !in_array($orcid, $excludeORCIDs)) {
            $excludeORCIDs[] = $orcid;
        } else if (($action == "removeORCID") && in_array($orcid, $orcids)) {
            $pos = array_search($orcid, $orcids);
            array_splice($orcids, $pos, 1);
            $excludeORCIDs[] = $orcid;
        }
        $newORCIDList = implode(", ", $orcids);
        $newExcludeList = implode(", ", $excludeORCIDs);
        $uploadRow = [
            "record_id" => $recordId,
            "redcap_repeat_instrument" => "",
            "redcap_repeat_instance" => "",
        ];
        if ($priorORCIDList != $newORCIDList) {
            $uploadRow["identifier_orcid"] = $newORCIDList;
        }
        if ($priorExcludeList != $newExcludeList) {
            $uploadRow["exclude_orcid"] = $newExcludeList;
        }
        if (count($uploadRow) > 3) {
            $data['feedback'] = Upload::oneRow($uploadRow, $token, $server);
        } else {
            $data['feedback'] = "No changes made";
        }
        $data['recordExcludes'] = $newExcludeList;

        $firstName = Download::oneFieldForRecordByPid($pid, "identifier_first_name", $recordId);
        $middleName = Download::oneFieldForRecordByPid($pid, "identifier_middle", $recordId);
        $lastName = Download::oneFieldForRecordByPid($pid, "identifier_last_name", $recordId);
        $institutionList = Download::oneFieldForRecordByPid($pid, "identifier_institution", $recordId);
        list($orcid, $mssg) = ORCID::downloadORCID($recordId, $firstName, $middleName, $lastName, $institutionList, $pid);
        if ($orcid) {
            $data['orcids'] = [$orcid];
            $data['message'] = "";
        } else {
            $data['orcids'] = [];
            $data['message'] = $mssg;
        }
        $data['redcapORCIDs'] = $newORCIDList;
    } else {
        $data['error'] = "Invalid record.";
    }
    echo json_encode($data);
    exit;
}

require_once(__DIR__."/../charts/baseWeb.php");

$thisUrl = Application::link("this");
$firstNames = Download::firstnames($token, $server);
$middleNames = Download::middlenames($token, $server);
$lastNames = Download::lastnames($token, $server);
$institutionLists = Download::institutions($token, $server);
$orcidTexts = Download::ORCIDs($token, $server);
$blockORCIDs = Download::oneField($token, $server, "identifier_block_orcid");
$excludeORCIDs = Download::oneField($token, $server, "exclude_orcid");
$redcapORCIDs = [];
foreach ($orcidTexts as $recordId => $orcidText) {
    $redcapORCIDs[$recordId] = $orcidText ? preg_split("/\s*[,;]\s*/", $orcidText) : [];
}

$namesWithLinks = [];
foreach ($allRecords as $recordId) {
    $fn = $firstNames[$recordId] ?? "";
    $mn = $middleNames[$recordId] ?? "";
    $ln = $lastNames[$recordId] ?? "";

    $name = NameMatcher::formatName($fn, $mn, $ln);
    $nameWithLink = Links::makeFormLink($pid, $recordId, $eventId, $name, "identifiers");
    $namesWithLinks[$recordId] = $nameWithLink;
}

echo "<script>
function downloadORCIDInfo(url, loadingSel, i) {
    if (i < records.length) {
        const recordId = records[i];
        const firstName = firstNames[recordId] ?? '';
        const lastName = lastNames[recordId] ?? '';
    	$(loadingSel).html(getSmallLoadingMessage('Fetching ORCIDs for Record '+recordId+': '+firstName+' '+lastName));
        const middleName = middleNames[recordId] ?? '';
        const institutionList = institutionLists[recordId] ?? '';

        const postdata = {
            redcap_csrf_token: getCSRFToken(),
            record: recordId,
            firstName: firstName,
            middleName: middleName,
            lastName: lastName,
            institutionList: institutionList,
            action: 'downloadORCIDs'
        };
        console.log(JSON.stringify(postdata));
        $.post(url, postdata, (json) => {
            console.log(json);
            try {
                const data = JSON.parse(json);
                if (data.error) {
                    $(loadingSel).html('');
                    displayORCIDError(data.error);
                } else {
                    const rowClass = (i % 2 === 0) ? 'even' : 'odd';
                    $('table#main tbody').append('<tr class=\"'+rowClass+'\">'+makeORCIDRow(data, recordId, redcapORCIDs[recordId])+'</tr>');
                    downloadORCIDInfo(url, loadingSel, i+1);
                }
            } catch (e) {
                $(loadingSel).html('');
                displayORCIDError(e);
            }
        });
    } else {
        $(loadingSel).html('');
        console.log('Done!');
    }
}

function makeORCIDRow(data, recordId, redcapIDs) {
    const firstName = firstNames[recordId] ?? '';
    const lastName = lastNames[recordId] ?? '';
    const middleName = middleNames[recordId] ?? '';
    const nameWithLink = recordId+': '+(namesWithLinks[recordId] ?? firstName+' '+middleName+' '+lastName);
    const institutionList = institutionLists[recordId] ?? '';
    const institutionAry = institutionList ? institutionList.split(/\s*[,;]\s*/) : [];
    const institutionHTML = (institutionAry.length > 0) ? institutionAry.join('<br/>') : '[none listed]';
    const orcids = data.orcids ?? [];
    const message = data.message ?? '';
    const fullWebInfo = (orcids.length > 0) ? displayORCIDLinks(orcids, redcapIDs, 'add', recordId) : parseORCIDMessage(message, redcapIDs, recordId);
    if (fullWebInfo.match(/Could not contact/)) {
        console.error(fullWebInfo);
    }
    const webInfo = fullWebInfo.match(/Could not contact/) ? 'Could not contact ORCID. Please try again.' : fullWebInfo;
    const blockButton = blocking[recordId] ? makeTurnOffCell(recordId) : makeTurnOnCell(recordId);
    return '<th>'+nameWithLink+'</th><td>'+institutionHTML+'</td><td>'+blockButton+'</td><td>'+displayORCIDLinks(redcapIDs, redcapIDs, 'remove', recordId)+'<br/>'+addCustomORCID(recordId)+'</td><td>'+webInfo+'</td>';
}

function makeTurnOnCell(recordId) {
    return 'No<br/><button class=\"smallest\"  onclick=\"changeORCIDBlocking(\'$thisUrl\', \''+recordId+'\', \'on\', this); return false;\">block</button>';
}

function makeTurnOffCell(recordId) {
    return 'Blocked<br/><button class=\"smallest\"  onclick=\"changeORCIDBlocking(\'$thisUrl\', \''+recordId+'\', \'off\', this); return false;\">unblock</button>';
}

function displayORCIDError(mssg) {
    console.error(mssg);
    $.sweetModal({
        content: mssg,
        icon: $.sweetModal.ICON_ERROR
    });
}

function getORCIDLinks(orcids) {
    if (orcids.length === 0) {
        return {};
    }
    const links = {};
    for (let i=0; i < orcids.length; i++) {
        const orcid = orcids[i];
        const url = 'https://orcid.org/'+orcid;
        links[orcid] = '<a href=\"'+url+'\" target=\"_NEW\">'+orcid+'</a>';
    }
    return links;
}

function parseORCIDMessage(message, redcapORCIDIDs, recordId) {
    const moreThanOneRegex = /^".ORCID::MORE_THAN_ONE."\\".ORCID::ORCID_DELIM."\\d+"."\\".ORCID::ORCID_DELIM."/;
    const noMatchesRegex = /^".ORCID::NO_MATCHES."\\".ORCID::ORCID_DELIM."/;
    if (message.match(moreThanOneRegex)) {
        const json = message.replace(moreThanOneRegex, '');
        try {
            const orcids = JSON.parse(json);
            return displayORCIDLinks(orcids, redcapORCIDIDs, 'add', recordId);
        } catch(e) {
            console.error(e);
            return message;
        }
    } else if (message.match(noMatchesRegex)) {
        return 'No matches!';
    }
    return message;
}

function addCustomORCID(recordId) {
    return '<input type=\"text\" id=\"custom_orcid_'+recordId+'\" placeholder=\"Add Custom ORCID\" value=\"\" style=\"width: 180px; margin-top: 12px;\" /> <button onclick=\"addORCIDToRecord(\'$thisUrl\', \''+recordId+'\', $(\'#custom_orcid_'+recordId+'\').val(), this); return false;\" class=\"smallest\">add</button>';
}

function excludeORCIDInRecord(url, recordId, orcid, buttonOb) {
    changeORCIDList('excludeORCID', url, recordId, orcid, buttonOb);
}

function addORCIDToRecord(url, recordId, orcid, buttonOb) {
    changeORCIDList('addORCID', url, recordId, orcid, buttonOb);
}
    
function changeORCIDList(action, url, recordId, orcid, buttonOb) {    
    if (orcid === '') {
        displayORCIDError('No ORCID specified!');
    } else if (orcid.match(/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/)) {
        const myRow = $(buttonOb).closest('tr');
        const postdata = {
            redcap_csrf_token: getCSRFToken(),
            record: recordId,
            orcid: orcid,
            action: action
        };
        let cb = (data) => { excludes[recordId] = data.recordExcludes ?? ''; const redcapIDs = data.redcapORCIDs ? data.redcapORCIDs.split(/\s*[,;]\s*/) : []; $(myRow).html(makeORCIDRow(data, recordId, redcapIDs)); }
        myRow.html('<td colspan=\"5\">'+getSmallLoadingMessage(\"Reloading\")+'</td>')
        runORCIDPOST(url, postdata, cb);
    } else {
        displayORCIDError('Improper <a href=\"https://support.orcid.org/hc/en-us/articles/360006897674-Structure-of-the-ORCID-Identifier\" target=\"_new\">ORCID format</a>!')
    }
}

function removeORCIDFromRecord(url, recordId, orcid, buttonOb) {
    const myRow = $(buttonOb).closest('tr');
    const postdata = {
        redcap_csrf_token: getCSRFToken(),
        record: recordId,
        orcid: orcid,
        action: 'removeORCID'
    };
    let cb = (data) => { excludes[recordId] = data.recordExcludes ?? ''; const redcapIDs = data.redcapORCIDs ? data.redcapORCIDs.split(/\s*[,;]\s*/) : []; $(myRow).html(makeORCIDRow(data, recordId, redcapIDs)); }
    myRow.html('<td colspan=\"5\">'+getSmallLoadingMessage(\"Reloading\")+'</td>')
    runORCIDPOST(url, postdata, cb);
}

function runORCIDPOST(url, postdata, cb) {
    console.log(JSON.stringify(postdata));
    $.post(url, postdata, (json) => {
        console.log(json);
        try {
            const data = JSON.parse(json);
            if (data.error) {
                displayORCIDError(data.error);
            } else if (cb) {
                cb(data);
            }
        } catch (e) {
            displayORCIDError(e);
        }
    });
}

function changeORCIDBlocking(url, recordId, action, buttonOb) {
    const myCell = $(buttonOb).closest('td');
    const postdata = {
        redcap_csrf_token: getCSRFToken(),
        record: recordId,
        value: action,
        action: 'changeBlocking'
    };
    let cb = () => { displayORCIDError('Improper action! This should never happen.'); }
    if (action === 'on') {
        cb = () => {
            // now turned on --> button to turn off
            $(myCell).html(makeTurnOffCell(recordId));
        }
    } else if (action === 'off') {
        cb = () => {
            // now turned off --> button to turn on
            $(myCell).html(makeTurnOnCell(recordId));
        }
    }
    $(myCell).html(getSmallLoadingMessage(\"Saving\"))
    runORCIDPOST(url, postdata, cb);
}

function displayORCIDLinks(orcids, redcapORCIDIDs, action, recordId) {
    const links = getORCIDLinks(orcids);
    const html = [];
    const recordExcludes = excludes[recordId] ? excludes[recordId].split(/\s*[,;]\s*/) : [];
    for (let i=0; i < orcids.length; i++) {
        const orcid = orcids[i];
        const link = links[orcid];
        if (recordExcludes.indexOf(orcid) >= 0) {
            html.push('<span class=\"strikethrough\">'+link+'</span> <button class=\"smallest\" onclick=\"addORCIDToRecord(\'$thisUrl\', \''+recordId+'\', \''+orcid+'\', this); return false;\">add</button>');
        } else if ((redcapORCIDIDs.indexOf(orcid) < 0) && (action === 'add')) {
            html.push(link+' <button class=\"smallest\" onclick=\"addORCIDToRecord(\'$thisUrl\', \''+recordId+'\', \''+orcid+'\', this); return false;\">add</button> <button onclick=\"excludeORCIDInRecord(\'$thisUrl\', \''+recordId+'\', \''+orcid+'\', this); return false;\" class=\"smallest\">exclude</button>');
        } else if (action === 'remove') {
            html.push(link+' <button class=\"smallest\" onclick=\"removeORCIDFromRecord(\'$thisUrl\', \''+recordId+'\', \''+orcid+'\', this); return false;\">remove</button>');
        } else if (action === 'add') {
            html.push(link+ ' <span class=\"smallest darkgreentext\">[in REDCap]</span>');
        } else {
            html.push(link);
        }
    }
    if (html.length === 0) {
        return 'None';
    } else {
        return html.join('<br/>');
    }
}

const records = ".json_encode($records).";
const firstNames = ".json_encode($firstNames).";
const middleNames = ".json_encode($middleNames).";
const lastNames = ".json_encode($lastNames).";
const namesWithLinks = ".json_encode($namesWithLinks).";
const redcapORCIDs = ".json_encode($redcapORCIDs).";
const institutionLists = ".json_encode($institutionLists).";
const blocking = ".json_encode($blockORCIDs).";
const excludes = ".json_encode($excludeORCIDs).";

$(document).ready(() => {
    downloadORCIDInfo('$thisUrl', '.loading', 0);
});

</script>";

echo "<h1>ORCID Wrangler</h1>";
echo "<p class='centered max-width'>ORCIDs provide a unique identifier to search for publications. Flight Tracker attempts weekly to search for new ORCIDs. Publications matched with ORCIDs are automatically accepted. Thus, getting a correct matched ORCID is important because an incorrect ORCID can bring in wrong publication data.</p>";
echo "<h4 class='nomargin'>Actions</h4>";
echo "<ul class='max-width-600 nomargin'>";
echo "<li><strong>Block/Unblock:</strong> For each record, you can turn off (i.e., block) all searching of ORCID for that record.</li>";
echo "<li><strong>Remove:</strong> You can remove incorrect ORCIDs. These will automatically be placed on the record's 'Exclude List' to skip over henceforth.</li>";
echo "<li><strong>Add:</strong> You can add ORCIDs that are on the ORCID website, via a full or partial name match. Names that are struck through (<span class='strikethrough'>like this</span>) are on the exclude list.</li>";
echo "</ul>";
echo "<p class='centered max-width'> You are encouraged to click the links to see whether an ORCID is correct.</p>";
echo "<p class='centered max-width'><label for='record'>Skip to one record: </label><select id='record' onchange='const record = $(\"#record :selected\").val(); if (record !== \"\") { window.location.href = \"$thisUrl&record=\" + encodeURIComponent(record); }'>";
$selected = (($_GET['record'] == "all") || !isset($_GET['record'])) ? "selected" : "";
echo "<option value='all' $selected>---ALL---</option>";
natcasesort($lastNames);
foreach ($lastNames as $recordId => $lastName) {
    $firstName = $firstNames[$recordId] ?? "";
    $middleName = $middleNames[$recordId] ?? "";
    $name = NameMatcher::formatName($firstName, $middleName, $lastName);
    $selected = ($_GET['record'] == $recordId) ? "selected" : "";
    echo "<option value='$recordId' $selected>$recordId: $name</option>";
}
echo "</select></p>";
echo "<div class='loading centered'></div>";
echo "<table id='main' class='bordered centered max-width-1000'>";
echo "<thead><tr class='stickyGrey'><th>Name</th><th title='Institutions that you’ve added. Information for matching to right ORCID.'>Outside Institutions in REDCap</th><th style='width: 80px;' title='Block or unblock future searches for ORCID ids.'>Block All Searching?</th><th style='min-width: 275px;' title='ORCIDs that you’re currently using. You can remove these by clicking the button. Link goes to individual’s ORCID profile, which lists institutions.'>ORCIDs in REDCap</th><th style='min-width: 300px;' title='ORCIDs currently matched to this name on the ORCID website. This cell often has false positives. You can add to REDCap by clicking the button.'>ORCIDs on Website</th></tr></thead>";
echo "<tbody>";
echo "</tbody></table>";
echo "<div class='loading centered'></div>";