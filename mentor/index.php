<?php
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Application;

use \Vanderbilt\CareerDevLibrary\LDAP;

require_once dirname(__FILE__)."/debug.php";
require_once dirname(__FILE__)."/base.php";
require_once dirname(__FILE__)."/../small_base.php";
require_once dirname(__FILE__)."/../Application.php";
require_once dirname(__FILE__)."/../CareerDev.php";
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");
require_once dirname(__FILE__)."/../classes/LDAP.php";

require_once dirname(__FILE__).'/_header.php';

$username = $_GET['uid'];
if (!$username || !DEBUG) {
    $username = $userid;
}

$menteeRecordIds = $module->getRecordsAssociatedWithUserid($username, $token, $server);

if(isset($_REQUEST['uid']) && DEBUG){
    $username = $_REQUEST['uid'];
    $uidString = "&uid=$username";
} else {
    $username = $userid;
    $uidString = "";
}

$metadata = Download::metadata($token, $server);
$allMetadataForms = REDCapManagement::getFormsFromMetadata($metadata);
$metadata = filterMetadata($metadata, FALSE);
$metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);

list($firstName, $lastName) = getNameFromREDCap($username, $token, $server);
$names = Download::names($token, $server);
$userids = Download::userids($token, $server);
$allMentorUids = Download::primaryMentorUserids($token, $server);
$allMentors = Download::primaryMentors($token, $server);
$redcapData = Download::fieldsForRecords($token, $server, array_unique(array_merge($metadataFields, ["record_id"])), $menteeRecordIds);

?>

<section class="bg-light">
  <div class="container">
    <div class="row">
      <div class="col-lg-12">
          <div class="blue-box">
              <h2 style="color: #222222;">Typical Workflow</h2>
              <h3><?= implode("<br>&rarr; ", ["Mentee Preferences", "Discussion with Mentor", "Final Agreement", "Revisit Agreement"]) ?></h3>
          </div>
          <h2><?= $firstName ?>, here are your mentee-mentor relationships</h2>
          <p><style type="text/css">

.table{width: 96%; margin-left: 4%;}
thead th {border-top: 0px solid #dee2e6 !important;font-size: 11px; text-transform: uppercase;font-family: proxima-soft, sans-serif; border-bottom: unset !important;    letter-spacing: 1px;}
thead th:nth-of-type(1),thead th:nth-of-type(2){width: 90px; padding-left: 0px !important; padding-right: 0px !important;}
thkead th:nth-of-type(3){width: 11%; text-align: center;}
thead th:nth-of-type(3){width: 78px; text-align: center;}
thead th:nth-of-type(6){    width: 19%;text-align: left;}
tr td img, tr th img{width: 30px; padding-left: 0px !important; padding-right: 0px !important;}
tbody tr td,tbody tr th{font-family: proxima-soft, sans-serif; font-size:15px;line-height: 20px; font-weight: 200}
tbody tr:nth-child(odd) {background-color: #f2f2f2;}
tbody tr td:nth-of-type(1) img{margin-top: 2px;}
tbody tr{    line-height: 30px;}
.form-control{font-size: 14px;}
input:placeholder-shown {border:0px; background: none;}
tbody tr th:nth-of-type(1),tbody tr td:nth-of-type(1){padding-left: 0px !important; padding-right: 0px !important; text-align: center;}
tbody tr th:nth-of-type(1),tbody tr td:nth-of-type(3),tbody tr td:nth-of-type(4),tbody tr td:nth-of-type(5),tbody tr td:nth-of-type(6){padding-top: 1.4em !important;}
tjbody tr td:nth-of-type(4){text-align: center;}
tbody tr td:nth-of-type(3):hover{cursor:hand;}
tbody tr td:nth-of-type(4){       padding-top: 1em;padding-bottom: 1em;}
tbody tr th:nth-of-type(2), tbody tr td:nth-of-type(2) {
padding-top: 1.4em !important; text-align: center;
}
tbody tr td:nth-of-type(1) small{
    margin-top: -5px;
    display: block;
}

.blue-box {
    padding: 40px;
    background-image: linear-gradient(to bottom right, #66d1ff, #4f64db);
    border-radius: 25px;
    margin: 25px auto;
    max-width: 500px;
    text-align: center !important;
    box-shadow: 6px 6px 4px #444444;
}

textarea{  display: block;box-sizing: padding-box;overflow: hidden;}
.tnote{
    color: #a0a0a0;    
    font-size: 15px;
    line-height: 20px;
    height: 20px;
    overflow: hidden;
}
.tnote_d{background-color: unset !important;border: 0px solid !important; color: #a0a0a0; height: 20px; overflow: hidden;}
.tnote_e{background-color: unset !important;border: 0px solid !important; color: #000000; height: auto;overflow: unset}
tbody tr th:nth-of-type(1) img{
    mkargin-left: 10px;
    mkargin-right: 10px;
}
tkhead th:nth-of-type(1)::before{content: "Discussed";
    position: absolute;
    top: 128px;
    left: 77px;
}
tbody tr td a{color: #17a2b8; text-decoration: underline;}

.red{color: #af3017}
.orange{color: #de8a12;}
.notoverdue{padding-top: 1.4em !important;}
.tnoter{    font-size: 16px;
line-height: 20px; font-family: proxima-nova}
  .incomplete{padding-top: 21px !important;}

</style>

          <table id="quest1" class="table listmentors" style="margin-left: 0px;">
              <thead>
              <tr>
                  <th style="text-align: center;" scope="col">latest update</th>
                  <th style="text-align: center;" scope="col">progress</th>
                  <th style="text-align: center;" scope="col">status</th>
                  <th scope="col">mentee</th>
                  <th scope="col">mentor(s)</th>
                  <th scope="col">send notification</th>
              </tr>
              </thead>
              <tbody>
              <?php
              $i = 1;
              foreach ($menteeRecordIds as $menteeRecordId) {
                  $menteeName = $names[$menteeRecordId];
                  $menteeUserid = $userids[$menteeRecordId];
                  $namesOfMentors = $allMentors[$menteeRecordId];
                  $useridsOfMentors = $allMentorUids[$menteeRecordId];
                  $myRow = getLatestRow($menteeRecordId, [$username], $redcapData);
                  $mentorRow = getLatestRow($menteeRecordId, $allMentorUids[$menteeRecordId], $redcapData);
                  if (empty($myRow)) {
                      $instance = REDCapManagement::getMaxInstance($redcapData, "mentoring_agreement", $menteeRecordId) + 1;
                      $percentComplete = 0;
                      $mdy = date("m-d-Y");
                      $lastMentorInstance = FALSE;
                      $surveyText = "start";
                  } else {
                      $percentComplete = getPercentComplete($myRow, $metadata);
                      $mdy = REDCapManagement::YMD2MDY($myRow['mentoring_last_update']);
                      $instance = $myRow['redcap_repeat_instance'];
                      $lastMentorInstance = $mentorRow['redcap_repeat_instance'];
                      $surveyText = "edit";
                  }
                  $newMentorInstance = $instance + 1;
                  $trailerURL = $uidString."&menteeRecord=$menteeRecordId&instance=$instance";
                  if ($menteeUserid == $username) {
                      $surveyPage = Application::link("mentor/index_menteeview.php").$trailerURL;
                  } else {
                      $surveyPage = Application::link("mentor/index_mentorview.php").$trailerURL;
                  }
                  if ($lastMentorInstance) {
                      $completedTrailerURL = $uidString."&menteeRecord=$menteeRecordId&instance=$lastMentorInstance";
                      $completedPage = Application::link("mentor/index_complete.php").$completedTrailerURL;
                  } else {
                      $completedPage = "";
                  }

                  echo "<tr id='m$i'>\n";
                  echo "<th scope='row'><a class='surveylink' href='$surveyPage'>$surveyText</a></th>\n";
                  if ($percentComplete > 0) {
                      echo "<td class='orange'>$percentComplete%<br><small>$mdy</small></td>\n";
                  } else {
                      echo "<td class='red incomplete'>NOT STARTED</td>\n";
                  }
                  if ($completedPage) {
                      echo "<td><a href='$completedPage'>view last agreement</a></td>\n";
                  } else {
                      echo "<td>no prior agreements</td>\n";
                  }
                  echo "<td>$menteeName</td>\n";
                  if (!empty($namesOfMentors)) {
                      $mentorNameText = REDCapManagement::makeConjunction($namesOfMentors);
                  } else {
                      $mentorNameText = "None listed";
                  }
                  $changeMentorLink = "";
                  if (isMentee($menteeRecordId, $username)) {
                      $changeMentorLink = "<br><a href='".Application::link("mentor/addMentor.php")."&menteeRecord=$menteeRecordId$uidString'>Add a Mentor</a>";
                  }
                  echo "<td>$mentorNameText$changeMentorLink</td>\n";
                  echo "<script>let namesOfMentors_$menteeRecordId = ".json_encode($namesOfMentors)."; let useridsOfMentors_$menteeRecordId = ".json_encode($useridsOfMentors).";</script>\n";
                  echo "<td><a href='javascript:void(0)' onclick='sendreminder(\"$menteeRecordId\", \"$newMentorInstance\", namesOfMentors_$menteeRecordId, useridsOfMentors_$menteeRecordId, \"$menteeName\");'>send reminder for mentor(s) to complete</a></td>\n";
                  echo "</tr>\n";
                  $i++;
              }
              ?>
              </tbody>
          </table>
          <?php
          if (empty($menteeRecordIds)) {
              echo "<div style='text-align: center;'>No Mentees Active For You</div>";
          }
          ?>
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
                    boxcolor = "#666666";
                    hoverout = "colorWhite";
                } else {
                    hoverout = "colorBlack";
                }
                console.log("#"+this.id+' .box_title strong');
                jQuery("#"+this.id+" .box_guys img").attr('src','<?= Application::link("mentor/img/guys.png") ?>');
                jQuery(this).css("background-color", boxcolor);
                jQuery("#"+this.id+" .box_title").addClass('colorWhite').removeClass('colorBlack');
                jQuery("#"+this.id+' .box_title strong').removeClass('colorBlack').removeClass('colorAqua').removeClass('colorOrange').addClass('colorWhite');
                jQuery("#"+this.id+" .box_body, #"+this.id+" .box_body p:first-of-type").addClass('colorWhite');
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

    <?= makeReminderJS($firstName." ".$lastName) ?>

      <style type="text/css">
  body {

    font-family: europa, sans-serif;
    letter-spacing: -0.5px;
    font-size: 1.3em;
}
.h2, h2 {
    font-weight: 700;
    text-align: center;
    color: #727272;
}

h3 {
    color: #555555;
    text-align: center;
}

a.surveylink {
    text-decoration: underline !important;
}


.bg-light {
    background-color: #ffffff!important;
}
.box_bg{height: 361px;width: 340px;background-size: contain;    padding: 34px;
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

<script type="text/javascript">
    dfn=function(obj){
        objta = "#"+obj+" td:nth-of-type(4) .tnote";
        obj = "#"+obj+" .dfn";
        let offImgSrc = '<?= Application::link("mentor/img/images/dfb_off_03.png") ?>';
        let onImgSrc = '<?= Application::link("mentor/img/images/dfb_on_03.png") ?>';
        if($(obj).attr('src') && ($(obj).attr('src') == offImgSrc)) {
            $(obj).attr('src', onImgSrc);
            $(objta).removeClass('tnote_d');
            $(objta).addClass('tnote_e');
        } else {
            $(obj).attr('src',offImgSrc);
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
        if($(objta).attr('src') == '<?= Application::link("mentor/img/images/yn_off_03.jpg") ?>'){
            $(objta).addClass('isactive').attr('src','<?= Application::link("mentor/img/images/yn_on_07.png") ?>');// set 'yes' on
            $(objtaother).removeClass('isactive').attr('src','<?= Application::link("mentor/img/images/yn_off_05.jpg") ?>');// set 'no' off
        } else if($(objta).attr('src') == '<?= Application::link("mentor/img/images/yn_on_07.png") ?>'){
            $(objta).removeClass('isactive').attr('src','<?= Application::link("mentor/img/images/yn_off_03.jpg") ?>');// set 'no' on
            $(objtaother).removeClass('isactive').attr('src','<?= Application::link("mentor/img/images/yn_off_05.jpg") ?>');// set 'yes' off
        } else if($(objta).attr('src') == '<?= Application::link("mentor/img/images/yn_off_05.jpg") ?>'){
            $(objta).addClass('isactive').attr('src','<?= Application::link("mentor/img/images/yn_on_03.png") ?>');// set 'yes' on
            $(objtaother).removeClass('isactive').attr('src','<?= Application::link("mentor/img/images/yn_off_03.jpg") ?>');// set 'no' off
        } else if($(objta).attr('src') == '<?= Application::link("mentor/img/images/yn_on_03.png") ?>'){
            $(objta).attr('src','<?= Application::link("mentor/img/images/yn_off_05.jpg") ?>').removeClass('isactive');
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


    });







</script>
<script>
  function minutes_with_leading_zeros(dt) {
    return (dt.getMinutes() < 10 ? '0' : '') + dt.getMinutes();
  }
  var currcomment = "0";
  showcomment = function(servicerequest_id) {
    $('.fauxcomment').css('display', 'none');
    var offset = $("#" + servicerequest_id + " .tcomments").offset();
    var offsetleft = offset.left + 50;
    var offsettop = offset.top - 16;
    var commentcontent = '<div style="position: relative;height: 250px;"><div class="closecomments"><span style="float:left;color: #000000;font-weight: 700;font-size: 12px;margin-left: 6px;">Comments for question/option ' + servicerequest_id + '</span><a style="float:right;" href="javascript:$(\'.fauxcomment\').css(\'display\',\'none\');"><img src="/images/x-circle.svg"></a></div><div id="lcomments" class="listofcomments" style="   position: absolute;bottom: 0;height: 220px;display: inline-block;overflow: scroll; ">';

    //data here (commentcontent) is only used for demo purpposes. Use actual data either via ajax or add hidden div in data row to read from
    commentcontent += '<div class="acomment">This is a test for the discussion/notes comment section.<span class="timestamp">(Burks, B) 6/22/18 15:30</span></div>';
    commentcontent += '<div class="acomment odd">Checked the data and it is correct. Need to alter temp tag to allow for subjegation.<span class="timestamp">(Lightner, C) Yesterday 07:23</span></div><div class="datemarker">Today</div>';
    commentcontent += '<div class="acomment">Official U.S. edition with full color illustrations throughout. New York Times Bestseller A Summer Reading Pick for President Barack Obama, Bill Gates, and Mark Zuckerberg From<span class="timestamp">(Patton, D) Today 03:17</span></div>';
    commentcontent += '<div class="acomment odd">Nashville pollen level for 3/18/2019: 9.4 (Medium-High)<span class="timestamp">(Moore, R) Today 05:42</span></div>';
    commentcontent += '</div></div><div class="insertcomment"><input id="addcomment" type="text" placeholder="add comment..."><span><a href="javascript:addcomment(\'' + servicerequest_id + '\')"><img src="/images/at-sign.svg" style="height: 18px;margin-left: 8px;"></a></span></div>';
    $(".fauxcomment").css('top', offsettop + 'px').css('left', offsetleft + 'px').html('<!--img class="thefauxcomment ' + servicerequest_id + '" src="/images/comments.png"-->' + commentcontent);
    $('.fauxcomment').css('display', 'inline-block');

    currcomment = servicerequest_id;
    $(".acomment:odd").css("background-color", "#eceff5");
    var element = document.getElementById("lcomments"); //scrolls to bottom
    element.scrollTop = element.scrollHeight;
  }

  addcomment = function(servicerequest_id) {
    $('#' + servicerequest_id + ' .tcomments .timestamp').remove();
    var d = new Date();
    var latestcomment = $('#addcomment').val() + '<span class="timestamp">(me) Today ' + d.getHours() + ':' + minutes_with_leading_zeros(d) + '</span>';
    $('<div class="acomment">' + latestcomment + '</div>').appendTo(".listofcomments");
    $('#' + servicerequest_id + ' .tcomments a').html($('#addcomment').val());
    $('#' + servicerequest_id + ' .tcomments a').after('<span class="timestamp" style="display: inline;margin-left: 6px;">(me) Today ' + d.getHours() + ':' + minutes_with_leading_zeros(d) + '</span>');
    $('#addcomment').val('');
    $(".acomment:odd").css("background-color", "#eceff5");
    var element = document.getElementById("lcomments"); //scrolls to bottom
    element.scrollTop = element.scrollHeight;
  }
</script>


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

});

</script>
<style type="text/css">

.listmentors .table{width: 96%; margin-left: 4%;}
    thead th {border-top: 0px solid #dee2e6 !important;font-size: 11px; text-transform: uppercase;font-family: proxima-soft, sans-serif; border-bottom: unset !important;    letter-spacing: 1px;}
    .listmentors     thead th:nth-of-type(1),thead th:nth-of-type(2){width: 90px; padding-left: 0px !important; padding-right: 0px !important;}
    .listmentors     thead th:nth-of-type(3){width: 11%; text-align: center;}
    .listmentors     thead th:nth-of-type(3){width: 78px; text-align: center;}
    .listmentors     thead th:nth-of-type(6){    width: 19%;text-align: left;}
    .listmentors tr td img, tr th img{width: 30px; padding-left: 0px !important; padding-right: 0px !important;}
    .listmentors tbody tr td,tbody tr th{font-family: proxima-soft, sans-serif; font-size:15px;line-height: 20px; font-weight: 200}
    .listmentors tbody tr:nth-child(odd) {background-color: #f2f2f2;}
    .listmentors tbody tr td:nth-of-type(1) img{margin-top: 2px;}
    .listmentors tbody tr{    line-height: 30px;}
    .listmentors .form-control{font-size: 14px;}
    .listmentors input:placeholder-shown {border:0px; background: none;}
    .listmentors tbody tr th:nth-of-type(1), .listmentors tbody tr td:nth-of-type(1){vertical-align: middle; padding-left: 0px !important; padding-right: 0px !important; text-align: center;}
    .listmentors tbody tr th:nth-of-type(1), .listmentors tbody tr td:nth-of-type(3), .listmentors tbody tr td:nth-of-type(4), .listmentors tbody tr td:nth-of-type(5), .listmentors tbody tr td:nth-of-type(6){padding-top: 1.4em !important;}
    .listmentors tjbody tr td:nth-of-type(4){text-align: center;}
    .listmentors tbody tr td:nth-of-type(3):hover{cursor:hand;}
    .listmentors tbody tr td:nth-of-type(4){       padding-top: 1em;padding-bottom: 1em;}
    .listmentors tbody tr th:nth-of-type(2),  .listmentors tbody tr td:nth-of-type(2) {
    padding-top: 1.4em !important; text-align: center;
    }
    .listmentors tbody tr td:nth-of-type(1) small{
        margin-top: -5px;
        display: block;
    }
    
    .listmentors textarea{  display: block;box-sizing: padding-box;overflow: hidden;}
    .tnote{
        color: #a0a0a0;    
        font-size: 15px;
        line-height: 20px;
        height: 20px;
        overflow: hidden;
    }
    .listmentors .tnote_d{background-color: unset !important;border: 0px solid !important; color: #a0a0a0; height: 20px; overflow: hidden;}
    .listmentors .tnote_e{background-color: unset !important;border: 0px solid !important; color: #000000; height: auto;overflow: unset}
    .listmentors tbody tr th:nth-of-type(1) img{
        margin-left: 10px;
        margin-right: 10px;
    }
    .listmentors tkhead th:nth-of-type(1)::before{content: "Discussed";
        position: absolute;
        top: 128px;
        left: 77px;
    }
    .listmentors tbody tr td a{color: #17a2b8; text-decoration: underline;}

    .listmentors   .red{color: #af3017}
    .listmentors   .orange{color: #de8a12;}
    .listmentors   .notoverdue{padding-top: 1.4em !important;}
    .listmentors   .tnoter{    font-size: 16px;
    line-height: 20px; font-family: proxima-nova}
    .listmentors     .incomplete{padding-top: 21px !important;}

</style>
