<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\REDCapLookup;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

$table1Pid = Application::getTable1PID();

if (REDCapManagement::versionGreaterThanOrEqualTo(REDCAP_VERSION, "12.5.2")) {
    $redcapData = \REDCap::getData($table1Pid, "json-array");
} else {
    $json = \REDCap::getData($table1Pid, "json");
    $redcapData = json_decode($json, TRUE);
}
$metadataJSON = \REDCap::getDataDictionary($table1Pid, "json");
$metadata = json_decode($metadataJSON, TRUE);
$fieldLabels = DataDictionaryManagement::getLabels($metadata);
$choices = DataDictionaryManagement::getChoices($metadata);

$fieldsToSkip = ["record_id", "table_1_rows_complete"];
$typeField = "population";
$reformattedRows = [];
$copyRowHTML = "<div class='centered smaller'><a class='darkgreytext' href='javascript:;' onclick='copyRow($(this).parent().parent().parent());'>Copy Data Row</a></div>";
foreach ($redcapData as $row) {
    $reformattedRow = [];
    // $textType = $choices[$typeField][$row[$typeField] ?? ""] ?? "[$typeField]";
    $textType = "Trainees";
    if ($row['population'] == "both") {
        $fullStrings = [
            "predocs" => "Predoctorates",
            "postdocs" => "Postdoctorates",
        ];
        $rows = [];
        foreach ($fullStrings as $type => $typeString) {
            $rows[$type] = [];
            makeNameAndEmail($rows[$type], $row);
            $rows[$type]["Population"] = $typeString;
            foreach ($row as $field => $value) {
                if (
                    (
                        !in_array($field, $fieldsToSkip) && preg_match("/$type$/", $field)
                    )
                    || in_array($field, ["program", "total_faculty", "participating_faculty", "last_update"])
                ) {
                    $newFieldName = str_replace($typeString, $textType, $fieldLabels[$field]);
                    $newFieldName = preg_replace("/[\r\n]+.+$/", "", $newFieldName);
                    $rows[$type][$newFieldName] = makeValueForField($field, $value, $choices);
                }
            }
        }
        foreach ($rows as $type => $reformattedRow) {
            if (!empty($reformattedRow)) {
                $reformattedRow["Copy"] = $copyRowHTML;
                $reformattedRows[] = $reformattedRow;
            }
        }
    } else {
        foreach ($row as $field => $value) {
            if (!in_array($field, $fieldsToSkip)) {
                if (in_array($field, ["name", "email"])) {
                    makeNameAndEmail($reformattedRow, $row);
                } else {
                    $newFieldName = str_replace("[$typeField]", $textType, $fieldLabels[$field]);
                    $newFieldName = preg_replace("/[\r\n]+.+$/", "", $newFieldName);
                    $reformattedRow[$newFieldName] = makeValueForField($field, $value, $choices);
                }
            }
        }
        if (!empty($reformattedRow)) {
            $reformattedRow["Copy"] = $copyRowHTML;
            $reformattedRows[] = $reformattedRow;
        }
    }
}
$json = json_encode($reformattedRows);

function makeValueForField($field, $value, $choices) {
    if (preg_match("/^(\d+)\[(\d)\]$/", $value, $matches)) {
        $number = $matches[1];
        $reliability = $matches[2];
        return "<div class='centered'><strong class='value'>$number</strong><div class='smallest'>Reliability Index: $reliability</div></div>";
    } else if (is_numeric($value)) {
        $number = $value;
        return "<div class='centered'><strong class='value'>$number</strong></div>";
    } else if ($field == "population") {
        return $choices['population'][$value] ?? "";
    } else {
        return $value;
    }
}

function makeNameAndEmail(&$reformattedRow, $dataRow) {
    $name = $dataRow['name'];
    $email = $dataRow['email'];
    $newFieldName = "Input By";
    $newValue = "";
    if ($name && $email) {
        $newValue = "<a href='mailto:$email'>$name</a>";
    } else if ($name) {
        $newValue = $name;
    } else if ($email) {
        $newValue = "<span class='smaller'>$email</span>";
    }
    $reformattedRow[$newFieldName] = $newValue;
}

?>

{
    "draw": <?= Sanitizer::sanitizeInteger($_GET['draw'] ?? 1) ?>,
    "recordsTotal": <?=count($reformattedRows)?>,
    "recordsFiltered": <?=count($reformattedRows)?>,
    "data": <?=$json?>
}

