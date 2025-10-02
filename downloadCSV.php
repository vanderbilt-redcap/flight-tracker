<?php

use Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/classes/Autoload.php");

if ($_GET['file'] == "import.csv") {
	$file = "import.csv";
} elseif ($_GET['file'] == "import_positions.csv") {
	$file = "import_positions.csv";
} else {
	die("Invalid request.");
}
$file = REDCapManagement::makeSafeFilename($file);
$match = $_GET['match'];

$validFiles = ["import.csv", "import_positions.csv"];
if (!in_array($file, $validFiles) || !file_exists(dirname(__FILE__)."/".$file)) {
	die("Invalid request.");
}

$contents = file_get_contents(dirname(__FILE__)."/".$file);

if ($match == "record") {
	$contents = preg_replace("/^First Name,Last Name/", "Record ID", $contents);
} elseif ($match == "names") {
	$contents = preg_replace("/^Record ID/", "First Name,Last Name", $contents);
} else {
	die("Invalid request.");
}

header("Content-type: text/csv");
header("Content-Disposition: attachment; filename=".$file);
header("Pragma: no-cache");
header("Expires: 0");
echo $contents;
