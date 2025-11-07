<?php

namespace Vanderbilt\CareerDevLibrary;

require_once dirname(__FILE__)."/preliminary.php";
require_once dirname(__FILE__)."/../small_base.php";
require_once dirname(__FILE__)."/base.php";
require_once(dirname(__FILE__)."/../classes/Autoload.php");

if (isset($_POST['sectionsToShow'])) {
	$allSections = MMAHelper::getAllSections();
	$requestedSections = Sanitizer::sanitizeArray($_POST['sectionsToShow']);
    $menteeRecordId = Sanitizer::sanitize($_POST['recordId']);
	$sectionsToShow = [];
	foreach ($requestedSections as $section) {
		if (isset($allSections[$section])) {
			$sectionsToShow[] = $section;
		}
	}
    $session = MMAHelper::getCurrentDatabaseSession($menteeRecordId, $pid);
	$_SESSION[MMAHelper::STEPS_KEY] = $sectionsToShow;
    $session[MMAHelper::STEPS_KEY] = $sectionsToShow;
    MMAHelper::saveCurrentDatabaseSession($menteeRecordId, $pid, $session);
	if (empty($sectionsToShow)) {
		echo json_encode(["error" => "No validated sections to show!"]);
	} else {
		echo json_encode(["result" => "success"]);
	}
	exit;
}

require_once dirname(__FILE__).'/_header.php';

if (isset($_REQUEST['uid']) && MMAHelper::getMMADebug()) {
	$userid2 = Sanitizer::sanitize($_REQUEST['uid']);
	$uidString = "&uid=".$userid2;
	$spoofing = MMAHelper::makeSpoofingNotice($userid2);
} else {
	$userid2 = $hash ?: Application::getUsername();
	$uidString = $hash ? "&hash=".$hash : "";
	$spoofing = "";
}
$phase = Sanitizer::sanitize($_GET['phase'] ?? "");
$step = Sanitizer::sanitize($_GET['step'] ?: "initial");
$sections = MMAHelper::getAllSections();
if (($step !== "initial") && !isset($sections[$step])) {
	$step = "initial";
}

$dateToRemind = "now";


$menteeRecordId = false;
if ($_REQUEST['menteeRecord']) {
	$records = Download::recordIdsByPid($pid);
	$menteeRecordId = Sanitizer::getSanitizedRecord($_GET['menteeRecord'], $records);
	list($myMentees, $myMentors) = MMAHelper::getMenteesAndMentors($menteeRecordId, $userid2, $token, $server);
} else {
	throw new \Exception("You must specify a mentee record!");
}
if (isset($_GET['test'])) {
	echo "myMentees: ".json_encode($myMentees)."<br>";
	echo "myMentors: ".json_encode($myMentors)."<br>";
}

$menteeName = Download::fullName($token, $server, $menteeRecordId);
$metadataFields = Download::metadataFieldsByPidWithPrefix($pid, MMAHelper::PREFIX);
$metadata = MMAHelper::getMetadata($pid, $metadataFields);
$allMetadataForms = Download::metadataForms($token, $server);
$notesFields = MMAHelper::getNotesFields($metadataFields);

list($firstName, $lastName) = MMAHelper::getNameFromREDCap($userid2, $token, $server);
$otherMentors = REDCapManagement::makeConjunction($myMentors["name"] ?? []);

$redcapData = Download::fieldsForRecordsByPid($pid, array_merge(["record_id"], $metadataFields), [$menteeRecordId]);
if ($_REQUEST['instance']) {
	$currInstance = Sanitizer::sanitizeInteger($_REQUEST['instance']);
} elseif ($hash) {
	$currInstance = 1;
} else {
	$maxInstance = REDCapManagement::getMaxInstance($redcapData, MMAHelper::INSTRUMENT, $menteeRecordId);
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
		&& ($row['redcap_repeat_instrument'] == MMAHelper::INSTRUMENT)
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
            <?= $spoofing ?>
            <h2 style="color: #727272;">Hi, <?= $firstName ?>!</h2>
            <?= MMAHelper::makeSurveyHTML($otherMentors, "mentor(s)", $menteeRecordId, $metadata) ?>
        </div>
    </div>
  </div>
</section>

<?php

include dirname(__FILE__).'/_footer.php';
$commentJS = MMAHelper::makeCommentJS($userid2, $menteeRecordId, $currInstance, $currInstance, $priorNotes, $menteeName, $dateToRemind, true, in_array("mentoring_agreement_evaluations", $allMetadataForms), $pid);
echo MMAHelper::getMenteeHead($hash, $menteeRecordId, $currInstance, $uidString, $userid2, $commentJS, $pid);
$enqueuedSteps = $_SESSION[MMAHelper::STEPS_KEY] ?: MMAHelper::getCurrentDatabaseSession($menteeRecordId, $pid)[MMAHelper::STEPS_KEY] ?: [];
if (!in_array($step, $enqueuedSteps)) {
    $_SESSION[MMAHelper::STEPS_KEY] = [];
    $session = MMAHelper::getCurrentDatabaseSession($menteeRecordId, $pid);
    $session[MMAHelper::STEPS_KEY] = [];
    MMAHelper::saveCurrentDatabaseSession($menteeRecordId, $pid, $session);
	$step = "initial";
}
$thisUrl = Application::link("this").$uidString;
if ($step == "initial") {
	echo MMAHelper::getInitialSetup($thisUrl, $phase, $menteeRecordId, $currInstance, $myMentors);
} else {
	echo MMAHelper::makePrefillDropdownHTML($surveysAvailableToPrefill, $uidString, $instances, $currInstance);
	echo MMAHelper::makeStepHTML($metadata, $step, $redcapData, $menteeRecordId, $currInstance, $phase, $notesFields, $userid2, $thisUrl, $token, $server, $pid, $myMentors);
}
