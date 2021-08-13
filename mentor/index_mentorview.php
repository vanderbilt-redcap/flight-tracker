<?php
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

use \Vanderbilt\CareerDevLibrary\LDAP;
use \Vanderbilt\CareerDevLibrary\LdapLookup;

require_once dirname(__FILE__)."/preliminary.php";
require_once dirname(__FILE__)."/../small_base.php";
require_once dirname(__FILE__)."/base.php";
require_once dirname(__FILE__)."/../CareerDev.php";
require_once(dirname(__FILE__)."/../classes/Autoload.php");

require_once dirname(__FILE__).'/_header.php';


if ($_REQUEST['uid'] && DEBUG) {
    $userid2 = $_REQUEST['uid'];
    $uidString = "&uid=$userid2";
} else {
    $userid2 = $userid;
    $uidString = "";
}

$userids = Download::userids($token, $server);

$menteeRecordId = FALSE;
if ($_REQUEST['menteeRecord']) {
    $menteeRecordId = $_REQUEST['menteeRecord'];
    list($myMentees, $myMentors) = getMenteesAndMentors($menteeRecordId, $userid2, $token, $server);
} else {
    throw new \Exception("You must specify a mentee record!");
}

echo "<link rel='stylesheet' type='text/css' href='".Application::link("mentor/css/simptip.css")."' media='screen,projection' />\n";

$names = Download::names($token, $server);
$menteeName = $names[$menteeRecordId];

$metadata = Download::metadata($token, $server);
$allMetadataForms = REDCapManagement::getFormsFromMetadata($metadata);
$metadata = filterMetadata($metadata);
$metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
$choices = REDCapManagement::getChoices($metadata);
$notesFields = getNotesFields($metadataFields);

list($firstName, $lastName) = getNameFromREDCap($userid2, $token, $server);
$otherMentors = REDCapManagement::makeConjunction($myMentors["name"]);
$otherMentees = REDCapManagement::makeConjunction($myMentees["name"]);

$fields = array_merge(["record_id", "mentoring_userid", "mentoring_last_update", "mentoring_panel_names", "mentoring_userid"], $metadataFields);
$redcapData = Download::fieldsForRecords($token, $server, $fields, [$menteeRecordId]);
if ($_REQUEST['instance']) {
    $currInstance = $_REQUEST['instance'];
} else {
    $maxInstance = REDCapManagement::getMaxInstance($redcapData, "mentoring_agreement", $menteeRecordId);
    $currInstance = $maxInstance + 1;
}
$dateToRemind = getDateToRemind($redcapData, $menteeRecordId, $currInstance);
$menteeInstance = getMaxInstanceForUserid($redcapData, $menteeRecordId, $userids[$menteeRecordId]);
$surveysAvailableToPrefill = getMySurveys($userid2, $token, $server, $menteeRecordId, $currInstance);
list($priorNotes, $instances) = makePriorNotesAndInstances($redcapData, $notesFields, $menteeRecordId, $menteeInstance);
$currInstanceRow = [];
$currInstanceRow = REDCapManagement::getRow($redcapData, $menteeRecordId, "mentoring_agreement", $currInstance);
$menteeInstanceRow = REDCapManagement::getRow($redcapData, $menteeRecordId, "mentoring_agreement", $menteeInstance);

$completeURL = Application::link("mentor/index_complete.php").$uidString."&menteeRecord=$menteeRecordId&instance=$currInstance";

?>
<form id="tsurvey" name="tsurvey">
<input type="hidden" class="form-hidden-data" name="mentoring_start" id="mentoring_start" value="<?= date("Y-m-d H:i:s") ?>">
<section class="bg-light">
    <div class="container">
        <div class="row">
            <div class="col-lg-12">


                <h2 style="color: #727272;">Hi, <?= $firstName ?>!</h2>

                <?= makeSurveyHTML($menteeName, "mentee", $currInstanceRow, $metadata) ?>

            </div>

        </div>

        <div class="row">
            <div class="col-lg-12 tdata">
                <h4>Please fill out the checklist below while dialoging with your mentee. The mentee's responses have been pre-filled.</h4>
                <input type="hidden" class="form-hidden-data" name="mentoring_start" id="mentoring_start" value="<?= date("Y-m-d h:i:s") ?>">
                <?= (!empty($surveysAvailableToPrefill)) ? makePrefillHTML($surveysAvailableToPrefill, $uidString) : "" ?>

                    <?php

                    $htmlRows = [];
                    $sections = [];
                    $tablesShown = [];
                    $tableNum = 1;
                    $i = 0;
                    $skipFieldTypes = ["file", "text"];
                    $agreementSigned = agreementSigned($redcapData, $menteeRecordId, $currInstance);
                    foreach ($metadata as $row) {
                        if ($row['section_header'] && !in_array($row['field_type'], $skipFieldTypes)) {
                            list($sec_header, $sectionDescription) = parseSectionHeader($row['section_header']);
                            $sections[$tableNum] = $sec_header;
                            $encodedSection = REDCapManagement::makeHTMLId($row['section_header']);

                            if ($tableNum > 1) {
                                $htmlRows[] = "</tbody></table>";
                            }
                            // $htmlRows[] = "<div class='subHeader'>$sectionDescription</div>";
                            $tableId = "quest$tableNum";
                            // $hasAnswers = hasDataInSection($metadata, $row['section_header'], $menteeRecordId, $menteeInstance, "mentoring_agreement", $menteeInstanceRow);
                            $hasAnswers = TRUE;
                            if ($hasAnswers) {
                                $tablesShown[] = $tableId;
                                $displayTable = "";
                            } else {
                                $displayTable = " display: none;";
                            }
                            $htmlRows[] = "<table id='$tableId' class='table $encodedSection' style='margin-left: 0px;$displayTable'>";
                            $htmlRows[] = '<thead>';
                            $htmlRows[] = '<tr>';
                            $htmlRows[] = '<th style="text-align: left;" scope="col"></th>';
                            $htmlRows[] = '<th style="text-align: center; border-right: 0px;" scope="col"></th>';
                            $htmlRows[] = '<th style="text-align: center;" scope="col"></th>';
                            $htmlRows[] = '<th style="text-align: center;" scope="col"></th>';
                            $htmlRows[] = '</tr>';
                            $htmlRows[] = '<tr>';
                            $htmlRows[] = '<th style="text-align: left;" scope="col">question</th>';
                            $htmlRows[] = '<th style="text-align: center;" scope="col">mentor responses</th>';
                            $htmlRows[] = '<th style="text-align: center;" scope="col">latest note<br>(click for full conversation)</th>';
                            $htmlRows[] = '<th style="text-align: center;" scope="col">mentee responses</th>';
                            $htmlRows[] = '</tr>';
                            $htmlRows[] = '</thead>';
                            $htmlRows[] = '<tbody>';
                            
                            $tableNum++;
                        }
                        $field = $row['field_name'];
                        if (!in_array($field, $notesFields) && !in_array($row['field_type'], $skipFieldTypes)) {
                            $i++;
                            $prefices = ["radio" => "exampleRadiosh", "checkbox" => "exampleChecksh", "notes" => "exampleTextareash"];

                            $menteeFieldValues = [];
                            $mentorFieldValues = [];
                            if (($row['field_type'] == "radio") || ($row['field_type'] == "notes")) {
                                $menteeValue = REDCapManagement::findField([$menteeInstanceRow], $menteeRecordId, $field, "mentoring_agreement", $menteeInstance);
                                $mentorValue = REDCapManagement::findField([$currInstanceRow], $menteeRecordId, $field, "mentoring_agreement", $currInstance);
                                if ($menteeValue) {
                                    $menteeFieldValues = [$menteeValue];
                                }
                                if ($mentorValue) {
                                    $mentorFieldValues = [$mentorValue];
                                }
                            } else if ($row['field_type'] == "checkbox") {
                                foreach ($choices[$field] as $index => $label) {
                                    $value = REDCapManagement::findField([$menteeInstanceRow], $menteeRecordId, $field."___".$index, "mentoring_agreement", $menteeInstance);
                                    if ($value) {
                                        $menteeFieldValues[] = $index;
                                    }

                                    $value = REDCapManagement::findField([$currInstanceRow], $menteeRecordId, $field."___".$index, "mentoring_agreement", $currInstance);
                                    if ($value) {
                                        $mentorFieldValues[] = $index;
                                    }
                                }
                            }
                            $specs = [
                                "mentor" => ["values" => $mentorFieldValues, "suffix" => "", "colClass" => "thementor", "status" => "", ],
                                "mentee" => ["values" => $menteeFieldValues, "suffix" => "_menteeanswer", "colClass" => "thementee", "status" => "disabled", ],
                            ];
                            if (fieldValuesAgree($mentorFieldValues, $menteeFieldValues) || ($row['field_type'] == "notes")) {
                                $status = "agree";
                            } else {
                                $status = "disagree";
                            }
                            if ($agreementSigned) {
                                $statusClass = "";
                            } else {
                                $statusClass = " class='$status'";
                            }
                            $htmlRows[] = "<tr id='$field-tr'$statusClass>";
                            $htmlRows[] = '<th scope="row">'.$row['field_label'].'</th>';
                            $prefix = $prefices[$row['field_type']];
                            foreach ($specs as $key => $spec) {
                                $suffix = "";
                                if (in_array($row['field_type'], ["checkbox", "radio"])) {
                                    $htmlRows[] = "<td class='{$spec['colClass']}'>";
                                    foreach ($choices[$field] as $index => $label) {
                                        $name = $prefix.$field.$spec['suffix'];
                                        $id = $name."___".$index;
                                        $selected = "";
                                        if (in_array($index, $spec['values'])) {
                                            $selected = "checked";
                                        }
                                        $htmlRows[] = '<div class="form-check"><input class="form-check-input" type="'.$row['field_type'].'" name="'.$name.'" id="'.$id.'" value="'.$index.'" '.$selected.' '.$spec['status'].'><label class="form-check-label" for="'.$id.'">'.$label.'</label></div>';
                                    }
                                    $htmlRows[] = '</td>';
                                } else if (($row['field_type'] == "notes") && ($key == "mentor")) {
                                    $name = $prefix.$field.$spec['suffix'];
                                    $id = $name;
                                    $mentorValue = $spec['values'][0];
                                    $menteeValue = REDCapManagement::findField($redcapData, $menteeRecordId, $field, "mentoring_agreement", $menteeInstance);
                                    if ($mentorValue) {
                                        $value = $mentorValue;
                                    } else {
                                        $value = $menteeValue;
                                    }

                                    $htmlRows[] = "<td class='{$spec['colClass']}' colspan='3'>";
                                    $htmlRows[] = '<div class="form-check" style="height: 100px;"><textarea class="form-check-input" name="'.$name.'" id="'.$id.'">'.$value.'</textarea></div>';
                                    $htmlRows[] = '</td>';
                                }
                                if ($key == "mentor") {
                                    $htmlRows[] = makeNotesHTML($field, [$menteeInstanceRow], $menteeRecordId, $menteeInstance, $notesFields);
                                }
                            }
                        }
                        $htmlRows[] = '</tr>';
                    }
                    $htmlRows[] = "</tbody></table>";
                    echo implode("\n", $htmlRows)."\n";

                    ?>

                <style type="text/css">
                    .table {
                        width: 96%;
                        margin-left: 4%;
                    }

                    thead th {
                        border-top: 0px solid #dee2e6 !important;
                        font-size: 11px;
                        text-transform: uppercase;
                        font-family: proxima-soft, sans-serif;
                        border-bottom: unset !important;
                        letter-spacing: 1px;
                    }

                    thead th:nth-of-type(2),
                    thead th:nth-of-type(3),
                    {
                        width: 18.5%;
                        padding-left: 0px !important;
                        padding-right: 0px !important;
                        border-right: 1px solid #cccccc;
                    }

                    thead th:nth-of-type(3) {
                        width: 31%;
                    }

                    thead th:nth-of-type(1),
                    tbody tr td,
                    thead th:nth-of-type(2),
                    thead th:nth-of-type(3) {
                        border-right: 1px solid #cccccc;
                    }

                    tr td img,
                    tr th img {
                        width: 30px;
                        padding-left: 0px !important;
                        padding-right: 0px !important;
                    }

                    tbody tr td,
                    tbody tr th {
                        font-family: proxima-soft, sans-serif;
                        font-size: 15px;
                        line-height: 20px;
                        font-weight: 200;
                        padding-top: 1.3em;
                        padding-bottom: 1.3em;
                    }

                    tbody tr:nth-child(odd) {
                        background-color: #00000008;
                    }

                    tbody tr td:nth-of-type(1) img {
                        margin-top: 2px;
                    }

                    tbody tr {
                        line-height: 30px;
                    }

                    .form-control {
                        font-size: 14px;
                    }

                    input:placeholder-shown {
                        border: 0px;
                        background: none;
                    }
                    tbody tr.disagree td:nth-of-type(1),
                    tbody tr.disagree td:nth-of-type(2),
                    tbody tr.disagree th:nth-of-type(1){
                        background-color:#af000024 !important;
                        font-weight: bold;
                    }
                    thead th:nth-of-type(4),
                    tbody tr td:nth-of-type(3){
                        background-color:#af000024 !important;
                    }
                    tbody tr th:nth-of-type(1),
                    tbody tr td:nth-of-type(1) {
                        padding-left: 1em !important;
                        padding-right: 1em !important;
                        text-align: left;
                        border-right: 1px solid #cccccc;
                    }

                    tbody tr th:nth-of-type(1),
                    tbody tr td:nth-of-type(5),
                    tbody tr td:nth-of-type(6) {
                        padding-top: 1.4em !important;
                    }

                    tbody tr td:nth-of-type(3),
                    tbody tr td:nth-of-type(4),
                    {
                        padding-top: 1.4em !important;
                        text-align: left;
                    }

                    tbody tr td:nth-of-type(1) small {
                        margin-top: -5px;
                        display: block;
                    }

                    textarea {
                        display: block;
                        box-sizing: padding-box;
                        overflow: hidden;
                    }

                    textarea.form-check-input {
                        width: 100%;
                        height: 100px;
                        overflow: scroll !important;
                    }

                    .tnote {
                        font-size: 15px;
                        line-height: 20px;
                        overflow: hidden;
                    }

                    .tnote_d {
                        background-color: unset !important;
                        border: 0px solid !important;
                        color: #a0a0a0;
                        height: 20px;
                        overflow: hidden;
                    }

                    .tnote_e {
                        background-color: unset !important;
                        border: 0px solid !important;
                        color: #000000;
                        height: auto;
                        overflow: unset
                    }

                    tbody tr th:nth-of-type(1) img {
                        mkargin-left: 10px;
                        mkargin-right: 10px;
                    }

                    tkhead th:nth-of-type(1)::before {
                        content: "Discussed";
                        position: absolute;
                        top: 128px;
                        left: 77px;
                    }

                    tbody tr td a {
                        color: #17a2b8;
                        text-decoration: underline;
                    }

                    .red {
                        color: #af3017
                    }

                    .orange {
                        color: #de8a12;
                    }

                    .notoverdue {
                        padding-top: 1.4em !important;
                    }

                    .tnoter {
                        font-size: 16px;
                        line-height: 20px;
                        font-family: proxima-nova
                    }

                    .row_red th,
                    .row_red td {
                        background-color: #ea0e0e30
                    }

                    tr td.thementor,tr td.thementee {
                        padding-top: 1.5em;
                        padding-bottom: 1.5em;
                    }

                    .subHeader {
                        text-transform: none;
                        font-weight: 500;
                        letter-spacing: normal;
                        font-size: 16px;
                        font-family: proxima-nova;
                        cursor: pointer;
                    }

                    .verticalheader {
                        text-align: right;
                        width: 0px;
                        transform: rotate(-90deg);
                        -webkit-transform: rotate(-90deg);
                        -moz-transform: rotate(-90deg);
                        -ms-transform: rotate(-90deg);
                        -o-transform: rotate(-90deg);
                        filter: progid:DXImageTransform.Microsoft.BasicImage(rotation=3);
                        margin-left: -15px;
                        FONT-VARIANT: JIS04;
                        position: relative;
                        top: 377px;
                        text-transform: uppercase;
                        font-weight: 700;
                        font-family: proxima-nova;
                        letter-spacing: 8px;
                        white-space: nowrap;
                    }

                    #quest1 {
                        background-color: #41a9de14 !important;
                    }
                    #vh1 {
                        /* color: #41a9de !important;  */
                    }

                    #quest2 {
                        background-color: #f6dd6645 !important;
                    }
                    #vh2 {
                        /* color: #f6dd66 !important;     top: 291px;   */
                    }

                    #quest3 {
                        background-color: #ec9d5045 !important;
                    }

                    #quest4 {
                        background-color: #5fb7494a !important;
                    }

                    #quest5 {
                        background-color: #a6609721 !important;
                    }

                    #quest6{
                        background-color: #9ba4ac21 !important;
                    }

                    #quest7{
                        background-color: #41a9de14 !important;
                    }

                    #quest8{
                        background-color: #f6dd6645 !important;
                    }

                    #quest9 {
                        background-color: #a6609721 !important;
                    }

                    #quest10{
                        background-color: #9ba4ac21 !important;
                    }

                    #quest11{
                        background-color: #41a9de14 !important;
                    }

                    #quest12{
                        background-color: #f6dd6645 !important;
                    }

                    .form-check-input { margin-right: 6px !important; }

                </style>







            </div>

        </div>




    </div>
</section>
</form>


<p style="text-align: center;">Saving will enqueue an automated email to follow up, to be sent on <?= REDCapManagement::MDY2LongDate($dateToRemind) ?>.</p>
<p style="text-align: center;"><button type="button" class="btn btn-info" onclick="saveagreement(function() { window.location='<?= $completeURL ?>'; });">save, view &amp; sign final agreement</button></p
<p style="height: 200px"></p>
<div class="fauxcomment" style="display: none;"></div>

<?php include dirname(__FILE__).'/_footer.php'; ?>

<style type="text/css">
    body {

        font-family: europa, sans-serif;
        letter-spacing: -0.5px;
        font-size: 1.3em;
    }
    .h2, h2 {
        font-weight: 700;
    }
    .bg-light {
        background-color: #ffffff!important;
    }
    .box_bg{height: 371px;width: 100%;background-size: contain;    padding: 34px;
        padding-top: 26px;background-image: url(<?= Application::link("mentor/img/box_trans.png") ?>)}
    .box_bg img{width: 142px;
        margin-left: -29px;}
    .box_body{    font-family: synthese, sans-serif;
        font-weight: 200;
        font-size: 17px;
        line-height: 22px;
        padding-top: 22px;
    }
    .box_body button{font-family: europa, sans-serif;}
    .box_white{background-color: #ffffff}
    .box_orange{background-color: #de6339}

    .box_title{    font-size: 23px;
        line-height: 27px;
    }
    .boxa .box_title strong{
        color: #26798a;
    }
    .boxb .box_title strong{
        color: #de6339;
    }
    .tcontainer{
        display: table;
        wkidth:90vw;
        height: 323px;
        bgorder: 3px solid steelblue;
        margin: auto;
    }

    .getstarted{
        display: table-cell;
        text-align: center;
        vertical-align: middle;
        margin: auto;
        bgackground: tomato;
        width: 50vw; height: 323px;
        background-color: #056c7d; text-align: center;
    }
    .btn-light{color: #26798a}
    .lm{text-align: center}
    .lm button{color:#000000;}

    #nprogress .bar {
        background: #1ABB9C
    }
    #nprogress .peg {
        box-shadow: 0 0 10px #1ABB9C, 0 0 5px #1ABB9C
    }
    #nprogress .spinner-icon {
        border-top-color: #1ABB9C;
        border-left-color: #1ABB9C
    }

    .opacity100{
        opacity: 1 !important;
    }
</style>
<link rel="stylesheet" href="<?= Application::link("mentor/jquery.sweet-modal.min.css") ?>" />
<script src="<?= Application::link("mentor/jquery.sweet-modal.min.js") ?>"></script>
<?= makePercentCompleteJS() ?>
<script type="text/javascript">
    dfn = function(obj) {
        objta = "#" + obj + " td:nth-of-type(4) .tnote";
        obj = "#" + obj + " .dfn";
        if ($(obj).attr('src') == '<?= Application::link("mentor/img/images/dfb_off_03.png") ?>') {
            $(obj).attr('src', '<?= Application::link("mentor/img/images/dfb_on_03.png") ?>');
            $(objta).removeClass('tnote_d');
            $(objta).addClass('tnote_e');
        } else {
            $(obj).attr('src', '<?= Application::link("mentor/img/images/dfb_off_03.png") ?>');
            $(objta).removeClass('tnote_e');
            $(objta).addClass('tnote_d');
        }
    }

    jQuery(document).ready(function() {


        $("tbody tr td:nth-of-type(2)").each(function(index, element) {
            updatequest = $(this).html();
            updatequest = updatequest.replace()
        });

        $("tbody tr .thementee").each(function(index, element) {
            $(this).find('input').prop( "disabled",true);
            //updatequest = updatequest.replace()
        });

        $("input[type=checkbox].form-check-input").change(function() { updateData(this); });
        $("input[type=radio].form-check-input").change(function() { updateData(this); });
        $("textarea.form-check-input").blur(function() { updateData(this); });


    <?php
        $sectionsToShow = getSectionsToShow($userid2, array_values($sections), $redcapData, $menteeRecordId, $currInstance);
        foreach ($sections as $tableNum => $header) {
            $encodedSection = REDCapManagement::makeHTMLId($header);
            $header = strtolower($header);
            $header = addslashes(beautifyHeader($header));
            echo "var header$tableNum = '$encodedSection';\n";
            echo "\$('#quest".$tableNum."').before('<div class=\"verticalheader\" id=\"vh$tableNum\">$header</div>');\n";
        }
        ?>
    });

    function valuesAgree(name1, name2) {
        var values1 = [];
        $('[name='+name1+']:checked').each(function(idx, ob) {
            values1.push($(ob).val());
        });
        var values2 = [];
        $('[name='+name2+']:checked').each(function(idx, ob) {
            values2.push($(ob).val());
        });
        if (values1.length != values2.length) {
            return false;
        }
        for (var i=0; i < values1.length; i++) {
            if (values2.indexOf(values1[i]) < 0) {
                return false;
            }
            if (values1.indexOf(values2[i]) < 0) {
                return false;
            }
        }
        return true;
    }

    function getOtherName(ob) {
        if ($(ob).attr('name').match(/_menteeanswer/)) {
            return $(ob).attr('name').replace(/_menteeanswer/, '');
        } else {
            return $(ob).attr('name') + '_menteeanswer';
        }
    }

    function changeHighlightingFromAgreements(ob) {
        <?php
        if (!agreementSigned($redcapData, $menteeRecordId, $currInstance)) {
        ?>
            if (valuesAgree($(ob).attr('name'), getOtherName(ob))) {
                $(ob).closest("tr").removeClass("disagree");
                $(ob).closest("tr").addClass("agree");
            } else {
                $(ob).closest("tr").addClass("disagree");
                $(ob).closest("tr").removeClass("agree");
            }
        <?php
        }
        ?>

    }

    function updateData(ob) {
        console.log("updateData with "+$(ob).attr("id"));
        changeHighlightingFromAgreements(ob);
        var suffix = '';
        if ($(ob).attr("id").match(/_menteeanswer/)) {
            suffix = '_menteeanswer';
        }
        if ($(ob).attr("id").match(/^exampleChecksh/)) {
            let fullFieldName = $(ob).attr("id").replace(/^exampleChecksh/, "").replace(/_menteeanswer/, "");

            let a = $(ob).attr("id").replace(/^exampleChecksh/, "").replace(/_menteeanswer/, "").split(/___/)
            let fieldName = a[0]
            let value = a[1]

            let checkValue = $(ob).is(":checked") ? "1" : "0"
            let thisbox = "#exampleChecksh"+fieldName+suffix+"___"+value;

            $(thisbox).addClass("simptip-position-left").attr("data-tooltip","option saved!");
            let type = "checkbox";
            console.log(thisbox+" "+type);

            $.post('<?= Application::link("mentor/change.php").$uidString ?>', {
                type: type,
                record: '<?= $menteeRecordId ?>',
                instance: '<?= $currInstance ?>',
                field_name: fullFieldName,
                value: checkValue,
                userid: '<?= $userid2 ?>'
            }, function (html) {
                console.log(html);
                $(thisbox).delay(300).removeClass("simptip-position-left").removeAttr("data-tooltip");
            })
        } else if ($(ob).attr("id").match(/^exampleRadiosh/)) {
            let a = $(ob).attr("id").replace(/^exampleRadiosh/, "").replace(/_menteeanswer/, "").split(/___/)
            let fieldName = a[0]
            let value = a[1]
            let thisbox = "#exampleRadiosh"+fieldName+suffix+"___"+value;
            $(thisbox).addClass("simptip-position-left").attr("data-tooltip","option saved!");

            let type = "radio";
            console.log(thisbox+" "+type);
            $.post('<?= Application::link("mentor/change.php").$uidString ?>', {
                type: type,
                record: '<?= $menteeRecordId ?>',
                instance: '<?= $currInstance ?>',
                field_name: fieldName,
                value: value,
                userid: '<?= $userid2 ?>'
            }, function(html) {
                console.log(html);
                $(thisbox).delay(300).removeClass("simptip-position-left").removeAttr("data-tooltip");
            });
        } else if ($(ob).attr("id").match(/^exampleTextareash/)) {
            let fieldName = $(ob).attr("id").replace(/^exampleTextareash/, "");
            let value = $(ob).val();
            let thisbox = "#exampleTextareash"+fieldName;
            $(thisbox).attr("disabled", true);
            let type = "textarea";
            console.log(thisbox+" "+type);
            $.post('<?= Application::link("mentor/change.php").$uidString ?>', {
                type: type,
                record: '<?= $menteeRecordId ?>',
                instance: '<?= $currInstance ?>',
                field_name: fieldName,
                value: value,
                userid: '<?= $userid2 ?>'
            }, function(html) {
                console.log(html);
                $(thisbox).attr("disabled", false);
            });
        }
        $('.chart').data('easyPieChart').update(getPercentComplete());
    }

</script>

<style type="text/css">
    h4 {
        color: #5b8ac3;
        font-family: proxima-soft, sans-serif;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 14px;
        letter-spacing: 0px;
    }

    .tdata {
        padding-top: 30px;
    }

    .fauxcomment {
        position: absolute;
        z-index: 1000;
        width: 324px;
        background-color: #ffffff;
        padding: 13px;
        padding-bottom: 0px;
        -webkit-box-shadow: 0px 0px 13px 0px rgba(0, 0, 0, 0.47);
        -moz-box-shadow: 0px 0px 13px 0px rgba(0, 0, 0, 0.47);
        box-shadow: 0px 0px 13px 0px rgba(0, 0, 0, 0.47);
    }

    .fauxcomment>img {
        width: 324px;
    }

    .acomment {
        font-size: 11px;
        border: 1px solid #eceff5;
        color: #747373;
        font-weight: 700;
        border-radius: 4px;
        padding: 7px;
        margin-bottom: 5px;
        display: inline-block;
        max-width: 95%;
        width: 95%;
    }

    .acomment.odd {
        background-color: #eceff5
    }

    ::-webkit-scrollbar {
        -webkit-appearance: none;
        width: 10px;
    }

    ::-webkit-scrollbar-thumb {
        border-radius: 5px;
        background-color: rgba(0, 0, 0, .5);
        -webkit-box-shadow: 0 0 1px rgba(255, 255, 255, .5);
    }

    .timestamp {
        display: block;
        width: 100%;
        margin-left: 6px;
        font-weight: 100;
        color: #a8a8a8;
    }

    input.addcomment {
        margin-bottom: 11px;
        border-radius: 5px;
        border: 1px solid #eeeeee;
        padding: 4px;
        font-size: 13px;
        padding-left: 6px;
        width: 90%;
    }

    .datemarker {

        color: #000000;
        display: inline-block;
        height: 20px;
        width: 86%;
        text-align: center;
        font-weight: 700;
        margin: 6px;
        font-size: 13px;
    }

    .closecomments {
        display: inline-block;
        width: 101%;
        height: 26px;
        margin-bottom: 7px;
        float: right;
        text-align: right;
    }
    .chart {
        position: relative;
        display: inline-block;
        width: 110px;
        height: 110px;
        margin-top: 5px;
        margin-bottom: 5px;
        text-align: center;
    }
    .percent {
        display: inline-block;
        line-height: 106px;
        z-index: 2;
        font-size: 25px;
        letter-spacing: -1px;
        background-color: #303034;
        border-radius: 86px;
        width: 87px;
        height: 100px;
        margin-top: 1px;
        color: #ffffff;
    }
    .percent:after {
        content: '%';
        margin-left: 0.1em;
        font-size: .8em;
    }
    .chart canvas{

        height: 110px;
        width: 110px;
        margin-top: -100px;
        display: block;
    }

</style>

<?= makeCommentJS($userid2, $menteeRecordId, $menteeInstance, $currInstance, $priorNotes, $menteeName, $dateToRemind, FALSE) ?>

<style type="text/css">
    body {

        font-family: europa, sans-serif;
        letter-spacing: -0.5px;
        font-size: 1.3em;
    }
    .h2, h2 {
        font-weight: 700;
    }
    .bg-light {
        background-color: #ffffff!important;
    }
    .box_bg{height: 371px;width: 100%;background-size: contain;    padding: 34px;
        padding-top: 26px;background-image: url(<?= Application::link("mentor/img/box_trans.png") ?>)}
    .box_bg img{width: 142px;
        margin-left: -29px;}
    .box_body{    font-family: synthese, sans-serif;
        font-weight: 200;
        font-size: 17px;
        line-height: 22px;
        padding-top: 22px;
    }
    .box_body button{font-family: europa, sans-serif;}
    .box_white{background-color: #ffffff}
    .box_orange{background-color: #de6339}

    .box_title{    font-size: 23px;
        line-height: 27px;
    }
    .boxa .box_title strong{
        color: #26798a;
    }
    .boxb .box_title strong{
        color: #de6339;
    }
    .tcontainer{
        display: table;
        wkidth:90vw;
        height: 323px;
        bgorder: 3px solid steelblue;
        margin: auto;
    }

    .getstarted{
        display: table-cell;
        text-align: center;
        vertical-align: middle;
        margin: auto;
        bgackground: tomato;
        width: 50vw; height: 323px;
        background-color: #056c7d; text-align: center;
    }
    .btn-light{color: #26798a}
    .lm{text-align: center}
    .lm button{color:#000000;}

    #nprogress .bar {
        background: #1ABB9C
    }
    #nprogress .peg {
        box-shadow: 0 0 10px #1ABB9C, 0 0 5px #1ABB9C
    }
    #nprogress .spinner-icon {
        border-top-color: #1ABB9C;
        border-left-color: #1ABB9C
    }

    .opacity100{
        opacity: 1 !important;
    }
</style>


<?php

function hasDataInSection($metadata, $sectionHeader, $recordId, $instance, $instrument, $dataRow) {
    $sectionFields = REDCapManagement::getFieldsUnderSection($metadata, $sectionHeader);
    $indexedMetadata = REDCapManagement::indexMetadata($metadata);
    $choices = REDCapManagement::getChoices($metadata);
    foreach ($sectionFields as $field) {
        if ($indexedMetadata[$field]['field_type'] == "checkbox") {
            foreach ($choices[$field] as $index => $value) {
                $value = REDCapManagement::findField([$dataRow], $recordId, $field."___".$index, $instrument, $instance);
                if ($value) {
                    $hasAnswers = TRUE;
                    break; // choices
                }
            }
        } else {
            $value = REDCapManagement::findField([$dataRow], $recordId, $field, $instrument, $instance);
            if ($value) {
                return TRUE;
            }
        }
    }
    return FALSE;
}