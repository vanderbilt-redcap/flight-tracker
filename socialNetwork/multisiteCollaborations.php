<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\GlobalHeatGraph;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\BarChart;
use \Vanderbilt\CareerDevLibrary\SocialNetworkChart;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\DateManagement;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\GrantFactory;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

define('START_YEAR', 2014);
define('MAX_COLORS', 8);
define('STARS', '***');

$action = $_GET['action'] ?? "";
$cohort = $_GET['cohort'] ? ($_GET['cohort'] == "all") ? "all" : Sanitizer::sanitizeCohort($_GET['cohort']) : "";
if (($cohort !== "") && ($cohort != "all")) {
    $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else if ($cohort == "all") {
    $records = Download::recordIds($token, $server);
} else {
    $records = [];
}
if (($action == "international") && isset($_GET['csv']) && isset($_GET['start']) && isset($_GET['end'])) {
    $startDate = Sanitizer::sanitizeDate($_GET['start']);
    $endDate = Sanitizer::sanitizeDate($_GET['end']);
    if ($startDate) {
        $startTs = strtotime($startDate);
        $endTs = $endDate ? strtotime($endDate) : time();
        $fields = [
            "record_id",
            "citation_ts",
            "citation_pmid",
            "citation_rcr",
            "citation_altmetric_score",
            "citation_title",
            "citation_full_citation",
            "citation_affiliations",
            "citation_include",
        ];
        $headers = [
            "Record ID",
            "REDCap Instance",
            "Scholar Name",
            "PMID",
            "Title",
            "Full Citation",
            "International Affiliations",
            "Countries",
            "Relative Citation Ratio",
            "Altmetric Score",
        ];

        $lines = makeCSVLinesForInternationalAffiliations($records, $token, $server, $fields, $startTs, $endTs, ($_GET['oneInstitutionPerRow'] == "on"));
        if (empty($lines)) {
            require_once(dirname(__FILE__)."/../charts/baseWeb.php");
            echo "<p class='centered red max-width'>No international data found in the given timespan ($startDate - $endDate)</p>";
        } else {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="international_collaborations.csv"');
            $fp = fopen("php://output", "w");
            fputcsv($fp, $headers);
            foreach ($lines as $line) {
                fputcsv($fp, $line);
            }
            fclose($fp);
            exit;
        }
    }
}

require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$metadata = Download::metadata($token, $server);
$metadataFields = DataDictionaryManagement::getFieldsFromMetadata($metadata);
if (!in_array("citation_affiliations", $metadataFields)) {
    $indexLink = Application::link("index.php");
    echo "<p class='centered green'>This graph relies on affiliation information downloaded from PubMed. Unfortunately, you are not set up for this currently. Please go to <a href='$indexLink'>Flight Tracker's Home page</a> and update your Data Dictionary. Then you'll have to wait past one weekend for your data to update. After that, this graph should be available.</p>";
    exit;
}
$networkChartName = "chartdiv_".$pid;
$requestedInstitutions = (isset($_GET['institutions']) && ($_GET['institutions'] !== "")) ? REDCapManagement::removeBlanksFromAry(preg_split("/[\n\r]+/", Sanitizer::sanitizeWithoutChangingQuotes($_GET['institutions']))) : [];
$startDate = isset($_GET['start']) ? Sanitizer::sanitizeDate($_GET['start']) : START_YEAR."-01-01";
$endDate = isset($_GET['end']) ? Sanitizer::sanitizeDate($_GET['end']) : "";
$startTs = ($startDate && DateManagement::isDate($startDate)) ? strtotime($startDate) : strtotime(START_YEAR."-01-01");
$endTs = ($endDate && DateManagement::isDate($endDate)) ? strtotime($endDate) : time();

$cohorts = new Cohorts($token, $server, Application::getModule());

if (isset($_GET['cohort']) && !empty($records)) {
    $citationFields = [
        "record_id",
        "citation_pmid",
        "citation_affiliations",
        "citation_ts",
        "citation_num_citations",
        "citation_include",
    ];
    $citationFields = DataDictionaryManagement::filterOutInvalidFields($metadata, $citationFields);

    if ($action == "multisite") {
        list($institutionMatches, $uniqueIDs, $pubs) = findMatchesForInstitutions($token, $server, $citationFields, $requestedInstitutions, $records, $startTs, $endTs);
        list($institutionCollabs, $networkGraphData, $colorWheel) = makeEdges($institutionMatches, $requestedInstitutions);
        $numCollabs = getNumberOfCollaborations($institutionCollabs);
        if ($numCollabs === 0) {
            echo "<h3>No collaborations among the ".count($requestedInstitutions)." institutions are currently observed.</h3>";
        } else {
            echo "<h1>Multi-Site Collaborations</h1>";
            echo "<table style='margin: 30px auto; max-width: 800px;' class='bordered'>\n";
            echo "<tr><th>Total Number of Papers</th><td>".REDCapManagement::pretty(count($uniqueIDs))."</td></tr>";
            echo "<tr><th>Total Number of Citations by Papers</th><td>".REDCapManagement::pretty(array_sum(array_values($uniqueIDs)))."</td></tr>";
            echo "<tr><th>Total Collaborations</th><td>".REDCapManagement::pretty($numCollabs)."</td></tr>";
            echo "</table>";

            $socialNetwork = new SocialNetworkChart($networkChartName, $networkGraphData, $pid);
            $socialNetwork->setNonRibbon(FALSE);
            echo $socialNetwork->getImportHTML();
            echo $socialNetwork->getHTML(900, 900);

            if (date("Y", $startTs) != date("Y", $endTs)) {
                echo "<br/><br/>";
                list($barChartCols, $barChartLabels) = makePublicationColsAndLabels($pubs);
                $chart = new BarChart($barChartCols, $barChartLabels, "barChart_$pid");
                $chart->setXAxisLabel("Year");
                $chart->setYAxisLabel("Number of Collaborations");
                echo $chart->getImportHTML();
                echo $chart->getHTML(500, 300);
            }
        }
    } else if ($action == "international") {
        $internationalCollaborations = getInternationalCollaborations($token, $server, $citationFields, $records, $startTs, $endTs);
        if (empty($internationalCollaborations)) {
            echo "<h3>No international (non-US) collaborations are currently observed.</h3>";
        } else {
            echo "<h1>International Collaborations</h1>";
            $numCollabs = array_sum(array_values($internationalCollaborations));
            $homeLatitude = Sanitizer::sanitizeNumber($_GET['source_latitude'] ?? "");
            $homeLongitude = Sanitizer::sanitizeNumber($_GET['source_longitude'] ?? "");
            echo "<table style='margin: 30px auto; max-width: 800px;' class='bordered'>\n";
            //  echo "<tr><th>Total Number of Papers</th><td>".REDCapManagement::pretty(count($uniqueIDs))."</td></tr>";
            // echo "<tr><th>Total Number of Citations by Papers</th><td>".REDCapManagement::pretty(array_sum(array_values($uniqueIDs)))."</td></tr>";
            echo "<tr><th>Number of International Collaborations</th><td>".REDCapManagement::pretty($numCollabs)."</td></tr>";
            echo "<tr><th>Number of International Countries Collaborated With</th><td>".REDCapManagement::pretty(count($internationalCollaborations))."</td></tr>";
            echo "</table>";

            $globalChart = new GlobalHeatGraph("intl_collabs", $internationalCollaborations, $pid);
            if ($homeLatitude && $homeLongitude) {
                $globalChart->setHomeCoords($homeLatitude, $homeLongitude);
            }
            $globalChart->setLegendTitle("Number of Collaborative Papers");
            $isVIGH = (
                (Application::isLocalhost() && ($pid == 72))
                || (Application::isServer("redcap.vanderbilt.edu") && ($pid == 178505))
            );
            if ($isVIGH || (isset($_GET['minColor']) && isset($_GET['maxColor']))) {
                $minColor = Sanitizer::sanitize($_GET['minColor'] ?: "#CCDAE2");
                $maxColor = Sanitizer::sanitize($_GET['maxColor'] ?: "#336e8c");
                $globalChart->setHeatColors($minColor, $maxColor);
            }
            echo $globalChart->getImportHTML();
            echo $globalChart->getHTML(1000, 650);
        }
    }
    echo "<br/><br/>";
    echo "<hr/>";
}

echo "<h1>Configure Multi-Site &amp; International Collaborations</h1>";
echo "<h2>Multi-Site Collaborations</h2>";
echo makeSiteForm("multisite", [], $cohort, $cohorts, $requestedInstitutions, $startDate, $endDate);
echo "<hr/>";
echo "<h2>International Collaborations</h2>";
echo "<p class='centered max-width green'>Note that institutions that need to be cleaned will be marked with a ".STARS.". Other institutions may also need to be cleaned due to limitations in the PubMed data quality and storage capabilities.</p>";
echo makeSiteForm("international", ["csv" => "Make CSV"], $cohort, $cohorts, NULL, $startDate, $endDate);


function makePublicationColsAndLabels($pubs) {
    $data = [];
    foreach ($pubs as $loc => $ts) {
        $year = date("Y", $ts);
        if (!isset($data[$year])) {
            $data[$year] = 0;
        }
        $data[$year]++;
    }

    # avoid infinite loop
    if (!empty($data)) {
        ksort($data, SORT_NUMERIC);
        for ($year = min(array_keys($data)); $year <= max(array_keys($data)); $year++) {
            if (!isset($data[$year])) {
                $data["$year"] = 0;
            }
        }
        ksort($data, SORT_NUMERIC);
    }

    $labels = array_keys($data);
    $cols = array_values($data);
    return [$cols, $labels];
}

function getPlainColorWheel() {
    return array_reverse(Application::getApplicationColors(["1.0", "0.3"], TRUE));
}

function makeEdges($matches, $requestedInstitutions) {
    $edgeWeights = [];
    $seen = [];
    $separator = "|";
    $colorWheel = getPlainColorWheel();
    $institutionColors = [];
    foreach ($requestedInstitutions as $i => $institution1) {
        $seen[] = $institution1;
        foreach ($requestedInstitutions as $institution2) {
            if (!in_array($institution1, $seen)) {
                $edgeWeights[$institution1.$separator.$institution2] = 0;
            }
        }
        $institutionColors[$institution1] = $colorWheel[$i % count($colorWheel)];
    }

    foreach ($matches as $licensePlate => $matchedInstitutions) {
        $seen = [];
        foreach ($matchedInstitutions as $from) {
            $seen[] = $from;
            foreach ($matchedInstitutions as $to) {
                if (!in_array($to, $seen)) {
                    if (isset($edgeWeights[$to.$separator.$from])) {
                        $edgeWeights[$to.$separator.$from]++;
                    } else {
                        $edgeWeights[$from.$separator.$to]++;
                    }
                }
            }
        }
    }

    $chartData = [];
    foreach ($institutionColors as $institution => $color) {
        $chartData[] = [
            "from" => $institution,
            "nodeColor" => $color,
        ];
    }
    $totalCollabs = array_sum(array_values($edgeWeights));
    $collabIncrement = 1;
    if ($totalCollabs > 5000) {
        $collabIncrement = 100;
    } else if ($totalCollabs > 500) {
        $collabIncrement = 10;
    }
    $collaborations = [];
    foreach ($edgeWeights as $index => $numCollabs) {
        list($institution1, $institution2) = explode($separator, $index);
        if (!isset($collaborations[$institution1])) {
            $collaborations[$institution1] = [];
        }
        $collaborations[$institution1][$institution2] = $numCollabs;
        $count = 0;
        while ($count < $numCollabs) {
            $chartData[] = [
                "from" => $institution1,
                "to" => $institution2,
                "value" => $numCollabs,
            ];
            $count += $collabIncrement;
        }
    }
    return [$collaborations, $chartData, $institutionColors];
}

function findGrantMatchesForRecord(&$index, &$coeusAwardNumbers, $token, $server, $fields, $fromRecordId, $indexedFields, $records, $userids, $names) {
    $redcapData = Download::fieldsForRecords($token, $server, $fields, [$fromRecordId]);
    $matches = [];
    foreach ($redcapData as $row) {
        if ($row['redcap_repeat_instrument'] == "") {
            $index[$fromRecordId] = $row[$indexedFields["field"]];
        }
    }
    $authors = [];
    $awardNumFields = GrantFactory::getAllAwardFields($token, $server);
    $piFields = GrantFactory::getAllPIFields($token, $server);
    # change to $grants->getGrants('all_pis')???
    foreach ($redcapData as $row) {
        $instance = $row['redcap_repeat_instance'];
        $instrument = $row['redcap_repeat_instrument'];
        $awardNo = "";
        $prefix = "";
        foreach ($awardNumFields as $field) {
            if (isset($row[$field]) && $row[$field]) {
                $awardNo = $row[$field];
                $prefix = REDCapManagement::getPrefix($field);
                break;
            }
        }
        if ($awardNo) {
            $baseAwardNo = Grant::translateToBaseAwardNumber($awardNo);
            if (!isset($authors[$baseAwardNo])) {
                $authors[$baseAwardNo] = [];
            }
            $myAuthors = [];
            foreach ($piFields as $field) {
                if (($instrument == "coeus2") && ($row["coeus2_award_status"] == "Awarded")) {
                    foreach ($userids as $recordId => $userid) {
                        if ($userid && $names[$recordId]
                            && (preg_match("/\($userid;/", $row['coeus2_collaborators'])
                                || preg_match("/$userid \(/", $row['coeus2_collaborators']))) {
                            $myAuthors[] = $names[$recordId];
                        }
                    }
                } else if (($instrument == "coeus") && ($awardNo !== "000") && ($awardNo !== "")) {
                    if (!isset($coeusAwardNumbers[$awardNo])) {
                        $coeusAwardNumbers[$awardNo] = [];
                    }
                    if (!isset($coeusAwardNumbers[$awardNo][$row['record_id']])) {
                        $coeusAwardNumbers[$awardNo][$row['record_id']] = [];
                    }
                    $coeusAwardNumbers[$awardNo][$row['record_id']][] = $row['redcap_repeat_instance'];
                } else if ((REDCapManagement::getPrefix($field) == $prefix) && isset($row[$field]) && $row[$field]) {
                    $myAuthors = array_unique(array_merge($myAuthors, NameMatcher::makeArrayOfFormattedNames($row[$field])));
                }
            }
            for ($i = 0; $i < count($myAuthors); $i++) {
                $myAuthors[$i] = trim($myAuthors[$i]);
            }
            foreach ($myAuthors as $myAuthor) {
                list($myAuthorFirst, $myAuthorLast) = NameMatcher::splitName($myAuthor);
                $authorAlreadyPresent = FALSE;
                foreach ($authors[$baseAwardNo] as $author) {
                    list($authorFirst, $authorLast) = NameMatcher::splitName($author);
                    if (NameMatcher::matchByInitials($myAuthorLast, $myAuthorFirst, $authorLast, $authorFirst)) {
                        $authorAlreadyPresent = TRUE;
                        break;
                    }
                }
                if (!$authorAlreadyPresent && ($matchRecordId = NameMatcher::matchName($myAuthorFirst, $myAuthorLast, $token, $server))) {
                    if (($matchRecordId != $fromRecordId) && in_array($matchRecordId, $records)) {
                        if (!isset($matches[$matchRecordId])) {
                            $matches[$matchRecordId] = [];
                        }
                        $matches[$matchRecordId][] = $instance;
                        $authors[$baseAwardNo][] = $myAuthor;
                    }
                }
            }
        }
    }
    return $matches;
}

function findMatchesForInstitutions($token, $server, $fields, $requestedInstitutions, $records, $startTs, $endTs) {
    $ids = [];
    $matches = [];
    $pubs = [];
    foreach ($records as $recordId) {
        $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
        foreach ($redcapData as $row) {
            if ($row['redcap_repeat_instrument'] == "citation") {
                $ts = DateManagement::isDate($row['citation_ts']) ? strtotime($row['citation_ts']) : 0;
                if (canApproveTimestamp($ts, $startTs, $endTs) && ($row['citation_include'] == '1')) {
                    $licensePlate = $recordId.":".$row['redcap_repeat_instance'];
                    $affiliationsByAuthor = [];
                    if ($row['citation_affiliations']) {
                        $affiliationsByAuthor = json_decode($row['citation_affiliations'], TRUE) ?: [];
                    }
                    $matchedInstitutions = [];
                    $publicationAffiliations = Publications::getAllAffiliationsFromAuthorArray($affiliationsByAuthor);
                    foreach ($requestedInstitutions as $institution) {
                        # match 1+ $publicationAffiliations
                        if (NameMatcher::matchInstitution($institution, $publicationAffiliations)) {
                            $matchedInstitutions[] = $institution;
                        }
                    }
                    # matched 2+ of the $requestedInstitutions
                    if (count($matchedInstitutions) > 1) {
                        $ids[$row['citation_pmid']] = $row['citation_num_citations'];
                        $matches[$licensePlate] = $matchedInstitutions;
                        $pubs[$licensePlate] = $ts;
                    }
                }
            }
        }
    }
    return [$matches, $ids, $pubs];
}

function canApproveTimestamp($ts, $startTs, $endTs) {
    if (!$ts) {
        return FALSE;
    }
    return (
        (!$startTs && !$endTs)
        || ($ts
            && (
                (
                    $startTs
                    && ($startTs <= $ts)
                    && (
                        !$endTs
                        || ($endTs >= $ts)
                    )
                )
                || (
                    $endTs
                    && ($endTs >= $ts)
                    && (
                        !$startTs
                        || ($startTs <= $ts)
                    )
                )
            )
        )
    );
}

function getExplodedFirstNames($token, $server) {
    $firstnames = Download::firstnames($token, $server);
    $possibilities = [];
    foreach (array_keys($firstnames) as $recordId) {
        $possibilities[$recordId] = NameMatcher::explodeFirstName($firstnames[$recordId]);
    }
    return $possibilities;
}

function getExplodedLastNames($token, $server) {
    $lastnames = Download::lastnames($token, $server);
    $possibilities = [];
    foreach (array_keys($lastnames) as $recordId) {
        $possibilities[$recordId] = NameMatcher::explodeLastName($lastnames[$recordId]);
    }
    return $possibilities;
}

function getNumberOfCollaborations($typeCollaborations) {
    $dataValues = [];
    foreach ($typeCollaborations as $recordId => $indivCollaborations) {
        $numCollaborations = array_sum(array_values($indivCollaborations));
        if ($numCollaborations > 0) {
            $dataValues[] = $numCollaborations;
        }
    }
    return array_sum($dataValues);
}

function findOrganization($nodes, $candidateIndices) {
    if (empty($candidateIndices)) {
        throw new \Exception("Invalid search for an organization! This should never happen. ".json_encode($nodes));
    }
    $omitRegexes= [
        "/LLC/",
        "/partner site/i",
    ];
    foreach ($candidateIndices as $index) {
        $match = FALSE;
        foreach ($omitRegexes as $regex) {
            if (preg_match($regex, $nodes[$index])) {
                $match = TRUE;
                break;
            }
        }
        if (!$match) {
            return $nodes[$index];
        }
    }
    return $nodes[$candidateIndices[count($candidateIndices) - 1]];
}

function removeEmails($str) {
    $str = preg_replace("/\.$/", "", $str);
    $words = preg_split("/\s+/", $str);
    $filtered = [];
    foreach ($words as $word) {
        if (!REDCapManagement::isEmailOrEmails($word)) {
            $filtered[] = $word;
        }
    }
    return implode(" ", $filtered);
}

# returns the location and the earliest node used
function getLocation($nodes) {
    if (count($nodes) >= 2) {
        $canadianProvincesAndTerritories = [
            "Ontario",
            "ON",
            "Quebec",
            "QC",
            "Nova Scotia",
            "NS",
            "New Brunswick",
            "NV",
            "Manitoba",
            "MB",
            "MT",
            "British Columbia",
            "BC",
            "Prince Edward Island",
            "PE",
            "PEI",
            "Saskatchewan",
            "SK",
            "Alberta",
            "AB",
            "Newfoundland",
            "Newfoundland and Labrador",
            "Newfoundland & Labrador",
            "NL",
            "Northwest Territories",
            "NT",
            "Yukon",
            "UT",
            "Nunavut",
            "NU",
            "CA",
        ];
        $australianProvinces = [
            "New South Wales",
            "NSW",
            "Victoria",
            "VIC",
            "Queensland",
            "QLD",
            "Western Australia",
            "WA",
            "South Australia",
            "SA",
            "Tasmania",
            "TAS",
        ];
        $country = removeEmails($nodes[count($nodes) - 1]);
        if ($country == "Canada") {
            if (
                in_array($nodes[count($nodes) - 3], $canadianProvincesAndTerritories)
                && (count($nodes) >= 4)
            ) {
                return [$nodes[count($nodes) - 4], $country, count($nodes) - 4];
            } else if (
                in_array($nodes[count($nodes) - 2], $canadianProvincesAndTerritories)
                && (count($nodes) >= 3)
            ) {
                return [$nodes[count($nodes) - 3], $country, count($nodes) - 3];
            } else {
                return [$nodes[count($nodes) - 2], $country, count($nodes) - 2];
            }
        } else if (
            ($country == "Australia")
            && in_array($nodes[count($nodes) - 2], $australianProvinces)
            && (count($nodes) >= 3)
        ) {
            return [$nodes[count($nodes) - 3], $country, count($nodes) - 3];
        } else {
            return [$nodes[count($nodes) - 2], $country, count($nodes) - 2];
        }
    }
    return ["", "", -1];
}

# data cleaning - imprecise, but some general trends hold
function shortenInstitutionNames($institutions) {
    $ary = [];
    foreach ($institutions as $institution) {
        $nodes = preg_split("/\s*,\s*/", $institution);
        # last two nodes are usually city and country, but there are some exceptions handled by getLocation
        list($loc, $country, $earliestNodeUsed) = getLocation($nodes);

        # when more than five nodes used, index 0 is usually department
        $candidateIndices = [];
        $firstNodeAvailable = 0;
        if (count($nodes) >= 6) {
            $firstNodeAvailable = 1;
        } else if ((count($nodes) == 5) && ($earliestNodeUsed > 1)) {
            $firstNodeAvailable = 1;
        }
        for ($i = $earliestNodeUsed - 1; $i >= $firstNodeAvailable; $i--) {
            $candidateIndices[] = $i;
        }

        if (count($nodes) >= 5) {
            $ary[] = findOrganization($nodes, $candidateIndices)." ($loc, $country)";
        } else if (count($nodes) == 4) {
            if ($nodes[3] == "Canada") {
                $ary[] = findOrganization($nodes, [2,1,0])." ($loc, $country)";
            } else {
                $ary[] = findOrganization($nodes, $candidateIndices)." ($loc, $country)";
            }
        } else if (count($nodes) == 3) {
            $ary[] = $nodes[0]." ($loc, $country)";
        } else if (count($nodes) == 2) {
            $ary[] = $nodes[0]." (".$nodes[1].")";
        } else if (count($nodes) == 1) {
            $ary[] = $nodes[0];
        }
    }
    return array_unique($ary);
}

function starUSAAffiliations($institutions, $stars) {
    $ary = [];
    foreach ($institutions as $institution) {
        if (preg_match("/USA/", $institution) && !preg_match("/USAID/", $institution)) {
            $ary[] = "$stars$institution";
        } else {
            $ary[] = $institution;
        }
    }
    return $ary;
}

function makeSiteForm($action, $extraParams, $cohort, $cohorts, $requestedInstitutions, $startDate, $endDate) {
    list($url, $params) = REDCapManagement::splitURL(Application::link("this"));
    $html = "";
    $html .= "<form method='GET' action='$url' id='$action'>\n";
    foreach ($params as $param => $value) {
        $html .= "<input type='hidden' name='$param' value='$value'>";
    }
    $html .= "<input type='hidden' name='action' value='$action' />";
    $highlightClass = (isset($_GET['cohort']) && ($cohort === "")) ? "red" : "";
    $html .= "<p class='centered $highlightClass'><a href='" . Application::link("cohorts/addCohort.php") . "'>Make a Cohort</a> to View a Sub-Group<br>";
    $html .= $cohorts->makeCohortSelect($cohort, "", TRUE) . "</p>";
    if ($action == "multisite") {
        $html .= "<p class='centered max-width green'>More concise institution names will receive more matches.</p>";
        $html .= "<p class='centered max-width'><label for='institutions'>List of Institutions or Fragments of Institution Names (one per line):</label><br/><textarea style='width: 300px; height: 200px;' id='institutions' name='institutions'>".implode("\n", $requestedInstitutions)."</textarea></p>";
    }
    if (isset($extraParams['csv'])) {
        $checked = ($_GET['oneInstitutionPerRow'] == "on") ? "checked" : "";
        $html .= "<div class='centered max-width'><input type='checkbox' id='oneInstitutionPerRow' name='oneInstitutionPerRow' $checked /> <label for='oneInstitutionPerRow'>One Institution per Row on CSV? (Unchecked means combining all institutions for a publication on one row.)</label></div>";
    }
    if ($action == "international") {
        $defaultLat = Sanitizer::sanitizeNumber($_GET['source_latitude'] ?? "");
        $defaultLong = Sanitizer::sanitizeNumber($_GET['source_longitude'] ?? "");
        $html .= "<p class='centered max-width'>Home Coordinates for Global Arcs on Graph (optional; leave blank to turn off):<br/><label for='source_latitude'>Latitude (negative for south): </label><input type='number' step='0.0001' id='source_latitude' name='source_latitude' style='width: 200px;' value='$defaultLat'/><br/><label for='source_longitude'>Longitude (negative for west): </label><input type='number' step='0.0001' id='source_longitude' name='source_longitude' style='width: 200px;' value='$defaultLong'/></p>";
    }

    $html .= "<div class='centered max-width'><label for='start'>Start Date (on-or-after ".START_YEAR."): </label><input type='date' id='start' name='start' value='$startDate' />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<label for='end'>End Date: </label><input type='date' id='end' name='end' value='$endDate' /></div>";
    $html .= "<div class='centered max-width smaller'>PubMed publications only (no ERIC)</div>";
    $html .= "<div class='centered max-width'>";
    $html .= "<button>Make Graph</button>";
    foreach ($extraParams as $param => $label) {
        $html .= "&nbsp&nbsp;&nbsp;<button onclick='$(\"<input>\").attr({type: \"hidden\", id:\"$param\", name:\"$param\", value:\"1\"}).appendTo(\"form#$action\"); return true;'>$label</button>";
    }
    $html .= "</div>";
    $html .= "</p></form>";
    return $html;
}

function makeCSVLinesForInternationalAffiliations($records, $token, $server, $fields, $startTs, $endTs, $oneRowPerInstitution) {
    $names = Download::names($token, $server);
    $lines = [];
    foreach ($records as $recordId) {
        $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
        foreach ($redcapData as $row) {
            $ts = DateManagement::isDate($row['citation_ts']) ? strtotime($row['citation_ts']) : 0;
            if (
                ($row['redcap_repeat_instrument'] == "citation")
                && canApproveTimestamp($ts, $startTs, $endTs)
                && ($row['citation_include'] == '1')
                && $row['citation_affiliations']
            ) {
                $affiliationsByAuthor = json_decode($row['citation_affiliations'], TRUE) ?? [];
                if (!empty($affiliationsByAuthor)) {
                    $publicationAffiliations = Publications::getAllAffiliationsFromAuthorArray($affiliationsByAuthor);
                    $internationalInstitutions = NameMatcher::matchInternationalAffiliations($publicationAffiliations);
                    if (!empty($internationalInstitutions)) {
                        if ($oneRowPerInstitution) {
                            $seen = [];
                            foreach ($internationalInstitutions as $internationalInstitution) {
                                if (!in_array($internationalInstitution, $seen)) {
                                    $lines[] = makeCSVLine($row, $names[$recordId] ?? "", [$internationalInstitution]);
                                    $seen[] = $internationalInstitution;
                                }
                            }
                        } else {
                            $lines[] = makeCSVLine($row, $names[$recordId] ?? "", $internationalInstitutions);
                        }
                    }
                }
            }
        }
    }
    return $lines;
}

function makeCSVLine($row, $name, $internationalInstitutions) {
    $shortenedInstitutions = starUSAAffiliations(shortenInstitutionNames($internationalInstitutions), STARS);
    $countries = NameMatcher::getInternationalCountries($internationalInstitutions);
    return [
        $row['record_id'],
        $row['redcap_repeat_instance'],
        $name,
        $row['citation_pmid'],
        $row['citation_title'],
        $row['citation_full_citation'],
        implode("; ", $shortenedInstitutions),
        implode("; ", $countries),
        $row['citation_rcr'],
        $row['citation_altmetric_score'],
    ];
}

function getInternationalCollaborations($token, $server, $fields, $records, $startTs, $endTs) {
    $collaborations = [];
    foreach ($records as $recordId) {
        $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
        foreach ($redcapData as $row) {
            if ($row['redcap_repeat_instrument'] == "citation") {
                $ts = DateManagement::isDate($row['citation_ts']) ? strtotime($row['citation_ts']) : 0;
                if (canApproveTimestamp($ts, $startTs, $endTs) && ($row['citation_include'] == '1')) {
                    $affiliations = [];
                    if ($row['citation_affiliations']) {
                        $affiliations = json_decode($row['citation_affiliations'], TRUE) ?: [];
                    }
                    $countries = NameMatcher::getInternationalCountries(Publications::getAllAffiliationsFromAuthorArray($affiliations));
                    foreach ($countries as $country) {
                        if (!isset($collaborations[$country])) {
                            $collaborations[$country] = 0;
                        }
                        $collaborations[$country]++;
                    }
                }
            }
        }
    }
    return $collaborations;
}