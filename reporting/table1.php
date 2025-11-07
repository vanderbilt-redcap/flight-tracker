<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\DataTables;
use \Vanderbilt\CareerDevLibrary\NIHTables;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Links;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$setupLink = Application::link("reporting/setupTable1.php");
if (isset($_POST['supertoken'])) {
    $supertoken = Sanitizer::sanitize($_POST['supertoken']);
    if (REDCapManagement::isValidSupertoken($supertoken)) {
        Application::saveSetting("supertoken", $supertoken, $pid);
        Application::saveSystemSetting("table1Pid", "");
    } else {
        echo "<p class='centered red max-width'>Invalid supertoken!</p>";
    }
} else if (isset($_POST['token'])) {
    $blankProjectToken = Sanitizer::sanitizeToken($_POST['token']);
    if ($blankProjectToken) {
        Application::saveSystemSetting("table1Pid", "");
        Application::saveSystemSetting("table1Token", $blankProjectToken);
        echo "<p class='centered max-width green'>Token saved. <a href='$setupLink'>Click here to set up the project</a>. Note: Clicking the link will <strong>overwrite</strong> the entire REDCap project associated with this token.</p>";
        exit;
    } else {
        echo "<p class='centered red max-width'>Invalid token!</p>";
    }
}

$table1Pid = Application::getTable1PID();
$table1Token = Application::getTable1Token();
if (
    !$table1Pid
    || (
        $table1Token
        && ($table1Pid != REDCapManagement::getPIDFromToken($table1Token, $server))
    )
) {
    if (Application::isPluginProject()) {
        echo "<p class='centered max-width'>You must enable the Table 1 project from a project with the Flight Tracker External Module enabled.</p>";
    } else if (Application::getSetting("supertoken", $pid)) {
        echo "<h1>NIH Training Table 1 Not Set Up</h1>";
        echo "<p class='centered max-width'>NIH Training Table 1 requires a special REDCap project to be set up on this server. This project will track <strong>all institutional data for all Flight Trackers</strong>. Would you like to set it up now? <button onclick='location.href = \"$setupLink\";'>Yes</button></p>";
    } else {
        $redcapAdminEmail = $homepage_contact_email ?? "";
        $link = Application::link("this", $pid);
        echo "<h1>You Need to Set Up a Project to Proceed</h1>";
        if ($table1Token && ($table1Pid != REDCapManagement::getPIDFromToken($table1Token, $server))) {
            echo "<p class='centered max-width red'>Your current token is invalid. Please set up your REDCap project and token for NIH Table 1 again.</p>";
        }
        echo "<p class='centered max-width'>NIH Training Table 1 requires a special REDCap project to be set up on this server. You have two options: Submitting a 32-character API token to a blank REDCap project <strong>-or-</strong> providing a 64-character 'supertoken' so that REDCap can create a blank project for you.</p>";
        echo "<h2>Option 1: Traditional API Token</h2>";
        echo "<ol class='max-width'>";
        echo "<li>Create a blank REDCap project. If you cannot do this, you may need to contact your <a href='mailto:$redcapAdminEmail'>REDCap Administrator</a> and ask her/him to set one up for you.</li>";
        echo "<li>Go to the User Rights page (from the left-hand toolbar) and give yourself API Import Rights and API Export Rights. If you do not see the User Rights page, you may need to contact your <a href='mailto:$redcapAdminEmail'>REDCap Administrator</a>.</li>";
        echo "<li>Go to the API page (from the left-hand toolbar) and generate an API Token for yourself.</li>";
        echo "<li>Copy the newly created API Token and paste it in the below field. <strong>This is not your current API Token but a token for a blank project.</strong> Then press Submit Token.</li>";
        echo "</ol>";
        echo "<form action='$link' method='POST'>";
        echo Application::generateCSRFTokenHTML();
        echo "<p class='centered'><label for='token'>32-Character API Token</label><br/><input type='text' style='width: 400px;' name='token' id='token' /></p>";
        echo "<p class='centered'><button>Submit Token</button></p>";
        echo "</form>";

        echo "<h2>Option 2: Supertoken</h2>";
        echo "<p class='centered max-width'>To acquire a 64-character REDCap supertoken, please contact your <a href='mailto:$redcapAdminEmail'>REDCap Administrator</a>.</p>";
        echo "<form action='$link' method='POST'>";
        echo Application::generateCSRFTokenHTML();
        echo "<p class='centered'><label for='supertoken'>64-Character REDCap Supertoken</label><br/><input type='text' style='width: 400px;' name='supertoken' id='supertoken' /></p>";
        echo "<p class='centered'><button>Submit Supertoken</button></p>";
        echo "</form>";
    }
    exit;
}

$columns = [
    [
        "data" => 'Date',
        "title" => 'Date',
        "orderable" => true,
        "searchable" => true
    ],
    [
        "data" => 'Input By',
        "title" => 'Source',
        "orderable" => true,
        "searchable" => true
    ],
    [
        "data" => 'Population',
        "title" => 'Population',
        "orderable" => true,
        "searchable" => true
    ],
    [
        "data" => 'Participating Department or Program',
        "title" => 'Participating Department or Program',
        "orderable" => true,
        "searchable" => true
    ],
    [
        "data" => 'Total Faculty',
        "title" => 'Total Faculty',
        "orderable" => false,
        "searchable" => false
    ],
    [
        "data" => 'Participating Faculty',
        "title" => 'Participating Faculty',
        "orderable" => false,
        "searchable" => false
    ],
    [
        "data" => 'Total Trainees',
        "title" => 'Total Trainees',
        "orderable" => false,
        "searchable" => false
    ],
    [
        "data" => 'Total Trainees Supported by any HHS Training Award',
        "title" => 'Total Trainees Supported by any HHS Training Award',
        "orderable" => false,
        "searchable" => false
    ],
    [
        "data" => 'Total Trainees with Participating Faculty',
        "title" => 'Total Trainees with Participating Faculty',
        "orderable" => false,
        "searchable" => false
    ],
    [
        "data" => 'Eligible Trainees with Participating Faculty',
        "title" => 'Eligible Trainees with Participating Faculty',
        "orderable" => false,
        "searchable" => false
    ],
    [
        "data" => 'TGE Trainees Supported by this Training Grant (Renewals / Revisions)',
        "title" => 'TGE Trainees Supported by this Training Grant (Renewals / Revisions)',
        "orderable" => false,
        "searchable" => false
    ],
    [
        "data" => 'Trainees Supported by this Training Grant (R90 Only Renewals / Revisions)',
        "title" => 'Trainees Supported by this Training Grant (R90 Only Renewals / Revisions)',
        "orderable" => false,
        "searchable" => false
    ],
    [
        "data" => 'Copy',
        "title" => '',
        "orderable" => false,
        "searchable" => false
    ],
];

$surveyLink = Application::getTable1SurveyLink();
if ($surveyLink) {
    $inputText = "";
} else {
    $inputText = "<a href='$setupLink'>You can set up this project here.</a>";
}
$nihLink = NIHTables::NIH_LINK;
$table1Link = Links::makeProjectHomeURL($table1Pid);
$accessMessage = " - you might not have access to this REDCap project";
$username = Application::getUsername();
$users = REDCapManagement::getUsersForProject($table1Pid);
if (!in_array($username, $users)) {
    $table1UserEmail = "";
    foreach ($users as $user) {
        if (REDCapManagement::isActiveUser($user)) {
            $table1UserEmail = REDCapManagement::getEmailFromUseridFromREDCap($user);
            break;
        }
    }
    if ($table1UserEmail) {
        $accessMessage = " - contact <a href='mailto:$table1UserEmail'>$table1UserEmail</a> for access";
    }
}

$copyStartCol = 3;
$copyEndCol = 11;
$copyHeaders = [];
for ($i = $copyStartCol; $i <= $copyEndCol; $i++) {
    $copyHeaders[] = $columns[$i]["title"];
}
$headersJSON = json_encode($copyHeaders);

echo "<h1>Institution's Training Table 1 Data</h1>";
echo "<p class='centered max-width'>Share the following link with Program Managers at your institution (they do not have to be Flight Tracker users) to fill out and share data. These program managers do <strong>not</strong> need to be users of this Flight Tracker project. <br/><input id='longurl' value='$surveyLink' onclick='this.select();' readonly='readonly' style='width: 90%; max-width: 450px; margin-right: 5px; margin-left: 5px;' /><span class='smaller'><a href='javascript:;' onclick='copyToClipboard($(\"#longurl\"));'>Copy</a></span></p>";
echo "<p class='centered max-width'>Available in each Flight Tracker project on your server, this page contains all <a href='$nihLink'>NIH Training Table 1</a> entries available for sharing (from <a href='$table1Link' target='_NEW'>the REDCap project at pid $table1Pid</a>$accessMessage). $inputText Use the Search feature to filter information, either by Source, Department/Program, or Population. You can also sort by the first four columns to prioritize entries. The Source's email address, if available, can be accessed by clicking on the name.</p>";
echo DataTables::makeIncludeHTML();
echo DataTables::makeMainHTML('reporting/getTable1.php', Application::getModule(), $columns, TRUE);
echo "<div class='alignright smaller'><a class='darkgreytext' href='javascript:;' onclick='copyTable();'>Copy Entire Table</a></div>";
echo "<script>
function copyTable() {
    const headers = $headersJSON;
    let html = '<table><thead><th>'+headers.join('</th><th>')+'</th></thead><tbody>';
    $('table#em-log-module-log-entries tbody').children('tr').each((idx, trOb) => {
        html += '<tr>';
        html += copyTDsForRow(trOb);
        html += '</tr>';
    });
    html += '</tbody></table>';
    copyHTML(html);
}

function copyHTML(html) {
    const spreadSheetRow = new Blob([html], {type: 'text/html'});
    navigator.clipboard.write([new ClipboardItem({'text/html': spreadSheetRow})])
}

function copyTDsForRow(trOb) {
    let html = '';
    $(trOb).children('td').each((idx, tdOb) => {
        if ((idx >= $copyStartCol) && (idx <= $copyEndCol)) {
            let value = '';
            if ($(tdOb).find('.value').length === 1) {
                value = $(tdOb).find('.value').html();
            } else {
                value = $(tdOb).html();
            }
            html += '<td>'+value+'</td>';
        }
    });
    return html;
}

function copyRow(trOb) {
    let headers = $headersJSON;
    $(trOb).children('td').each((idx, tdOb) => {
        if (idx === $copyStartCol - 1) {
            // replace with pre-doc vs. post-doc depending on row
            const value = $(tdOb).html();
            if (value) {
                for (let i=0; i < headers.length; i++) {
                    headers[i] = headers[i].replace(/Trainees/, value);                    
                }
            }
        }
    });
    const html = '<table><thead><th>'+headers.join('</th><th>')+'</th></thead><tbody><tr>' + copyTDsForRow(trOb) + '</tr></tbody></table>';
    copyHTML(html);
}
</script>";
