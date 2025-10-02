<?php

namespace Vanderbilt\CareerDevLibrary;

require_once dirname(__FILE__)."/preliminary.php";
require_once dirname(__FILE__)."/../small_base.php";
require_once dirname(__FILE__)."/base.php";
require_once(dirname(__FILE__)."/../classes/Autoload.php");

Application::getUsername();

$enabledSections = MMAHelper::getEnabledSections($pid);

if ($_POST['customQuestions']) {
	$customQuestionData = json_decode($_POST['customQuestions'], true);
	foreach ($customQuestionData as $questionNumber => &$question) {
		$question['questionSource'] = $userid;
		$question['questionNumber'] = $questionNumber;
	}
	Application::saveSetting("customQuestions_mma_$userid", $customQuestionData, $pid);
	//Get Mentors Mentee RecordIds
	$associatedRecords = MMAHelper::getRecordsAssociatedWithUserid($userid, $token, $server);
	$result = MMAHelper::sendInitialEmails($associatedRecords, $pid, $token, $server);
	if (!is_int($result) && $result['error']) {
		echo json_encode($result);
		exit;
	}
	echo json_encode(["result" => $result]);
	exit;
}

require_once dirname(__FILE__).'/_header.php';

//summary_mentor_userid

//redcap_user_information table to go from mentor username (bob, alice, carol) comma seperated list potentially
//will have between 1-3 emails send to all emails in these results
//send them link to customization page. if no userid's for mentors skip this step.

//http://127.0.0.1/external_modules/?prefix=flightTracker&page=mentor%2Fmentor_configure&project_id=23&RedcordIds=[1,4] //urlEncoded
//echo $pid;
//echo $userId;
//echo $userid;
//echo $token;
//echo $server;
//
//$records = Download::recordIds($token, $server);
//
//$userids = Download::userids($token, $server);   // no hash defined -> not public project
//$allMentorUids = Download::primaryMentorUserids($token, $server);
//$records = Download::records
//$redcapData = Download::fieldsForRecords($token, $server, array_unique(array_merge($metadataFields, ["record_id"])), $menteeRecordIds);
//Mentor UserId In summary_mentor_userid can be more than one, can be comma-delimited.
//This is psedoish code
//Get list of all mentors and their mentees.
//If you add a get parameter of uid then you can ghost as another user.
$AllSetsOfMentorsAndUserIds = Download::oneField($token, $server, "summary_mentor_userid");
//Split for mentors and mentees.
//$mentoruserids = preg_split("/\s*(\,\;)\s*/", $AllSetsOfMentorsAndUserIds);

//Will have mentors userid from accessing this page
//If they don't have access to manage this mentee just kill page and exit.
//Gets currently logged in userid


//mentor
?>
<script src="<?= Application::link("/mentor/js/mentorConfigure.js")?>"></script>

<style>
    .centered { text-align: center; }
    .smallest { font-size: 0.5em; }
    .smaller { font-size: 0.75em; }
    input[readonly=readonly] { background-color: #bbbbbb; }
    .red { color: #ea0e0ecc; }
    .green { color: #35482f; }
    .bolded { font-weight: bold; }
    .grey { color: #444444; }
    progress { color: #17a2b8; }
    th,td { text-align: center; padding: 4px; font-size: 0.8em; line-height: 1em; }
    body {
        font-family: europa, sans-serif !important;
        letter-spacing: -0.5px;
        font-size: 1.3em;
    }
    .bg-light { background-color: #ffffff!important; }
    tbody tr td,tbody tr th { border: #888888 solid 1px; }
    .va-top { vertical-align: top; }
</style>

<section class="bg-light">
	<div class="container">
		<div class="row">
			<div class="col-lg-12">
				<h2 style="color: #727272;">Customize Your Mentor Agreement</h2>
			</div>
		</div>
		<div class="row">
			<div class="col-lg-8">
				<p>Your Flight Tracker administrator asked for you to be the mentor on a mentoring agreement. The software will provide several sections of questions, but they also wanted to give you the option to add up to <?php echo MMAHelper::NUM_CUSTOM_QUESTIONS ?> custom custom questions to the survey. No additional questions are required, but they are available. <strong>Please submit this survey and click the button below.</strong> Your mentee will be automatically notified once you click the button.</p>
			</div>
			<div class="col-lg-4">
				<img src="<?= Application::link("mentor/img/temp_image.jpg") ?>" alt="Mentor Configuration" style="max-width: 100%; height: 60%;">
			</div>
		</div>
		<div class="row">
			<div class="col-lg-12 centered">
				<label for="customQuestionsNum">How many custom questions would you like to ask your mentees?</label>
				<select id="customQuestionsNum" name="num_custom_questions">
					<?php
					for ($i = 0; $i <= MMAHelper::NUM_CUSTOM_QUESTIONS; $i++) {
						echo "<option value='$i'>$i</option>";
					}
?>
				</select>
			</div>
		</div>


		<div id="customQuestionArea" class="centered">
		</div>

		<div id="responseArea" class="centered">
		</div>
		<div class="centered">
			<button  onclick="mentorConfigure.serializeQuestions('<?= Application::link("this") ?>'); return false;">Save Question Configuration</button>
		</div>
	</div>
	<input type="text" hidden="hidden" id="csrfToken" value="<?= Application::generateCSRFToken() ?>">
    <input type="hidden" id="customQuestionData" value="<?= htmlspecialchars(json_encode(Application::getSetting("customQuestions_mma_$userid", $pid))) ?>">
</section>


<!--
//require_once dirname(__FILE__).'/_header.php';
-->
