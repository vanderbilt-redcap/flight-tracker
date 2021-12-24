<?php

namespace Vanderbilt\CareerDevLibrary;

require_once dirname(__FILE__)."/preliminary.php";
require_once dirname(__FILE__)."/base.php";
require_once dirname(__FILE__)."/../small_base.php";
require_once dirname(__FILE__)."/../classes/Autoload.php";

require_once dirname(__FILE__).'/_header.php';

if(isset($_REQUEST['uid']) && MMA_DEBUG){
    $username = REDCapManagement::sanitize($_REQUEST['uid']);
    $uidString = "&uid=$username";
} else {
    $username = (Application::getProgramName() == "Flight Tracker Mentee-Mentor Agreements") ? NEW_HASH_DESIGNATION : Application::getUsername();
    $uidString = "";
}

list($firstName, $lastName) = MMAHelper::getNameFromREDCap($username, $token, $server);
$menteeRecordIds = MMAHelper::getRecordsAssociatedWithUserid($username, $token, $server);

?>

<style type="text/css">

    .table{width: 96%; margin-left: 4%;}
    .listmentors thead th {border-top: 0px solid #dee2e6 !important;font-size: 11px; text-transform: uppercase;font-family: proxima-soft, sans-serif; border-bottom: unset !important;    letter-spacing: 1px;}
    .listmentors thead th:nth-of-type(1),.listmentors thead th:nth-of-type(2){width: 90px; padding-left: 0px !important; padding-right: 0px !important;}
    .listmentors thead th:nth-of-type(3){width: 78px; text-align: center;}
    .listmentors thead th:nth-of-type(6){    width: 19%;text-align: left;}
    .listmentors tr td img,.listmentors tr th img{width: 30px; padding-left: 0px !important; padding-right: 0px !important;}
    .listmentors tbody tr td,tbody tr th{font-family: proxima-soft, sans-serif; font-size:15px;line-height: 20px; font-weight: 200}
    .listmentors tbody tr:nth-child(odd) {background-color: #f2f2f2;}
    table.menu tbody tr td:nth-of-type(1) img{margin-top: 2px;}
    table.menu tbody tr{    line-height: 30px;}
    .form-control{font-size: 14px;}
    input:placeholder-shown {border:0px; background: none;}
    .listmentors tbody tr th:nth-of-type(1),.listmentors tbody tr td:nth-of-type(1){padding-left: 0px !important; padding-right: 0px !important; text-align: center;}
    .listmentors tbody tr th:nth-of-type(1),.listmentors tbody tr td:nth-of-type(3),.listmentors tbody tr td:nth-of-type(4),.listmentors tbody tr td:nth-of-type(5),tbody tr td:nth-of-type(6){padding-top: 1.4em !important;}
    .listmentors tbody tr td:nth-of-type(3):hover{cursor:hand;}
    .listmentors tbody tr td:nth-of-type(4){       padding-top: 1em;padding-bottom: 1em;}
    .listmentors tbody tr th:nth-of-type(2),.listmentors tbody tr td:nth-of-type(2) {
        padding-top: 1.4em !important; text-align: center;
    }
    .listmentors tbody tr td:nth-of-type(1) small{
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
    .blue-box a { color: black; }

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
    .listmentors tbody tr td a{color: #17a2b8; text-decoration: underline;}

    .red{color: #af3017}
    .orange{color: #de8a12;}
    .notoverdue{padding-top: 1.4em !important;}
    .incomplete{padding-top: 21px !important;}

</style>

<?php
if ($hash && $hashRecordId || $isNewHash) {
    $html = MMAHelper::makePublicApplicationForm($token, $server, $isNewHash ? NEW_HASH_DESIGNATION : $hash, $hashRecordId);
} else {
    $metadata = Download::metadata($token, $server);
    $metadata = MMAHelper::filterMetadata($metadata, FALSE);
    $html = MMAHelper::makeMainTable($token, $server, $username, $metadata, $menteeRecordIds, $uidString);
}
echo $html;
?>

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

    <?= MMAHelper::makeReminderJS($firstName." ".$lastName) ?>

      <style type="text/css">
  body {

    font-family: europa, sans-serif !important;
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

<link rel="stylesheet" href="<?= Application::link("mentor/jquery.sweet-modal.min.css") ?>" />
<script src="<?= Application::link("mentor/jquery.sweet-modal.min.js") ?>"></script>

<script type="text/javascript">
    dfn=function(obj){
        objta = "#"+obj+" td:nth-of-type(4) .tnote";
        obj = "#"+obj+" .dfn";
        let offImgSrc = '<?= Application::link("mentor/img/images/dfb_off_03.png") ?>';
        let onImgSrc = '<?= Application::link("mentor/img/images/dfb_on_03.png") ?>';
        if($(obj).attr('src') && ($(obj).attr('src') === offImgSrc)) {
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
        if($(objta).attr('src') === '<?= Application::link("mentor/img/images/yn_off_03.jpg") ?>'){
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
  let currcomment = "0";
  showcomment = function(servicerequest_id) {
    $('.fauxcomment').css('display', 'none');
    const offset = $("#" + servicerequest_id + " .tcomments").offset();
    const offsetleft = offset.left + 50;
    const offsettop = offset.top - 16;
    let commentcontent = '<div style="position: relative;height: 250px;"><div class="closecomments"><span style="float:left;color: #000000;font-weight: 700;font-size: 12px;margin-left: 6px;">Comments for question/option ' + servicerequest_id + '</span><a style="float:right;" href="javascript:$(\'.fauxcomment\').css(\'display\',\'none\');"><img src="/images/x-circle.svg"></a></div><div id="lcomments" class="listofcomments" style="   position: absolute;bottom: 0;height: 220px;display: inline-block;overflow: scroll; ">';

    //data here (commentcontent) is only used for demo purpposes. Use actual data either via ajax or add hidden div in data row to read from
    commentcontent += '<div class="acomment">This is a test for the discussion/notes comment section.<span class="timestamp">(Burks, B) 6/22/18 15:30</span></div>';
    commentcontent += '<div class="acomment odd">Checked the data and it is correct. Need to alter temp tag to allow for subjegation.<span class="timestamp">(Lightner, C) Yesterday 07:23</span></div><div class="datemarker">Today</div>';
    commentcontent += '<div class="acomment">Official U.S. edition with full color illustrations throughout. New York Times Bestseller A Summer Reading Pick for President Barack Obama, Bill Gates, and Mark Zuckerberg From<span class="timestamp">(Patton, D) Today 03:17</span></div>';
    commentcontent += '<div class="acomment odd">Nashville pollen level for 3/18/2019: 9.4 (Medium-High)<span class="timestamp">(Moore, R) Today 05:42</span></div>';
    commentcontent += '</div></div><div class="insertcomment"><input id="addcomment" type="text" placeholder="add comment..."><span><a href="javascript:addcomment(\'' + servicerequest_id + '\')"><img src="/images/at-sign.svg" style="height: 18px;margin-left: 8px;"></a></span></div>';
    $(".fauxcomment")
        .css('top', offsettop + 'px')
        .css('left', offsetleft + 'px')
        .html('<!--img class="thefauxcomment ' + servicerequest_id + '" src="/images/comments.png"-->' + commentcontent)
        .css('display', 'inline-block');

    currcomment = servicerequest_id;
    $(".acomment:odd").css("background-color", "#eceff5");
    let element = document.getElementById("lcomments"); //scrolls to bottom
    element.scrollTop = element.scrollHeight;
  }

  addcomment = function(servicerequest_id) {
    $('#' + servicerequest_id + ' .tcomments .timestamp').remove();
    const d = new Date();
    const commentText = $('#addcomment').val();
    const latestcomment = commentText + '<span class="timestamp">(me) Today ' + d.getHours() + ':' + minutes_with_leading_zeros(d) + '</span>';
    $('<div class="acomment">' + latestcomment + '</div>').appendTo(".listofcomments");
    $('#' + servicerequest_id + ' .tcomments a')
        .html(commentText)
        .after('<span class="timestamp" style="display: inline;margin-left: 6px;">(me) Today ' + d.getHours() + ':' + minutes_with_leading_zeros(d) + '</span>');
    $('#addcomment').val('');
    $(".acomment:odd").css("background-color", "#eceff5");
    let element = document.getElementById("lcomments"); //scrolls to bottom
    element.scrollTop = element.scrollHeight;
  }

  changePhase=function(ob) {
      const phase = $(ob).val();
      const parentRow = $(ob).parent().parent();
      const link = parentRow.find('a.surveylink');
      const origUrl = link.attr('href').replace(/&phase=\d+/, '');
      const newUrl = origUrl + '&phase='+encodeURI(phase);
      link.attr('href', newUrl);
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
    thead th {border-top: 0px solid #dee2e6 !important;font-size: 11px; text-transform: uppercase;font-family: proxima-soft, sans-serif; border-bottom: unset !important; letter-spacing: 1px; text-align: center;}
    .listmentors     thead th:nth-of-type(1),thead th:nth-of-type(2){width: 90px; padding-left: 0px !important; padding-right: 0px !important;}
    .listmentors     thead th:nth-of-type(3){width: 11%; }
    .listmentors     thead th:nth-of-type(3){width: 78px; }
    .listmentors     thead th:nth-of-type(6){    width: 19%; }
    .listmentors tr td img, tr th img{width: 30px; padding-left: 0px !important; padding-right: 0px !important;}
    .listmentors tbody tr td,tbody tr th{font-family: proxima-soft, sans-serif; font-size:15px;line-height: 20px; font-weight: 200}
    .listmentors tbody tr:nth-child(odd) {background-color: #f2f2f2;}
    .listmentors tbody tr td:nth-of-type(1) img{margin-top: 2px;}
    .listmentors tbody tr{    line-height: 30px;}
    .listmentors .form-control{font-size: 14px;}
    .listmentors input:placeholder-shown {border:0px; background: none;}
    .listmentors tbody tr th:nth-of-type(1), .listmentors tbody tr td:nth-of-type(1){vertical-align: middle; padding-left: 0px !important; padding-right: 0px !important; text-align: center;}
    .listmentors tbody tr th:nth-of-type(1), .listmentors tbody tr td:nth-of-type(3), .listmentors tbody tr td:nth-of-type(4), .listmentors tbody tr td:nth-of-type(5), .listmentors tbody tr td:nth-of-type(6){padding-top: 1.4em !important;}
    .listmentors tbody tr td:nth-of-type(4){text-align: center;}
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
    .listmentors tbody tr td a{color: #17a2b8; text-decoration: underline;}

    .listmentors   .red{color: #af3017}
    .listmentors   .orange{color: #de8a12;}
    .listmentors   .notoverdue{padding-top: 1.4em !important;}
    .listmentors     .incomplete{padding-top: 21px !important;}

</style>

<?= MMAHelper::makeEmailJS($username) ?>
