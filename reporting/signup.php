<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

$predocIdx = 6;
$postdocIdx = 7;
$textAssociations = [
    "green" => "Currently Has<br><strong>Appointment</strong>",
    "red" => "Currently Has<br><span class='nowrap bolded'>No Appointment</span>",
    "light_green" => "Ready to Save<br><strong>Appointment</strong>",
    "light_red" => "Ready to Save<br><span class='nowrap bolded'>No Appointment</span>",
];
$engagedTextAssociations = [
    "white" => "<span class='nowrap'>Not Affiliated</span><br><span class='nowrap'>with CTSA</span>",
    "green" => "Currently<br><strong>Engaged</strong>",
    "red" => "Currently<br><span class='nowrap bolded'>Not Engaged</span>",
    "light_green" => "Ready to Save<br><strong>Engaged</strong>",
    "light_red" => "Ready to Save<br><span class='nowrap bolded'>Not Engaged</span>",
];

if (count($_POST) > 0) {
    $toDelete = ["deletedPredocs" => $predocIdx, "deletedPostdocs" => $postdocIdx];
    $toChange = ["changedPredocs" => $predocIdx, "changedPostdocs" => $postdocIdx];
    $engagedValues = ["1" => "changedYesEngaged", "2" => "changedNoEngaged"];
    $upload = [];

    foreach ($engagedValues as $val => $field) {
        $records = REDCapManagement::sanitizeArray($_POST[$field]);
        foreach ($records as $recordId) {
            $upload[] = [
                "record_id" => $recordId,
                "redcap_repeat_instrument" => "",
                "redcap_repeat_instance" => "",
                "identifier_is_engaged" => $val,
            ];
        }
    }

    $fields = ["record_id", "custom_role"];
    foreach ($toDelete as $field => $idx) {
        $instancesDeleted = 0;
        $records = REDCapManagement::sanitizeArray($_POST[$field]);
        if (!empty($records)) {
            $instancesToDelete = [];
            $redcapData = Download::fieldsForRecords($token, $server, $field, $records);
            foreach ($redcapData as $row) {
                if (($row['custom_role'] == $idx) && ($row['redcap_repeat_instrument'] == "custom_grant")) {
                    $recordId = $row['record_id'];
                    if (!isset($instances[$recordId])) {
                        $instancesToDelete[$recordId] = [];
                    }
                    $instancesToDelete[$recordId][] = $row['redcap_repeat_instance'];
                }
            }

            foreach ($instancesToDelete as $recordId => $instances) {
                if (!empty($instances)) {
                    Upload::deleteFormInstances($token, $server, $pid, "custom_", $recordId, $instances);
                    $instancesDeleted += count($instances);
                }
            }
        }
        echo "$field deleted $instancesDeleted instances\n";
    }

    foreach ($toChange as $field => $idx) {
        $recordsAndDates = REDCapManagement::sanitizeArray($_POST[$field]);
        if (!empty($recordsAndDates)) {
            $records = array_keys($recordsAndDates);
            $fields = ["record_id", "custom_role"];
            $redcapData = Download::fieldsForRecords($token, $server, $fields, $records);
            foreach ($recordsAndDates as $recordId => $dates) {
                $instance = REDCapManagement::getMaxInstance($redcapData, "custom_grant", $recordId) + 1;
                foreach ($redcapData as $row) {
                    if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "custom_grant") && ($row['custom_role'] == $idx)) {
                        $instance = $row['redcap_repeat_instance'];
                        break;
                    }
                }
                if (count($dates) == 3) {
                    $startDate = $dates[0];
                    $endDate = $dates[1];
                    $title = $dates[2];
                    $upload[] = [
                        "record_id" => $recordId,
                        "redcap_repeat_instrument" => "custom_grant",
                        "redcap_repeat_instance" => $instance,
                        "custom_start" => $startDate,
                        "custom_end" => $endDate,
                        "custom_title" => $title,
                        "custom_role" => $idx,
                        "custom_type" => "10",     // Training Appointment
                        "custom_last_update" => date("Y-m-d"),
                        "custom_grant_complete" => "2",
                    ];
                }
            }
        }
    }

    echo "Uploaded ".count($upload)." rows\n";
    if (!empty($upload)) {
        Upload::rows($upload, $token, $server);
    }
    exit;
}

require_once(dirname(__FILE__)."/../charts/baseWeb.php");


$names = Download::names($token, $server);
$metadata = Download::metadata($token, $server);
$metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
$fields = [
    "record_id",
    "custom_role",
    "custom_start",
    "custom_end",
    "custom_title",
    "identifier_is_engaged",
];
$fields = REDCapManagement::screenForFields($metadata, $fields);
$indexedData = REDCapManagement::indexREDCapData(Download::fields($token, $server, $fields));
$hasIsEngaged = in_array("identifier_is_engaged", $metadataFields);
$choices = REDCapManagement::getChoices($metadata);

echo "<h1>Sign Up for NIH Tables</h1>";
echo "<h4 class='max-width'>Click on a Colored Cell to Change. Dates and Project Titles, though necessary for NIH tables, are not required to sign up a scholar.</h4>";
echo "<p class='submit centered'><button onclick='saveForm();'>Save Changes</button></p>";

echo "<table class='centered bordered'>";
echo "<thead><tr class='whiteRow'>";
echo "<th>Name</th>";
echo "<th>Pre-Docs</th>";
echo "<th>Post-Docs</th>";
if ($hasIsEngaged) {
    echo "<th>Is Engaged<br>in Research?<br>(CTSA only)</th>";
}
echo "</tr></thead>";
echo "<tbody>";
$i = 0;
foreach ($names as $recordId => $name) {
    $rowClass = ($i % 2 == 0) ? "even" : "odd";
    $i++;
    $recordData = $indexedData[$recordId] ?? [];

    $predocClass = "red";
    $predocRange = makeRange($recordId);
    $postdocClass = "red";
    $postdocRange = makeRange($recordId);
    $isEngagedClass = "white";
    foreach ($recordData as $row) {
        if ($row['redcap_repeat_instrument'] == "custom_grant") {
            if ($row['custom_role'] == $predocIdx) {
                $predocClass = "green";
                $predocRange = makeCustomRange($row);
            } else if ($row['custom_role'] == $postdocIdx) {
                $postdocClass = "green";
                $postdocRange = makeCustomRange($row);
            }
        }
        if (($row['redcap_repeat_instrument'] == "") && ($row['identifier_is_engaged'] == "1")) {
            $isEngagedClass = "green";
        } else if (($row['redcap_repeat_instrument'] == "") && ($row['identifier_is_engaged'] == "2")) {
            $isEngagedClass = "red";
        }
    }

    echo "<tr>";
    echo "<th class='$rowClass'>$recordId: $name</th>";
    echo "<td class='predoc $predocClass' record='$recordId'><div class='predocText' style='text-align: center; float:left; width: 120px; padding-top: 1.5em;' record='$recordId'>".$textAssociations[$predocClass]."</div>";
    echo "<div class='predocDates' style='float: left; text-align: right;' record='$recordId'>$predocRange</div></td>";
    echo "<td class='postdoc $postdocClass' record='$recordId'><div class='postdocText' style='text-align: center; float:left; width: 120px; padding-top: 1.5em;' record='$recordId'>".$textAssociations[$postdocClass]."</div>";
    echo "<div class='postdocDates' style='float: left; text-align: right;' record='$recordId'>$postdocRange</div></td>";
    if ($hasIsEngaged) {
        echo "<td class='engaged $isEngagedClass centered' record='$recordId'>".$engagedTextAssociations[$isEngagedClass]."</td>";
    }
    echo "</tr>";
}
echo "</tbody></table>";
echo "<p class='submit centered'><button onclick='saveForm();'>Save Changes</button></p>";

$thisLink = Application::link("this");
echo "<script>

$(document).ready(function() {
    $('.predoc,.postdoc,.engaged').click(function(e) {
        toggleCell(this, e);
    });
    $('.pickDate').change(function() {
        editCell(this);
    });
});

function getClassList() {
    return ['white', 'green', 'red', 'light_green', 'light_red'];
}

function getColorClasses(jQueryOb) {
    let hasClass = {};
    let potentialClasses = getClassList();
    potentialClasses.forEach(function(className) {
        hasClass[className] = jQueryOb.hasClass(className);
    });
    for (let i = 0; i < potentialClasses.length; i++) {
    }
    return hasClass;
}

function toggleCell(ob, e) {
    if (e && (e.target.classList.contains('pickDate') || $(e.target).parents('.pickDate').length)) {
        return;
    }
    let hasClass = getColorClasses($(ob));
    let record = $(ob).attr('record');
    if (hasClass['white']) {
        $(ob).removeClass('white');
        $(ob).addClass('light_green');
    } else if (hasClass['green']) {
        $(ob).removeClass('green');
        $(ob).addClass('light_red');
    } else if (hasClass['red']) {
        $(ob).removeClass('red');
        $(ob).addClass('light_green');
    } else if (hasClass['light_red']) {
        $(ob).removeClass('light_red');
        $(ob).addClass('light_green');
    } else if (hasClass['light_green']) {
        $(ob).removeClass('light_green');
        $(ob).addClass('light_red');
    }
    if ($(ob).hasClass('engaged')) {
        setEngagedText($(ob));        
    } else {
        let textSel = getTextSelector($(ob))+'[record=\"'+record+'\"]';
        setCellText($(ob), $(textSel));
    }
    $('.submit').show();
}

function getTextSelector(selOrOb) {
    let dateSel = getDateSelector(selOrOb);
    
    if (dateSel.match(/predoc/)) {
        return '.predocText';
    } else if (dateSel.match(/postdoc/)) {
        return '.postdocText';
    }
    return '';
}

function getDateSelector(selOrOb) {
    if (typeof selOrOb == 'string') {
        let sel = selOrOb;
        if (sel.match(/predoc/)) {
            return '.predocDates';
        } else if (sel.match(/postdoc/)) {
            return '.postdocDates';
        }
    } else {
        let jQueryOb = selOrOb;
        if (jQueryOb.hasClass('postdoc') || jQueryOb.hasClass('postdocDates') || jQueryOb.hasClass('postdocText')) {
            return '.postdocDates';
        } else if (jQueryOb.hasClass('predoc') || jQueryOb.hasClass('predocDates') || jQueryOb.hasClass('predocText')) {
            return '.predocDates';
        }
    }
    
    return '';
}

function getStartEnd(sel) {
    let hash = {};
    let dateSel = getDateSelector(sel);
    $(sel).each(function() {
        if (dateSel !== '') {
            let record = $(this).attr('record');
            let startDate = $(dateSel+'[record=\"'+record+'\"] .startDate').val() ?? '';
            let endDate = $(dateSel+'[record=\"'+record+'\"] .endDate').val() ?? '';
            let title = $(dateSel+'[record=\"'+record+'\"] .title').val() ?? '';
            hash[record] = [startDate, endDate, title];
        }
    });
    return hash;
}

function getRecords(sel) {
    let records = [];
    $(sel).each(function() {
        let record = $(this).attr('record');
        records.push(record);
    });
    return records;
}

function saveForm() {
    if ($('.light_red').length + $('.light_green').length > 0) {
        const post = {};
        post.deletedPredocs = getRecords('.predoc.light_red');
        post.changedPredocs = getStartEnd('.predoc.light_green');
        post.deletedPostdocs = getRecords('.postdoc.light_red');
        post.changedPostdocs = getStartEnd('.postdoc.light_green');
        post.changedYesEngaged = getRecords('.engaged.light_green');
        post.changedNoEngaged = getRecords('.engaged.light_red');
        
        const url = '$thisLink';
        $.post(url, post, function(html) {
            console.log('Saved '+html);
            $('.engaged.light_green').each(function() {
                $(this).removeClass('light_green');
                $(this).addClass('green');
                setEngagedText($(this));
            });
            $('.engaged.light_red').each(function() {
                $(this).removeClass('light_red');
                $(this).addClass('red');
                setEngagedText($(this));
            });
            $('.postdoc.light_green,.predoc.light_green').each(function() {
                $(this).removeClass('light_green');
                $(this).addClass('green');
                let textSel = getTextSelector($(this));
                setCellText($(this), $(textSel));
            });
            $('.postdoc.light_red,.predoc.light_red').each(function() {
                $(this).removeClass('light_red');
                $(this).addClass('red');
                let textSel = getTextSelector($(this));
                setCellText($(this), $(textSel));
            });
            alert('Saved');
        });
    } else {
        alert('No changes to save!');
    }
}

function removeAllColors(jQueryOb) {
    let classes = getClassList();
    for (let i = 0; i < classes.length; i++) {
        jQueryOb.removeClass(classes[i]);
    }
}

function setEngagedText(cell) {
    const textAssociations = ".json_encode($engagedTextAssociations).";
    for (let className in textAssociations) {
        if (cell.hasClass(className)) {
            cell.html(textAssociations[className]);
        }
    }
}

function setCellText(colorCell, textCell) {
    const textAssociations = ".json_encode($textAssociations).";
    for (let className in textAssociations) {
        if (colorCell.hasClass(className)) {
            textCell.html(textAssociations[className]);
        }
    }
}

function editCell(ob) {
    let record = $(ob).attr('record');
    let parentCell = $(ob).parent();
    let toggleCell = null;
    let textCell = null;
    if (parentCell.hasClass('predocDates')) {
        toggleCell = $('.predoc[record=\"'+record+'\"]');
        textCell = $('.predocText[record=\"'+record+'\"]');
    } else if (parentCell.hasClass('postdocDates')) {
        toggleCell = $('.postdoc[record=\"'+record+'\"]');
        textCell = $('.postdocText[record=\"'+record+'\"]');
    }
    if (toggleCell && textCell) {
        let startValue = parentCell.find('.startDate').val();
        let endValue = parentCell.find('.endDate').val();        
        if (startValue || endValue) {
            removeAllColors(toggleCell);
            toggleCell.addClass('light_green');
            setCellText(toggleCell, textCell);
        } else {
            removeAllColors(toggleCell);
            toggleCell.addClass('light_red');
            setCellText(toggleCell, textCell);
        }
    }
}

</script>";

function makeRange($recordId, $startDate = "", $endDate = "", $title = "") {
    $startLine = "Start: <input type='date' class='startDate pickDate' value='$startDate' record='$recordId'>";
    $endLine = "End: <input type='date' class='endDate pickDate' value='$endDate' record='$recordId'>";
    $titleLine = "<input type='text' class='title pickDate' value='$title' record='$recordId' placeholder='Project Title'>";
    return $titleLine."<br>".$startLine."<br>".$endLine;
}

function makeCustomRange($row) {
    return makeRange($row['record_id'], $row['custom_start'], $row['custom_end'], $row['custom_title']);
}

