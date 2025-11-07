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
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");
if ($_GET['record']) {
    $highlightedRecord = REDCapManagement::sanitize($_GET['record']);
} else {
    $highlightedRecord = FALSE;
}

$metadata = Download::metadata($token, $server);
$metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
$choices = REDCapManagement::getChoices($metadata);
$metadataLabels = REDCapManagement::getLabels($metadata);
if ($_GET['cohort'] && ($_GET['cohort'] != "all")) {
    $records = Download::cohortRecordIds($token, $server, Application::getModule(), $_GET['cohort']);
} else if ($_GET['cohort'] == "all") {
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
    $indexByField = $_GET['field'];
} else {
    $indexByField = "record_id";
}
$includeMentors = ($_GET['mentors'] == "on") && ($indexByField == 'record_id');
$otherMentorsOnly = ($_GET['other_mentors'] == "on") && ($indexByField == 'record_id');

$cohorts = new Cohorts($token, $server, Application::getModule());

if ($includeHeaders) {
    echo "<h1>Publishing Collaborations Among Scholars</h1>\n";
    list($url, $params) = REDCapManagement::splitURL(Application::link("socialNetwork/shadedCoauthorship.php"));
    echo "<form method='GET' action='$url'>\n";
    foreach ($params as $param => $value) {
        echo "<input type='hidden' name='$param' value='$value'>";
    }
    echo "<p class='centered'><a href='" . Application::link("cohorts/addCohort.php") . "'>Make a Cohort</a> to View a Sub-Group<br>";
    echo $cohorts->makeCohortSelect($_GET['cohort'], "", TRUE) . "<br>";
    echo makeFieldSelect($indexByField, $possibleFields, $metadataLabels) . "<br>";
    $style = "";
    if ($indexByField != "record_id") {
        $style = " style='display: none;'";
    }
    $checked = [];
    foreach (['mentors', 'other_mentors'] as $key) {
        if ($_GET[$key] == "on") {
            $checked[$key] = " checked";
        } else {
            $checked[$key] = "";
        }
    }

    echo "<span class='mentorCheckbox'$style><input type='checkbox' name='mentors'{$checked['mentors']}> Include Mentors' Collaborations with Scholars<br></span>";
    echo "<span class='mentorCheckbox'$style><input type='checkbox' name='other_mentors'{$checked['other_mentors']}> Show Only Collaborations with Multiple Mentors<br></span>";
    echo "<button>Go!</button></p></form>";
}

if (isset($_GET['cohort']) && !empty($records)) {
    $inlineDefinitions = [
        "given" => "Citations <u>to</u> others in network",
        "received" => "Citations <u>by</u> others in network",
    ];
    $topDefinitions = [
        "Scholar" => "The person whose publication is being examined.",
        "Collaborator" => "A different person who has co-authored a paper with the Scholar.",
        "Connection" => "A paper published with a Scholar and a Collaborator. (One paper may have more than one connection.)",
    ];

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
    $fields = ["record_id", "citation_include", "citation_authors", "citation_year", "citation_month", "citation_day"];
    if (!in_array($indexByField, $fields)) {
        $fields[] = $indexByField;
    }

    $matches = [];
    $pubs = [];
    $index = [];
    if ($highlightedRecord) {
        foreach ($records as $fromRecordId) {
            if ($fromRecordId == $highlightedRecord) {
                $matches[$highlightedRecord] = findMatchesForRecord($index, $pubs, $token, $server, $fields, $highlightedRecord, $possibleFirstNames, $possibleLastNames, $indexByField, $records);
            } else {
                $matches[$fromRecordId] = [];
            }
        }
    } else {
        foreach ($records as $fromRecordId) {
            $fromMatches = findMatchesForRecord($index, $pubs, $token, $server, $fields, $fromRecordId, $possibleFirstNames, $possibleLastNames, $indexByField, $records);
            if ($otherMentorsOnly) {
                if (count($fromMatches) > 1) {
                    $matches[$fromRecordId] = $fromMatches;
                }
            } else {
                $matches[$fromRecordId] = $fromMatches;
            }
        }
    }

    list($connections, $chartData, $uniqueNames) = makeEdges($matches, $indexByField, $names, $choices, $index, $token, $server);
    if ($includeMentors) {
        $mentorConnections = getAvgMentorConnections($matches);
    } else {
        $mentorConnections = 0;
    }
    list($stats, $maxConnections, $maxNames, $maxCollaborators, $maxCollabNames, $totalCollaborators) = makeSummaryStats($connections, $names);

    if ($includeHeaders) {
        echo "<table style='margin: 30px auto; max-width: 800px;' class='bordered'>\n";
        echo "<tr><td colspan='2'><h4 class='nomargin'>Definitions</h4>";
        $lines = [];
        foreach ($topDefinitions as $term => $def) {
            $lines[] = "<b>$term</b> - $def";
        }
        echo "<div class='centered'>".implode("<br>", $lines)."</div>";
        foreach (array_keys($connections) as $type) {
            echo "<tr><td colspan='2' class='centered green'><h4 class='nomargin'>".ucfirst($type)."</h4>{$inlineDefinitions[$type]}</td></tr>";
            if ($stats) {
                echo "<tr><th>Total Connections</th><td>".REDCapManagement::pretty(array_sum($stats[$type]->getValues()))."</td></tr>\n";
                echo "<tr><th>Number of Scholars with Connections</th><td>n = ".REDCapManagement::pretty($stats[$type]->getN())."</td></tr>\n";
                echo "<tr><th>Average Number of Collaborators with at least one Connection</th><td>".REDCapManagement::pretty($totalCollaborators[$type] / $stats[$type]->getN(), 1)."</td></tr>\n";
                echo "<tr><th>Mean of Connections</th><td>&mu; = ".REDCapManagement::pretty($stats[$type]->mean(), 1)."</td></tr>\n";
                echo "<tr><th>Average Connections with a Collaborator</th><td>".REDCapManagement::pretty($stats[$type]->sum() / $totalCollaborators[$type], 1)."</td></tr>\n";
                echo "<tr><th>Median of Connections</th><td>".REDCapManagement::pretty($stats[$type]->median(), 1)."</td></tr>\n";
                echo "<tr><th>Mode of Connections</th><td>".implode(", ", $stats[$type]->mode())."</td></tr>\n";
                echo "<tr><th>Standard Deviation</th><td>&sigma; = ".REDCapManagement::pretty($stats[$type]->getSigma(), 1)."</td></tr>\n";
                echo "<tr><th>Maximum Connections</th><td>max = ".REDCapManagement::pretty($maxConnections[$type])." (".REDCapManagement::makeConjunction($maxNames[$type]).")</td></tr>\n";
                echo "<tr><th>Maximum Number of Collaborators</th><td>max = ".REDCapManagement::pretty($maxCollaborators[$type])." (".REDCapManagement::makeConjunction($maxCollabNames[$type]).")</td></tr>\n";
                if ($includeMentors && ($type == "given")) {
                    echo "<tr><th>Average Connections per Mentor with All Scholars</th><td>".REDCapManagement::pretty($mentorConnections, 1)."</td></tr>\n";
                }
            }
        }
        echo "</table>\n";
    }

    $socialNetwork = new SocialNetworkChart($networkChartName, $chartData);
    $socialNetwork->setNonRibbon(count($uniqueNames) > 100);
    echo $socialNetwork->getImportHTML();
    echo $socialNetwork->getHTML(900, 700);

    if ($includeHeaders) {
        echo "<br><br>";
        list($barChartCols, $barChartLabels) = makePublicationColsAndLabels($pubs);
        $chart = new BarChart($barChartCols, $barChartLabels, "barChart");
        $chart->setXAxisLabel("Year");
        $chart->setYAxisLabel("Number of Connections");
        echo $chart->getImportHTML();
        echo $chart->getHTML(500, 300);
    }
}




function getCitationTimestamp($row) {
    $year = Citation::transformYear($row['citation_year']);
    $month = $row['citation_month'];
    $day = $row['citation_day'];
    return Citation::transformDateToTimestamp(Citation::transformIntoDate($year, $month, $day));
}

function makePublicationColsAndLabels($pubs) {
    $data = [];
    foreach ($pubs as $loc => $ts) {
        $year = (int) date("Y", $ts);
        if (!isset($data[$year])) {
            $data[$year] = 0;
        }
        $data[$year]++;
    }
    ksort($data);
    if (!empty($data)) {
        $dataKeys = [];
        foreach (array_keys($data) as $key) {
            $dataKeys[] = $key;
        }

        if (!empty($dataKeys)) {
            $min = min($dataKeys);
            $max = max($dataKeys);
            for ($year = $min; $year <= $max; $year++) {
                if (!isset($data[$year])) {
                    $data[$year] = 0;
                }
            }
        }
    }
    ksort($data);

    $labels = array_keys($data);
    $cols = array_values($data);
    return [$cols, $labels];
}

function makeFieldSelect($selectedField, $fields, $metadataLabels) {
    $html = "";
    $html .= "Index by Field: <select name='field' onchange='if ($(this).val() == \"record_id\") { $(\".mentorCheckbox\").show(); } else { $(\".mentorCheckbox\").hide(); }'>";
    foreach ($fields as $field) {
        $selected = "";
        if ($field == $selectedField) {
            $selected = " selected";
        }
        $html .= "<option value='$field'$selected>".$metadataLabels[$field]."</option>";
    }
    $html .= "</select>";
    return $html;
}

function getAvgMentorConnections($matches) {
    $mentorConnections = 0;
    $mentorCollaborators = [];
    foreach (array_keys($matches) as $fromRecordId) {
        foreach ($matches[$fromRecordId] as $toRecordId => $fromInstances) {
            if (preg_match("/^Mentor/", $toRecordId)) {
                $mentor = $toRecordId;
                $mentorConnections += count($fromInstances);
                if (!in_array($mentor, $mentorCollaborators)) {
                    $mentorCollaborators[] = $mentor;
                }
            }
        }
    }
    return $mentorConnections / count($mentorCollaborators);
}

function getTypeForRecord($oneFieldData, $recordId, $combine) {
    $value = $oneFieldData[$recordId];
    if (in_array($value, $combine)) {
        if (isset($_GET['newOnly'])) {
            return FALSE;
        } else {
            return implode(" / ", $combine);
        }
    } else {
        return $value;
    }
}

function getNodeColorIndex($from, $fromType, $combine) {
    $combineType = implode(" / ", $combine);
    if ($fromType == "Mentor") {
        return 0;
    }else if ($fromType == $combineType) {
        return 2;
    } else if ($fromType == "KL2") {
        return 3;
    } else if ($fromType == "TL1") {
        return 4;
    }
    throw new \Exception("Invalid node type $from $fromType");
}

function getEdgeColorIndex($fromType, $toType, $combine) {
    $combineType = implode(" / ", $combine);
    $validTypes = ["Mentor", $combineType, "KL2", "TL1"];
    if (!in_array($fromType, $validTypes)) {
        throw new \Exception("Invalid From Type $fromType!");
    }
    if (!in_array($toType, $validTypes)) {
        throw new \Exception("Invalid To Type $toType!");
    }

    if (($toType == "Mentor") || ($fromType == "Mentor")) {
        return 0;
    }
    if ($fromType == $combineType) {
        return 2;
    } else if ($fromType == "KL2") {
        return 3;
    } else if ($fromType == "TL1") {
        return 4;
    } else {
        throw new \Exception("This should never happen ($fromType, $toType)");
    }
}

function makeNodeName($recordId, $indexByField, $colorData, $combine, $choices, $index, $names) {
    $fromType = FALSE;
    if ($indexByField == "record_id") {
        if ($names[$recordId]) {
            $from = $recordId.": ".$names[$recordId];
            $fromType = getTypeForRecord($colorData, $recordId, $combine);
            return [$from, $fromType];
        } else {
            # mentor
            $fromType = "Mentor";
            $from = $recordId;
            return [$from, $fromType];
        }
    } else if ($choices[$indexByField]) {
        $from = $choices[$indexByField][$index[$recordId]];
    } else {
        $from = $index[$recordId];
    }
    return [$from, $fromType];
}

function makeEdges($matches, $indexByField, $names, $choices, $index, $token, $server) {
    if (isset($_GET['blackAndWhite'])) {
        $colorWheel = ["#000000", "#808080", "#C0C0C0", "#DCDCDC", "#F5F5F5",];
    } else {
        // $colorWheel = ["#003f5c", "#955196", "#dd5182", "#ff6e54", "#ffa600",];  // https://learnui.design/tools/data-color-picker.html#palette
        $colorWheel = ["#003f5c", "#955196", "#ff6e54", "#dd5182", "#ffda00",];
    }
    $combine = ["VCTRS", "VPSD", "VFRS"];
    $connections = ["given" => [], "received" => [], ];
    $chartData = [];
    $uniqueNames = [];
    $colorData = Download::oneField($token, $server, "identifier_grant_type");
    foreach (array_keys($matches) as $fromRecordId) {
        if (count($matches[$fromRecordId]) > 0) {
            list($from, $fromType) = makeNodeName($fromRecordId, $indexByField, $colorData, $combine, $choices, $index, $names);
            if ($fromType) {
                $colorIndex = getNodeColorIndex($from, $fromType, $combine);
                $chartRow = ["from" => $from, "nodeColor" => $colorWheel[$colorIndex]];
                $chartData[] = $chartRow;
            }
        }
    }
    $totalConnections = 0;
    foreach (array_keys($matches) as $fromRecordId) {
        $connections["given"][$fromRecordId] = [];
        list($from, $fromType) = makeNodeName($fromRecordId, $indexByField, $colorData, $combine, $choices, $index, $names);
        foreach ($matches[$fromRecordId] as $toRecordId => $fromInstances) {
            list($to, $toType) = makeNodeName($toRecordId, $indexByField, $colorData, $combine, $choices, $index, $names);
            foreach ([$to, $from] as $name) {
                if (!in_array($name, $uniqueNames)) {
                    $uniqueNames[] = $name;
                }
            }
            $numItems = count($fromInstances);
            $chartRow = ["from" => $from, "to" => $to, "value" => $numItems];
            if ($fromType && $toType) {
                $colorIndex = getEdgeColorIndex($fromType, $toType, $combine);
                $chartRow["nodeColor"] = $colorWheel[$colorIndex];
                $chartRow["colorIndex"] = $colorIndex;
            }
            if (!isset($_GET['newOnly'])) {
                $chartData[] = $chartRow;
            } else if ($fromType && $toType) {
                $chartData[] = $chartRow;
            }
            $connections["given"][$fromRecordId][$toRecordId] = $numItems;

            if (!isset($connections["received"][$toRecordId])) {
                $connections["received"][$toRecordId] = [];
            }
            $connections["received"][$toRecordId][$fromRecordId] = $numItems;
            $totalConnections += $numItems;
        }
    }
    return [$connections, $chartData, $uniqueNames];
}

function findMatchesForRecord(&$index, &$pubs, $token, $server, $fields, $fromRecordId, $possibleFirstNames, $possibleLastNames, $indexByField, $records) {
    $redcapData = Download::fieldsForRecords($token, $server, $fields, [$fromRecordId]);
    $matches = [];
    foreach ($redcapData as $row) {
        if ($row['redcap_repeat_instrument'] == "") {
            $index[$fromRecordId] = $row[$indexByField];
        }
        if (($row['redcap_repeat_instrument'] == "citation") && ($row['citation_include'] == '1')) {
            $authors = preg_split("/,\s*/", $row['citation_authors']);
            foreach ($authors as $author) {
                list($authorInitials, $authorLast) = NameMatcher::splitName($author);
                foreach (array_keys($possibleFirstNames) as $toRecordId) {
                    if ($toRecordId != $fromRecordId && in_array($toRecordId, $records)) {
                        foreach ($possibleFirstNames[$toRecordId] as $firstName) {
                            foreach ($possibleLastNames[$toRecordId] as $lastName) {
                                if (NameMatcher::matchByInitials($authorLast, $authorInitials, $lastName, $firstName)) {
                                    // echo "Matched $fromRecordId $authorLast, $authorInitials to $toRecordId $lastName, $firstName<br>";
                                    if (!isset($matches[$toRecordId])) {
                                        $matches[$toRecordId] = [];
                                    }
                                    $matches[$toRecordId][] = $row['redcap_repeat_instance'];
                                    $ts = getCitationTimestamp($row);
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
    return $matches;
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

function makeSummaryStats($connections, $names) {
    $stats = [];
    $maxConnections = [];
    $maxNames = [];
    $maxCollaborators = [];
    $maxCollabNames = [];
    $totalCollaborators = [];
    foreach ($connections as $type => $typeConnections) {
        $dataValues = [];
        $maxCollaborators[$type] = 0;
        $totalCollaborators[$type] = 0;
        foreach ($typeConnections as $recordId => $indivConnections) {
            $numConnections = array_sum(array_values($indivConnections));
            if ($numConnections > 0) {
                $dataValues[] = $numConnections;
                $numCollaborators = count($indivConnections);
                $totalCollaborators[$type] += $numCollaborators;
                if ($numCollaborators > $maxCollaborators[$type]) {
                    $maxCollaborators[$type] = $numCollaborators;
                }
            }
        }
        $stats[$type] = new Stats($dataValues);
        $maxConnections[$type] = !empty($dataValues) ? max($dataValues) : 0;
        $maxNames[$type] = [];
        $maxCollabNames[$type] = [];
        foreach ($typeConnections as $recordId => $indivConnections) {
            $numConnections = array_sum(array_values($indivConnections));
            $numCollaborators = count($indivConnections);
            if ($numConnections == $maxConnections[$type]) {
                if ($names[$recordId]) {
                    $maxNames[$type][] = $names[$recordId];
                } else {
                    # mentor
                    $maxNames[$type][] = $recordId;
                }
            }
            if ($numCollaborators == $maxCollaborators[$type]) {
                if ($names[$recordId]) {
                    $maxCollabNames[$type][] = $names[$recordId];
                } else {
                    # mentor
                    $maxCollabNames[$type][] = $recordId;
                }
            }
        }
    }
    return [$stats, $maxConnections, $maxNames, $maxCollaborators, $maxCollabNames, $totalCollaborators];
}

function addMentorNamesForRecords(&$firstNames, &$lastNames, &$records, $mentors, $highlightedRecord = FALSE) {
    foreach ($mentors as $recordId => $mentorList) {
        $useThisRecord = !$highlightedRecord || ($highlightedRecord == $recordId);
        if (in_array($recordId, $records) && $useThisRecord) {
            $i = 1;
            foreach ($mentorList as $mentor) {
                list($first, $last) = NameMatcher::splitName($mentor);
                $alreadyPresent = FALSE;
                foreach ($firstNames as $currRecordId => $currFirst) {
                    $currLast = $lastNames[$currRecordId];
                    if (NameMatcher::matchByInitials($last, $first, $currLast, $currFirst)) {
                        $alreadyPresent = TRUE;
                        break;
                    }
                }
                if (!$alreadyPresent) {
                    $key = "Mentor $mentor";
                    $records[] = $key;
                    $firstNames[$key] = NameMatcher::explodeFirstName($first);
                    $lastNames[$key] = NameMatcher::explodeLastName($last);
                }
                $i++;
            }
        }
    }
}

function findColors($records, $token, $server, $field) {

}