<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Stats;
use \Vanderbilt\CareerDevLibrary\BarChart;
use \Vanderbilt\CareerDevLibrary\SocialNetworkChart;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\Grant;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\DateManagement;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Grants;
use \Vanderbilt\CareerDevLibrary\GrantFactory;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");
if (isset($_GET['record'])) {
    $allRecords = Download::recordIds($token, $server);
    $highlightedRecord = Sanitizer::getSanitizedRecord($_GET['record'], $allRecords);
} else {
    $highlightedRecord = FALSE;
}

$numCollabsToShow = isset($_GET['numCollabs']) ? Sanitizer::sanitize($_GET['numCollabs']) : 3;
define('PUBYEAR_SELECT', '---pub_year---');
define('START_YEAR', 2010);

$metadata = Download::metadata($token, $server);
$metadataFields = DataDictionaryManagement::getFieldsFromMetadata($metadata);
$choices = DataDictionaryManagement::getChoices($metadata);
$metadataLabels = DataDictionaryManagement::getLabels($metadata);
$userids = Download::userids($token, $server);
$cohort = $_GET['cohort'] ? ($_GET['cohort'] == "all") ? "all" : Sanitizer::sanitizeCohort($_GET['cohort']) : "";
if (($cohort !== "") && ($cohort != "all")) {
    $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else if (($cohort == "all") || $highlightedRecord) {
    $records = Download::recordIds($token, $server);
} else {
    $records = [];
}
$networkChartName = "chartdiv";
$includeHeaders = !isset($_GET['headers']) || ($_GET['headers'] != "false");
$possibleFields = ["record_id", "summary_primary_dept", "summary_gender", "summary_urm", "summary_degrees", "summary_current_division"];
if (CareerDev::isVanderbilt() && in_array("identifier_grant_type", $metadataFields)) {
    $possibleFields[] = "identifier_grant_type";
}
if ($_GET['field'] && in_array($_GET['field'], $possibleFields)) {
    $indexByField = Sanitizer::sanitize($_GET['field']);
} else if ($_GET['field'] == PUBYEAR_SELECT) {
    $indexByField = PUBYEAR_SELECT;
} else {
    $indexByField = "record_id";
}
$startDate = Publications::adjudicateStartDate($_GET['limitPubs'] ?? "", $_GET['start'] ?? "");
$endDate = isset($_GET['end']) ? Sanitizer::sanitizeDate($_GET['end']) : "";
$startTs = ($startDate && DateManagement::isDate($startDate)) ? strtotime($startDate) : FALSE;
$endTs = ($endDate && DateManagement::isDate($endDate)) ? strtotime($endDate) : FALSE;

$includeMentors = isset($_GET['mentors']) && ($_GET['mentors'] == "on") && isForIndividualScholars($indexByField);
$otherMentorsOnly = isset($_GET['other_mentors']) && ($_GET['other_mentors'] == "on") && isForIndividualScholars($indexByField);

$cohorts = new Cohorts($token, $server, Application::getModule());

if (isset($_GET['grants'])) {
    $title = "Grant Collaborations Among Scholars";
    $inlineDefinitions = [
        "collaborations" => "Grants <u>with</u> others in network",
    ];
    $topDefinitions = [
        "Scholar" => "The person whose grant is being examined.",
        "Collaborator" => "A different person who has investigated a grant as a PI or Co-PI with the Scholar.",
        "Collaboration" => "A grant awarded with a Scholar and a Collaborator. (One grant may have more than one collaboration.)",
    ];
} else {
    $title = "Publishing Collaborations Among Scholars";
    $inlineDefinitions = [
        "given" => "Citations <u>to</u> others in network",
        "received" => "Citations <u>by</u> others in network",
    ];
    $topDefinitions = [
        "Scholar" => "The person whose publication is being examined.",
        "Collaborator" => "A different person who has co-authored a paper with the Scholar.",
        "Collaboration" => "A paper published with a Scholar and a Collaborator. (One paper may have more than one collaboration.)",
    ];
}

if ($includeHeaders) {
    if (isset($_GET['grants'])) {
        echo Grants::makeFlagLink($pid);
    }
    echo "<h1>$title</h1>\n";
    list($url, $params) = REDCapManagement::splitURL(Application::link("socialNetwork/collaboration.php"));
    echo "<form method='GET' action='$url'>\n";
    if (!isset($params['limitPubs']) && isset($_GET['limitPubs'])) {
        $limitYear = Sanitizer::sanitizeInteger($_GET['limitPubs']);
        $params['limitPubs'] = $limitYear;
    }
    if (isset($_GET['showFlagsOnly'])) {
        $params['showFlagsOnly'] = '1';
    }
    foreach ($params as $param => $value) {
        echo "<input type='hidden' name='$param' value='$value'>";
    }
    if (isset($_GET['grants'])) {
        echo "<input type='hidden' name='grants' value='1'>";
    }
    echo "<p class='centered'><a href='" . Application::link("cohorts/addCohort.php") . "'>Make a Cohort</a> to View a Sub-Group<br>";
    echo $cohorts->makeCohortSelect($cohort, "", TRUE) . "<br>";
    echo makeFieldSelect($indexByField, $possibleFields, $metadataLabels) . "<br>";
    $style = "";
    if (!isForIndividualScholars($indexByField)) {
        $style = " style='display: none;'";
    }
    $checked = [];
    foreach (['mentors', 'other_mentors'] as $key) {
        if (isset($_GET[$key]) && ($_GET[$key] == "on")) {
            $checked[$key] = " checked";
        } else {
            $checked[$key] = "";
        }
    }

    if (!isset($_GET['grants'])) {
        echo "<div class='mentorCheckbox centered max-width'$style><input type='checkbox' id='mentors' name='mentors'{$checked['mentors']}> <label for='mentors'>Include Mentors' Collaborations with Scholars</label></div>";
        echo "<div class='mentorCheckbox centered max-width'$style><input type='checkbox' id='other_mentors' name='other_mentors'{$checked['other_mentors']}> <label for='other_mentors'>Show Only Collaborations with Multiple Mentors</label></div>";
        echo "<div class='centered max-width'><label for='start'>Start Date (on-or-after ".START_YEAR."): </label><input type='date' id='start' name='start' value='$startDate' />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<label for='end'>End Date: </label><input type='date' id='end' name='end' value='$endDate' /></div>";
        echo Publications::makeLimitButton("div");
    }
    echo "<div class='centered max-width'><label for='numCollabs'>Number of Top Collaborators to Highlight</label> <input type='number' id='numCollabs' name='numCollabs' value='$numCollabsToShow' style='width: 50px;' /></div>";
    echo "<div class='centered max-width'><button>Go!</button></div>";
    echo "</p></form>";
}

$display = isset($_GET['cohort']) || $highlightedRecord;

if ($display && !empty($records)) {
    $names = Download::names($token, $server);
    if ($otherMentorsOnly) {
        $possibleLastNames = [];
        $possibleFirstNames = [];
    } else {
        $possibleLastNames = getExplodedLastNames($token, $server);
        $possibleFirstNames = getExplodedFirstNames($token, $server);
    }
    $mentors = [];
    if ($includeMentors || $otherMentorsOnly) {
        $mentors = Download::primaryMentors($token, $server);
        addMentorNamesForRecords($possibleFirstNames, $possibleLastNames, $records, $mentors, $highlightedRecord);
    }
    $citationFields = [
        "record_id",
        "citation_pmid",
        "citation_include",
        "citation_authors",
        "citation_year",
        "citation_month",
        "citation_day",
        "citation_num_citations",
        "eric_id",
        "eric_include",
        "eric_author",
        "eric_sourceid",
        "eric_publicationdateyear",
    ];
    if (!in_array($indexByField, $citationFields)) {
        $citationFields[] = $indexByField;
    }
    $citationFields = DataDictionaryManagement::filterOutInvalidFields($metadata, $citationFields);

    $grantFields = array_unique(array_merge(
        ["record_id", "identifier_first_name", "identifier_last_name"],
        GrantFactory::getAllAwardFields($token, $server),
        GrantFactory::getAllPIFields($token, $server)
    ));
    if (!in_array($indexByField, $grantFields)) {
        $grantFields[] = $indexByField;
    }
    $grantFields = DataDictionaryManagement::filterOutInvalidFields($metadata, $grantFields);

    $matches = [];
    $pubs = [];
    $index = [];
    $coeusAwardNumbers = [];
    $uniqueIDs = [];
    if ($highlightedRecord) {
        foreach ($records as $fromRecordId) {
            if ($fromRecordId == $highlightedRecord) {
                if (isset($_GET['grants'])) {
                    $matches[$highlightedRecord] = findGrantMatchesForRecord($index, $coeusAwardNumbers,  $token, $server, $grantFields, $highlightedRecord, $indexByField, $records, $userids, $names);
                } else {
                    list($matches[$highlightedRecord], $ids) = findMatchesForRecord($index, $pubs, $token, $server, $citationFields, $highlightedRecord, $possibleFirstNames, $possibleLastNames, $indexByField, $records, $startTs, $endTs);
                    foreach ($ids as $id => $numCitations) {
                        $uniqueIDs[$id] = $numCitations;
                    }
                }
            } else {
                $matches[$fromRecordId] = [];
            }
        }
    } else {
        foreach ($records as $fromRecordId) {
            if (isset($_GET['grants'])) {
                $fromMatches = findGrantMatchesForRecord($index, $coeusAwardNumbers, $token, $server, $grantFields, $fromRecordId, $indexByField, $records, $userids, $names);
            } else {
                list($fromMatches, $ids) = findMatchesForRecord($index, $pubs, $token, $server, $citationFields, $fromRecordId, $possibleFirstNames, $possibleLastNames, $indexByField, $records, $startTs, $endTs);
                foreach ($ids as $id => $numCitations) {
                    $uniqueIDs[$id] = $numCitations;
                }
            }
            if ($otherMentorsOnly) {
                if (count($fromMatches) > 1) {
                    $matches[$fromRecordId] = $fromMatches;
                }
            } else {
                $matches[$fromRecordId] = $fromMatches;
            }
        }
    }
    # TODO adds duplicates because there's no way to do an author check, as with the others
//    foreach ($coeusAwardNumbers as $awardNo => $matchedRecords) {
//        if (count($matchedRecords) > 1) {
//            foreach ($matchedRecords as $fromRecord => $fromInstances) {
//                foreach ($matchedRecords as $toRecord => $toInstances) {
//                    if ($fromRecord != $toRecord) {
//                        if (!isset($matches[$fromRecord])) {
//                            $matches[$fromRecord] = [];
//                        }
//                        if (!isset($matches[$fromRecord][$toRecord])) {
//                            $matches[$fromRecord][$toRecord] = [];
//                        }
//                        foreach ($toInstances as $toInstance) {
//                            $matches[$fromRecord][$toRecord][] = $toInstance;
//                        }
//                    }
//                }
//            }
//        }
//    }

    list($collaborations, $chartData, $uniqueNames) = makeEdges($matches, $indexByField, $names, $choices, $index, $pubs);

    if ($includeMentors) {
        $mentorCollaborations = getAvgMentorCollaborations($matches);
    } else {
        $mentorCollaborations = 0;
    }
    list($stats, $maxCollaborations, $maxNames, $maxCollaborators, $maxCollabNames, $totalCollaborators) = makeSummaryStats($collaborations, $names, $numCollabsToShow);

    if (isset($_GET['test'])) {
        echo "unique IDs: ".implode(", ", array_keys($uniqueIDs))."<br/>";
    }
    $noCollaborations = (getCollaborationsRepresented($stats) == 0);
    if ($noCollaborations) {
        echo "<br><br><br><br><br><br><br><br><br><br>";
        echo "<h3>No collaborations are currently observed.</h3>";
    } else if ($includeHeaders) {
        echo "<table style='margin: 30px auto; max-width: 800px;' class='bordered'>\n";
        echo "<tr><td colspan='2'><h4 class='nomargin'>Definitions</h4>";
        $lines = [];
        foreach ($topDefinitions as $term => $definition) {
            $lines[] = "<b>$term</b> - $definition";
        }
        echo "<div class='centered'>".implode("<br>", $lines)."</div>";
        if (isset($_GET['grants'])) {
            $collaborations['collaborations'] = $collaborations['given'];
            $stats['collaborations'] = $stats['given'];
            $totalCollaborators['collaborations'] = $totalCollaborators['given'];
            $maxCollaborators['collaborations'] = $maxCollaborators['given'];
            $maxCollaborations['collaborations'] = $maxCollaborations['given'];
            $maxNames['collaborations'] = $maxNames['given'];
            $maxCollabNames['collaborations'] = $maxCollabNames['given'];
        }
        foreach ($inlineDefinitions as $type => $definition) {
            echo "<tr><td colspan='2' class='centered green'><h4 class='nomargin'>".ucfirst($type)."</h4>$definition</td></tr>";
            if ($stats) {
                if (($type == "given") && !isset($_GET['grants'])) {
                    echo "<tr><th>Total Number of Papers</th><td>".REDCapManagement::pretty(count($uniqueIDs))."</td></tr>";
                    echo "<tr><th>Total Number of Citations by Papers</th><td>".REDCapManagement::pretty(array_sum(array_values($uniqueIDs)))."</td></tr>";
                }
                echo "<tr><th>Total Collaborations</th><td>".REDCapManagement::pretty(array_sum($stats[$type]->getValues()))."</td></tr>\n";
                echo "<tr><th>Number of Scholars with Collaborations</th><td>n = ".REDCapManagement::pretty($stats[$type]->getN())."</td></tr>\n";
                echo "<tr><th>Average Number of Collaborators with at least one Collaboration</th><td>".REDCapManagement::pretty($totalCollaborators[$type] / $stats[$type]->getN(), 1)."</td></tr>\n";
                echo "<tr><th>Mean of Collaborations</th><td>&mu; = ".REDCapManagement::pretty($stats[$type]->mean(), 1)."</td></tr>\n";
                echo "<tr><th>Average Collaborations with a Collaborator</th><td>".REDCapManagement::pretty($stats[$type]->sum() / $totalCollaborators[$type], 1)."</td></tr>\n";
                echo "<tr><th>Median of Collaborations</th><td>".REDCapManagement::pretty($stats[$type]->median(), 1)."</td></tr>\n";
                echo "<tr><th>Mode of Collaborations</th><td>".implode(", ", $stats[$type]->mode())."</td></tr>\n";
                echo "<tr><th>Standard Deviation</th><td>&sigma; = ".REDCapManagement::pretty($stats[$type]->getSigma(), 1)."</td></tr>\n";
                echo "<tr><th>Maximum Collaborations</th><td>Leading $numCollabsToShow Entries<br/>".formatCollaborators($maxCollaborations[$type], $maxNames[$type], $numCollabsToShow)."</td></tr>\n";
                echo "<tr><th>Maximum Number of Collaborators</th><td>Leading $numCollabsToShow Entries<br/>".formatCollaborators($maxCollaborators[$type], $maxCollabNames[$type], $numCollabsToShow)."</td></tr>\n";
                if ($includeMentors && ($type != "received") && $mentorCollaborations) {
                    echo "<tr><th>Average Collaborations per Mentor with All Scholars</th><td>".REDCapManagement::pretty($mentorCollaborations, 1)."</td></tr>\n";
                }
            }
        }
        echo "</table>\n";
    }

    if (!$noCollaborations) {
        $atBottomOfPage = (!$includeHeaders || isset($_GET['grants']));
        echo makeLegendHTML($indexByField);
        $socialNetwork = new SocialNetworkChart($networkChartName, $chartData);
        $socialNetwork->setNonRibbon(count($uniqueNames) > 100);
        echo $socialNetwork->getImportHTML();
        echo $socialNetwork->getHTML(900, 900, TRUE, [], $atBottomOfPage);

        if (!$atBottomOfPage) {
            echo "<br><br>";
            list($barChartCols, $barChartLabels) = makePublicationColsAndLabels($pubs);
            $chart = new BarChart($barChartCols, $barChartLabels, "barChart");
            $chart->setXAxisLabel("Year");
            $chart->setYAxisLabel("Number of Collaborations");
            echo $chart->getImportHTML();
            echo $chart->getHTML(500, 300, TRUE);
        }
    }
}



function formatCollaborators($maxCollaboratorsForType, $maxCollabNamesForType, $numCollabsToShow) {
    $previousEntry = -999999;
    $htmlToReturn = [];
    for ($i = 0; $i < $numCollabsToShow; $i++) {
        if (isset($maxCollaboratorsForType[$i]) && ($maxCollaboratorsForType[$i] != $previousEntry)) {
            $htmlToReturn[] = REDCapManagement::pretty($maxCollaboratorsForType[$i])." (".REDCapManagement::makeConjunction($maxCollabNamesForType[$i]).")";
            $previousEntry = $maxCollaboratorsForType[$i];
        }
    }
    return implode("<br/>", $htmlToReturn);
}

function getCitationTimestamp($row) {
    if ($row['redcap_repeat_instrument'] == "citation") {
        $year = Citation::transformYear($row['citation_year']);
        $month = $row['citation_month'];
        $day = $row['citation_day'];
        return Citation::transformDateToTimestamp(Citation::transformIntoDate($year, $month, $day));
    } else if ($row['redcap_repeat_instrument'] == "eric") {
        $date = Citation::getDateFromSourceID($row['eric_sourceid'], $row['eric_publicationdateyear']);
        if ($date && DateManagement::isDate($date)) {
            return strtotime($date);
        }
        return 0;
    } else {
        throw new \Exception("Invalid row!");
    }
}

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
        ksort($data);
        for ($year = min(array_keys($data)); $year <= max(array_keys($data)); $year++) {
            if (!isset($data[$year])) {
                $data[$year] = 0;
            }
        }
        ksort($data);
    }

    $labels = array_keys($data);
    $cols = array_values($data);
    return [$cols, $labels];
}

function truncateFieldLabel($label) {
    if (preg_match("/<br>/", $label)) {
        $nodes = preg_split("/<br>/", $label);
        $label = $nodes[0];
    }
    $limit = 30;
    if (strlen($label) > $limit) {
        $label = substr($label, 0, $limit)."...";
    }
    return $label;
}

function makeFieldSelect($selectedField, $fields, $metadataLabels) {
    $html = "";
    $pubyearSelect = PUBYEAR_SELECT;
    $html .= "Index by Field: <select name='field' onchange='if (($(this).val() == \"record_id\") || ($(this).val() == \"$pubyearSelect\")) { $(\".mentorCheckbox\").show(); } else { $(\".mentorCheckbox\").hide(); }'>";
    foreach ($fields as $field) {
        $selected = "";
        if ($field == $selectedField) {
            $selected = " selected";
        }
        $html .= "<option value='$field'$selected>".truncateFieldLabel($metadataLabels[$field])."</option>";
    }
    $selected = "";
    if ($selectedField == PUBYEAR_SELECT) {
        $selected = " selected";
    }
    $html .= "<option value='".PUBYEAR_SELECT."'$selected>Publication Year</option>";
    $html .= "</select>";
    return $html;
}

function getAvgMentorCollaborations($matches) {
    $mentorCollaborations = 0;
    $mentorCollaborators = [];
    foreach (array_keys($matches) as $fromRecordId) {
        foreach ($matches[$fromRecordId] as $toRecordId => $fromInstances) {
            if (preg_match("/^Mentor/", $toRecordId)) {
                $mentor = $toRecordId;
                $mentorCollaborations += count($fromInstances);
                if (!in_array($mentor, $mentorCollaborators)) {
                    $mentorCollaborators[] = $mentor;
                }
            }
        }
    }
    if (count($mentorCollaborators) > 0) {
        return $mentorCollaborations / count($mentorCollaborators);
    }
    return 0;
}

function getPlainColorWheel() {
    // Flight Tracker colors
//    return [
//        "#5764ae",
//        "#f0565d",
//        "#8dc63f",
//        "#f79721",
//        "#8c91ae",
//        "#f09599",
//        "#a4c675",
//        "#f7b768",
//   ];
    return Application::getApplicationColors(["1.0", "0.3"], TRUE);
}

function generateColorWheel($numColors, $startYear, $endYear) {
    # from https://learnui.design/tools/data-color-picker.html#palette
//    $colors = [
//        "#003f5c",
//        "#2f4b7c",
//        "#665191",
//        "#a05195",
//        "#d45087",
//        "#f95d6a",
//        "#ff7c43",
//        "#ffa600",
//    ];

    # Flight Tracker colors with a second four that are de-saturated a bit
    $colors = getPlainColorWheel();
    $unknownColor = '#000000';

    if ($numColors > count($colors)) {
        throw new \Exception("Requested $numColors colors; maximum is ".count($colors));
    }

    $yearspan = $endYear - $startYear + 1;
    $yearsPerColor = 1;
    while ($yearspan > $yearsPerColor * count($colors)) {
        $yearsPerColor++;
    }

    $colorWheel = ["Unknown" => $unknownColor];
    $i = 0;
    for ($year_i = $startYear; $year_i <= $endYear; $year_i += $yearsPerColor) {
        for ($year = $year_i; $year < $year_i + $yearsPerColor; $year++) {
            $colorWheel[$year] = $colors[$i];
        }
        $i++;
    }
    return $colorWheel;
}

function collapseColorWheel($colorWheel) {
    $newWheel = [];
    $reverseWheel = [];
    ksort($colorWheel);
    foreach ($colorWheel as $label => $hex) {
        if (is_numeric($label)) {
            $year = $label;
            if (!isset($reverseWheel[$hex])) {
                $reverseWheel[$hex] = ["start" => $year, "end" => $year];
            }
            $reverseWheel[$hex]["end"] = $year;
        } else {
            # unknown
            $newWheel[$label] = $hex;
        }
    }
    foreach ($reverseWheel as $hex => $ary) {
        if ($ary["start"] == $ary["end"]) {
            $yearspan = $ary["start"];
        } else {
            $yearspan = $ary["start"]."-".$ary["end"];
        }
        $newWheel[$yearspan] = $hex;
    }
    return $newWheel;
}

function makeLegendHTML($indexByField) {
    if ($indexByField == PUBYEAR_SELECT) {
        $colorWheel = generateColorWheel(8, START_YEAR, date("Y"));
        $colorWheel = collapseColorWheel($colorWheel);

        $lines = [];
        foreach ($colorWheel as $label => $hex) {
            $lines[] = "<span style='background-color: $hex'>&nbsp;&nbsp;&nbsp;</span> $label";
        }
        return "<div style='background-color: white;' class='smaller centered max-width'>".implode("&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;", $lines)."</div>";
    }
    return "";
}

function makeEdges($matches, $indexByField, $names, $choices, $index, $pubs) {
    $totalCollaborations = 0;
    if ($indexByField == PUBYEAR_SELECT) {
        $colorWheel = generateColorWheel(8, START_YEAR, date("Y"));
    } else {
        $colorWheel = getPlainColorWheel();
    }
    $combine = ["VCTRS", "VPSD", "VFRS"];
    $collaborations = ["given" => [], "received" => [], ];
    $chartData = [];
    $uniqueNames = [];
    foreach (array_keys($matches) as $fromRecordId) {
        $collaborations["given"][$fromRecordId] = [];
        foreach ($matches[$fromRecordId] as $toRecordId => $fromInstances) {
            if (isForIndividualScholars($indexByField)) {
                if (isset($names[$fromRecordId])) {
                    $from = $fromRecordId.": ".$names[$fromRecordId];
                } else {
                    # mentor
                    $from = $fromRecordId;
                }
                if (isset($names[$toRecordId])) {
                    $to = $toRecordId . ": " . $names[$toRecordId];
                } else {
                    # mentor
                    $to = $toRecordId;
                }
            } else if ($choices[$indexByField]) {
                $from = $choices[$indexByField][$index[$fromRecordId]];
                $to = $choices[$indexByField][$index[$toRecordId]];
            } else {
                $from = $index[$fromRecordId];
                $to = $index[$toRecordId];
            }
            foreach ([$to, $from] as $name) {
                if (!in_array($name, $uniqueNames)) {
                    $uniqueNames[] = $name;
                }
            }
            if (in_array($from, $combine)) {
                $from = implode(" / ", $combine);
            }
            if (in_array($to, $combine)) {
                $to = implode(" / ", $combine);
            }
            $numItems = count($fromInstances);
            $chartRow = ["from" => $from, "to" => $to];
            if ($indexByField == PUBYEAR_SELECT) {
                $dates = [];
                foreach ($pubs as $key => $ts) {
                    $nodes = preg_split("/:/", $key);
                    if ($ts && ($fromRecordId == $nodes[0]) && ($toRecordId == $nodes[1])) {
                        $year = date("Y", $ts);
                        if (!isset($dates[$year])) {
                            $dates[$year] = 0;
                        }
                        $dates[$year]++;
                    }
                }
                if (count($dates) > 0) {
                    foreach ($dates as $year => $count) {
                        $newRow = $chartRow;
                        $newRow['nodeColor'] = $colorWheel[$year];
                        $newRow['value'] = $count;
                        $chartData[] = $newRow;
                    }
                } else {
                    $chartRow['nodeColor'] = $colorWheel['Unknown'];
                    $chartRow['value'] = $numItems;
                    $chartData[] = $chartRow;
               }
            } else {
                $chartRow['value'] = $numItems;
                $chartRow['nodeColor'] = $colorWheel[count($chartData) % count($colorWheel)];
                $chartData[] = $chartRow;
            }
            $collaborations["given"][$fromRecordId][$toRecordId] = $numItems;

            if (!isset($collaborations["received"][$toRecordId])) {
                $collaborations["received"][$toRecordId] = [];
            }
            $collaborations["received"][$toRecordId][$fromRecordId] = $numItems;
            $totalCollaborations += $numItems;
        }
    }
    return [$collaborations, $chartData, $uniqueNames];
}

function findGrantMatchesForRecord(&$index, &$coeusAwardNumbers, $token, $server, $fields, $fromRecordId, $indexByField, $records, $userids, $names) {
    $redcapData = Download::fieldsForRecords($token, $server, $fields, [$fromRecordId]);
    $matches = [];
    foreach ($redcapData as $row) {
        if ($row['redcap_repeat_instrument'] == "") {
            $index[$fromRecordId] = $row[$indexByField];
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

function findMatchesForRecord(&$index, &$pubs, $token, $server, $fields, $fromRecordId, $possibleFirstNames, $possibleLastNames, $indexByField, $records, $startTs, $endTs) {
    $ids = [];
    $fields[] = "identifier_last_name";
    $fields[] = "identifier_first_name";
    $fields = array_unique($fields);
    $redcapData = Download::fieldsForRecords($token, $server, $fields, [$fromRecordId]);
    $matches = [];
    $fromLastName = "";
    $fromFirstName = "";
    foreach ($redcapData as $row) {
        if ($row['redcap_repeat_instrument'] == "") {
            $index[$fromRecordId] = $row[$indexByField];
            $fromFirstName = $row['identifier_first_name'];
            $fromLastName = $row['identifier_last_name'];
        }
    }
    foreach ($redcapData as $row) {
        $authors = [];
        if (($row['redcap_repeat_instrument'] == "citation") && ($row['citation_include'] == '1') && $row['citation_include']) {
            $authors = preg_split("/,\s*/", $row['citation_authors']);
        } else if (($row['redcap_repeat_instrument'] == "eric") && ($row['eric_include'] == "1") && $row['eric_author']) {
            $authors = preg_split("/;\s*/", $row['eric_author']);
        }
        foreach ($authors as $author) {
            list($authorFirst, $authorLast) = NameMatcher::splitName($author);
            foreach (array_keys($possibleFirstNames) as $toRecordId) {
                if ($toRecordId != $fromRecordId && in_array($toRecordId, $records)) {
                    foreach ($possibleFirstNames[$toRecordId] as $firstName) {
                        foreach ($possibleLastNames[$toRecordId] as $lastName) {
                            if (
                                (
                                    ($row['redcap_repeat_instrument'] == "citation")
                                    && NameMatcher::matchByInitials($authorLast, $authorFirst, $lastName, $firstName)
                                    && !NameMatcher::matchByInitials($authorLast, $authorFirst, $fromLastName, $fromFirstName)
                                )
                                || (
                                    ($row['redcap_repeat_instrument'] == "eric")
                                    && NameMatcher::matchName($authorLast, $authorFirst, $lastName, $firstName)
                                    && !NameMatcher::matchName($authorLast, $authorFirst, $fromLastName, $fromFirstName)
                                )
                            ) {
                                $ts = getCitationTimestamp($row);
                                if (canApproveTimestamp($ts, $startTs, $endTs)) {
                                    if ($row['redcap_repeat_instrument'] == "citation") {
                                        $ids[$row['citation_pmid']] = $row['citation_num_citations'];
                                    } else if ($row['redcap_repeat_instrument'] == "eric") {
                                        $ids[$row['eric_id']] = 1;
                                    }
                                    if (isset($_GET['test'])) {
                                        echo "Matched $fromRecordId $authorLast, $authorFirst to $toRecordId $lastName, $firstName<br>";
                                    }
                                    if (!isset($matches[$toRecordId])) {
                                        $matches[$toRecordId] = [];
                                    }
                                    $matches[$toRecordId][] = $row['redcap_repeat_instrument'];
                                    if ($ts) {
                                        $pubs["$fromRecordId:$toRecordId:".$row['redcap_repeat_instance']] = $ts;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    return [$matches, array_unique($ids)];
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

function makeSummaryStats($collaborations, $names, $numCollabsToShow) {
    $stats = [];
    $maxCollaborations = [];
    $maxNames = [];
    $collabsInOrder = [];
    $maxCollaborators = [];
    $maxCollabNames = [];
    $totalCollaborators = [];
    foreach ($collaborations as $type => $typeCollaborations) {
        $dataValues = [];
        $maxCollaborators[$type] = [];
        $totalCollaborators[$type] = 0;
        $collabsInOrder[$type] = [];
        $maxCollaborations[$type] = [];
        foreach ($typeCollaborations as $recordId => $indivCollaborations) {
            $numCollaborations = array_sum(array_values($indivCollaborations));
            if ($numCollaborations > 0) {
                $dataValues[] = $numCollaborations;
                $numCollaborators = count($indivCollaborations);
                $totalCollaborators[$type] += $numCollaborators;
                if (!isset($collabsInOrder[$type][$numCollaborators])) {
                    $collabsInOrder[$type][$numCollaborators] = [];
                }
                $collabsInOrder[$type][$numCollaborators][] = $recordId;
            }
        }
        $stats[$type] = new Stats($dataValues);
        rsort($dataValues);
        for ($i = 0; $i < $numCollabsToShow; $i++) {
            $maxCollaborations[$type][$i] = $dataValues[$i] ?: 0;
        }
        $maxNames[$type] = [];
        $maxCollabNames[$type] = [];
        krsort($collabsInOrder[$type]);
        for ($i = 0; $i < $numCollabsToShow; $i++) {
            if (!empty($collabsInOrder[$type])) {
                $keys = array_keys($collabsInOrder[$type]);
                if (isset($keys[$i])) {
                    $maxCollaborators[$type][$i] = $keys[$i];
                } else if ($i > 0) {
                    $maxCollaborators[$type][$i] = $maxCollaborators[$type][$i - 1];
                } else {
                    $maxCollaborators[$type][$i] = 0;
                }
            } else {
                $maxCollaborators[$type][$i] = 0;
            }
            $maxNames[$type][$i] = [];
            $maxCollabNames[$type][$i] = [];
            foreach ($typeCollaborations as $recordId => $indivCollaborations) {
                $numCollaborations = array_sum(array_values($indivCollaborations));
                $numCollaborators = count($indivCollaborations);

                if ($numCollaborations == $maxCollaborations[$type][$i]) {
                    if (isset($names[$recordId])) {
                        $maxNames[$type][$i][] = $names[$recordId];
                    } else {
                        # mentor
                        $maxNames[$type][$i][] = $recordId;
                    }
                }
                if ($numCollaborators == $maxCollaborators[$type][$i]) {
                    if (isset($names[$recordId])) {
                        $maxCollabNames[$type][$i][] = $names[$recordId];
                    } else {
                        # mentor
                        $maxCollabNames[$type][$i][] = $recordId;
                    }
                }
            }
        }
    }
    return [$stats, $maxCollaborations, $maxNames, $maxCollaborators, $maxCollabNames, $totalCollaborators];
}

function addMentorNamesForRecords(&$firstNames, &$lastNames, &$records, $mentors, $highlightedRecord = FALSE) {
    foreach ($mentors as $recordId => $mentorList) {
        $useThisRecord = !$highlightedRecord || ($highlightedRecord == $recordId);
        if (in_array($recordId, $records) && $useThisRecord) {
            $i = 1;
            foreach ($mentorList as $mentor) {
                list($first, $last) = NameMatcher::splitName($mentor);
                $first = NameMatcher::dashes2Spaces($first);
                $last = NameMatcher::dashes2Spaces($last);
                $alreadyPresent = FALSE;
                foreach ($firstNames as $currRecordId => $currFirst) {
                    $currLast = $lastNames[$currRecordId];
                    if (NameMatcher::matchByInitials($last, $first, $currLast, $currFirst)) {
                        $alreadyPresent = TRUE;
                        break;
                    }
                }
                if (!$alreadyPresent) {
                    $key = "Mentor $first $last";
                    $records[] = $key;
                    $firstNames[$key] = NameMatcher::explodeFirstName($first);
                    $lastNames[$key] = NameMatcher::explodeLastName($last);
                }
                $i++;
            }
        }
    }
}

function isForIndividualScholars($indexByField) {
    return in_array($indexByField, ["record_id", PUBYEAR_SELECT]);
}

function getCollaborationsRepresented($stats) {
    $collaborationsRepresented = 0;
    foreach ($stats as $type => $s) {
        $collaborationsRepresented += array_sum($s->getValues());
    }
    return $collaborationsRepresented;
}