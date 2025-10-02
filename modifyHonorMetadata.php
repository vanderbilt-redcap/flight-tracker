<?php

# for use with hooks/honorHook.php

use Vanderbilt\CareerDevLibrary\Sanitizer;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use Vanderbilt\CareerDevLibrary\Upload;

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

$affectedFields = DataDictionaryManagement::HONORACTIVITY_SPECIAL_FIELDS;
$records = Download::recordIdsByPid($pid);
$field = Sanitizer::sanitize($_POST['field'] ?? "");
$recordId = Sanitizer::getSanitizedRecord($_POST['record'] ?? "", $records);
$label = Sanitizer::sanitize($_POST['label'] ?? "");
$destFields = $affectedFields[$field] ?? [];
if ($field && $recordId && $label && !empty($destFields)) {
	$metadata = Download::metadataByPid($pid);
	$numModifiedFields = 0;
	$fieldChoices = [];
	$newIndex = 0;
	foreach ($metadata as $i => $row) {
		if (in_array($row['field_name'], $destFields)) {
			if (empty($fieldChoices)) {
				$fieldChoices = DataDictionaryManagement::getRowChoices($row['select_choices_or_calculations']);
				$otherString = $fieldChoices[DataDictionaryManagement::HONORACTIVITY_OTHER_VALUE] ?? "Other/Not Listed";
				unset($fieldChoices[DataDictionaryManagement::HONORACTIVITY_OTHER_VALUE]);  // reset last below
				$maxIndex = 0;
				foreach (array_keys($fieldChoices) as $index) {
					$isInt = is_int($index) || (ctype_digit($index));
					if ($isInt && ($index > $maxIndex)) {
						$maxIndex = $index;
					}
				}
				$newIndex = $maxIndex + 1;
				$fieldChoices[$newIndex] = $label;
				$fieldChoices[DataDictionaryManagement::HONORACTIVITY_OTHER_VALUE] = $otherString;
			}
			$metadata[$i]["select_choices_or_calculations"] = DataDictionaryManagement::makeChoiceStr($fieldChoices);
			$numModifiedFields++;
		}
	}
	if ($newIndex == 0) {
		$data = ["error" => "No new index generated!"];
	} elseif ($numModifiedFields > 0) {
		Upload::metadataNoAPI($metadata, $pid);
		$data = [
			"result" => $numModifiedFields." fields modified.",
			"index" => $newIndex,
			"label" => $label,
		];
	} else {
		$data = ["error" => "No fields modified!"];
	}
} else {
	$data = ["error" => "Missing input data!"];
}
echo json_encode($data);
