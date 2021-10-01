<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\REDCapLookup;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

if (isset($_GET['upload']) && ($_GET['upload'] == 'table')) {
    list($lines, $matchedMentorUids, $newMentorNames) = parsePostForLines($_POST);
    $newLines = [];
    $originalMentorNames = [];
    if (!empty($matchedMentorUids)) {
        $mentorCol = 13;
        for ($i = 0; $i < count($lines); $i++) {
            if ($newMentorNames[$i]) {
                $originalMentorNames[$i] = $lines[$i][$mentorCol];
                $lines[$i][$mentorCol] = $newMentorNames[$i];
                $newLines[$i] = $lines[$i];
            } else {
                $newLines[$i] = [];
            }
        }
        $newUids = getUidsForMentors($newLines);
        if (!empty($newUids)) {
            echo makeAdjudicationTable($lines, $newUids, $matchedMentorUids, $originalMentorNames);
        } else {
            commitChanges($token, $server, $lines, $matchedMentorUids, $pid);
        }
    } else if ($lines) {
        commitChanges($token, $server, $lines, $matchedMentorUids, $pid);
    } else {
        echo "<p class='centered'>No data to upload.</p>";
    }
} else if (isset($_POST['newnames']) || isset($_FILES['csv'])) {
	$lines = [];
	if (isset($_FILES['csv'])) {
		if (is_uploaded_file($_FILES['csv']['tmp_name'])) {
			$fp = fopen($_FILES['csv']['tmp_name'], "rb");
			if (!$fp) {
				echo "Cannot find file.";
			}
			$i = 0;
			while ($line = fgetcsv($fp)) {
				if ($i > 0) {
				    if ($line[0] != "") {
                        $lines[] = $line;
                        $i++;
                    }
				} else {
                    $i++;
                }
			}
			fclose($fp);
		}
	} else {
	    $list = htmlentities($_POST['newnames'], ENT_QUOTES);
		$rows = explode("\n", $list);
		foreach ($rows as $row) {
			if ($row) {
			    $row = htmlentities($row);
				$nodes = preg_split("/\s*[,\t]\s*/", $row);
				if (count($nodes) == 6) {
					$lines[] = $nodes;
				} else {
					header("Location: ".CareerDev::link("add.php")."&mssg=improper_line");
				}
			}
		}
	}
	$mentorUids = getUidsForMentors($lines);
	if (!empty($mentorUids)) {
        echo makeAdjudicationTable($lines, $mentorUids, [], []);
    } else {
	    commitChanges($token, $server, $lines, $mentorUids, $pid);
    }
} else {                //////////////////// default setup
    if (isset($_GET['mssg'])) {
        $mssg = htmlentities($_GET['mssg'], ENT_QUOTES);
		echo "<p class='red centered'><b>$mssg</b></p>";
	}
	echo "<p class='centered'>".CareerDev::makeLogo()."</p>\n";
?>
	<style>
	button { font-size: 20px; color: white; background-color: black; }
	</style>

	<h1>Adding New Scholars or Modifying Existing Scholars for <?= PROGRAM_NAME ?></h1>
	<div style='margin: 0 auto; max-width: 800px;'>
		<p class='centered' id='prompt'><a href='javascript:;' onclick="$('#explanations').show(); $('#prompt').hide();">Click to show detailed instructions</a></p>
		<div id='explanations' style='display: none;'>
			<p>The <b>FirstName</b> is the given name for a person.</p>
			<p>The <b>PreferredName</b> is a name <i>different from the FirstName</i> by which the person should be called. This is a nickname that might or might not be used in the publication or grant literature.</p>
			<p>The <b>Middle</b> is either an initial or a full name.</p>
			<p>The <b>LastName</b> should contain any prior last names [e.g., maiden names] that publications or grants might be listed under. To achieve this, please supply a hyphenated name [e.g., Martin-Smith] or the prior last name in parentheses [e.g., Smith (Martin)].</p>
			<p>The <b>Email</b> addresses you enter will be sent a REDCap survey to fill out with demographic information.</p>
			<p>The <b>Institution</b> should be a short name, but not initials. For instance, Vanderbilt University Medical Center is Vanderbilt, but not VUMC. This is the institution's name that PubMed and the Federal and NIH RePORTERs will match the name on.</p>
			<p><b>--OR--</b> you can supply a Microsoft Excel CSV below.</p>
		</div>
		<form method='POST' action='<?= CareerDev::link("add.php") ?>'><p>
			<b>Please enter</b>:<br>
			<i>FirstName, PreferredName, Middle, LastName, Email, Additional Institutions:</i><br>
			<textarea style='width: 600px; height: 300px;' name='newnames'></textarea><br>
			<button>Process Names</button>
		</p></form>
		<p><b>--OR--</b> supply a CSV Spreadsheet with the specified fields in <a href='<?= CareerDev::link("newFaculty.php") ?>'>this example</a>.</p>
		<form enctype='multipart/form-data' method='POST' action='<?= CareerDev::link("add.php") ?>'><p>
			<input type="hidden" name="MAX_FILE_SIZE" value="3000000" />
			CSV Upload: <input type='file' name='csv'><br>
			<button>Process File</button>
		</p></form>
	</div>
<?php
}

function processLines($lines, $nextRecordId, $token, $server, $mentorUids) {
	$upload = [];
	$lineNum = 1;
	$metadata = Download::metadata($token, $server);
	$metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
	$recordIds = [];
	foreach ($lines as $nodes) {
	    $mentorUid = $mentorUids[$lineNum - 1];
		if ((count($nodes) >= 6) && $nodes[0] && $nodes[3]) {
			$firstName = $nodes[0];
			$middle = $nodes[2];
			$lastName = $nodes[3];
			$preferred = $nodes[1];
			$recordId = NameMatcher::matchName($firstName, $lastName, $token, $server); 
			if (!$recordId) {
				#new
				$recordId = $nextRecordId;
				$nextRecordId++;
			}
			if ($preferred && ($preferred != $firstName)) {
				$firstName .= " (".$preferred.")";
			}
			$email = trim($nodes[4]);
			$institution = trim($nodes[5]);
			$uploadRow = [
			    "record_id" => $recordId,
                "identifier_institution" => $institution,
                "identifier_middle" => $middle,
                "identifier_first_name" => $firstName,
                "identifier_last_name" => $lastName,
                "identifier_email" => $email,
            ];
			if (count($nodes) >= 13) {
				if (preg_match("/female/i", $nodes[6])) {
					$gender = 1;
				} else if (preg_match("/^male/i", $nodes[6])) {
					$gender = 2;
				} else if ($nodes[6] == "") {
					$gender = "";
				} else {
					echo "<p>The gender column contains an invalid value ({$nodes[6]}). Import not successful.</p>";
					throw new \Exception("The gender column contains an invalid value ({$nodes[6]}). Import not successful.");
				}
				if ($nodes[7]) {
				    $dob = importMDY2YMD($nodes[7], "date-of-birth");
				} else {
					$dob = "";
				}
				if (preg_match("/American Indian or Alaska Native/i", $nodes[8])) {
					$race = 1;
				} else if (preg_match("/Asian/i", $nodes[8])) {
					$race = 2;
				} else if (preg_match("/Native Hawaiian or Other Pacific Islander/i", $nodes[8])) {
					$race = 3;
				} else if (preg_match("/Black or African American/i", $nodes[8])) {
					$race = 4;
				} else if (preg_match("/White/i", $nodes[8])) {
					$race = 5;
				} else if (preg_match("/More Than One Race/i", $nodes[8])) {
					$race = 6;
				} else if (preg_match("/Other/i", $nodes[8])) {
					$race = 7;
				} else if ($nodes[8] == "") {
					$race = "";
				} else {
					echo "<p>The race column contains an invalid value ({$nodes[8]}). Import not successful.</p>";
					throw new \Exception("The race column contains an invalid value ({$nodes[8]}). Import not successful.");
				}
				if (preg_match("/Non-Hispanic/i", $nodes[9])) {
					$ethnicity = 2;
				} else if (preg_match("/Hispanic/i", $nodes[9])) {
					$ethnicity = 1;
				} else if ($nodes[9] == "") {
					$ethnicity = "";
				} else {
					echo "<p>The ethnicity column contains an invalid value ({$nodes[9]}). Import not successful.</p>";
					throw new \Exception("The ethnicity column contains an invalid value ({$nodes[9]}). Import not successful.");
				}
				if (preg_match("/Prefer Not To Answer/i", $nodes[10])) {
					$disadvantaged = 3;
				} else if (preg_match("/N/i", $nodes[10])) {
					$disadvantaged = 2;
				} else if (preg_match("/Y/i", $nodes[10])) {
					$disadvantaged = 1;
				} else if ($nodes[10] == "") {
					$disadvantaged = "";
				} else {
					echo "<p>The disadvantaged column contains an invalid value ({$nodes[10]}). Import not successful.</p>";
					throw new \Exception("The disadvantaged column contains an invalid value ({$nodes[10]}). Import not successful.");
				}
				if (preg_match("/N/i", $nodes[11])) {
					$disabled = 2;
				} else if (preg_match("/Y/i", $nodes[11])) {
					$disabled = 1;
				} else {
					$disabled = "";
				}
				if (preg_match("/US born/i", $nodes[12])) {
					$citizenship = 1;
				} else if (preg_match("/Acquired US/i", $nodes[12])) {
					$citizenship = 2;
				} else if (preg_match("/Permanent US Residency/i", $nodes[12])) {
					$citizenship = 3;
				} else if (preg_match("/Temporary Visa/i", $nodes[12])) {
					$citizenship = 4;
				} else if ($nodes[12] == "") {
					$citizenship = "";
				} else {
					echo "<p>The citizenship column contains an invalid value ({$nodes[12]}). Import not successful.</p>";
					throw new \Exception("The citizenship column contains an invalid value ({$nodes[12]}). Import not successful.");
				}
				if ($nodes[13]) {
					$mentor = $nodes[13];
				} else {
					$mentor = "";
				}
				if ($nodes[14]) {
				    if (REDCapManagement::isDate($nodes[14])) {
                        $trainingStart = importMDY2YMD($nodes[14], "Start of Training");
                        $orcid = "";
                    } else {
                        $trainingStart = "";
				        $orcid = $nodes[14];
                    }
                } else {
				    $trainingStart = "";
				    $orcid = "";
                }
				if ($nodes[15]) {
                    $trainingStart = importMDY2YMD($nodes[15], "Start of Training");
                }

				$uploadRow["imported_dob"] = $dob;
				$uploadRow["imported_gender"] = $gender;
				$uploadRow["imported_race"] = $race;
				$uploadRow["imported_ethnicity"] = $ethnicity;
				$uploadRow["imported_disadvantaged"] = $disadvantaged;
				$uploadRow["imported_disabled"] = $disabled;
				$uploadRow["imported_citizenship"] = $citizenship;
                $uploadRow["imported_mentor"] = $mentor;
                $uploadRow["imported_mentor_userid"] = $mentorUid;
                if (in_array("identifier_start_of_training", $metadataFields)) {
                    $uploadRow["identifier_start_of_training"] = $trainingStart;
                }
                if ($orcid && in_array("identifier_orcid", $metadataFields)) {
                    $uploadRow["identifier_orcid"] = $orcid;
                }
			}
			$upload[] = $uploadRow;
			$recordIds[] = $uploadRow["record_id"];
		}
		$lineNum++;
	}
	return [$upload, $recordIds];
}

function importMDY2YMD($mdyDate, $col) {
    $nodes = preg_split("/[\-\/]/", $mdyDate);
    # assume MDY
    if ((count($nodes) == 3) && is_numeric($nodes[0]) && is_numeric($nodes[1]) && is_numeric($nodes[2])) {
        if ($nodes[2] < 100) {
            if ($nodes[2] > 20) {
                $nodes[2] += 1900;
            } else {
                $nodes[2] += 2000;
            }
        }
        return $nodes[2] . "-" . $nodes[0] . "-" . $nodes[1];
    } else {
        echo "<p>The $col column contains an invalid value ($mdyDate). Import not successful.</p>";
        throw new \Exception("The $col column contains an invalid value ({$mdyDate}). Import not successful.");
    }
}

function getUidsForMentors($lines) {
    $mentorUids = [];
    $mentorCol = 13;
    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];
        if (isset($line) && isset($line[$mentorCol]) && $line[$mentorCol]) {
            list($mentorFirst, $mentorLast) = NameMatcher::splitName($line[$mentorCol], 2);
            $lookup = new REDCapLookup($mentorFirst, $mentorLast);
            $currentUids = $lookup->getUidsAndNames();
            if (count($currentUids) == 0) {
                $lookup = new REDCapLookup("", $mentorLast);
                $currentUids = $lookup->getUidsAndNames();
            }
            if (!empty($currentUids)) {
                $mentorUids[$i] = $currentUids;
            }
        }
    }
    return $mentorUids;
}

function makeAdjudicationTable($lines, $mentorUids, $existingUids, $originalMentorNames) {
    $headers = [
        "Scholar Name",
        "Mentor Name",
        "Mentor User-id",
    ];
    $url = Application::link("this")."&upload=table";
    $foundMentor = FALSE;

    $html = "";
    $html .= "<form action='$url' method='POST'>";
    $html .= "<table class='bordered centered max-width'>";
    $html .= "<thead>";
    $html .= "<tr><th>".implode("</th><th>", $headers)."</th></tr>";
    $html .= "</thead>";
    $html .= "<tbody>";
    for ($i = 0; $i < count($lines); $i++) {
        $currLine = $lines[$i];
        $json = json_encode($currLine);
        $hiddenHTML = "<input type='hidden' name='line___$i' value='$json'>";
        $currMentorUids = $mentorUids[$i];
        $currMentorName = "";
        if (isset($currLine[13])) {
            $currMentorName = $currLine[13];
        }
        $customId = "mentorcustom___$i" . "___custom";
        $mentorCustomCode = "mentor___custom";
        $customRadio = "<input type='radio' name='mentor___$i' id='$customId' value='$mentorCustomCode'>";
        $customHidden = "<input type='hidden' name='mentor___$i' value='$mentorCustomCode'>";
        $customLine = "<label for='$customId'>Custom:</label> <input type='text' name='mentorcustom___$i' value=''>";
        if (isset($originalMentorNames[$i]) && REDCapManagement::hasValue($originalMentorNames[$i])) {
            $html .= "<input type='hidden' name='mentorname___$i' value='".preg_replace("/'/", "\\'", $originalMentorNames[$i])."'>";
        }
        if (isset($existingUids[$i])) {
            $html .= "<input type='hidden' name='mentor___$i' value='".preg_replace("/'/", "\\'", $existingUids[$i])."'>";
        } else if ($currMentorName) {
            $foundMentor = TRUE;
            $html .= "<tr>";
            $html .= "<th>{$currLine[0]} {$currLine[3]}$hiddenHTML</th>";
            $html .= "<td>$currMentorName</td>";
            if (count($currMentorUids) == 0) {
                $escapedMentorName = preg_replace("/'/", "\\'", $currMentorName);
                $hiddenField = "<input type='hidden' name='originalmentorname___$i' value='$escapedMentorName'>";
                $html .= "<td class='red'>";
                $html .= "<strong>No mentors matched with $currMentorName.</strong><br>Will not upload this mentor.<br>Perhaps there is a nickname and/or a maiden name at play here. Do you want to try adjusting their name?<br>$hiddenField<input type='text' name='newmentorname___$i' value='$escapedMentorName'><br>";
                $html .= "<br>Or try a custom id?<br>".$customLine.$customHidden;
                $html .= "</td>";
            } else if (count($currMentorUids) == 1) {
                $uid = array_keys($currMentorUids)[0];
                $yesId = "mentor___$i" . "___yes";
                $noId = "mentor___$i" . "___no";
                $yesno = "<input type='radio' name='mentor___$i' id='$yesId' value='$uid' checked> <label for='$yesId'>Yes</label><br>";
                $yesno .= "<input type='radio' name='mentor___$i' id='$noId' value=''> <label for='$noId'>No</label><br>";
                $yesno .= $customRadio." ".$customLine;
                $html .= "<td class='green'>Matched: $uid<br>$yesno</td>";
            } else {
                $radios = [];
                $selected = " checked";
                foreach ($currMentorUids as $uid => $mentorName) {
                    $id = "mentor___" . $i . "___" . $uid;
                    $radios[] = "<input type='radio' name='mentor___$i' id='$id' value='$uid'$selected> <label for='$id'>$mentorName ($uid)</label>";
                    $selected = "";
                }
                $radios[] = $customRadio." ".$customLine;
                $html .= "<td class='yellow'>" . implode("<br>", $radios) . "</td>";
            }
            $html .= "</tr>";
        } else {
            $html .= $hiddenHTML;
        }
    }
    $html .= "</tbody>";
    $html .= "</table>";
    $html .= "<p class='centered'><button>Add Mentors</button></p>";
    $html .= "</form>";


    if ($foundMentor) {
        return $html;
    } else {
        return "";
    }
}

function parsePostForLines($post) {
    $lines = [];
    $mentorUids = [];
    $newMentorNames = [];
    $mentorCol = 13;
    $mentorCustomCode = "mentor___custom";
    foreach ($post as $key => $value) {
        $key = REDCapManagement::sanitize($key);
        $value = REDCapManagement::sanitize($value);
        if (preg_match("/^line___\d+$/", $key)) {
            $i = preg_replace("/^line___/", "", $key);
            $lines[$i] = json_decode($value, TRUE);
            if ($post["mentorname___".$i]) {
                $lines[$i][$mentorCol] = $post["mentorname___".$i];
            }
        } else if (preg_match("/^mentor___\d+$/", $key) && ($value != $mentorCustomCode)) {
            $i = preg_replace("/^mentor___/", "", $key);
            $mentorUids[$i] = $value;
        } else if (preg_match("/^newmentorname___\d+$/", $key)) {
            $i = preg_replace("/^newmentorname___/", "", $key);
            $origMentorName = $post['originalmentorname___'.$i];
            if ($origMentorName != $value) {
                $newMentorNames[$i] = $value;
            }
        } else if (preg_match("/^mentorcustom___\d+$/", $key) && $value) {
            $i = preg_replace("/^mentorcustom___/", "", $key);
            if ($post['mentor___'.$i] == $mentorCustomCode) {
                $mentorUids[$i] = $value;
            }
        }
    }
    ksort($lines);
    ksort($mentorUids);
    ksort($newMentorNames);
    return [$lines, $mentorUids, $newMentorNames];
}

function commitChanges($token, $server, $lines, $mentorUids, $pid) {
    $maxRecordId = 0;
    $recordIds = Download::recordIds($token, $server);
    foreach ($recordIds as $recordId) {
        if ($recordId > $maxRecordId) {
            $maxRecordId = $recordId;
        }
    }
    $recordId = $maxRecordId + 1;

    try {
        list($upload, $newRecordIds) = processLines($lines, $recordId, $token, $server, $mentorUids);
        $feedback = [];
        if (!empty($upload)) {
            $feedback = Upload::rows($upload, $token, $server);
            foreach ($newRecordIds as $recordId) {
                Application::refreshRecordSummary($token, $server, $pid, $recordId);
            }
        } else {
            $mssg = "No data specified.";
            header("Location: ".CareerDev::link("add.php")."&mssg=".urlencode($mssg));
        }
        if (isset($feedback['error'])) {
            $mssg = "People not added ". $feedback['error'];
            header("Location: ".CareerDev::link("add.php")."&mssg=".urlencode($mssg));
        }
        if (isset($_GET['mssg']) && ($_GET['mssg'] == "improper_line")) {
            $mssg = "A line does not contain the necessary 6 columns. No data have been added. Please try again.";
            echo "<p class='red centered'>$mssg</p>";
            return;
        }
    } catch (\Exception $e) {
        $mssg = $e->getMessage();
        header("Location: ".CareerDev::link("add.php")."&mssg=".urlencode($mssg));
    }

    $timespan = 3;
    echo "<h1>Adding New Scholars or Modifying Existing Scholars</h1>";
    echo "<div style='margin: 0 auto; max-width: 800px'>";
    echo "<p class='centered'>".count($upload).(count($upload) == 1 ? " person" : " people")." added/modified.</p>";
    echo "<p class='centered'>Going to Flight Tracker Central in ".$timespan." seconds...</p>";
    echo "<script>\n";
    echo "$(document).ready(function() {\n";
    echo "\tsetTimeout(function() {\n";
    echo "\t\twindow.location.href='".Application::link("index.php")."';\n";
    echo "\t}, ".floor($timespan * 1000).");\n";
    echo "});\n";
    echo "</script>\n";
    echo "</div>";
}