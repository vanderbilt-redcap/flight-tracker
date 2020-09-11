<?php
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

use \Vanderbilt\CareerDevLibrary\LDAP;
use \Vanderbilt\CareerDevLibrary\LdapLookup;

require_once dirname(__FILE__)."/../small_base.php";
require_once dirname(__FILE__)."/base.php";
require_once dirname(__FILE__)."/../Application.php";
require_once dirname(__FILE__)."/../CareerDev.php";
require_once(dirname(__FILE__)."/../classes/Links.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once dirname(__FILE__)."/../classes/LDAP.php";
require_once dirname(__FILE__)."/../classes/REDCapManagement.php";

authenticate($userid, $_REQUEST['menteeRecord']);
if ($_GET['uid']) {
    $username = $_GET['uid'];
} else {
    $username = $userid;
}

require_once dirname(__FILE__).'/_header.php';

if ($_REQUEST['menteeRecord']) {
    $menteeRecordId = $_REQUEST['menteeRecord'];
    list($myMentees, $myMentors) = getMenteesAndMentors($menteeRecordId, $username, $token, $server);
} else {
    throw new \Exception("You must specify a mentee record!");
}
if ($_REQUEST['instance']) {
    $instance = $_REQUEST['instance'];
} else {
    throw new \Exception("You must specify an instance");
}
list($firstName, $lastName) = getNameFromREDCap($username, $token, $server);
$metadata = Download::metadata($token, $server);
$metadata = filterMetadata($metadata);
$metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
$notesFields = getNotesFields($metadataFields);
$choices = REDCapManagement::getChoices($metadata);
$redcapData = Download::fieldsForRecords($token, $server, array_merge(["record_id", "mentoring_last_update"], $metadataFields), [$menteeRecordId]);
$row = pullInstanceFromREDCap($redcapData, $instance);
$listOfMentors = REDCapManagement::makeConjunction(array_values($myMentors["name"]));
$listOfMentees = isMentee($menteeRecordId, $username) ? $firstName." ".$lastName : $myMentees["name"][$menteeRecordId];
$dateToRevisit = getDateToRevisit($redcapData, $menteeRecordId, $instance);

?>
<section class="bg-light">
  <div class="container">
    <div class="row">
      <div class="col-lg-12" style="">
        <h2 style="text-align: center;font-weight: 500;font-family: din-2014, sans-serif;font-size: 32px;letter-spacing: -1px;">Mentorship Agreement<br>between
        <?= $listOfMentees ?> and <?= $listOfMentors ?><br>
        <?= REDCapManagement::YMD2MDY(REDCapManagement::findField($redcapData, $menteeRecordId, "mentoring_last_update")) ?></h2>

            <p style="text-align: center;width: 80%;margin: auto;margin-top: 2em;">
            <span style="text-decoration: underline;"><?= $listOfMentors ?> (mentor)</span> and <span style="text-decoration: underline;"><?= $listOfMentees ?> (mentee)</span> do hereby enter into a formal mentoring agreement. The elements of the document below provide evidence that a formal discussion has been conducted by the Mentor and Mentee together, touching on multiple topics that relate to the foundations of a successful training relationship for both parties. Below are key elements which we discussed at the start of our Mentor-Mentee Relationship. These elements, and others, also provide opportunities for further and/or new discussions together at future time points (e.g., 6, 12, and 18 months from now, as well as on an as needed basis).
            </p>

          <p style="text-align: center;">We will revisit this agreement on-or-around <?= $dateToRevisit ?>.</p>

          <?php

          $htmlRows = [];
          $closing = "</ul></div></p>";
          $noInfo = "No Information Specified.";
          $hasRows = FALSE;
          foreach ($metadata as $metadataRow) {
              if ($metadataRow['section_header']) {
                  if (!empty($htmlRows)) {
                      if (!$hasRows) {
                          $htmlRows[] = "<div>$noInfo</div>";
                      }
                      $htmlRows[] = $closing;
                  }
                  $htmlRows[] = '<p class="catquestions">';
                  $htmlRows[] = '<div class="categ">'.$metadataRow['section_header'].'</div>';
                  $htmlRows[] = '<div style="width: 100%;"><ul>';
                  $hasRows = FALSE;
              }
              $field = $metadataRow['field_name'];
              if ($row[$field] && !in_array($field, $notesFields)) {
                  $value = "";
                  if ($choices[$field] && $choices[$field][$row[$field]]) {
                      $value = $choices[$field][$row[$field]];
                  } else {
                      $value = $row[$field];
                  }
                  $htmlRows[] = "<li>".$metadataRow['field_label'].": <span>".$value."</span></li>";
                  $hasRows = TRUE;
              }
          }
          if (!$hasRows) {
              $htmlRows[] = "<div>$noInfo</div>";
          }
          $htmlRows[] = $closing;
          echo implode("\n", $htmlRows)."\n";

          ?>
      </div>
    </div>
  </div>
</section>


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

.catquestions{
  width: 96%;text-align:left; margin-top: 2em;
}
.categ{
    font-family: din-2014, sans-serif;
    letter-spacing: 14px;
    margin-top: 2em;
    margin-left: 0px;
    position: relative;
    z-index: 4;
    top: 0;
    left: 0;
}
.categ+div{
    border-left: 1px solid #ffc66e;
    padding-left: 19px;
    margin-left: 7px;
    font-size: 16px;
    font-weight: 100;
}
.categ+div ul{
  list-style: decimal;
}
.categ+div ul li span{
  font-weight:700;text-decoration: underline;
}
.categ::before{
    content: '';
    display: inline-block;
    width: 32px;
    height: 32px;
    -moz-border-radius: 7.5px;
    -webkit-border-radius: 7.5px;
    border-radius: 22.5px;
    background-color: #ffc66e;
    margin-right: -2px;
    margin-top: 0px;
    position: absolute;
    top: -1px;
    left: -8px;
    z-index: -1;
} 

.categ:nth-of-type(1)+div {border-left: 1px solid #f6dd66;}
.categ:nth-of-type(2)+div {border-left: 1px solid #ec9d50;}
.categ:nth-of-type(3)+div {border-left: 1px solid #5fb749;}
.categ:nth-of-type(4)+div {border-left: 1px solid #a66097;}
.categ:nth-of-type(5)+div {border-left: 1px solid #9ba4ac;}
.categ:nth-of-type(6)+div {border-left: 1px solid #41a9de;}
.categ:nth-of-type(7)+div {border-left: 1px solid #f6dd66;}
.categ:nth-of-type(8)+div {border-left: 1px solid #ec9d50;}
.categ:nth-of-type(9)+div {border-left: 1px solid #5fb749;}
.categ:nth-of-type(10)+div {border-left: 1px solid #a66097;}
.categ:nth-of-type(11)+div {border-left: 1px solid #9ba4ac;}
.categ:nth-of-type(12)+div {border-left: 1px solid #41a9de;}
.categ:nth-of-type(13)+div {border-left: 1px solid #f6dd66;}


.categ:nth-of-type(1)::before {background-color: #f6dd66;}
.categ:nth-of-type(2)::before {background-color: #ec9d50;}
.categ:nth-of-type(3)::before {background-color: #5fb749;}
.categ:nth-of-type(4)::before {background-color: #a66097;}
.categ:nth-of-type(5)::before {background-color: #9ba4ac;}
.categ:nth-of-type(6)::before {background-color: #41a9de;}
.categ:nth-of-type(7)::before {background-color: #f6dd66;}
.categ:nth-of-type(8)::before {background-color: #ec9d50;}
.categ:nth-of-type(9)::before {background-color: #5fb749;}
.categ:nth-of-type(10)::before {background-color: #a66097;}
.categ:nth-of-type(11)::before {background-color: #9ba4ac;}
.categ:nth-of-type(12)::before {background-color: #41a9de;}
.categ:nth-of-type(13)::before {background-color: #f6dd66;}


</style>




<?php include dirname(__FILE__).'/_footer.php'; ?>