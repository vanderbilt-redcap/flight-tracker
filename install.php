<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;

require_once(dirname(__FILE__)."/CareerDev.php");
require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/classes/Download.php");
require_once(dirname(__FILE__)."/classes/Upload.php");

if (isset($_POST['departments']) && isset($_POST['resources'])) {
	$module = CareerDev::getModule();
	if ($module) {
		require_once(dirname(__FILE__)."/small_base.php");
		$token = CareerDev::getSetting("token");
		$server = CareerDev::getSetting("server");
		$pid = CareerDev::getSetting("pid");
		$tokenName = CareerDev::getSetting("tokenName");
		$installCoeus = CareerDev::getSetting("hasCoeus");
		$institutions = CareerDev::getInstitutions();
		displayInstallHeaders($module, $token, $server, $pid, $tokenName);
		if ($_POST['resources'] && $_POST['departments']) {
			$lists = array(
					"departments" => $_POST['departments'],
					"resources" => $_POST['resources'],
					"institutions" => implode("\n", $institutions),
					);
			$feedback = \Vanderbilt\FlightTrackerExternalModule\addLists($token, $server, $lists, $installCoeus);
			redirectToAddScholars();
		} else {
			echo "<p class='red centered'>You must supply at least one resource and one department!</p>\n";
		}
	} else {
		throw new \Exception("Could not find module!");
	}
}
if (isset($_POST['token']) && isset($_POST['title'])) {
	$requiredFields = array("title", "token", "institution", "short_institution", "timezone", "email", "cities");
	foreach ($requiredFields as $field) {
		if (!$_POST[$field]) {
			sendErrorMessage("Please provide a value for the field '".$field."'!");
		}
	}

	$newToken = $_POST['token'];
	$newServer = APP_PATH_WEBROOT_FULL."api/";
	if (isValidToken($newToken)) {
		$feedback = uploadProjectSettings($newToken, $newServer, $_POST['title']);
		$projectId = getPIDFromToken($newToken, $newServer);
		$eventId = getEventIdForClassical($projectId);

		displayInstallHeaders(CareerDev::getModule(), $newToken, $newServer, $projectId, $_POST['title']);
		echo "<h1>Academic Departments</h1>\n";

		$formsAndLabels = array(
					"custom_grant" => "[custom_number]",
					"followup" => "",
					"promotion" => "",
					"reporter" => "[reporter_projectnumber]",
					"exporter" => "[exporter_full_project_num]",
					"citation" => "[citation_pmid] [citation_title]",
					"resources" => "[resources_resource]: [resources_date]",
					"honors_and_awards" => "[honor_name]: [honor_date]",
					);
		if ($_POST['coeus']) {
			$formsAndLabels["coeus"] = "[coeus_sponsor_award_number]";
		}
		setupRepeatingForms($eventId, $formsAndLabels);

		$settingFields = array(
				'institution' => $_POST['institution'],
				'short_institution' => $_POST['short_institution'],
				'other_institutions' => $_POST['other_institutions'],
				'token' => $newToken,
				'event_id' => $eventId,
				'pid' => $projectId,
				'server' => $newServer,
				'admin_email' => $_POST['email'],
				'tokenName' => $_POST['title'],
				'timezone' => $_POST['timezone'],
				'cities' => $_POST['cities'],
				'hasCoeus' => $_POST['coeus'],
				'internal_k_length' => '3',
				'k12_kl2_length' => '3',
				'individual_k_length' => '5',
				'run_tonight' => FALSE,
				);
		setupModuleSettings($projectId, $settingFields);

		$surveysAndLabels = array(
						"initial_survey" => "Flight Tracker Initial Survey",
						"followup" => "Flight Tracker Followup Survey",
						);
		setupSurveys($projectId, $surveysAndLabels);

		echo makeDepartmentPrompt($projectId);
		echo makeInstallFooter();
	} else {
		sendErrorMessage("Invalid token: $token");
	}
} else {
	displayInstallHeaders();
	echo makeIntroPage($_GET['pid']);
	echo makeInstallFooter();
}

function redirectToAddScholars() {
	header("Location: ".CareerDev::link("add.php")."&headers=false");
}

function isValidToken($token) {
	return (strlen($token) == 32);
}

function uploadProjectSettings($token, $server, $title) {
	$redcapData = array(
				"is_longitudinal" => 0,
				"surveys_enabled" => 1,
				"record_autonumbering_enabled" => 1,
				"project_title" => "Flight Tracker - ".$title,
				"custom_record_label" => "[identifier_first_name] [identifier_last_name]",
				);
	$feedback = Upload::projectSettings($redcapData, $token, $server);
	return $feedback;
}


function getPIDFromToken($token, $server) {
	$projectSettings = Download::getProjectSettings($token, $server);
	if (isset($projectSettings['project_id'])) {
		return $projectSettings['project_id'];
	}
	return "";
}

function getEventIdForClassical($projectId) {
	$sql = "SELECT DISTINCT(m.event_id) AS event_id FROM redcap_events_metadata AS m INNER JOIN redcap_events_arms AS a ON (a.arm_id = m.arm_id) WHERE a.project_id = '$projectId'";
	$q = db_query($sql);
	if ($row = db_fetch_assoc($q)) {
		return $row['event_id'];
	}
	throw new \Exception("The event_id is not defined. (This should never happen.)");
}

function getExternalModuleId($prefix) {
	$sql = "SELECT external_module_id FROM redcap_external_modules WHERE directory_prefix = '".db_real_escape_string($prefix)."'";
	$q = db_query($sql);
	if ($row = db_fetch_assoc($q)) {
		return $row['external_module_id'];
	}
	throw new \Exception("The external_module_id is not defined. (This should never happen.)");
}

function setupRepeatingForms($eventId, $formsAndLabels) {
	$sqlEntries = array();
	foreach ($formsAndLabels as $form => $label) {
		array_push($sqlEntries, "($eventId, '".db_real_escape_string($form)."', '".db_real_escape_string($label)."')");
	}
	if (!empty($sqlEntries)) {
		$sql = "REPLACE INTO redcap_events_repeat (event_id, form_name, custom_repeat_form_label) VALUES".implode(",", $sqlEntries);
		db_query($sql);
	}
}

function setupModuleSettings($projectId, $fields) {
	foreach ($fields as $field => $value) {
		CareerDev::setSetting($field, $value);
	}
}

function setupSurveys($projectId, $surveysAndLabels) {
	foreach ($surveysAndLabels as $form => $label) {
		$sql = "REPLACE INTO redcap_surveys (project_id, font_family, form_name, title, instructions, acknowledgement, question_by_section, question_auto_numbering, survey_enabled, save_and_return, logo, hide_title, view_results, min_responses_view_results, check_diversity_view_results, end_survey_redirect_url, survey_expiration) VALUES ($projectId, '16', '".db_real_escape_string($form)."', '".db_real_escape_string($label)."', '<p><strong>Please complete the survey below.</strong></p>\r\n<p>Thank you!</p>', '<p><strong>Thank you for taking the survey.</strong></p>\r\n<p>Have a nice day!</p>', 0, 1, 1, 1, NULL, 0, 0, 10, 0, NULL, NULL)";
		db_query($sql);
	}
}

function sendErrorMessage($mssg) {
	header("Location: ".CareerDev::link("install.php")."?mssg=".urlencode($mssg));
}

function makeDepartmentPrompt($projectId) {
	$html = "";

	$html .= "<style>\n";
	$html .= getCSS();
	$html .= "</style>\n";

	$html .= "<form method='POST' action='".preg_replace("/pid=\d+/", "pid=$projectId", CareerDev::getLink("install.php"))."'>\n";
	$html .= "<p class='centered'>Please enter a list of your academic departments.<br>(One per line.)<br>\n";
	$html .= "<textarea name='departments' class='config'></textarea></p>\n";
	$html .= "<p class='centered'>Please enter a list of resources your scholars may use (e.g., workshops, tools).<br>(One per line.)<br>\n";
	$html .= "<textarea name='resources' class='config'></textarea></p>\n";
	$html .= "<p class='centered'><button>Configure Fields</button></p>\n";
	$html .= "</form>\n";

	return $html;
}

function makeIntroPage($projectId) {
	$warnings = array();
	$rights = \REDCap::getUserRights(USERID);
	$defaultToken = "";
	if ($rights[USERID]['api_token'] && $rights[USERID]['api_import'] && $rights[USERID]['api_export']) {
		$defaultToken = $rights[USERID]['api_token'];
	}
	if (!$rights[USERID]['api_import']) {
		array_push($warnings, "This user must have <a href='".APP_PATH_WEBROOT."UserRights/index.php?pid=$projectId'>API Import rights</a> for this project in order to install ".CareerDev::getProgramName());
	}
	if (!$rights[USERID]['api_export']) {
		array_push($warnings, "This user must have <a href='".APP_PATH_WEBROOT."UserRights/index.php?pid=$projectId'>API Export rights</a> for this project in order to install ".CareerDev::getProgramName());
	}
	if ((!$rights[USERID]['api_import']) || (!$rights[USERID]['api_export'])) {
		array_push($warnings, "To assign API rights, follow the link; select your username from the list; select 'Edit user priviledges;' and check API Import and API Export rights."); 
	}

	$html = "";
	$html .= "<p class='small centered recessed'>(Not expecting this page? <a class='recessed' href='".APP_PATH_EXTMOD."manager/project.php?pid=$projectId'>Click Here</a> to Disable Flight Tracker)</p>\n";
	$html .= "<style>\n";
	$html .= getCSS();
	$html .= "</style>\n";
	$html .= "<h1>Flight Tracker Installation</h1>\n";
	if (!empty($warnings)) {
		foreach ($warnings as $warning) {
			$html .= "<p class='centered red'>$warning</p>\n";
		}
		return $html;
	}

	if (isset($_GET['mssg'])) {
		$html .= "<p class='centered red'>{$_GET['mssg']}</p>";
	}
	$html .= "<form method='POST' action='".CareerDev::link("install.php")."'>\n";
	$html .= "<table style='margin-left: auto; margin-right: auto; max-width: 800px;'>\n";

	$html .= "<tr>\n";
	$html .= "<td colspan='2'>\n";
	$html .= "<h2>Software Mission</h2>\n";
	$html .= "<p class='centered'>Providing a tool for insight that tracks the career development of a population of scholars through following publications, resource use, mentoring, and federal grants.</p>\n";
	$html .= "<h2><a href='".CareerDev::link("help/install.php")."'>Installation Video</a></h2>\n";
	$html .= "<h2>What You'll Need</h2>\n";
	$html .= "<ol>\n";
	$html .= "<li>Ten minutes (or less)</li>\n";
	$html .= "<li>A <a href='".APP_PATH_WEBROOT."API/project_api.php?pid=".$projectId."'>REDCap API Token</a> (32 characters) to this project. (You may need to contact your REDCap Administrator to generate this for you.)</li>\n";
	$html .= "<li>Basic configuration information (see below)</li>\n";
	$html .= "<li>A list of primary academic departments for all institutions represented - at the very least, those that your scholars are part of</li>\n";
	$html .= "<li>A list of institutional resources you offer for help (e.g., workshops, seminars)</li>\n";
	$html .= "<li>A list of names, emails, and institutions</li>\n";
	$html .= "</ol>\n";
	$html .= "<h2>What You'll Get</h2>\n";
	$html .= "<ol>\n";
	$html .= "<li>A REDCap project filled with your scholars.</li>\n";
	$html .= "<li>Background processes that update your project every week or every day, provided that your REDCap instance has the Cron enabled.</li>\n";
	$html .= "<li>Downloads of all of your scholars' federal grants and publications.</li>\n";
	$html .= "<li>Lots of ways to 'slice and dice' your data.</li>\n";
	$html .= "</ol>\n";
	$html .= "</td>\n";
	$html .= "</tr>\n";

	$html .= "<tr>\n";
	$html .= "<td colspan='2'><h2>Please Supply the Following</h2></td>\n";
	$html .= "</tr>\n";

	$html .= "<tr>\n";
	$html .= "<td style='text-align: right;'>Title:<br><span class='small'>(i.e., Name of Project)</span></td>\n";
	$html .= "<td><input type='text' name='title'></td>\n";
	$html .= "</tr>\n";

	$html .= "<tr>\n";
	$html .= "<td style='text-align: right;'><a href='".APP_PATH_WEBROOT."API/project_api.php?pid=".$projectId."'>REDCap Token</a> (32 characters):<br><span class='small'>with API Import/Export rights<br>(<b>overwrites entire project</b>)</span></td>\n";
	$html .= "<td><input type='text' name='token' value='$defaultToken'></td>\n";
	$html .= "</tr>\n";

	$html .= "<tr>\n";
	$html .= "<td style='text-align: right;'>Full Institution Name:<br><span class='small'>(e.g., Vanderbilt University Medical Center)</span></td>\n";
	$html .= "<td><input type='text' name='institution'></td>\n";
	$html .= "</tr>\n";

	$html .= "<tr>\n";
	$html .= "<td style='text-align: right;'>Short Institution Name:<br><span class='small'>(e.g., Vanderbilt)<br>This is the institution name that your scholars will be searched under in the NIH.</span></td>\n";
	$html .= "<td><input type='text' name='short_institution'></td>\n";
	$html .= "</tr>\n";

	$html .= "<tr>\n";
	$html .= "<td style='text-align: right;'>Other Affliiated Institutions:<br><span class='small'>(Short Names, List Separated by Commas)<br>E.g., Vanderbilt pools resources to track scholars from Meharry and Tennessee State. These names will be searched from the NIH as well.<br>Optional.</span></td>\n";
	$html .= "<td><input type='text' name='other_institutions'></td>\n";
	$html .= "</tr>\n";

	$zones = timezone_identifiers_list();
	$currZone = date_default_timezone_get();
	$html .= "<tr>\n";
	$html .= "<td style='text-align: right;'>Timezone:</td>\n";
	$html .= "<td>\n";
	$html .= "<select name='timezone'>\n";
	foreach ($zones as $zone) {
		$html .= "<option value='$zone'";
		if ($zone == $currZone) {
			$html .= " selected";
		}
		$html .= ">$zone</option>\n";
	}
	$html .= "</select>\n";
	$html .= "</td>\n";
	$html .= "</tr>\n";

	$html .= "<tr>\n";
	$html .= "<td style='text-align: right;'>Admin Email(s):<br><span class='small'>(List Separated by Commas)</span></td>\n";
	$html .= "<td><input type='text' name='email'></td>\n";
	$html .= "</tr>\n";

	$html .= "<tr>\n";
	$html .= "<td style='text-align: right;'>Home Cities of Institutions:<br><span class='small'>(No States, just Cities)<br>(List Separated by Commas)</span></td>\n";
	$html .= "<td><input type='text' name='cities'></td>\n";
	$html .= "</tr>\n";

	$html .= "<tr>\n";
	$html .= "<td style='text-align: right;'>Should the COEUS module be installed?<br><span class='small'>(Requires Custom Programming)</span></td>\n";
	$html .= "<td>\n";
	$html .= "<input type='radio' value='1' id='coeus_1' name='coeus'> <label for='coeus_1'>Yes</label> \n";
	$html .= "<input type='radio' value='0' id='coeus_0' name='coeus' checked> <label for='coeus_0'>No</label> \n";
	$html .= "</td>\n";
	$html .= "</tr>\n";

	$html .= "<tr>\n";
	$html .= "<td colspan='2' style='text-align: center;' ><button>Transform My Project!</button></td>\n";
	$html .= "</tr>\n";

	$html .= "</table>\n";
	$html .= "</form>\n";

	return $html;
}

function makeInstallHeaders() {
	$html = "";

	$html .= "<head>\n";
	$html .= "<title>Flight Tracker for Scholars</title>\n";
	$html .= "<link rel='icon' href='".CareerDev::link("img/flight_tracker_icon.png")."'>\n";
	$html .= "<link rel='stylesheet' type='text/css' href='".CareerDev::link("css/career_dev.css")."'>\n";
	$html .= "</head>\n";
	$html .= "<body>\n";
	$html .= "<p class='centered'>".CareerDev::makeLogo()."</p>\n";

	return $html;
}

function makeInstallFooter() {
	return "</body>\n";
}

function displayInstallHeaders($module = NULL, $token = NULL, $server = NULL, $pid = NULL, $tokenName = NULL) {
	if (isset($_GET['headers'])) {
		require_once(dirname(__FILE__)."/charts/baseWeb.php");
		if ($module) {
			echo $module->makeHeaders($token, $server, $pid, $tokenName);
		}
	} else {
		echo makeInstallHeaders();
	}
}

function getCSS() {
	$html = "";
	$html .= "td { padding: 8px; }\n";
	$html .= "input[type=text],select { width: 200px; }\n";
	$html .= "button { font-size: 20px; color: white; background-color: black; }\n";
	return $html;
}
