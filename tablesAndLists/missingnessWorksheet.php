<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Scholar;
use \Vanderbilt\CareerDevLibrary\Result;

require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../classes/Links.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Scholar.php");

require_once(dirname(__FILE__)."/../small_base.php");
?>

<style>
body { font-family: Arial, Helvetica, sans-serif; }
.underline { height: 50px; border-bottom: 1px solid black; }
@font-face { font-family: 'Museo Sans'; font-style: normal; font-weight: normal; src: url('../fonts/exljbris - MuseoSans-500.otf'); }
h1,h2,h3,h4,h5 { font-family: "Museo Sans"; }
table { page-break-after: always; }
th { text-align: left; padding: 4px; }
</style>

<?php
$skip = array("identifier_institution", "summary_left_vanderbilt", "summary_survey", "summary_mentor");
$_GLOBALS['skip'] = $skip;

$metadata = Download::metadata($token, $server);
$choices = Scholar::getChoices($metadata);

if ($_GET['record']) {
	$records = array($_GET['record']);
} else if ($_GET['cohort']) {
	$names = Download::names($token, $server);
	$cohortRecords = Download::cohortRecordIds($token, $server, $metadata, $_GET['cohort']);
	$records = array();
	foreach ($names as $record => $name) {
		if (in_array($record, $cohortRecords)) {
			array_push($records, $record);
		}
	}
} else {
	$names = Download::names($token, $server);
	$records = array_keys($names);
}

$fields = array(
		"identifier_email",
		"summary_degrees",
		"summary_gender",
		"summary_race_ethnicity",
		"summary_dob",
		"summary_citizenship",
		"summary_race_ethnicity",
		"summary_primary_dept",
		"summary_current_division",
		"summary_current_rank",
		"summary_current_start",
		"summary_current_tenure",
		"summary_mentor",
		);
$addlFields = array();
foreach ($fields as $field) {
	array_push($addlFields, $field);
	if ($field == "summary_race_ethnicity") {
		$field = "summary_race";
		$addlFields[] = $field."_sourcetype";
		$addlFields[] = $field."_source";
		$field = "summary_ethnicity";
		$addlFields[] = $field."_sourcetype";
		$addlFields[] = $field."_source";
	} else {
		$addlFields[] = $field."_sourcetype";
		$addlFields[] = $field."_source";
	}
}
$nameFields = array("record_id", "identifier_last_name", "identifier_first_name");
$shortSummaryFields = array_unique(array_merge($nameFields, $addlFields));

$orders = Scholar::getDefaultOrder("all");
$scholar = new Scholar($token, $server, $metadata, $pid);
foreach ($orders as $field => $defaultOrder) {
	$order = $scholar->getOrder($defaultOrder, $field);
	foreach ($order as $sourceField => $sourceType) {
		if (!in_array($sourceField, $shortSummaryFields)) {
			array_push($shortSummaryFields, $sourceField);
		}
	}
}

$filteredSummaryFields = \Vanderbilt\FlightTrackerExternalModule\filterFields($shortSummaryFields, $metadata);

foreach ($records as $record) {
	$recordData = Download::fieldsForRecords($token, $server, $filteredSummaryFields, array($record));
	$html = "";
	$html .= "<h1>Missingness Worksheet</h1>\n";

	$html .= "<table style='max-width: 100%;'>";
	$html .= "<tbody>\n";
	$worksheetHTML = generateWorksheetColumns($recordData, $orders, $metadata);
	$html .= $worksheetHTML;
	for ($i = 1; $i <= 4; $i++) {
		$html .= "<tr><td colspan='2' class='underline'></tr>\n";
	}
	$html .= "</tbody>\n";
	$html .= "</table>\n";

	if ($worksheetHTML) {
		echo $html;
	}
}

function generateWorksheetColumns($data, $orders, $metadata) {
	global $token, $server, $pid;
	global $skip;

	$maxSpaces = 100;
	$sourceTypes = array(
				"" => "",
				"0" => "Computer Generated",
				"1" => "Self-Reported",
				"2" => "Manually Entered",
				);

	$html = "";
	$html .= "<tr><th>Record ID</th><td style='min-width: 400px;'>".findValue("record_id", $data)."</td></tr>\n";
	$html .= "<tr><th>Name</th><td>".findValue("identifier_first_name", $data)." ".findValue("identifier_last_name", $data)."</td></tr>\n";

	$numRows = 0;
	$scholar = new Scholar($token, $server, $metadata, $pid);
	$choices = Scholar::getChoices($metadata);
	foreach ($orders as $field => $defaultOrder) {
		if (in_array($field, $skip)) {
			continue;
		}
		$order = $scholar->getOrder($defaultOrder, $field);
		$metadataRow = \Vanderbilt\FlightTrackerExternalModule\getMetadataRow($field, $metadata);
		$fieldLabel = $metadataRow['field_label'];
		if ($field == "summary_degrees") {
			if (!findValue($field, $data)) {
				$numRows++;
				$html .= "<tr><td colspan='2'><b>$fieldLabel</b><br>";
				$html .= "<ul>";
				for ($i = 1; $i <= 3; $i++) {
					$html .= "<li>Degree $i: <span class='underline'>";
					for ($j = 0; $j < $maxSpaces; $j++) {
						$html .= "&nbsp;";
					}
					$html .= "</span></li>\n";
				}
				$html .= "</ul>";
				$html .= "</td></tr>\n";
				$html .= generateSourcePromptRow($fieldLabel, $sourceTypes);
			}
		} else if ($field == "summary_race_ethnicity") {
			if (!findValue($field, $data)) {
				$numRows++;
				$html .= "<tr><td colspan='2'><b>$fieldLabel</b><br>";
				$html .= "<ul>\n";
				foreach ($choices[$field] as $value => $label) {
					if ($label == "Other") {
						$html .= "<li style='list-style-type: circle;'>Other <span class='underline'>";
						for ($j = 0; $j < $maxSpaces; $j++) {
							$html .= "&nbsp;";
						}
						$html .= "</span></li>\n";
					} else {
						$html .= "<li style='list-style-type: circle;'>$label</li>\n";
					}
				}
				$html .= "</ul></td></tr>\n";
				$html .= generateSourcePromptRow($fieldLabel, $sourceTypes);
			}
		} else {
			$value = findValue($field, $data);
			if ($value) {
				if (isset($choices[$sourceField])) {
					$value = $choices[$field][$value];
				}
				if ($metadataRow['text_validation_type_or_show_slider_number'] == "date_ymd") {
					$value = \Vanderbilt\FlightTrackerExternalModule\YMD2MDY($value);
				}
			}
			if (!$value) {
				$numRows++;
				$html .= "<tr><th>$fieldLabel</th><td class='underline'></td></tr>\n";
				$html .= generateSourcePromptRow($fieldLabel, $sourceTypes);
			}

			// if ($field == "summary_current_rank") {
				// $html .= "<tr><th>Position at start?</th><td class='underline'></td></tr>\n";
				// $html .= "<tr><td colspan='2'><b>Any Promotions? If yes, position at:<br>";
				// $html .= "<ul>";
				// for ($i = 1; $i <= 3; $i++) {
					// $html .= "<li>Promotion $i: ";
					// for ($j = 0; $j < $maxSpaces; $j++) {
						// $html .= "&nbsp;";
					// }
					// $html .= "</li>\n";
				// }
				// $html .= "</ul>";
				// $html .= "</td></tr>\n";
			// }
		}
	}
	if ($numRows > 0) {
		return $html;
	} else {
		return "";
	}
}

function generateSourcePromptRow($label, $sourceTypes) {
	$html = "";
	$html .= "<tr><td>$label Source:</td><td>";
	foreach ($sourceTypes as $idx => $sourceType) {
		if ($sourceType) {
			$html .= "o $sourceType";
			for ($i = 1; $i <= 5; $i++) {
				$html .= "&nbsp;";
			}
		}
	}
	return $html;
}

function findValue($field, $data) {
	foreach ($data as $row) {
		if (isset($row[$field]) && ($row[$field] != "")) {
			return $row[$field];
		}
	}
	return "";
}
