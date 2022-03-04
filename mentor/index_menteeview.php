<?php

namespace Vanderbilt\CareerDevLibrary;

require_once dirname(__FILE__)."/preliminary.php";
require_once dirname(__FILE__)."/../small_base.php";
require_once dirname(__FILE__)."/base.php";
require_once(dirname(__FILE__)."/../classes/Autoload.php");

require_once dirname(__FILE__).'/_header.php';

if (isset($_REQUEST['uid']) && MMA_DEBUG) {
    $userid2 = REDCapManagement::sanitize($_REQUEST['uid']);
    $uidString = "&uid=".$userid2;
} else {
    $userid2 = $hash ? $hash : Application::getUsername();
    $uidString = $hash ? "&hash=".$hash : "";
}
$phase = isset($_GET['phase']) ? REDCapManagement::sanitize($_GET['phase']) : "";

$dateToRemind = "now";

echo "<link rel='stylesheet' type='text/css' href='".Application::link("mentor/css/simptip.css")."' media='screen,projection' />\n";

$names = Download::names($token, $server);

$menteeRecordId = FALSE;
if ($_REQUEST['menteeRecord']) {
    $menteeRecordId = REDCapManagement::sanitize($_REQUEST['menteeRecord']);
    list($myMentees, $myMentors) = MMAHelper::getMenteesAndMentors($menteeRecordId, $userid2, $token, $server);
} else {
    throw new \Exception("You must specify a mentee record!");
}
if (isset($_GET['test'])) {
    echo "myMentees: ".json_encode($myMentees)."<br>";
    echo "myMentors: ".json_encode($myMentors)."<br>";
}

$metadata = Download::metadata($token, $server);
$allMetadataForms = REDCapManagement::getFormsFromMetadata($metadata);
$metadata = REDCapManagement::filterMetadataForForm($metadata, "mentoring_agreement");
$metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
$choices = REDCapManagement::getChoices($metadata);
$notesFields = MMAHelper::getNotesFields($metadataFields);

list($firstName, $lastName) = MMAHelper::getNameFromREDCap($userid2, $token, $server);
$otherMentors = REDCapManagement::makeConjunction($myMentors["name"] ?? []);

$redcapData = Download::fieldsForRecords($token, $server, array_merge(["record_id"], $metadataFields), [$menteeRecordId]);
if ($_REQUEST['instance']) {
    $currInstance = REDCapManagement::sanitize($_REQUEST['instance']);
} else if ($hash) {
    $currInstance = 1;
} else {
    $maxInstance = REDCapManagement::getMaxInstance($redcapData, "mentoring_agreement", $menteeRecordId);
    $currInstance = $maxInstance + 1;
}
if (!$hash) {
    $surveysAvailableToPrefill = MMAHelper::getMySurveys($userid2, $token, $server, $menteeRecordId, $currInstance);
} else {
    $surveysAvailableToPrefill = [];
}
$instanceRow = [];
foreach ($redcapData as $row) {
    if (($row['record_id'] == $menteeRecordId)
        && ($row['redcap_repeat_instrument'] == "mentoring_agreement")
        && ($row['redcap_repeat_instance'] == $currInstance)) {
        $instanceRow = $row;
    }
}

list($priorNotes, $instances) = MMAHelper::makePriorNotesAndInstances($redcapData, $notesFields, $menteeRecordId, $currInstance);

$welcomeText = "<p>Below is the Mentee-Mentor Agreement with <strong>$otherMentors</strong>. Once completed, $otherMentors will be notified to complete the agreement on their end.</p>";
$secHeaders = MMAHelper::getSectionHeadersWithMenteeQuestions($metadata);
$sectionsToShow = MMAHelper::getSectionsToShow($userid2, $secHeaders, $redcapData, $menteeRecordId, $currInstance);

?>

<section class="bg-light">
  <div class="container">
    <div class="row">
        <div class="col-lg-12">


            <h2 style="color: #727272;">Hi, <?= $firstName ?>!</h2>

            <?= MMAHelper::makeSurveyHTML($otherMentors, "mentor(s)", $instanceRow, $metadata) ?>

        </div>
    </div>
  </div>
</section>









<?php include dirname(__FILE__).'/_footer.php'; ?>
<script type="text/javascript">
    jQuery(document).ready(function(){

            jQuery(".box_bg").hover(function() { 
                if(jQuery(this).hasClass("boxa")){
                    boxcolor = "#26798a";
                    hoverout = "colorAqua";
                }
                if(jQuery(this).hasClass("boxb")){
                    boxcolor = "#de6339";
                    hoverout = "colorOrange";
                }
                if(jQuery(this).hasClass("boxc")){
                    boxcolor = "#ffffff";
                    hoverout = "colorWhite";
                } else {
                    hoverout = "colorBlack";
                }
                console.log("#"+this.id+' .box_title strong');
                jQuery(this).css("background-color", boxcolor); 
                jQuery("#"+this.id+" .box_title").addClass('colorWhite').removeClass('colorBlack');
                jQuery("#"+this.id+' .box_title strong').removeClass('colorBlack').removeClass('colorAqua').removeClass('colorOrange').addClass('colorWhite');
                jQuery("#"+this.id+" .box_body, #"+this.id+" .box_body p:first-of-type").addClass('colorWhite');
                jQuery("#"+this.id+" .box_guys img").attr('src','<?= Application::link("mentor/img/guys.png") ?>');
            }, function() { 
                jQuery(this).css("background-color", "#ffffff"); 
                jQuery("#"+this.id+' .box_title').addClass('colorBlack').removeClass('colorWhite');
                jQuery("#boxa .box_title strong").addClass("colorAqua").removeClass('colorWhite');
                jQuery("#boxb .box_title strong").addClass("colorOrange").removeClass('colorWhite');
                jQuery("#boxc .box_title strong").addClass("colorBlack").removeClass('colorWhite');
                jQuery("#"+this.id+" .box_body, #"+this.id+".box_body p:first-of-type").removeClass('colorWhite').removeClass('colorGrey');
                jQuery("#"+this.id+" .box_body p:first-of-type").removeClass('colorWhite').removeClass('colorGrey');
                
                if(this.id == 'boxa'){
                  jQuery("#"+this.id+" .box_guys img").attr('src','<?= Application::link("mentor/img/images/box_imgs_03.jpg") ?>');
                } else if(this.id == 'boxb'){
                  jQuery("#"+this.id+" .box_guys img").attr('src','<?= Application::link("mentor/img/images/box_imgs_06.jpg") ?>');
                } else if(this.id == 'boxc'){
                  jQuery("#"+this.id+" .box_guys img").attr('src','<?= Application::link("mentor/img/images/box_imgs_08.jpg") ?>');
                }
            }); 


    });
</script>
<style type="text/css">
  .colorWhite{color: #ffffff !important}
  .colorBlack{color: #000000 !important}
  .colorAqua{color: #26798a !important}
  .colorOrange{color: #de6339 !important}
  .colorGrey{color: #828282 !important;}
  .box_body p:first-of-type{color: #828282}


  .note_agreementchecklist{
    background-image: url(./images/note_viewagreement.png);
    height: 157px;
    width: 217px;
    background-size: contain;
    background-repeat: no-repeat;
    position: absolute;
    left: 467px;
    bottom: 21px;
    opacity: .3;
  }


</style>

<script>

jQuery(document).ready(function() {

$('.viewagreement').after('<div class="note_agreementchecklist"></div>');  
$('.viewagreement').hover(
  function() {
    $('.note_agreementchecklist').addClass('opacity100');
  }, function() {
    $('.note_agreementchecklist').removeClass('opacity100');
  }
);

});

</script>

<div class="row col-lg-12 tdata" style="text-align: center;">
    <?= (!empty($surveysAvailableToPrefill)) ? MMAHelper::makePrefillHTML($surveysAvailableToPrefill, $uidString) : "" ?>
    <?= (!empty($instances)) ? MMAHelper::makePriorInstancesDropdown($instances, $currInstance) : "" ?>
    <h4 style="margin: 0 auto; width: 100%; max-width: 800px;">Please independently fill out the checklist below. Suggested tables are open. Click on a header to expand the table. When complete, click on the button to alert your mentor.</h4>
</div>
<form id="tsurvey" name="tsurvey">
      <input type="hidden" class="form-hidden-data" name="mentoring_phase" id="mentoring_phase" value="<?= $phase ?>">
      <input type="hidden" class="form-hidden-data" name="mentoring_start" id="mentoring_start" value="<?= date("Y-m-d H:i:s") ?>">
      <section class="bg-light">
      <div class="container">
      <div class="row">
      <div class="col-lg-12 tdata">

<div style="display: none"><table><tbody>
<?php
$skipFieldTypes = ["file", "text"];
foreach ($metadata as $row) {
  list($sec_header, $sectionDescription) = MMAHelper::parseSectionHeader($row['section_header']);

  $fieldName = $row['field_name'];
  $rowName = $fieldName."-tr";
  if(in_array($sec_header, $secHeaders) && !in_array($row['field_type'], $skipFieldTypes)) {
      $encodedSection = REDCapManagement::makeHTMLId($sec_header);
      $displayCSS = "";
      if (!in_array($encodedSection, $sectionsToShow)) {
          $displayCSS = " display: none;";
      }
      ?>
            </tbody></table></div>
          <div class="tabledquestions">
            <div class="mainHeader" onclick="toggleSectionTable('.<?= $encodedSection ?>');"><?= strip_tags($sec_header) ?>
                <?php
                if ($sectionDescription) {
                    echo "<div class='subHeader'>".strip_tags($sectionDescription)."</div>";
                }
                ?>
                <div class="smallHeader"><?= MMAHelper::getSectionExpandMessage() ?></div>
            </div>
          <table id="quest1" class="table <?= $encodedSection ?>" style="margin-left: 0px;<?= $displayCSS ?>">
              <thead>
              <tr>
                  <th style="text-align: left;" scope="col"></th>
                  <th style="text-align: center; border-right: 0px;" scope="col"></th>
                  <th style="text-align: center;" scope="col"></th>

              </tr>
              <tr>
                  <th style="text-align: left;" scope="col">question</th>
                  <th style="text-align: center;" scope="col">mentee responses</th>
                  <th style="text-align: center;" scope="col">latest note<br>(click for full conversation)</th>
              </tr>
              </thead>
              <tbody>
    <?php
  }

  if ($row['field_type'] == "radio") { ?>
      <tr id="<?php echo $rowName; ?>"><th scope="row"><?php echo trim($row['field_label']);?></th>
        <td>
            <?php
              $value = REDCapManagement::findField($redcapData, $menteeRecordId, $fieldName, "mentoring_agreement", $currInstance);
              $prefix = "exampleRadiosh";
              foreach ($choices[$fieldName] as $key => $label) {
                  $name = $prefix.$fieldName;
                  $id = $name."___".$key;

                  ?><div class="form-check"><input class="form-check-input" type="radio" onclick="doMMABranching();" name="<?= $name ?>" id="<?= $id ?>" value="<?= $key; ?>" <?= ($value == $key) ? "checked" : "" ?>><label class="form-check-label" for="<?= $id ?>"><?php echo $label; ?></label></div><?php
              }
            ?>
        </td>
          <?= MMAHelper::makeNotesHTML($fieldName, $redcapData, $menteeRecordId, $currInstance, $notesFields) ?>
      </tr>
    <?php } else if ($row['field_type'] == "checkbox" ) { ?>
      <tr id="<?= $rowName ?>"><th scope="row"><?= trim($row['field_label']) ?></th>
          <td>
              <?php
              $prefix = "exampleChecksh";
              foreach ($choices[$fieldName] as $key => $label) {
                  $name = $prefix.$fieldName;
                  $id = $name."___".$key;
                  $value = REDCapManagement::findField($redcapData, $menteeRecordId, $fieldName."___".$key, "mentoring_agreement", $currInstance);
                  $isChecked = "";
                  if ($value) {
                      $isChecked = "checked";
                  }

                  ?>
                  <div class="form-check"><input class="form-check-input" onclick="doMMABranching();" type="checkbox" name="<?= $name ?>" id="<?= $id ?>" <?= $isChecked ?> ><label class="form-check-label" for="<?= $id ?>"><?= $label; ?></label></div>
                  <?php
              }
              ?>
          </td>
          <?= MMAHelper::makeNotesHTML($fieldName, $redcapData, $menteeRecordId, $currInstance, $notesFields) ?>
      </tr>
  <?php
  } else if (($row['field_type'] == "notes") && (!in_array($fieldName, $notesFields))) {
      $rowCSSStyle = ($row['field_name'] == "mentoring_other_evaluation") ? "style='display: none;'" : "";
      $prefix = "exampleTextareash";
      $name = $prefix.$fieldName;
      $id = $name;
      $value = REDCapManagement::findField($redcapData, $menteeRecordId, $fieldName, "mentoring_agreement", $currInstance);
      ?>
      <tr id="<?= $rowName ?>" <?= $rowCSSStyle ?>><th scope="row"><?= trim($row['field_label']) ?></th>
          <td colspan="2">
              <div class="form-check" style="height: 100px;">
                  <textarea class="form-check-input" name="<?= $name ?>" id="<?= $id ?>"><?= $value ?></textarea>
              </div>
          </td>
      </tr>
    <?php
  }


}


?>
              </tbody>
          </table>
          </div>
      </div>
      </div>
      </section>

</form>
<div class="fauxcomment" style="display: none;"></div>
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

            thead th:nth-of-type(4) {
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
              font-weight: 200
            }

            tbody tr:nth-child(odd) {
              background-color: #9898981c;
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

            tbody tr th:nth-of-type(1),
            tbody tr td:nth-of-type(1) {
              padding-left: 1em !important;
              padding-right: 1em !important;
              text-align: left;
              border-right: 1px solid #cccccc;
              width: 31%
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
              margin-left: 10px;
              margin-right: 10px;
            }

            tbody tr td a, tbody tr th a {
              color: #17a2b8;
              text-decoration: underline;
            }

            .row_red th,
            .row_red td {
              background-color: #ea0e0e30
            }

            .subHeader {
                text-transform: none;
                font-weight: 500;
                letter-spacing: normal;
                font-size: 16px;
                font-family: proxima-nova;
                cursor: pointer;
            }

            .smallHeader {
                text-transform: none;
                font-weight: 400;
                letter-spacing: normal;
                font-size: 12px;
                font-family: proxima-nova;
                cursor: pointer;
            }

            .mainHeader {
              text-transform: uppercase;
              font-weight: 900;
              letter-spacing: 7px;
              font-size: 24px;
                cursor: pointer;
            }

            .tabledquestions:nth-of-type(1) {
              padding: 1em;
              background-color: #41a9de14;
            }

            .tabledquestions:nth-of-type(2) {
              padding: 1em;
              background-color: #f6dd6645;
              margin-top: 1em;
            }
            .tabledquestions:nth-of-type(3) {
              padding: 1em;
              background-color: #ec9d5045;
              margin-top: 1em;
            }
            .tabledquestions:nth-of-type(4) {
              padding: 1em;
              background-color: #5fb7494a;
              margin-top: 1em;
            }
            .tabledquestions:nth-of-type(5) {
                padding: 1em;
                background-color: #a6609721;
                margin-top: 1em;
            }
            .tabledquestions:nth-of-type(6) {
                padding: 1em;
                background-color: #9ba4ac21;
                margin-top: 1em;
            }
            .tabledquestions:nth-of-type(7) {
                padding: 1em;
                background-color: #41a9de14;
                margin-top: 1em;
            }

            .tabledquestions:nth-of-type(8) {
                padding: 1em;
                background-color: #f6dd6645;
                margin-top: 1em;
            }
            .tabledquestions:nth-of-type(9) {
                padding: 1em;
                background-color: #ec9d5045;
                margin-top: 1em;
            }
            .tabledquestions:nth-of-type(10) {
                padding: 1em;
                background-color: #5fb7494a;
                margin-top: 1em;
            }
            .tabledquestions:nth-of-type(11) {
                padding: 1em;
                background-color: #a6609721;
                margin-top: 1em;
            }
            .tabledquestions:nth-of-type(12) {
                padding: 1em;
                background-color: #9ba4ac21;
                margin-top: 1em;
            }
            .tabledquestions:nth-of-type(13) {
                padding: 1em;
                background-color: #41a9de14;
                margin-top: 1em;
            }

            .tabledquestions:nth-of-type(14) {
                padding: 1em;
                background-color: #f6dd6645;
                margin-top: 1em;
            }
            .tabledquestions:nth-of-type(15) {
                padding: 1em;
                background-color: #ec9d5045;
                margin-top: 1em;
            }
            .tabledquestions:nth-of-type(16) {
                padding: 1em;
                background-color: #5fb7494a;
                margin-top: 1em;
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
    width: 286px;
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
    margin-left: 6px;
    width: 100%;
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
<style type="text/css">
  body {

    font-family: europa, sans-serif !important;
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
  width:90vw;
  height: 323px;
  border: 3px solid steelblue;
  margin: auto;
}

.getstarted{
    display: table-cell;
  text-align: center;
  vertical-align: middle;
  margin: auto;
  background: tomato;
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

<link rel="stylesheet" href="<?= Application::link("css/jquery.sweet-modal.min.css") ?>" />
<script src="<?= Application::link("js/jquery.sweet-modal.min.js") ?>"></script>
<?= MMAHelper::makePercentCompleteJS() ?>
<script type="text/javascript">
    dfn=function(obj){
        objta = "#"+obj+" td:nth-of-type(4) .tnote";
        obj = "#"+obj+" .dfn";
        if($(obj).attr('src') === '<?= Application::link("mentor/img/images/dfb_off_03.png") ?>'){
            $(obj).attr('src','<?= Application::link("mentor/img/images/dfb_on_03.png") ?>');
            $(objta).removeClass('tnote_d');
            $(objta).addClass('tnote_e');
        } else {
            $(obj).attr('src','<?= Application::link("mentor/img/images/dfb_off_03.png") ?>');
            $(objta).removeClass('tnote_e');
            $(objta).addClass('tnote_d');
        }
    }



    yn_discussed=function(obj,yn){
        var questnum = $("#"+obj).closest("table").attr('id');
        if(yn == 1){
            objta = "#"+obj+" th:nth-of-type(1) img";
            ynother = 2;
            objtaother = "#"+obj+" td:nth-of-type(1) img";
        } else {
            objta = "#"+obj+" td:nth-of-type(1) img";            
            ynother = 1;
            objtaother = "#"+obj+" th:nth-of-type(1) img";
        }
        if($(objta).attr('src') === '<?= Application::link("mentor/img/images/yn_off_03.jpg") ?>'){
            $(objta).addClass('isactive').attr('src','<?= Application::link("mentor/img/images/yn_on_07.png") ?>');// set 'yes' on
            $(objtaother).removeClass('isactive').attr('src','<?= Application::link("mentor/img/images/yn_off_05.jpg") ?>');// set 'no' off
        } else if($(objta).attr('src') === '<?= Application::link("mentor/img/images/yn_on_07.png") ?>'){
            $(objta).removeClass('isactive').attr('src','<?= Application::link("mentor/img/images/yn_off_03.jpg") ?>');// set 'no' on
            $(objtaother).removeClass('isactive').attr('src','<?= Application::link("mentor/img/images/yn_off_05.jpg") ?>');// set 'yes' off
        } else if($(objta).attr('src') === '<?= Application::link("mentor/img/images/yn_off_05.jpg") ?>'){
            $(objta).addClass('isactive').attr('src','<?= Application::link("mentor/img/images/yn_on_03.png") ?>');// set 'yes' on
            $(objtaother).removeClass('isactive').attr('src','<?= Application::link("mentor/img/images/yn_off_03.jpg") ?>');// set 'no' off
        } else if($(objta).attr('src') === '<?= Application::link("mentor/img/images/yn_on_03.png") ?>'){
            $(objta).attr('src','<?= Application::link("mentor/img/images/yn_off_05.jpg") ?>"').removeClass('isactive');
            $(objtaother).removeClass('isactive');// set 'no' off
        } 
        var disc=0; 
        //console.log($("#"+questnum+" .isactive").length); //number of unique yes/no questions answerd
        //console.log($("#"+questnum+" tbody tr").length); //number of yes/no questions
        var questnum_t = questnum.replace('quest','');
        $('#quest_num'+questnum_t).html($("#"+questnum+" .isactive").length);
        $.sweetModal({
                title: '',
                content: 'Discussion response updated!',
                timeout: 800
            });

    } 



    jQuery(document).ready(function(){
        $(".tnote").focusout(function(){
            $.sweetModal({
                title: '',
                content: 'Your note has been saved!',
                timeout: 1000
            });
        });
        
        $("tbody tr td:nth-of-type(2)").each( function( index, element ){
            updatequest = $( this ).html();
            updatequest = updatequest.replace()
        });

        $("input[type=checkbox].form-check-input").change(function() { updateData(this); });
        $("input[type=radio].form-check-input").change(function() { updateData(this); });
        $("textarea.form-check-input").blur(function() { updateData(this); });

        $("select#instances").change(() => {
            let value = $('select#instances option:selected').val()
            <?php
                $hashStr = $hash ? "&hash=$hash" : "";
                if (isset($_REQUEST['uid'])) {
                    echo "let uidStr = '&uid='+encodeURI('".REDCapManagement::sanitize($_REQUEST['uid']).$hashStr."')\n";
                } else {
                    echo "let uidStr = '".$hashStr."'\n";
                }
            ?>
            const baseUrl = '<?= Application::link("mentor/index_menteeview.php")."&menteeRecord=$menteeRecordId" ?>' + uidStr;
            if (value) {
                window.location.href = baseUrl + "&instance=" + encodeURI(value);
            } else {
                window.location.href = baseUrl;
            }
        });
        doMMABranching();
    });

    <?= MMAHelper::getBranchingJS() ?>

    function updateData(ob) {
        const mentoringStart = $('#mentoring_start').val();
        const phase = $('#mentoring_phase').val();
        if ($(ob).attr("id").match(/^exampleChecksh/)) {
            let fullFieldName = $(ob).attr("id").replace(/^exampleChecksh/, "")

            let a = $(ob).attr("id").replace(/^exampleChecksh/, "").split(/___/)
            let fieldName = a[0]
            let value = a[1]

            let checkValue = $(ob).is(":checked") ? "1" : "0"
            let thisbox = "#exampleChecksh"+fieldName+"___"+value;
            console.log(thisbox);

            $(thisbox).addClass("simptip-position-left").attr("data-tooltip","option saved!");

            $.post('<?= Application::link("mentor/change.php").$uidString ?>', {
                type: 'checkbox',
                record: '<?= $menteeRecordId ?>',
                instance: '<?= $currInstance ?>',
                field_name: fullFieldName,
                value: checkValue,
                userid: '<?= $userid2 ?>',
                start: mentoringStart,
                phase: phase
            }, function (html) {
                console.log(html);
                $(thisbox).delay(300).removeClass("simptip-position-left").removeAttr("data-tooltip");
            })
        } else if ($(ob).attr("id").match(/^exampleRadiosh/)) {
            let a = $(ob).attr("id").replace(/^exampleRadiosh/, "").split(/___/)
            let fieldName = a[0]
            let value = a[1]
            let thisbox = "#exampleRadiosh"+fieldName+"___"+value;
            $(thisbox).addClass("simptip-position-left").attr("data-tooltip","option saved!");

            $.post('<?= Application::link("mentor/change.php").$uidString ?>', {
                type: 'radio',
                record: '<?= $menteeRecordId ?>',
                instance: '<?= $currInstance ?>',
                field_name: fieldName,
                value: value,
                userid: '<?= $userid2 ?>',
                start: mentoringStart,
                phase: phase
            }, function(html) {
                console.log(html);
                $(thisbox).delay(300).removeClass("simptip-position-left").removeAttr("data-tooltip");
            });
        } else if ($(ob).attr("id").match(/^exampleTextareash/)) {
            let fieldName = $(ob).attr("id").replace(/^exampleTextareash/, "");
            let value = $(ob).val();
            let thisbox = "#exampleTextareash"+fieldName;
            $(thisbox).attr("disabled", true);
            $.post('<?= Application::link("mentor/change.php").$uidString ?>', {
                type: 'textarea',
                record: '<?= $menteeRecordId ?>',
                instance: '<?= $currInstance ?>',
                field_name: fieldName,
                value: value,
                userid: '<?= $userid2 ?>',
                start: mentoringStart,
                phase: phase
            }, function(html) {
                console.log(html);
                $(thisbox).attr("disabled", false);
            });
        }

        $('.chart').data('easyPieChart').update(getPercentComplete());
    }







</script>

<?= MMAHelper::makeCommentJS($userid2, $menteeRecordId, $currInstance, $currInstance, $priorNotes, $names[$menteeRecordId], $dateToRemind, TRUE, in_array("mentoring_agreement_evaluations", $allMetadataForms), $pid) ?>


<style type="text/css">
h4{
    color:#5b8ac3;
    font-family: proxima-soft, sans-serif;
    font-weight: 700; 
    text-transform: uppercase; 
    font-size: 14px; letter-spacing: 0px;
}
.tdata{
    padding-top: 30px;
}
.note_viewagreementstatus{
  background-image: url(./images/note_viewagreementstatus.png);
    height: 157px;
    width: 217px;
    background-size: contain;
    background-repeat: no-repeat;
    position: absolute;
    left: 57%;
    /* top: 11px; */
    opacity: .3;
    margin-top: -35px;
}
.simptip-position-left{transition: opacity 300 ease-in-out;}
[data-tooltip].simptip-position-left:after {
    background-color: #056c7d;
    color: #ffffff;
    opacity: 0.7;
}
[data-tooltip].simptip-position-left:before {
    border-left-color: #056c7d;
    opacity: 0.7;
}
.form-check-input {
    margin-right: 6px !important;
    position: absolute !important;
}
</style>

<script>

jQuery(document).ready(function() {

  $('.viewagreementstatus').append('<div class="note_viewagreementstatus"></div>');  
  $('.viewagreementstatus').hover(
    function() {
      $('.note_viewagreementstatus').addClass('opacity100');
    }, function() {
      $('.note_viewagreementstatus').removeClass('opacity100');
    }
  );
$('#tsurvey>section').append('<div style="width:100%;text-align:center;"><button type="button" class="btn btn-light viewagreement" style="margin-top: 22px;margin-bottom: 8em;color:#ffffff; background-color: #056c7d;border-color: #056c7d;" onclick="saveagreement()">save mentoring agreement &amp; notify mentor(s)</button></div>');
});

</script>
