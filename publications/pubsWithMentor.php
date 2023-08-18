<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\Cohorts;
use Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$cohorts = new Cohorts($token, $server, Application::getModule());
$thisUrl = Application::link("this");

$cohort = "all";
if (isset($_GET['cohort']) && ($_GET['cohort'] == "all")) {
    $records = Download::recordIds($token, $server);
} else if (isset($_GET['cohort'])) {
    $cohort = Sanitizer::sanitizeCohort($_GET['cohort'], $pid);
    if ($cohort) {
        $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
    } else {
        $records = Download::recordIds($token, $server);
    }
} else {
    $records = Download::recordIds($token, $server);
}

$names = Download::names($token, $server);
$mentors = Download::primaryMentors($token, $server);
$numMentors = 0;

$recordsWithMentors = [];
foreach ($records as $recordId) {
    if (isset($mentors[$recordId]) && $mentors[$recordId]) {
        $recordsWithMentors[] = $recordId;
        $numMentors += count($mentors[$recordId]);
    }
}

echo "<h1>Publications with Mentors</h1>";
if (empty($recordsWithMentors)) {
    echo "<p class='centered'>No mentors present. Try adding some in each record's Initial Import form.</p>";
    exit;
}
echo "<p class='centered'>".$cohorts->makeCohortSelect($cohort ?? "", "location.href=\"$thisUrl&cohort=\"+$(this).val();", TRUE)."</p>";

$numMatches = 0;
$matchedCitations = [];
$citationFields = Download::citationFields($token, $server);
foreach ($recordsWithMentors as $recordId) {
    $recordMentors = $mentors[$recordId];
    $redcapData = Download::fieldsForRecords($token, $server, $citationFields, [$recordId]);
    $pubs = new Publications($token, $server, []);
    $pubs->setRows($redcapData);
    foreach ($pubs->getCitations("Included") as $citation) {
        foreach ($recordMentors as $mentorName) {
            if ($citation->hasAuthor($mentorName)) {
                if (!isset($matchedCitations[$recordId])) {
                    $matchedCitations[$recordId] = [];
                }
                $matchedCitations[$recordId][] = $citation;
                $numMatches++;
                break;   // just need one mentor match per citation
            }
        }
    }
}

if (empty($matchedCitations)) {
    $pubWranglerLink = Application::link("wrangler/pubs.php", $pid);
    echo "<p class='centered'>No matches with the $numMentors mentors present in the ".count($recordsWithMentors)." scholars that have mentors. Are you up to date on your <a href='$pubWranglerLink'>Publication Wrangling</a>?</p>";
    exit;
}

echo "<h2>".REDCapManagement::pretty($numMatches)." Publications with Mentors among ".count($matchedCitations)." Scholars</h2>";
foreach ($matchedCitations as $recordId => $citations) {
    $name = $names[$recordId] ?? "Unknown";
    $mentorWord = (count($mentors[$recordId]) > 1) ? "mentors" : "mentor";
    $citationWord = (count($citations) > 1) ? "publications" : "publication";
    $namesToBold = array_merge([$name], $mentors[$recordId]);
    echo "<h3>$name (".count($citations)." $citationWord with $mentorWord ".REDCapManagement::makeConjunction($mentors[$recordId]).")</h3>";
    foreach ($citations as $citation) {
        echo "<p class='max-width' style='margin: 1em auto;'>".$citation->getCitationWithLink(FALSE, TRUE, $namesToBold)."</p>";
    }
}
