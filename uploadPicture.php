<?php

use Vanderbilt\CareerDevLibrary\Sanitizer;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Upload;
use Vanderbilt\CareerDevLibrary\FileManagement;

require_once(__DIR__."/charts/baseWeb.php");
require_once(__DIR__."/classes/Autoload.php");

$records = Download::recordIds($token, $server);
$recordId = Sanitizer::getSanitizedRecord($_GET['record'], $records);
if ($recordId) {
	$field = "identifier_picture";
	if (isset($_FILES['picture']) && file_exists($_FILES['picture']['tmp_name'])) {
		$mimeType = $_FILES['picture']['type'];
		$suffix = FileManagement::getMimeSuffix($mimeType);
		$base64 = FileManagement::getBase64OfFile($_FILES['picture']['tmp_name'], $mimeType);
		Upload::file($pid, $recordId, $field, $base64, "scholar.".$suffix);
	}
	$names = Download::names($token, $server);
	$name = $names[$recordId] ?? "";
	$base64 = Download::fileAsBase64($pid, $field, $recordId);
	$thisLink = Application::link("this", $pid)."&record=".$recordId;
	echo "<h1>Upload a Picture of $name</h1>";
	if ($base64) {
		echo "<h4>Current Image (resized)</h4><p class='centered'><img src='$base64' style='max-width: 300px; max-height: 300px; width: auto; height: auto;' alt='Image of $name' /></p>";
	}
	echo "<form action='$thisLink' enctype='multipart/form-data' method='POST'>";
	echo "<table class='centered'><tbody><tr><td class='alignLeft'><label for='picture'>Upload Picture<br/>(high-resolution preferred):</label></td><td><input type='file' name='picture' id='picture' /></td></tr></tbody></table>";
	echo "<p class='centered'><button>Upload</button></p>";
	echo "</form>";
} else {
	echo "<h1>Not Available</h1>";
}
