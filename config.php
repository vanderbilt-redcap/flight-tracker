<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Portal;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;

require_once(dirname(__FILE__)."/classes/Autoload.php");
require_once(dirname(__FILE__)."/FlightTrackerExternalModule.php");

define('MAX_DEGREE_SOURCES', 5);

if (isset($_GET['uploadOrder'])) {
	require_once(dirname(__FILE__)."/small_base.php");

	$html = uploadOrderToMetadata($token, $server, $_POST);
	echo $html;
	exit();
} else {
	require_once(dirname(__FILE__)."/charts/baseWeb.php");

?>
	<style>
	td { padding: 8px; }
	</style>

<div id='overlay'></div>

<?php
}
	echo "<h1>Configure ".CareerDev::getProgramName()."</h1>\n";

if (count($_POST) > 0) {
	if (isset($_GET['order'])) {
		if ($_POST['text'] && $_POST['code'] && ($_POST['type'] != "")) {
			$exampleField = getExampleField();
			$text = Sanitizer::sanitizeWithoutChangingQuotes($_POST['text']);
			$code = Sanitizer::sanitize($_POST['code']);
			$type = Sanitizer::sanitize(['type']);
			$metadata = Download::metadata($token, $server);
			if (isset($_GET['test'])) {
			    echo "In config 1, metadata has ".count($metadata)." rows<br>";
            }
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
					echo "<p class='red centered'>Could not add ".Sanitizer::sanitize($code)." to data sources!</p>\n";
				}
			} else {
				echo "<p class='red centered'>Could not add because code ('".Sanitizer::sanitize($code)."') already exists.</p>\n";
			}
		} else {
			echo "<p class='red centered'>You must specify the text, a value for its code, and a type.</p>\n";
		}
	} else {
		$lists = [];
        $optionSettings = REDCapManagement::getOptionalSettings();
		foreach ($_POST as $key => $value) {
            $key = Sanitizer::sanitizeWithoutChangingQuotes($key);
            $value = Sanitizer::sanitizeWithoutChangingQuotes($value);
            $value = REDCapManagement::changeSlantedQuotes($value);
            if (isset($optionSettings[$key])) {
                $lists[$key] = $value;
                Application::saveSetting($key, $value, $pid);
            } else if (in_array($key, ["departments", "resources"])) {
                $lists[$key] = $value;
            } else if (in_array($key, ["pid", "event_id"])) {
                $module = Application::getModule();
                if ($key == "pid") {
                    $value = $module->getProjectId();
                } else if ($key == "event_id") {
                    $value = $module->getEventId();
                }
                Application::saveSetting($key, $value, $pid);
			} else {
                Application::saveSetting($key, $value, $pid);
			}
		}
		$lists["institutions"] = implode("\n", CareerDev::getInstitutions());
		$metadata = Download::metadata($token, $server);
        if (isset($_GET['test'])) {
            echo "In config 2, metadata has ".count($metadata)." rows<br>";
        }
        $token = Application::getSetting("token", $pid);
        $feedback = DataDictionaryManagement::addLists($token, $server, $pid, $lists, CareerDev::getSetting("hasCoeus"), $metadata);
		if (is_array($feedback)) {
		    $feedback = json_encode($feedback);
        }
		echo "<p class='centered green'>Saved ".count($_POST)." settings into pid $pid ($feedback)</p>\n";
	}
}

if (!isset($metadata)) {
    $metadata = Download::metadata($token, $server);
}
if (isset($_GET['order'])) {
	echo makeOrder($token, $server, $pid, $metadata);
} else {
	echo makeSettings(CareerDev::getModule(), $pid);
}

function getFieldNames($metadata) {
	$fields = array();
	foreach ($metadata as $row) {
		$fields[] = $row['field_name'];
	}
	return $fields;
}

function getExampleField() {
	return Scholar::getExampleField();
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
		$texts[] = "$key = $value";
	}
	return $texts;
}

function makeOrder($token, $server, $pid, $metadata = []) {
	$exampleField = getExampleField();
	$delim = getUploadDelim();

	if (empty($metadata)) {
		$metadata = Download::metadata($token, $server);
        if (isset($_GET['test'])) {
            echo "In config 3, metadata has ".count($metadata)." rows<br>";
            echo "$token, $server, $pid<br>";
        }
	}
	$scholar = new Scholar($token, $server, $metadata, $pid);
	$orders = Scholar::getDefaultOrder("all");
	$choices = Scholar::getChoices($metadata);

	$allFields = REDCapManagement::getFieldsFromMetadata($metadata);

	$fieldLabels = array();
	foreach ($orders as $fieldForOrder => $order) {
		$fieldLabels[$fieldForOrder] = findFieldLabel($fieldForOrder, $metadata);
	}

	list($sources, $sourceTypes) = produceSourcesAndTypes($scholar, $metadata);

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
	$html .= Application::generateCSRFTokenHTML();
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
		if ($fieldLabel) {
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
                    $html .= "<div>";
                    $html .= "<h4>".ucfirst($type)."</h4>\n";
                    $html .= "<ul class='sortable nobullets' id='$fieldForOrder$delim$type'>\n";
                    foreach ($sourceRow as $field => $source) {
                        if ($choices[$exampleField][$source]) {
                            $sourceName = $choices[$exampleField][$source];
                        } else {
                            $sourceName = $source;
                        }
                        $sourceTypeForField = $sourceTypes[$fieldForOrder][$type][$field];
                        $html .= makeLI($field, $sourceTypeForField, $sourceName, $field);
                    }
                    $html .= "</ul>\n";
                    $html .= "</div>";
                    $html .= makeNewSourceHTML($allFields, $choices[$exampleField], 1);
                } else {
                    $sourceTypeForField = $sourceTypes[$fieldForOrder][$field];
                    if ($choices[$exampleField][$source]) {
                        $sourceName = $choices[$exampleField][$source];
                    } else {
                        $sourceName = $source;
                    }
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

function makeSettings($module, $pid) {
	$ary = [];
	
	$ary["Length of K Grants"] = [];
	$ary["Length of K Grants"][] = makeSetting("internal_k_length", "number", "Internal K Length in Years", "3");
	$ary["Length of K Grants"][] = makeSetting("k12_kl2_length", "number", "K12/KL2 Length in Years", "3");
	$ary["Length of K Grants"][] = makeSetting("individual_k_length", "number", "Length of NIH K Grants in Years", "5");

	$ary["Installation Variables"] = [];
	$ary["Installation Variables"][] = makeSetting("institution", "text", "Full Name of Institution");
	$ary["Installation Variables"][] = makeSetting("short_institution", "text", "Short Name of Institution");
    $ary["Installation Variables"][] = makeSetting("other_institutions", "text", "Other Institutions (if any); comma-separated");
    $ary["Installation Variables"][] = makeSetting("display_institutions", "text", "'Home' Institutions that Your Scholars Belong To; comma-separated");
    $ary["Installation Variables"][] = makeSetting("omit_va", "yesno", "Omit searching for pubs/grants with 'Veterans Health Administration'? (Default: searches for VHA with all scholars for matches.)", 0);
    $ary["Installation Variables"][] = makeSetting("token", "text", "API Token");
    $ary["Installation Variables"][] = makeSetting("supertoken", "text", "REDCap Supertoken (optional, from REDCap Administrator, for turning on Cohort Portals)");
	$ary["Installation Variables"][] = makeSetting("event_id", "text", "Event ID (read-only)", "", [], TRUE);
	$ary["Installation Variables"][] = makeSetting("pid", "text", "Project ID (read-only)", "", [], TRUE);
	$ary["Installation Variables"][] = makeSetting("server", "text", "Server API Address");
	$ary["Installation Variables"][] = makeSetting("tokenName", "text", "Project Name");
	$ary["Installation Variables"][] = makeSetting("timezone", "text", "Timezone");
    $ary["Installation Variables"][] = makeSetting("grant_class", "radio", "Grant Class", "", CareerDev::getGrantClasses());
	$ary["Installation Variables"][] = makeSetting("grant_number", "text", "Grant Number");
    $ary["Installation Variables"][] = makeSetting("server_class", "radio", "Server Class", "prod", CareerDev::getServerClasses());
    $ary["Installation Variables"][] = makeSetting("send_error_logs", "yesno", "Report Fatal Errors to Development Team?");
    $ary["Installation Variables"][] = makeSetting("auto_recalculate", "yesno", "Automatically Re-summarize After Data Saves? (No waits until overnight.)", 0);
    $ary["Installation Variables"][] = makeSetting("security_test_mode", "yesno", "Place in Security-Test mode (disabling unauthorized APIs)?", 0);

    $ary["Administrative Setup"][] = makeSetting("departments", "textarea", "Department Names (One per line.)");
	$ary["Administrative Setup"][] = makeSetting("resources", "short_textarea", "Resources (One per line.)");
    $optionSettings = REDCapManagement::getOptionalSettings();
    $fileMetadata = DataDictionaryManagement::getFileMetadata();
    foreach(REDCapManagement::getOptionalFields() as $field) {
        $setting = REDCapManagement::turnOptionalFieldIntoSetting($field);
        $label = $optionSettings[$setting] ?? $field;
        $row = DataDictionaryManagement::getRowForFieldFromMetadata($field, $fileMetadata);
        $form = $row['form_name'] ?? "";
        $note = (isset($row['field_note']) && $row['field_note']) ? "<div class='smaller'>".$row['field_note']."</div>" : "";
        $ary["Administrative Setup"][] = makeSetting($setting, "short_textarea", "<div>Options for <strong>$label</strong> on the <strong>".ucfirst($form)."</strong> form.</div>$note<div class='smaller'>(One per line. Optional. When filled in, it will create an extra field in your project once you update your Data Dictionary on Flight Tracker's Home page.)</div>");
    }
    $ary["Administrative Setup"][] = makeCheckboxes("shared_forms", FlightTrackerExternalModule::getConfigurableForms(), "In Flight Tracker's data-sharing on the same server, in addition to surveys, what data should be shared among the following forms?");


	$ary["Emails"] = [];
//	array_push($ary["Emails"], makeHelperText("An initial email can automatically be sent out during the first month after the new record is added to the database. If you desire to use this feature, please complete the following fields."));
//	array_push($ary["Emails"], makeSetting("init_from", "text", "Initial Email From Address"));
//	array_push($ary["Emails"], makeSetting("init_subject", "text", "Initial Email Subject"));
//	array_push($ary["Emails"], makeSetting("init_message", "textarea", "Initial Email Message"));
    $ary["Emails"][] = makeSetting("admin_email", "text", "Administrative Email(s) for Flight Tracker Project; comma-separated");
    $ary["Emails"][] = makeSetting("default_from", "text", "Default From Address");
    $ary["Emails"][] = makeSetting("warning_minutes", "number", "Number of Minutes Before An Email to Send a Warning Email", Application::getWarningEmailMinutes($pid));
    if (Portal::isLive()) {
        $ary["Emails"][] = makeSystemSetting("bulletin_board_monitor", "text", "Bulletin Board Monitor Email(s) for entire server; comma-separated");
    }

    $ary["Publications"] = [];
    $ary["Publications"][] = makeSetting("pubmed_api_key", "text", "PubMed API Key for faster queries (".Links::makeLink("https://www.ncbi.nlm.nih.gov/books/NBK25497/#:~:text=API%20Keys", "Acquire an API Key for PubMed").")");
    $ary["Publications"][] = makeSetting("wos_userid", "text", Links::makeLink("https://www.webofknowledge.com/", "Web of Science (for H Index)") . " User ID");
    $ary["Publications"][] = makeSetting("wos_password", "text", Links::makeLink("https://www.webofknowledge.com/", "Web of Science (for H Index)") . " Password");
    $ary["Publications"][] = makeSetting("scopus_api_key", "text", Links::makeLink("https://www.scopus.com/", "Scopus") . " API Key (for H Index)");

    $ary["Additional Institutional Resources"] = [];
    $ary["Additional Institutional Resources"][] = makeSetting("mentee_agreement_link", "text", "Additional Institutional Resources for scholars to use. This will appear on the Mentee-Mentor Agreements and the Scholar Portal.");

    $ary["Proxy Server (Only if Applicable)"] = [];
    $ary["Proxy Server (Only if Applicable)"][] = makeHelperText("If your REDCap server has a proxy server, please fill out the following information. (If you don't know about this, you probably don't have one, so no worries then.)");
    $ary["Proxy Server (Only if Applicable)"][] = makeSetting("proxy-ip", "text", "Proxy IP Address");
    $ary["Proxy Server (Only if Applicable)"][] = makeSetting("proxy-port", "text", "Proxy Port Number");
    $ary["Proxy Server (Only if Applicable)"][] = makeSetting("proxy-user", "text", "Proxy Username");
    $ary["Proxy Server (Only if Applicable)"][] = makeSetting("proxy-pass", "text", "Proxy Password");

    $ary["REDCap Configuration"] = [];
    $ary["REDCap Configuration"][] = makeSetting("safe_servers", "text", "Comma-separated list of domain names (e.g., redcap.vanderbilt.edu) of other <strong>external</strong> servers that (e.g., a separate REDCap Survey Server) that can access Flight Tracker");

    $html = "";
	if ($module) {
		$html .= "<form method='POST' action='".Application::link("config.php")."'>\n";
		$html .= Application::generateCSRFTokenHTML();
		foreach ($ary as $header => $htmlAry) {
            $id = REDCapManagement::makeHTMLId($header);
			$html .= "<a id='$id'></a><h2>$header</h2>";
			$html .= "<table class='centered' style='max-width: 600px;'>";
			$html .= implode("\n", $htmlAry);
			$html .= "<tr><td colspan='2' class='centered'><input type='submit' value='Save Settings'></td></tr>";
			$html .= "</table>";
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

function makeCheckboxes($var, $fieldChoices, $label, $defaultChecked = []) {
    if (empty($fieldChoices)) {
        return "";
    }
    $sharedForms = CareerDev::getSetting($var);
    if (!$sharedForms) {
        $sharedForms = $defaultChecked;
    }
    $html = "";
    $html .= "<tr><td style='text-align: right;'>$label</td>\n";
    $html .= "<td style='text-align: left;'>";
    $first = TRUE;
    foreach ($fieldChoices as $idx => $fieldLabel) {
        if (in_array($idx, $sharedForms)) {
            $selected = " checked";
        } else {
            $selected = "";
        }
        if (!$first) {
            for ($i = 0 ; $i < 5; $i++) {
                $html .= "&nbsp;";
            }
        } else {
            $first = FALSE;
        }
        $html .= "<span class='sameLine'><input type='checkbox' name='$var"."[]' id='$var"."___$idx' value='$idx'$selected><label for='$var"."___$idx'> $fieldLabel</label></span>";
    }
    $html .= "</td></tr>\n";
    return $html;
}

function makeSystemSetting($var, $type, $label, $default = "", $fieldChoices = [], $readonly = FALSE) {
    $value = Application::getSystemSetting($var);
    if ($value === "") {
        $value = $default;
    }
    return constructSetting($value, $var, $type, $label, $default, $fieldChoices, $readonly);
}

function makeSetting($var, $type, $label, $default = "", $fieldChoices = [], $readonly = FALSE)
{
    $value = Application::getSetting($var);
    if ($value === "") {
        $value = $default;
    }
    return constructSetting($value, $var, $type, $label, $default, $fieldChoices, $readonly);
}

function constructSetting($value, $var, $type, $label, $default, $fieldChoices, $readonly) {
    if (in_array($var, ["event_id", "pid", "token"])) {
        $module = Application::getModule();
        $realValue = "";
        if ($var == "event_id") {
            $realValue = $module->getEventId();
        } else if ($var == "pid") {
            $realValue = $module->getProjectId();
        } else if ($var == "token") {
            # check for copied project
            $myPid = $module->getProjectId();
            $myUsername = Application::getUsername();
            $realValue = REDCapManagement::getToken($myPid, $myUsername);
            if ($realValue === NULL) {
                $realValue = "";
            }
            if (!$realValue) {
                $allTokens = REDCapManagement::getToken($myPid);
                if (in_array($value, $allTokens)) {
                    $realValue = $value;
                }
            }
        }
        if ($realValue != $value) {
            # recently copied project
            Application::saveSetting($var, $realValue);
            $value = $realValue;
        }
    }
	$html = "";
	$spacing = "";
    for ($i = 0 ; $i < 5; $i++) {
        $spacing .= "&nbsp;";
    }
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
		$html .= "<input type='$type' name='$var' value=\"$value\"";
		if ($readonly) {
		    $html .= " readonly";
        }
		$html .= ">\n";
		$html .= "</td>";
		$html .= "</tr>";
	} else if (($type == "radio") || ($type == "yesno")) {
		if ($type == "yesno") {
			$fieldChoices = ["0" => "No", "1" => "Yes"];
		}
		$html .= "<tr>";
		$html .= "<td style='text-align: right;'>";
		$html .= $label;
		$html .= "</td><td style='text-align: left;'>";
		$options = [];
		foreach ($fieldChoices as $idx => $fieldLabel) {
			if ($idx == $value) {
				$selected = " checked";
			} else {
				$selected = "";
			}
			$html .= "<div><input type='radio' name='$var' id='$var"."___$idx' value='$idx'$selected><label for='$var"."___$idx'> $fieldLabel</label></div>";
		}
		$html .= implode($spacing, $options);
		$html .= "</td>";
		$html .= "</tr>";
	} else if (in_array($type, ["textarea", "short_textarea"])) {
		$html .= "<tr>";
		$html .= "<td colspan='2'>";
        if (preg_match("/^<div/", $label)) {
            $html .= $label;
        } else {
            $html .= "<div>$label</div>";
        }
        if ($type == "short_textarea") {
            $html .= "<textarea class='config' style='height: 250px;' name='$var'>$value</textarea>";
        } else {
            $html .= "<textarea class='config' name='$var'>$value</textarea>";
        }
		$html .= "</td>";
		$html .= "</tr>";
	}
	return $html;
}
