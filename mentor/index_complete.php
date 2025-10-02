<?php

namespace Vanderbilt\CareerDevLibrary;

require_once dirname(__FILE__)."/preliminary.php";
require_once dirname(__FILE__)."/../small_base.php";
require_once dirname(__FILE__)."/base.php";
require_once(dirname(__FILE__)."/../classes/Autoload.php");

if (isset($_GET['menteeRecord'])) {
	$records = Download::recordIds($token, $server);
	$menteeRecordId = REDCapManagement::getSanitizedRecord($_GET['menteeRecord'], $records);
} else {
	throw new \Exception("You must specify a mentee record!");
}

if ($_GET['uid']) {
	$username = REDCapManagement::sanitize($_GET['uid']);
	$trailingUidString = "&uid=$username";
	$spoofing = MMAHelper::makeSpoofingNotice($username);
} elseif ($hash) {
	$username = $hash;
	$trailingUidString = "&hash=$hash&menteeRecordId=$menteeRecordId";
	$userids = [];
	$spoofing = "";
} else {
	$username = Application::getUsername();
	$trailingUidString = "";
	$userids = Download::userids($token, $server);
	$spoofing = "";
}

require_once dirname(__FILE__).'/_header.php';

list($myMentees, $myMentors) = MMAHelper::getMenteesAndMentors($menteeRecordId, $username, $token, $server);
if (isset($_GET['test'])) {
	echo "myMentees: ".json_encode($myMentees)."<br>";
	echo "myMentors: ".json_encode($myMentors)."<br>";
}

if (isset($_GET['instance'])) {
	$instance = REDCapManagement::sanitize($_GET['instance']);
} else {
	throw new \Exception("You must specify an instance");
}
list($firstName, $lastName) = MMAHelper::getNameFromREDCap($username, $token, $server);
$metadataFields = Download::metadataFieldsByPidWithPrefix($pid, MMAHelper::PREFIX);
$metadata = MMAHelper::getMetadata($pid, $metadataFields);
$allMetadataForms = REDCapManagement::getFormsFromMetadata($metadata);
$notesFields = MMAHelper::getNotesFields($metadataFields);
$choices = REDCapManagement::getChoices($metadata);
$redcapData = Download::fieldsForRecordsByPid($pid, array_merge(["record_id", "mentoring_userid", "mentoring_last_update"], $metadataFields), [$menteeRecordId]);
$row = MMAHelper::pullInstanceFromREDCap($redcapData, $instance);
unset($row['mentoring_custom_question_json']);
unset($row['mentoring_custom_question_json_admin']);
#into values the same as current fields in $row.
#This code could most likely be reused in index_mentorview
$date = "";
$menteeInstance = false;
if ($hash) {
	$menteeInstance = 1;
} else {
	$userids = Download::userids($token, $server);
	$menteeUsernames = MMAHelper::getMenteeUserids($userids[$menteeRecordId] ?? "");
	foreach ($menteeUsernames as $menteeUsername) {
		$menteeInstance = MMAHelper::getMaxInstanceForUserid($redcapData, $menteeRecordId, $menteeUsername);
		if ($menteeInstance) {
			break;
		}
	}
}
$menteeRow = $menteeInstance ? REDCapManagement::getRow($redcapData, $menteeRecordId, MMAHelper::INSTRUMENT, $menteeInstance) : [];
$listOfMentors = REDCapManagement::makeConjunction(array_values($myMentors["name"] ?? []));
if ($hash) {
	$listOfMentees = REDCapManagement::makeConjunction($myMentees["name"] ?? []);
} elseif (MMAHelper::isMentee($menteeRecordId, $username)) {
	$listOfMentees =  $firstName." ".$lastName;
} else {
	$menteeName = Download::fullName($token, $server, $menteeRecordId);
	if ($menteeName) {
		$listOfMentees = $menteeName;
	} else {
		$listOfMentees = "[Mentee]";
	}
}
$dateToRevisit = MMAHelper::getDateToRevisit($redcapData, $menteeRecordId, $instance);
if ($hash) {
	$revisitText = "";
	$exampleDates = "";
} else {
	$revisitText = "<p style='text-align: center;'>We will revisit this agreement on-or-around $dateToRevisit.</p>";
	$exampleDates = " (e.g., 6, 12, and 18 months from now, as well as on an as needed basis)";
}


?>
    <link rel="stylesheet" href="<?= Application::link("css/jquery.sweet-modal.min.css") ?>" />
    <script src="<?= Application::link("js/jquery.sweet-modal.min.js") ?>"></script>
<section class="bg-light">
    <div style="text-align: center;" class="smaller"><a href="javascript:;" onclick="window.print();">Click here to print or save as PDF</a></div>
  <div class="container">
    <div class="row">
      <div class="col-lg-12" style="">
        <h2 style="text-align: center;font-weight: 500;font-family: din-2014, sans-serif;font-size: 32px;letter-spacing: -1px;">Mentorship Agreement<br>between
        <?= $listOfMentees ?> and <?= $listOfMentors ?><br>
        <?= REDCapManagement::YMD2MDY(REDCapManagement::findField($redcapData, $menteeRecordId, "mentoring_last_update")) ?></h2>

            <p style="text-align: center;width: 80%;margin: auto;margin-top: 2em;">
            <span style="text-decoration: underline;"><?= $listOfMentees ?> (mentee)</span> and <span style="text-decoration: underline;"><?= $listOfMentors ?> (mentor)</span> do hereby enter into a formal mentoring agreement. The elements of the document below provide evidence that a formal discussion has been conducted by the Mentor and Mentee together, touching on multiple topics that relate to the foundations of a successful training relationship for both parties. Below are key elements which we discussed at the start of our Mentee-Mentor Relationship. These elements, and others, also provide opportunities for further and/or new discussions together at future time points<?= $exampleDates ?>.
            </p>

          <?= $revisitText ?>
          <p style="text-align: center;" class="smaller"><a href="javascript:;" class="notesShowHide" onclick="toggleAllNotes(this);">hide all chatter</a></p>

          <?php

		  $htmlRows = [];
$closing = "</ul></div></p>";
$noInfo = "No Information Specified.";
$hasRows = false;
$skipFieldTypes = ["file", "text"];
$index = 0;
foreach ($metadata as $metadataRow) {
	$index++;
	if ($metadataRow['field_type'] == "descriptive") {
		continue;
	}
	$metadataRow['field_label'] = preg_replace("/:$/", "", $metadataRow["field_label"]);
	if ($metadataRow['section_header']) {
		list($sec_header, $sec_descript) = MMAHelper::parseSectionHeader($metadataRow['section_header']);
		if (!empty($htmlRows)) {
			if (!$hasRows) {
				array_pop($htmlRows);
				array_pop($htmlRows);
				array_pop($htmlRows);
				// $htmlRows[] = "<div>$noInfo</div>";
			} else {
				$htmlRows[] = $closing;
			}
		}
		$htmlRows[] = '<p class="catquestions">';
		$htmlRows[] = "<div class='categ'>$sec_header</div>";
		$htmlRows[] = '<div style="width: 100%;"><ul>';
		$hasRows = false;
	}
	$field = $metadataRow['field_name'];
	$possibleNotesField = $field."_notes";
	if (preg_match("/@HIDDEN/", $metadataRow['field_annotation'])) {
		continue;
	} elseif ($row[$field] && !in_array($field, $notesFields) && !in_array($metadataRow['field_type'], $skipFieldTypes)) {
		$value = "";
		if ($choices[$field] && $choices[$field][$row[$field]]) {
			$value = $choices[$field][$row[$field]];
		} elseif ($row[$field] !== "") {
			$value = preg_replace("/\n/", "<br>", $row[$field]);
			$value = preg_replace("/<script>.+<\/script>/", "", $value);   // security
		}

		$notesText = "";
		if ($menteeRow[$possibleNotesField]) {
			$notesText = "<div class='smaller notesText'>".preg_replace("/\n/", "<br>", $menteeRow[$possibleNotesField])."</div><!-- <div class='smaller'><a href='javascript:;' class='notesShowHide' onclick='showHide(this);'>hide chatter</a></div> -->";
		}
		if ($metadataRow['field_type'] == "descriptive") {
			$htmlRows[] = MMAHelper::stripPiping($metadataRow['field_label']);
		} elseif ($metadataRow['field_type'] == "notes") {
			if (!preg_match("/^mentoring_custom_question_readable/", $metadataRow['field_name'])) {
				$htmlRows[] = "<li>".MMAHelper::stripPiping($metadataRow['field_label']).":<br><span>".$value."</span></li>";
			} else {
				$htmlRows[] = "<li><span>".$value."</span></li>";
			}
		} else {
			$htmlRows[] = "<li>".MMAHelper::stripPiping($metadataRow['field_label']).": <span>".$value."</span>$notesText</li>";
		}
		$hasRows = true;
	} elseif (($metadataRow['field_type'] == "file") && ($metadataRow['text_validation_type_or_show_slider_number'] == "signature")) {
		$dateField = $field."_date";
		if ($row[$field]) {
			$base64 = MMAHelper::getBase64OfFile($menteeRecordId, $instance, $field, $pid);
			if ($row[$dateField]) {
				$date = "<br>".REDCapManagement::YMD2MDY($row[$dateField]);
			} else {
				$date = "";
			}
			if ($base64) {
				$htmlRows[] = "<li>".MMAHelper::stripPiping($metadataRow['field_label']).":<br><img src='$base64' class='signature' alt='signature'><div class='signatureDate'>$date</div></li>";
			} else {
				$htmlRows[] = "<li>".MMAHelper::stripPiping($metadataRow['field_label']).": ".$row[$field]."</li>";
			}
		} else {
			$date = date("m-d-Y");
			$ymdDate = date("Y-m-d");
			$htmlRows[] = "<li>".MMAHelper::stripPiping($metadataRow['field_label']).":<br><div class='signature' id='$field'></div><div class='signatureDate'>$date</div><button onclick='saveSignature(\"$field\", \"$ymdDate\");'>Save</button> <button onclick='resetSignature(\"#$field\");'>Reset</button></li>";
			$htmlRows[] = "<script>
                            $(document).ready(function() {
                                $('#$field').jSignature();
                            });
                            </script>";
		}
		$hasRows = true;
	} elseif ($metadataRow['field_type'] == "checkbox") {
		$notesText = "";
		if ($menteeRow[$possibleNotesField]) {
			$notesText = "<div class='smaller notesText'>".preg_replace("/\n/", "<br>", $menteeRow[$possibleNotesField])."</div><!-- <div class='smaller'><a href='javascript:;' class='notesShowHide' onclick='showHide(this);'>hide chatter</a></div> -->";
		}
		$values = [];
		foreach ($row as $f => $v) {
			if (($v == "1") && preg_match("/^$field"."___/", $f)) {
				$index = explode("___", $f)[1];
				$label = $choices[$field][$index] ?? "";
				if ($label) {
					$values[] = $label;
				}
			}
		}
		if (!empty($values)) {
			$htmlRows[] = "<li>".MMAHelper::stripPiping($metadataRow['field_label']).": <span>".implode("; ", $values)."</span>$notesText</li>";
			$hasRows = true;
		}
	}
}
if (!$hasRows) {
	array_pop($htmlRows);
	array_pop($htmlRows);
	array_pop($htmlRows);
	// $htmlRows[] = "<div>$noInfo</div>";
} else {
	$htmlRows[] = $closing;
}
echo implode("\n", $htmlRows)."\n";

?>
      </div>
    </div>
  </div>
</section>

<?php
echo MMAHelper::getCompleteHead($trailingUidString, $menteeRecordId, $instance);
include dirname(__FILE__).'/_footer.php';
?>
