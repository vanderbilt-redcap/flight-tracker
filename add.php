<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\REDCapLookup;
use \Vanderbilt\CareerDevLibrary\NIHTables;


require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

$allNewRecords = ($_POST['createNewRecords'] || isset($_GET['createNewRecords']));
$createRecordsURI = $allNewRecords ? "&createNewRecords" : "";
if (isset($_GET['upload']) && ($_GET['upload'] == 'table')) {
    list($lines, $matchedMentorUids, $newMentorNames) = parsePostForLines($_POST);
    $newLines = [];
    $originalMentorNames = [];
    if (!empty($matchedMentorUids) || Application::isLocalhost()) {
        $mentorCol = 13;
        for ($i = 0; $i < count($lines); $i++) {
            if (isset($newMentorNames[$i])) {
                $originalMentorNames[$i] = $lines[$i][$mentorCol];
                if (is_array($newMentorNames[$i])) {
                    $lines[$i][$mentorCol] = implode(", ", array_values($newMentorNames[$i]));
                } else {
                    $lines[$i][$mentorCol] = $newMentorNames[$i];
                }
                $newLines[$i] = $lines[$i];
            } else {
                $newLines[$i] = [];
            }
        }
        $newUids = getUidsForMentors($newLines);
        if (!empty($newUids) || Application::isLocalhost()) {
            echo makeAdjudicationTable($lines, $newUids, $matchedMentorUids, $originalMentorNames);
        } else {
            commitChanges($token, $server, $lines, $matchedMentorUids, $pid, $createRecordsURI);
        }
    } else if ($lines) {
        commitChanges($token, $server, $lines, $matchedMentorUids, $pid, $createRecordsURI);
    } else {
        echo "<p class='centered'>No data to upload.</p>";
    }
} else if (in_array($_POST['action'] ?? "", ["intakeTable", "importText"])) {
	$lines = [];
	if ($_POST['action'] == "intakeTable") {
        $tmpFilename = $_FILES['csv']['tmp_name'] ?? "";
		if ($tmpFilename && is_string($tmpFilename) && is_uploaded_file($tmpFilename)) {
			$fp = fopen($tmpFilename, "rb");
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
	} else if ($_POST['action'] == "importText") {
	    $list = REDCapManagement::sanitize($_POST['newnames']);
        $rows = explode("\n", $list);
        foreach ($rows as $row) {
            if ($row) {
                $nodes = preg_split("/\s*[,\t]\s*/", $row);
                if (count($nodes) == 6) {
                    $lines[] = $nodes;
                } else {
                    if (isset($_POST['addNewScholar'])) {
                        $link = Application::link("addNewScholar.php");
                    } else {
                        $link = Application::link("this").$createRecordsURI;
                    }
                    $mssg = "A line does not contain the necessary 6 columns. No data have been added. Please try again.";
                    exitProcess($mssg, $link);
                    exit;
                }
            }
        }
	} else {
        throw new \Exception("This should never happen.");
    }
	$mentorUids = getUidsForMentors($lines);
	if (!empty($mentorUids)) {
        echo makeAdjudicationTable($lines, $mentorUids, [], []);
        $url = APP_PATH_WEBROOT."ProjectGeneral/keep_alive.php?pid=".$pid;
        echo "<script>
function keepAlive() {
    const resetTime = 3;
    setTimeout(function(){
        $.post('$url', {'redcap_csrf_token': getCSRFToken()}, function(data) {
            if (data === '1') {
                keepAlive();
            }
        });
    }, (resetTime*60000));

$(document).ready(() => {
    keepAlive();
});
</script>";
    } else {
	    commitChanges($token, $server, $lines, $mentorUids, $pid, $createRecordsURI);
    }
} else if (in_array($_POST['action'] ?? "", ["importTrainees", "importFaculty", "importBoth"])) {
    if (isset($_FILES['tableCSV']['tmp_name']) && is_string($_FILES['tableCSV']['tmp_name'])) {
        $filename = $_FILES['tableCSV']['tmp_name'];
    } else {
        $filename = "";
    }
    $mssg = \Vanderbilt\FlightTrackerExternalModule\importNIHTable($_POST, $filename, $token, $server);
    $link = Application::link("index.php");
    $goodToGo = preg_match("/green/", $mssg);
    $timespan = 3;
    if ($goodToGo) {
        echo "<p class='centered'>Going to Flight Tracker Central in " . $timespan . " seconds...</p>";
        echo $mssg;
        echo refreshScript($timespan, $link);
    } else {
        echo $mssg;
    }
} else {                //////////////////// default setup
	echo "<p class='centered'>".CareerDev::makeLogo()."</p>\n";
?>
	<style>
	button { font-size: 20px; color: white; background-color: black; }
	</style>

    <script>
        function checkSimilarNames(ob) {
            if (ob.attr('checked')) {
                const thisID = ob.attr('id');
                const thisLabel = $('label[for='+thisID+']').html();
                $('input[type=radio]').each(function(idx, radioInputOb) {
                    const radioInputID = radioInputOb.attr('id');
                    const radioInputName = radioInputOb.attr('name');
                    const radioIsChecked = ($('[name='+radioInputName+']:checked').length > 0);
                    const radioInputLabel = $('label[for='+radioInputID+']').html();
                    if ((radioInputLabel === thisLabel) && !radioIsChecked) {
                        // only check unchecked groups
                        radioInputOb.attr('checked', true);
                    }
                });
            }
        }

        $(document).ready(() => {
            $('input[type=radio]').on('click', function() { checkSimilarNames($(this)); })
        });
    </script>

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
		<form method='POST' action='<?= Application::link("this") ?>'><p>
            <?= Application::generateCSRFTokenHTML() ?>
			<b>Please enter</b>:<br>
			<i>FirstName, PreferredName, Middle, LastName, Email, Additional Institutions:</i><br>
			<textarea style='width: 600px; height: 300px;' name='newnames'></textarea><br/>
                <input type="hidden" name="createNewRecords" value="1" />
                <input type="hidden" name="action" value="importText" />
			<button>Process Names</button>
		</p></form>
        <h2><b>--OR--</b> supply a CSV Spreadsheet with the specified fields in <a href='<?= Application::link("newFaculty.php") ?>'>this example</a>.</h2>
        <p class="centered">(Name and institution fields are the most important on this spreadsheet. Other fields are preferred, but still optional.)</p>
        <form enctype='multipart/form-data' method='POST' action='<?= Application::link("this") ?>'><p>
                <?= Application::generateCSRFTokenHTML() ?>
                <input type="hidden" name="MAX_FILE_SIZE" value="3000000" />
                CSV Upload: <input type='file' name='csv'><br/>
                <input type="hidden" name="createNewRecords" value="1" />
                <input type="hidden" name="action" value="intakeTable" />
                <button>Process File</button>
            </p></form>
        <h2><b>--OR--</b> supply a CSV Spreadsheet with for <a href="<?= NIHTables::NIH_LINK ?>">NIH Training Tables</a> 5 or 8.</h2>
        <form enctype='multipart/form-data' method='POST' action='<?= Application::link("this") ?>'>
            <?= Application::generateCSRFTokenHTML() ?>
            <p class='max-width'>
                <input type='radio' name='action' id='actionTrainees' value='importTrainees' checked /> <label for='actionTrainees'>Import Only Trainees as Scholars to be Tracked</label><br/>
                <input type='radio' name='action' id='actionFaculty' value='importFaculty' /> <label for='actionFaculty'>Import Only Faculty as Scholars to be Tracked</label><br/>
                <input type='radio' name='action' id='actionBoth' value='importBoth' /> <label for='actionBoth'>Import Both Trainees and Faculty as Scholars to be Tracked</label>
            </p>
            <p class='max-width'>
                <select name='tableNumber'>
                    <option value=''>---SELECT TABLE---</option>
                    <option value='5'>Table 5</option>
                    <option value='8'>Table 8 (except Part IV)</option>
                </select>
            </p>
            <p class='max-width'><input type='hidden' name='MAX_FILE_SIZE' value='3000000' />
                Upload CSV (with headers in first row): <input type='file' name='tableCSV'><br/>
                <button>Process File</button>
            </p></form>
    </div>
<?php
}

function processLines($lines, $nextRecordId, $token, $server, $mentorUids, $allNewRecords) {
	$upload = [];
	$lineNum = 1;
	$metadataFields = Download::metadataFields($token, $server);
	$recordIds = [];
    $names = Download::names($token, $server);
    $messagesSent = FALSE;
	foreach ($lines as $nodes) {
	    $mentorUid = $mentorUids[$lineNum - 1];
        if (is_array($mentorUid)) {
            $mentorUid = implode(", ", $mentorUid);
        }
        if (
            ($nodes[0] || $nodes[3])
            && (!$nodes[0] || !$nodes[3])
        ) {
            throw new \Exception("Both a first name and a last name are required! Error on line $lineNum");
        }
		if ((count($nodes) >= 6) && $nodes[0] && $nodes[3]) {
			$firstName = $nodes[0];
			$middle = $nodes[2];
			$lastName = $nodes[3];
			$preferred = $nodes[1];
			$recordId = NameMatcher::matchName($firstName, $lastName, $token, $server);
			if (!$recordId || $allNewRecords) {
				#new
				$recordId = $nextRecordId;
				$nextRecordId++;
			} else {
                $name = $names[$recordId] ?? "";
                $messagesSent = TRUE;
                echo "<p class='centered nomargin'>Matched $firstName $lastName with Record $recordId ($name)</p>";
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
				if (preg_match("/female/i", $nodes[6]) || (strtolower($nodes[6]) == "f")) {
					$gender = 1;
				} else if (preg_match("/^male/i", $nodes[6]) || (strtolower($nodes[6]) == "m")) {
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
	return [$messagesSent, $upload, $recordIds];
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
        if (isset($line) && isset($line[$mentorCol]) && trim($line[$mentorCol])) {
            $currentUids = [];
            if (preg_match("/[,;]/", $line[$mentorCol])) {
                if (NameMatcher::isLastNameFirst($line[$mentorCol])) {
                    list($mentorFirst, $mentorLast) = NameMatcher::splitName($line[$mentorCol], 2);
                    $currentUids = getUidsForName($mentorFirst, $mentorLast);
                } else {
                    # multiple names
                    $mentorNames = preg_split("/\s*[,;]\s*/", $line[$mentorCol]);
                    foreach ($mentorNames as $mentorName) {
                        if ($mentorName) {
                            list($mentorFirst, $mentorLast) = NameMatcher::splitName($mentorName, 2);
                            $currentUids[$mentorName] = getUidsForName($mentorFirst, $mentorLast);
                        }
                    }
                }
            } else {
                list($mentorFirst, $mentorLast) = NameMatcher::splitName($line[$mentorCol], 2);
                $currentUids = getUidsForName($mentorFirst, $mentorLast);
            }
            if (!empty($currentUids)) {
                $mentorUids[$i] = $currentUids;
            }
        }
    }
    return $mentorUids;
}

function getUidsForName($first, $last) {
    $lookup = new REDCapLookup($first, $last);
    $currentUids = $lookup->getUidsAndNames();
    if (count($currentUids) == 0) {
        $lookup = new REDCapLookup("", $last);
        $currentUids = $lookup->getUidsAndNames();
    }
    return $currentUids;
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
    $html .= "<form action='$url' id='mainForm' method='POST'>";
    $html .= Application::generateCSRFTokenHTML();
    $html .= "<table class='bordered centered max-width'>";
    $html .= "<thead>";
    $html .= "<tr><th>".implode("</th><th>", $headers)."</th></tr>";
    $html .= "</thead>";
    $html .= "<tbody>";
    for ($i = 0; $i < count($lines); $i++) {
        $currLine = $lines[$i];
        $json = json_encode($currLine);
        $hiddenHTML = "<input type='hidden' name='line___$i' value='$json'>";
        $currMentorUids = $mentorUids[$i] ?? [];
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
            if (is_array($existingUids[$i])) {
                foreach ($existingUids[$i] as $j => $value) {
                    $html .= "<input type='hidden' name='mentor___{$i}_$j' value='".preg_replace("/'/", "\\'", $value)."'>";
                }
            } else {
                $html .= "<input type='hidden' name='mentor___$i' value='".preg_replace("/'/", "\\'", $existingUids[$i])."'>";
            }
        } else if ($currMentorName) {
            $foundMentor = TRUE;
            $html .= "<tr>";
            $html .= "<th>{$currLine[0]} {$currLine[3]}$hiddenHTML</th>";
            $html .= "<td>$currMentorName</td>";
            $html .= processMentorName($currMentorName, $currMentorUids, $i, $customLine, $customHidden, $customRadio);
            $html .= "</tr>";
        } else {
            $html .= $hiddenHTML;
        }
    }
    $html .= "</tbody>";
    $html .= "</table>";
    $html .= "<p class='centered'><button>Add Mentors</button></p>";
    $html .= "</form>";

    $html .= "<script>
$(document).ready(() => {
    if ($('.mentorKeep').length > 0) {
        $('form#mainForm').prepend('<p class=\"centered\"><button class=\"green\" onclick=\"checkAllMentors(); return false;\">Check All for Uploading Unmatched Mentors</button></p>');
    }
});

function checkAllMentors() {
    $('.mentorKeep').attr('checked', true);
}
</script>";


    if ($foundMentor) {
        return $html;
    } else {
        return "";
    }
}

function processMentorName($currMentorName, $currMentorUids, $i, $customLine, $customHidden, $customRadio) {
    error_log("processing Mentor Name $currMentorName");
    $html = "";

    if (count($currMentorUids) == 0) {
        $escapedMentorName = preg_replace("/'/", "\\'", $currMentorName);
        $hiddenField = "<input type='hidden' name='originalmentorname___$i' value='$escapedMentorName'>";
        $mentorKeep = "mentorkeep___$i";
        $html .= "<td class='red'>";
        $html .= "<strong>No names in REDCap matched with $currMentorName.</strong><br>Keep mentor? <input type='radio' class='mentorKeep' name='$mentorKeep' id='$mentorKeep' value='1' /> <label for='$mentorKeep'> Yes, upload anyways</label><br>Or perhaps there is a nickname and/or a maiden name at play here. Do you want to try adjusting their name?<br>$hiddenField<input type='text' name='newmentorname___$i' value='$escapedMentorName'><br>";
        $html .= "<br>Or try a custom id?<br>".$customLine.$customHidden;
        $html .= "</td>";
    } else if (count($currMentorUids) == 1) {
        $key = array_keys($currMentorUids)[0];
        if (is_array($currMentorUids[$key])) {
            $individualMentorName = $key;
            $html .= processMentorName($individualMentorName, $currMentorUids[$individualMentorName], $i."_1", $customLine, $customHidden, $customRadio);
        } else {
            $uid = $key;
            $yesId = "mentor___$i" . "___yes";
            $noId = "mentor___$i" . "___no";
            $yesno = "<input type='radio' name='mentor___$i' id='$yesId' value='$uid' checked> <label for='$yesId'>Yes</label><br>";
            $yesno .= "<input type='radio' name='mentor___$i' id='$noId' value=''> <label for='$noId'>No</label><br>";
            $yesno .= $customRadio." ".$customLine;
            $html .= "<td class='green'>Matched: $uid<br>$yesno</td>";
        }
    } else {
        $firstKey = array_keys($currMentorUids)[0];
        if (is_array($currMentorUids[$firstKey])) {
            $j = 1;
            $html .= "<td class='yellow'>";
            foreach ($currMentorUids as $individualMentorName => $uidChoices) {
                if ($j > 1) {
                    $html .= "<hr>";
                }
                $html .= processMentorName($individualMentorName, $uidChoices, $i."_".$j, $customLine, $customHidden, $customRadio);
                $j++;
            }
            $html .= "</td>";
        } else {
            $radios = [];
            $noId = "mentor___$i" . "___no";
            $radios[] = "<input type='radio' name='mentor___$i' id='$noId' value='' checked /> <label for='$noId'>None of the Above</label>";
            foreach ($currMentorUids as $uid => $mentorName) {
                $id = "mentor___" . $i . "___" . $uid;
                $mentorEmail = REDCapLookup::getUserInfo($uid)["user_email"] ?? "";
                $radios[] = "<input type='radio' name='mentor___$i' id='$id' value='$uid' /> <label for='$id'>$mentorName ($uid<br/>$mentorEmail)</label>";
            }
            $radios[] = $customRadio." ".$customLine;
            $html .= "<td class='yellow'>" . implode("<br>", $radios) . "</td>";
        }
    }
    return $html;
}

function parsePostForLines($post) {
    $lines = [];
    $mentorUids = [];
    $newMentorNames = [];
    $multiMentors = [];
    $mentorCol = 13;
    $mentorCustomCode = "mentor___custom";
    foreach ($post as $key => $value) {
        $key = REDCapManagement::sanitize($key);
        if (preg_match("/^line___\d+$/", $key)) {
            $value = REDCapManagement::sanitizeJSON($value);
            $i = preg_replace("/^line___/", "", $key);
            $values = json_decode($value);
            $lines[$i] = $values;
            if ($post["mentorname___" . $i]) {
                $lines[$i][$mentorCol] = $post["mentorname___" . $i];
            }
        }
    }
    foreach ($post as $key => $value) {
        $key = REDCapManagement::sanitize($key);
        $value = REDCapManagement::sanitize($value);
        if (preg_match("/^mentor___[\d_]+$/", $key) && ($value != $mentorCustomCode)) {
            $i = preg_replace("/^mentor___/", "", $key);
            if (preg_match("/_/", $i)) {
                assignFromParts($mentorUids, $i, $value);
            } else {
                $mentorUids[$i] = $value;
            }
        } else if (preg_match("/^newmentorname___[\d_]+$/", $key)) {
            $i = preg_replace("/^newmentorname___/", "", $key);
            $origMentorName = $post['originalmentorname___'.$i];
            if (preg_match("/_/", $i)) {
                assignFromParts($multiMentors, $i, $origMentorName);
                if ($post['mentorkeep___'.$i]) {
                    $parts = explode("_", $i);
                    $maxForPart = findMaxForPart($post, $parts);
                    $keepAll = keepAllMentors($post, $parts[0], $maxForPart);
                    if (!$keepAll) {
                        assignFromParts($newMentorNames, $i, $origMentorName);
                    }
                } else {
                    assignFromParts($newMentorNames, $i, $value);
                }
            } else if ($post['mentorkeep___'.$i]) {
                $lines[$i][$mentorCol] = $origMentorName;
            } else if ($origMentorName != $value) {
                $newMentorNames[$i] = $value;
            }
        } else if (preg_match("/^mentorcustom___[\d_]+$/", $key) && $value) {
            $i = preg_replace("/^mentorcustom___/", "", $key);
            if ($post['mentor___'.$i] == $mentorCustomCode) {
                if (preg_match("/_/", $i)) {
                    assignFromParts($mentorUids, $i, $value);
                } else {
                    $mentorUids[$i] = $value;
                }
            }
        }
    }
    foreach ($lines as $i => $line) {
        if (isset($multiMentors[$i])) {
            ksort($multiMentors[$i]);
            $lines[$i][$mentorCol] = implode(", ", array_values($multiMentors[$i]));
        }
    }

    ksort($lines);
    ksort($mentorUids);
    foreach ($mentorUids as $i => $uid) {
        if (is_array($uid)) {
            ksort($mentorUids[$i]);
        }
    }
    ksort($newMentorNames);
    foreach ($newMentorNames as $i => $name) {
        if (is_array($name)) {
            ksort($newMentorNames[$i]);
        }
    }
    return [$lines, $mentorUids, $newMentorNames];
}

function keepAllMentors($post, $parts0, $maxForPart) {
    for ($j2 = 1; $j2 <= $maxForPart; $j2++) {
        if (!$post["mentorkeep___{$parts0}_".$j2]) {
            return FALSE;
        }
    }
    return TRUE;
}

function findMaxForPart($post, $parts1) {
    $maxForPart = $parts1[1];
    foreach (array_keys($post) as $key2) {
        $key2 = REDCapManagement::sanitize($key2);
        if (preg_match("/^originalmentorname___{$parts1[0]}_/", $key2)) {
            $i2 = preg_replace("/^originalmentorname___/", "", $key2);
            $parts2 = explode("_", $i2);
            if ($parts2[1] > $maxForPart) {
                $maxForPart = $parts2[1];
            }
        }
    }
    return $maxForPart;
}

function assignFromParts(&$ary, $i, $value) {
    $parts = explode("_", $i);
    if (!isset($ary[$parts[0]])) {
        $ary[$parts[0]] = [];
    }
    $ary[$parts[0]][$parts[1]] = $value;
}

function commitChanges($token, $server, $lines, $mentorUids, $pid, $createRecordsURI) {
    $maxRecordId = 0;
    $recordIds = Download::recordIds($token, $server);
    foreach ($recordIds as $recordId) {
        if ($recordId > $maxRecordId) {
            $maxRecordId = $recordId;
        }
    }
    $recordId = $maxRecordId + 1;

    $upload = [];
    $messagesSent = FALSE;
    $mssg = "";
    try {
        list($messagesSent, $upload, $newRecordIds) = processLines($lines, $recordId, $token, $server, $mentorUids, $createRecordsURI);
        $feedback = [];
        if (!empty($upload)) {
            $feedback = Upload::rows($upload, $token, $server);
            foreach ($newRecordIds as $recordId) {
                Application::refreshRecordSummary($token, $server, $pid, $recordId);
            }
        } else {
            $mssg = "No data specified.";
        }
        if (isset($feedback["error"])) {
            $mssg = "People not added ". $feedback['error'];
        }
    } catch (\Exception $e) {
        $mssg = $e->getMessage();
    }
    if ($mssg) {
        $link = Application::link("this").$createRecordsURI;
        exitProcess($mssg, $link);
        return;
    }

    $timespan = 3;
    echo "<h1>Adding New Scholars or Modifying Existing Scholars</h1>";
    echo "<div style='margin: 0 auto; max-width: 800px'>";
    echo "<p class='centered'>".count($upload).(count($upload) == 1 ? " person" : " people")." added/modified.</p>";
    $link = Application::link("index.php");
    if (!$messagesSent) {
        echo "<p class='centered'>Going to Flight Tracker Central in ".$timespan." seconds...</p>";
        echo refreshScript($timespan, $link);
    } else {
        echo "<p class='centered'><a href='$link'>Go to Flight Tracker Central</a></p>";
    }
    echo "</div>";
}

function refreshScript($timespan, $link) {
    $html = "<script>\n";
    $html .= "$(document).ready(function() {\n";
    $html .= "\tsetTimeout(function() {\n";
    $html .= "\t\twindow.location.href='$link';\n";
    $html .= "\t}, ".floor($timespan * 1000).");\n";
    $html .= "});\n";
    $html .= "</script>\n";
    return $html;
}

function exitProcess($mssg, $link) {
    echo "<p class='red centered'>$mssg</p>";
    echo "<p class='centered'><a href='$link'>Restart Process</a></p>";
}