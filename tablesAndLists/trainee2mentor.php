<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(__DIR__."/../classes/Autoload.php");
require_once(__DIR__."/../charts/baseWeb.php");

$cohort = Sanitizer::sanitizeCohort($_GET['cohort'], $pid);
if ($cohort) {
    $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else {
    $records = Download::recordIdsByPid($pid);
}

$scholarNames = Download::names($token, $server);
$mentors = Download::primaryMentors($token, $server);

$matches = [];
foreach ($records as $scholarRecordId) {
    $scholarName = $scholarNames[$scholarRecordId] ?? "";
    list($scholarFirst, $scholarLast) = NameMatcher::splitName($scholarName, 2);
    foreach ($mentors as $mentorRecordId => $recordMentors) {
        foreach ($recordMentors as $mentor) {
            list($mentorFirst, $mentorLast) = NameMatcher::splitName($mentor, 2);
            if (NameMatcher::matchName($scholarFirst, $scholarLast, $mentorFirst, $mentorLast)) {
                if (!isset($matches[$scholarRecordId])) {
                    $matches[$scholarRecordId] = [
                        "name" => $scholarName,
                        "mentor" => $mentors[$scholarRecordId] ?? [],
                        "mentee" => [],
                    ];
                }
                $mentee = $scholarNames[$mentorRecordId];
                $matches[$scholarRecordId]["mentee"][] = $mentee;
            }
        }
    }
}

$thisUrl = Application::link("this");
echo "<h1>Trainees Becoming Mentors</h1>";
$cohorts = new Cohorts($token, $server, Application::getModule());
echo "<p class='centered'>".$cohorts->makeCohortSelect($cohort, "location.href=\"$thisUrl&cohort=\"+$(this).val();")."</p>";
echo "<table class='centered max-width bordered'><thead>";
echo "<tr class='stickyGrey'><th>Trainee &rArr; Mentor</th><th>Original Mentor(s)</th><th>Mentee(s)</th></tr>";
echo "</thead><tbody>";
if (empty($matches)) {
    echo "<tr class='even'><td colspan='3' class='centered'>None are found.</td></tr>";
} else {
    foreach (array_values($matches) as $i => $match) {
        $rowClass = ($i % 2 == 0) ? "even" : "odd";
        $mentors = !empty($match['mentor']) ? REDCapManagement::makeConjunction($match['mentor']) : "[None specified]";
        $mentees = REDCapManagement::makeConjunction($match['mentee']);
        echo "<tr class='$rowClass'><td>{$match['name']}</td><td>$mentors</td><td>$mentees</td></tr>";
    }
}
echo "</tbody></table>";