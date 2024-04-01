<?php

namespace Vanderbilt\CareerDevLibrary;

use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once dirname(__FILE__)."/preliminary.php";
require_once dirname(__FILE__)."/base.php";
require_once dirname(__FILE__)."/../small_base.php";
require_once dirname(__FILE__)."/../classes/Autoload.php";

if(isset($_REQUEST['uid']) && MMAHelper::getMMADebug()){
    $username = REDCapManagement::sanitize($_REQUEST['uid']);
    $uidString = "&uid=$username";
} else {
    $username = (Application::getProgramName() == "Flight Tracker Mentee-Mentor Agreements") ? NEW_HASH_DESIGNATION : Application::getUsername();
    $uidString = "";
}

if ($_POST['action'] == "getMenteeHTML") {
    $allMentees = [];
    $pids = Sanitizer::sanitizeArray($_POST['pids'] ?? []);
    foreach ($pids as $currPid) {
        $time1 = microtime(TRUE);
        $currToken = Application::getSetting("token", $currPid);
        $currServer = Application::getSetting("server", $currPid);
        if (
                $currToken && $currServer
                && REDCapManagement::isActiveProject($currPid)
                && !CareerDev::isCopiedProject($currPid)
        ) {
            $currMentees = MMAHelper::getMentees("all", $username, $currToken, $currServer);
            if (!empty($currMentees) && !empty($currMentees["name"])) {
                $allMentees[$currPid] = $currMentees;
            }
        }
        $time2 = microtime(TRUE);
        if (isset($_GET['test'])) {
            echo "Downloading $currPid took ".($time2 - $time1)." seconds<br/>";
        }
    }

    $html = "";
    foreach ($allMentees as $currPid => $projectMentees) {
        $currToken = Application::getSetting("token", $currPid);
        $currServer = Application::getSetting("server", $currPid);
        $projectLink = Application::getMenteeAgreementLink($currPid).$uidString;
        if ($currToken && $currServer && $projectLink && Application::has("mentoring_agreement", $currPid)) {
            $projectTitle = Download::projectTitle($currPid);
            $projectTitle = preg_replace("/^Flight Tracker - /", "", $projectTitle);
            $menteeEmails = Download::emails($currToken, $currServer);
            $completes = Download::oneFieldWithInstances($currToken, $currServer, "mentoring_agreement_complete");
            $completeUserids = Download::oneFieldWithInstances($currToken, $currServer, "mentoring_userid");
            $completeDates = Download::oneFieldWithInstances($currToken, $currServer, "mentoring_last_update");
            $referencedProject = "$projectTitle<br/><span class='smaller'>(pid $currPid)</span>";
            $numRowsForProject = count($projectMentees["name"]);
            foreach ($projectMentees["name"] as $recordId => $menteeName) {
                $menteeUserid = $projectMentees["uid"][$recordId] ?? "";
                if (empty($completes[$recordId])) {
                    $mark = "<span class='red bolded' title='missing'>X</span>";
                } else {
                    $userInstances = [];
                    $latestTs = 0;
                    foreach ($completes[$recordId] as $instance => $formStatus) {
                        if ($completeUserids[$recordId][$instance] == $menteeUserid) {
                            $userInstances[] = $instance;
                            $instanceTs = $completeDates[$recordId][$instance] ? strtotime($completeDates[$recordId][$instance]) : "";
                            if ($instanceTs && ($instanceTs > $latestTs)) {
                                $latestTs = $instanceTs;
                            }
                        }
                    }
                    $numCompleteByUser = count($userInstances);
                    if ($numCompleteByUser === 0) {
                        $mark = "<span class='red bolded' title='Not completed'>X</span>";
                    } else if ($latestTs > 0) {
                        $date = date("M d Y", $latestTs);
                        $mdy = date("m-d-Y", $latestTs);
                        $mark = "<span class='green bolded smaller' title='Last entered on $mdy'>$date</span>";
                    } else {
                        $mark = "<span class='red bolded' title='No record'>X</span>";
                    }
                }

                $isFirstRowForProject = ($recordId == array_keys($projectMentees["name"])[0]);
                $menteeEmail = $menteeEmails[$recordId] ?? "";
                $html .= "<tr>";
                if ($isFirstRowForProject) {
                    $html .= "<td class='grey' rowspan='$numRowsForProject'>$referencedProject</td>";
                }
                $html .= "<td class='centered'>$mark</td>";
                $html .= "<td><strong>$menteeName</strong>";
                if ($menteeEmail) {
                    $html .= "<span class='smaller'><br/>(<a href='mailto:$menteeEmail?subject=Flight Tracker Mentee-Mentor Agreement'>$menteeEmail</a>)</span>";
                }
                $html .= "</td>";
                if ($isFirstRowForProject) {
                    $id = $currPid."___link";
                    $html .= "<td rowspan='$numRowsForProject'><input id='$id' type='text' style='max-width: 250px; margin-right: 5px; margin-left: 5px;' readonly='readonly' value='$projectLink' onclick='this.select();' /><span class='smaller'><a href='javascript:;' onclick='copyToClipboard($(\"#longurl\"));'>Copy</a></span></td>";
                }
                $html .= "</tr>";
            }
        } else {
            $html .= "<tr><td class='centered' colspan='4'>Could not access pid $currPid</td></tr>";
        }
    }

    echo $html;
    exit;
}

require_once dirname(__FILE__).'/_header.php';

list($firstName, $lastName) = MMAHelper::getNameFromREDCap($username, $token, $server);
$jqueryLink = Application::link("mentor/js/jquery.min.js");
$thisLink = Application::link("this").$uidString;
$imageLink = Application::link("mentor/img/loading.gif");
?>

<script src="<?= $jqueryLink ?>"></script>

<style>
    .centered { text-align: center; }
    .smallest { font-size: 0.5em; }
    .smaller { font-size: 0.75em; }
    input[readonly=readonly] { background-color: #bbbbbb; }
    .red { color: #ea0e0ecc; }
    .green { color: #35482f; }
    .bolded { font-weight: bold; }
    .grey { color: #444444; }
    progress { color: #17a2b8; }
    th,td { text-align: center; padding: 4px; font-size: 0.8em; line-height: 1em; }
    body {
        font-family: europa, sans-serif !important;
        letter-spacing: -0.5px;
        font-size: 1.3em;
    }
    .bg-light { background-color: #ffffff!important; }
    tbody tr td,tbody tr th { border: #888888 solid 1px; }
</style>

<script>
    function copyToClipboard(element) {
        const text = $(element).text() ? $(element).text() : $(element).val();
        navigator.clipboard.writeText(text);
    }

    function makeMentees(pids, startI, batchSize) {
        const batchPids = [];
        const link = "<?= $thisLink ?>";
        for (let i=startI; i < startI + batchSize; i++) {
            if (pids.length > i) {
                batchPids.push(pids[i]);
            }
        }
        if (batchPids.length === 0) {
            return;
        }
        $.post(link, { action: 'getMenteeHTML', pids: batchPids, redcap_csrf_token: getCSRFToken() }, (tableHTML) => {
            console.log(tableHTML.length+" characters");
            if (tableHTML) {
                $('#mainTable tbody:last-child').append(tableHTML);
                $('#mainTable thead').show();
            }
            if (pids.length <= startI + batchSize) {
                if ($('#mainTable tbody tr').length === 0) {
                    $('#mainTable tbody').html("<tr><td class='centered' colspan='4'>No Mentees Found.</td></tr>");
                }
                $('#status').html("");
            } else {
                const perc = Math.round((startI + batchSize) * 100 / pids.length);
                $('#projectNum')
                    .val(startI + batchSize)
                    .html(" "+perc+"% ");
                makeMentees(pids, startI + batchSize, batchSize);
            }
        });
    }

    $(document).ready(() => {
        const waitingImage = "<p class='centered'><img style='width: 100px; height: 100px;' src='<?= $imageLink ?>' alt='Loading...' /></p>";
        const pids = <?= json_encode(Application::getActiveSourcePids()); ?>;
        const blankTable = "<table id='mainTable'><thead style='display: none;'><tr><th>Project</th><th class='smaller'>Agreement<br/>Last Updated</th><th>Mentee</th><th>Link to Agreement</th></tr></thead><tbody></tbody></table>";
        $('#status').html(waitingImage+"<p class='centered'>Looking for Mentees Across "+pids.length+" Projects...<br/><progress id='projectNum' value='0' max='"+pids.length+"'> 0% </progress></p>");
        $('#results').html(blankTable);
        makeMentees(pids, 0, 3);
    });
</script>

<?php

echo "<section class='bg-light'>";
echo "<div class='container'>";
echo "<div class='row'>";
echo "<div class='col-lg-12'>";
echo "<h1>$firstName $lastName's Mentees</h1>";
echo "<div id='results'></div>";
echo "<div id='status'></div>";
echo "</div></div></div></section>";