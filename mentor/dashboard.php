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
$numInvited = ["mentees" => MMAHelper::getElementCount($userids), "mentors" => MMAHelper::getElementCount($mentorUserids)];
$completedInitial = ["mentees" => [], "mentors" => []];
$completedFollowup = ["mentees" => [], "mentors" => []];
$timesToComplete = ["mentees" => [], "mentors" => []];
$numMentors = MMAHelper::getElementCount($mentorNames);
$names = Download::names($token, $server);

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
$metadata = Download::metadata($token, $server);
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

$homeLink = Application::getMenteeAgreementLink($pid);
$addLink = Application::link("addMentor.php");
$configUrl = Application::link("mentor/config.php");
$redcapLookupUrl = Application::link("mentor/lookupREDCapUseridFromREDCap.php");
echo "<h2>Getting Started</h2>";
echo "<h3>Step 1: Configure</h3>";
echo "<p class='centered max-width'><a href='$configUrl'>Configure the Agreements for your institution and project</a>.</p>";
echo "<h3>Step 2: Get User IDs</h3>";
echo "<p class='centered max-width'>You will need to make sure you have REDCap user-ids for any mentees <strong>and</strong> for their mentors. Currently, you have $numMentees scholars/mentees, $numMenteeUserids user-ids for mentees, $numMentorNames mentor names, and $numMentorUserids user-ids for mentors. Input the mentee userid <strong>on each record's Identifiers form</strong>. Mentor names can be input using <a href='$addLink'>this tool</a> or manually input <strong>on each record's Manual Import form</strong>. Multiple mentor names and user-ids can be separated by commas.</p>";
echo "<p class='centered max-width'><a href='javascript:;' onclick='$(\"#useridLookup\").show();'>Lookup REDCap User IDs</a></p>";
echo "<div class='centered max-width' style='display: none; background-color: rgba(191,191,191,0.5);' id='useridLookup'>";
echo "<h4>Lookup a REDCap User ID</h4>";
echo "<p class='centered nomargin'>Please remember that some users might employ nicknames or maiden names.</p>";
echo "<p class='green' id='message'></p>";
echo "<p><label for='first_name'>First Name</label>: <input type='text' id='first_name' /><br>";
echo "<label for='last_name'>Last Name</label>: <input type='text' id='last_name' /><br>";
echo "<button onclick='lookupREDCapUserid(\"$redcapLookupUrl\", $(\"#message\")); return false;'>Look up name</button>";
echo "</div>";
echo "<h3>Step 3: Pass on the Link</h3>";
echo "<p class='centered max-width'><strong><a class='smaller' href='$homeLink'>$homeLink</a></strong><br>Pass along this link to any mentee or mentor that (A) has a REDCap userid and (B) is registered in your Flight Tracker as a Scholar/Mentee or a Primary Mentor (with a <a href='$addLink'>registered userid</a>). With this link, they can access their relevant mentoring information anytime.</p>";
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
