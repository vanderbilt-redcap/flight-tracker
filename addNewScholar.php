<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

$fields = [
		"First Name" => "identifier_first_name",
		"Last Name" => "identifier_last_name",
        "Email" => "identifier_email",
        "User-id" => "identifier_userid",
		];
if (checkPOSTKeys(array_values($fields))) {
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
		echo "<div class='red padded'>ERROR! ".$feedback['error']."</div>\n";
	} else {
		echo "<div class='green padded'>Scholar successfully added to Record $recordId. They will be automatically processed and updated with each overnight run.</div>\n";
	}
} else {
    $incompleteMssg = "";
    if ((count($_POST) > 0) && (count($_POST) < count($fields) + 1)) {
        $incompleteMssg = "<div class='red padded'>Not all of the fields were filled out. No new record created.</div>";
    }
    echo $incompleteMssg;
	echo "<h1>Add a New Scholar or Modify an Existing Scholar</h1>\n";

	$link = CareerDev::link("addNewScholar.php");
	echo "<form action='$link' method='POST'>\n";
	echo Application::generateCSRFTokenHTML();
	echo "<table style='margin:0px auto;'>\n";
	foreach ($fields as $label => $var) {
        $defaultValue = Sanitizer::sanitize($_POST[$var]) ?? "";
		echo "<tr>\n";
		echo "<td style='text-align: right; padding-right: 5px;'>$label:</td>\n";
		echo "<td padding-left: 5px;'><input type='text' name='$var' style='width: 250px;' value='$defaultValue'></td>\n";
		echo "</tr>\n";
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
	echo "<p class='centered'><input type='hidden' name='MAX_FILE_SIZE' value='3000000' />\n";
	echo "CSV Upload: <input type='file' name='csv'><br>\n";
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
