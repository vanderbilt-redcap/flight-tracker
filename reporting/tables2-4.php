<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\NIHTables;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\ReactNIHTables;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\CustomGrantFactory;
use \Vanderbilt\CareerDevLibrary\GrantLexicalTranslator;
use \Vanderbilt\CareerDevLibrary\Grant;

# mostly a driver for the React tables in the tables2-4/ directory - returns a JSON
# React transforms JSON data into HTML

require_once(dirname(__FILE__)."/../classes/Autoload.php");

if (in_array(gethostname(), ["scottjpearson", "ORIWL-KCXDJK7.local", "VICTRKCXDJK7NLJ"])) {
    # Testing only - to allow to run with React server using 'npm start'
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET,HEAD,OPTIONS,POST,PUT");
    header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, X-Requested-With");
    define("NOAUTH", TRUE);
} else {
    Application::applySecurityHeaders($_GET['pid'] ?? $_GET['project_id'] ?? NULL);
}
require_once(dirname(__FILE__)."/../small_base.php");

# if $_POST is a JSON instead of a url-encoded string
# eventually in newer versions of ExtMod framework
$entityBody = file_get_contents('php://input');
if ($entityBody) {
    $_POST = json_decode($entityBody, TRUE) ?? $_POST;
}

Application::increaseProcessingMax(1);

$reactHandler = new ReactNIHTables($token, $server, $pid);
$module = Application::getModule();
$titleHTML = "<title>Flight Tracker Feedback for NIH Training Tables</title>".Application::makeIcon();

if (isset($_POST['action']) && $token && $server && $pid) {
    $action = Sanitizer::sanitize($_POST['action']);
    ReactNIHTables::convertJSONs($_POST);
    $data = [];
    try {
        Application::keepAlive($pid);
        if (isset($_GET['NOAUTH'])) {
            $data['error'] = "NOAUTH should not appear on this page.";
        } else if ($action == "getFooter") {
            $data['html'] = Application::getFooter();
        } else if ($action == "getTable") {
            $metadata = Download::metadata($token, $server);
            $nihTables = new NIHTables($token, $server, $pid, $metadata);
            $data['table'] = $reactHandler->getDataForTable(Sanitizer::sanitizeArray($_POST), $nihTables);

            $table1Pid = Application::getTable1PID();
            $tableNum = Sanitizer::sanitizeInteger($_POST['tableNum'] ?? "");
            if ($table1Pid && ($tableNum == 2)) {
                $data['coaching'] = $reactHandler->getProgramEntriesFromTable1($table1Pid, $nihTables);
            } else {
                $data['coaching'] = [];
            }
        } else if ($action == "saveDelegateEmail") {
            $scholarEmail = Sanitizer::sanitize($_POST['email'] ?? "");
            if (!$scholarEmail || REDCapManagement::isEmailOrEmails($scholarEmail)) {
                $databaseKey = ReactNIHTables::makeDelegateEmailId($scholarEmail);
                $value = Sanitizer::sanitize($_POST['value'] ?? "");
                if (REDCapManagement::isEmailOrEmails($value) || ($value == "")) {
                    Application::saveSetting($databaseKey, $value, $pid);
                    $data['result'] = "Saved.";
                }
            }
        } else if ($action == "saveTable") {
            $tableData = $_POST['tableData'];     // sanitizing causes double-escapes of HTML
            $tableNum = Sanitizer::sanitize($_POST['tableNum']);
            $name = Sanitizer::sanitize($_POST['name']);
            $dateOfReport = Sanitizer::sanitize($_POST['date']);
            $faculty = Sanitizer::sanitizeArray($_POST['facultyList']);
            $grantPI = Sanitizer::sanitize($_POST['grantPI']);
            $grantTitle = Sanitizer::sanitize($_POST['grantTitle']);

            $metadata = Download::metadata($token, $server);
            $nihTables = new NIHTables($token, $server, $pid, $metadata);
            $data = $reactHandler->saveData($nihTables, $tableNum, $tableData, $name, $dateOfReport, $faculty, $grantTitle, $grantPI);
        } else if ($action == "getProjectInfo") {
            $name = Sanitizer::sanitize($_POST['name']);
            $data = $reactHandler->getProjectInfo($name);
        } else if ($action == "getSavedTableNames") {
            $data = $reactHandler->getSavedTableNames();
        } else if ($action == "setSavedTableNames") {
            $newSavedNames = Sanitizer::sanitizeArray($_POST['savedTableNames'] ?? []);
            $reactHandler->setSavedTableNames($newSavedNames);
            $data = $reactHandler->getSavedTableNames();
        } else if ($action == "lookup") {
            $tableNum = Sanitizer::sanitize($_POST['tableNum']);
            $post = Sanitizer::sanitizeArray($_POST);
            if (in_array($tableNum, [2, 4])) {
                $data = $reactHandler->lookupValuesFor2And4($post);
            } else {
                $data = $reactHandler->lookupValuesFor3($post);
            }
        } else if ($action == "lookupRePORTER") {
            $metadata = Download::metadata($token, $server);
            $dateOfReport = Sanitizer::sanitize($_POST['date']);
            $data = $reactHandler->lookupRePORTER(Sanitizer::sanitizeArray($_POST), $metadata, $dateOfReport);
        } else if ($action == "lookupFaculty") {
            $faculty = Sanitizer::sanitizeArray($_POST['faculty']);
            $dateOfReport = Sanitizer::sanitize($_POST['date']);
            if (!empty($faculty)) {
                $metadata = Download::metadata($token, $server);
                $data = $reactHandler->lookupFacultyInRePORTER($faculty, $metadata, $dateOfReport);
            }
        } else if ($action == "getInstitutions") {
            $data = Application::getInstitutions($pid);
        } else if ($action == "lookupInstitution") {
            $institutions = Application::getInstitutions($pid);
            $dateOfReport = Sanitizer::sanitize($_POST['date']);
            $name = Sanitizer::sanitize($_POST['savedName'] ?? "");
            if (!empty($institutions)) {
                $metadata = Download::metadata($token, $server);
                $data = $reactHandler->lookupTrainingGrantsByInstitutionsInRePORTER($institutions, $metadata, $dateOfReport, $name);
                $data = ReactNIHTables::transformToCamelCase($data, ["Award Number"]);
            }
        } else if ($action == "lookupOverlappingFaculty") {
            $awards = Sanitizer::sanitizeArray($_POST['awards']);
            $data = $reactHandler->lookupOverlappingFaculty($awards);
        } else if ($action == "saveOverlappingFaculty") {
            $data = $reactHandler->saveOverlappingFaculty(Sanitizer::sanitizeArray($_POST));
        } else if ($action == "savePeople") {
            $metadata = Download::metadata($token, $server);
            $nihTables = new NIHTables($token, $server, $pid, $metadata);
            $data = $reactHandler->savePeople(Sanitizer::sanitizeArray($_POST), $nihTables);
        } else if ($action == "sendVerificationEmail") {
            $metadata = Download::metadata($token, $server);
            $nihTables = new NIHTables($token, $server, $pid, $metadata);

            # NOTE: Not default_from in order to be more personal
            $data = $reactHandler->sendVerificationEmail($_POST, $nihTables);
        } else if ($action == "getDateOfLastVerification") {
            $data['dates'] = $reactHandler->getDatesOfLastVerification($_POST);
            $data['notes'] = $reactHandler->getNotesData($_POST);
            $data['delegates'] = $reactHandler->getDelegateEmails($_POST);
        } else if ($action == "getMatches") {
            $faculty = Sanitizer::sanitizeArray($_POST['faculty']);
            $dateOfReport = Sanitizer::sanitize($_POST['date']);
            if (!empty($faculty)) {
                $metadata = Download::metadata($token, $server);
                $choices = DataDictionaryManagement::getChoices($metadata);
                $nihTables = new NIHTables($token, $server, $pid, $metadata);
                $data["matches"] = $reactHandler->findMatches($faculty, $dateOfReport, $nihTables);
                $data["departments"] = $choices["summary_primary_dept"];
            } else {
                $data['error'] = "No faculty specified.";
            }
        } else if ($action == "manuallyApprove") {
            $email = Sanitizer::sanitize($_POST['email']);
            $tables = Sanitizer::sanitizeArray($_POST['tables']);
            if ($email && REDCapManagement::isEmail($email)) {
                $data = $reactHandler->saveConfirmationTimestamp($email, $tables);
            } else {
                $data["error"] = "Invalid email";
            }
        } else if ($action == "getFundingSource") {
            $customGrantRow = Sanitizer::sanitizeArray($_POST['customGrant']);
            $customGrantRow["record_id"] = 1;
            $customGrantRow["redcap_repeat_instance"] = 1;
            $customGrantRow["redcap_repeat_instrument"] = "custom_grant";
            $facultyName = Sanitizer::sanitize($_POST['facultyName']);
            $lexTrans = new GrantLexicalTranslator($token, $server, Application::getModule(), $pid);
            $metadata = Download::metadata($token, $server);
            $gf = new CustomGrantFactory($facultyName, $lexTrans, $metadata, "Grant", $token, $server);
            $gf->processRow($customGrantRow, [$customGrantRow], $token);
            $fundingSource = NIHTables::$NA;
            foreach ($gf->getGrants() as $grant) {
                $fundingSource = $grant->getTable4AbbreviatedFundingSource() ?? NIHTables::$NA;
                if (preg_match("/".Grant::$fdnOrOther."/", $fundingSource)) {
                    $fundingSource = NIHTables::makeComment($fundingSource);
                }
                break;
            }
            $data['fundingSource'] = $fundingSource;
        } else if ($action == "saveToREDCap") {
            $genericUploadRow = Sanitizer::sanitizeArray($_POST['values'], TRUE, FALSE);
            $facultyName = Sanitizer::sanitize($_POST['name']);
            $dateOfReport = Sanitizer::sanitizeDate($_POST['dateOfReport']);
            $instrument = "custom_grant";
            if ($facultyName && !empty($genericUploadRow)) {
                $metadata = Download::metadata($token, $server);
                list($firstNamesByPid, $lastNamesByPid, $emailsByPid) = NIHTables::getNamesByPid();
                $matches = NIHTables::findMatchesInAllFlightTrackers($facultyName, $firstNamesByPid, $lastNamesByPid);
                foreach ($matches as $coords) {
                    list($currPid, $recordId) = explode(":", $coords);
                    $currToken = Application::getSetting("token", $currPid);
                    $currServer = Application::getSetting("server", $currPid);
                    if ($currToken && $currServer && REDCapManagement::isActiveProject($currPid)) {
                        $maxInstance = Download::getMaxInstanceForRepeatingForm($currToken, $currServer, $instrument, $recordId);
                        $uploadRow = [
                            "record_id" => $recordId,
                            "redcap_repeat_instrument" => $instrument,
                            "redcap_repeat_instance" => ($maxInstance + 1),
                        ];
                        foreach ($genericUploadRow as $field => $value) {
                            $uploadRow[$field] = $value;
                        }
                        try {
                            $data[$currPid] = Upload::oneRow($uploadRow, $currToken, $currServer);
                        } catch (\Exception $e) {
                            $data[$currPid] = ["error" => $e->getMessage()];
                        }
                    } else {
                        $data[$currPid] = ["error" => "Project not active."];
                    }
                }
            } else {
                $data['error'] = "No name specified.";
            }
        } else if ($action == "getInstrumentMetadata") {
            $instrument = Sanitizer::sanitize($_POST['instrument']);
            if ($instrument) {
                $metadata = Download::metadata($token, $server);
                $requestedFields = DataDictionaryManagement::getFieldsFromMetadata($metadata, $instrument);
                if (!empty($requestedFields)) {
                    $metadataToReturn = [];
                    foreach ($metadata as $row) {
                        if (in_array($row['field_name'], $requestedFields)) {
                            $metadataToReturn[] = $row;
                        }
                    }
                    $data = $metadataToReturn;
                } else {
                    $data = ["error" => "Invalid instrument."];
                }
            } else {
                $data = ["error" => "No instrument supplied."];
            }
        } else {
            $data = ["error" => "Invalid action."];
        }
    } catch (\Exception $e) {
        if (Application::isLocalhost()) {
            $mssg = $e->getMessage()." ".$e->getTraceAsString();
        } else {
            $mssg = $e->getMessage();
        }
        $mssg = Sanitizer::sanitizeWithoutChangingQuotes($mssg);
        $trace = Sanitizer::sanitizeJSON(json_encode($e->getTrace()));
        $data = ["error" => $mssg, "trace" => $trace];
    }
    header("Content-type: application/json");
    echo json_encode($data);
} else if (isset($_GET['revise'])) {
    echo $titleHTML;
    $email = Sanitizer::sanitize($_GET['revise'] ?? "No email");
    $date = Sanitizer::sanitizeDate($_GET['date']);
    $requestedHash = Sanitizer::sanitize($_GET['hash']);
    $savedName = Sanitizer::sanitize($_GET['savedName'] ?? "");
    list($userids, $name) = $reactHandler->getUseridsAndNameAssociatedWithEmail($email);
    if ($reactHandler->verify($requestedHash, $email)) {
        list($tables, $emailHash) = $reactHandler->getInformation($requestedHash, $email);
        if (!empty($userids) && !isset($_GET['delegate'])) {
            $newUrl = Application::link("reporting/tables2-4WithAuth.php", $pid)."&revise=".urlencode($email)."&hash=".urlencode($emailHash)."&date=".urlencode($date)."&NOAUTH";
            header("Location: $newUrl");
        } else {
            echo $reactHandler->getTable1_4Header();
            echo $reactHandler->makeHTMLForNIHTableEdits($date, $name, $email, $emailHash, $tables, $savedName);
        }
    } else {
        echo "Not verified.";
    }
} else if (isset($_GET['confirm'])) {
    echo $titleHTML;
    $email = Sanitizer::sanitize($_GET['confirm'] ?? "No email");
    $date = Sanitizer::sanitizeDate($_GET['date']);
    $requestedHash = Sanitizer::sanitize($_GET['hash']);
    if ($reactHandler->verify($requestedHash, $email)) {
        list($tables, $emailHash) = $reactHandler->getInformation($requestedHash, $email);
        if ($reactHandler->hasUseridsAssociatedWithEmail($email) && !isset($_GET['delegate'])) {
            $reactHandler->saveConfirmationTimestamp($email, $tables);
            $newUrl = Application::link("reporting/tables2-4WithAuth.php", $pid) . "&confirm=" . urlencode($email) . "&hash=" . urlencode($emailHash) . "&date=" . urlencode($date) . "&NOAUTH";
            header("Location: $newUrl");
        } else {
            $reactHandler->saveConfirmationTimestamp($email, $tables);
            echo $reactHandler->getTable1_4Header();
            echo "Data saved. Thank you!";
        }
    }
} else if (isset($_GET['email'])) {
    echo $titleHTML;
    $email = Sanitizer::sanitize($_GET['email'] ?? "No email");
    $requestedHash = Sanitizer::sanitize($_GET['hash']);
    echo $reactHandler->getTable1_4Header();
    if ($reactHandler->verify($requestedHash, $email)) {
        list($userids, $name) = $reactHandler->getUseridsAndNameAssociatedWithEmail($email);
        list($tables, $emailHash) = $reactHandler->getInformation($requestedHash, $email);
        echo $reactHandler->saveNotes($_POST, $email, $tables);
    } else {
        echo "Invalid request.";
    }
} else {
    $manifestUrl = Application::link("reporting/tables2-4/public/manifest.json", $pid);
    $thisLink = Application::link("this", $pid);

    # from React
    echo "<!DOCTYPE html>
<html lang='en'>
  <head>
    <meta charset='utf-8' />
    <link rel='manifest' href='$manifestUrl' />
  </head>
  <body>
    <noscript>You need to enable JavaScript to run this app.</noscript>
    <div id='root' link='$thisLink'></div>
  </body>
</html>";
}
