<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/base.php");

$numWeeks = 3;
$resourceField = "mentoring_local_resource";

if (isset($_POST['newUids'])) {
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
} else if (isset($_POST['updateMentors'])) {
    require_once(dirname(__FILE__)."/../small_base.php");
    $records = Download::recordIds($token, $server);
    $names = MMAHelper::downloadAndMakeNames($token, $server);
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

$records = Download::recordIdsByPid($pid);
if (Application::isMSTP($pid)) {

    $instrument = "mstp_mentee_mentor_agreement";
    $downloadUrl = Application::link("mstp/downloadMMA.php");   // add record & instance
    $fields  = [
        "record_id",
        "mstpmma_mentor_link",
        "mstpmma_mentee_link",
        "mstpmma_mentor_initiate_date",
        "mstpmma_mentor_name",
        "mstpmma_mentee_comments_date",
        "mstpmma_mentee_signature_date",
        "mstpmma_mentor_signature_date",
    ];

    $names = Download::namesByPid($pid);
    $redcapData = Download::fieldsForRecordsByPid($pid, $fields, $records);
    $htmlRows = [];
    foreach ($redcapData as $row) {
        if (
            ($row['redcap_repeat_instrument'] == $instrument)
            && $row['mstpmma_mentor_link']
            && $row['mstpmma_mentee_link']
        ) {
            $recordId = $row['record_id'];
            $instance = $row['redcap_repeat_instance'];
            $timestamps = [];

            $html = "<tr>";
            $html .= "<th>{$names[$recordId]}<br/><a href='{$row['mstpmma_mentee_link']}'>Mentee Access</a></a></th>";
            $html .= "<th>{$row['mstpmma_mentor_name']}<br/><a href='{$row['mstpmma_mentor_link']}'>Mentor Access</th>";
            $html .= "<td>".(DateManagement::YMD2MDY($row['mstpmma_mentor_initiate_date']) ?: "Not started")."</td>";
            $html .= "<td>".(DateManagement::YMD2MDY($row['mstpmma_mentee_comments_date']) ?: "No Mentee Comments")."</td>";
            $html .= "<td><span class='nobreak'>Mentee: ".(DateManagement::YMD2MDY($row['mstpmma_mentee_signature_date']) ?: "Unsigned")."</span><br/><span class='nobreak'>Mentor: ".(DateManagement::YMD2MDY($row['mstpmma_mentor_signature_date']) ?: "Unsigned")."</span></td>";
            $html .= "<td><a href='$downloadUrl&record=$recordId&instance=$instance'>View Current Agreement</a></td>";
            $html .= "</tr>";

            if (empty($timestamps)) {
                $ts = time();
            } else {
                $ts = max($timestamps);
            }
            $htmlRows[$ts] = $html;
        }
    }
    krsort($htmlRows);

    echo "<h1>MSTP Mentoring Agreement Dashboard</h1>";
    if (empty($htmlRows)) {
        echo "<p class='centered max-width'>No one has initiated an agreement.</p>";
    } else {
        echo "<table class='centered max-width-1000 bordered'>";
        echo "<thead class='stickyGrey'><tr><th>Mentee</th><th>Mentor</th><th>Mentor Start Date</th><th>Mentee Comment Date</th><th>Signature Date(s)</th><th>View</th></tr></thead>";
        echo "<tbody>";
        echo implode("", array_values($htmlRows));
        echo "</tbody>";
        echo "</table>";
    }
    exit;
}
$userids = Download::userids($token, $server);
$mentorNames = Download::primaryMentors($token, $server);
$mentorUserids = Download::primaryMentorUserids($token, $server);
$numInvited = ["mentees" => MMAHelper::getElementCount($userids), "mentors" => MMAHelper::getElementCount($mentorUserids)];
$completedInitial = ["mentees" => [], "mentors" => []];
$completedFollowup = ["mentees" => [], "mentors" => []];
$timesToComplete = ["mentees" => [], "mentors" => []];
$numMentors = MMAHelper::getElementCount($mentorNames);
$names = MMAHelper::downloadAndMakeNames($token, $server);

$numMenteeUserids = 0;
$numMentees = 0;
$numMentorNames = 0;
$numMentorUserids = 0;
foreach (array_keys($names) as $recordId) {
    $numMentees++;
    if ($userids[$recordId]) {
        $numMenteeUserids++;
    }
    if (isset($mentorNames[$recordId]) && $mentorNames[$recordId] && !empty($mentorNames[$recordId])) {
        $numMentorNames++;
    }
    if (isset($mentorUserids[$recordId]) && $mentorUserids[$recordId] && !empty($mentorUserids[$recordId])) {
        $numMentorUserids++;
    }
}

$selectFieldTypes = ["dropdown", "radio", "checkbox", ];
$metadata = MMAHelper::getMetadata($pid);
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

$recordsWithMenteeResponse = [];
$values = [];
foreach ($records as $recordId) {
    $redcapData = Download::fieldsForRecordsByPid($pid, $agreementFields, [$recordId]);

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
    foreach ($redcapData as $row) {
        if ($row['redcap_repeat_instrument'] == "mentoring_agreement") {
            $useridOfRespondant = $row['mentoring_userid'];
            $menteeUserids = MMAHelper::getMenteeUserids($userids[$recordId]);
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
                $completedInitial[$respondantClass][] = $recordId;
                if (!isset($recordsWithMenteeResponse[$recordId])) {
                    $recordsWithMenteeResponse[$recordId] = $names[$recordId];
                }
                $isFirstMentee = FALSE;
            } else if ($isFirstMentor && ($respondantClass == "mentors")) {
                $completedInitial[$respondantClass][] = $recordId;
                $isFirstMentor = FALSE;
            } else if (isset($completedFollowup[$respondantClass])) {
                $completedFollowup[$respondantClass][] = $recordId;
            }
            if (isset($timesToComplete[$respondantClass]) && $row['mentoring_start'] && $row['mentoring_end']) {
                $timesToComplete[$respondantClass][] = (strtotime($row['mentoring_end']) - strtotime($row['mentoring_start'])) / 60;
            }
        }
    }
}
$missingMentors = ["initial" => [], "followup" => []];
foreach ($completedInitial["mentees"] as $recordId) {
    if (!in_array($recordId, $completedInitial["mentors"])) {
        $missingMentors["initial"][] = $recordId;
    }
}
foreach ($completedFollowup["mentees"] as $recordId) {
    if (!in_array($recordId, $completedFollowup["mentors"])) {
        $missingMentors["followup"][] = $recordId;
    }
}

if ($numMentors > $numInvited["mentors"]) {
    $link = Application::link("this");
    echo "<p class='centered'><button onclick='checkForNewMentorUserids(\"$link\");'>Update Mentor User-ids</button></p>";
    echo "<div id='results'></div>";
}

echo "<h1>Mentoring Agreement Responses</h1>";

echo "<h2>Submissions</h2>";
echo "<table class='centered bordered max-width'>";
echo "<thead><tr>";
echo "<th>Measure</th>";
echo "<th>For Mentees</th>";
echo "<th>For Mentors</th>";
echo "</tr></thead>";
echo "<tbody>";
echo MMAHelper::makeGeneralTableRow("Number of Mentors Filled In", $numMentors, "Mentors");
echo MMAHelper::makeGeneralTableRow("People with Unique User-ids Involved", $numInvited, "Individuals");
echo MMAHelper::makeGeneralTableRow("Number Completed Initial<br>Mentee-Mentor Agreement", $completedInitial, "", FALSE, $names, $mentorNames);
echo MMAHelper::makeGeneralTableRow("Number of Mentors Who Haven't<br>Filled Out a Mentee-Mentor Agreement", ["mentees" => [], "mentors" => $missingMentors['initial']], "", FALSE, $names, $mentorNames);
echo MMAHelper::makeDropdownTableRow($pid, $event_id, "View Responses", $recordsWithMenteeResponse);
echo "</tbody>";
echo "</table>";

echo "<h2>Spoof a User</h2>";
echo "<p class='centered max-width'>Only users with REDCap user-ids can access Mentee-Mentor Agreements. Authorized Fight Tracker users can 'spoof' a user to see what their view looks like - that is, they can see what a user's view looks like. Select a name from the below dropdown to see more.</p>";
$userids = Download::userids($token, $server);
$names = Download::names($token, $server);
$combos = [];
foreach ($userids as $recordId => $userid) {
    if ($userid) {
        $combos[$userid] = $names[$recordId] ?? "Unknown name";
    }
}
if (!empty($combos)) {
    $introUrl = Application::link("mentor/intro.php");
    echo "<p class='centered max-width'><label for='uid'>User: </label><select id='uid' onchange='if ($(this).val()) { location.href = \"$introUrl&uid=\"+encodeURIComponent($(this).val()); }'>";
    echo "<option value=''>---SELECT---</option>";
    foreach ($combos as $userid => $name) {
        echo "<option value='$userid'>$name ($userid)</option>";
    }
    echo "</select></p>";
} else {
    echo "<p class='centered max-width'>No user-ids have been entered.</p>";
}

echo "<script>
function checkForNewMentorUserids(link) {
    $('#results').html('');
    presentScreen('Checking...');
    $.post(link, { 'redcap_csrf_token': getCSRFToken(), 'updateMentors': true }, function(json) {
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
        $.post(link, { 'redcap_csrf_token': getCSRFToken(), 'newUids': newUids }, function(json) {
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
