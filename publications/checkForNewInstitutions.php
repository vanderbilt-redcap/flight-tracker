<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\DateManagement;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\URLManagement;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;

require_once(__DIR__."/../small_base.php");
require_once(__DIR__."/../classes/Autoload.php");

$thresholdMonths = Sanitizer::sanitizeInteger($_GET['numMonths'] ?? 12);
$thresholdTs = strtotime("-$thresholdMonths months");
$thresholdDate = date("Y-m-d", $thresholdTs);
$allRecords = Download::recordIds($token, $server);
if (isset($_POST['records']) && is_array($_POST['records'])) {
    $records = [];
    foreach ($_POST['records'] as $candidateRecord) {
        $recordId = Sanitizer::getSanitizedRecord($candidateRecord, $allRecords);
        if ($recordId) {
            $records[] = $recordId;
        }
    }

    $firstNames = Download::firstnames($token, $server);
    $middleNames = Download::middlenames($token, $server);
    $lastNames = Download::lastnames($token, $server);
    $metadataFields = Download::metadataFields($token, $server);
    $institutionData = Download::institutions($token, $server);
    $everybodysInstitutions = array_unique(array_merge(Application::getInstitutions($pid), Application::getHelperInstitutions($pid)));
    $citationDates = Download::oneFieldWithInstances($token, $server, "citation_ts");
    $citationIncludes = Download::oneFieldWithInstances($token, $server, "citation_include");
    $citationPMIDs = Download::oneFieldWithInstances($token, $server, "citation_pmid");

    $nameMismatches = [];
    foreach ($records as $recordId) {
        $latestTs = FALSE;
        foreach ($citationDates[$recordId] ?? [] as $instance => $date) {
            $include = $citationIncludes[$recordId][$instance] ?? "";
            if ($date && DateManagement::isDate($date) && ($include == '1')) {
                $ts = strtotime($date);
                if ($ts > $latestTs) {
                    $latestTs = $ts;
                }
            }
        }
        if ($latestTs < $thresholdTs) {
            $firstName = $firstNames[$recordId] ?? "";
            $lastName = $lastNames[$recordId] ?? "";
            $middleName = $middleNames[$recordId] ?? "";
            if ($firstName && $lastName) {
                $recordPMIDs = $citationPMIDs[$recordId] ?? [];
                $recordInstitutions = $institutionData[$recordId] ? preg_split("/\s*,\s*/", $institutionData[$recordId]) : [];
                $relevantInstitutions = array_unique(array_merge($everybodysInstitutions, $recordInstitutions));
                $pulledPMIDs = Publications::searchPubMedForNameAndDate($firstName, $middleName, $lastName, $pid, [], $thresholdDate);
                $includedRecordPMIDs = [];
                foreach ($recordPMIDs as $instance => $pmid) {
                    $include = $citationIncludes[$recordId][$instance] ?? "";
                    if (($include == "1") && !in_array($pmid, $includedRecordPMIDs)) {
                        $includedRecordPMIDs[] = $pmid;
                    }
                }
                $missingPMIDs = [];
                foreach ($pulledPMIDs as $pmid) {
                    if (!in_array($pmid, $includedRecordPMIDs)) {
                        $missingPMIDs[] = $pmid;
                    }
                }
                if (!empty($missingPMIDs)) {
                    $affiliationsAndDates = Publications::getAffiliationsAndDatesForPMIDs($missingPMIDs, $metadataFields, $pid);
                    foreach ($affiliationsAndDates as $pmid => $ary) {
                        $affiliationsByAuthor = $ary['affiliations'];
                        $publicationDate = $ary['date'];
                        $fullCitation = $ary['citation'];
                        $authors = array_keys($affiliationsByAuthor);
                        $matchedAuthorAffiliations = [];
                        $matchedAuthors = [];
                        foreach ($authors as $author) {
                            list($authorInitials, $authorLast) = NameMatcher::splitName($author, 2, FALSE, FALSE);
                            if (NameMatcher::matchByInitials($lastName, $firstName, $authorLast, $authorInitials)) {
                                $matchedAuthorAffiliations = array_unique(array_merge($matchedAuthorAffiliations, $affiliationsByAuthor[$author]));
                                $matchedAuthors[] = $author;
                            }
                        }
                        if (!empty($matchedAuthorAffiliations)) {
                            REDCapManagement::compressArray($matchedAuthorAffiliations);
                            $matched = FALSE;
                            foreach ($relevantInstitutions as $institution) {
                                if (NameMatcher::matchInstitution($institution, $matchedAuthorAffiliations)) {
                                    $matched = TRUE;
                                    break;
                                }
                            }
                            if (!$matched) {
                                if (!isset($nameMismatches[$recordId])) {
                                    $nameMismatches[$recordId] = [];
                                }
                                $nameMismatches[$recordId][$pmid] = [
                                    "authors" => REDCapManagement::clearUnicodeInArray($matchedAuthors),
                                    "affiliations" => REDCapManagement::clearUnicodeInArray($matchedAuthorAffiliations),
                                    "date" => DateManagement::YMD2MDY($publicationDate),
                                    "name" => NameMatcher::formatName($firstName, $middleName, $lastName),
                                    "citation" => REDCapManagement::clearUnicode($fullCitation),
                                    "institutions" => $recordInstitutions,
                                ];
                            }
                        }
                    }
                }
            }
        }
    }
    echo json_encode($nameMismatches);
    exit;
} else if (isset($_POST['institution']) && isset($_POST['record'])) {
    $recordId = Sanitizer::getSanitizedRecord($_POST['record'], $allRecords);
    $institution = Sanitizer::sanitizeWithoutChangingQuotes($_POST['institution']);
    if ($recordId && $institution) {
        $metadataFields = Download::metadataFields($token, $server);
        $positionChangeFields = array_unique(array_merge(["record_id", "identifier_institution"], DataDictionaryManagement::filterFieldsForPrefix($metadataFields, "promotion_")));
        $redcapData = Download::fieldsForRecords($token, $server, $positionChangeFields, [$recordId]);
        $maxInstance = REDCapManagement::getMaxInstance($redcapData, "position_change", $recordId);
        $previousInstitutionList = REDCapManagement::findField($redcapData, $recordId, "identifier_institution");
        $newInstitutionList = $previousInstitutionList ? $previousInstitutionList.", $institution" : $institution;
        $upload = [];
        $upload[] = [
            "record_id" => $recordId,
            "redcap_repeat_instrument" => "position_change",
            "redcap_repeat_instance" => $maxInstance + 1,
            "promotion_institution" => $institution,
            "promotion_date" => date("Y-m-d"),
            "position_change_complete" => "2",
        ];
        $upload[] = [
            "record_id" => $recordId,
            "redcap_repeat_instrument" => "",
            "redcap_repeat_instance" => "",
            "identifier_institution" => $newInstitutionList,
            "identifiers_complete" => "2",
        ];
        try {
            Upload::rows($upload, $token, $server);
            echo "Successfully uploaded.";
        } catch (\Exception $e) {
            echo "Error: ".Sanitizer::sanitizeWithoutChangingQuotes($e->getMessage());
        }
    } else if (!$institution && !$recordId) {
        echo "Error: Invalid institution and record";
    } else if (!$institution) {
        echo "Error: Invalid institution";
    } else {
        echo "Error: Invalid record";
    }
    exit;
}

require_once(__DIR__."/../charts/baseWeb.php");

$thisUrl = Application::link("this");
list($url, $params) = URLManagement::splitURL($thisUrl);
$longThresholdDate = DateManagement::YMD2LongDate($thresholdDate);
echo "<h1>Searching PubMed for New Institutions</h1>";
echo "<form action='$url' method='GET'>";
echo URLManagement::makeHiddenInputs($params);
echo "<p class='centered'><label for='numMonths'>Number of Months to Search For:</label> <input type='number' min='1' id='numMonths' name='numMonths' value='$thresholdMonths' /> <button>Go!</button></p>";
echo "</form>";
echo "<p class='centered max-width'>Flight Tracker matches data by name and institution. This page searches PubMed by name to see if any institutions that <strong>you aren't listed</strong> have been recently used by publications by a scholar with the same name. You can consider automatically adding that institution to the scholar's record for future downloads. This page should be used <strong>carefully</strong> because false publication matches might be made based on erroneous decisions. We encourage you to consult outside resources like <a href='https://www.linkedin.com/' target='_blank'>LinkedIn</a> for confirmation. Institutions only need to be added once per scholar.</p>";
echo "<h2>Looking for New Institutions<br/>on Publications After $longThresholdDate</h2>";
$metadataFields = Download::metadataFields($token, $server);
if (!in_array("citation_ts", $metadataFields)) {
    $indexLink = Application::link("index.php", $pid);
    echo "<p class='centered max-width red'>You must update your Data Dictionary in order to proceed with this analysis. Please update <a href='$indexLink'>here</a>.</p>";
    exit;
}

$recordsJSON = json_encode($allRecords);
echo "<div id='loading' class='centered'></div>";
echo "<table id='results' class='centered bordered'><thead><tr class='stickyGrey'><th>Scholar Name</th><th class='max-width-300'>Citation</th><th>Publication Date</th><th class='max-width-300'>Current Institutions</th><th>Matched Authors</th><th class='max-width-300'>Matched Institutions</th></tr></thead><tbody></tbody></table>";
echo "<script>
$(document).ready(() => {
    const records = $recordsJSON;
    const url = '$thisUrl';
    downloadNewInstitutionsFromPubMed(url, records, '#results tbody', '#loading', 0, 0);
});
</script>";