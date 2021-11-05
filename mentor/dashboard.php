<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\BarChart;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\REDCapLookup;
use \Vanderbilt\CareerDevLibrary\REDCapLookupByUserid;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/base.php");

$numWeeks = 3;

if ($_POST['newUids']) {
    require_once(dirname(__FILE__)."/../small_base.php");
    $records = Download::recordIds($token, $server);
    $newUids = REDCapManagement::sanitizeArray($_POST['newUids']);
    $upload = [];
    $mentorUserids = Download::primaryMentorUserids($token, $server);
    foreach ($newUids as $recordId => $uids) {
        $recordId = REDCapManagement::getSanitizedRecord($recordId, $records);
        if ($recordId) {
            $addedNew = FALSE;
            $newUidList = [];
            foreach ($uids as $uid) {
                if (!$mentorUserids[$recordId]) {
                    $newUidList = [];
                } else {
                    $newUidList = $mentorUserids[$recordId];
                }
                if (!in_array($uid, $mentorUserids[$recordId])) {
                    $newUidList[] = $uid;
                    $addedNew = TRUE;
                }
            }
            if ($addedNew) {
                $uploadRow = [
                    "record_id" => $recordId,
                    "summary_mentor_userid" => implode(", ", $newUidList),
                ];
                $upload[] = $uploadRow;
            }
        }
    }
    if (!empty($upload)) {
        $feedback = Upload::rows($upload, $token, $server);
    } else {
        $feedback = ["message" => "No data to upload."];
    }
    echo json_encode($feedback);
    exit;
} else if ($_POST['updateMentors']) {
    require_once(dirname(__FILE__)."/../small_base.php");
    $records = Download::recordIds($token, $server);
    $names = Download::names($token, $server);
    $mentorNames = Download::primaryMentors($token, $server);
    $mentorUserids = Download::primaryMentorUserids($token, $server);
    $upload = [];
    $itemsToAdjudicate = [];
    foreach ($records as $recordId) {
        if ($mentorNames[$recordId]) {
            $numMentorNames = count($mentorNames[$recordId]);
            $numMentorUserids = count($mentorUserids[$recordId]);
            if ($numMentorNames > $numMentorUserids) {
                $uids = $mentorUserids[$recordId];
                $allBlank = !empty($mentorNames[$recordId]);
                $checkboxes = [];
                $fixedMentorNames = [];
                foreach ($mentorNames[$recordId] as $mentorName) {
                    if ($mentorName) {
                        list($mentorFirstName, $mentorLastName) = NameMatcher::splitName($mentorName);
                        $lookup = new REDCapLookup($mentorFirstName, $mentorLastName);
                        $mentorUidsAndNames = $lookup->getUidsAndNames();
                        $fixedMentorNames[] = $lookup->getName();
                        if (count($mentorUidsAndNames) == 0) {
                            # try again with just the last name
                            $lookup = new REDCapLookup("", $mentorLastName);
                            $mentorUidsAndNames = $lookup->getUidsAndNames();
                        }
                        if (count($mentorUidsAndNames) == 1) {
                            $allBlank = FALSE;
                            $uid = array_keys($mentorUidsAndNames)[0];
                            if (!in_array($uid, $uids)) {
                                $uids[] = $uid;
                            }
                        } else if (count($mentorUidsAndNames) > 1) {
                            $allBlank = FALSE;
                            $foundMatch = FALSE;
                            foreach ($mentorUserids[$recordId] as $userid) {
                                if ($userid && isset($mentorUidsAndNames[$userid])) {
                                    $foundMatch = TRUE;
                                    break;
                                }
                            }
                            if (!$foundMatch) {
                                foreach ($mentorUidsAndNames as $uid => $name) {
                                    if ($name) {
                                        $escapedUid = preg_replace("/'/", "\\'", $uid);
                                        $id = "mentorUid___$recordId" . "___$escapedUid";
                                        $checkboxes[$uid] = "<input type='checkbox' id='$id' name='$id'> <label for='$id'>$uid <strong>$name</strong></label>";
                                    }
                                }
                            }
                        }
                    }
                }
                $useridStr = !empty($mentorUserids[$recordId]) ? implode(", ", $mentorUserids[$recordId]) : "No user-id entered";
                if (!empty($checkboxes)) {
                    $adjudicationRow = [
                        "Scholar" => $names[$recordId],
                        "Mentors" => implode("<br>", $fixedMentorNames),
                        "Existing Mentor User-ids" => $useridStr,
                        "Mentor Matches" => implode("<br>", array_values($checkboxes)),
                    ];
                    $itemsToAdjudicate[] = $adjudicationRow;
                } else if ($allBlank) {
                    $adjudicationRow = [
                        "Scholar" => $names[$recordId],
                        "Mentors" => implode("<br>", $fixedMentorNames),
                        "Existing Mentor User-ids" => $useridStr,
                        "Mentor Matches" => "No matches found.",
                    ];
                    $itemsToAdjudicate[] = $adjudicationRow;
                } else if (count($uids) > $numMentorUserids) {
                    $uploadRow = ["record_id" => $recordId, "summary_mentor_userids" => implode(", ", $uids)];
                    $upload[] = $uploadRow;
                }
            }
        }
    }
    if (!empty($itemsToAdjudicate)) {
        $feedback = ["message" => "Adjudicate", "data" => $itemsToAdjudicate];
    } else if (!empty($upload)) {
        $feedbackData = Upload::rows($upload, $token, $server);
        $feedback = ["message" => "Uploaded", "data" => $feedbackData];
    } else {
        $feedback = ["message" => "No data to upload."];
    }
    echo json_encode($feedback);
    exit;
}

require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$records = Download::recordIds($token, $server);
$userids = Download::userids($token, $server);
$mentorNames = Download::primaryMentors($token, $server);
$mentorUserids = Download::primaryMentorUserids($token, $server);
$numInvited = ["mentees" => getElementCount($userids), "mentors" => getElementCount($mentorUserids)];
$numCompletedInitial = ["mentees" => 0, "mentors" => 0];
$numCompletedFollowup = ["mentees" => 0, "mentors" => 0];
$timesToComplete = ["mentees" => [], "mentors" => []];
$numMentors = getElementCount($mentorNames);

$selectFieldTypes = ["dropdown", "radio", "checkbox", ];
$metadata = Download::metadata($token, $server);
$allForms = REDCapManagement::getFormsFromMetadata($metadata);
$hasEvaluationsEnabled = in_array("mentoring_agreement_evaluations", $allForms);
$choices = REDCapManagement::getChoices($metadata);
$agreementFields = REDCapManagement::getFieldsFromMetadata($metadata, "mentoring_agreement");
$agreementFields[] = "record_id";
$selectFieldsAndLabels = [];
$selectFieldsAndTypes = [];
foreach ($metadata as $row) {
    if (in_array($row['field_name'], $agreementFields) && in_array($row['field_type'], $selectFieldTypes)) {
        $selectFieldsAndLabels[$row['field_name']] = $row['field_label'];
        $selectFieldsAndTypes[$row['field_name']] = $row['field_type'];
    }
}

$values = [];
foreach ($records as $recordId) {
    $redcapData = Download::fieldsForRecords($token, $server, $agreementFields, [$recordId]);

    foreach ($selectFieldsAndLabels as $fieldName => $fieldLabel) {
        if (!isset($values[$fieldName])) {
            $values[$fieldName] = [];
        }
        if (!isset($values[$fieldName][$recordId])) {
            $values[$fieldName][$recordId] = [];
        }
        foreach ($redcapData as $row) {
            if (isset($row[$fieldName]) && isset($choices[$fieldName][$row[$fieldName]])) {
                $values[$fieldName][$recordId][] = $row[$fieldName];
            }
        }
    }

    $isFirstMentor = TRUE;
    $isFirstMentee = TRUE;
    if (isset($_GET['test']) && (count($redcapData) > 0)) {
        echo "Record $recordId: ".REDCapManagement::json_encode_with_spaces($redcapData)."<br>";
    }
    foreach ($redcapData as $row) {
        if ($row['redcap_repeat_instrument'] == "mentoring_agreement") {
            $useridOfRespondant = $row['mentoring_userid'];
            $menteeUserids = getMenteeUserids($userids[$recordId]);
            if (in_array($useridOfRespondant, $menteeUserids)) {
                $respondantClass = "mentees";
            } else if (in_array($useridOfRespondant, $mentorUserids[$recordId])) {
                $respondantClass = "mentors";
            } else {
                $respondantClass = "unknown";
            }
            if (isset($_GET['test'])) {
                echo "Record $recordId: $respondantClass $useridOfRespondant<br>";
            }

            if ($isFirstMentee && ($respondantClass == "mentees")) {
                $numCompletedInitial[$respondantClass]++;
                $isFirstMentee = FALSE;
            } else if ($isFirstMentor && ($respondantClass == "mentors")) {
                $numCompletedInitial[$respondantClass]++;
                $isFirstMentor = FALSE;
            } else if (isset($numCompletedFollowup[$respondantClass])) {
                $numCompletedFollowup[$respondantClass]++;
            }
            if (isset($timesToComplete[$respondantClass]) && $row['mentoring_start'] && $row['mentoring_end']) {
                $timesToComplete[$respondantClass][] = strtotime($row['mentoring_start']) - strtotime($row['mentoring_end']);
            }
        }
    }
}
$averageTimesToComplete = [];
foreach ($timesToComplete as $respondantClass => $times) {
    $averageTimesToComplete[$respondantClass] = 0;
    if (count($times) > 0) {
        $averageTimesToComplete[$respondantClass] = REDCapManagement::pretty(array_sum($times) / (count($times) * 60), 1);
    }
}

if ($numMentors > $numInvited["mentors"]) {
    $link = Application::link("this");
    echo "<p class='centered'><button onclick='checkForNewMentorUserids(\"$link\");'>Update Mentor User-ids</button></p>";
    echo "<div id='results'></div>";
}

echo "<h1>Mentoring Agreement Responses</h1>";

$homeLink = Application::getMenteeAgreementLink();
$addLink = Application::link("addMentor.php");
echo "<p class='centered max-width'><strong><a class='smaller' href='$homeLink'>$homeLink</a></strong><br>Pass along this link to any mentee or mentor that (A) has a REDCap userid and (B) is registered in your Flight Tracker as a Scholar/Mentee or a Primary Mentor (with a <a href='$addLink'>registered userid</a>). With this link, they can access their relevant mentoring information anytime.</p>";
echo "<h2>Submissions</h2>";
echo "<table class='centered bordered max-width'>";
echo "<thead><tr>";
echo "<th>Measure</th>";
echo "<th>For Mentees</th>";
echo "<th>For Mentors</th>";
echo "</tr></thead>";
echo "<tbody>";
echo makeGeneralTableRow("Number of Mentors Filled In", $numMentors, "Mentors");
echo makeGeneralTableRow("People with Unique User-ids Involved", $numInvited, "Individuals");
echo makeGeneralTableRow("Number Completed Initial<br>Mentee-Mentor Agreement Survey", $numCompletedInitial, "");
echo makeGeneralTableRow("Number Completed Follow-up<br>Mentee-Mentor Agreement Survey", $numCompletedFollowup, "");
echo makeGeneralTableRow("Average Time to Complete", $averageTimesToComplete, "Minutes");
echo "</tbody>";
echo "</table>";

echo "<h2>Result Charts</h2>";
$sourceData = [];
$isFirstChart = TRUE;
$colors = Application::getApplicationColors();
$i = 0;
foreach ($selectFieldsAndLabels as $fieldName => $fieldLabel) {
    echo "<h3 class='max-width'>$fieldLabel</h3>";
    $cols = [];
    foreach (array_values($choices[$fieldName]) as $label) {
        $cols[$label] = 0;
    }
    $total = 0;
    foreach ($values[$fieldName] as $recordId => $recordData) {
        foreach ($recordData as $datumIdx) {
            $datumLabel = $choices[$fieldName][$datumIdx];
            $cols[$datumLabel]++;
            $total++;
        }
    }
    $sourceData[$fieldName] = $cols;
    if ($total > 0) {
        if ($selectFieldsAndTypes[$fieldName] == "checkbox") {
            echo "<p class='centered'>Note: More than one option can be selected because this is a checkbox.</p>";
        }
        $chart = new BarChart(array_values($cols), array_keys($cols), $fieldName);
        if ($isFirstChart) {
            echo $chart->getImportHTML();
            $isFirstChart = FALSE;
        }
        $chart->setColor($colors[$i % count($colors)]);
        $i++;
        echo $chart->getHTML(800, 400);
    } else {
        echo "<p class='centered'>No data are currently available for this item.</p>";
    }
}

echo "<h2>Result Table</h2>";
echo "<table class='bordered max-width centered'>";
echo "<thead><tr>";
echo "<th>Question</th>";
echo "<th>Answer</th>";
echo "<th>Number of Responses with Answer</th>";
echo "<th>Total Responses (n)</th>";
echo "<th>Response Percentage</th>";
echo "</tr></thead>";
echo "<tbody>";
foreach ($sourceData as $fieldName => $cols) {
    $fieldLabel = $selectFieldsAndLabels[$fieldName];
    foreach ($cols as $colLabel => $numberWithAnswer) {
        echo makeAnswerTableRow($fieldLabel, $colLabel, $numberWithAnswer, getTotalCount($values[$fieldName]));
        $fieldLabel = "";
    }
}
echo "</tbody>";
echo "</table>";
if ($hasEvaluationsEnabled) {
    $evalFields = REDCapManagement::getFieldsFromMetadata($metadata, "mentoring_agreement_evaluations");
    $evalFields[] = "record_id";
    $recordsWithoutEvals = ["mentor" => [], "mentee" => []];
    foreach ($records as $recordId) {
        $redcapData = Download::fieldsForRecords($token, $server, $evalFields, [$recordId]);
        $hasEval = [];
        foreach (array_keys($recordsWithoutEvals) as $type) {
            $hasEval[$type] = FALSE;
        }
        foreach ($redcapData as $row) {
            if ($row['redcap_repeat_instrument'] == "mentoring_agreement_evaluations") {
                $instance = $row['redcap_repeat_instance'];
                foreach (array_keys($recordsWithoutEvals) as $type) {
                    if ($instance == getEvalInstance($type)) {
                        $hasEval[$type] = TRUE;
                    }
                }
            }
        }
        if (!$hasEval["mentee"] && !$hasEval["mentor"]) {
            $redcapData = Download::fieldsForRecords($token, $server, ["record_id", "mentoring_last_update"], [$recordId]);
            $minTime = time() - $numWeeks * 7 * 24 * 3600;
            $updatedRecently = FALSE;
            $updatedAtAll = FALSE;
            foreach ($redcapData as $row) {
                if ($row['redcap_repeat_instrument'] == "mentoring_agreement") {
                    $updatedAtAll = TRUE;
                    if ($row['mentoring_last_update']) {
                        if (strtotime($row['mentoring_last_update']) > $minTime) {
                            $updatedRecently = TRUE;
                            break;
                        }
                    }
                }
            }
            if (!$updatedAtAll && !$updatedRecently) {
                $recordsWithoutEvals["mentor"][] = $recordId;
                $recordsWithoutEvals["mentee"][] = $recordId;
            }
        } else if (!$hasEval["mentor"]) {
            $recordsWithoutEvals["mentor"][] = $recordId;
        } else if (!$hasEval["mentee"]) {
            $recordsWithoutEvals["mentee"][] = $recordId;
        }
    }
    if (!empty(array_merge($recordsWithoutEvals['mentor'], $recordsWithoutEvals['mentee']))) {
        $names = Download::names($token, $server);
        $emails = Download::emails($token, $server);
        echo "<table class='centered bordered'><tbody><tr>";
        foreach ($recordsWithoutEvals as $type => $recordsWithoutEval) {
            $typeLabel = ucfirst($type);
            $recordJSON = json_encode($recordsWithoutEval);
            $count = count($recordsWithoutEval);
            if ($count >= 2) {
                $countStr = "$count Scholars Without an Eval from a $typeLabel";
            } else if ($count == 1) {
                $countStr = "$count Scholar Without an Eval from a $typeLabel";
            } else {
                $countStr = "No Scholars Without an Eval from a $typeLabel";
            }
            echo "<td>";
            echo "<p class='centered skinnymargins'><span class='bolded'>$countStr</span><br><span class='smaller'>(no response in over $numWeeks weeks)</span></p>";
            if ($count > 0) {
                $mailtos = [];
                foreach ($recordsWithoutEval as $recordId) {
                    $email = "";
                    if ($type == "mentee") {
                        $email = $emails[$recordId];
                        $name = $names[$recordId];
                    } else if ($type == "mentor") {
                        $mentorEmails = [];
                        foreach ($mentorUserids[$recordId] as $mentorUserid) {
                            $lookup = new REDCapLookupByUserid($mentorUserid);
                            $mentorEmail = $lookup->getEmail();
                            if ($mentorEmail) {
                                $mentorEmails[] = $mentorEmail;
                            }
                        }
                        $name = implode(", ", $mentorNames[$recordId]);
                        $email = implode(",", $mentorEmails);
                    } else {
                        throw new \Exception("Invalid type $type");
                    }
                    if ($email) {
                        $mailtos[] = "<a href='mailto:$email'>$name</a>";
                    } else {
                        $mailtos[] = $name;
                    }
                }
                echo "<p class='centered skinnymargins'>".implode("<br>", $mailtos)."</p>";
            }
            echo "</td>";
        }
        echo "</tr></tbody></table>";
    }
}
echo "<script>
function checkForNewMentorUserids(link) {
    $('#results').html('');
    presentScreen('Checking...');
    $.post(link, { 'updateMentors': true }, function(json) {
        clearScreen();
        let data = JSON.parse(json);
        if (data['message']) {
            console.log(data['message']);
            if (data['message'] == 'Adjudicate') {
                let headers = Object.keys(data['data'][0]);
                let html = [];
                html.push('<h2>User-ids to Adjudicate</h2>');
                html.push('<table class=\"centered bordered max-width\"><thead>');
                html.push('<tr><th>'+headers.join('</th><th>')+'</th></tr>');
                html.push('</thead><tbody>');
                for (var i = 0; i < data['data'].length; i++) {
                    let cols = [];
                    for (var j = 0; j < headers.length; j++) {
                        let header = headers[j];
                        var colType = 'td';
                        var addlClass = '';
                        if (j === 0) {
                            colType = 'th';
                        } else if (j === 3) {
                            addlClass = ' alignLeft';
                        }
                        
                        cols.push('<'+colType+' class=\"alignTop'+addlClass+'\">'+data['data'][i][header]+'</'+colType+'>');
                    }
                    html.push('<tr>'+cols.join('')+'</tr>');
                }
                html.push('</tbody></table>');
                html.push('<p class=\"centered\"><button onclick=\"submitAdjudications(\''+link+'\');\">Submit</button></p>');
                $('#results').html(html.join(''));
            } else {
                alert(data['message']);
                if (data['data']) {
                    console.log(JSON.stringify(data['data']));
                }
            }
        }
    });
}

function submitAdjudications(link) {
    let newUids = {};
    $('input[type=checkbox]:checked').each(function(idx, ob) {
        let nodes = $(ob).attr('name').split(/___/);
        let elementType = nodes[0];
        let recordId = nodes[1];
        let uid = nodes[2];
        if (uid && (elementType == 'mentorUid')) {
            if (typeof newUids[recordId] == 'undefined') {
                newUids[recordId] = [];
            }
            newUids[recordId].push(uid);
        }
    });
    if (newUids.length > 0) {
        presentScreen('Uploading...');
        $.post(link, { 'newUids': newUids }, function(json) {
            clearScreen();
            let data = JSON.parse(json);
            if (data['message']) {
                alert(data['message']);
            }
            $('#results').html('');
        });
    }
}
</script>";

function getTotalCount($ary) {
    $n = 0;
    foreach (array_values($ary) as $valueAry) {
        $n += count($valueAry);
    }
    return $n;
}

function makeAnswerTableRow($fieldLabel, $answerLabel, $positives, $n) {
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

function makeGeneralTableRow($title, $values, $units) {
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
        $html .= "<td>".REDCapManagement::pretty($values["mentees"], 0).$unitsWithSpace."</td>";
        $html .= "<td>".REDCapManagement::pretty($values["mentors"], 0).$unitsWithSpace."</td>";
    } else {
        $html .= "<td colspan='2' class='centered'>".$values.$unitsWithSpace."</td>";
    }
    $html .= "</tr>";
    return $html;
}

function getElementCount($elements) {
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