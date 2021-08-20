<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$metadata = Download::metadata($token, $server);
$metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
$fieldLabels = REDCapManagement::getLabels($metadata);
$surveys = REDCapManagement::getSurveys($pid, $metadata);
$module = Application::getModule();
$names = Download::names($token, $server);

$orderedSurveys = ["initial_survey", "followup"];
foreach (array_keys($surveys) as $instrument) {
    if (!in_array($instrument, $orderedSurveys)) {
        $orderedSurveys[] = $instrument;
    }
}

$fields = ["record_id" => ""];
foreach ($orderedSurveys as $instrument) {
    $fields[$instrument."_complete"] = $instrument;
    $prefix = REDCapManagement::getPrefixFromInstrument($instrument);
    $suffixes = ["_last_update", "_date"];
    foreach ($suffixes as $suffix) {
        $lastUpdateField = $prefix.$suffix;
        if (in_array($lastUpdateField, $metadataFields)) {
            $fields[$lastUpdateField] = $instrument;
        }
    }
}
$instrumentsToFields = [];
foreach ($fields as $field => $instrument) {
    if (!isset($instrumentsToFields[$instrument])) {
        $instrumentsToFields[$instrument] = [];
    }
    $instrumentsToFields[$instrument][] = $field;
}

$firstNames = Download::firstnames($token, $server);
$lastNames = Download::lastnames($token, $server);
$redcapData = Download::fields($token, $server, array_keys($fields));

if (method_exists($module, "getPids")) {
    $projectPids = $module->getPids();
} else if (isset($info['prod'])) {
    // Vanderbilt only - plugin project
    $projectPids = [$info['prod']['pid']];   // mother ship
    $prefix = CareerDev::getPrefix();
    $sql = "SELECT DISTINCT(s.project_id) AS project_id FROM redcap_external_module_settings AS s INNER JOIN redcap_external_modules AS m ON m.external_module_id = s.external_module_id WHERE m.directory_prefix = '$prefix'";
    $q = db_query($sql);
    if ($error = db_error()) {
        throw new \Exception("SQL error: $error $sql");
    }
    while ($row = db_fetch_assoc($q)) {
        if (REDCapManagement::isActiveProject($row['project_id'])) {
            $projectPids[] = $row['project_id'];
        }
    }
} else {
    $projectPids = [];
}

if (isset($_GET['test'])) {
    echo "projectPids: ".json_encode($projectPids)."<br>";
}

$repeating = [];
$classical = [];
$skip = ["record_id"];
foreach ($redcapData as $row) {
    $recordId = $row['record_id'];
    if (!isset($classical[$recordId])) {
        $classical[$recordId] = [];
    }
    if (!isset($repeating[$recordId])) {
        $repeating[$recordId] = [];
    }
    foreach (array_keys($fields) as $field) {
        if (!in_array($field, $skip)) {
            if ($row['redcap_repeat_instrument'] && isset($row[$field]) && $row[$field]) {
                if (!isset($repeating[$recordId][$field])) {
                    $repeating[$recordId][$field] = [];
                }
                $repeating[$recordId][$field][$row['redcap_repeat_instance']] = $row[$field];
            } else if (isset($row[$field]) && $row[$field]) {
                $classical[$recordId][$field] = $row[$field];
            }
        }
    }
}

$completionChoices = [
    "" => "",
    "0" => "Incomplete",
    "1" => "Unverified",
    "2" => "Complete",
];
list($allNames, $allProjectTitles, $allProjectContacts, $allRespondants) = getProjectInfo($projectPids, array_keys($surveys));
if (isset($_GET['test'])) {
    echo "allNames: ".REDCapManagement::json_encode_with_spaces($allNames)."<br>";
    echo "allProjectTitles: ".REDCapManagement::json_encode_with_spaces($allProjectTitles)."<br>";
    echo "allProjectContacts: ".REDCapManagement::json_encode_with_spaces($allProjectContacts)."<br>";
    echo "allRespondants: ".REDCapManagement::json_encode_with_spaces($allRespondants)."<br>";
}

echo "<h1>Survey Responses</h1>";
echo "<p class='centered max-width'>Scroll over a value to reveal other projects where it is present.</p>";
echo "<br><br>";
echo "<table class='max-width centered bordered'>";
echo "<thead>";
echo "<tr class='whiteRow'>";
echo "<th>Name</th>";
foreach ($instrumentsToFields as $instrument => $fields) {
    if ($instrument) {
        $formName = $surveys[$instrument];
        $fieldHTML = [];
        foreach ($fields as $field) {
            $fieldLabel = $fieldLabels[$field] ?? ucwords(preg_replace("/_/", " ", $instrument))." Completion Status";
            $fieldHTML[] = "<div class='smallest skinnymargins'><span style='word-break: break-all;'>$field</span><br>$fieldLabel</div>";
        }
        echo "<th><h4 class='nomargin'>$formName</h4>";
        echo implode("", $fieldHTML)."</th>";
    }
}
echo "</tr>";
echo "</thead>";
echo "<tbody>";
foreach ($names as $recordId => $name) {
    $classicalFieldValues = $classical[$recordId] ?? [];
    $repeatingFieldValues = $repeating[$recordId] ?? [];
    echo "<tr class='whiteRow'>";
    echo "<th style='padding: 0 3px;'>".Links::makeRecordHomeLink($pid, $recordId, $recordId.": ".$name)."</th>";
    foreach ($instrumentsToFields as $instrument => $fields) {
        if ($instrument) {
            $instrumentName = $surveys[$instrument];
            $valueHTML = [];
            $valueTable = [];
            $isRepeating = FALSE;
            foreach ($fields as $field) {
                $value = "";
                if ($repeatingFieldValues[$field]) {
                    $isRepeating = TRUE;
                    if (count($repeatingFieldValues[$field]) == 1) {
                        foreach ($repeatingFieldValues[$field] as $instance => $value) {
                            if (REDCapManagement::isCompletionField($field)) {
                                $value = $completionChoices[$value];
                            }
                            break;
                        }
                    } else if (count($repeatingFieldValues[$field]) >= 2) {
                        foreach ($repeatingFieldValues[$field] as $i => $v) {
                            if (!isset($valueTable[$i])) {
                                $valueTable[$i] = [];
                                foreach ($fields as $f) {
                                    $valueTable[$i][$f] = "";
                                }
                            }
                            if (REDCapManagement::isCompletionField($field)) {
                                $v = $completionChoices[$v];
                            }
                            $valueTable[$i][$field] = $v;
                        }
                    }
                } else {
                    $value = ($classicalFieldValues[$field] ?? "");
                    if (REDCapManagement::isCompletionField($field)) {
                        $value = $completionChoices[$value];
                    }
                    $instance = 1;
                }
                if ($instance && !isset($valueHTML[$instance])) {
                    $valueHTML[$instance] = [];
                }
                if ($value) {
                    $valueHTML[$instance][] = $value;
                }
            }
            $tableOfValues = "";
            if (!empty($valueTable)) {
                $tableOfValues = "<table class='centered bordered'>";
                foreach ($valueTable as $instance => $fieldValues) {
                    $tableOfValues .= "<tr>";
                    foreach ($fields as $field) {
                        if (isset($fieldValues[$field])) {
                            $tooltip = makeTooltip($fieldValues[$field], $firstNames[$recordId], $lastNames[$recordId], $instrument, $instance, TRUE, $allNames, $allProjectTitles, $allProjectContacts, $allRespondants);
                            $tableOfValues .= "<td>" . ($fieldValues[$field] ? $tooltip : "<span class='smallest'>[Blank]</span>") . "</td>";
                        }
                    }
                    $tableOfValues .= "</tr>";
                }
                $tableOfValues .= "</table>";
                $valueWithTooltip = $tableOfValues;
            } else {
                if (count($valueHTML) == 1) {
                    foreach ($valueHTML as $instance => $htmlAry) {
                        $valueWithTooltip = makeTooltip(implode("<br>", $htmlAry), $firstNames[$recordId], $lastNames[$recordId], $instrument, $instance, $isRepeating, $allNames, $allProjectTitles, $allProjectContacts, $allRespondants);
                        break;
                    }
                } else {
                    throw new \Exception("Too many values (this should not happen): ".count($valueHTML)." for instrument $instrumentName");
                }
            }
            echo "<td>$valueWithTooltip</td>";
        }
    }
    echo "</tr>";
}
echo "</tbody>";
echo "</table>";

if (isset($_GET['test'])) {
    echo "allRespondants: ".json_encode($allRespondants)."<br>";
}

function getProjectInfo($pids, $instruments) {
    $allNames = [];
    $allProjectTitles = [];
    $allProjectContacts = [];
    $origPid = $_GET['pid'];
    foreach ($pids as $pid) {
        CareerDev::setPid($pid);
        $token = Application::getSetting("token", $pid);
        $server = Application::getSetting("server", $pid);
        if ($token && $server) {
            try {
                $allNames[$pid] = [
                    'first' => Download::firstnames($token, $server),
                    'last' => Download::lastnames($token, $server),
                ];
                $allProjectTitles[$pid] = Download::projectTitle($token, $server);

                $allProjectContacts[$pid] = [];
                $adminUserids = REDCapManagement::getDesignUseridsForProject($pid);
                foreach ($adminUserids as $userid) {
                    list($userFirst, $userLast) = REDCapManagement::getUserNames($userid);
                    $userEmail = REDCapManagement::getEmailFromUseridFromREDCap($userid);
                    if ($userEmail) {
                        $contact = "<a href='mailto:$userEmail'>$userFirst $userLast</a>";
                    } else {
                        $contact = "$userFirst $userLast";
                    }
                    $allProjectContacts[$pid][] = $contact;
                }
            } catch (\Exception $e) {
                Application::log("Exception ".$e->getMessage(), $pid);
            }
        } else {
            Application::log("$pid has no token", $pid);
        }
    }
    $allRespondants = [];
    foreach ($instruments as $instrument) {
        $allRespondants[$instrument] = getAllRespondants($pids, $instrument);
    }
    CareerDev::setPid($origPid);
    return [$allNames, $allProjectTitles, $allProjectContacts, $allRespondants];
}

function makeTooltip($value, $firstName, $lastName, $instrument, $instance, $isRepeating, $allNames, $allProjectTitles, $allProjectContacts, $allRespondants) {
    if ($value) {
        $respondants = $allRespondants[$instrument];
        if (isset($respondants[$instance])) {
            $respondants = $respondants[$instance];
        }
        $sourceData = findDataSource($firstName, $lastName, $respondants, $allNames, $allProjectTitles, $allProjectContacts);
        if (!empty($sourceData)) {
            $projectHTML = [];
            foreach ($sourceData as $pid => $datum) {
                if ($pid != $_GET['pid']) {
                    $myHTML = "";
                    if ($datum['title']) {
                        $myHTML .= "<h4 class='nomargin'>".$datum["title"]."</h4>";
                        $myHTML .= "<p class='nomargin smallest centered'>(PID $pid)</p>";
                    }
                    $thresholdContacts = 5;
                    if (empty($datum['contacts'])) {
                        $myHTML .= "<p class='smaller skinnymargins'>No contacts available.</p>";
                    } else {
                        $myHTML .= "<p class='skinnymargins'><span class='bolded underline'>Contacts</span><br><span class='smaller'>(Click to Email)</span></p>";
                        $myHTML .= "<div>";
                        if (count($datum['contacts']) <= $thresholdContacts) {
                            $myHTML .= "<div>".implode("<br>", $datum['contacts'])."</div>";
                        } else {
                            $limitedContacts = [];
                            for ($i = 0; $i < $thresholdContacts; $i++) {
                                $limitedContacts[] = $datum['contacts'][$i];
                            }
                            $otherContacts = [];
                            for ($i = $thresholdContacts; $i < count($datum['contacts']); $i++) {
                                $otherContacts[] = $datum['contacts'][$i];
                            }
                            $myHTML .= "<div>".implode("<br>", $limitedContacts) . "<br><span style='text-decoration: underline;' onclick='$(this).parent().find(\".otherNames\").show(); $(this).hide();'>more...</span><div class='otherNames' style='display: none;'>" . implode("<br>", $otherContacts) . "</div></div>";
                        }
                        $myHTML .= "</div>";
                    }
                    $projectHTML[] =  $myHTML;
                }
            }
            if (empty($projectHTML)) {
                return $value;
            }
            $introText = "also in";
            if ($isRepeating) {
                $introText = "instance $instance also in";
            }
            $html = "<p class='smallest skinnymargins'>$introText</p>".implode("<hr>", $projectHTML);
            return "<span class='tooltip'><span class='widetooltiptext centered'>$html</span>$value</span>";
        } else {
            return $value;
        }
    }
    return "";
}

function findDataSource($firstName, $lastName, $allRespondantsForInstrument, $allNames, $allProjectTitles, $allProjectContacts) {
    if (empty($allNames)) {
        return [[], []];
    }

    $matchedProjects = matchRespondantsToName($allNames, $allRespondantsForInstrument, $firstName, $lastName);
    $projectData = [];
    foreach ($matchedProjects as $pid) {
        $projectData[$pid] = [
            "title" => $allProjectTitles[$pid],
            "contacts" => $allProjectContacts[$pid],
            ];
    }
    return $projectData;
}

function matchRespondantsToName($allNames, $allRespondants, $firstName, $lastName) {
    $matchedProjects = [];
    foreach ($allNames as $pid => $fieldValues) {
        if (isset($allRespondants[$pid])) {
            $projectLastNames = $fieldValues['last'];
            $projectFirstNames = $fieldValues['first'];
            foreach ($allRespondants[$pid] as $recordId) {
                if (
                    !in_array($pid, $matchedProjects)
                    && NameMatcher::matchName($projectFirstNames[$recordId], $projectLastNames[$recordId], $firstName, $lastName)
                ) {
                    $matchedProjects[] = $pid;
                }
            }
        }
    }
    return $matchedProjects;
}

function getAllRespondants($pids, $instrument) {
    if (empty($pids)) {
        return [];
    }
    $escapedPids = [];
    foreach ($pids as $pid) {
        $escapedPids[] = db_real_escape_string($pid);
    }
    $pidStr = "'".implode("', '", $escapedPids)."'";
    $sql = "SELECT r.record AS record, r.instance AS instance, s.project_id AS project_id FROM redcap_surveys AS s INNER JOIN redcap_surveys_participants AS p ON p.survey_id = s.survey_id INNER JOIN redcap_surveys_response AS r ON r.participant_id = p.participant_id WHERE s.project_id IN ($pidStr) AND s.form_name = '".db_real_escape_string($instrument)."'";
    if (isset($_GET['test'])) {
        echo $sql."<br>";
    }
    $q = db_query($sql);
    if ($error = db_error()) {
        throw new \Exception("SQL error: $error $sql");
    }
    $allRespondants = [];
    while ($row = db_fetch_assoc($q)) {
        if (!isset($allRespondants[$row['instance']])) {
            $allRespondants[$row['instance']] = [];
        }
        if (!isset($allRespondants[$row['instance']][$row['project_id']])) {
            $allRespondants[$row['instance']][$row['project_id']] = [];
        }
        if (!in_array($row['record'], $allRespondants[$row['instance']][$row['project_id']])) {
            $allRespondants[$row['instance']][$row['project_id']][] = $row['record'];
        }
    }
    return $allRespondants;
}