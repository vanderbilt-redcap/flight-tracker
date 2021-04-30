<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \ExternalModules\ExternalModules;

ini_set("memory_limit", "4096M");
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once(dirname(__FILE__)."/CareerDev.php");
require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/classes/Download.php");
require_once(dirname(__FILE__)."/classes/REDCapManagement.php");
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
        $departments = trim($_POST['departments']);
        $resources = trim($_POST['resources']);
		if ($resources && $departments) {
			$lists = array(
					"departments" => $departments,
					"resources" => $resources,
					"institutions" => implode("\n", $institutions),
					);
			$feedback = \Vanderbilt\FlightTrackerExternalModule\addLists($token, $server, $pid, $lists, $installCoeus);
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
		$feedback = \Vanderbilt\FlightTrackerExternalModule\uploadProjectSettings($newToken, $newServer, $_POST['title']);
		$projectId = REDCapManagement::getPIDFromToken($newToken, $newServer);
		$eventId = REDCapManagement::getEventIdForClassical($projectId);

		displayInstallHeaders(CareerDev::getModule(), $newToken, $newServer, $projectId, $_POST['title']);
		echo "<h1>Academic Departments</h1>\n";

		$settingFields = [
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
				'default_from' => 'noreply.flighttracker@vumc.org',
				'run_tonight' => FALSE,
				'grant_class' => $_POST['grant_class'],
				'grant_number' => $_POST['grant_number'],
                'auto_recalculate' => '1',
                'shared_forms' => [],
				];
        \Vanderbilt\FlightTrackerExternalModule\setupModuleSettings($projectId, $settingFields);

		$metadata = Download::metadata($newToken, $newServer);
        $formsAndLabels = CareerDev::getRepeatingFormsAndLabels($metadata);
        if ($_POST['coeus']) {
            // $formsAndLabels["coeus"] = "[coeus_sponsor_award_number]";
        }
        REDCapManagement::setupRepeatingForms($eventId, $formsAndLabels);

        $surveysAndLabels = array(
						"initial_survey" => "Flight Tracker Initial Survey",
						"followup" => "Flight Tracker Followup Survey",
						);
		REDCapManagement::setupSurveys($projectId, $surveysAndLabels);

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
	return REDCapManagement::isValidToken($token);
}

function sendErrorMessage($mssg) {
	header("Location: ".CareerDev::link("install.php")."?mssg=".urlencode($mssg));
}

function makeDepartmentPrompt($projectId) {
	$html = "";

	$html .= "<style>\n";
	$html .= getCSS();
	$html .= "</style>\n";

	$style = " style='width: 400px; height: 400px;'";
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
	if (!$rights[USERID]['design']) {
		array_push($warnings, "This user must have <a href='".APP_PATH_WEBROOT."UserRights/index.php?pid=$projectId'>Design rights</a> for this project in order to install ".CareerDev::getProgramName());
	}
	if ((!$rights[USERID]['api_import']) || (!$rights[USERID]['api_export'])) {
		array_push($warnings, "To assign API rights, follow the link; select your username from the list; select 'Edit user priviledges;' and check API Import rights, API Export rights."); 
	}

	$html = "";
	$html .= "<script>\n";
	$html .= "function changeGrantClass(name) {\n";
	$html .= "\tvar val = $('[name='+name+']:checked').val();\n";
	$html .= "\tif ((val == 'K') || (val == 'T')) {\n";
	$html .= "\t\t$('grant_number_row').show();\n";
	$html .= "\t} else {\n";
	$html .= "\t\t$('grant_number_row').hide();\n";
	$html .= "\t}\n";
	$html .= "}\n";
	$html .= "</script>\n";
	$html .= "<p class='small centered recessed'>(Not expecting this page? <a class='recessed' href='".ExternalModules::$BASE_URL."manager/project.php?pid=$projectId'>Click Here</a> to Disable Flight Tracker)</p>\n";
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
	$html .= "<li>Your REDCap Administrator handy via email or phone.</li>\n";
	$html .= "<li>A <a href='".APP_PATH_WEBROOT."API/project_api.php?pid=".$projectId."'>REDCap API Token</a> (32 characters) to this project. (You may need to contact your REDCap Administrator to generate this for you.)</li>\n";
	$html .= "<li>REDCap Design User Rights. (The API token can be shared among all the project's users, but each Flight Tracker user needs Design rights.)</li>\n";
	$html .= "<li>Basic configuration information (see below)</li>\n";
	$html .= "<li>A list of primary academic departments for all institutions represented - at the very least, those that your scholars are part of</li>\n";
	$html .= "<li>A list of institutional resources you offer for help (e.g., workshops, seminars)</li>\n";
	$html .= "<li>A list of names, emails, and institutions</li>\n";
	$html .= "</ol>\n";
	$html .= "<h2>Federal Data Sources Consulted</h2>\n";
	$html .= "<p class='centered'>The following data sources need to be accessible (white-listed) from your REDCap server in order for Flight Tracker to work.</p>\n";
	$html .= CareerDev::getSiteListHTML();
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
	$html .= "<td style='text-align: right;'>Other Affiliated Institutions:<br><span class='small'>(Short Names, List Separated by Commas)<br>E.g., Vanderbilt pools resources to track scholars from Meharry and Tennessee State. These names will be searched from the NIH as well.<br>Optional.</span></td>\n";
	$html .= "<td><input type='text' name='other_institutions'></td>\n";
	$html .= "</tr>\n";

	$html .= "<tr>\n";
	$html .= "<td style='text-align: right;'>Class of Project:<br><span class='small'>If the project is affiliated with a grant, specify what type of grant. Small variations exist for these grant classes.</span></td>\n";
	$html .= "<td>";
	$grantClasses = CareerDev::getGrantClasses();
	$grantClassRadios = array();
	$grantClassName = "grant_class";
	foreach ($grantClasses as $value => $label) {
		$id = $grantClassName."_".$value;
		array_push($grantClassRadios, "<input type='radio' id='$id' name='$grantClassName' value='$value' onclick='changeGrantClass(\"$grantClassName\");'><label for='$id'> $label</label>");
	}
	$html .= implode("<br>", $grantClassRadios);
	$html .= "</td>\n";
	$html .= "</tr>\n";

	$html .= "<tr id='grant_number_row' style='display: none;'>\n";
	$html .= "<td style='text-align: right;'>Grant Number (e.g., R01CA654321):</td>\n";
	$html .= "<td><input type='text' name='grant_number'></td>\n";
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

	// turn off COEUS since few use it
	$html .= "<input type='hidden' value='0' name='coeus'>\n";

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
	$html .= "<link rel='stylesheet' type='text/css' href='".CareerDev::link("css/career_dev.css")."&".CareerDev::getVersion()."'>\n";
	$html .= "<script src='".CareerDev::link("js/jquery.min.js")."'></script>\n";
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
	$html .= "textarea.config { width: 400px; height: 400px; }\n";
	return $html;
}
