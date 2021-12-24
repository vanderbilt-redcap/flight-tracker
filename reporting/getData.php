<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\NIHTables;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\NameMatcher;

define('NOAUTH', TRUE);

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$fullUrl = REDCapManagement::sanitize($_POST['origin']);
list($originUrl, $params) = explode("?", $fullUrl);
$cohort = REDCapManagement::sanitize($_GET['cohort']);
$record = REDCapManagement::sanitize($_POST['record']);
$modalId = REDCapManagement::sanitize($_POST['modalId']);
$data = [];

if ($token != $_POST['token']) {
    die(json_encode(["error" => "Must supply correct token!"]));
}

$metadata = Download::metadata($token, $server);
if ($cohort) {
    $records = Download::cohortRecordIds($token, $server, Application::getModule(), $cohort);
} else {
    $records = Download::recordIds($token, $server);
}

$provideNameUrls = [
    "https://public.era.nih.gov/xtract/editSuccessfulParticipatingPerson.era",
    "https://public.era.nih.gov/xtract/editParticipatingPersonHome.era",
];
if ($_POST['row']) {
    if (in_array($record, $records)) {
        $row = [REDCapManagement::sanitize($_POST['row'])];
        $data = translateModal($modalId, $record, $metadata, $row);
    }
} else if (in_array($originUrl, $provideNameUrls)) {
    if (in_array($record, $records)) {
        $data = translateModal($modalId, $record, $metadata);
    } else {
        $data['firstnames'] = Download::sortedfirstnames($token, $server);
        $data['lastnames'] = Download::sortedlastnames($token, $server);
    }
}
echo json_encode($data);





function translateModal($modalId, $record, $metadata, $tableData = NULL) {
    global $token, $server, $pid;
    $tables = new NIHTables($token, $server, $pid, $metadata);
    $data = [];

    $tableNum = getTableNum($modalId);
    if (isset($_GET['test'])) {
        echo "TableNum: $tableNum<br>";
    }
    if ($tableNum && !$tableData) {
        $tableData = accessTableData($tables, $record, $tableNum);
    } else if (!$tableData && ($modalId == "addDegreeModal")) {
        $tableData = accessDegrees($record, $tables);
    }

    if ($modalId == "confirmFinalizeRtd") {
    } else if ($modalId == "finalizeDialogErrorMessage") {
    } else if ($modalId == "finalizeDialogSuccessMessage") {
    } else if ($modalId == "rpprRtdWithOneCopyOptDiv") {
    } else if ($modalId == "rpprRtdWithMultipleCopyOptDiv") {
    } else if ($modalId == "copyDiffRtdToRpprDiv") {
    } else if ($modalId == "copyOnDemandConfirmDiv") {
    } else if ($modalId == "renewalCurrentRpprRtdDiv") {
    } else if ($modalId == "renewalPriorRpprRtdDiv") {
    } else if ($modalId == "copyProgramStatisticsDiv") {
    } else if ($modalId == "warningModal") {
    } else if ($modalId == "copyParticipatingTraineesDiv") {
        # target? - Mentees
    } else if ($modalId == "editMentoringRecord") {
        # target? - Mentors
    } else if ($modalId == "addPublicationRecord") {
        if ($_POST['row']) {
            $row = REDCapManagement::sanitize($_POST['row']);
            $data["PMID"] = $row["PMID"];
        } else if ($_POST['step'] == "2") {
            $row = $tableData[0];
            $publications = explode("</p><p class='citation'>", $row['Publication']);
            $rows = [];
            $i = 1;
            foreach ($publications as $strCitation) {
                $strCitation = preg_replace("/^<p class='citation'>/", "", $strCitation);
                $strCitation = preg_replace("/<\/p>$/", "", $strCitation);
                $dataRow = ["Number" => $i];
                if (preg_match("/PMID\s+\d+\./", $strCitation, $matches)) {
                    $dataRow["PMID"] = preg_replace("/\.$/", "", preg_replace("/^PMID\s+/", "", $matches[0]));
                }
                $rows[] = $dataRow;
                $i++;
            }
            $data['multipleItems'] = $rows;
        } else if ($_POST['step'] == "1") {
            $firstNames = Download::firstnames($token, $server);
            $lastNames = Download::lastnames($token, $server);
            $data["firstName"] = $firstNames[$record];
            $data["lastName"] = $lastNames[$record];
        } else {
            $data["alert"] = "No Flight Tracker data are needed at this step. Please fill out the rest manually.";
        }
    } else if ($modalId == "editPublicationRecord") {
    } else if ($modalId == "editProfilePersonDegreeModal") {
        # target? - Demographics?
    } else if ($modalId == "editFacultyDataModal") {
        # target? - Mentors?
    } else if ($modalId == "editInTrainingDataModal") {
        if (count($tableData) > 1) {
            $data['multipleItems'] = $tableData;
        } else if (count($tableData) == 1) {
            $row = $tableData[0];
            if ($row['Terminal Degree(s)<br>Received and Year(s)']) {
                $data['inTraining'] = ($row['Terminal Degree(s)<br>Received and Year(s)'] == "In Training") ? "Yes" : "No"; // Yes, No
            }
            $data['traineeType'] = getTraineeStatus($tables, $record); // Pre-doc, Post-doc, Short-term
            $data['researchTopic'] = "";
            foreach ($row as $item => $value) {
                if (preg_match("/Topic of Research Project/", $item)) {
                    $data['researchTopic'] = $value;
                    break;
                }
            }
            $data['startDateInProgram'] = $row['Start Date'];   // already in mm/yyyy
            if (isset($data['inTraining']) && ($data['inTraining'] == "Yes")) {
                $data['endDateInProgram'] = "";
            } else {
                $grantClass = Application::getSetting("grant_class", $pid);
                if ($grantClass == "T") {
                    $data['endDateInProgram'] = REDCapManagement::stripMY($row['Terminal Degree(s)<br>Received and Year(s)']);   // mm/yyyy
                } else if ($grantClass == "K") {
                    $redcapData = Download::fieldsForRecords($token, $server, Application::$summaryFields, [$record]);
                    $scholar = new Scholar($token, $server, $metadata, $pid);
                    $scholar->setRows($redcapData);
                    $endOfK = $scholar->getEndOfK([1, 2]);
                    if ($endOfK) {
                        $data['endDateInProgram'] = REDCapManagement::YMD2MY($endOfK);
                    } else {
                        $data['endDateInProgram'] = "";
                    }
                } else {
                    $data['endDateInProgram'] = "";
                }
            }
        }
    } else if ($modalId == "addfacultyMemberModal") {
        # mentors
        if (count($tableData) > 1) {
            $data['multipleItems'] = $tableData;
        } else if (count($tableData) == 1) {
            $row = $tableData[0];
            $fullNames = turnNamesIntoArray($row['Faculty Member']);
            if (count($fullNames) >= 2) {
                $i = 1;
                $rows = [];
                foreach ($fullNames as $fullName) {
                    $keyedNames = [];
                    $keyedNames["Mentor $i"] = $fullName;
                    $rows[] = $keyedNames;
                    $i++;
                }
                $data['multipleItems'] = $rows;
            } else if (count($fullNames) == 1) {
                list($first, $middle, $last) = NameMatcher::splitName($fullNames[0], 3);
                $data['commonsUserId'] = "";
                $data['personIdString'] = "";
                $data['firstName'] = $first;
                $data['middleName'] = $middle;
                $data['lastName'] = $last;
            }
        }
    } else if ($modalId == "addDegreeModal") {
        if (count($tableData) > 1) {
            $data['multipleItems'] = $tableData;
        } else if (count($tableData) == 1) {
            $row = $tableData[0];
            $degree = strtoupper($row["Degree"]);
            $possibleDegreesInxTRACT = ["AB", "BA", "BOTH", "BS", "BSN", "DC", "DDOT", "DDS", "DMD", "DNSC", "DO", "DOTH", "DPH", "DPM", "DRPH", "DSC", "DSW", "DVM", "EDD", "ENGD", "FAAN", "JD", "MA", "MB", "MBA", "MBBS", "MD", "MDOT", "MLS", "MOTH", "MPA", "MPH", "MS", "MSN", "ND", "OD", "OTH", "PHD", "PHMD", "PSYD", "RN", "SCD", "VDOT", "VMD",];
            $possibleDoctorates = ["MD", "PHD", "DO", "PSYD", "DMD", "DDOT", "DDS", "DNSC", "DOTH", "DPH", "DPM", "DRPH", "DSC", "DSW", "DVM", "EDD", "ENGD", "JD", "MDOT", "ND", "OD", "PHMD", "SCD", "VDOT", "VMD",];
            if (in_array($degree, $possibleDegreesInxTRACT)) {
                $data['degreeCode'] = $degree;
            } else {
                $data['degreeCode'] = "OTH";
                $data['otherDegreeText'] = $degree;
            }
            $data['degreeDate'] = ($row["Degree Date"] ? $row["Degree Date"] : "");  // mm/yyyy
            if (in_array($degree, $possibleDoctorates)) {
                $data['terminalDegree'] = "true";  // "true", "false"
            } else {
                $data['terminalDegree'] = "false";  // "true", "false"
            }
            $data['degreeStatus'] = ($row["Degree Date"] ? "Y" : "N");  // Y = Completed, N = In Progress
            // $data['reportOnRtd'] = ""; // Received in Training? "true", "false"
            $data['ms-institution'] = $row["Degree Institution"];
        }
    } else if ($modalId == "addEmploymentModal") {
        if (count($tableData) > 1) {
            $data['multipleItems'] = $tableData;
        } else if (count($tableData) == 1) {
            $row = $tableData[0];
            $initialText = "";
            $initialField = "";
            $currentText = "";
            $currentField = "";
            foreach ($row as $field => $value) {
                if (preg_match("/Initial Position/", $field)) {
                    $initialText = $value;
                    $initialField = $field;
                } else if (preg_match("/Current Position/", $field)) {
                    $currentText = $value;
                    $currentField = $field;
                }
            }
            if (!$currentText && !$initialText) {
                return [];
            }
            $redcapData = Download::fieldsForRecords($token, $server, Application::$positionFields, [$record]);
            $currentPos = $tables->getCurrentPosition($redcapData, $record, FALSE);
            $initialPos = $tables->getInitialPosition($redcapData, $record, FALSE);
            $pos = [];
            if (($currentText != "") && ($initialText != "")) {
                if ($currentText == $initialText) {
                    $pos = $currentPos;
                } else {
                    $currentData = convertTextToEmployment($currentText, $currentField);
                    $initialData = convertTextToEmployment($initialText, $initialField);
                    $data['multipleItems'] = [$currentData, $initialData];
                }
            } else if ($currentText != "") {
                $pos = $currentPos;
                $data['primaryInitialEmployment'] = "";    // checkbox
                $data['currentInitialEmployment'] = "checked";    // checkbox for current employment
            } else if ($initialText != "") {
                $pos = $initialPos;
                $data['primaryInitialEmployment'] = "checked";    // checkbox
                $data['currentInitialEmployment'] = "";    // checkbox for current employment
            }   // no else
            if (!empty($pos)) {
                $data['primaryActivityCode'] = convertToActivityCode($pos["original_category_num"]);
                $data['ms-employmentposition'] = $pos['title'];
                $data['employmentStartDateStr'] = "";  // mm/yyyy
                $data['employmentEndDateStr'] = ""; // mm/yyyy
                if (
                    (isset($data['currentInitialEmployment']) && $data['currentInitialEmployment'])
                    || (isset($data['primaryInitialEmployment']) && $data['primaryInitialEmployment'])
                ) {
                    $data['primaryEmploymentCode'] = "Y";   // Y/N
                } else {
                    $data['primaryEmploymentCode'] = "";   // Y/N
                }
                $data['employmentStatusCode'] = "";   // F = Full-time, P = Part-time
                $data['ms-institution'] = $pos["institution"];
                $data['ms-department'] = $pos["department"];

                $workForceSector = convertToWorkForceSector($pos["original_category_num"]);
                if ($workForceSector == "Unknown") {
                    // $data['alert'] = "Workforce Sector is Unknown! Leaving blank.";
                    $data['workForceSectorCode'] = "";
                } else {
                    $data['workForceSectorCode'] = $workForceSector;
                }
            }
        }
    } else if (in_array($modalId, ["addNIHSourcesOfSupport", "addOtherSourcesOfSupport", "addSourceOfSupportModal", "editOtherFundingSourceModal"])) {
        $data['listedSupport'] = getSupportStatements($tables, $record);
    } else {
        die(json_encode(["error" => "Invalid modalId $modalId"]));
    }
    return $data;
}

# 1, Academia, still research-dominant (PI)
# 5, Academia, still research-dominant (Staff)
# 2, Academia, not research dominant
# 7, Academia, training program
# 3, Private practice
# 4, Industry, federal, non-profit, or other - research dominant
# 6, Industry, federal, non-profit, or other - not research dominant
function convertToWorkForceSector($cat) {
    // Academia, Government, For-Profit, Nonprofit, Other
    if (!$cat) {
        return "";
    } else if (in_array($cat, [1, 5, 2, 7])) {
        return "Academia";
    } else {
        return "Unknown";
    }
}

function convertToActivityCode($cat) {
    // Input: 1, Academia, still research-dominant (PI) | 5, Academia, still research-dominant (Staff) | 2, Academia, not research dominant | 7, Academia, training program | 3, Private practice | 4, Industry, federal, non-profit, or other - research dominant | 6, Industry, federal, non-profit, or other - not research dominant
    // Output: Primarily Research, Primarily Teaching, Primarily Clinical, Research-Related, Further Training, Unrelated to Research
    if (!$cat) {
        return "";
    } else if (in_array($cat, [1, 4])) {
        return "Primarily Research";
    } else if (in_array($cat, [2])) {
        return "Primarily Teaching";
    } else if (in_array($cat, [3])) {
        return "Primarily Clinical";
    } else if (in_array($cat, [5])) {
        return "Research-Related";
    } else if (in_array($cat, [7])) {
        return "Further Training";
    } else if (in_array($cat, [6])) {
        return "Unrelated to Research";
    }
}

function convertTextToEmployment($text, $field) {
    if (!$text) {
        return [];
    }
    if (preg_match("/Initial Position/", $field)) {
        $positionType = "Initial Position";
    } else if (preg_match("/Current Position/", $field)) {
        $positionType = "Current Position";
    } else {
        $positionType = "Position";
    }
    $nodes = explode("<br>", $text);
    if (count($nodes) == 4) {
        # Position, Department, Institution, Activity
        return [
            $positionType => $nodes[0],
            "Department" => $nodes[1],
            "Institution" => $nodes[2],
            "Activity" => $nodes[3],
        ];
    } else {
        throw new \Exception("Unknown employment text: $text");
    }
}

function turnNamesIntoArray($str) {
    $str = preg_replace("/^<p>/", "", $str);
    $str = preg_replace("/<\/p>$/", "", $str);
    return preg_split("/<\/p><p>/", $str);
}

function getTableNum($modalId) {
    if (in_array($modalId, ["addEmploymentModal", "addfacultyMemberModal", "editInTrainingDataModal"])) {
        return 8;
    } else if (in_array($modalId, ["addPublicationRecord", "editPublicationRecord"])) {
        return 5;
    }
    return "";
}

function accessDegrees($recordId, $tables) {
    $degreeData = $tables->getDegreesAndInstitutions($recordId);
    $i = 1;
    $data = [];
    foreach ($degreeData as $degree => $ary) {
        $year = $ary[0];
        $institution = $ary[1];
        $label = "Degree";
        $dataRow = [];
        $dataRow[$label] = $degree;
        $dataRow[$label." Date"] = $year;
        $dataRow[$label." Institution"] = $institution;
        $data[] = $dataRow;
        $i++;
    }
    return $data;
}

function accessTableData($tables, $recordId, $overallTableNum) {
    $possibleTables = getTables($overallTableNum);
    if (isset($_GET['test'])) {
        echo "PossibleTables: ".json_encode($possibleTables)."<br>";
    }
    $finalResults = [];
    foreach ($possibleTables as $table) {
        if (NIHTables::beginsWith($table, ["5"])) {
            $data = $tables->get5Data($table, [$recordId]);
        } else if (NIHTables::beginsWith($table, ["8"])) {
            $data = $tables->get8Data($table, $recordId);
        } else {
            throw new \Exception("Invalid table! $table");
        }
        foreach ($data as $row) {
            $key = implode("|", array_values($row));
            $finalResults[$key] = $row;
        }
    }
    return array_values($finalResults);
}

function getTables($overallTableNum) {
    $possibleTables = [];
    if ($overallTableNum == 8) {
        $possibleTables = ["8AI", "8AIII", ];     // "8AIV"
        if (isset($_GET['appointments'])) {
            $possibleTables[] = "8CI-VUMC";
            $possibleTables[] = "8CIII-VUMC";
        } else {
            $possibleTables[] = "8CI";
            $possibleTables[] = "8CIII";
        }
    } else if ($overallTableNum == 6) {
        $possibleTables = ["6AII"];
        if (isset($_GET['appointments'])) {
            $possibleTables[] = "6BII-VUMC";
        } else {
            $possibleTables[] = "6BII";
        }
    } else if ($overallTableNum == 5) {
        $possibleTables = ["5A"];
        if (isset($_GET['appointments'])) {
            $possibleTables[] = "5B-VUMC";
        } else {
            $possibleTables[] = "5B";
        }
    }
    return $possibleTables;
}

function getTextareaInnerHTML($str) {
    if (preg_match("/<textarea.+<\/textarea>/i", $str, $matches)) {
        $textarea = $matches[0];
        $textarea = preg_replace("/<\/textarea>/i", "", $textarea);
        $textarea = preg_replace("/<textarea[^>]*>/i", "", $textarea);
        $textarea = preg_replace("/[\r\n]+/", "<br>", $textarea);
        return $textarea;
    } else {
        return "";
    }

}

function getSupportStatements($tables, $recordId) {
    $tableData = accessTableData($tables, $recordId, 8);
    $finalResults = [];
    foreach ($tableData as $row) {
        if ($row['Summary of Support During Training']) {
            $finalResults[] = getTextareaInnerHTML($row['Summary of Support During Training']);
        }
    }
    return implode("<br><br>", $finalResults);
}

function getTraineeStatus($tables, $recordId) {
    if ($tables->isPredoc($recordId)) {
        return "Pre-doc";
    } else if ($tables->isPostdoc($recordId)) {
        return "Post-doc";
    } else {
        return "";
    }
    # skip Short-term
}
