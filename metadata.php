<?php

use Vanderbilt\FlightTrackerExternalModule\CareerDev;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use Vanderbilt\CareerDevLibrary\FeatureSwitches;
use Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");

$lastCheckField = "prior_metadata_ts";
$deletionRegEx = DataDictionaryManagement::getDeletionRegEx();

if ($_POST['process'] == "check") {
	$ts = $_POST['timestamp'];
	$lastCheckTs = CareerDev::getSetting($lastCheckField);
	if (!$lastCheckTs) {
		$lastCheckTs = 0;
	}

	# check a maximum of once every 30 seconds
	if ($ts > $lastCheckTs + 30) {
		$requiredCustomFields = ["resources", "departments"];
		$missingField = false;
		foreach ($requiredCustomFields as $setting) {
			if (!trim(Application::getSetting($setting, $pid))) {
				$missingField = true;
				break;
			}
		}
		if ($missingField) {
			$configLink = Application::link("config.php");
			$mssg = "You are missing a required field that is necessary to upgrade your Data Dictionary. The fields ".REDCapManagement::makeConjunction($requiredCustomFields)." are required and can be set via the <a href=\"$configLink\">Configure Application page</a>.";
			echo "<script>$.sweetModal({content: '$mssg', icon: $.sweetModal.ICON_ERROR});</script>";
		} else {
			$metadata = Download::metadataByPid($pid);
			$switches = new FeatureSwitches($token, $server, $pid);
			$files = Application::getMetadataFiles($pid);
			list($missing, $additions, $changed) = DataDictionaryManagement::findChangedFieldsInMetadata($metadata, $files, $deletionRegEx, CareerDev::getRelevantChoices(), $switches->getFormsToExclude(), $pid);
			CareerDev::setSetting($lastCheckField, time(), $pid);
			if (count($additions) + count($changed) > 0) {
				if (Application::isSuperUser()) {
					$module = Application::getModule();
					$pids = $module->getPids();
					$protocol = isset($_SERVER["HTTPS"]) ? 'https' : 'http';
					echo "<div id='metadataWarning' class='install-metadata-box install-metadata-box-danger'>
                <i class='fa fa-exclamation-circle' aria-hidden='true'></i> <a href='javascript:;' onclick='installMetadataForProjects(" . json_encode($pids) . ");'>Click here to install for all " . Application::getProgramName() . " projects (REDCap SuperUsers only).</a>
                </div>";
				}
				echo "<script>const missing = " . json_encode($missing) . ";</script>\n";
				echo "<div id='metadataWarning' class='install-metadata-box install-metadata-box-danger'>
                <i class='fa fa-exclamation-circle' aria-hidden='true'></i> An upgrade in your Data Dictionary exists. <a href='javascript:;' onclick='installMetadata(missing);'>Click here to install.</a>
                <p>The following fields will be added: " . (empty($additions) ? "<i>None</i>" : "<strong>" . implode(", ", $additions) . "</strong>") . "</p>
                <p>The following fields will be changed: " . (empty($changed) ? "<i>None</i>" : "<strong>" . implode(", ", $changed) . "</strong>") . "</p>
            </div>";
			}
		}
	}
} elseif (in_array($_POST['process'], ["install", "install_all"])) {
	Application::increaseProcessingMax(2);
	if (isset($_POST['pids'])) {
		$pidsToRun = [];
		$requestedPids = Sanitizer::sanitizeArray($_POST['pids']);
		$pids = Application::getPids();
		foreach ($requestedPids as $requestedPid) {
			if (REDCapManagement::isActiveProject($requestedPid) && in_array($requestedPid, $pids)) {
				$pidsToRun[] = $requestedPid;
			}
		}
	} else {
		$pidsToRun = [$pid];
	}
	$returnData = DataDictionaryManagement::installMetadataForPids($pidsToRun, $deletionRegEx);
	if (time() < strtotime("2023-11-30")) {
		$module = Application::getModule();
		foreach ($pidsToRun as $currPid) {
			foreach (["followup", "initial_survey"] as $form) {
				$sql = "UPDATE redcap_surveys SET save_and_return_code_bypass = ?, edit_completed_response = ? WHERE project_id = ? AND form_name = ?";
				$module->query($sql, [1, 1, $currPid, $form]);
			}
		}
	}
	echo json_encode($returnData);
} elseif ($_POST['process'] === "install_from_scratch") {
	$institutions = Application::getInstitutions();
	$institutionText = implode("\n", $institutions);
	$departmentText = Application::getSetting("departments", $pid);
	$resourceText = Application::getSetting("resources", $pid);
	$personRoleText = Application::getSetting("person_role", $pid);
	$programRoleText = Application::getSetting("program_roles", $pid);
	$lists = [
		"departments" => $departmentText,
		"resources" => $resourceText,
		"institutions" => $institutionText,
		"person_role" => $personRoleText,
		"program_roles" => $programRoleText,
	];
	DataDictionaryManagement::addLists($pid, $lists, Application::isVanderbilt() && !Application::isLocalhost());
}
