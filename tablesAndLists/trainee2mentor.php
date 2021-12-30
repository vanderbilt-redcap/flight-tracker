<?php

use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$module = Application::getModule();
if ($_GET['cohort']) {
    $cohort = REDCapManagement::sanitize($_GET['cohort']);
    $records = Download::cohortRecordIds($token, $server, $module, $cohort);
    $cohortStr = " (Cohort ".$cohort.")";
} else {
    $records = Download::recordIds($token, $server);
    $cohortStr = "";
}
$metadata = Download::metadata($token, $server);
$firstNames = Download::firstnames($token, $server);
$lastNames = Download::lastnames($token, $server);
$allMentors = Download::allMentors($token, $server, $metadata);
$primaryMentors = Download::primaryMentors($token, $server);

$matches = [];
foreach ($records as $recordId) {
    $lastName = $lastNames[$recordId];
    $firstName = $firstNames[$recordId];
    foreach ($allMentors as $menteeRecordId => $mentors) {
        foreach ($mentors as $mentor) {
            list($mentorFirst, $mentorLast) = NameMatcher::splitName($mentor, 2);
            if (NameMatcher::matchName($firstName, $lastName, $mentorFirst, $mentorLast)) {
                if (!isset($matches[$recordId])) {
                    $matches[$recordId] = [];
                }
                $matches[$recordId][] = $menteeRecordId;
            }
        }
    }
}

echo "<h1>Trainees Becoming Mentors</h1>";
if ($cohortStr) {
    echo "<h3>$cohortStr</h3>";
}
echo "<p class='centered'>Names in <span class='green'>Green</span> have this scholar as their primary mentor; other names have this scholar as a secondary mentor.</p>";
$cohorts = new Cohorts($token, $server, $module);
$link = Application::link("this");
echo "<p class='centered'>Restrict Scholars (but not Mentees) to Cohort:<br>".$cohorts->makeCohortSelect($_GET['cohort'], "location.href = \"$link\"+\"&cohort=\"+encodeURIComponent($(this).val());")."</p>";
if (empty($matches)) {
    echo "<p class='centered'>No name matches.</p>";
} else {
    echo "<table class='centered bordered'>";
    echo "<thead><tr><th>Scholar Name</th><th>Scholar Record</th><th>Scholar's<br>Primary Mentor</th><th>Number of<br>Matched Mentees</th><th>Mentees</th></tr></thead>";
    echo "<tbody>";
    foreach ($matches as $recordId => $menteeRecords) {
        $firstName = $firstNames[$recordId];
        $lastName = $lastNames[$recordId];
        $numMentees = count($menteeRecords);
        $menteeRecordLinks = [];
        foreach ($menteeRecords as $menteeRecordId) {
            $menteeFirst = $firstNames[$menteeRecordId];
            $menteeLast = $lastNames[$menteeRecordId];
            $isPrimaryMentor = FALSE;
            foreach ($primaryMentors[$menteeRecordId] as $mentor) {
                list($mentorFirst, $mentorLast) = NameMatcher::splitName($mentor, 2);
                if (NameMatcher::matchName($mentorFirst, $mentorLast, $firstName, $lastName)) {
                    $isPrimaryMentor = TRUE;
                    break;     // inner
                }
            }
            if ($isPrimaryMentor) {
                $menteeName = "<span class='green'>&nbsp;$menteeFirst $menteeLast&nbsp;</span>";
            } else {
                $menteeName = "$menteeFirst $menteeLast";
            }
            $menteeRecordLinks[] = Links::makeRecordHomeLink($pid, $menteeRecordId, $menteeName);
            // $menteeRecordLinks[] = implode(", ", $allMentors[$menteeRecordId]);
        }
        echo "<tr>";
        echo "<td>$firstName $lastName</td>";
        echo "<td>".Links::makeRecordHomeLink($pid, $recordId, "Record $recordId")."</td>";
        if ($primaryMentors[$recordId] && (count($primaryMentors[$recordId]) > 0)) {
            echo "<td>".implode("<br>", $primaryMentors[$recordId])."</td>";
        } else {
            echo "<td></td>";
        }
        echo "<td>$numMentees</td>";
        echo "<td>".implode("<br>", $menteeRecordLinks)."</td>";
        echo "</tr>";
    }
    echo "</tbody>";
    echo "</table>";
}
