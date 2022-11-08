<?php

use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

if (count($_FILES) > 0 && isset($_FILES['logo'])) {
	$filename = $_FILES['logo']['tmp_name'] ?? "";
    if (!$filename || !is_string($filename)) {
        echo "<p>Your file was not properly uploaded.</p>\n";
        exit;
    }
	$check = getimagesize($filename);
	if ($check !== FALSE) {
		$img = file_get_contents($filename);
		$base64 = base64_encode($img);
		$mime = mime_content_type($filename);

		if (preg_match("/image/", $mime)) {
			$header = "data:$mime;charset=utf-8;base64, ";
			\Vanderbilt\FlightTrackerExternalModule\saveBrandLogo($header.$base64);
			echo makePrompt("<p class='green centered padded'>".\Vanderbilt\FlightTrackerExternalModule\pretty(strlen($base64))." bytes uploaded</p>");
		} else {
			echo "<p>Your file was of type $mime. It needs to be an image!</p>\n";
			exit;
		}
	} else {
		echo "<p>Your file was not properly uploaded. It needs to be an image!</p>\n";
		exit;
	}
} else {
	if (isset($_GET['removeBrand'])) {
		\Vanderbilt\FlightTrackerExternalModule\removeBrandLogo();
	}
	echo makePrompt();
}

function makePrompt($mssg = "") {
	require_once(dirname(__FILE__)."/../charts/baseWeb.php");
	$html = "";

	$html .= "<h1>Brand Your CareerDev Instance</h1>\n";

	$html .= $mssg;

	$base64 = \Vanderbilt\FlightTrackerExternalModule\getBrandLogo();
	if ($base64) {
		$html .= "<p class='centered'>Your current logo:<br><img src='$base64' class='brandLogo'></p>\n";
	} else {
		$html .= "<p class='centered'>No logo currently saved.</p>\n";
	}

	$thisLink = Application::link("this");
	$html .= "<div class='centered' style='max-width: 800px;'>\n";
	$html .= "<p>You can brand your instance of Flight Tracker for Scholars with your logo. It will be displayed 40-pixels tall. Just upload a file here, and it will appear in the upper-left corner in your project.</p>\n";
	$html .= "<form action='$thisLink' method='POST' enctype='multipart/form-data'>\n";
	$html .= Application::generateCSRFTokenHTML();
	$html .= "<p class='centered'><input type='file' name='logo'></p>\n";
	$html .= "<p class='centered'><button>Submit Logo</button></p>\n";
	$html .= "</form>\n";
	if ($base64) {
		$html .= "<p class='centered'><a href='$thisLink&removeBrand'>Remove Brand Image</a></p>\n";
	}
	$html .= "</div>\n";

	return $html;
}
