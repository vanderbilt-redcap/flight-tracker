<?php

use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/debug.php");
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../Application.php");


function authenticate($username, $menteeRecords) {
    if (!is_array($menteeRecords)) {
        $menteeRecords = [$menteeRecords];
    }
    if (!$username) {
        # login to REDCap
        require APP_PATH_DOCROOT.'/Config/init_functions.php';
        System::initGlobalPage();
    } else {
        $token = Application::getSetting("token", $_GET['pid']);
        $server = Application::getSetting("server", $_GET['pid']);
        $userids = Download::userids($token, $server);
        $mentorUserids = Download::primaryMentorUserids($token, $server);

        $validUserids = [];
        foreach ($menteeRecords as $menteeRecord) {
            if (!$userids[$menteeRecord]) {
                $userids[$menteeRecord] = [];
            } else {
                $userids[$menteeRecord] = [$userids[$menteeRecord]];
            }
            if (!$mentorUserids[$menteeRecord]) {
                $mentorUserids[$menteeRecord] = [];
            }
            $validUserids = array_unique(array_merge($validUserids, $userids[$menteeRecord], $mentorUserids[$menteeRecord]));
        }
        if (!in_array($username, $validUserids) && (!DEBUG || !in_array($_GET['uid'], $validUserids))) {
            die("You do not have access to this record!");
        }
    }
}

function makePercentCompleteJS() {
    $html = "<script>
    function getPercentComplete() {
        var numer = 0;
        var denom = 0;
        var seen = {};
        $('input').each(function(idx, ob) {
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

function makePriorInstancesDropdown($instances, $currInstance) {
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

function fieldValuesAgree($set1, $set2) {
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

function scheduleEmail($to, $from, $subject, $message, $datetime) {
    $ts = strtotime($datetime);
    if (DEBUG) {
        $subject = $to.": ".$subject." on ".$datetime;
        \REDCap::email("scott.j.pearson@vumc.org", $from, $subject, $message);
    }
    // TODO
}

function isMentee($recordId, $username) {
    global $token, $server;
    $userids = Download::userids($token, $server);
    if (strtolower($userids[$recordId]) == strtolower($username)) {
        return TRUE;
    } else {
        return FALSE;
    }
}

function getNotesFields($fields) {
    $notesFields = [];
    foreach ($fields as $field) {
        if (preg_match("/_notes$/", $field)) {
            $notesFields[] = $field;
        }
    }
    return $notesFields;
}

function getLatestRow($recordId, $usernames, $redcapData) {
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

function getRecordsAssociatedWithUserid($userid, $token, $server) {
    $menteeUserids = Download::userids($token, $server);
    $allMentorUserids = Download::primaryMentorUserids($token, $server);

    $menteeRecordIds = [];
    foreach ($menteeUserids as $recordId => $menteeUserid) {
        if ($userid == $menteeUserid) {
            $menteeRecordIds[] = $recordId;
        }
    }
    foreach ($allMentorUserids as $recordId => $mentorUserids) {
        if (in_array($userid, $mentorUserids)) {
            $menteeRecordIds[] = $recordId;
        }
    }
    return $menteeRecordIds;
}

function getMenteesAndMentors($menteeRecordId, $userid, $token, $server) {
    $menteeUserids = Download::userids($token, $server);
    $allMentors = Download::primaryMentors($token, $server);
    $allMentorUserids = Download::primaryMentorUserids($token, $server);

    $menteeUid = $menteeUserids[$menteeRecordId];
    $mentorUids = $allMentorUserids[$menteeRecordId];
    $myMentees = [];
    $myMentors = [];
    $myMentees["name"] = Download::menteesForMentor($token, $server, $userid);
    if ($userid == $menteeUid) {
        # Mentee
        $myMentors["name"] = $allMentors[$menteeRecordId];
        $myMentors["uid"] = $allMentorUserids[$menteeRecordId];
    } else if (in_array($userid, $mentorUids)) {
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

function cleanMentorName($mentor) {
    $mentor = str_replace(', PhD', '', $mentor);
    $mentor = str_replace('N/A', '', $mentor);
    $mentor = str_replace(',', '', $mentor);
    $mentor = str_replace('PhD', '', $mentor);
    $mentor = str_replace('/', ' and ', $mentor);
    $mentor = str_replace('none (currently)', '', $mentor);
    $mentor = str_replace('no longer in academia', '', $mentor);
    return $mentor;
}

function filterMetadata($metadata, $skipFields = TRUE) {
    $fieldsToSkip = ["mentoring_userid", "mentoring_last_update"];
    $metadata = REDCapManagement::filterMetadataForForm($metadata, "mentoring_agreement");
    foreach ($metadata as $row) {
        if (!in_array($row['field_name'], $fieldsToSkip) || !$skipFields) {
            $newMetadata[] = $row;
        }
    }
    return $newMetadata;
}

function getPercentComplete($row, $metadata) {
    $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
    $notesFields = getNotesFields($metadataFields);
    $numer = 0;
    $denom = count($metadataFields) - count($notesFields);

    foreach ($metadata as $metadataRow) {
        if (!in_array($metadataRow['field_name'], $notesFields)) {
            if ($metadataRow['field_type'] == "checkbox") {
                $denom--;
            } else if ($row[$metadataRow['field_name']]) {
                $numer++;
            }
        }
    }

    return ceil($numer * 100 / $denom);
}

function pullInstanceFromREDCap($redcapData, $instance) {
    foreach ($redcapData as $redcapRow) {
        if (($redcapRow['redcap_repeat_instrument'] == "mentoring_agreement") && ($redcapRow["redcap_repeat_instance"] == $instance)) {
            return $redcapRow;
        }
    }
    return [];
}

function getNameFromREDCap($username, $token = "", $server = "") {
    if ($token && $server) {
        $firstNames = Download::firstnames($token, $server);
        $lastNames = Download::lastnames($token, $server);
        $userids = Download::userids($token, $server);
        foreach ($userids as $recordId => $userid) {
            if ($userid == $username) {
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
    return ["", ""];
}

function getMaxInstanceForUserid($rows, $recordId, $userid) {
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

function makePopupJS() {

    $resources = [];
    // TODO Additional, Custom Resources - put at top
    $resources[] = "Huskins WC, Silet K, Weber-Main AM, Begg MD, Fowler VG, Jr., Hamilton J and Fleming M. Identifying and aligning expectations in a mentoring relationship. <i>Clinical and translational science</i>. 2011;4:439-47. https://doi.org/10.1111/j.1752-8062.2011.00356.x";
    $resources[] = "Ramanan RA, Taylor WC, Davis RB and Phillips RS. Mentoring matters. Mentoring and career preparation in internal medicine residency training. <i>J Gen Intern Med</i>. 2006;21:340-5. https://doi.org/10.1111/j.1525-1497.2006.00346_1.x";
    $resources[] = "Ramanan RA, Phillips RS, Davis RB, Silen W and Reede JY. Mentoring in medicine: keys to satisfaction. <i>The American journal of medicine</i>. 2002;112:336-41. https://doi.org/10.1016/s0002-9343(02)01032-x";
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

    foreach ($resources as $i => $resource) {
        $resource = REDCapManagement::fillInLinks($resource);
        $resource = "<li>$resource</li>";
        $resources[$i] = $resource;
    }

    $close = "<div style='text-align: right; font-size: 12px; margin: 0;'><a href='javascript:;' onclick='$(this).parent().parent().slideUp(\"fast\");' style='text-decoration: none; color: black;'>X</a></div>";

    $html = "";

    $html .= "<div class='characteristics' id='mentor_characteristics' style='display: none;'>$close<h3>Characteristics of Successful Mentor</h3>
<ul style='list-style-type:disc'>
<li>Effectively provide intellectual guidance in the scientific topics of her/his strength, to directly broaden the Mentee’s scientific, and overall academic, proficiency</li>
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
function getDateToRemind($data, $recordId, $instance) {
    $dateToRevisit = getDateToRevisit($data, $recordId, $instance);
    if (REDCapManagement::isDate($dateToRevisit)) {
        $tsToRevisit = strtotime($dateToRevisit);
        if ($tsToRevisit) {
            return adjustDate($tsToRevisit, -1);
        }
    }
    return "";
}

# returns MDY
function getDateToRevisit($data, $recordId, $instance) {
    $monthsInFuture = REDCapManagement::findField($data, $recordId, "mentoring_revisit", "mentoring_agreement", $instance);
    $lastUpdate = REDCapManagement::findField($data, $recordId, "mentoring_last_update", "mentoring_agreement", $instance);
    $ts = strtotime($lastUpdate);
    if (!$ts || !$lastUpdate) {
        $ts = time();
    }
    if ($monthsInFuture) {
        return adjustDate($ts, $monthsInFuture);
    } else {
        return "An Unspecified Date";
    }
}

function fixDate($month, $day, $year) {
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
function adjustDate($ts, $monthsInFuture) {
    $month = date("m", $ts);
    $year = date("Y", $ts);
    $day = date("d", $ts);
    $month += $monthsInFuture;
    return fixDate($month, $day, $year);
}

function makeSurveyHTML($partners, $row, $metadata) {
    $html = "";
    $imageLink = Application::link("mentor/img/temp_image.jpg");
    $scriptLink = Application::link("mentor/vendor/jquery.easy-pie-chart/dist/jquery.easypiechart.min.js");
    $percComplete = getPercentComplete($row, $metadata);

    $html .= "
<p><div>
    <div style='float: right;margin-left: 39px;width: 147px;font-family: proxima-nova;margin-top: 16px;'>
        <span class='chart' data-percent='$percComplete'>
            <span class='percent'></span>
        </span>
        <div style='text-align: center;margin-top: 0px;font-size: 13px;width: 115px;'>(complete)</div>
    </div>
</div></p>";
    $html .= "<p>Welcome to the Mentoring Agreement. The first step to completing the Mentoring Agreement is to reflect on what is important to you in a successful mentor-mentee relationship. Through a series of questions on topics such as meetings, communication, research, and approach to scholarly products, to name a few, this survey will help guide you through that process and provide you with a tool to capture your thoughts. The survey should take about 30 minutes to complete. Your mentor(s)/mentee ($partners) will also complete a survey.</p>";
    $html .= "<p><img src='$imageLink' style='float: left; margin-right: 39px;width: 296px;'>Once both of you have completed the process, you will be able to see each of your surveys side by side to see where you agree or disagree. At that time, we recommend scheduling a time to meet with each other to discuss those items where you disagree so that you can come to an agreement.  Once you come to an agreement on each question, a final Mentor-Mentee agreement will be produced that you can refer to as needed. We encourage mentors and mentees to revisit this document on a regular basis, with a suggestion of annually.</p>";

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

function getMySurveys($username, $token, $server, $currentRecordId, $currentInstance) {
    $redcapData = Download::fields($token, $server, ["record_id", "mentoring_userid", "mentoring_last_update"]);
    $names = Download::names($token, $server);
    $userids = Download::userids($token, $server);
    $surveyLocations = [];
    foreach ($redcapData as $row) {
        if(($row['mentoring_userid'] == $username) && (($row['record_id'] != $currentRecordId) || ($row['redcap_repeat_instance'] != $currentInstance))) {
            if ($userids[$row['record_id']] == $username) {
                $menteeName = "yourself";
            } else {
                $menteeName = "mentee ".$names[$row['record_id']];
            }
            $surveyLocations[$row['record_id'].":".$row['redcap_repeat_instance']] = "For ".$menteeName." (".$row['mentoring_last_update'].")";
        }
    }
    return $surveyLocations;
}

function makePriorNotesAndInstances($redcapData, $notesFields, $menteeRecordId, $instance) {
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

function getUseridsForRecord($token, $server, $recordId, $recipientType) {
    $userids = [];
    if (in_array($recipientType, ["mentee", "all"])) {
        $menteeUserids = Download::userids($token, $server);
        if ($menteeUserids[$recordId]) {
            $userids = array_unique(array_merge($userids, $menteeUserids[$recordId]));
        }
    }
    if (in_array($recipientType, ["mentors", "all"])) {
        $mentorUserids = Download::primaryMentorUserids($token, $server);
        if ($mentorUserids[$recordId]) {
            $userids = array_unique(array_merge($userids, $mentorUserids[$recordId]));
        }
    }
    return $userids;
}

function getEmailAddressesForRecord($userids) {
    $emails = [];
    foreach ($userids as $userid) {
        $email = REDCapManagement::getEmailFromUseridFromREDCap($userid);
        if ($email) {
            $emails[] = $email;
        }
    }
    return array_unique($emails);
}

function makeCommentJS($username, $menteeRecordId, $instance, $priorNotes) {
    $html = "";
    $uidString = "";
    if (isset($_GET['uid'])) {
        $uidString = "&uid=$username";
    }
    $url = $_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
    if (preg_match("/index_menteeview/", $url)) {
        $verticalOffset = 1000;
    } else {
        $verticalOffset = 50;
    }

    $html .="
<script>
    var currcomment = '0';
    var priorNotes = ".json_encode($priorNotes).";

    function minutes_with_leading_zeros(dt) {
        return (dt.getMinutes() < 10 ? '0' : '') + dt.getMinutes();
    }
  
    showallcomments = function() {
        $('tr').each(function() {
            let id = $(this).attr('id')
            if (id && id.match(/-tr$/)) {
                console.log(id)
                showcomment(id, false)
            }
        })
    }

    dounbindenter=function(){
        $(document).keypress(function(e){
            if (e.which == 13){
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
        let commentcontent = '<div style=\"position: relative;height: 250px;\"><div class=\"closecomments\"><span style=\"float:left;color: #000000;font-weight: 700;font-size: 12px;margin-left: 6px;\">Notes/comments:</span><a style=\"float:right;\" href=\"javascript:$(\'.fauxcomment\').css(\'display\',\'none\');dounbindenter()\"><img src=\"".Application::link("mentor/images/x-circle.svg")."\"></a></div><div id=\"'+fieldName+'-lcomments\" class=\"listofcomments\" style=\"position: absolute;bottom: 0;height: 220px;display: inline-block;overflow: scroll;\">';

        for(var line of priorNote.split(/\\n/)) {
            if(line != ''){
                commentcontent += '<div class=\"acomment\">'+line+'</div>';
            }
        }

        if (insert_comment) {
            commentcontent += '</div></div><div class=\"insertcomment\"><input id=\"addcomment\" type=\"text\" placeholder=\"add comment...\"><span><a href=\"javascript:addcomment(\'' + servicerequest_id + '\')\"><img src=\"".Application::link("mentor/images/at-sign.svg")."\" style=\"height: 18px;margin-left: 8px;\"></a></span></div>';
            //bind ENTER key to comment
            $(document).keypress(function(e){
                if (e.which == 13){
                    addcomment(servicerequest_id);
                    return false;
                }
            });
        }
        $('.fauxcomment').css('top', offsettop + 'px').css('left', offsetleft + 'px').html(commentcontent);
        $('.fauxcomment').css('display', 'inline-block');

        currcomment = servicerequest_id;
        $('.acomment:odd').css('background-color', '#eceff5');
        var element = document.getElementById(fieldName+'-lcomments'); //scrolls to bottom
        if (element) {
            element.scrollTop = element.scrollHeight;
        }
    }

    saveagreement=function(){
        let serialized = $('#tsurvey').serialize()
            .replace(/exampleRadiosh/g, '')
            .replace(/exampleChecksh/g, '')
            .replace(/=on/g, '=1')
            .replace(/=off/g, '=0');
        $.ajax({
            url: '".Application::link("mentor/_agreement_save.php").$uidString."',
            type : 'POST',
            //dataType : 'json', // data type
            data :  'record_id=$menteeRecordId&redcap_repeat_instance=$instance&'+serialized,
            success : function(result) {
                console.log(result);
                $('.sweet-modal-overlay').remove();
                $.sweetModal({
                    content: 'We\'ve saved your agreement. You can update your responses or return to Flight Tracker. Thank you!',
                    icon: $.sweetModal.ICON_SUCCESS
                });
            },
            error: function(xhr, resp, text) {
                console.log(xhr, resp, text);
            }
        });
    }

    addcomment = function(servicerequest_id) {
        $('#' + servicerequest_id + ' .tcomments .timestamp').remove();
        let commentText = $('#addcomment').val();
        if (commentText) {
            let d = new Date();
            let today = (d.getMonth() + 1)+'-'+d.getDate()+'-'+d.getFullYear();
            let latestcomment = commentText + '<span class=\"timestamp\">($username) '+today+' ' + d.getHours() + ':' + minutes_with_leading_zeros(d) + '</span>';
            $('<div class=\"acomment\">' + latestcomment + '</div>').appendTo('.listofcomments');
            $('#' + servicerequest_id + ' .tcomments a').html(commentText);
            $('#' + servicerequest_id + ' .tcomments a').after('<span class=\"timestamp\">($username) '+today+' ' + d.getHours() + ':' + minutes_with_leading_zeros(d) + '</span>');
            $('#addcomment').val('');
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
            $.post('".Application::link("mentor/change.php").$uidString."', {
                userid: '$username',
                type: 'notes',
                record: '$menteeRecordId',
                instance: '$instance',
                field_name: notesFieldName,
                value: latestcomment
            }, function(html) {
                console.log(html);
            });
        }
    }
    
    function getLinkForEntryPage() {
        return '".Application::link("mentor/index.php")."';
    }
    
    function scheduleMentorEmail(menteeRecord, menteeName) {
        let link = getLinkForEntryPage();
        let subject = menteeName+'\'s Mentoring Agreement';
        let paragraph1 = '<p>Your mentee ('+menteeName+') has completed an initial mentoring agreement and would like you to review the following Mentor Agreement.</p>';
        let paragraph2 = '<p><a href=\"'+link+'\">'+link+'</p>';
        let message = paragraph1 + paragraph2;
        scheduleEmail('mentor', menteeRecord, subject, message, 'now');
    }

    function scheduleMenteeEmail(menteeRecord, menteeName) {
        let link = getLinkForEntryPage();
        let subject = 'Your Mentoring Agreement';
        let paragraph1 = '<p>'+menteeName+',</p><p>Your mentor(s) have/has completed their Mentoring Agreement. You may view the completed agreement at the link below. It is highly recommended that you discuss the agreement face-to-face soon.</p>';
        let paragraph2 = '<p><a href=\"'+link+'\">'+link+'</p>';
        let message = paragraph1 + paragraph2;
        scheduleEmail('mentee', menteeRecord, subject, message, 'now');
    }

    function scheduleReminderEmail(menteeRecord, menteeName, dateToSend) {
        let link = getLinkForEntryPage();
        let subject = 'Reminder: '+menteeName+'\'s Mentoring Agreement';
        let paragraph1 = '<p>Your mentee ('+menteeName+') has completed an initial mentoring agreement and would like you to review the following Mentor Agreement.</p>';
        let paragraph2 = '<p><a href=\"'+link+'\">'+link+'</p>';
        let message = paragraph1 + paragraph2;
        scheduleEmail('all', menteeRecord, subject, message, dateToSend);
    }
    
    function scheduleEmail(recipientType, menteeRecord, subject, message, dateToSend) {
        var datetimeToSend = dateToSend+' 09:00';
        if (dateToSend == 'now') {
            datetimeToSend = 'now';
        }
        $.post('".Application::link("mentor/schedule_email.php").$uidString."',
            { menteeRecord: menteeRecord, recipients: recipientType, subject: subject, message: message, datetime: datetimeToSend },
            function(html) {
            console.log(html);
        });
    }

</script>";
    return $html;
}

function makeNotesHTML($field, $redcapData, $recordId, $instance, $notesFields) {
    $notesField = $field."_notes";
    $html = "";
    if (in_array($notesField, $notesFields)) {
        $html .= "<td class='tcomments'>\n";
        $notesData = REDCapManagement::findField($redcapData, $recordId, $notesField, "mentoring_agreement", $instance);
        if ($notesData == "") {
            $html .= "<a href='javascript:void(0)' onclick='showcomment($(this).closest(\"tr\").attr(\"id\"), true)'>add note</a>\n";
        } else {
            $notesLines = explode("\n", $notesData);
            $html .= "<a href='javascript:void(0)' onclick='showcomment($(this).closest(\"tr\").attr(\"id\"), true)'><div class='tnote'>".$notesLines[count($notesLines) - 1]."</div></a>\n";
        }
        $html .= "</td>\n";
    }
    return $html;
}
function makePrefillHTML($surveysAvailableToPrefill, $uidString = "") {
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
                console.log(json);
                let data = JSON.parse(json);
                clearAll();
                for (let field in data) {
                    let value = data[field];
                    if (field.match(/___/)) {
                        let b = field.split(/___/);
                        let checkboxField = b[0];
                        let checkboxValue = b[1];
                        let fieldSel = '#exampleRadiosh'+checkboxField+'___'+checkboxValue;
                        console.log('Setting '+fieldSel+' with '+value);
                        if ((value === 0) || (value === '0') || (value === '')) {
                            $('#exampleChecksh'+checkboxField+'___'+checkboxValue).attr('checked', false);
                        } else if ((value === 1) || (value === '1')) {
                            $('#exampleChecksh'+checkboxField+'___'+checkboxValue).attr('checked', true);
                        } else {
                            $.sweetModal('Invalid check value '+value);
                        }
                    } else if (value !== '') {
                        let fieldSel = '#exampleRadiosh'+field+'___'+value;
                        console.log('Checking '+fieldSel);
                        $(fieldSel).attr('checked', true);
                    }
                }
                $(sel).val('');
            });
        }
    }

</script>";
    return $html;
}

function getEmailFromREDCap($userid) {
    $sql = "select user_email from redcap_user_information WHERE username = '".db_real_escape_string($userid)."'";
    $q = db_query($sql);
    while ($row = db_fetch_assoc($q)) {
        if ($row['user_email']) {
            return $row['user_email'];
        }
    }
    return "";
}

function beautifyHeader($str) {
    $str = preg_replace("/Career and Professional Development/i", "Development", $str);
    $str = preg_replace("/Approach to Scholarly Products/i", "Scholarship", $str);
    $str = preg_replace("/Financial Support/i", "Financials", $str);
    return $str;
}

function isTestServer() {
    return (SERVER_NAME == "redcaptest.vanderbilt.edu");
}

function makeReminderJS($from) {
    $html = "";
    $html .= "<script>

    function makeList(names) {
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
                                scheduleEmail('mentor', recordId, 'Mentor Agreement with '+menteeName, note, 'now');
                            } else if (!note) {
                               $.sweetModal('Error! No note specified! No email sent!');
                            }
                        }
                    }
                }
            });
        } else {
            $.sweetModal('No userid available for '+listOfMentorNames+'.');
        }
    }
    </script>";
    return $html;
}