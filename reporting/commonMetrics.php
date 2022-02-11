<?php

use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Upload;
use Vanderbilt\CareerDevLibrary\NameMatcher;
use Vanderbilt\CareerDevLibrary\NIHTables;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

if (isset($_GET['cohort']) && $_GET['cohort']) {
    $cohort = REDCapManagement::sanitizeCohort($_GET['cohort']);
    $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else {
    $cohort = "";
    $records = Download::recordIds($token, $server);
}

$grantClass = Application::getSetting("grant_class", $pid);
if ($grantClass == "T") {
    $categories = ["TL1 Pre-docs", "TL1 Post-docs"];
} else if ($grantClass == "K") {
    $categories = ["KL2"];
} else {
    $categories = ["TL1 Pre-docs", "TL1 Post-docs", "KL2"];
}
$blankRow = ["Male" => [], "Female" => [], "Non-Binary" => []];

list($tableRows, $totalCompleted) = makeDataTableRows($categories, $blankRow, $records, $token, $server, $pid);
$names = Download::names($token, $server);

if ((isset($_GET['download'])) && in_array($_GET['download'], ["TL1", "KL2"])) {
    $catClass = REDCapManagement::sanitize($_GET['download']);
    $allData = getFieldDataToDownload($tableRows, $totalCompleted, $catClass);
    $projectName = Application::getProjectTitle();
    if (!empty($allData)) {
        outputCSVFromData($allData, $projectName . " Common Metrics -" . date("Y-m-d") . ".csv");
    } else {
        require_once(dirname(__FILE__)."/../charts/baseWeb.php");
        echo makeTableFromData($tableRows, $totalCompleted, $blankRow, $token, $server, $grantClass, $names);
    }
} else if (isset($_POST['Engaged']) && isset($_POST['Not Engaged'])) {
    require_once(dirname(__FILE__)."/../charts/baseWeb.php");
    if ($_POST['Engaged'] || $_POST['Not Engaged']) {
        $engagedList = REDCapManagement::sanitize($_POST['Engaged']);
        $notEngagedList = REDCapManagement::sanitize($_POST['Not Engaged']);
        $engagedNames = preg_split("/[\n\r]+/", $engagedList);
        $notEngagedNames = preg_split("/[\n\r]+/", $notEngagedList);
        $values = ["1" => $engagedNames, "2" => $notEngagedNames];
        $upload = [];
        $lastNames = Download::lastnames($token, $server);
        $firstNames = Download::firstnames($token, $server);
        $unmatchedNames = [];
        foreach ($values as $val => $valNames) {
            $unmatchedNames[$val] = [];
            foreach ($valNames as $valName) {
                if ($valName) {
                    list($valFirstName, $valLastName) = NameMatcher::splitName($valName);
                    if ($recordId = NameMatcher::matchName($valFirstName, $valLastName, $token, $server)) {
                        $upload[] = [
                            "record_id" => $recordId,
                            "identifier_is_engaged" => $val,
                        ];
                    } else {
                        $unmatchedNames[$val] = $valName;
                    }
                }
            }
        }
        if (!empty($upload)) {
            Upload::rows($upload, $token, $server);
            $numNames = count($upload);
            echo "<p class='centered max-width green'>$numNames Names Entered</p>";
        } else {
            echo "<p class='centered max-width red'>No one signed up!</p>";
        }
        if (!empty($unmatchedNames["1"]) || !empty($unmatchedNames["2"])) {
            $allUnmatchedNames = array_merge($unmatchedNames["1"] ?? [], $unmatchedNames["2"] ?? []);
            $numNames = count($allUnmatchedNames);
            echo "<p class='centered max-width red'>$numNames unmatched names!<br>".implode("<br>", $allUnmatchedNames)."</p>";
        }
        echo makeTableFromData($tableRows, $totalCompleted, $blankRow, $token, $server, $grantClass, $names);
    } else {
        echo "<p class='centered max-width red'>No one signed up!</p>";
        echo makeTableFromData($tableRows, $totalCompleted, $blankRow, $token, $server, $grantClass, $names);
    }
} else {
    require_once(dirname(__FILE__)."/../charts/baseWeb.php");
    echo makeTableFromData($tableRows, $totalCompleted, $blankRow, $token, $server, $grantClass, $names);
}

function getFieldDataToDownload($tableRows, $totalCompleted, $catClass) {
    $allData = [];
    foreach($tableRows as $cat => $rows) {
        if (preg_match("/$catClass/", $cat)) {
            if ($catClass == "KL2") {
                $currentData = [
                    "total_kl2" => $totalCompleted["KL2"],
                    "kl2_grad_m_urp_eng" => $rows["URP + Engaged"]["Male"],
                    "kl2_grad_f_urp_eng" => $rows["URP + Engaged"]["Female"],
                    "kl2_grad_oths_urp_eng" => $rows["URP + Engaged"]["Non-Binary"],
                    "kl2_grad_m_urp_xeng" => $rows["URP + Not Engaged"]["Male"],
                    "kl2_grad_f_urp_xeng" => $rows["URP + Not Engaged"]["Female"],
                    "kl2_grad_oths_urp_xeng" => $rows["URP + Not Engaged"]["Non-Binary"],
                    "kl2_grad_m_xurp_eng" => $rows["Non-URP + Engaged"]["Male"],
                    "kl2_grad_f_xurp_eng" => $rows["Non-URP + Engaged"]["Female"],
                    "kl2_grad_oths_xurp_eng" => $rows["Non-URP + Engaged"]["Non-Binary"],
                    "kl2_grad_m_xurp_xeng" => $rows["Non-URP + Not Engaged"]["Male"],
                    "kl2_grad_f_xurp_xeng" => $rows["Non-URP + Not Engaged"]["Female"],
                    "kl2_grad_oths_xurp_xeng" => $rows["Non-URP + Not Engaged"]["Non-Binary"],
                ];
            } else if ($catClass == "TL1") {
                if (preg_match("/Pre-doc/", $cat)) {
                    $type = "pre";
                } else if (preg_match("/Post-doc/", $cat)) {
                    $type = "post";
                } else {
                    throw new \Exception("Invalid category $cat");
                }
                $currentData = [
                    "total_tl1" => $totalCompleted["TL1"],
                    "tl1_$type"."_m_urp_eng" => $rows["URP + Engaged"]["Male"],
                    "tl1_$type"."_f_urp_eng" => $rows["URP + Engaged"]["Female"],
                    "tl1_$type"."_oths_urp_eng" => $rows["URP + Engaged"]["Non-Binary"],
                    "tl1_$type"."_m_urp_xeng" => $rows["URP + Not Engaged"]["Male"],
                    "tl1_$type"."_f_urp_xeng" => $rows["URP + Not Engaged"]["Female"],
                    "tl1_$type"."_oths_urp_xeng" => $rows["URP + Not Engaged"]["Non-Binary"],
                    "tl1_$type"."_m_xurp_eng" => $rows["Non-URP + Engaged"]["Male"],
                    "tl1_$type"."_f_xurp_eng" => $rows["Non-URP + Engaged"]["Female"],
                    "tl1_$type"."_oths_xurp_eng" => $rows["Non-URP + Engaged"]["Non-Binary"],
                    "tl1_$type"."_m_xurp_xeng" => $rows["Non-URP + Not Engaged"]["Male"],
                    "tl1_$type"."_f_xurp_xeng" => $rows["Non-URP + Not Engaged"]["Female"],
                    "tl1_$type"."_oths_xurp_xeng" => $rows["Non-URP + Not Engaged"]["Non-Binary"],
                ];
            } else {
                throw new \Exception("Invalid download class $catClass");
            }
            foreach ($currentData as $key => $value) {
                $allData[$key] = $value;
            }
        }
    }
    return $allData;
}

function outputCSVFromData($allData, $filename) {
    header('Content-Type: application/csv');
    header('Content-Disposition: attachment; filename="'.$filename.'";');
    $fp = fopen('php://output', 'w');
    $headers = array_keys($allData);
    fputcsv($fp, $headers);
    foreach ($headers as $header) {
        fputcsv($fp, $allData[$header]);
    }
    fclose($fp);
}

function makeDownloadLinkTable($categories) {
    $links = [];
    foreach ($categories as $cat) {
        if (preg_match("/KL2/", $cat)) {
            $link = Application::link("this")."&download=KL2";
            $html = "<a href='$link'>Download KL2 CSV</a>";
            if (!in_array($html, $links)) {
                $links[] = $html;
            }
        }
        if (preg_match("/TL1/", $cat)) {
            $link = Application::link("this")."&download=TL1";
            $html = "<a href='$link'>Download TL1 CSV</a>";
            if (!in_array($html, $links)) {
                $links[] = $html;
            }
        }
    }
    $spacing = "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
    return "<p class='centered'>".implode($spacing, $links)."</p>";
}

function makeTableFromData($tableRows, $totalCompleted, $blankRow, $token, $server, $grantClass, $names) {
    $html = "";
    $html .= "<h1>Common Metrics</h1>";
    $configLink = Application::link("config.php");
    $html .= "<p class='centered bolded'>Grant Class (<a href='$configLink' target='_NEW' class='smaller'>change here</a>): ".ucfirst($grantClass)."</p>";

    $metadata = Download::metadata($token, $server);
    $engagedField = "identifier_is_engaged";
    $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
    $hasEngagedSetup = in_array($engagedField, $metadataFields);
    if ($hasEngagedSetup) {
        $link = Application::link("reporting/signup.php");
        $html .= "<p class='red centered max-width'>To get your scholars to show up as engaged or not engaged in research, please use <a href='$link'>this sign-up tool</a>.</p>";
    } else {
        $link = Application::link("index.php");
        $html .= "<p class='red centered max-width'>Please upgrade your data dictionary/metadata on <a href='$link'>the Home page</a> to sign up whether people are Engaged or Not Engaged.</p>";
    }
    $html .= makeDownloadLinkTable(array_keys($tableRows));
    foreach($tableRows as $cat => $rows) {
        $html .= "<h2>$cat</h2>";
        $catClass = "";
        if (preg_match("/TL1/", $cat)) {
            $catClass = "TL1";
        } else if (preg_match("/KL2/", $cat)) {
            $catClass = "KL2";
        }
        $html .= "<p class='centered'>The total number of $catClass trainees who have completed program requirements (July 1, 2012 - current reporting period)<br>";
        $html .= count($totalCompleted[$catClass])."</p>";
        $html .= "<table class='centered max-width bordered'>";
        $html .= "<thead>";
        $html .= "<tr class='whiteRow'>";
        $html .= "<td></td>";
        foreach (array_keys($blankRow) as $header) {
            $html .= "<th>$header</th>";
        }
        $html .= "<th>Names</th>";
        $html .= "</tr>";
        $html .= "</thead>";
        $html .= "<tbody>";
        $i = 0;
        foreach ($rows as $rowHeader => $items) {
            $rowClass = ($i % 2 == 0) ? "odd" : "even";
            $i++;
            $html .= "<tr class='$rowClass'>";
            $html .= "<th>$rowHeader</th>";
            $records = [];
            foreach (array_keys($blankRow) as $header) {
                $html .= "<td style='vertical-align: top;'>".count($items[$header])."</td>";
                $records = array_unique(array_merge($records, $items[$header]));
            }
            $nameList = [];
            foreach ($records as $recordId) {
                $nameList[] = $names[$recordId];
            }
            $nameStr = empty($nameList) ? "No applicable names." : implode(", ", $nameList);
            $html .= "<td class='smaller' style='vertical-align: top;'>$nameStr</td>";
            $html .= "</tr>";
        }
        $html .= "</tbody>";
        $html .= "</table>";
    }
    return $html;
}

function makeDataTableRows($categories, $blankRow, $records, $token, $server, $pid) {
    $metadata = Download::metadata($token, $server);
    $gender = Download::oneField($token, $server, "summary_gender");
    $urm = Download::oneField($token, $server, "summary_urm");
    $nihTables = new NIHTables($token, $server, $pid, $metadata);
    $choices = REDCapManagement::getChoices($metadata);
    $engagedField = "identifier_is_engaged";
    $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
    if (in_array($engagedField, $metadataFields)) {
        $engagedStatus = Download::oneField($token, $server, $engagedField);
    } else {
        $engagedStatus = [];
    }

    $tableRows = [];
    foreach ($categories as $cat) {
        $tableRows[$cat]["URP + Engaged"] = $blankRow;
        $tableRows[$cat]["URP + Not Engaged"] = $blankRow;
        $tableRows[$cat]["Non-URP + Engaged"] = $blankRow;
        $tableRows[$cat]["Non-URP + Not Engaged"] = $blankRow;
    }

    $earliestThresholdDate = "2012-07-01";
    $predocNames = $nihTables->downloadPredocNames($earliestThresholdDate);
    $postdocNames = $nihTables->downloadPostdocNames("", $earliestThresholdDate);

    $totalCompleted = ["TL1" => [], "KL2" => []];

    foreach ($records as $recordId) {
        $recordCategories = [];
        if (isset($predocNames[$recordId])) {
            $cat = "TL1 Pre-docs";
            if (isset($tableRows[$cat])) {
                $recordCategories[] = $cat;
            }
        }
        if (isset($postdocNames[$recordId])) {
            $cat = "TL1 Post-docs";
            if (isset($tableRows[$cat])) {
                $recordCategories[] = $cat;
            }
            $cat = "KL2";
            if (isset($tableRows[$cat])) {
                $recordCategories[] = $cat;
            }
        }

        $genderKey = "";
        if (isset($gender[$recordId])) {
            if ($gender[$recordId] !== "") {
                $genderKey = $choices["summary_gender"][$gender[$recordId]];
            }
            if (!in_array($genderKey, ["Female", "Male", ""])) {
                $genderKey = "Non-Binary";
            }
        }
        $urmStatus = "";
        if (isset($urm[$recordId]) && ($urm[$recordId] !== "")) {
            if ($urm[$recordId] == 1) {
                $urmStatus = "URP";
            } else if (($urm[$recordId] === "0") || ($urm[$recordId] === 0)) {
                $urmStatus = "Non-URP";
            } else {
                $urmStatus = "";
            }
        }
        if (isset($_GET['test'])) {
            echo "Record $recordId has URM $urmStatus and gender $genderKey<br>";
        }
        if ($urmStatus) {
            $engagedIdx = $engagedStatus[$recordId];
            $engagedText = "";
            if (isset($choices[$engagedField][$engagedIdx])) {
                $engagedText = $choices[$engagedField][$engagedIdx];
            }
            if ($engagedText) {
                foreach ($recordCategories as $cat) {
                    $tableRows[$cat]["$urmStatus + $engagedText"][$genderKey][] = $recordId;
                }
            }
        }
    }
    return [$tableRows, $totalCompleted];
}

