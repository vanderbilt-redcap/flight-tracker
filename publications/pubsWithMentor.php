<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\Cohorts;
use Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$cohorts = new Cohorts($token, $server, Application::getModule());
$thisUrl = Application::link("this");

$cohort = "all";
if (isset($_GET['cohort']) && ($_GET['cohort'] == "all")) {
    $records = Download::recordIds($token, $server);
} else if (isset($_GET['cohort'])) {
    $cohort = Sanitizer::sanitizeCohort($_GET['cohort'], $pid) ?: "all";
    if ($cohort != "all") {
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

if (isset($_GET['csv'])) {
    $lines = [];
    $headers = [
        "Record ID",
        "Name",
        "Mentor List",
        "PMID",
        "DOI",
        "Full Citation",
    ];
    $lines[] = $headers;
    foreach ($matchedCitations as $recordId => $citations) {
        $name = $names[$recordId];
        $mentorList = implode(", ", $mentors[$recordId]);
        foreach ($citations as $citation) {
            $pmid = $citation->getVariable("pmid");
            $doi = $citation->getVariable("doi");
            $fullCitation = $citation->getPubMedCitation();
            $line = [
                $recordId,
                $name,
                $mentorList,
                $pmid,
                $doi,
                utf8_encode($fullCitation),
            ];
            $lines[] = $line;
        }
    }
    header("Content-type: text/csv");
    header("Content-Disposition: attachment; filename=pubs_with_mentors.csv");
    header("Pragma: no-cache");
    header("Expires: 0");

    $fp = fopen('php://output', 'w');
    foreach ($lines as $line) {
        fputcsv($fp, $line);
    }
    fclose($fp);

    exit;
}
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

echo "<h1>Publications with Mentors</h1>";
if (empty($recordsWithMentors)) {
    echo "<p class='centered'>No mentors present. Try adding some in each record's Initial Import form.</p>";
    exit;
}
echo "<p class='centered'>".$cohorts->makeCohortSelect($cohort ?? "", "location.href=\"$thisUrl&cohort=\"+$(this).val();", TRUE)."</p>";

if (empty($matchedCitations)) {
    $pubWranglerLink = Application::link("wrangler/pubs.php", $pid);
    echo "<p class='centered'>No matches with the $numMentors mentors present in the ".count($recordsWithMentors)." scholars that have mentors. Are you up to date on your <a href='$pubWranglerLink'>Publication Wrangling</a>?</p>";
    exit;
}

$encodedCohort = urlencode($cohort ?? "all");
echo "<h2>".REDCapManagement::pretty($numMatches)." Publications with Mentors among ".count($matchedCitations)." Scholars</h2>";
echo "<p class='centered'><a href='$thisUrl&cohort=$encodedCohort&csv'>Download as CSV</a></p>";
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
