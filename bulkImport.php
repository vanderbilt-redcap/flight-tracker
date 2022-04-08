<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

if (isset($_GET['positions'])) {
    $importFile = "import_positions.csv";
    $title = "Positions";
    $suffix = "&positions";
    $expectedItems = 10;
} else if (isset($_GET['grants'])) {
    $importFile = "import.csv";
    $title = "Grants";
    $suffix = "&grants";
    $expectedItems = 12;
} else {
    # default
    $importFile = "import.csv";
    $title = "Grants";
    $suffix = "&grants";
    $expectedItems = 12;
}
if (isset($_GET['test'])) {
    $suffix .= "&test";
}

if (isset($_FILES['bulk'])) {
	$longImportFile = dirname(__FILE__)."/".$importFile;
	if (verifyFile($_FILES['bulk'], $longImportFile, $expectedItems)) {
		$errors = [];
		list($headers, $lines, $startIdx) = readCSV($_FILES['bulk'], $title);

		$metadata = Download::metadata($token, $server);
		$records = Download::recordIds($token, $server);
		$metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
		$choices = REDCapManagement::getChoices($metadata);
		$matchedIndices = [0];
		$unmatchedLines = [];
		$maxInstances = [];
		$i = 0;
		$customFields = Application::getCustomFields($metadata);
		$upload = [];
        foreach ($lines as $line) {
            if ($startIdx == 2) {
                $firstName = $line[0];
                $lastName = $line[1];
                $recordId = NameMatcher::matchName($firstName, $lastName, $token, $server);
            } else if ($startIdx == 1) {
                $recordId = $line[0];
            } else if ($startIdx == 0) {
                $recordId = FALSE;
            } else {
                throw new \Exception("This should never happen, a start index of $startIdx");
            }
            if ($recordId && in_array($recordId, $records)) {
                if ($title == "Grants") {
                    $redcapData = Download::fieldsForRecords($token, $server,  array("record_id", "custom_last_update"), [$recordId]);
                    if (!$maxInstances[$recordId]) {
                        $maxInstances[$recordId] = REDCapManagement::getMaxInstance($redcapData, "custom_grant", $recordId);
                    }
                } else if ($title == "Positions") {
                    $redcapData = Download::fieldsForRecords($token, $server,  array("record_id", "promotion_date"), [$recordId]);
                    if (!$maxInstances[$recordId]) {
                        $maxInstances[$recordId] = REDCapManagement::getMaxInstance($redcapData, "position_change", $recordId);
                    }
                } else {
                    echo "<p class='red padded centered max-width'>ERROR! Could not match group!</p>\n";
                }
                $maxInstances[$recordId]++;
                try {
                    if ($title == "Grants") {
                        $uploadRow = [
                            "record_id" => $recordId,
                            "redcap_repeat_instrument" => "custom_grant",
                            "redcap_repeat_instance" => $maxInstances[$recordId],
                            "custom_title" => $line[$startIdx],
                            "custom_number" => $line[$startIdx + 1],
                            "custom_type" => translateTypeIntoIndex($line[$startIdx + 2], $choices),
                            "custom_org" => $line[$startIdx + 3],
                            "custom_recipient_org" => $line[$startIdx + 4],
                            "custom_role" => translateIntoIndex($line[$startIdx + 5], $choices, "custom_role"),
                            "custom_start" => $line[$startIdx + 6],
                            "custom_end" => $line[$startIdx + 7],
                            "custom_costs" => $line[$startIdx + 8],
                            "custom_last_update" => date("Y-m-d"),
                            "custom_grant_complete" => "2",
                        ];
                        if (in_array("custom_costs_total", $customFields)) {
                            $uploadRow["custom_costs_total"] = $line[$startIdx + 9];
                        }
                        $upload[] = $uploadRow;
                    } else if ($title == "Positions") {
                        list($department, $other) = findDepartment($line[$startIdx + 6], $choices, "promotion_department");
                        $uploadRow = [
                            "record_id" => $recordId,
                            "redcap_repeat_instrument" => "position_change",
                            "redcap_repeat_instance" => $maxInstances[$recordId],
                            "promotion_in_effect" => $line[$startIdx],
                            "promotion_job_title" => $line[$startIdx + 1],
                            "promotion_job_category" => translateIntoIndex($line[$startIdx + 2], $choices, "promotion_job_category"),
                            "promotion_rank" => translateIntoIndex($line[$startIdx + 3], $choices, "promotion_rank"),
                            "promotion_institution" => $line[$startIdx + 4],
                            "promotion_location" => $line[$startIdx + 5],
                            "promotion_department" => $department,
                            "promotion_department_other" => $other,
                            "promotion_division" => $line[$startIdx + 7],
                            "promotion_date" => date("Y-m-d"),
                            "position_change_complete" => "2",
                        ];
                        if (in_array("promotion_prior", $metadataFields)) {
                            if ($line[10]) {
                                $uploadRow["promotion_prior"] = "1";
                            } else {
                                $uploadRow["promotion_prior"] = "";
                            }
                        }
                        $upload[] = $uploadRow;
                    } else {
                        echo "<p class='red padded centered max-width'>ERROR in Record $recordId! Could not match group!</p>\n";
                        $unmatchedLines[] = $i;
                    }
                } catch (\Exception $e) {
                    echo "<p class='red padded centered max-width'>ERROR in Record $recordId! ".$e->getMessage()."</p>\n";
                    $unmatchedLines[] = $i;
                }
            } else if ($i != 0) {
                echo "<p class='red padded centered max-width'>Could not match $recordId with start index of $startIdx.</p>";
                $unmatchedLines[] = $i;
            }
            $i++;
        }
		if (!empty($unmatchedLines)) {
			echo "<div class='red padded max-width'>\n";
			echo "<h4>Unmatched Lines!</h4>\n";
			echo "<p class='centered'>The following lines could not be matched to a record, perhaps due to previous errors. Please fix and submit again. No records were imported.</p>\n";
			foreach ($unmatchedLines as $i) {
				$line = $lines[$i];
				echo "<p class='centered'>Line ".($i+1).": <code>".implode(", ", $line)."</code></p>\n";
			}
			echo "</div>\n";
		} else {
		    try {
                $feedback = Upload::rows($upload, $token, $server);
                if ($feedback['error'])	{
                    echo "<p class='red padded centered max-width'>ERROR! ".$feedback['error']."</p>\n";
                } else {
                    echo "<p class='green padded centered max-width'>Upload successful!</p>\n";
                }
            } catch (\Exception $e) {
                echo "<p class='red padded centered max-width'>ERROR! ".$e->getMessage()."</p>\n";
            }
		}
	} else {
		echo "<p class='red padded centered max-width'>ERROR! The file is not in the right format. ".detectFirstError($_FILES['bulk'], $longImportFile, $expectedItems)."</p>\n";
	}
} else if (isset($_POST['submit'])) {
	echo "<p class='red padded centered max-width'>ERROR! The file could not be found!</p>\n";
}

$downloadFile = "downloadCSV.php";
?>

<h1>Import <?= $title ?> in Bulk</h1>

<div style='width: 800px; margin: 14px auto;' class='green centered padded'>Please import a CSV (comma delimited) with one row per grant. Use <a href='<?= Application::link($downloadFile)."&file=".urlencode($importFile)."&match=names"  ?>'>this template (for matching by first and last names)</a> or <a href='<?= Application::link($downloadFile)."&file=".urlencode($importFile)."&match=record" ?>'>this template (for matching by record ids)</a> to start with. Each line must match the first name and last name (exactly) as specified in the database.</div>
<form method='POST' action='<?= CareerDev::link("bulkImport.php").$suffix ?>' enctype='multipart/form-data'>
<?= Application::generateCSRFTokenHTML() ?>
<p class='centered'><input type='file' name='bulk'></p>
<p class='centered'><input type='submit' name='submit' value='Upload'></p>
</form>

<?php
function verifyFile($fileinfo, $importFile, $expectedItems) {
	$error = detectFirstError($fileinfo, $importFile, $expectedItems);
	if (!$error) {
		return TRUE;
	} else {
		return FALSE;
	}
}

function detectFirstError($fileinfo, $importFile, $expected) {
	if (!$fileinfo) {
		return "No file supplied!";
	}

	$filename = $fileinfo['tmp_name'];
	if (!file_exists($filename)) {
		return "File does not exist!";
	}

	$fp = fopen($importFile, "r");
	$headers = fgetcsv($fp);
	fclose($fp);

	$fp = fopen($filename, "r");
    $firstLine = fgetcsv($fp);

    if ($firstLine[0] == "Record ID") {
        array_shift($headers);
        $headers[0] = "Record ID";
        $expected--;
    }

	if (preg_match("/import_positions/", $importFile)) {
        if (count($headers) + 1 == count($firstLine)) {
            $headers[] = "Prior [Yes or No]";
            $expected++;
        }
    }
    if (count($headers) != count($firstLine)) {
        return "The number of headers (".count($firstLine).") do not match what's expected (".count($headers).")!";
    }

	for ($i = 0; $i < count($headers); $i++) {
		if ($headers[$i] != $firstLine[$i]) {
			return "The words in the headers (".$firstLine[$i].") do not match what's expected (".$headers[$i].")!";
		}
	}

	$i = 2;
	while ($line = fgetcsv($fp)) {
		if (count($line) != $expected) {
			return "Line $i has ".count($line)." items! ($expected items expected.)";
		}
		if (preg_match("/import\.csv/", $importFile)) {
            if (!inDateFormat($line[8])) {
                return "The start date {$line[8]} is not in YYYY-MM-DD or MM-DD-YYYY format!";
            }
            if (!inDateFormat($line[9])) {
                return "The end date {$line[9]} is not in YYYY-MM-DD or MM-DD-YYYY format!";
            }
        }
		$i++;
	}
	fclose($fp);

	return "";
}

function ensureDateIsYMD($date) {
	if (preg_match("/^\d\d?[\-\/]\d\d?[\-\/]\d\d\d\d$/", $date)) {
		# assume MDY
		$nodes = preg_split("/[\-\/]/", $date);
		if (count($nodes) == 3) {
			return $nodes[2]."-".$nodes[0]."-".$nodes[1];
		}
	}
	if (preg_match("/^\d\d?[\-\/]\d\d?[\-\/]\d\d$/", $date)) {
		# assume MDY
		$nodes = preg_split("/[\-\/]/", $date);
		if ((count($nodes) == 3) && is_numeric($nodes[0]) && is_numeric($nodes[1]) && is_numeric($nodes[2])) {
			$year = $nodes[2];
			if ($year > 80) {
				$year += 1900;
			} else {
				$year += 2000;
			}
			return $year."-".$nodes[0]."-".$nodes[1];
		}
	}
	return $date;
}

function inDateFormat($date) {
	if ($date === "") {
		return TRUE;
	}
	if (preg_match("/^\d\d\d\d[\-\/]\d\d?[\-\/]\d\d?$/", $date)) {
		return TRUE;
	}
	if (preg_match("/^\d\d[\-\/]\d\d?[\-\/]\d\d?$/", $date)) {
		return TRUE;
	}
	if (preg_match("/^\d\d?[\-\/]\d\d?[\-\/]\d\d\d\d$/", $date)) {
		return TRUE;
	}
	if (preg_match("/^\d\d?[\-\/]\d\d?[\-\/]\d\d$/", $date)) {
		return TRUE;
	}
	return FALSE;
}

function readCSV($fileinfo, $validationType) {
	$filename = $fileinfo['tmp_name'];
	if (file_exists($filename)) {
		$fp = fopen($filename, "r");
		$headers = fgetcsv($fp);
        $startIdx = getStartIndex($headers);
        $lines = array();
		while ($line = fgetcsv($fp)) {
		    for ($i = 0; $i < count($line); $i++) {
		        $line[$i] = trim(utf8_decode($line[$i]));
            }
		    if ($validationType == "Grants") {
                $line[$startIdx + 6] = ensureDateIsYMD($line[$startIdx + 6]);
                $line[$startIdx + 7] = ensureDateIsYMD($line[$startIdx + 7]);
                $line[$startIdx + 8] = REDCapManagement::removeMoneyFormatting($line[$startIdx + 8]);
                $line[$startIdx + 9] = REDCapManagement::removeMoneyFormatting($line[$startIdx + 9]);
            }
			array_push($lines, $line);
		}
		fclose($fp);
		return [$headers, $lines, $startIdx];
	}
	return [];
}

function translateTypeIntoIndex($type, $allChoices) {
	if (is_numeric($type)) {
		return $type;
	} else {
	    return translateIntoIndex($type, $allChoices, "custom_type");
	}
}

# returns [$departmentIndex, $otherValue]
function findDepartment($value, $allChoices, $field) {
    $choices = $allChoices[$field];
    $foundIdx = "";
    foreach ($choices as $idx => $label) {
        if ($label == $value) {
            $foundIdx = $idx;
        }
    }
    if ($foundIdx != "") {
        return [$foundIdx, ""];
    } else if ($choices["999999"] && $value != "") {
        # select field with OTHER
        return ["999999", $value];
    } else if ($choices && $value != "") {
        # select field, but no other specified
        return ["", $value];
    } else {
        # no select field
        return [$value, ""];
    }
}

function translateIntoIndex($value, $allChoices, $field) {
	$choices = $allChoices[$field];
	foreach ($choices as $idx => $label) {
	    if ($label == $value) {
	        return $idx;
        }
    }
	if ($value == "Training Grant Admin") {
	    return translateIntoIndex("Mentoring/Training Grant Admin", $allChoices, $field);
    } else if ($value == "Mentoring/Training Grant Admin") {
        return translateIntoIndex("Training Grant Admin", $allChoices, $field);
    }
	if ($value == "") {
        return "";
    } else {
	    throw new \Exception("Could not find '$value' in choices: ".json_encode($choices));
    }
}

function getRecordsFromREDCapData($redcapData) {
	$records = array();
	foreach ($redcapData as $row) {
		if (!in_array($row['record_id'], $records)) {
			array_push($records, $row['record_id']);
		}
	}
	return $records;
}

function getStartIndex($headers) {
    if (($headers[0] == "First Name") && ($headers[1] == "Last Name")) {
        return 2;
    } else if (in_array($headers[0], ["Record ID", "Record Id", "record_id"])) {
        return 1;
    } else {
        return 0;
    }
}