<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/CareerDev.php");
require_once(dirname(__FILE__)."/classes/Download.php");
require_once(dirname(__FILE__)."/classes/Upload.php");
require_once(dirname(__FILE__)."/classes/Scholar.php");
require_once(dirname(__FILE__)."/classes/REDCapManagement.php");

define('MAX_DEGREE_SOURCES', 5);

if (isset($_GET['uploadOrder'])) {
	require_once(dirname(__FILE__)."/small_base.php");

	\Vanderbilt\FlightTrackerExternalModule\uploadOrderToMetadata($token, $server, $_POST);
	exit();
} else {
	require_once(dirname(__FILE__)."/charts/baseWeb.php");

?>
	<style>
	td { padding: 8px; }
	</style>
<?php
}
	echo "<h1>Configure ".CareerDev::getProgramName()."</h1>\n";

if (count($_POST) > 0) {
	if (isset($_GET['order'])) {
		if ($_POST['text'] && $_POST['code'] && ($_POST['type'] != "")) {
			$exampleField = getExampleField();
			$text = $_POST['text'];
			$code = $_POST['code'];
			$type = $_POST['type'];
			$metadata = Download::metadata($token, $server);
			$choices = Scholar::getChoices($metadata);

			if (!isset($choices[$exampleField][$code])) {
				$i = 0;
				$rowsAffected = 0;
				foreach ($metadata as $row) {
					if (preg_match("/_source$/", $row['field_name'])) {
						if ($row['select_choices_or_calculations']) {
							$row['select_choices_or_calculations'] .= " | ";
						}
						$row['select_choices_or_calculations'] .= "$code, $text";
						$metadata[$i] = $row;
						$rowsAffected++;
					}
					$i++;
				}
				$res = Scholar::addSourceType(CareerDev::getModule(), $code, $type, $pid);
				if ($res) {
					$feedback = Upload::metadata($metadata, $token, $server);
					echo "<p class='green centered'>$rowsAffected fields affected.</p>\n";
				} else {
					echo "<p class='red centered'>Could not add $code to data sources!</p>\n";
				}
			} else {
				echo "<p class='red centered'>Could not add because code ('$code') already exists.</p>\n";
			}
		} else {
			echo "<p class='red centered'>You must specify the text, a value for its code, and a type.</p>\n";
		}
	} else {
		$lists = array();
		foreach ($_POST as $key => $value) {
			if (($key == "departments") || ($key == "resources")) {
				$lists[$key] = $value;
			} else {
				CareerDev::setSetting($key, $value);
			}
		}
		$lists["institutions"] = implode("\n", CareerDev::getInstitutions());
		$metadata = Download::metadata($token, $server);
		\Vanderbilt\FlightTrackerExternalModule\addLists($token, $server, $lists, CareerDev::getSetting("hasCoeus"), $metadata);
		echo "<p class='centered green'>Saved ".json_encode($_POST)." settings</p>\n";
	}
}

if (isset($_GET['order'])) {
	echo makeOrder($metadata);
} else {
	echo makeSettings(CareerDev::getModule());
}

function getFieldNames($metadata) {
	$fields = array();
	foreach ($metadata as $row) {
		array_push($fields, $row['field_name']);
	}
	return $fields;
}

function getExampleField() {
	return "identifier_left_date_source";
}

function getExistingChoices($existingChoices, $scholar, $allFields) {
	$choices = array();
	$orders = Scholar::getDefaultOrder("all");
	foreach ($existingChoices as $key => $text) {
		foreach ($orders as $fieldForOrder => $order) {
			if (!isset($choices[$key])) {
				$newOrder = $scholar->getOrder($order, $fieldForOrder);
				foreach ($newOrder as $field => $source) {
					if (($source == $key) && in_array($field, $allFields) && !isset($choices[$key]))  {
						$choices[$key] = $text;
					}
				}
			}
		}
	}
	return $choices;
}

function getExistingChoicesTexts($existingChoices, $scholar, $allFields) {
	$choices = getExistingChoices($existingChoices, $scholar, $allFields);
	$texts = array();
	foreach ($choices as $key => $value) {
		array_push($texts, "$key = $value");
	}
	return $texts;
}

function makeOrder($metadata = array()) {
	global $token, $server, $pid;
	$exampleField = getExampleField();
	$delim = \Vanderbilt\FlightTrackerExternalModule\getUploadDelim();

	if (empty($metadata)) {
		$metadata = Download::metadata($token, $server, $pid);
	}
	$scholar = new Scholar($token, $server, $pid, $metadata);
	$orders = Scholar::getDefaultOrder("all");
	$choices = Scholar::getChoices($metadata);

	$allFields = REDCapManagement::getFieldsFromMetadata($metadata);

	$fieldLabels = array();
	foreach ($orders as $fieldForOrder => $order) {
		$fieldLabels[$fieldForOrder] = findFieldLabel($fieldForOrder, $metadata);
	}

	list($sources, $sourceTypes) = \Vanderbilt\FlightTrackerExternalModule\produceSourcesAndTypes($scholar, $metadata);
	$existingChoicesTexts = getExistingChoicesTexts($choices[$exampleField], $scholar, $allFields);

	$button = "<p class='centered'><button onclick='commitOrder(); return false;'>Commit All Changes</button></p>\n";
	$html = "";
	$html .= "<script src='".CareerDev::link("/js/config.js")."'></script>\n";
	$html .= "<script>\n";
	$html .= "var maxDegrees = ".MAX_DEGREE_SOURCES.";\n";
	$html .= "$(document).ready(function() { $('.sortable').sortable({ revert: true }); $('ul.sortable, li').disableSelection(); });\n";
	$html .= "</script>\n";
	$html .= "<h2>Add New Data Source</h2>\n";
	$html .= "<table style='width: 800px;' class='centered'>\n";
	$html .= "<tr><td colspan='2'>\n";
	$html .= "<p class='centered'>To add a new data source, you must create a code for it (no spaces [like <code>initial_survey</code>], then name it, and then select its type (computer-generated, self-reported, or manually entered). It will appear in existing data sources only when it is assigned to a field in the Source-of-Truth configuration below.</p>\n";
	$html .= "</td></tr>\n";
	$html .= "<tr>\n";
	$html .= "<td style='vertical-align: top; width: 50%;'>\n";
	$html .= "<h3>Default Data Sources</h3>\n";
	$html .= "<p class='centered'>These are included by Flight Tracker by default. Custom data sources are shown in the dropdowns for a <b>New Source</b> below.</p>\n";
	$html .= implode("<br>\n", $existingChoicesTexts);
	$html .= "</td>\n";
	$html .= "<td style='vertical-align: top; width: 50%;'><form method='POST' action='".CareerDev::link("config.php")."&order'>\n";
	$html .= "<h3>Add a Custom Data Source</h3>\n";
	$html .= "<p class='centered'>Code: <input type='text' name='code' value=''><br>\n";
	$html .= "Name: <input type='text' name='text' value=''><br>\n";
	$html .= "Type: <select name='type'>\n";
	$html .= "<option value=''>---SELECT---</option>\n";
	foreach ($choices[$exampleField."type"] as $key => $text) {
		$html .= "<option value='$key'>$text</option>\n";
	}
	$html .= "</select><br>\n";
	$html .= "<button>Add Data Source</button></p>\n";
	$html .= "</form></td>\n";
	$html .= "</tr>\n";
	$html .= "</table>\n";
	$html .= "<hr>\n";
	$html .= "<h2>Configure Source of Truth</h2>\n"; 
	$html .= "<p class='centered' style='width: 800px; margin: 0 auto;'>The \"Source of Truth\" defines which field provides the chosen value. These values are re-calculated every night. They are defined in an order, with the top being given priority. Starting with the top value, if a data value for a field exists, the field is chosen; if a data value for the field does not exist, we move down one rung in the order until no more rungs exist. You may sort the order and add new fields here. New fields must be added for new data sources to be hooked up.</p>\n";
	$html .= $button;
	foreach ($sources as $fieldForOrder => $sourceList) {
		$fieldLabel = $fieldLabels[$fieldForOrder];
		$html .= "<div style='margin: 14px auto; max-width: 600px;'>\n";
		$html .= "<h3>$fieldLabel</h3>\n";
		if ($fieldForOrder == "summary_race_ethnicity") {
			$numEntries = 2;
		} else {
			$numEntries = 1;
		}
		if ($numEntries == 1) {
			$html .= "<ul class='sortable nobullets' id='$fieldForOrder'>\n";
		}
		foreach ($sourceList as $field => $source) {
			if (is_array($source)) {
				$sourceRow = $source;
				$type = $field;
				$html .= "<h4>".ucfirst($type)."</h4>\n";
				$html .= "<ul class='sortable nobullets' id='$fieldForOrder$delim$type'>\n";
				foreach ($sourceRow as $field => $source) {
					$sourceName = $choices[$exampleField][$source];
					$sourceTypeForField = $sourceType[$fieldForOrder][$type][$field];
					$html .= makeLI($field, $sourceTypeForField, $sourceName, $field);
				}
				$html .= "</ul>\n";
				$html .= makeNewSourceHTML($allFields, $choices[$exampleField], 1);
			} else {
				$sourceTypeForField = $sourceType[$fieldForOrder][$field];
				$sourceName = $choices[$exampleField][$source];
				$fields = explode($delim, $field);
				if (count($fields) > 1) {
					# summary_degrees
					$fieldText = implode(", ", $fields);
					$fieldID = implode($delim, $fields);
				} else {
					$fieldText = $fields[0];
					$fieldID = $fields[0];
				}

				$html .= makeLI($fieldID, $sourceTypeForField, $sourceName, $fieldText);
			}
		}
		if ($numEntries == 1) {
			$html .= "</ul>\n";
			if ($fieldForOrder == "summary_degrees") {
				$numAdditionalSources = MAX_DEGREE_SOURCES;
			} else {
				$numAdditionalSources = 1;
			}
			$html .= makeNewSourceHTML($allFields, $choices[$exampleField], $numAdditionalSources);
		}
		$html .= "</div>\n";
	}
	$html .= $button;

	return $html;
}

function makeLI($fieldID, $sourceTypeForField, $sourceName, $fieldText) {
	# the following line is also replicated in config.js; please change it in both places
	return "<li class='ui-state-default centered nobullets' id='$fieldID' type='$sourceTypeForField'>$sourceName [$fieldText]</li>\n";
}

function makeNewSourceHTML($allFields, $choices, $numSources) {
	$html = "";
	$html .= "<p class='centered'>";
	$html .= "New Source: <select onchange='checkButtonVisibility(this);' class='newSortableSource'>\n";
	$html .= "<option value=''>---SELECT---</option>\n";
	foreach ($choices as $code => $text) {
		$html .= "<option value='$code'>$text</option>\n";
	}
	$html .= "</select><br>\n";
	for ($i = 1; $i <= $numSources; $i++) {
		if ($numSources == 1) {
			$index = "";
		} else {
			$index = "index='$i'";
			$html .= "<br>\n";
		}
		$optional = "";
		if ($i > 1) {
			$optional = " (optional)";
		}
		$html .= "New Field$optional: <select onchange='checkButtonVisibility(this);' $index class='newSortableField combobox'>\n";
		$html .= "<option value=''>---SELECT---</option>\n";
		foreach ($allFields as $field) {
			$html .= "<option value='$field'>$field</option>\n";
		}
		$html .= "</select><br>\n";
	}
	$html .= "<button style='display: none;' onclick='addCustomField(this); return false;'>Add</button></p>\n";
	return $html;
} 


function findFieldLabel($fieldName, $metadata) {
	foreach ($metadata as $row) {
		if ($row['field_name'] == $fieldName) {
			return $row['field_label'];
		}
	}
	return "";
}

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
	array_push($ary["Installation Variables"], makeSetting("admin_email", "text", "Administrative Email(s) for Flight Tracker Project; comma-separated"));
	array_push($ary["Installation Variables"], makeSetting("tokenName", "text", "Project Name"));
	array_push($ary["Installation Variables"], makeSetting("timezone", "text", "Timezone"));
	array_push($ary["Installation Variables"], makeSetting("cities", "text", "City or Cities"));
	array_push($ary["Installation Variables"], makeSetting("grant_class", "radio", "Grant Class", "", CareerDev::getGrantClasses()));
	array_push($ary["Installation Variables"], makeSetting("grant_number", "text", "Grant Number"));
	array_push($ary["Installation Variables"], makeSetting("departments", "textarea", "Department Names"));
	array_push($ary["Installation Variables"], makeSetting("resources", "textarea", "Resources"));
	array_push($ary["Installation Variables"], makeSetting("send_error_logs", "yesno", "Report Fatal Errors to Development Team?"));

	$ary["Automated Emails"] = array();
	array_push($ary["Automated Emails"], makeHelperText("An initial email can automatically be sent out during the first month after the new record is added to the database. If you desire to use this feature, please complete the following fields."));
	array_push($ary["Automated Emails"], makeSetting("init_from", "text", "Initial Email From Address"));
	array_push($ary["Automated Emails"], makeSetting("init_subject", "text", "Initial Email Subject"));
	array_push($ary["Automated Emails"], makeSetting("init_message", "textarea", "Initial Email Message"));

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

function makeHelperText($str) {
	return "<tr><td colspan='2' class='centered'>".$str."</td></tr>";
}

function makeSetting($var, $type, $label, $default = "", $fieldChoices = array()) {
	$value = CareerDev::getSetting($var);
	$html = "";
	$spacing = "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
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
		$html .= "</td><td style='text-align: left;'>";
		$html .= "<input type='$type' name='$var' value='$value'>\n";
		$html .= "</td>";
		$html .= "</tr>";
	} else if (($type == "radio") || ($type == "yesno")) {
		if ($type == "yesno") {
			$fieldChoices = array("0" => "No", "1" => "Yes");
		}
		$html .= "<tr>";
		$html .= "<td style='text-align: right;'>";
		$html .= $label;
		$html .= "</td><td style='text-align: left;'>";
		$options = array();
		foreach ($fieldChoices as $idx => $fieldLabel) {
			if ($idx == $value) {
				$selected = " checked";
			} else {
				$selected = "";
			}
			$html .= "<input type='radio' name='$var' id='$var"."___$idx' value='$idx'$selected><label for='$var"."___$idx'> $fieldLabel</label>\n";
		}
		$html .= implode($spacing, $options);
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
