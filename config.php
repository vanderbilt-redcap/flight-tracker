<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Download;

require_once(dirname(__FILE__)."/CareerDev.php");
require_once(dirname(__FILE__)."/classes/Download.php");
require_once(dirname(__FILE__)."/charts/baseWeb.php");

?>
<style>
td { padding: 8px; }
</style>

<?php
	echo "<h1>Configure ".CareerDev::getProgramName()."</h1>\n";

if (count($_POST) > 0) {
	foreach ($_POST as $key => $value) {
		$lists = array();
		if (($key == "departments") || ($key == "resources")) {
			$lists[$key] = $value;
		} else {
			CareerDev::setSetting($key, $value);
		}
	}
	$lists["institutions"] = implode("\n", CareerDev::getInstitutions());
	$metadata = Download::metadata($token, $server);
	\Vanderbilt\FlightTrackerExternalModule\addLists($token, $server, $lists, CareerDev::getSetting("hasCoeus"), $metadata);
	echo "<p class='centered green'>Saved ".count($_POST)." settings</p>\n";
}

echo makeSettings(CareerDev::getModule());






function makeSettings($module) {
	$ary = array();
	
	$ary["Length of K Grants"] = array();
	array_push($ary["Length of K Grants"], makeSetting("internal_k_length", "number", "Internal K Length in Years", "3"));
	array_push($ary["Length of K Grants"], makeSetting("k12_kl2_length", "number", "K12/KL2 Length in Years", "3"));
	array_push($ary["Length of K Grants"], makeSetting("individual_k_length", "number", "Length of NIH K Grants in Years", "5"));

	$ary["Installation Variables"] = array();
	array_push($ary["Installation Variables"], makeSetting("institution", "text", "Full Name of Institution"));
	array_push($ary["Installation Variables"], makeSetting("short_institution", "text", "Short Name of Institution"));
	array_push($ary["Installation Variables"], makeSetting("other_institutions", "text", "Other Institutions (if any); comma-separated"));
	array_push($ary["Installation Variables"], makeSetting("token", "text", "API Token"));
	array_push($ary["Installation Variables"], makeSetting("event_id", "text", "Event ID"));
	array_push($ary["Installation Variables"], makeSetting("pid", "text", "Project ID"));
	array_push($ary["Installation Variables"], makeSetting("server", "text", "Server API Address"));
	array_push($ary["Installation Variables"], makeSetting("admin_email", "text", "Administrative Email(s); comma-separated"));
	array_push($ary["Installation Variables"], makeSetting("tokenName", "text", "Project Name"));
	array_push($ary["Installation Variables"], makeSetting("timezone", "text", "Timezone"));
	array_push($ary["Installation Variables"], makeSetting("cities", "text", "City or Cities"));
	array_push($ary["Installation Variables"], makeSetting("departments", "textarea", "Department Names"));
	array_push($ary["Installation Variables"], makeSetting("resources", "textarea", "Resources"));

	$html = "";
	if ($module) {
		$html .= "<form method='POST' action='".$module->getUrl("config.php")."'>\n";
		foreach ($ary as $header => $htmlAry) {
			$html .= "<h2>$header</h2>\n";
			$html .= "<table class='centered'>\n";
			$html .= implode("\n", $htmlAry);
			$html .= "<tr><td colspan='2' class='centered'><input type='submit' value='Save Settings'></td></tr>";
			$html .= "</table>\n";
		}
		$html .= "</form>\n";
	} else {
		throw new \Exception("Could not find module!");
	}
	return $html;
}

function makeSetting($var, $type, $label, $default = "") {
	$value = CareerDev::getSetting($var);
	$html = "";
	if (($type == "text") || ($type == "number")) {
		$html .= "<tr>";
		$html .= "<td style='text-align: right;'>";
		$html .= $label;
		if ($default) {
			$html .= " (default: ".$default.")";
			if (!$value) {
				$value = $default;
			}
		}
		$html .= "</td><td>";
		$html .= "<input type='$type' name='$var' value='$value'>\n";
		$html .= "</td>";
		$html .= "</tr>";
	} else if ($type == "textarea") {
		$html .= "<tr>";
		$html .= "<td colspan='2'>";
		$html .= $label;
		$html .= "<br>";
		$html .= "<textarea class='config' name='$var'>$value</textarea>";
		$html .= "</td>";
		$html .= "</tr>";
	}
	return $html;
}
