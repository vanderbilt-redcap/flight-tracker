<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\DateManagement;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\NIHTables;

if (in_array(gethostname(), ["scottjpearson", "ORIWL-KCXDJK7.local"])) {
    # Testing only - to allow to run with React server using 'npm start'
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET,HEAD,OPTIONS,POST,PUT");
    header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, X-Requested-With");
    define("NOAUTH", TRUE);
}
define("NEW_PREFIX" , "_NEW_");

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

$entityBody = file_get_contents('php://input');
if ($entityBody) {
    $_POST = json_decode($entityBody, TRUE) ?? $_POST;
}

$errorMssg = "";
if (!empty($_POST)) {
    $table = Sanitizer::sanitize($_POST['table'] ?? "");
    $awardNo = Sanitizer::sanitize($_POST['awardNo'] ?? "");
    $dateOfSubmission = Sanitizer::sanitizeDate($_POST['dateOfSubmission'] ?? "");
    $action = Sanitizer::sanitize($_POST['action'] ?? "");
    $scope = Sanitizer::sanitize((isset($_POST['scope']) && is_numeric($_POST['scope'])) ? $_POST['scope'] - 1 : "all");
    Application::keepAlive($pid);

    $data = [];
    try {
        if (($action == "getNumLines") && isset($_FILES['file'])) {
            $filename = $_FILES['file']['tmp_name'] ?? "";
            if ($filename && is_string($filename) && file_exists($filename)) {
                $linesToProcess = readFileAsDataLines($filename);
                if (NIHTables::beginsWith($table, ["5"])) {
                    combineTable5Lines($linesToProcess);
                }
                $data['numLines'] = count($linesToProcess);
            } else {
                $data['error'] = "Could not read file!";
            }
        } else if (($action == "uploadFile") && isset($_FILES['file'])) {
            $filename = $_FILES['file']['tmp_name'] ?? "";
            if ($table && $filename && is_string($filename) && file_exists($filename)) {
                $linesToProcess = readFileAsDataLines($filename);
                if (NIHTables::beginsWith($table, ["5"])) {
                    combineTable5Lines($linesToProcess);
                }

                if (!empty($linesToProcess)) {
                    list($unprocessedLines, $upload, $warnings) = processLines($linesToProcess, $table, $dateOfSubmission, $awardNo, $token, $server, $pid, $scope);
                    $data['lines'] = $unprocessedLines;
                    $data['upload'] = $upload;
                    $data['warnings'] = $warnings;
                } else {
                    $data['error'] = "No data specified.";
                }
            } else {
                $data['error'] = "File not uploaded.";
            }
        } else if ($action == "uploadREDCap") {
            $upload = Sanitizer::sanitizeArray($_POST['upload'] ?? [], FALSE);
            $upload = changeNewRecordIds($upload, $token, $server);
            if (!empty($upload)) {
                Upload::rows($upload, $token, $server);
            } else {
                $data['error'] = "Empty data.";
            }
        } else if ($action == "processLines") {
            $linesToProcess = Sanitizer::sanitizeArray($_POST['lines'] ?? []);
            if (!empty($linesToProcess)) {
                list($unprocessedLines, $upload, $warnings) = processLines($linesToProcess, $table, $dateOfSubmission, $awardNo, $token, $server, $pid, $scope);
                $data['lines'] = $unprocessedLines;
                $data['upload'] = $upload;
                $data['warnings'] = $warnings;
            } else {
                $data['error'] = "No lines to process specified.";
            }
        } else {
            $data['error'] = "Improper action specified.";
        }
    } catch (\Exception $e) {
        $data['error'] = $e->getMessage();
    }
    $json = json_encode($data);
    echo $json;
    exit;
}

function changeNewRecordIds($rows, $token, $server) {
    $recordIds = Download::recordIds($token, $server);
    $maxRecordId = !empty($recordIds) ? max($recordIds) : 0;
    $assigned = [];
    $newRows = [];
    foreach ($rows as $row) {
        if (isset($assigned[$row['record_id']])) {
            $row['record_id'] = $assigned[$row['record_id']];
        } else if (preg_match("/^".NEW_PREFIX."/", $row['record_id'])) {
            $maxRecordId++;
            $assigned[$row['record_id']] = $maxRecordId;
            $row['record_id'] = $assigned[$row['record_id']];
        }
        $newRows[] = $row;
    }
    return $newRows;
}

function getImportedMentorField($metadata) {
    $metadataFields = DataDictionaryManagement::getFieldsFromMetadata($metadata);
    $options = [ "imported_mentor", "override_mentor", ];
    foreach ($options as $field) {
        if (in_array($field, $metadataFields)) {
            return $field;
        }
    }
    throw new \Exception("Could not find mentor field in ".implode(", ", $options));
}

function combineTable5Lines(&$lines) {
    $combinedLines = [];
    $citationCol = 4;
    $nameCols = [0, 1];
    foreach ($lines as $line) {
        $lastLineIndex = count($combinedLines) - 1;
        if ($line[2] && $line[3]) {
            $combinedLines[] = $line;
        } else if ($lastLineIndex >= 0) {
            # trainee names and mentor names
            foreach ($nameCols as $col) {
                if ($line[$col]) {
                    $combinedLines[$lastLineIndex][$col] = trim($combinedLines[$lastLineIndex][$col]);
                    $combinedLines[$lastLineIndex][$col] .= " ".trim($line[$col]);
                }
            }
            if ($line[$citationCol] && Citation::isCitation($line[$citationCol])) {
                $combinedLines[$lastLineIndex][$citationCol] .= "\n".$line[$citationCol];
            } else if ($line[$citationCol]) {
                $combinedLines[$lastLineIndex][$citationCol] .= " ".$line[$citationCol];
            }
        }
    }
    $lines = $combinedLines;
}

function processLines($linesToProcess, $table, $dateOfSubmission, $awardNo, $token, $server, $pid, $lineScope = "all") {
    $unprocessedLines = [];
    $upload = [];
    $warnings = [];
    if (($lineScope !== "all") && !isset($linesToProcess[$lineScope])) {
        return [$unprocessedLines, $upload, $warnings];
    }

    $firstNames = Download::firstnames($token, $server);
    $lastNames = Download::lastnames($token, $server);
    $mentors = Download::primaryMentors($token, $server);
    $trainingStarts = Download::oneField($token, $server, "identifier_start_of_training");
    $metadata = Download::metadata($token, $server);
    $importedMentors = Download::oneField($token, $server, getImportedMentorField($metadata));
    $choices = DataDictionaryManagement::getChoices($metadata);
    $priorPMIDs = NIHTables::beginsWith($table, ["5"]) ? Download::oneFieldWithInstances($token, $server, "citation_pmid") : [];
    if ($lineScope == "all") {
        $lines = $linesToProcess;
    } else {
        $lines = [$linesToProcess[$lineScope]];
    }
    $seenTraineeNames = [];
    foreach ($lines as $line) {
        try {
            if (NIHTables::beginsWith($table, ["2"])) {
                list($unprocessed, $uploadRows, $warnings) = processTable2Line($line, $pid, $firstNames, $lastNames);
            } else if (NIHTables::beginsWith($table, ["4"])) {
                list($unprocessed, $uploadRows, $warnings) = processTable4Line($line, $token, $server, $metadata, $firstNames, $lastNames);
            } else if (NIHTables::beginsWith($table, ["5"])) {
                if ($table == "5A") {
                    $category = "Predoctoral";
                } else if ($table = "5B") {
                    $category = "Postdoctoral";
                } else {
                    throw new \Exception("Invalid table $table");
                }
                list($unprocessed, $uploadRows, $warnings) = processTable5Line($line, $awardNo, $category, $token, $server, $pid, $metadata, $firstNames, $lastNames, $trainingStarts, $mentors, $importedMentors, $priorPMIDs, $seenTraineeNames);
            } else if (NIHTables::beginsWith($table, ["8"])) {
                if ($table == "8A") {
                    $category = "Predoctoral";
                } else if ($table == "8C") {
                    $category = "Postdoctoral";
                } else {
                    throw new \Exception("Invalid table $table");
                }
                list($unprocessed, $uploadRows, $warnings) = processTable8Line($line, $awardNo, $category, $token, $server, $metadata, $choices, $firstNames, $lastNames, $trainingStarts, $mentors, $importedMentors, $table);
            } else {
                $unprocessed = [];
                $uploadRows = [];
                $warnings = [];
            }
        } catch (\Exception $e) {
            $line['comments'] = ["Exception" => $e->getMessage()];
            $unprocessed = [$line];
            $uploadRows = [];
        }
        foreach ($unprocessed as $myLine) {
            if ($myLine) {
                $unprocessedLines[] = $myLine;
            }
        }
        $upload = array_merge($upload, $uploadRows);
    }
    return [$unprocessedLines, $upload, $warnings];
}


function addCustomGrantToSignUp(&$uploadForRecord, $recordId, $awardNo, $category, $researchTopic, $startOfTraining, $endOfTraining, $token, $server, $metadata, $directCosts = "") {
    $instrument = "custom_grant";
    $fields = Application::getCustomFields($metadata);
    $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
    $maxInstance = REDCapManagement::getMaxInstance($redcapData, $instrument, $recordId);
    $alreadyHasGrant = FALSE;

    $maxInstance++;
    $uploadRow = [
        "record_id" => $recordId,
        "redcap_repeat_instrument" => $instrument,
        "redcap_repeat_instance" => $maxInstance,
        $instrument."_complete" => "2",
    ];
    foreach ($redcapData as $row) {
        if (($row['redcap_repeat_instrument'] == $instrument) && ($row['custom_number'] == $awardNo)) {
            $alreadyHasGrant = TRUE;
            if (($row['custom_title'] == "") && ($researchTopic !== "")) {
                $uploadRow["custom_title"] = $researchTopic;
            }
        }
    }
    if (!$alreadyHasGrant) {
        if ($researchTopic) {
            $uploadRow['custom_title'] = $researchTopic;
        }
        if ($awardNo) {
            $uploadRow['custom_number'] = $awardNo;
        }
        if ($category == "Predoctoral") {
            $role = 6;
        } else if ($category == "Postdoctoral") {
            $role = 7;
        } else if (in_array($category, ["PD/PI", "PI", "Project PI"])) {
            $role = 1;
        } else if (in_array($category, ["CoPI", "Co-PI", "Project Lead"])) {
            $role = 2;
        } else if (in_array($category, ["Co-I", "Co-Investigator"])) {
            $role = 3;
        } else if (in_array($category, ["Other"])) {
            $role = 4;
        } else {
            $role = "";
        }
        if ($role) {
            $uploadRow["custom_role"] = $role;
        }
        if ($startOfTraining) {
            $uploadRow["custom_start"] = $startOfTraining;
        }
        if ($endOfTraining) {
            $uploadRow['custom_end'] = $endOfTraining;
        }
        if ($directCosts) {
            $uploadRow['custom_costs'] = $directCosts;
        }
    }
    if (count($uploadRow) > 4) {
        $uploadForRecord[] = $uploadRow;
    }
}


function parsePublications($publicationText, $traineeName, $facultyName, &$warnings, $metadata, $pid) {
    $pmids = [];
    $pmcs = [];
    $pubs = ($publicationText === "") ? [] : preg_split("/[\n\r]+/", $publicationText);
    if (empty($pubs)) {
        return [];
    }
    list($traineeFirst, $traineeMiddle, $traineeLast) = NameMatcher::splitName($traineeName, 3);
    $pmidsForFirstName = Publications::searchPubMedForName($traineeFirst, $traineeLast, $pid);
    if ($traineeMiddle) {
        $pmidsForFullName = Publications::searchPubMedForName("$traineeFirst $traineeMiddle", $traineeLast, $pid);
    } else {
        $pmidsForFullName = [];
    }
    $pmidsForAuthor = array_unique(array_merge($pmidsForFirstName, $pmidsForFullName));
    try {
        if (!empty($pubs)) {
            $publicationREDCap = Publications::getCitationsFromPubMed($pmidsForAuthor, $metadata, "manual", 1, 1, [], $pid, FALSE);
        } else {
            $publicationREDCap = [];
        }
    } catch (\Exception $e) {
        $publicationREDCap = [];
    }
    $unmatchedPubs = [];
    # title, journal
    $thresholdPairs = [
        [90, 80],
        [95, 70],
        [99, 1],
    ];
    foreach ($pubs as $i => $pub) {
        $pub = REDCapManagement::clearUnicode($pub);
        if ($pub) {
            list($title, $journal) = Citation::getPublicationTitleAndJournalFromText($pub);
            if ($title && $journal) {
                $isPubMatched = FALSE;
                $errorNotes = [];
                $titleLower = strtolower($title);
                $journalLower = strtolower($journal);
                if (preg_match("/PMID/i", $pub)) {
                    $pmids[] = Publications::getCurrentPMID($pub);
                } else if (preg_match("/PMC/i", $pub)) {
                    $pmcs[] = Publications::getCurrentPMC($pub);
                } else {
                    foreach ($publicationREDCap as $row) {
                        similar_text(strtolower($row['citation_title']), $titleLower, $titlePerc);
                        similar_text(strtolower($row['citation_journal']), $journalLower, $journalPerc);
                        foreach ($thresholdPairs as $pair) {
                            $titleThreshold = $pair[0];
                            $journalThreshold = $pair[1];
                            if (
                                $row['citation_title']
                                && $row['citation_journal']
                                && ($titlePerc >= $titleThreshold)
                                && ($journalPerc >= $journalThreshold)
                            ) {
                                $pmids[] = $row['citation_pmid'];
                                $isPubMatched = TRUE;
                                break;
                            }
                        }
                        if (($titlePerc > 50) || ($journalPerc > 50)) {
                            $errorNotes[] = "Comparing $titleLower to {$row['citation_title']} with $titlePerc and $journalLower to {$row['citation_journal']} with $journalPerc";
                        }
                    }
                }
                if (!$isPubMatched) {
                    $unmatchedPubs[] = $title;
                }
            }
        }
    }
    if (!empty($unmatchedPubs)) {
        if (count($unmatchedPubs) == 1) {
            $question = "Is there perhaps a typo? Or is the full name not available in PubMed?";
        } else {
            $question = "Are there perhaps some typos?";
        }
        $warnings[] = [
            "Publications" => [
                "note" => "The following publication titles could not be matched on PubMed. $question Please consider entering them manually in the Publication Wrangler. They will likely be picked up in forthcoming sweeps of PubMed.",
                "titles" => $unmatchedPubs,
                "trainee" => $traineeName,
                "faculty" => $facultyName,
            ],
        ];
    }
    if (!empty($pmcs)) {
        $convertedPMIDs = Publications::PMCsToPMIDs($pmcs, $pid);
        $pmids = array_unique(array_merge($pmids, $convertedPMIDs));
    }
    return $pmids;
}


function getMatchedRecords($traineeName, $firstNames, $lastNames) {
    $matchedRecords = [];
    list($traineeFirstName, $traineeMiddleName, $traineeLastName) = NameMatcher::splitName($traineeName, 3);
    foreach ($firstNames as $recordId => $firstName) {
        $lastName = $lastNames[$recordId] ?? "";
        if (NameMatcher::matchName($traineeFirstName, $traineeLastName, $firstName, $lastName, $recordId)) {
            $matchedRecords[] = $recordId;
        }
    }
    return $matchedRecords;
}

function isMentorAlreadyAdded($facultyFirstName, $facultyLastName, $traineeMentors) {
    foreach ($traineeMentors as $mentor) {
        list($mentorFirstName, $mentorMiddleName, $mentorLastName) = NameMatcher::splitName($mentor, 3);
        if (NameMatcher::matchName($facultyFirstName, $facultyLastName, $mentorFirstName, $mentorLastName)) {
            return TRUE;
        }
    }
    return FALSE;
}

function hasFileUpload() {
    return (count($_FILES) > 0);
}

function cleanLine(&$line) {
    for ($i = 0; $i < count($line); $i++) {
        $line[$i] = REDCapManagement::clearUnicode($line[$i]);
        $line[$i] = trim($line[$i]);
        if (strtolower($line[$i]) == "none") {
            $line[$i] = "";
        } else if (preg_match("/No Publication/i", $line[$i])) {
            $line[$i] = "";
        }
    }
}

function processTable2Line($line, $pid, $firstNames, $lastNames) {
    $tableNum = 2;
    $comments = [];
    if ($line[0] && (count($line) >= 12)) {
        try {
            cleanLine($line);
            $facultyName = $line[0];
            $fieldsToSave = [
                "Degree(s)" => $line[1],
                "Rank" => $line[2],
                "Research<br/>Interest" => $line[4],
                "Training<br/>Role" => $line[5],
                "Pre-doctorates<br/>In Training" => $line[6],
                "Pre-doctorates<br/>Graduated" => $line[7],
                "Predoctorates<br/>Continued in<br/>Research or<br/>Related Careers" => $line[8],
                "Post-doctorates<br/>In Training" => $line[9],
                "Post-doctorates<br/>Completed<br/>Training" => $line[10],
                "Postdoctorates<br/>Continued in<br/>Research or<br/>Related Careers" => $line[11],
            ];
            $uploadNames = FALSE;
            if (isset($line['record_id']) && ($line['record_id'] == "skip")) {
                return [[], [], []];
            } else if (isset($line['record_id']) && ($line['record_id'] == "new")) {
                $matchedRecords = [NEW_PREFIX.$facultyName];
                $uploadNames = TRUE;
            } else if (isset($line['record_id']) && ($line['record_id'])) {
                $matchedRecords = [$line['record_id']];
            } else {
                $matchedRecords = getMatchedRecords($facultyName, $firstNames, $lastNames);
            }
            if (count($matchedRecords) == 1) {
                $recordId = $matchedRecords[0];

                if ($uploadNames) {
                    addNormativeNameRow($uploadRows, $recordId, $facultyName);
                }

                $settingKey = NIHTables::makeCountKey($tableNum, $recordId);
                $setting = Application::getSetting($settingKey, $pid) ?: [];
                # recordInstance won't have the email in the identifier
                $recordInstance = NIHTables::getUniqueIdentifier($line, $tableNum);
                # in case a recordInstance with the email exists -> use the full recordInstance
                foreach (array_keys($setting) as $recordInstanceKey) {
                    if (preg_match("/^$recordInstance/", $recordInstanceKey)) {
                        $recordInstance = $recordInstanceKey;
                        break;
                    }
                }
                $setting[$recordInstance] = [date("Y-m-d") => $fieldsToSave];
                Application::saveSetting($settingKey, $setting, $pid);
            } else if (count($matchedRecords) >= 2) {
                $comments["Record"] = getMoreThan1RecordText($matchedRecords);
            } else {
                $comments["Record"] = getCreateRecordText();
            }
        } catch (\Exception $e) {
            $comments["Exception"] = $e->getMessage();
        }
    }
    if (!empty($comments)) {
        $line['comments'] = $comments;
        return [[$line], [], []];
    } else {
        return [[], [], []];
    }
}

function processTable4Line($line, $token, $server, $metadata, $firstNames, $lastNames) {
    $uploadRows = [];
    $comments = [];
    if ($line[0] && $line[1] && (strtolower($line[1]) != "none") && (count($line) >= 7)) {
        try {
            cleanLine($line);
            $facultyName = $line[0];
            $fundingSource = $line[1];
            $grantNumber = $line[2];
            $role = $line[3];
            $grantTitle = $line[4];
            if (preg_match("/-/", $line[5])) {
                list($startDate, $endDate) = explode("-", $line[5]);
                $startDate = processDate($startDate, "01-01");
                $endDate = processDate($endDate, "12-31");
            } else {
                $startDate = processDate($line[5], "01-01");
                $endDate = "";
                $comments["Project Period"] = "No end date specified.";
            }
            $yearlyDirectCosts = preg_replace("/[\$,]/", "", $line[6]);

            $validFundingSources = ["NIH", "AHRQ", "NSF", "Other Fed", "Univ", "Fdn", "Other"];
            if (!in_array($fundingSource, $validFundingSources)) {
                $comments["Funding Source"] = "Invalid funding source '$fundingSource'; valid sources: ".implode(", ", $validFundingSources);
            }
            $validRoles = ["PI", "PD", "MPI", "PD/PI", "Co-PI", "CoPI", "Project PI", "Project Lead", "Co-I", "Co-Investigator", "Other"];
            if (!in_array($role, $validRoles)) {
                $comments["Role"] = "Invalid role '$role'; valid roles: ".implode(", ", $validRoles);
            }
            if (!is_numeric($yearlyDirectCosts)) {
                $comments["Direct Costs"] = "A non-numeric value was specified: '$yearlyDirectCosts'.";
            }

            if (empty($comments)) {
                $uploadNames = FALSE;
                if (isset($line['record_id']) && ($line['record_id'] == "skip")) {
                    return [[], [], []];
                } else if (isset($line['record_id']) && ($line['record_id'] == "new")) {
                    $matchedRecords = [NEW_PREFIX.$facultyName];
                    $uploadNames = TRUE;
                } else if (isset($line['record_id']) && ($line['record_id'])) {
                    $matchedRecords = [$line['record_id']];
                } else {
                    $matchedRecords = getMatchedRecords($facultyName, $firstNames, $lastNames);
                }
                if (count($matchedRecords) == 1) {
                    $matchedRecordId = $matchedRecords[0];
                    if ($uploadNames) {
                        addNormativeNameRow($uploadRows, $matchedRecordId, $facultyName);
                    }
                    addCustomGrantToSignUp($uploadRows, $matchedRecordId, $grantNumber, $role, $grantTitle, $startDate, $endDate, $token, $server, $metadata, $yearlyDirectCosts);
                } else if (count($matchedRecords) >= 2) {
                    $comments["Record"] = getMoreThan1RecordText($matchedRecords);
                } else {
                    $comments["Record"] = getCreateRecordText();
                }
            }
        } catch (\Exception $e) {
            $comments["Exception"] = $e->getMessage();
        }
        if (!empty($comments)) {
            $line['comments'] = $comments;
            return [[$line], [], []];
        } else {
            return [[], $uploadRows, []];
        }
    } else {
        # fundamentally invalid line
        return [[], [], []];
    }

}

function processTable5Line($line, $awardNo, $category, $token, $server, $pid, $metadata, $firstNames, $lastNames, $trainingStarts, $mentors, $importedMentors, $priorPMIDs, &$seenTraineeNames) {
    $trainingStartDate = "";
    $trainingEndDate = "";
    $uploadForRecord = [];
    $comments = [];
    $warnings = [];
    $isFirstUpload = hasFileUpload();
    if ($line[0] && $line[1] && (count($line) >= 5)) {
        try {
            cleanLine($line);
            removeExtraSpaces($line);
            $facultyName = trim($line[0]);
            list ($facultyFirst, $facultyMiddle, $facultyLast) = NameMatcher::splitName($facultyName, 3);
            $formattedFacultyName = formatName($facultyFirst, $facultyMiddle, $facultyLast);
            $traineeName = trim($line[1]);
            list ($traineeFirst, $traineeMiddle, $traineeLast) = NameMatcher::splitName($traineeName, 3);
            $formattedTraineeName = formatName($traineeFirst, $traineeMiddle, $traineeLast);
            if (in_array($formattedTraineeName, $seenTraineeNames)) {
                # trust only first row for each trainee
                return [[], [], []];
            }
            $seenTraineeNames[] = $formattedTraineeName;
            $uploadNames = FALSE;
            if (isset($line['record_id']) && ($line['record_id'] == "skip")) {
                return [[], [], []];
            } else if (isset($line['record_id']) && ($line['record_id'] == "new")) {
                $matchedRecords = [NEW_PREFIX.$traineeName];
                $uploadNames = TRUE;
            } else if (isset($line['record_id']) && ($line['record_id'])) {
                $matchedRecords = [$line['record_id']];
            } else {
                $matchedRecords = getMatchedRecords($traineeName, $firstNames, $lastNames);
            }

            $trainingPeriod = trim($line[3]);
            $publicationText = trim($line[4]);
            if ($trainingPeriod && preg_match("/\s*[-–]\s*/", $trainingPeriod)) {
                $trainingAry = preg_split("/\s*[-–]\s*/", $trainingPeriod);
                $trainingStartYear = trim($trainingAry[0]);
                $trainingEndYear = trim($trainingAry[1] ?? "");
                if (DateManagement::isDate($trainingStartYear) || DateManagement::isYear($trainingStartYear)) {
                    $trainingStartDate = processDate($trainingStartYear, "07-01");
                } else {
                    $comments["Training Period"] = "$formattedTraineeName has a start year of training, with '$trainingStartYear.' A year was expected here.";
                }
                if ($trainingEndYear == "Present") {
                    if ($isFirstUpload) {
                        $comments["Training Period"] = "$formattedTraineeName has '$trainingEndYear' as their end year of training. A year was expected here.";
                    } else {
                        $trainingEndDate = "";
                    }
                } else if (DateManagement::isDate($trainingEndYear) || DateManagement::isYear($trainingEndYear)) {
                    $trainingEndDate = processDate($trainingEndYear, "06-30");
                } else if (preg_match("/^Present/", $trainingEndYear)) {
                    $comments["Training Period"] = "$formattedTraineeName has '$trainingEndYear' as their end year of training. It appears that you have hidden or special characters at the end of this field. Please remove them and transform the date into a numerical year.";
                } else {
                    $comments["Training Period"] = "$formattedTraineeName has an unusual end year of training, with '$trainingEndYear.' This sometimes is due to hidden or special characters in the CSV. A numerical year is expected.";
                }
            } else if (DateManagement::isYear($trainingPeriod) || DateManagement::isDate($trainingPeriod)) {
                $trainingStartDate = processDate($trainingPeriod, "01-01");
                $trainingEndDate = processDate($trainingPeriod, "12-31");
            } else {
                $comments["Training Period"] = "$formattedTraineeName has an unusual Training Period. The format of [StartYear]-[EndYear] is expected; you have '$trainingPeriod.' This is sometimes due to hidden or special characters in the CSV. Please correct this.";
            }
            if (!empty($comments)) {
                $line['comments'] = $comments;
                return [[$line], [], []];
            } else {
                if (count($matchedRecords) == 1) {
                    $matchedRecordId = $matchedRecords[0];
                    addCustomGrantToSignUp($uploadForRecord, $matchedRecordId, $awardNo, $category, "", $trainingStartDate, $trainingEndDate, $token, $server, $metadata);
                    addNormativeRow($uploadForRecord, $matchedRecordId, $facultyName, "", $trainingStartDate, $trainingStarts, $mentors, $importedMentors, $metadata, $uploadNames ? $traineeName : "");

                    $pmids = parsePublications($publicationText, $formattedTraineeName, $formattedFacultyName, $warnings, $metadata, $pid);
                    if (!empty($pmids)) {
                        $myPriorPMIDs = $priorPMIDs[$matchedRecordId] ?? [];
                        $newPMIDs = array_diff($pmids, $myPriorPMIDs);
                        $startInstance = empty($priorPMIDs[$matchedRecordId]) ? 1 : max(REDCapManagement::makeArrayOneType(array_keys($priorPMIDs[$matchedRecordId]), "int")) + 1;
                        $newRows = Publications::getCitationsFromPubMed($newPMIDs, $metadata, "manual", $matchedRecordId, $startInstance, $myPriorPMIDs, $pid);
                        for ($j = 0; $j < count($newRows); $j++) {
                            $newRows[$j]['citation_include'] = '1';
                        }
                        $uploadForRecord = array_merge($uploadForRecord, $newRows);
                    }
                } else if (count($matchedRecords) >= 2) {
                    $comments["Record"] = getMoreThan1RecordText($matchedRecords);
                } else {
                    $comments["Record"] = getCreateRecordText();
                }
            }
        } catch (\Exception $e) {
            $comments["Exception"] = $e->getMessage();
        }
    } else {
        # fundamentally invalid line
        return [[], [], []];
    }
    if (empty($comments)) {
        return [[], $uploadForRecord, $warnings];
    } else {
        $line['comments'] = $comments;
        return [[$line], [], []];
    }
}

function removeExtraSpaces(&$ary) {
    foreach ($ary as $idx => $value) {
        if (is_string($value)) {
            $value = trim($value);
            $value = preg_replace("/^&nbsp;/", "", $value);
            $value = preg_replace("/&nbsp;$/", "", $value);
            $ary[$idx] = $value;
        }
    }
}

function processTable8Line($line, $awardNo, $category, $token, $server, $metadata, $choices, $firstNames, $lastNames, $trainingStarts, $mentors, $importedMentors, $table) {
    $unprocessed = [];
    $upload = [];
    $startIndex = ($table == "8A") ? 0 : 1;
    if ($line[0] && $line[1] && count($line) >= 9) {
        try {
            cleanLine($line);
            removeExtraSpaces($line);
            $traineeName = trim($line[0]);
            $facultyName = trim($line[$startIndex + 1]);
            $trainingStartDate = processDate(trim($line[$startIndex + 2]), "07-01");

            $supportDuringTraining = trim($line[$startIndex + 3]);
            $supportDuringTraining = preg_replace("/\s{2,}/", "\n", $supportDuringTraining);

            if (!preg_match("/In Training/i", $line[$startIndex + 4])) {
                $degreesAndYearsLines = preg_split("/[\n\r]+/", trim($line[$startIndex + 4]));
                $degreesAndYears = processDegreesAndYears($degreesAndYearsLines);
            } else {
                $degreesAndYears = [];
            }
            $researchTopic = trim($line[$startIndex + 5]);

            $positions = [];
            parsePositions(trim($line[$startIndex + 6]), $positions);
            parsePositions(trim($line[$startIndex + 7]), $positions);
            $grants = parseGrants(trim($line[$startIndex + 8]));

            $uploadNames = FALSE;
            if (isset($line['record_id']) && ($line['record_id'] == "skip")) {
                return [[], [], []];
            } else if (isset($line['record_id']) && ($line['record_id'] == "new")) {
                $matchedRecords = [NEW_PREFIX.$traineeName];
                $uploadNames = TRUE;
            } else if (isset($line['record_id']) && ($line['record_id'])) {
                $matchedRecords = [$line['record_id']];
            } else {
                $matchedRecords = getMatchedRecords($traineeName, $firstNames, $lastNames);
            }
            if (count($matchedRecords) == 1) {
                $matchedRecordId = $matchedRecords[0];
                addCustomGrantToSignUp($upload, $matchedRecordId, $awardNo, $category, $researchTopic, $trainingStartDate, "", $token, $server, $metadata);
                transformDegreesToREDCap($token, $server, $upload, $matchedRecordId, $degreesAndYears, $choices);
                transformPositionsToREDCap($token, $server, $upload, $matchedRecordId, $positions, $choices);
                transformGrantsToREDCap($token, $server, $upload, $matchedRecordId, $grants, $choices);
                addNormativeRow($upload, $matchedRecordId, $facultyName, $supportDuringTraining, $trainingStartDate, $trainingStarts, $mentors, $importedMentors, $metadata, $uploadNames ? $traineeName : "");
            } else if (count($matchedRecords) >= 2) {
                $line["comments"] = ["Record" => getMoreThan1RecordText($matchedRecords)];
                $unprocessed[] = $line;
            } else {
                $line["comments"] = ["Record" => getCreateRecordText()];
                $unprocessed[] = $line;
            }
        } catch (\Exception $e) {
            $line['comments'] = ["Exception" => $e->getMessage()];
            $unprocessed[] = $line;
        }
        return [$unprocessed, $upload, []];
    } else {
        # fundamentally invalid line
        return [[], [], []];
    }
}

function getCreateRecordText() {
    return "No records matched. Would you like to create a record?";
}

function getMoreThan1RecordText($records) {
    return "More than one record matched: ".implode(", ", $records);
}

function addNormativeNameRow(&$upload, $recordId, $name) {
    list($firstName, $middleName, $lastName) = NameMatcher::splitName($name, 3);
    $row = ["record_id" => $recordId];
    if ($firstName) {
        $row['identifier_first_name'] = $firstName;
    }
    if ($lastName) {
        $row['identifier_last_name'] = $lastName;
    }
    if ($middleName) {
        $row['identifier_middle'] = $middleName;
    }
    if (count($row) > 1) {
        $upload[] = $row;
    }
}

function addNormativeRow(&$upload, $recordId, $facultyName, $supportDuringTraining, $trainingStartDate, $trainingStarts, $mentors, $importedMentors, $metadata, $traineeName) {
    list($facultyFirstName, $facultyMiddleName, $facultyLastName) = NameMatcher::splitName($facultyName, 3);
    $normativeRow = [
        "record_id" => $recordId,
        "redcap_repeat_instrument" => "",
        "redcap_repeat_instance" => "",
    ];
    $recordTrainingStart = $trainingStarts[$recordId] ?? FALSE;
    if ($trainingStartDate && !$recordTrainingStart) {
        $normativeRow['identifier_start_of_training'] = $trainingStartDate;
    }
    if ($facultyName && !isMentorAlreadyAdded($facultyFirstName, $facultyLastName, $mentors[$recordId] ?? [])) {
        $formattedFacultyName = formatName($facultyFirstName, $facultyMiddleName, $facultyLastName);
        $normativeRow[getImportedMentorField($metadata)] = (isset($importedMentors[$recordId]) && $importedMentors[$recordId]) ? $importedMentors[$recordId].", $formattedFacultyName" : $formattedFacultyName;
    }
    if ($supportDuringTraining) {
        $normativeRow['identifier_support_summary'] = $supportDuringTraining;
    }
    if ($traineeName) {
        list ($traineeFirstName, $traineeMiddleName, $traineeLastName) = NameMatcher::splitName($traineeName, 3);
        $normativeRow["identifier_first_name"] = $traineeFirstName;
        $normativeRow["identifier_middle"] = $traineeMiddleName;
        $normativeRow["identifier_last_name"] = $traineeLastName;
    }
    if (count($normativeRow) > 3) {
        $upload[] = $normativeRow;
    }
}

function formatName($first, $middle, $last) {
    return NameMatcher::formatName($first, $middle, $last);
}

function transformDegreesToREDCap($token, $server, &$upload, $recordId, $degreesAndYears, $choices) {
    $instrument = "manual_degree";
    $fields = ["record_id", $instrument."_complete"];
    $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
    $maxInstance = REDCapManagement::getMaxInstance($redcapData, $instrument, $recordId);

    foreach ($degreesAndYears as $degree) {
        $date = $degree['year'];
        $degreeText = $degree['degree'];
        $degreeText = trim(strtolower(str_replace(".", "", $degreeText)));
        $degreeTextWithoutSpaces = preg_replace("/\s+/", "", $degreeText);
        $matchedIndex = FALSE;
        foreach ($choices['imported_degree'] as $index => $label) {
            $label = strtolower(str_replace(".", "", $label));
            $labelWithoutSpaces = preg_replace("/\s+/", "", $label);
            if (($label == $degreeText) || ($labelWithoutSpaces == $degreeTextWithoutSpaces)) {
                $matchedIndex = $index;
                break;
            }
        }

        if ($matchedIndex) {
            $maxInstance++;
            $uploadRow = [
                "record_id" => $recordId,
                "redcap_repeat_instrument" => $instrument,
                "redcap_repeat_instance" => $maxInstance,
                $instrument."_complete" => "2",
                "imported_degree" => $matchedIndex,
            ];
            if ($date) {
                $ts = strtotime($date);
                $uploadRow['imported_degree_month'] = date("m", $ts);
                $uploadRow['imported_degree_year'] = date("Y", $ts);
            }
            $upload[] = $uploadRow;
        } else {
            Application::log("Could not match $degreeText with a label");
        }
    }
}

function transformGrantsToREDCap($token, $server, &$upload, $recordId, $grants, $choices) {
    $instrument = "custom_grant";
    $fields = ["record_id", $instrument."_complete"];
    $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
    $maxInstance = REDCapManagement::getMaxInstance($redcapData, $instrument, $recordId);

    foreach ($grants as $grant) {
        $maxInstance++;
        $uploadRow = [
            "record_id" => $recordId,
            "redcap_repeat_instance" => $maxInstance,
            "redcap_repeat_instrument" => $instrument,
            "custom_start" => $grant['year'],
            $instrument."_complete" => "2",
        ];

        if ($grant['activity_code'] && $grant['institute']) {
            $uploadRow['custom_number'] = $grant['institute']." ".$grant['activity_code'];
        } else if ($grant['activity_code']) {
            $uploadRow['custom_number'] = $grant['activity_code'];
        } else if ($grant['institute']) {
            $uploadRow['custom_number'] = $grant['institute'];
        }

        $roleLowerCase = strtolower($grant['role']);
        $otherRole = FALSE;
        foreach ($choices['role'] as $index => $label) {
            if ($label == "Other") {
                $otherRole = $index;
            }
            if (preg_match("/-/", $label)) {
                $labelWithoutDash = str_replace("-", "", $label);
                $options = [strtolower($label), strtolower($labelWithoutDash)];
            } else {
                $options = [strtolower($label)];
            }
            if (in_array($roleLowerCase, $options)) {
                $uploadRow['custom_role'] = $index;
                break;
            }
        }
        if ($grant['role'] && !isset($uploadRow['custom_role'])) {
            $uploadRow['custom_role'] = $otherRole;
            $uploadRow['custom_role_other'] = $grant['role'];
        }
        $upload[] = $uploadRow;
    }
}

function transformPositionsToREDCap($token, $server, &$upload, $recordId, $positions, $choices) {
    $instrument = "position_change";
    $fields = ["record_id", $instrument."_complete"];
    $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
    $maxInstance = REDCapManagement::getMaxInstance($redcapData, $instrument, $recordId);

    foreach ($positions as $position) {
        $maxInstance++;
        $uploadRow = [
            "record_id" => $recordId,
            "redcap_repeat_instrument" => $instrument,
            "redcap_repeat_instance" => $maxInstance,
            "promotion_job_title" => $position["title"] ?? "",
            "promotion_institution" => $position['institution'] ?? "",
            $instrument."_complete" => "2",
        ];
        $itemsToSearchFor = [
            "title" => "promotion_rank",
            "sector" => "promotion_workforce_sector",
            "activity" => "promotion_activity",
        ];
        foreach ($itemsToSearchFor as $positionField => $redcapField) {
            if (isset($position[$positionField]) && $position[$positionField] && isset($choices[$redcapField])) {
                foreach ($choices[$redcapField] as $index => $label) {
                    if (strtolower($position[$positionField]) == strtolower($label)) {
                        $uploadRow[$redcapField] = $index;
                        break;
                    }
                }
            }
        }
        $upload[] = $uploadRow;
    }
}

function parseGrants($text) {
    $text = str_replace("—", " ", $text);
    $text = str_replace("-", " ", $text);
    $text = trim($text);
    if (!$text) {
        return [];
    }

    $grants = [];
    $doubleLineRegexes = [
        "/\n\n/",
        "/\r\r/",
        "/\n\r\n\r/",
        "/\r\n\r\n/",
    ];
    foreach ($doubleLineRegexes as $doubleLineRegex) {
        if (preg_match($doubleLineRegex, $text)) {
            break;
        }
        $doubleLineRegex = $doubleLineRegexes[0]; /// for last case
    }
    if (preg_match($doubleLineRegex, $text)) {
        $lines = preg_split($doubleLineRegex, $text);
        $regex = "/\s*\/?[\n\r]+\s*/";
    } else {
        $lines = preg_split("/[\n\r]+/", $text);
        $regex = "/\s*\/\s*/";
    }

    $unfinishedLine = "";
    $j = 0;
    do {
        if ($lines[$j]) {
            $unfinishedLine = processGrantLine($unfinishedLine." ".$lines[$j], $grants, $regex);
        }
        $j++;
    } while ($j < count($lines));
    if ($unfinishedLine) {
        $nodes = preg_split($regex, $unfinishedLine);
        list($institute, $activityCode) = processGrantIdentifier($nodes[0]);
        $role = $nodes[1] ?? "";
        $year = processDate(getFirstYear($nodes[2] ?? ""), "01-01");
        $grants[] = [
            "activity_code" => $activityCode,
            "institute" => $institute,
            "role" => $role,
            "year" => $year,
        ];
    }

    return $grants;
}

function getFirstYear($year) {
    if (preg_match("/(\d{4})-\d{4}/", $year, $matches)) {
        return $matches[1];
    } else if (DateManagement::isYear($year)) {
        return $year;
    } else if (trim($year) === "") {
        return "";
    } else {
        throw new \Exception("Could not interpret '$year' as a year.");
    }
}

function processGrantIdentifier($grantText) {
    if (preg_match("/\s+/", $grantText)) {
        $ary = preg_split("/\s+/", $grantText);
        $institute = $ary[0];
        $activityCode = $ary[1];
    } else {
        $activityCode = $grantText;
        $institute = "";
    }
    return [$activityCode, $institute];
}

function processGrantLine($line, &$grants, $regex) {
    $line = str_replace("  ", " ", $line);
    $nodes = preg_split($regex, $line);
    if (count($nodes) >= 3) {
        for ($k = 0; $k < count($nodes); $k++) {
            $nodes[$k] = trim($nodes[$k]);
        }
        list($institute, $activityCode) = processGrantIdentifier($nodes[0]);
        $year = processDate(getFirstYear($nodes[2]), "01-01");
        $grants[] = [
            "activity_code" => $activityCode,
            "institute" => $institute,
            "role" => $nodes[1],
            "year" => $year,
        ];
        return "";
    } else {
        return $line;
    }
}

function parsePositions($text, &$positions) {
    $text = trim($text);
    if (!$text) {
        return;
    }

    $lines = preg_split("/([\n\r]+|[ \t]{2,})/", $text);
    for ($j = 0; $j < count($lines); $j++) {
        $lines[$j] = trim($lines[$j]);
    }
    if (count($lines) == 5) {
        $positions[] = [
            "title" => trim($lines[0]),
            "field" => trim($lines[1]),
            "institution" => trim($lines[2]),
            "sector" => trim($lines[3]),
            "activity" => trim($lines[4]),
        ];
    } else if (count($lines) == 4) {
        $positions[] = [
            "title" => trim($lines[0]),
            "field" => "",
            "institution" => trim($lines[1]),
            "sector" => trim($lines[2]),
            "activity" => trim($lines[3]),
        ];
    } else if (count($lines) == 3) {
        $positions[] = [
            "title" => trim($lines[0]),
            "field" => "",
            "institution" => trim($lines[1]),
            "sector" => "",
            "activity" => trim($lines[2]),
        ];
    } else {
        throw new \Exception("Invalid number of lines (".count($lines).") for position: ".$text);
    }
}

function processDegreesAndYears($lines) {
    $ary = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line && preg_match("/^(.+)\((.+)\)$/", $line, $matches)) {
            $degree = $matches[1];
            $year = $matches[2];
            $ary[] = ["degree" => $degree, "year" => $year];
        } else if ($line) {
            $nodes = preg_split("/\s+/", $line);
            $degree = $nodes[0];
            $year = ((count($nodes) > 1) && $nodes[1]) ? processDate($nodes[1], "-06-01") : "";
            $ary[] = ["degree" => $degree, "year" => $year];
        }
    }
    return $ary;
}

function processDate($date, $defaultMD) {
    if (DateManagement::isMDY($date)) {
        return DateManagement::MDY2YMD($date);
    } else if (DateManagement::isYMD($date)) {
        return $date;
    } else if (DateManagement::isMY($date)) {
        return DateManagement::MY2YMD($date);
    } else if (DateManagement::isYear($date)) {
        return $date."-".$defaultMD;
    } else if ($date === "") {
        return "";
    } else if ($fragment = DateManagement::getDateFragment($date)) {
        if (DateManagement::isDate($fragment) || DateManagement::isMY($fragment) || DateManagement::isYear($fragment)) {
            return processDate($fragment, $defaultMD);
        } else {
            throw new \Exception("Invalid date $date. The fragment ($fragment) is promising but could not be parsed. Try M-D-Y, Y-MD, or M-Y.");
        }
    } else if ($convertedDate = DateManagement::convertExcelDate($date)) {
        if (DateManagement::isDate($convertedDate) || DateManagement::isMY($convertedDate) || DateManagement::isYear($convertedDate)) {
            return processDate($convertedDate, $defaultMD);
        } else {
            throw new \Exception("Cannot convert this date ($date) from Excel. Please try changing the formatting of the column to M-D-Y, Y-M-D, or M-Y.");
        }
    } else if (!DateManagement::isDate($date)) {
        throw new \Exception("Invalid date $date.");
    } else {
        throw new \Exception("Date format not supported for $date. Please try M-D-Y, Y-M-D, or M-Y.");
    }
}

function readFileAsDataLines($filename) {
    $fp = fopen($filename, "r");
    $lines = [];
    $lineNum = 0;
    while ($line = fgetcsv($fp)) {
        if ($lineNum > 0) {
            $lines[] = $line;
        }
        $lineNum++;
    }
    fclose($fp);
    return $lines;
}