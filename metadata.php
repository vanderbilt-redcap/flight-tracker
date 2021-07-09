<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

$files = [
    dirname(__FILE__)."/metadata.json",
];
if (CareerDev::isVanderbilt()) {
    $files[] = dirname(__FILE__)."/metadata.vanderbilt.json";
}
$lastCheckField = "prior_metadata_ts";
$deletionRegEx = "/___delete$/";

if ($_POST['process'] == "check") {
	$ts = $_POST['timestamp'];
	$lastCheckTs = CareerDev::getSetting($lastCheckField);
	if (!$lastCheckTs) {
		$lastCheckTs = 0;
	}

	# check a maximum of once every 30 seconds 
	if ($ts > $lastCheckTs + 30) {
        $missing = array();
        $additions = array();
        $changed = array();
        $metadata = array();
        $metadata['REDCap'] = Download::metadata($token, $server);
        $genderFieldsToHandleForVanderbilt = ["summary_gender", "summary_gender_source", "check_gender"];
        foreach ($files as $filename) {
            $fp = fopen($filename, "r");
            $json = "";
            while ($line = fgets($fp)) {
                $json .= $line;
            }
            fclose($fp);

            $metadata['file'] = json_decode($json, TRUE);

            $choices = array();
            foreach ($metadata as $type => $md) {
                $choices[$type] = REDCapManagement::getChoices($md);
            }

            if (!CareerDev::isVanderbilt()) {
                insertDeletesForPrefix($metadata['file'], "/^coeus_/");
            }

            $fieldList = array();
            $indexedMetadata = array();
            foreach ($metadata as $type => $metadataRows) {
                $fieldList[$type] = array();
                $indexedMetadata[$type] = array();
                foreach ($metadataRows as $row) {
                    $fieldList[$type][$row['field_name']] = $row['select_choices_or_calculations'];
                    $indexedMetadata[$type][$row['field_name']] = $row;
                }
            }

            $metadataFields = REDCapManagement::getMetadataFieldsToScreen();
            $specialFields = REDCapManagement::getSpecialFields("all");
            foreach ($fieldList["file"] as $field => $choiceStr) {
                $isSpecialGenderField = !CareerDev::isVanderbilt() || !in_array($field, $genderFieldsToHandleForVanderbilt);
                $isFieldOfSources = preg_match("/_source$/", $field) && isset($choices["REDCap"][$field]["scholars"]);
                if (!in_array($field, $specialFields)) {
                    if (!isset($fieldList["REDCap"][$field])) {
                        array_push($missing, $field);
                        if (!preg_match($deletionRegEx, $field)) {
                            array_push($additions, $field);
                        }
                    } else if ($isFieldOfSources) {
                        $sourceChoices = CareerDev::getRelevantChoices();
                        if (!REDCapManagement::arraysEqual($choices["REDCap"][$field], $sourceChoices)) {
                            array_push($missing, $field);
                            array_push($changed, $field);
                        }
                    } else if (!empty($choices["file"][$field]) && !empty($choices["REDCap"][$field]) && !REDCapManagement::arraysEqual($choices["file"][$field], $choices["REDCap"][$field])) {
                        if ($isSpecialGenderField) {
                            array_push($missing, $field);
                            array_push($changed, $field);
                        }
                    } else {
                        foreach ($metadataFields as $metadataField) {
                            if (REDCapManagement::hasMetadataChanged($indexedMetadata["REDCap"][$field][$metadataField], $indexedMetadata["file"][$field][$metadataField], $metadataField)) {
                                if ($isSpecialGenderField) {
                                    array_push($missing, $field);
                                    array_push($changed, $field);
                                }
                                break; // metadataFields loop
                            }
                        }
                    }
                }
            }
        }
        CareerDev::setSetting($lastCheckField, time(), $pid);
        if (count($additions) + count($changed) > 0) {
            echo "<script>var missing = ".json_encode($missing).";</script>\n";
            echo "<div id='metadataWarning' class='install-metadata-box install-metadata-box-danger'>
                <i class='fa fa-exclamation-circle' aria-hidden='true'></i> An upgrade in your Data Dictionary exists. <a href='javascript:;' onclick='installMetadata(missing);'>Click here to install.</a>
                <ul><li>The following fields will be added: ".(empty($additions) ? "<i>None</i>" : "<strong>".implode(", ", $additions)."</strong>")."</li>
                <li>The following fields will be changed: ".(empty($changed) ? "<i>None</i>" : "<strong>".implode(", ", $changed)."</strong>")."</li></ul>
            </div>";
        }
	}
} else if ($_POST['process'] == "install") {
	$postedFields = $_POST['fields'];
	foreach ($files as $filename) {
        $fp = fopen($filename, "r");
        $json = "";
        while ($line = fgets($fp)) {
            $json .= $line;
        }
        fclose($fp);

        $metadata = [];
        $metadata['file'] = json_decode($json, TRUE);
        $metadata['REDCap'] = Download::metadata($token, $server);
        if ($metadata['file']) {
            if ($grantClass == "K") {
                $mentorLabel = "Primary mentor during your K/K12 training period";
            } else if ($grantClass == "T") {
                $mentorLabel = "Primary mentor during your pre-doc/post-doc training period";
            } else {
                $mentorLabel = "Primary mentor (current)";
            }
            $fieldLabels = [];
            foreach ($metadata as $type => $md) {
                $fieldLabels[$type] = REDCapManagement::getLabels($md);
            }
            $fieldsForMentorLabel = ["check_primary_mentor", "followup_primary_mentor", ];
            foreach ($fieldsForMentorLabel as $field) {
                $metadata['file'] = changeFieldLabel($field, $mentorLabel, $metadata['file']);
                $fileValue = isset($fieldLabels['file'][$field]) ? $fieldLabels['file'][$field] : "";
                $redcapValue = isset($fieldLabels['REDCap'][$field]) ? $fieldLabels['REDCap'][$field] : "";
                if ($fileValue != $redcapValue) {
                    $postedFields[] = $field;
                }
            }
            $metadata["REDCap"] = reverseMetadataOrder("initial_import", "init_import_ecommons_id", $metadata["REDCap"]);
            $choices = ["REDCap" => REDCapManagement::getChoices($metadata["REDCap"])];
            $newChoices = CareerDev::getRelevantChoices();
            $newChoiceStr = REDCapManagement::makeChoiceStr($newChoices);
            for ($i = 0; $i < count($metadata['file']); $i++) {
                $field = $metadata['file'][$i]['field_name'];
                $isFieldOfSources = preg_match("/_source$/", $field) && isset($choices["REDCap"][$field]["scholars"]);
                if ($isFieldOfSources) {
                    $metadata['file'][$i]['select_choices_or_calculations'] = $newChoiceStr;
                }
            }

            try {
                $feedback = REDCapManagement::mergeMetadataAndUpload($metadata['REDCap'], $metadata['file'], $token, $server, $postedFields, $deletionRegEx);
                echo json_encode($feedback)."\n";
                $newMetadata = Download::metadata($token, $server);
                $formsAndLabels = CareerDev::getRepeatingFormsAndLabels($newMetadata);
                REDCapManagement::setupRepeatingForms($event_id, $formsAndLabels);   // runs a REPLACE

                convertOldDegreeData($pid);
            } catch (\Exception $e) {
                $feedback = ["Exception" => $e->getMessage()];
                echo json_encode($feedback);
            }
        }
    }
}

function convertOldDegreeData($pid) {
    $fields = [
        "check_degree0",
        "check_degree1",
        "check_degree2",
        "check_degree3",
        "check_degree4",
        "check_degree5",
        "followup_degree0",
        "followup_degree",
        "init_import_degree0",
        "init_import_degree1",
        "init_import_degree2",
        "init_import_degree3",
        "init_import_degree4",
        "init_import_degree5",
    ];
    $convert =[
        1 => "md",
        2 => "phd",
        18 => "mdphd",
        3 => "mph",
        4 => "msci",
        5 => "ms",
        11 => "mhs",
        13 => "pharmd",
        15 => "psyd",
        17 => "rn",
        19 => "bs",
        6 => 99,
    ];

    $pid = db_real_escape_string($pid);
    for ($i = 0; $i < count($fields); $i++) {
        $fields[$i] = db_real_escape_string($fields[$i]);
    }
    $fieldsStr = "('".implode("','", $fields)."')";
    foreach ($convert as $oldValue => $newValue) {
        $oldValue = db_real_escape_string($oldValue);
        $newValue = db_real_escape_string($newValue);
        $sql = "UPDATE redcap_data SET value='$newValue' WHERE project_id='$pid' AND value='$oldValue' AND field_name IN $fieldsStr";
        Application::log("Running SQL $sql");
        db_query($sql);
        if ($error = db_error()) {
            Application::log("ERROR: $error");
            return;
        }
    }
}

function reverseMetadataOrder($instrument, $desiredFirstField, $metadata) {
    $startI = 0;
    $endI = count($metadata) - 1;
    $started = FALSE;
    $instrumentRows = [];
    for ($i = 0; $i < count($metadata); $i++) {
        if (($metadata[$i]['form_name'] == $instrument) && ($startI === 0)) {
            $startI = $i;
            $started = TRUE;
        }
        if ($started && ($metadata[$i]['form_name'] == $instrument)) {
            $endI = $i;
        }
        if ($metadata[$i]['form_name'] == $instrument) {
            $instrumentRows[] = $metadata[$i];
        }
    }
    if ($metadata[$startI]['field_name'] != $desiredFirstField) {
        $instrumentRows = array_reverse($instrumentRows);
        for ($i = $startI; $i <= $endI; $i++) {
            $metadata[$i] = $instrumentRows[$i - $startI];
        }
    }
    return $metadata;
}

function changeFieldLabel($field, $label, $metadata) {
	$i = 0;
	foreach ($metadata as $row) {
		if ($row['field_name'] == $field) {
			$metadata[$i]['field_label'] = $label;
		}
		$i++;
	}
	return $metadata;
}

function insertDeletesForPrefix(&$metadata, $regExToTurnIntoDeletes) {
    for ($i = 0; $i < count($metadata); $i++) {
        if (preg_match($regExToTurnIntoDeletes, $metadata[$i]['field_name'])) {
            $metadata[$i]['field_name'] .= "___delete";
        }
    }
}