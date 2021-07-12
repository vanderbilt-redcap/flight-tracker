<?php

use \Vanderbilt\CareerDevLibrary\REDCapLookup;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\Upload;

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
} else if ($_GET['upload'] && ($_GET['upload'] == "csv")) {
    require_once(dirname(__FILE__) . "/charts/baseWeb.php");
    $html = makeUploadTable($_FILES['csv_file']['tmp_name'], $token, $server);
    echo $html;
} else if ($_POST['mentorName']) {
    require_once(dirname(__FILE__) . "/small_base.php");
    list($mentorFirst, $mentorLast) = NameMatcher::splitName($_POST['mentorName']);
    $lookup = new REDCapLookup($mentorFirst, $mentorLast);
    $uids = $lookup->getUidsAndNames();
    if (count($uids) == 0) {
        $lookup = new REDCapLookup("", $mentorLast);
        $uids = $lookup->getUidsAndNames();
    }
    echo json_encode($uids);
} else if ($_POST['newMentorName']) {
    require_once(dirname(__FILE__) . "/small_base.php");
    $newMentorName = $_POST['newMentorName'];
    $newMentorUid = $_POST['newMentorUid'];
    $recordId = $_POST['recordId'];
    $records = Download::recordIds($token, $server);
    if (in_array($recordId, $records) && $newMentorName && $newMentorUid) {
        $uploadRow = ["record_id" => $recordId, "imported_mentor" => $newMentorName, "imported_mentor_userid" => $newMentorUid, ];
        $feedback = Upload::oneRow($uploadRow, $token, $server);
    } else {
        $feedback = ["error" => "Invalid record"];
    }
    echo json_encode($feedback);
} else if ($_POST['scholarName']) {
    require_once(dirname(__FILE__) . "/small_base.php");
    $name = $_POST['scholarName'];
    $nodes = preg_split("/\s+/", $name);
    if (!$name) {
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
            $recordId = preg_replace("/^newmentorname___/", "", $key);
            $originalName = $post["originalmentorname___".$recordId];
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
    foreach ($post as $key => $value) {
        if (preg_match("/^mentor___\d+$/", $key)) {
            $recordId = preg_replace("/^mentor___/", "", $key);
            $mentorUids[$recordId] = $value;
        } else if (preg_match("/^mentorname___\d+$/", $key)) {
            $recordId = preg_replace("/^mentorname___/", "", $key);
            $mentorNames[$recordId] = $value;
        } else if (preg_match("/^newmentorname___\d+$/", $key)) {
            $recordId = preg_replace("/^newmentorname___/", "", $key);
            $newMentorNames[$recordId] = $value;
        }
    }
    return [$mentorNames, $mentorUids, $newMentorNames];
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
    foreach ($newMentorNames as $recordId => $newMentorName) {
        list($mentorFirst, $mentorLast) = NameMatcher::splitName($newMentorName, 2);
        $tableRows[] = lookupScholarAndMentorName($names, $firstNames[$recordId], $lastNames[$recordId], $mentorFirst, $mentorLast, $token, $server);
    }
    $hiddenRows = [];
    foreach ($mentorNames as $recordId => $mentorName) {
        $hiddenRows[] = "<input type='hidden' name='mentorname___$recordId' value='".preg_replace("/'/", "\\'", $mentorName)."'>";
    }
    foreach ($mentorUids as $recordId => $uid) {
        $hiddenRows[] = "<input type='hidden' name='mentor___$recordId' value='".preg_replace("/'/", "\\'", $uid)."'>";
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
                $tableRow = lookupScholarAndMentorName($names, $scholarFirst, $scholarLast, $mentorFirst, $mentorLast, $token, $server);
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
    $html .= "<table class='centered max-width bordered'>";
    $html .= "<thead><tr>".implode("", $headers)."</tr></thead>";
    $html .= "<tbody>".implode("", $tableRows)."</tbody>";
    $html .= "</table>";
    $html .= implode("", $hiddenRows);
    $html .= "<p class='centered'><button>Submit</button></p>";
    $html .= "</form>";
    return $html;
}

function lookupScholarAndMentorName($names, $scholarFirst, $scholarLast, $mentorFirst, $mentorLast, $token, $server) {
    if ($scholarFirst && $scholarLast && $mentorLast && ($recordId = NameMatcher::matchName($scholarFirst, $scholarLast, $token, $server))) {
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
        $tableRow .= "<td>$mentorFirst $mentorLast</td>";
        if (count($uids) == 0) {
            $hiddenField = "<input type='hidden' name='originalmentorname___$recordId' value='{$lookup->getName()}'>";
            $tableRow .= "<td class='red'><strong>No mentors matched with {$lookup->getName()}.</strong><br>Will not upload this mentor.<br>Perhaps there is a nickname and/or a maiden name at play here. Do you want to try adjusting their name?<br>$hiddenField<input type='text' name='newmentorname___$recordId' value='{$lookup->getName()}'></td>";
        } else {
            $hiddenField = "<input type='hidden' name='mentorname___$recordId' value='$escapedMentorName'>";
            if (count($uids) == 1) {
                $uid = array_keys($uids)[0];
                $yesId = "mentor___$recordId" . "___yes";
                $noId = "mentor___$recordId" . "___no";
                $yesno = "<input type='radio' name='mentor___$recordId' id='$yesId' value='$uid' checked> <label for='$yesId'>Yes</label><br>";
                $yesno .= "<input type='radio' name='mentor___$recordId' id='$noId' value=''> <label for='$noId'>No</label>";
                $tableRow .= "<td class='green'>$hiddenField" . "Matched: $uid<br>$yesno</td>";
            } else {
                $radios = [];
                $selected = " checked";
                foreach ($uids as $uid => $mentorName) {
                    $id = "mentor___" . $recordId . "___" . $uid;
                    $radios[] = "<input type='radio' name='mentor___$recordId' id='$id' value='$uid'$selected> <label for='$id'>$mentorName</label>";
                    $selected = "";
                }
                $tableRow .= "<td class='yellow'>$hiddenField" . implode("<br>", $radios) . "</td>";
            }
        }
        $tableRow .= "</tr>";
        return $tableRow;
    }
    return "";
}

function makeMainForm($token, $server) {
    $names = Download::names($token, $server);
    $scholarJSON = json_encode($names);
    $primaryMentors = Download::primaryMentors($token, $server);
    $mentorJSON = json_encode($primaryMentors);
    $thisUrl = Application::link("this");

    $html = "";
    $html .= "<p class='centered max-width'>REDCap user-ids are automatically looked up so that mentees and mentors can use the mentoring agreement. In case of multiple matches, you must confirm each user-id. Be wary that certain mentors might alternately have listed a formal name or a nickname.</p>";
    $html .= "<h1>Upload Mentors in Bulk</h1>";
    $html .= "<div class='max-width centered'>";
    $html .= "<p class='centered'>Please follow <a href='$thisUrl&download=csv'>this template</a> and upload the resulting CSV.</p>";
    $html .= "<form action='$thisUrl&upload=csv' method='POST' enctype='multipart/form-data'>";
    $html .= "<p class='centered'><input type='file' name='csv_file'></p>";
    $html .= "<p class='centered'><button>Upload</button></p>";
    $html .= "</form>";
    $html .= "</div>";
    $html .= "<h1>Or Specify a Primary Mentor</h1>";
    $html .= "<p class='centered'>Search for a Scholar: <input type='text' id='search'> <button onclick='searchForName(\"#search\", \"#currentMentorResults\", \"#currentMentors\"); return false;'>Search</button></p>";
    $html .= "<div id='currentMentorResults' style='display: none;' class='centered max-width'>";
    $html .= "<h3>Current Mentors</h3>";
    $html .= "<p class='centered' id='currentMentors'></p>";
    $html .= "</div>";
    $html .= "<div id='newMentor' class='centered max-width' style='display: none;'>";
    $html .= "<h3>New Mentor</h3>";
    $html .= "<p class='centered'>New Primary Mentor: <input type='text' id='primaryMentor'> <button onclick='searchForMentor(\"#primaryMentor\", \"#newMentorResults\", \"#existingMentor\"); return false;'>Search</button></p>";
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
    let scholarNames = $scholarJSON;
    let mentorNames = $mentorJSON;

    function changeMentor(recordId) {
        let radiosOb = $('[name=chooseMentor]');
        var mentorUid = '';
        var mentorName = '';
        if (radiosOb.length > 0) {
            mentorUid = radiosOb.val();
            mentorName = radiosOb.text();
        } else {
            let yesNoOb = $('[name=verifyMentor]');
            if (yesNoOb.val() === '0') {
                return;
            }
            mentorUid = $('#mentorUid').val();
            mentorName = $('#mentorName').val();
        }
        $.post('$thisUrl', { 'recordId': recordId, 'newMentorName': mentorName, 'newMentorUid': mentorUid }, function(json) {
            console.log(json);
            let data = JSON.parse(json);
            let ob = $('#mssg');
            if (data['errors'].length === 0) {
                ob.html('Upload successful');
                ob.removeClass('red');
                ob.addClass('green');
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
            $.post('$thisUrl', { 'scholarName': searchStr }, function(json) {
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
        $('#primaryMentor').val(mentorNames[recordId].join(', '));
    }

    function searchForMentor(mentorSel, resultsSel, existingMentorSel) {
        let searchStr = $(mentorSel).val();
        if (searchStr) {
            $.post('$thisUrl', { 'mentorName': searchStr }, function(json) {
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