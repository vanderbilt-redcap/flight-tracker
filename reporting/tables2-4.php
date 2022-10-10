<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\NIHTables;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\ReactNIHTables;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;

if (in_array(gethostname(), ["scottjpearson", "ORIWL-KCXDJK7.local"])) {
    # Testing only - to allow to run with React server using 'npm start'
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET,HEAD,OPTIONS,POST,PUT");
    header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, X-Requested-With");
    define("NOAUTH", TRUE);
}


$entityBody = file_get_contents('php://input');
if ($entityBody) {
    $_POST = json_decode($entityBody, TRUE) ?? $_POST;
}

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

Application::increaseProcessingMax(1);

$reactHandler = new ReactNIHTables($token, $server, $pid);
$module = Application::getModule();
if (isset($_POST['action']) && $token && $server && $pid) {
    $action = Sanitizer::sanitize($_POST['action']);
    $data = [];
    try {
        if ($action == "getFooter") {
            $data['html'] = Application::getFooter();
        } else if ($action == "getTable") {
            $metadata = Download::metadata($token, $server);
            $nihTables = new NIHTables($token, $server, $pid, $metadata);
            $data = $reactHandler->getDataForTable(Sanitizer::sanitizeArray($_POST), $nihTables);
        } else if ($action == "saveTable") {
            $tableData = Sanitizer::sanitizeArray($_POST['tableData'], FALSE);
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
    $str = json_encode($data);
    echo $str;
} else if (isset($_GET['revise'])) {
    echo Application::makeIcon();
    $email = Sanitizer::sanitize($_GET['revise'] ?? "No email");
    $date = Sanitizer::sanitize($_GET['date']);
    $requestedHash = Sanitizer::sanitize($_GET['hash']);
    $savedName = Sanitizer::sanitize($_GET['savedName'] ?? "");
    list($userids, $name) = $reactHandler->getUseridsAndNameAssociatedWithEmail($email);
    if ($reactHandler->verify($requestedHash, $email)) {
        list($tables, $emailHash) = $reactHandler->getInformation($requestedHash, $email);
        if (!empty($userids) && !isset($_GET['delegate'])) {
            $newUrl = Application::link("reporting/tables2-4WithAuth.php", $pid)."&revise=".urlencode($email)."&hash=".urlencode($emailHash)."&date=".urlencode($date);
            header("Location: $newUrl");
        } else {
            echo $reactHandler->getTable1_4Header();
            echo $reactHandler->makeHTMLForNIHTableEdits($date, $name, $email, $emailHash, $tables, $savedName);
        }
    } else {
        echo "Not verified.";
    }
} else if (isset($_GET['confirm'])) {
    $email = Sanitizer::sanitize($_GET['confirm'] ?? "No email");
    $date = Sanitizer::sanitize($_GET['date']);
    $requestedHash = Sanitizer::sanitize($_GET['hash']);
    if ($reactHandler->verify($requestedHash, $email)) {
        list($tables, $emailHash) = $reactHandler->getInformation($requestedHash, $email);
        if ($reactHandler->hasUseridsAssociatedWithEmail($email) && !isset($_GET['delegate'])) {
            $reactHandler->saveConfirmationTimestamp($email, $tables);
            $newUrl = Application::link("reporting/tables2-4WithAuth.php", $pid) . "&confirm=" . urlencode($email) . "&hash=" . urlencode($emailHash) . "&date=" . urlencode($date);
            header("Location: $newUrl");
        } else {
            $reactHandler->saveConfirmationTimestamp($email, $tables);
            echo $reactHandler->getTable1_4Header();
            echo "Data saved. Thank you!";
        }
    }
} else if (isset($_GET['email'])) {
    $email = Sanitizer::sanitize($_GET['email'] ?? "No email");
    $requestedHash = Sanitizer::sanitize($_GET['hash']);
    echo $reactHandler->getTable1_4Header();
    if ($reactHandler->verify($requestedHash, $email)) {
        list($userids, $name) = $reactHandler->getUseridsAndNameAssociatedWithEmail($email);
        if (!empty($userids)) {
            list($tables, $emailHash) = $reactHandler->getInformation($requestedHash, $email);
            echo $reactHandler->saveNotes($_POST, $email, $tables);
        } else {
            echo "Could not match user-id.";
        }
    } else {
        echo "Invalid request.";
    }
} else {
    $manifestUrl = Application::link("reporting/react-2/public/manifest.json", $pid);
    $thisLink = Application::link("this", $pid);

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
