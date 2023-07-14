<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\NameMatcher;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\NIHTables;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

$redcapLookupUrl = Application::link("mentor/lookupREDCapUseridFromREDCap.php");

$fields = [
		"First Name" => "identifier_first_name",
		"Last Name" => "identifier_last_name",
        "Email" => "identifier_email",
        "<a href='javascript:;' onclick='lookupREDCapUserid(\"$redcapLookupUrl\", $(\"#results\"));' title='Click to Look Up'>REDCap User-ID</a><br/><span class='smaller'>(optional; click to look up)</span>" => "identifier_userid",
		];
$requiredFields = ["identifier_first_name", "identifier_last_name", "identifier_email"];
if (($_POST['action'] == "oneByOne") && checkPOSTKeys($requiredFields)) {
    $recordIds = Download::recordIds($token, $server);
    $max = 0;
    foreach ($recordIds as $record) {
        if ($record > $max) {
            $max = $record;
        }
    }

    $recordId = $max + 1;
    $uploadRow = array(
        "record_id" => $recordId,
    );
    foreach (array_values($fields) as $field) {
        $uploadRow[$field] = REDCapManagement::sanitize($_POST[$field]);
    }
    $feedback = Upload::oneRow($uploadRow, $token, $server);
    \Vanderbilt\FlightTrackerExternalModule\queueUpInitialEmail($recordId);
    Application::refreshRecordSummary($token, $server, $pid, $recordId);
    if ($feedback['error']) {
        echo "<div class='red padded'>ERROR! " . $feedback['error'] . "</div>\n";
    } else {
        echo "<div class='green padded'>Scholar successfully added to Record $recordId. They will be automatically processed and updated with each overnight run.</div>\n";
    }
} else if (in_array($_POST['action'], ["importTrainees", "importFaculty", "importBoth"]) && in_array($_POST['tableNumber'], [5, 8])) {
    if (isset($_FILES['tableCSV']['tmp_name']) && is_string($_FILES['tableCSV']['tmp_name'])) {
        $filename = $_FILES['tableCSV']['tmp_name'];
    } else {
        $filename = "";
    }
    echo \Vanderbilt\FlightTrackerExternalModule\importNIHTable($_POST, $filename, $token, $server);
} else {
    $incompleteMssg = "";
    if ((count($_POST) > 0) && (count($_POST) < count($fields) + 1)) {
        $incompleteMssg = "<div class='red padded'>Not all of the fields were filled out. No new record created.</div>";
    }
    echo $incompleteMssg;
	echo "<h1>Add a New Scholar or Modify an Existing Scholar</h1>\n";

	$link = Application::link("addNewScholar.php");
	echo "<form action='$link' method='POST'>\n";
	echo Application::generateCSRFTokenHTML();
    echo "<input type='hidden' id='action' name='action' value='oneByOne' />";
	echo "<table style='margin:0px auto;'>\n";
	foreach ($fields as $label => $var) {
        $defaultValue = Sanitizer::sanitize($_POST[$var]) ?? "";
        $id = preg_replace("/^identifier_/", "", $var);
		echo "<tr>\n";
		echo "<td style='text-align: right; padding-right: 5px;'>$label:</td>\n";
		echo "<td padding-left: 5px;'><input type='text' name='$var' id='$id' style='width: 250px;' value='$defaultValue'></td>\n";
		echo "</tr>\n";
        if (preg_match("/User-ID/", $label)) {
            echo "<tr>";
            echo "<td id='results' class='centered' colspan='2'></td>";
            echo "</tr>";
        }
	}
	echo "</table>\n";
	echo "<p class='centered'><input type='submit' value='Add/Modify'></p>\n";
	echo "</form>\n";

	$bulkLink = CareerDev::link("add.php");
	echo "<h2>Add/Modify Scholars in Bulk</h2>\n";
	echo "<p class='centered' style='max-width: 800px; margin: 0 auto;'>Supply a CSV Spreadsheet with the specified fields in <a href='".CareerDev::link("newFaculty.php")."'>this example</a>. Please do not encode the values for the multiple-choice options; just specify the exact name of the option you are choosing. (E.g., do not specify 1 for Female or 2 for Male. Just specify 'Female' or 'Male'.)</p>\n";
	echo "<p class='centered'>If the same name is used for a scholar, any new values will overwrite what is already in REDCap.</p>\n";
	echo "<form enctype='multipart/form-data' method='POST' action='$bulkLink'>\n";
	echo Application::generateCSRFTokenHTML();
    echo "<input type='hidden' id='action' name='action' value='intakeTable' />";
    echo "<p class='centered max-width'><input type='hidden' name='MAX_FILE_SIZE' value='3000000' />\n";
	echo "CSV Upload: <input type='file' name='csv'><br/>\n";
    echo "<input type='checkbox' name='createNewRecords' id='createNewRecords' checked /> <label for='createNewRecords'>Create a New Record for Each Name.</label> If unchecked, it will try to match names to prior records.<br/>";
	echo "<button>Process File</button><br/>";
    echo "Please wait for the file to process.</p></form>\n";

    echo "<h2>Import Trainees from NIH Training Tables</h2>";
    echo "<p class='centered'>Duplicate names will be skipped.</p>";
    echo "<form enctype='multipart/form-data' method='POST' action='$link'>\n";
    echo Application::generateCSRFTokenHTML();
    echo "<p class='max-width centered'>";
    echo "<input type='radio' name='action' id='actionTrainees' value='importTrainees' checked /> <label for='actionTrainees'>Import Only Trainees as Scholars to be Tracked</label><br/>";
    echo "<input type='radio' name='action' id='actionFaculty' value='importFaculty' /> <label for='actionFaculty'>Import Only Faculty as Scholars to be Tracked</label><br/>";
    echo "<input type='radio' name='action' id='actionBoth' value='importBoth' /> <label for='actionBoth'>Import Both Trainees and Faculty as Scholars to be Tracked</label>";
    echo "</p>";
    echo "<p class='centered max-width'><select name='tableNumber'>";
    echo "<option value=''>---SELECT TABLE---</option>";
    echo "<option value='5'>Table 5</option>";
    echo "<option value='8'>Table 8 (except Part IV)</option>";
    echo "</select></p>";
    echo "<p class='centered max-width'><input type='hidden' name='MAX_FILE_SIZE' value='3000000' />\n";
    echo "Upload CSV (with headers in first row): <input type='file' name='tableCSV'><br/>\n";
    echo "<button>Process File</button>\n";
    echo "</p></form>\n";
}

function checkPOSTKeys($keys) {
	foreach ($keys as $key) {
		if (!isset($_POST[$key]) || ($_POST[$key] === "")) {
			return FALSE;
		}
	}
	return TRUE;
}
