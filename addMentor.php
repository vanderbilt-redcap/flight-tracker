<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\REDCapLookup;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/classes/Autoload.php");

if ($_GET['download'] && ($_GET['download'] == "csv")) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="mentors.csv"');
    echo "Scholar First Name,Scholar Last Name,Mentor First Name,Mentor Last Name\n";
} else if ($_GET['upload'] && ($_GET['upload'] == "form")) {
    require_once(dirname(__FILE__) . "/charts/baseWeb.php");
    if (hasSuggestions($_POST)) {
        $html = remakeUploadTable($_POST, $token, $server);
    } else {
        $html = uploadBulkForm($_POST, $token, $server);
        $html .= makeMainForm($token, $server);
    }
    echo $html;
} else if ($_GET['upload'] && ($_GET['upload'] == "csv") && isset($_FILES['csv_file'])) {
    require_once(dirname(__FILE__) . "/charts/baseWeb.php");
    $tmpFilename = $_FILES['csv_file']['tmp_name'] ?? "";
    if ($tmpFilename) {
        $html = makeUploadTable($tmpFilename, $token, $server);
        echo $html;
    } else {
        echo "Invalid file.";
    }
} else if ($_POST['mentorName']) {
    require_once(dirname(__FILE__) . "/small_base.php");
    $mentorName = Sanitizer::sanitize($_POST['mentorName'] ?? "");
    list($mentorFirst, $mentorLast) = NameMatcher::splitName($mentorName);
    $lookup = new REDCapLookup($mentorFirst, $mentorLast);
    $uids = $lookup->getUidsAndNames();
    if (count($uids) == 0) {
        $lookup = new REDCapLookup("", $mentorLast);
        $uids = $lookup->getUidsAndNames();
    }
    echo json_encode($uids);
} else if ($_POST['newMentorName']) {
    require_once(dirname(__FILE__) . "/small_base.php");
    $newMentorName = REDCapManagement::sanitize($_POST['newMentorName']);
    $newMentorUid = REDCapManagement::sanitize($_POST['newMentorUid']);
    $records = Download::recordIds($token, $server);
    $recordId = REDCapManagement::getSanitizedRecord($_POST['recordId'], $records);
    if ($recordId && $newMentorName && $newMentorUid) {
        $uploadRow = [
            "record_id" => $recordId,
            "imported_mentor" => $newMentorName,
            "imported_mentor_userid" => $newMentorUid,
            "summary_mentor" => $newMentorName,
            "summary_mentor_userid" => $newMentorUid,
        ];
        $feedback = Upload::oneRow($uploadRow, $token, $server);
    } else {
        $feedback = ["error" => "Invalid record"];
    }
    echo json_encode($feedback);
} else if ($_POST['scholarName']) {
    require_once(dirname(__FILE__) . "/small_base.php");
    $name = REDCapManagement::sanitize($_POST['scholarName']);
    $nodes = preg_split("/\s+/", $name);
    if (!isset($_POST['scholarName']) || ($name === "")) {
        echo "[]";
    } else if (count($nodes) == 1) {
        $firstNames = Download::firstnames($token, $server);
        $lastNames = Download::lastnames($token, $server);
        $matches = [];
        foreach ($lastNames as $recordId => $potentialLastName) {
            if (NameMatcher::matchByLastName($potentialLastName, $name)) {
                $matches[] = $recordId;
            }
        }
        foreach ($firstNames as $recordId => $potentialFirstName) {
            if (!isset($matches[$recordId]) && NameMatcher::matchByFirstName($potentialFirstName, $name)) {
                $matches[] = $recordId;
            }
        }
        echo json_encode($matches);
    } else {
        list($firstName, $lastName) = NameMatcher::splitName($name);
        $firstNames = Download::firstnames($token, $server);
        $lastNames = Download::lastnames($token, $server);
        $matches = [];
        foreach ($lastNames as $recordId => $potentialLastName) {
            $potentialFirstName = $firstNames[$recordId];
            if (NameMatcher::matchName($firstName, $lastName, $potentialFirstName, $potentialLastName)) {
                $matches[] = $recordId;
            }
        }
        echo json_encode($matches);
    }
} else {
    require_once(dirname(__FILE__)."/charts/baseWeb.php");
    $html = makeMainForm($token, $server);
    echo $html;
}

function hasSuggestions($post) {
    foreach ($post as $key => $value) {
        if (preg_match("/^newmentorname___/", $key)) {
            $recordKey = preg_replace("/^newmentorname___/", "", $key);
            $originalName = $post["originalmentorname___".$recordKey];
            if ($originalName != $value) {
                return TRUE;
            }
        }
    }
    return FALSE;
}

function parsePostForData($post) {
    $mentorNames = [];
    $mentorUids = [];
    $newMentorNames = [];
    $mentorNamesAsArray = [];
    $mentorUidsAsArray = [];
    $newMentorNamesAsArray = [];
    foreach ($post as $key => $value) {
        if (preg_match("/^mentor___[\d:]+$/", $key)) {
            $recordKey = preg_replace("/^mentor___/", "", $key);
            addMentorValue($mentorUids, $mentorUidsAsArray, $recordKey, $value);
        } else if (preg_match("/^mentorname___[\d:]+$/", $key) && ($value !== "")) {
            $recordKey = preg_replace("/^mentorname___/", "", $key);
            addMentorValue($mentorNames, $mentorNamesAsArray, $recordKey, $value);
        } else if (preg_match("/^newmentorname___[\d:]+$/", $key) && ($value !== "")) {
            $recordKey = preg_replace("/^newmentorname___/", "", $key);
            addMentorValue($newMentorNames, $newMentorNamesAsArray, $recordKey, $value);
        }
    }
    collapseMentorArrays($newMentorNames, $newMentorNamesAsArray);
    collapseMentorArrays($mentorNames, $mentorNamesAsArray);
    collapseMentorArrays($mentorUids, $mentorUidsAsArray);
    return [$mentorNames, $mentorUids, $newMentorNames];
}

function addMentorValue(&$textArray, &$arrayOfArrays, $recordKey, $value) {
    if (strpos($recordKey, ":") === FALSE) {
        $recordId = $recordKey;
        $textArray[$recordId] = $value;
    } else if ($value) {
        list($recordId, $i) = explode(":", $recordKey);
        if (!isset($arrayOfArrays[$recordId])) {
            $arrayOfArrays[$recordId] = [];
        }
        $arrayOfArrays[$recordId][$i] = $value;
    }
}

function collapseMentorArrays(&$textArray, $valuesAsArray) {
    foreach ($valuesAsArray as $recordId => $instances) {
        ksort($instances, SORT_NUMERIC);
        $newList = implode(", ", array_values($instances));
        $textArray[$recordId] = $newList;
    }
}

function uploadBulkForm($post, $token, $server) {
    list($mentorNames, $mentorUids, $newMentorNames) = parsePostForData($post);
    $upload = [];
    if (!empty($mentorNames) && !empty($mentorUids)) {
        foreach ($mentorNames as $recordId => $mentorName) {
            $uid = $mentorUids[$recordId];
            $upload[] = [
                "record_id" => $recordId,
                "imported_mentor" => $mentorName,
                "imported_mentor_userid" => $uid,
            ];
        }
    }
    if (!empty($upload)) {
        $feedback = Upload::rows($upload, $token, $server);
        if (empty($feedback['errors'])) {
            return "<p class='centered green'>Upload successful</p>";
        } else {
            return "<p class='centered max-width red'>".implode("<br>", $feedback['errors'])."</p>";
        }
    } else {
        return "<p class='centered red'>No data to upload.</p>";
    }
}

function remakeUploadTable($post, $token, $server) {
    list($mentorNames, $mentorUids, $newMentorNames) = parsePostForData($post);
    $names = Download::names($token, $server);
    $firstNames = Download::firstnames($token, $server);
    $lastNames = Download::lastnames($token, $server);
    $thisUrl = Application::link("this");

    $tableRows = [];
    $newMentorNamesAsArray = [];
    foreach ($newMentorNames as $key => $newMentorName) {
        if (strpos($key, ":") === FALSE) {
            $recordId = $key;
            $tableRows[] = lookupScholarAndMentorName($names, $firstNames[$recordId], $lastNames[$recordId], $newMentorName, $token, $server);
        } else {
            list($recordId, $i) = explode(":", $key);
            if (!isset($newMentorNamesAsArray[$recordId])) {
                $newMentorNamesAsArray[$recordId] = [];
            }
            $newMentorNamesAsArray[$recordId][$i] = $newMentorName;
        }
    }
    foreach ($newMentorNamesAsArray as $recordId => $instances) {
        ksort($instances, SORT_NUMERIC);
        $newMentorList = implode(", ", array_values($instances));
        $tableRows[] = lookupScholarAndMentorName($names, $firstNames[$recordId], $lastNames[$recordId], $newMentorList, $token, $server);
    }

    $hiddenRows = [];
    foreach ($mentorNames as $key => $mentorName) {
        $hiddenRows[] = "<input type='hidden' name='mentorname___$key' value='".preg_replace("/'/", "\\'", $mentorName)."'>";
    }
    foreach ($mentorUids as $key => $uid) {
        $hiddenRows[] = "<input type='hidden' name='mentor___$key' value='".preg_replace("/'/", "\\'", $uid)."'>";
    }
    return makeSubmitTable($thisUrl, getTableHeaders(), $tableRows, $hiddenRows);
}

function makeUploadTable($filename, $token, $server) {
    $thisUrl = Application::link("this");
    $html = "";
    if (file_exists($filename)) {
        $fp = fopen($filename, "r");
        $csvHeaders = fgetcsv($fp);
        if (count($csvHeaders) < 4) {
            $html .= "<p class='centered red'>Not enough columns! You have ".count($csvHeaders).".</p>";
            return $html;
        }
        $names = Download::names($token, $server);
        $tableRows = [];
        while ($line = fgetcsv($fp)) {
            if (count($line) >= 4) {
                $scholarFirst = $line[0];
                $scholarLast = $line[1];
                $mentorFirst = $line[2];
                $mentorLast = $line[3];
                $tableRow = lookupScholarAndMentorName($names, $scholarFirst, $scholarLast, "$mentorFirst $mentorLast", $token, $server);
                if ($tableRow) {
                    $tableRows[] = $tableRow;
                }
            }
        }
        if (!empty($tableRows)) {
            $html .= makeSubmitTable($thisUrl, getTableHeaders(), $tableRows, []);
        }
        fclose($fp);
    } else {
        $html .= "<p class='centered red'>Improper CSV file</p>";
    }
    return $html;
}

function getTableHeaders() {
    return [
        "<th>Matched Record</th>",
        "<th>Scholar Name</th>",
        "<th>Mentor Name</th>",
        "<th>Mentor Userid</th>",
    ];
}

function makeSubmitTable($thisUrl, $headers, $tableRows, $hiddenRows) {
    $html = "";
    $html .= "<form action='$thisUrl&upload=form' method='POST'>";
    $html .= Application::generateCSRFTokenHTML();
    $html .= "<table class='centered max-width bordered'>";
    $html .= "<thead><tr>".implode("", $headers)."</tr></thead>";
    $html .= "<tbody>".implode("", $tableRows)."</tbody>";
    $html .= "</table>";
    $html .= implode("", $hiddenRows);
    $html .= "<p class='centered'><button>Submit</button></p>";
    $html .= "</form>";
    return $html;
}

function lookupScholarAndMentorName($names, $scholarFirst, $scholarLast, $mentorList, $token, $server) {
    if ($scholarFirst && $scholarLast && $mentorList && ($recordId = NameMatcher::matchName($scholarFirst, $scholarLast, $token, $server))) {
        if (preg_match("/[,;]/", $mentorList)) {
            $mentorNames = preg_split("/\s*[,;]\s*/", $mentorList);
            $i = 1;
            $tableRow = "";
            foreach ($mentorNames as $mentorName) {
                $suffix = ":$i";
                $tableRow .= makeMentorHTML($names, $scholarFirst, $scholarLast, $mentorName, $recordId, $suffix);
                $i++;
            }
        } else {
            $tableRow = makeMentorHTML($names, $scholarFirst, $scholarLast, $mentorList, $recordId, "");
        }
        return $tableRow;
    }
    return "";
}

function makeMentorHTML($names, $scholarFirst, $scholarLast, $mentorName, $recordId, $suffix) {
    list($mentorFirst, $mentorLast) = NameMatcher::splitName($mentorName, 2);
    $lookup = new REDCapLookup($mentorFirst, $mentorLast);
    $uids = $lookup->getUidsAndNames();
    if (count($uids) == 0) {
        $lookup = new REDCapLookup("", $mentorLast);
        $uids = $lookup->getUidsAndNames();
    }
    $escapedMentorName = preg_replace("/'/", "\\'", $lookup->getName());

    $tableRow = "<tr>";
    $tableRow .= "<td>$recordId {$names[$recordId]}</td>";
    $tableRow .= "<td>$scholarFirst $scholarLast</td>";
    $tableRow .= "<td>$mentorName</td>";
    $hiddenField = "<input type='hidden' name='mentorname___$recordId$suffix' value='$escapedMentorName' />";
    if (count($uids) == 0) {
        $hiddenField .= "<input type='hidden' name='originalmentorname___$recordId$suffix' value='{$lookup->getName()}' />";
        $noId = "mentor___$recordId$suffix" . "___no";
        $skipInput = "<input type='radio' name='mentor___$recordId$suffix' id='$noId' value='' /> <label for='$noId'>Yes, please skip</label>";
        $tableRow .= "<td class='red'><strong>No names in REDCap matched with {$lookup->getName()}.</strong><br/>Do you want to skip this mentor's user-id?<br/>$skipInput<br/>Is there is a nickname and/or a maiden name at play here. Do you want to try adjusting their name?<br>$hiddenField<input type='text' name='newmentorname___$recordId' value=''></td>";
    } else if (count($uids) == 1) {
        $uid = array_keys($uids)[0];
        $userInfo = REDCapLookup::getUserInfo($uid);
        $email = $userInfo['user_email'] ?? "";
        $emailLink = $email ? "<a href='mailto:$email'>$email</a>" : "Email Unknown";

        $startTs = $userInfo['user_firstvisit'] ? strtotime($userInfo['user_firstvisit']) : FALSE;
        $endTs = $userInfo['user_lastactivity'] ? strtotime($userInfo['user_lastactivity']) : FALSE;
        if ($startTs && $endTs) {
            $startYear = date("Y", $startTs);
            $endYear = date("Y", $endTs);
            if ($startYear == $endYear) {
                $yearInfo = $startYear;
            } else {
                $yearInfo = $startYear." - ".$endYear;
            }
        } else {
            $yearInfo = "Unknown";
        }

        $yesId = "mentor___$recordId$suffix" . "___yes";
        $noId = "mentor___$recordId$suffix" . "___no";
        $yesno = "<input type='radio' name='mentor___$recordId$suffix' id='$yesId' value='$uid' checked /> <label for='$yesId'>Yes</label><br>";
        $yesno .= "<input type='radio' name='mentor___$recordId$suffix' id='$noId' value='' /> <label for='$noId'>No</label>";
        $tableRow .= "<td class='green'>$hiddenField" . "Matched: $uid<br/>(last used REDCap: $yearInfo)<br/>$emailLink<br/>$yesno</td>";
    } else {
        $radios = [];
        $noId = "mentor___$recordId" . "___no";
        $radios[] = "<input type='radio' name='mentor___$recordId$suffix' id='$noId' value='' checked /> <label for='$noId'>None of the Above</label>";
        foreach ($uids as $uid => $mentorName) {
            $id = "mentor___" . $recordId . $suffix . "___" . $uid;
            $mentorEmail = REDCapLookup::getUserInfo($uid)["user_email"] ?? "";
            $radios[] = "<input type='radio' name='mentor___$recordId$suffix' id='$id' value='$uid' /> <label for='$id'>$mentorName ($uid<br/>$mentorEmail)</label>";
        }

        $tableRow .= "<td class='yellow'>$hiddenField" . implode("<br>", $radios) . "</td>";
    }
    $tableRow .= "</tr>";
    return $tableRow;
}

function makeMainForm($token, $server) {
    $names = Download::names($token, $server);
    $scholarJSON = json_encode($names);
    $primaryMentors = Download::primaryMentors($token, $server);
    $mentorJSON = json_encode($primaryMentors);
    $thisUrl = Application::link("this");

    $url = Application::link("signupToREDCap");
    $text = getInviteText();
    $inviteText = "<a href='$url' target='_NEW'>$text</a>.";

    $html = "";
    $html .= "<p class='centered max-width'>REDCap user-ids are automatically looked up so that mentees and mentors can use the mentoring agreement. In case of multiple matches, you must confirm each user-id. Be wary that certain mentors might alternately have listed a formal name or a nickname.</p>";
    $html .= "<p class='centered smaller'>Or $inviteText</p>";
    $html .= "<h1>Upload Mentors in Bulk</h1>";
    $html .= "<div class='max-width centered'>";
    $html .= "<p class='centered max-width'>Please follow <a href='$thisUrl&download=csv'>this template</a> and upload the resulting CSV. If you want to add multiple mentors for each scholar, please add multiple spreadsheet rows, one per mentor. Flight Tracker will combine them.</p>";
    $html .= "<form action='$thisUrl&upload=csv' method='POST' enctype='multipart/form-data'>";
    $html .= Application::generateCSRFTokenHTML();
    $html .= "<p class='centered'><input type='file' name='csv_file'></p>";
    $html .= "<p class='centered'><button>Upload</button></p>";
    $html .= "</form>";
    $html .= "</div>";
    $html .= "<h1>Or Specify a Primary Mentor</h1>";
    $html .= "<p class='centered'>First, search for a Scholar/Mentee: <input type='text' id='search'> <button onclick='searchForName(\"#search\", \"#currentScholarResults\", \"#currentScholars\"); return false;'>Search</button></p>";
    $html .= "<div id='currentScholarResults' style='display: none;' class='centered max-width'>";
    $html .= "<h3>Current Scholars/Mentees</h3>";
    $html .= "<p class='centered' id='currentScholars'></p>";
    $html .= "</div>";
    $html .= "<div id='newMentor' class='centered max-width' style='display: none;'>";
    $html .= "<h3>New Mentor</h3>";
    $html .= "<p class='centered'>Next, specify a new Primary Mentor: <input type='text' id='primaryMentor'> <button onclick='searchForMentor(\"#primaryMentor\", \"#newMentorResults\", \"#existingMentor\"); return false;'>Search</button></p>";
    $html .= "<div id='newMentorResults' style='display: none;' class='centered max-width'>";
    $html .= "<p class='centered' id='existingMentor'></p>";
    $html .= "<p class='centered'><button onclick='changeMentor($(\"[name=chooseScholar]\").val()); return false;'>Add/Change Mentor</button></p>";
    $html .= "<p class='centered' id='mssg'></p>";
    $html .= "</div>";
    $html .= "</div>";
    $html .= makeMentorJS($scholarJSON, $mentorJSON);
    return $html;
}

function makeMentorJS($scholarJSON, $mentorJSON) {
    $thisUrl = Application::link("this");
    $js = "<script>
    const scholarNames = $scholarJSON;
    const mentorNames = $mentorJSON;

    function changeMentor(recordId) {
        const radiosOb = $('[name=chooseMentor]');
        let mentorUid = '';
        let mentorName = '';
        if (radiosOb.length > 0) {
            mentorUid = radiosOb.val();
            mentorName = radiosOb.text();
        } else {
            const yesNoOb = $('[name=verifyMentor]');
            if (yesNoOb.val() === '0') {
                return;
            }
            mentorUid = $('#mentorUid').val();
            mentorName = $('#mentorName').val();
        }
        $.post('$thisUrl', { 'redcap_csrf_token': getCSRFToken(), 'recordId': recordId, 'newMentorName': mentorName, 'newMentorUid': mentorUid }, function(json) {
            console.log(json);
            const data = JSON.parse(json);
            const ob = $('#mssg');
            if (data['errors'].length === 0) {
                const numSecs = 3;
                ob.html('Upload successful! Refreshing in '+numSecs+' seconds...');
                ob.removeClass('red');
                ob.addClass('green');
                window.scrollTo(0,document.body.scrollHeight);
                setTimeout(function() {
                    location.href = '$thisUrl';
                }, numSecs * 1000);
            } else {
                ob.html(data['errors'].join('<br>'));
                ob.removeClass('green');
                ob.addClass('red');
            }
        });
    }

    function searchForName(scholarSel, resultsSel, currentScholarSel) {
        let searchStr = $(scholarSel).val();
        if (searchStr) {
            $('#primaryMentor').val('');
            $.post('$thisUrl', { 'redcap_csrf_token': getCSRFToken(), 'scholarName': searchStr }, function(json) {
                console.log(json);
                let data = JSON.parse(json);
                if (data.length === 0) {
                    $(currentScholarSel).html('No Scholars Matched.');
                } else {
                    if (data.length === 1) {
                        let recordId = data[0];
                        let hiddenField = \"<input type='hidden' name='chooseScholar' value='\"+recordId+\"'>\";
                        $(currentScholarSel).html(scholarNames[recordId]+hiddenField);
                        fillPrimaryMentor(recordId);
                    } else {
                        var radios = [];
                        var selected = ' checked';
                        for (var i=0; i < data.length; i++) {
                            let recordId = data[i];
                            let id = 'chooseScholar'+recordId;
                            radios.push('<input type=\"radio\" onclick=\"fillPrimaryMentor('+recordId+');\" name=\"chooseScholar\" id=\"'+id+'\" value=\"'+recordId+'\"'+selected+'> <label for=\"'+id+'\">'+scholarNames[recordId]+' (Record '+recordId+')</label>');
                            selected = '';
                        }
                        $(currentScholarSel).html(radios.join('<br>'));
                    }
                    $('#newMentor').show();
                }
                $(resultsSel).show();
            });
        } else {
            $('#newMentor').hide();
            $(resultsSel).hide();
        }
    }

    function fillPrimaryMentor(recordId) {
        const mentors = mentorNames[recordId] ? mentorNames[recordId].join(', ') : '';
        $('#primaryMentor').val(mentors);
    }

    function searchForMentor(mentorSel, resultsSel, existingMentorSel) {
        let searchStr = $(mentorSel).val();
        if (searchStr) {
            $.post('$thisUrl', { 'redcap_csrf_token': getCSRFToken(), 'mentorName': searchStr }, function(json) {
                console.log(json);
                let data = JSON.parse(json);
                if (data.length === 0) {
                    $(existingMentorSel).html('No mentors matched');
                } else if (Object.keys(data).length === 1) {
                    let yesno = \"<input type='radio' name='verifyMentor' id='verifyMentor1' value='1' checked> <label for='verifyMentor1'>Yes</label><br>\"+
                        \"<input type='radio' name='verifyMentor' id='verifyMentor0' value='0'> <label for='verifyMentor0'>No</label>\";
                    for (var uid in data) {
                        let mentorName = data[uid];
                        let hiddenFields = \"<input type='hidden' id='mentorUid' value='\"+uid+\"'><input type='hidden' id='mentorName' value='\"+mentorName.replace(/'/g, \"\\\'\")+\"'>\";
                        $(existingMentorSel).html(mentorName+\" (\"+uid+\")<br>\"+yesno+hiddenFields);
                    }
                } else {
                    var radios = [];
                    for (var uid in data) {
                        let mentorName = data[uid];
                        let id = 'chooseMentor'+uid;
                        radios.push(\"<input type='radio' name='chooseMentor' id='\"+id+\"' value='\"+uid+\"'> <label for='\"+id+\"'>\"+mentorName+\"</label> (\"+uid+\")\");
                    }
                    $(existingMentorSel).html(radios.join('<br>'));
                }
                $(resultsSel).show();
            });
        } else {
            $(resultsSel).hide();
        }
    }
</script>";

    return $js;
}

function getInviteText() {
    if (Application::isVanderbilt()) {
        return "request a user-id from the REDCap Team";
    } else {
        global $homepage_contact;
        return "contact $homepage_contact";
    }
}