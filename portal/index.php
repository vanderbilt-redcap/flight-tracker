<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Portal;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Download;

require_once(__DIR__."/../classes/Autoload.php");
require_once(__DIR__."/base.php");

list($usernames, $firstName, $lastName, $emails) = Portal::getCurrentUserIDsNameAndEmails();
$username = Portal::siftThroughUsernamesForErrors($usernames, isset($_GET['match']));
$name = Portal::makeName($firstName, $lastName);
$testNames = Portal::getTestNames();
if (!$name && isset($testNames[$username])) {
    list ($fn, $ln) = $testNames[$username];
    $name = Portal::makeName($fn, $ln);
} else if (!$name && isset($_GET['match'])) {
    list($currPid, $recordId) = explode(":", Sanitizer::sanitize($_GET['match']));
    $name = Download::fullNameByPid($currPid, $recordId);
} else if (!$name) {
    # more computationally expensive, but rarely used; only when a user is not in REDCap, usually when spoofing
    $allPids = Application::getActivePids();
    $matches = Portal::getMatchesForUserid($username, $firstName, $lastName, $allPids)[0];
    if (!empty($matches)) {
        foreach ($matches as $currPid => $recordsAndNames) {
            if (!$name) {
                foreach ($recordsAndNames as $recordId => $recordName) {
                    if ($recordName) {
                        $name = $recordName;
                        break;
                    }
                }
            }
        }
    } else {
        $name = $username;
    }
}

$uidString = "";
if (isset($_GET['uid'])) {
    $uidString = "&uid=$username";
} else if (isset($_GET['match'])) {
    $uidString = "&match=".Sanitizer::sanitize($_GET['match']);
}

$loadingUrl = Application::link("img/loading.gif");
$driverUrl = Application::link("portal/driver.php").$uidString;

echo "<header>".Portal::getLogo($name)."<h2>Welcome to Flight Tracker's Scholar Portal</h2></header>";

echo "<div id='photoDiv'></div>";
echo "<div class='centered' id='project'></div>";
echo "<nav></nav>";
echo "<div class='loading'></div>";
echo "<div id='welcomeMessage' style='display: none;'><p style='margin-top: 0;'>Administrators and program directors are tracking your career development through the below REDCap-based project(s). These help them with reporting to funding agencies and ensuring excellent service. With the Scholar Portal, you can track these metrics as well.</p><p style='margin-bottom: 0;'>First, select one of the projects listed below. <strong>Your Graphs</strong> and <strong>Your Info</strong> show key metrics related to your professional progress. You can correct any inaccuracies under <strong>Your Info &rarr; Update</strong>. You can also look for colleagues in various research areas by exploring <strong>Your Network</strong>. Please <span class='multiProject'>click to select a project below, </span>explore your data through the menu options<span class='multiProject'>,</span> and build your career!</p></div>";
echo "<div id='noDataMessage' style='display: none;'>Welcome! You are not currently tracked by Flight Tracker on this REDCap server. Sorry! You can <a href='#find_collaborator' onclick='portal.takeAction(\"find_collaborator\", \"Find a Collaborator\"); return false;'>find a collaborator</a> or <a href='#board' onclick='portal.takeAction(\"board\", \"Bulletin Board\"); return false;'>check out the Institutional Bulletin Board</a>.</div>";
echo "<div id='mainBox'></div>";
echo "<div class='loading lower' style='display: none;'></div>";
echo "<div id='matchBox'></div>";

$closeWindow = isset($_GET['closeWindow']) ? "window.close();" : "";
$matchesHeaderText = Application::isVanderbilt() ? "Matches in All Flight Trackers Across Vanderbilt" : "Matches in All Institutional Flight Trackers";

echo "<script>
let portal = {};

$(document).ready(() => {
    $closeWindow
    portal = new Portal();
    portal.setLoadingUrl('$loadingUrl');
    portal.getMatches('$driverUrl');
    $(window).resize(() => {
        portal.refreshMatches();
        portal.refreshMenu();
    });
});

function getPortalHeaderText() {
    return '$matchesHeaderText';
}

</script>";

echo "<footer><div class='smaller centered'>Copyright &#9400; ".date("Y")." <a href='https://vumc.org' target='_NEW'>Vanderbilt University Medical Center</a> - powered by <a href='https://project-redcap.org/' target='_NEW'>REDCap</a> &amp; <a href='https://redcap.link/flight_tracker' target='_NEW'>Flight Tracker</a><br/>Funded by the <a href='https://ncats.nih.gov/ctsa' target='_NEW'>Clinical &amp; Translational Science Awards Program</a></div></footer>";