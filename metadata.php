<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/CareerDev.php");
require_once(dirname(__FILE__)."/classes/Download.php");
require_once(dirname(__FILE__)."/classes/Upload.php");
require_once(dirname(__FILE__)."/classes/REDCapManagement.php");

$filename = dirname(__FILE__)."/metadata.json";
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
		$fp = fopen($filename, "r");
		$json = "";
		while ($line = fgets($fp)) {
			$json .= $line;
		}
		fclose($fp);

		$metadata = array();
		$metadata['file'] = json_decode($json, TRUE);
		$metadata['REDCap'] = Download::metadata($token, $server);

		$choices = array();
		foreach ($metadata as $type => $md) {
			$choices[$type] = REDCapManagement::getChoices($md);
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

		$missing = array();
		$additions = array();
		$changed = array();
		$metadataFields = REDCapManagement::getMetadataFieldsToScreen();
		$specialFields = REDCapManagement::getSpecialFields("all");
		foreach ($fieldList["file"] as $field => $choiceStr) {
			if (!in_array($field, $specialFields)) {
				if (!isset($fieldList["REDCap"][$field])) {
					if (!preg_match("/^coeus_/", $field)) {
						array_push($missing, $field);
						if (!preg_match($deletionRegEx, $field)) {
							array_push($additions, $field);
						}
					}
				} else if ($choices["file"][$field] && $choices["REDCap"][$field] && !REDCapManagement::arraysEqual($choices["file"][$field], $choices["REDCap"][$field])) {
					array_push($missing, $field);
					array_push($changed, $field);

				} else {
					foreach ($metadataFields as $metadataField) {
						if (REDCapManagement::hasMetadataChanged($indexedMetadata["REDCap"][$field][$metadataField], $indexedMetadata["file"][$field][$metadataField], $metadataField)) {
							array_push($missing, $field);
							array_push($changed, $field);
							break; // metadataFields loop
						}
					}
				}
			}
		}

		CareerDev::setSetting($lastCheckField, time(), $pid);
		if (count($additions) + count($changed) > 0) {
			echo "<script>var missing = ".json_encode($missing).";</script>\n";
            echo "<div id='metadataWarning' class='install-metadata-box install-metadata-box-danger'>
                <i class='fa fa-exclamation-circle' aria-hidden='true'></i> An upgrade in your Data Dictionary exists. <a href='javascript:;' onclick='installMetadata(missing,".json_encode($this->getUrl("index.php?pid=".IEDEA_PROJECTS)).");'>Click here to install.</a>
                <ul><li>The following fields will be added: ".(empty($additions) ? "<i>None</i>" : "<strong>".implode(", ", $additions)."</strong>")."</li>
                <li>The following fields will be changed: ".(empty($changed) ? "<i>None</i>" : "<strong>".implode(", ", $changed)."</strong>")."</li></ul>
            </div>";
		}
	}
} else if ($_POST['process'] == "install") {
	$postedFields = $_POST['fields'];
	$fp = fopen($filename, "r");
	$json = "";
	while ($line = fgets($fp)) {
		$json .= $line;
	}
	fclose($fp);

	$metadata = array();
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
        $fieldLabels = array();
        foreach ($metadata as $type => $md) {
            $fieldLabels[$type] = REDCapManagement::getLabels($md);
        }
        $fieldsForMentorLabel = array("check_primary_mentor", "followup_primary_mentor", );
        foreach ($fieldsForMentorLabel as $field) {
            $metadata['file'] = changeFieldLabel($field, $mentorLabel, $metadata['file']);
            if ($fieldLabels['file'][$field] != $fieldLabels['REDCap'][$field]) {
                array_push($postedFields, $field);
            }
        }

        try {
		    $feedback = REDCapManagement::mergeMetadataAndUpload($metadata['REDCap'], $metadata['file'], $token, $server, $postedFields, $deletionRegEx);
		    echo json_encode($feedback);
        } catch (\Exception $e) {
            $feedback = array("Exception" => $e->getMessage());
            echo json_encode($feedback);
        }
	}
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
