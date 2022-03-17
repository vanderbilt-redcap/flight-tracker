<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\PositionChange;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

$records = Download::recordIds($token, $server);
if (empty($records)) {
    echo "<p class='centered'>No records available</p>";
    exit;
}
$recordId = REDCapManagement::getSanitizedRecord($_GET['record'] ?? $_POST['record'] ?? $records[0], $records);
$currRecordIndex = FALSE;
$i = 0;
foreach ($records as $currRecord) {
    if ($currRecord == $recordId) {
        $currRecordIndex = $i;
        break;
    }
    $i++;
}
if (($currRecordIndex !== FALSE) && ($currRecordIndex + 1 < count($records))) {
    $nextRecord = $records[$currRecordIndex + 1];
} else {
    $nextRecord = $records[0];
}

if (isset($_POST['row'])) {
    $row = $_POST['row'];
    $instance = $_POST['instance'];
    if ($recordId && is_array($row) && ($row['record_id'] == $recordId) && is_numeric($instance)) {
        $rowToUpload = ["record_id" => $recordId, "redcap_repeat_instrument" => "position_change", "redcap_repeat_instance" => $instance];
        foreach ($row as $field => $value) {
            if (!isset($rowToUpload[$field])) {
                $rowToUpload[$field] = $value;
            }
        }

        try {
            $feedback = Upload::oneRow($rowToUpload, $token, $server);
            $data = [
                "message" => "Saved.",
                "feedback" => json_encode($feedback)
            ];
        } catch (\Exception $e) {
            $data = ["error" => $e->getMessage()];
        }
    } else {
        $data = ["error" => "Could not locate record $recordId."];
    }
    echo json_encode($data);
    exit;
} else if (isset($_POST['instanceToDelete'])) {
    if ($recordId && is_numeric($_POST['instanceToDelete'])) {
        $redcapData = Download::fieldsForRecords($token, $server, Application::$positionFields, [$recordId]);
        $instances = REDCapManagement::getInstances($redcapData, "position_change", $recordId);
        $requestedInstance = $_POST['instanceToDelete'];
        if (in_array($requestedInstance, $instances)) {
            try {
                Upload::deleteFormInstances($token, $server, $pid, "promotion", $recordId, [$requestedInstance]);
                $data = ["message" => "Success. Instance $requestedInstance deleted from record $recordId."];
            } catch (\Exception $e) {
                $data = ["error" => $e->getMessage()];
            }
        } else {
            $data = ["error" => "Could not locate instance $requestedInstance on record $recordId."];
        }
    } else {
        $data = ["error" => "Could not locate record $recordId."];
    }
    echo json_encode($data);
    exit;
}

require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/baseSelect.php");

$names = Download::names($token, $server);
if (!$recordId) {
    if (empty($records)) {
        throw new \Exception("No records have been set up!");
    } else {
        $recordId = $records[0];
    }
}

$metadata = Download::metadata($token, $server);
$choices = REDCapManagement::getChoices($metadata);
$positionFields = REDCapManagement::getFieldsFromMetadata($metadata, "position_change");
$indexedMetadata = REDCapManagement::indexMetadata($metadata);
$labels = REDCapManagement::getLabels($metadata);
$fieldsToDownload = $positionFields;
$fieldsToDownload[] = "record_id";
$redcapData = Download::fieldsForRecords($token, $server, $fieldsToDownload, [$recordId]);
$instanceHTMLBoxes = [];
$elementStyle = "width: 400px;";
$maxInstance = REDCapManagement::getMaxInstance($redcapData, "position_change", $recordId);
$newInstance = $maxInstance + 1;
foreach ($redcapData as $row) {
    if ($row['redcap_repeat_instrument'] == "position_change") {
        $instance = $row['redcap_repeat_instance'];

        $html = "";
        $html .= "<div class='max-width blue shadow' id='boxInstance___$instance' style='margin: 32px auto;'>";
        $html .= "<h3>Position Change ($instance)</h3>";
        $html .= "<table class='centered noborder' id='instance$instance'>";
        $html .= "<tbody>";
        foreach ($positionFields as $field) {
            $id = $field."___".$instance;
            $label = $labels[$field] ?? "";
            $value = $row[$field] ?? "";
            $metadataRow = $indexedMetadata[$field] ?? [];
            $validationType = $metadataRow['text_validation_type_or_show_slider_number'];
            $fieldType = $metadataRow['field_type'];

            $html .= "<tr>";
            $html .= "<th class='alignright'><label for='$id'>$label</label></th>";
            $html .= "<td class='left-align' style='$elementStyle'>";
            if (in_array($fieldType, ["text", "slider"])) {
                if (preg_match("/^date_/", $validationType)) {
                    $inputType = "date";
                } else if (preg_match("/^datetime_/", $validationType)) {
                    $inputType = "datetime-local";
                } else if (in_array($validationType, ["number", "integer", "slider"])) {
                    $inputType = "number";
                } else {
                    $inputType = "text";
                }
                $html .= "<input type='$inputType' id='$id' value='$value' style='$elementStyle' />";
            } else if ($fieldType == "notes") {
                $html .= "<textarea id='$id' style='$elementStyle'>$value</textarea>";
            } else if (in_array($fieldType, ["radio", "yesno", "truefalse", "dropdown"])) {
                if ($fieldType == "yesno") {
                    $options = [1 => "Yes", 0 => "No"];
                } else if ($fieldType == "truefalse") {
                    $options = [1 => "True", 0 => "False"];
                } else {
                    $options = $choices[$field] ?? [];
                }

                $html .= "<select id='$id' style='$elementStyle'>";
                $html .= "<option value=''>---SELECT---</option>";
                foreach ($options as $v => $l) {
                    if ($v == $value) {
                        $html .= "<option value='$v' selected>$l</option>";
                    } else {
                        $html .= "<option value='$v'>$l</option>";
                    }
                }
                $html .= "</select>";
            } else if ($fieldType == "checkbox") {
                $checks = $choices[$field] ?? [];
                $checkHTML = [];
                foreach ($checks as $v => $l) {
                    $isCheckedText = $row[$field."___".$v] ? " checked" : "";
                    $checkId = $id."___".$v;
                    $checkHTML[] = "<input type='checkbox' id='$checkId' $isCheckedText /> <label for='$checkId'>$l</label>";
                }
                $html .= implode("<br/>", $checkHTML);
            } else if ($fieldType == "file") {
                if ($value) {
                    $html .= "<p>File already uploaded.</p>";
                } else {
                    $html .= "<p>File uploading not supported.</p>";
                }

            } else {
                $html .= "<p>Field type $fieldType not suppported.</p>";
            }
            $html .= "</td>";
            $html .= "</tr>";
        }
        $html .= "</tbody>";
        $html .= "</table>";
        $html .= "<p class='centered' style='margin: 16px auto;'><button onclick='savePositionChangeInstance(\"$recordId\", \"$instance\"); return false;'>Save Changes</button>&nbsp;&nbsp;&nbsp;<button onclick='deletePositionChangeInstance(\"$recordId\", \"$instance\"); return false;'>Delete Entry</button></p>";
        $html .= "</div>";
        $instanceHTMLBoxes[] = $html;
    }
}

$name = $names[$recordId] ?? "No name specified!";
$thisUrl = Application::link("this");
echo "<div class='subnav'>\n";
echo Links::makeDataWranglingLink($pid, "Grant Wrangler", $recordId, FALSE, "green");
echo Links::makePubWranglingLink($pid, "Publication Wrangler", $recordId, FALSE, "green");
echo Links::makePatentWranglingLink($pid, "Patent Wrangler", $recordId, FALSE, "green");
echo Links::makePositionChangeWranglingLink($pid, "Position Wrangler", $recordId, FALSE, "green");
echo Links::makeProfileLink($pid, "Scholar Profile", $recordId, FALSE, "green");
echo "<a class='yellow'>".PositionChange::getSelectRecord()."</a>";
echo "<a class='yellow'>".PositionChange::getSearch()."</a>";
echo Links::makeFormLink($pid, $recordId, $eventId, "Add New Position Instance", "position_change", $newInstance, "blue", "_NEW");

$nextPageLink = "$thisUrl&record=".$nextRecord;
# next record is in the same window => don't use Links class
echo "<a class='blue' href='$nextPageLink'>View Next Record</a>";

echo "</div>\n";   // .subnav

echo "<div id='content'>";
echo "<h1>Position Change Wrangler</h1>";
echo "<h2>Record $recordId: $name</h2>";
echo "<p class='centered'><button onclick='location.href = \"$thisUrl&record=$nextRecord\";'>Next Record</button></p>";
if (empty($instanceHTMLBoxes)) {
    echo "<p class='centered'>No data available</p>";
} else {
    echo implode("", $instanceHTMLBoxes);
}
echo "</div>";
$positionJSON = json_encode($positionFields);
echo "<script>
function savePositionChangeInstance(recordId, instance) {
    presentScreen('Saving...');
    const row = makePositionChangeRow(recordId, instance);
    $.post('$thisUrl', { record: recordId, instance: instance, row: row }, function(json) {
        console.log(json);
        clearScreen();
        handleJSONResponse(json);
    });
}

function makePositionChangeRow(recordId, instance) {
    const fields = $positionJSON;
    const row = {};
    let i;
    for (i=0; i < fields.length; i++) {
        const field = fields[i];
        const id = field + '___' + instance;
        if ($('#'+id).length > 0) {
            row[field] = $('#'+id).val();
        } else {
            const idRegex = new RegExp('^'+id+'___');
            $('input[type=checkbox]').each(function(idx, ob) {
                const checkId = $(ob).attr('id');
                if (checkId.match(idRegex)) {
                    const checkValue = checkId.replace(idRegex, '');
                    const checkField = field+'___'+checkValue;
                    let checkNumberValue = 0;
                    if ($(ob).is(':checked')) {
                        checkNumberValue = 1;
                    }
                    row[checkField] = checkNumberValue;
                }
            });
        }
    }
    if (Object.keys(row).length > 0) {
        row['record_id'] = recordId;
    }
    return row;
}

function deletePositionChangeInstance(recordId, instance) {
    $.post('$thisUrl', { record: recordId, instanceToDelete: instance }, function(json) {
        console.log(json);
        clearScreen();
        if (handleJSONResponse(json)) {
            $('#boxInstance___'+instance).hide();
        }
        
    });
}

function handleJSONResponse(json) {
    try {
        const data = JSON.parse(json);
        if (data.error) {
            $.sweetModal({
                icon: $.sweetModal.ICON_ERROR,
                content: data.error
            });
            return false;
        } else if (data.message) {
            $.sweetModal({
                icon: $.sweetModal.ICON_SUCCESS,
                content: data.message
            });
            return true;
        } else {
            $.sweetModal({
                icon: $.sweetModal.ICON_ERROR,
                content: json
            });
            return false;
        }
    } catch (e) {
        if (e.message) {
            $.sweetModal({
                icon: $.sweetModal.ICON_ERROR,
                content: e.message
            });
            return false;
        } else {
            $.sweetModal({
                icon: $.sweetModal.ICON_ERROR,
                content: e
            });
            return false;
        }
    }
}
</script>";
