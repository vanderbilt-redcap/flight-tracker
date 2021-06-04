<?php

use \Vanderbilt\CareerDevLibrary\Download;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/base.php");

$metadata = Download::metadata($token, $server);
$surveys = \Vanderbilt\FlightTrackerExternalModule\getSurveys($metadata);

if ($_POST['name']) {
	$name = $_POST['name'];
	if (!$surveys[$name]) {
		addNewSurvey($name, $metadata, $surveys);
		echo "Added survey $name.";
	} else {
		echo "ERROR: Survey name is already used!";
	}
} else {
	if (!is_array($surveys)) { $surveys = json_decode($surveys, true); }
	$notes = "<p class='centered notes' style='display: none;'></p>\n";

	echo "<div>\n"; // id='content'
	echo "<h1>Add a Survey</h1>\n";

	echo "<h3>Current Surveys</h3>\n";
	foreach ($surveys as $name => $dateField) {
		echo "<p class='centered'>$name</p>\n";
	}
	if (empty($surveys)) {
		echo "<p class='centered'>No surveys are currently set up.</p>\n";
	}

	echo "<h2>New Survey</h2>\n";
	echo "<p class='centered'>Name: <input type='text' id='name' value=''></p>\n";
	echo "<p class='centered'><button onclick='addSurvey(); return false;'>Add a New Survey</button></p>\n";
	echo $notes;

	echo "</div>\n";

?>
<script>
function addSurvey() {
	var name = $('#name').val();
	if (name) {
		$.post("add.php?pid=<?= $pid ?>", { name: name }, function(html) {
			$(".notes").html(html);
			var possibleClasses = [ "red", "green" ];
			for (var i = 0; i < possibleClasses.length; i++) {
				if ($(".notes").hasClass(possibleClasses[i])) {
					$(".notes").removeClass(possibleClasses[i]);
				}
			}
			if (html.match(/error/i)) {
				$(".notes").addClass("red");
			} else {
				$(".notes").addClass("green");
			}
		});
	} else {
		alert('You must specify a name for the survey!');
	}
}
</script>
<?php
}

function addNewSurvey($name, $title, $metadata, $surveys) {
	global $token, $server, $pid;
	$surveyName = preg_replace("/\s+/", "_", strtolower($name));
	$surveyName = preg_replace("/\W+/", "", $surveyName);
	$dateField = $surveyName."_date";

	$newMetadata = $metadata;
	array_push($newMetadata, createNewRow($surveyName."_first_name", $surveyName, "First Name"));
	array_push($newMetadata, createNewRow($surveyName."_last_name", $surveyName, "Last Name"));
	array_push($newMetadata, createNewRow($dateField, $surveyName, "Date Filled Out", "date_ymd", "@TODAY @HIDDEN-SURVEY"));

	$feedback = Upload::metadata($newMetadata, $token, $server);
	$instructions = "Please fill out the following survey to help us track our people better.";
	$acknowledgement = "Thank you for taking the survey!";

	$surveys[$name] = $dateField;
	saveEmailSetting("surveys", json_encode($surveys), $newMetadata);

	# enable as survey
	$sql = "INSERT INTO redcap_surveys (project_id, form_name, title, instructions, acknowledgement, question_by_section, display_page_number, question_auto_numbering, survey_enabled) VALUES('".db_real_escape_string($pid)."', '".db_real_escape_string($surveyName)."', '".db_real_escape_string($title)."', '".db_real_escape_string($instructions)."', '".db_real_escape_string($acknowledgement)."', 0, 1, 0, 1)";
	db_query($sql);
	$error = db_error();
	if ($error) {
		throw new \Exception("Your program has a SQL error:<br>".$error.".<br><br>The error is in the following SQL statement:<br>".$sql); 
	}
}

function createNewRow($fieldName, $formName, $fieldLabel, $validation = "", $annotation = "") {
	return array(
			"field_name" => $fieldName,
			"form_name" => $formName,
			"section_header" => "",
			"field_type" => "text",
			"field_label" => $fieldLabel,
			"select_choices_or_calculations" => "",
			"field_note" => "",
			"text_validation_type_or_show_slider_number" => $validation,
			"text_validation_min" => "",
			"text_validation_max" => "",
			"identifier" => "",
			"branching_logic" => "",
			"required_field" => "",
			"custom_alignment" => "",
			"question_number" => "",
			"matrix_group_name" => "",
			"matrix_ranking" => "",
			"field_annotation" => $annotation,
			);
}

?>
