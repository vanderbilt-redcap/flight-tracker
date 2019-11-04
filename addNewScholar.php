<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/classes/Download.php");
require_once(dirname(__FILE__)."/classes/Upload.php");

$fields = array(
		"First Name" => "identifier_first_name",
		"Last Name" => "identifier_last_name",
		"Email" => "identifier_email",
		);
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
		$uploadRow[$field] = $_POST[$field];
	}
	$feedback = Upload::oneRow($uploadRow, $token, $server);
	if ($feedback['error']) {
		echo "<div class='red padded'>ERROR! ".$feedback['error']."</div>\n";
	} else {
		echo "<div class='green padded'>Scholar successfully added to Record $recordId. They will be automatically processed and updated with each overnight run.</div>\n";
	}
} else {
	echo "<h1>Add a New Scholar</h1>\n";

	echo "<form action='<?= CareerDev::link("addNewScholar.php") ?>' method='POST'>\n";
	echo "<table style='margin:0px auto;'>\n";
	foreach ($fields as $label => $var) {
		echo "<tr>\n";
		echo "<td style='text-align: right; padding-right: 5px;'>$label:</td>\n";
		echo "<td padding-left: 5px;'><input type='text' name='$var'></td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "<p class='centered'><input type='submit' value='Add New Scholar'></p>\n";
	echo "</form>\n";

	echo "<h2>Add Scholars in Bulk</h2>\n";
	echo "<p class='centered>Supply a CSV Spreadsheet with the specified fields in <a href='".CareerDev::link("newFaculty.php")."'>this example</a>.</p>\n";
	echo "<form enctype='multipart/form-data' method='POST' action='".CareerDev::link("add.php")."'>\n";
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
