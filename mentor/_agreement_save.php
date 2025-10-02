<?php

namespace Vanderbilt\CareerDevLibrary;

require_once dirname(__FILE__)."/preliminary.php";
require_once dirname(__FILE__)."/../small_base.php";
require_once dirname(__FILE__)."/base.php";
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$metadata = MMAHelper::getMetadata($pid);
$records = Download::recordIds($token, $server);
if (!$_POST['record_id'] || !in_array($_POST['record_id'], $records)) {
	die("Improper Record Id");
}
list($myMentees, $myMentors) = MMAHelper::getMenteesAndMentors($_POST['record_id'], Application::getUsername(), $token, $server);
$customQuestions = MMAHelper::getCustomQuestions($pid, $myMentors);
list($adminResponse, $mentorResponse) = MMAHelper::parseCustomQuestionResponsesFromFullForm($_POST, $customQuestions);
$readableAdmin = MMAHelper::getCustomQuestionsReadable($customQuestions, $adminResponse);
$readableMentor = MMAHelper::getCustomQuestionsReadable($customQuestions, $mentorResponse);

//Remove the custom questions from $_POST
$uploadRow = MMAHelper::transformCheckboxes($_POST, $metadata);
$uploadRow = MMAHelper::handleTimestamps($uploadRow, $token, $server, $metadata);
$uploadRow["mentoring_custom_question_json"] = json_encode($mentorResponse);
$uploadRow["mentoring_custom_question_readable"] = $readableMentor;
$uploadRow["mentoring_custom_question_json_admin"] = json_encode($adminResponse);
$uploadRow["mentoring_custom_question_readable_admin"] = $readableAdmin;
$uploadRow["redcap_repeat_instrument"] = "mentoring_agreement";

try {
	$feedback = Upload::oneRow($uploadRow, $token, $server);
	echo json_encode($feedback);
} catch (\Exception $e) {
	echo "Exception: ".$e->getMessage();
}
