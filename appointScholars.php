<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use \Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Conversion;

require_once(dirname(__FILE__)."/classes/Autoload.php");
require_once(dirname(__FILE__)."/small_base.php");

# Works for input = titles
# TODO test
# TODO move to career_dev, along with curve and K2R

$input = Sanitizer::sanitize($_GET['input'] ?? "None Provided");
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
$kClasses = array_merge(Conversion::INTERNALLY_GRANTED_KS, [4]);

if (count($_POST) > 0) {
    $toDelete = [
        "deletedPredocs" => Conversion::PREDOC_INDEX,
        "deletedPostdocs" => Conversion::POSTDOC_INDEX,
        "deletedKCareers" => $kClasses,
    ];
    $toChange = [
        "changedPredocs" => Conversion::PREDOC_INDEX,
        "changedPostdocs" => Conversion::POSTDOC_INDEX,
        "changedKCareers" => $kClasses,
    ];
    $engagedValues = ["1" => "changedYesEngaged", "2" => "changedNoEngaged"];
    $upload = [];

    $allRecords = Download::recordIdsByPid($pid);
    foreach ($engagedValues as $val => $field) {
        $records = Sanitizer::sanitizeArray($_POST[$field] ?? []);
        foreach ($records as $recordId) {
            $recordId = Sanitizer::getSanitizedRecord($recordId, $allRecords);
            if ($recordId) {
                $upload[] = [
                    "record_id" => $recordId,
                    "redcap_repeat_instrument" => "",
                    "redcap_repeat_instance" => "",
                    "identifier_is_engaged" => $val,
                ];
            }
        }
    }

    foreach ($toDelete as $field => $idx) {
        $instancesDeleted = 0;
        $requestedRecords = Sanitizer::sanitizeArray($_POST[$field]);
        $records = [];
        foreach ($requestedRecords as $recordId) {
            $recordId = Sanitizer::getSanitizedRecord($recordId, $allRecords);
            if ($recordId) {
                $records[] = $recordId;
            }
        }
        if (!empty($records)) {
            if (is_array($idx)) {    // kCareer grant types - use different screen
                $customTypes = Download::oneFieldWithInstancesByPid($pid, "custom_type");
                $instancesToDelete = getInstancesToDelete($customTypes, $records, $allRecords, $idx);
            } else {
                $customRoles = Download::oneFieldWithInstancesByPid($pid, "custom_role");
                $instancesToDelete = getInstancesToDelete($customRoles, $records, $allRecords, $idx);
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

    $maxUploadInstance = [];
    $customGrantCompletes  = Download::oneFieldWithInstancesByPid($pid, "custom_grant_complete");    // test field
    foreach ($toChange as $field => $idx) {
        $recordsAndDates = Sanitizer::sanitizeArray($_POST[$field] ?? []);
        if (!empty($recordsAndDates)) {
            if (is_array($idx)) {    // kCareer grant types - use different screen
                $customTypes = Download::oneFieldWithInstancesByPid($pid, "custom_type");
                foreach ($recordsAndDates as $recordId => $dates) {
                    $recordId = Sanitizer::getSanitizedRecord($recordId, $allRecords);
                    if ($recordId) {
                        $instanceToUploadTo = getInstanceToUploadTo($maxUploadInstance, $customGrantCompletes, $customTypes, $recordId, $idx);
                        $upload[] = makeCustomGrantUploadRow($dates, $recordId, $instanceToUploadTo, Conversion::PI_ROLE_INDEX);
                    }
                }
            } else {
                $customRoles = Download::oneFieldWithInstancesByPid($pid, "custom_role");
                foreach ($recordsAndDates as $recordId => $dates) {
                    $recordId = Sanitizer::getSanitizedRecord($recordId, $allRecords);
                    if ($recordId) {
                        $instanceToUploadTo = getInstanceToUploadTo($maxUploadInstance, $customGrantCompletes, $customRoles, $recordId, $idx);
                        # the type is always Conversion::T_TYPE = Training Appointment
                        $upload[] = makeCustomGrantUploadRow($dates, $recordId, $instanceToUploadTo, $idx, Conversion::T_TYPE);
                    }
                }
            }
        }
    }

    echo "Uploaded ".count($upload)." rows\n";
    if (!empty($upload)) {
        Upload::rowsByPid($upload, $pid);
    }
    exit;
}

require_once(dirname(__FILE__)."/charts/baseWeb.php");

$names = Download::namesByPid($pid);
$metadataFields = Download::metadataFieldsByPid($pid);
$fields = [
    "record_id",
    "custom_role",
    "custom_start",
    "custom_end",
    "custom_title",
    "custom_type",
    "identifier_is_engaged",
];
$fields = DataDictionaryManagement::filterOutInvalidFieldsFromFieldlist($metadataFields, $fields);
$indexedData = REDCapManagement::indexREDCapData(Download::fields($token, $server, $fields));
$hasIsEngaged = in_array("identifier_is_engaged", $metadataFields);

echo "<h1>Appoint Scholars to Grants</h1>";
echo "<p class='centered max-width-600'>Click on a Colored Cell to Change. Dates and Research Project Topics, though necessary for NIH tables, are not required to sign up a scholar.</p>";
echo "<p class='submit centered'><button onclick='saveForm(); return false;'>Save Changes</button></p>";

$tLanguage = ($input == "grant_types") ? " on T" : "";
echo "<table class='centered bordered'>";
echo "<thead><tr>";
echo "<th class='stickyGrey'>Name</th>";
echo "<th class='stickyGrey'>Pre-Docs$tLanguage</th>";
echo "<th class='stickyGrey'>Post-Docs$tLanguage</th>";
if ($input == "grant_types") {
    echo "<th class='stickyGrey'>Early Career on K</th>";
}
if ($hasIsEngaged) {
    echo "<th class='stickyGrey'>Is Engaged<br>in Research?<br>(CTSA only)</th>";
}
echo "</tr></thead>";
echo "<tbody>";
$i = 0;
if ($input == "grant_types") {
    $allGrantTypes = DataDictionaryManagement::getChoicesForField($pid, "custom_type");
    $desiredGrantIndices = array_merge(Conversion::INTERNALLY_GRANTED_KS, [4, Conversion::T_TYPE]);
    $grantTypes = [];
    foreach ($allGrantTypes as $index => $label) {
        if (in_array($index, $desiredGrantIndices)) {
            $grantTypes[$index] = $label;
        }
    }
} else {
    $grantTypes = [];
}
foreach ($names as $recordId => $name) {
    $rowClass = ($i % 2 == 0) ? "even" : "odd";
    $i++;
    $recordData = $indexedData[$recordId] ?? [];

    $predocClass = "red";
    $predocRange = makeRange($recordId, FALSE, $grantTypes);
    $postdocClass = "red";
    $postdocRange = makeRange($recordId, FALSE, $grantTypes);
    $kClass = "red";
    $kRange = makeRange($recordId, TRUE, $grantTypes);
    $isEngagedClass = "white";
    foreach ($recordData as $row) {
        if ($row['redcap_repeat_instrument'] == "custom_grant") {
            if ($row['custom_role'] == Conversion::PREDOC_INDEX) {
                $predocClass = "green";
                $predocRange = makeCustomRange($row, FALSE, $grantTypes);
            } else if ($row['custom_role'] == Conversion::POSTDOC_INDEX) {
                $postdocClass = "green";
                $postdocRange = makeCustomRange($row, FALSE, $grantTypes);
            } else if (in_array($row['custom_type'], $kClasses)) {
                $kClass = "green";
                $kRange = makeCustomRange($row, TRUE, $grantTypes);
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
    echo "<td class='predoc $predocClass' record='$recordId'><div class='predocText appointmentBox' record='$recordId'>".$textAssociations[$predocClass]."</div>";
    echo "<div class='predocDates' style='text-align: right;' record='$recordId'>$predocRange</div></td>";
    echo "<td class='postdoc $postdocClass' record='$recordId'><div class='postdocText appointmentBox' record='$recordId'>".$textAssociations[$postdocClass]."</div>";
    echo "<div class='postdocDates' style='text-align: right;' record='$recordId'>$postdocRange</div></td>";
    if ($input == "grant_types") {
        echo "<td class='kCareer $kClass' record='$recordId'><div class='kCareerText appointmentBox' record='$recordId'>".$textAssociations[$kClass]."</div>";
        echo "<div class='kCareerDates' style='text-align: right;' record='$recordId'>$kRange</div></td>";
    }
    if ($hasIsEngaged) {
        echo "<td class='engaged $isEngagedClass centered' record='$recordId'>".$engagedTextAssociations[$isEngagedClass]."</td>";
    }
    echo "</tr>";
}
echo "</tbody></table>";
echo "<p class='submit centered'><button onclick='saveForm(); return false;'>Save Changes</button></p>";

$validText = ($input == "grant_types") ? "startValue" : "startValue || endValue";
$thisLink = Application::link("this");
echo "<script>

$(document).ready(function() {
    $('.predoc,.postdoc,.engaged,.kCareer').click(function(e) {
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
    const hasClass = {};
    const potentialClasses = getClassList();
    potentialClasses.forEach((className) => {
        hasClass[className] = jQueryOb.hasClass(className);
    });
    return hasClass;
}

function toggleCell(ob, e) {
    if (e && (e.target.classList.contains('pickDate') || $(e.target).parents('.pickDate').length)) {
        return;
    }
    const hasClass = getColorClasses($(ob));
    const record = $(ob).attr('record');
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
    const dateSel = getDateSelector(selOrOb);
    
    if (dateSel.match(/predoc/)) {
        return '.predocText';
    } else if (dateSel.match(/postdoc/)) {
        return '.postdocText';
    } else if (dateSel.match(/kCareer/)) {
        return '.kCareerText';
    }
    return '';
}

function getDateSelector(selOrOb) {
    if (typeof selOrOb == 'string') {
        const sel = selOrOb;
        if (sel.match(/predoc/)) {
            return '.predocDates';
        } else if (sel.match(/postdoc/)) {
            return '.postdocDates';
        } else if (sel.match(/kCareer/)) {
            return '.kCareerDates';
        }
    } else {
        const jQueryOb = selOrOb;
        if (jQueryOb.hasClass('postdoc') || jQueryOb.hasClass('postdocDates') || jQueryOb.hasClass('postdocText')) {
            return '.postdocDates';
        } else if (jQueryOb.hasClass('predoc') || jQueryOb.hasClass('predocDates') || jQueryOb.hasClass('predocText')) {
            return '.predocDates';
        } else if (jQueryOb.hasClass('kCareer') || jQueryOb.hasClass('kCareerDates') || jQueryOb.hasClass('kCareerText')) {
            return '.kCareerDates';
        }
    }
    
    return '';
}

function getStartEnd(sel) {
    const hash = {};
    const dateSel = getDateSelector(sel);
    $(sel).each((idx, ob) => {
        if (dateSel !== '') {
            const record = $(ob).attr('record');
            const startDate = $(dateSel+'[record=\"'+record+'\"] .startDate').val() ?? '';
            const endDate = $(dateSel+'[record=\"'+record+'\"] .endDate').val() ?? '';
            const title = $(dateSel+'[record=\"'+record+'\"] .title').val() ?? '';
            const grantType = $(dateSel+'[record=\"'+record+'\"] .grantType option:selected').val() ?? '';
            hash[record] = [startDate, endDate, title, grantType];
        }
    });
    return hash;
}

function getRecords(sel) {
    const records = [];
    $(sel).each(function(idx, ob) {
        const record = $(ob).attr('record');
        records.push(record);
    });
    return records;
}

function saveForm() {
    if ($('.light_red').length + $('.light_green').length > 0) {
        const post = {'redcap_csrf_token': getCSRFToken(), };
        post.deletedPredocs = getRecords('.predoc.light_red');
        post.changedPredocs = getStartEnd('.predoc.light_green');
        post.deletedPostdocs = getRecords('.postdoc.light_red');
        post.changedPostdocs = getStartEnd('.postdoc.light_green');
        post.deletedKCareers = getRecords('.kCareer.light_green');
        post.changedKCareers = getStartEnd('.kCareer.light_green');
        post.changedYesEngaged = getRecords('.engaged.light_green');
        post.changedNoEngaged = getRecords('.engaged.light_red');
        
        const url = '$thisLink';
        console.log(JSON.stringify(post));
        $.post(url, post, function(html) {
            console.log('Saved '+html);
            $('.engaged.light_green').each((idx, ob) => {
                $(ob).removeClass('light_green');
                $(ob).addClass('green');
                setEngagedText($(ob));
            });
            $('.engaged.light_red').each((idx, ob) => {
                $(ob).removeClass('light_red');
                $(ob).addClass('red');
                setEngagedText($(ob));
            });
            $('.postdoc.light_green,.predoc.light_green,.kCareer.light_green').each((idx, ob) => {
                $(ob).removeClass('light_green');
                $(ob).addClass('green');
                const textSel = getTextSelector($(ob));
                setCellText($(ob), $(textSel));
            });
            $('.postdoc.light_red,.predoc.light_red,.kCareer.light_red').each((idx, ob) => {
                $(ob).removeClass('light_red');
                $(ob).addClass('red');
                const textSel = getTextSelector($(ob));
                setCellText($(ob), $(textSel));
            });
            $.sweetModal({
                content: 'Saved!',
                icon: $.sweetModal.ICON_SUCCESS
            });
        });
    } else {
        $.sweetModal({
            content: 'No changes to save!',
            icon: $.sweetModal.ICON_ERROR
        });
    }
}

function removeAllColors(jQueryOb) {
    const classes = getClassList();
    classes.forEach((className) => {
        jQueryOb.removeClass(className);
    });
}

function setEngagedText(cell) {
    const textAssociations = ".json_encode($engagedTextAssociations).";
    for (const className in textAssociations) {
        if (cell.hasClass(className)) {
            cell.html(textAssociations[className]);
        }
    }
}

function setCellText(colorCell, textCell) {
    const textAssociations = ".json_encode($textAssociations).";
    for (const className in textAssociations) {
        if (colorCell.hasClass(className)) {
            textCell.html(textAssociations[className]);
        }
    }
}

function editCell(ob) {
    const record = $(ob).attr('record');
    const parentCell = $(ob).parent();
    let toggleCell = null;
    let textCell = null;
    if (parentCell.hasClass('predocDates')) {
        toggleCell = $('.predoc[record=\"'+record+'\"]');
        textCell = $('.predocText[record=\"'+record+'\"]');
    } else if (parentCell.hasClass('postdocDates')) {
        toggleCell = $('.postdoc[record=\"'+record+'\"]');
        textCell = $('.postdocText[record=\"'+record+'\"]');
    } else if (parentCell.hasClass('kCareerDates')) {
        toggleCell = $('.kCareer[record=\"'+record+'\"]');
        textCell = $('.kCareerText[record=\"'+record+'\"]');
    }
    if (toggleCell && textCell) {
        const startValue = parentCell.find('.startDate').val();
        const endValue = parentCell.find('.endDate').val();     
        const grantTypeOb = parentCell.find('.grantType');   
        // if a grant type exists, the grant type must have a value AND has a start value
        if (
            (
                !grantTypeOb
                || (grantTypeOb.find('option:selected').val() !== '')
            )
            && ($validText)
        ) {
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

function makeRange(string $recordId, bool $showGrantTypes, array $grantTypes, string $startDate = "", string $endDate = "", string $defaultTitle = "", string $defaultGrantType = ""): string {
    $startLine = "Start: <input type='date' class='startDate pickDate' value='$startDate' record='$recordId' />";
    $endLine = "End: <input type='date' class='endDate pickDate' value='$endDate' record='$recordId' />";
    $titleLine = "<textarea class='title pickDate' record='$recordId' placeholder='Research Project Topics'>$defaultTitle</textarea>";
    if ($showGrantTypes) {
        if ($defaultGrantType == "") {
            $options = ["<option value='' selected>---SELECT---</option>"];
        } else {
            $options = [];
        }
        foreach ($grantTypes as $index => $label) {
            $selected = ($index == $defaultGrantType) ? "selected" : "";
            $options[] = "<option value='$index' $selected>$label</option>";
        }
        $id = "grant_type___".$recordId."_".bin2hex(random_bytes(10));
        $titleLine = "<label for='$id'>Type: </label><select class='grantType pickDate' id='$id' record='$recordId'>".implode("", $options)."</select><br/>".$titleLine;
    }
    return $titleLine."<br />".$startLine."<br />".$endLine;
}

function makeCustomRange(array $row, bool $showGrantTypes, array $grantTypes): string {
    return makeRange($row['record_id'], $showGrantTypes, $grantTypes, $row['custom_start'], $row['custom_end'], $row['custom_title'], $row['custom_type']);
}

# $idx is either a value to be matched or an array of values to be matched
# When PHP 7 is over, update type of $idx to array|int
function getInstanceToUploadTo(array &$maxUploadInstance, array $testFields, array $fieldsWithIdxValues, string $recordId, $idx): int {
    if (!is_array($idx)) {
        $idx = [$idx];
    }
    foreach ($fieldsWithIdxValues[$recordId] as $byInstance) {
        foreach ($byInstance as $instance => $value) {
            if (in_array($value, $idx)) {
                return (int) $instance;
            }
        }
    }

    $instances = array_keys($testFields[$recordId] ?? []);
    if (isset($maxUploadInstance[$recordId])) {
        $newInstanceToUploadTo = (int) ($maxUploadInstance[$recordId] + 1);
    } else {
        $newInstanceToUploadTo = empty($instances) ? 1 : (((int) max($instances)) + 1);
    }
    $maxUploadInstance[$recordId] = $newInstanceToUploadTo;
    return $newInstanceToUploadTo;
}

function makeCustomGrantUploadRow(array $dates, string $recordId, int $instanceToUploadTo, int $roleIndex, int $typeIndex = -1): array {
    $startDate = $dates[0] ?? "";
    $endDate = $dates[1] ?? "";
    $title = $dates[2] ?? "";
    $grantType = $dates[3] ?? "";
    return [
        "record_id" => $recordId,
        "redcap_repeat_instrument" => "custom_grant",
        "redcap_repeat_instance" => $instanceToUploadTo,
        "custom_start" => $startDate,
        "custom_end" => $endDate,
        "custom_title" => $title,
        "custom_role" => $roleIndex,
        "custom_type" => (string) (($typeIndex > 0) ? $typeIndex : $grantType),
        "custom_created" => date("Y-m-d"),
        "custom_last_update" => date("Y-m-d"),
        "custom_grant_complete" => "2",
    ];
}

# $idx is either a value to be matched or an array of values to be matched
# When PHP 7 is over, update type of $idx to array|int

function getInstancesToDelete(array $fieldsWithIdxValues, array $records, array $allRecords, $idx): array {
    if (!is_array($idx)) {
        $idx = [$idx];
    }
    $instancesToDelete = [];
    foreach ($fieldsWithIdxValues as $recordId => $byInstance) {
        $recordId = Sanitizer::getSanitizedRecord($recordId, $allRecords);
        if ($recordId && in_array($recordId, $records)) {
            foreach ($byInstance as $instance => $value) {
                if (in_array($value, $idx)) {
                    if (!isset($instancesToDelete[$recordId])) {
                        $instancesToDelete[$recordId] = [];
                    }
                    $instancesToDelete[$recordId][] = $instance;
                }
            }
        }
    }
    return $instancesToDelete;
}