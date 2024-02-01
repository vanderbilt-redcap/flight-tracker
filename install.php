<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\REDCapLookupByUserid;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;
use \Vanderbilt\CareerDevLibrary\Application;
use \ExternalModules\ExternalModules;
use \Vanderbilt\CareerDevLibrary\DataDictionaryManagement;

ini_set("memory_limit", "4096M");
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once(dirname(__FILE__)."/classes/Autoload.php");
require_once(dirname(__FILE__)."/small_base.php");

$action = $_GET['action'] ?? "";
if ($action == "localizeVariables") {
	$module = CareerDev::getModule();
	if ($module) {
		require_once(dirname(__FILE__)."/small_base.php");
		$token = Application::getSetting("token");
		$server = Application::getSetting("server");
		$pid = Application::getSetting("pid");
		$tokenName = Application::getSetting("tokenName");
		$installCoeus = Application::getSetting("hasCoeus");
        $displayInstitutions = Application::getInstitutions($pid, FALSE);
		displayInstallHeaders($module, $token, $server, $pid, $tokenName);
        $departments = trim(Sanitizer::sanitizeWithoutChangingQuotes($_POST['departments'])) ?: "Department";
        $resources = trim(Sanitizer::sanitizeWithoutChangingQuotes($_POST['resources'])) ?: "Resource";
		if ($resources && $departments) {
			$lists = [
                "departments" => $departments,
                "resources" => $resources,
                "institutions" => implode("\n", $displayInstitutions),
            ];
            foreach (REDCapManagement::getOptionalSettings() as $setting => $label) {
                $lists[$setting] = trim(Sanitizer::sanitizeWithoutChangingQuotes($_POST[$setting] ?? ""));
            }
			DataDictionaryManagement::addLists($token, $server, $pid, $lists, $installCoeus);
			redirectToAddScholars();
		} else {
			echo "<p class='red centered'>You must supply at least one resource and one department!</p>";
		}
	} else {
		throw new \Exception("Could not find module!");
	}
} else if ($action == "configureProject") {
    $requiredFields = ["title", "token", "institution", "short_institution", "timezone", "email"];
    foreach ($requiredFields as $field) {
        if (!$_POST[$field]) {
            sendErrorMessage("Please provide a value for the field '" . $field . "'!");
        }
    }

    $newToken = Sanitizer::sanitize($_POST['token']);
    $newServer = APP_PATH_WEBROOT_FULL . "api/";
    if (isValidToken($newToken)) {
        $title = Sanitizer::sanitize($_POST['title']);
        \Vanderbilt\FlightTrackerExternalModule\uploadProjectSettings($newToken, $newServer, $title);
        $projectId = REDCapManagement::getPIDFromToken($newToken, $newServer);
        $eventId = REDCapManagement::getEventIdForClassical($projectId);

        displayInstallHeaders(CareerDev::getModule(), $newToken, $newServer, $projectId, $title);
        echo "<h1>Academic Departments</h1>";

        $menteeAgreementLink = "";
        if (CareerDev::isVanderbilt()) {
            $menteeAgreementLink = Application::getDefaultVanderbiltMenteeAgreementLink();
        }

        $settingFields = [
            'institution' => Sanitizer::sanitizeWithoutChangingQuotes($_POST['institution']),
            'short_institution' => Sanitizer::sanitizeWithoutChangingQuotes($_POST['short_institution']),
            'other_institutions' => Sanitizer::sanitizeWithoutChangingQuotes($_POST['other_institutions']),
            'display_institutions' => Sanitizer::sanitizeWithoutChangingQuotes($_POST['display_institutions']),
            'token' => $newToken,
            'event_id' => $eventId,
            'pid' => $projectId,
            'server' => $newServer,
            'admin_email' => Sanitizer::sanitize($_POST['email']),
            'tokenName' => Sanitizer::sanitizeWithoutChangingQuotes($_POST['title']),
            'timezone' => Sanitizer::sanitize($_POST['timezone']),
            'hasCoeus' => Sanitizer::sanitize($_POST['coeus']),
            'internal_k_length' => '3',
            'k12_kl2_length' => '3',
            'individual_k_length' => '5',
            'default_from' => 'noreply.flighttracker@vumc.org',
            'run_tonight' => FALSE,
            'grant_class' => Sanitizer::sanitize($_POST['grant_class']),
            'grant_number' => Sanitizer::sanitize($_POST['grant_number']),
            'auto_recalculate' => '1',
            'shared_forms' => [],
            'mentee_agreement_link' => $menteeAgreementLink,
            'server_class' => Sanitizer::sanitize($_POST['server_class']),
        ];
        \Vanderbilt\FlightTrackerExternalModule\setupModuleSettings($projectId, $settingFields);

        $metadata = Download::metadata($newToken, $newServer);
        $formsAndLabels = DataDictionaryManagement::getRepeatingFormsAndLabels($metadata);
        if ($_POST['coeus']) {
            // $formsAndLabels["coeus"] = "[coeus_sponsor_award_number]";
        }
        DataDictionaryManagement::setupRepeatingForms($eventId, $formsAndLabels);

        $surveysAndLabels = DataDictionaryManagement::getSurveysAndLabels($metadata, $pid);
        DataDictionaryManagement::setupSurveys($projectId, $surveysAndLabels);

        echo makeDepartmentPrompt($projectId);
        echo makeInstallFooter();
    } else {
        sendErrorMessage("Invalid token: $token");
    }
} else if ($action == "restoreMetadata") {
    $lists = [
        "departments" => Application::getSetting("departments", $pid),
        "resources" => Application::getSetting("resources", $pid),
        "institutions" => implode("\n", Application::getInstitutions($pid, FALSE)),
    ];
    foreach (REDCapManagement::getOptionalSettings() as $setting => $label) {
        $lists[$setting] = Application::getSetting($setting, $pid);
    }
    $feedback = DataDictionaryManagement::addLists($token, $server, $pid, $lists, Application::isVanderbilt() && !Application::isLocalhost());
    redirectToHomePage();
} else if (($action === "") && $token && $server) {
    $metadataFields = Download::metadataFields($token, $server);
    if (empty($metadataFields)) {
        displayInstallHeaders();
        $thisUrl = Application::link("this");
        echo "<h1>Empty Data Dictionary!</h1>";
        echo "<p><a href='$thisUrl&action=restoreMetadata'>Click here to restore your data</a></p>";
        echo makeInstallFooter();
    } else {
        redirectToHomePage();
    }
} else {
	displayInstallHeaders();
	echo makeIntroPage($pid);
	echo makeInstallFooter();
}

function redirectToAddScholars() {
    header("Location: ".Application::link("add.php")."&headers=false");
}

function redirectToHomePage() {
    header("Location: ".Application::link("index.php"));
}

function isValidToken($token) {
	return REDCapManagement::isValidToken($token);
}

function sendErrorMessage($mssg) {
	header("Location: ".Application::link("install.php")."&mssg=".urlencode($mssg));
}

function makeDepartmentPrompt($projectId) {
	$html = "";

	$html .= "<style>";
	$html .= getCSS();
	$html .= "</style>";

	if (Application::isVanderbilt()) {
        list($respDepts, $defaultDepartments) = REDCapManagement::downloadURL("https://redcap.vanderbilt.edu/plugins/career_dev/data/departments.txt");
        list($respResources, $defaultResources) = REDCapManagement::downloadURL("https://redcap.vanderbilt.edu/plugins/career_dev/data/resources.txt");
        if (($respDepts != 200) || ($respResources != 200)) {
            $defaultDepartments = "";
            $defaultResources = "";
        }
    } else {
	    $defaultDepartments = "";
	    $defaultResources = "";
    }

    $thisUrl = preg_replace("/pid=\d+/", "pid=$projectId", CareerDev::getLink("install.php"));
	$html .= "<form method='POST' action='$thisUrl&action=localizeVariables'>";
	$html .= Application::generateCSRFTokenHTML();
	$html .= "<p class='centered max-width'>Please enter a list of your <strong>Academic Departments</strong>.<br/>(One per line.)<br/>";
	$html .= "<textarea name='departments' class='config'>$defaultDepartments</textarea></p>";
	$html .= "<p class='centered max-width'>Please enter a list of <strong>Resources</strong> your scholars may use. These are items that your institution offers to help your scholars achieve career success, like workshops or tools. For example, Vanderbilt offers focused workshops, studios, pilot funding, feedback sessions, and grant writing resources.<br/>(One per line.)<br/>";
	$html .= "<textarea name='resources' class='config'>$defaultResources</textarea></p>";

    $optionalSettings = REDCapManagement::getOptionalSettings();
    $optionalSettingKeys = array_keys($optionalSettings);
    $suffix = "_div";
    foreach ($optionalSettingKeys as $currIndex => $setting) {
        $label = $optionalSettings[$setting];
        $id = $setting.$suffix;
        $nextIndex = $currIndex + 1;
        $nextSetting = $optionalSettingKeys[$nextIndex] ?? "";
        $nextId = ($nextIndex < count($optionalSettings)) ? $optionalSettingKeys[$nextIndex].$suffix : "";
        $onblur = ($nextId && $nextSetting) ? "onblur='if ($(this).val() !== \"\") { $(\"#$nextId\").show(); } else  if ($(\"[name=$nextSetting]\").val() === \"\") { $(\"#$nextId\").hide(); }'" : "";
        $styleCSS = preg_match("/_\d+$/", $setting) ? "style='display: none;'" : "";
        $html .= "<div id='$id' $styleCSS>";
        $html .= "<p class='centered max-width'>The following field is optional. It can remain blank if desired.<br/><strong>$label</strong><br/>(One per line.)<br/>";
        $html .= "<textarea name='$setting' class='config' $onblur ></textarea></p>";
        $html .= "</div>";
    }

	$html .= "<p class='centered'><button onclick='if (!verifyFieldsNotBlank([\"departments\", \"resources\"])) { alert(\"Cannot leave fields blank!\"); return false; } else { return true; }'>Configure Fields</button></p>";
	$html .= "</form>";

	$html .= "<script>
function verifyFieldsNotBlank(fields) {
    for (let i=0; i < fields.length; i++) {
        const field = fields[i];
        if ($('#'+field).length > 0) {
            if ($('#'+field).val() === '') {
                return false;
            }
        } else if ($('[name='+field+']').length > 0) {
            if ($('[name='+field+']').val() === '') {
                return false;
            }
        } else {
            console.log('Could not find field '+field);
        }
    }
    return true;
}
</script>";

	return $html;
}

function makeIntroPage($projectId) {
    $_GET['pid'] = $projectId;
	$warnings = array();
	$rights = \REDCap::getUserRights(USERID);
	$defaultToken = "";
	if ($rights[USERID]['api_token'] && $rights[USERID]['api_import'] && $rights[USERID]['api_export']) {
		$defaultToken = $rights[USERID]['api_token'];
	}
	if (!$rights[USERID]['api_import']) {
		$warnings[] = "This user must have <a href='" . APP_PATH_WEBROOT . "UserRights/index.php?pid=$projectId'>API Import rights</a> for this project in order to install " . CareerDev::getProgramName();
	}
	if (!$rights[USERID]['api_export']) {
		$warnings[] = "This user must have <a href='" . APP_PATH_WEBROOT . "UserRights/index.php?pid=$projectId'>API Export rights</a> for this project in order to install " . CareerDev::getProgramName();
	}
	if (!$rights[USERID]['design']) {
		$warnings[] = "This user must have <a href='" . APP_PATH_WEBROOT . "UserRights/index.php?pid=$projectId'>Design rights</a> for this project in order to install " . CareerDev::getProgramName();
	}
	if ((!$rights[USERID]['api_import']) || (!$rights[USERID]['api_export'])) {
		$warnings[] = "To assign API rights, follow the link; select your username from the list; select 'Edit user priviledges;' and check API Import rights, API Export rights.";
	}
    $baseUrl = ExternalModules::$BASE_URL ?? APP_URL_EXTMOD_RELATIVE;
    $projectTitle = \REDCap::getProjectTitle();
    $currentUser = Application::getUsername();
    $lookup = new REDCapLookupByUserid($currentUser);
    $currentUserEmail = $lookup->getEmail();

    $html = "";
	$html .= "<script>
function changeGrantClass(name) {
	const val = $('[name='+name+']:checked').val();
	console.log('changeGrantClass '+name+' checked: '+val);
	if ((val === 'K') || (val === 'T')) {
	    $('#grant_number_row').show();
	} else {
	    $('#grant_number_row').hide();
	}
}
</script>";
	$html .= "<p class='small centered recessed'>(Not expecting this page? <a class='recessed' href='$baseUrl"."manager/project.php?pid=$projectId'>Click Here</a> to Disable Flight Tracker)</p>";
	$html .= "<style>";
	$html .= getCSS();
	$html .= "</style>";
	$html .= "<h1>Flight Tracker Installation</h1>";
	if (!empty($warnings)) {
		foreach ($warnings as $warning) {
			$html .= "<p class='centered red'>$warning</p>";
		}
		return $html;
	}

	if (isset($_GET['mssg'])) {
	    $mssg = Sanitizer::sanitizeWithoutChangingQuotes($_GET['mssg']);
		$html .= "<p class='centered red'>{$mssg}</p>";
	}
    $thisUrl = Application::link("this");
	$html .= "<form method='POST' action='$thisUrl&action=configureProject'>";
	$html .= Application::generateCSRFTokenHTML();
	$html .= "<table style='margin-left: auto; margin-right: auto; max-width: 800px;'>";
	
	$html .= "<tr>";
	$html .= "<td colspan='2'>";
	$html .= "<h2>Software Mission</h2>";
	$html .= "<p class='centered'>Providing a tool for insight that tracks the career development of a population of scholars through following publications, resource use, mentoring, and federal grants.</p>";
	$html .= "<h2><a href='".Application::link("help/install.php")."'>Installation Video</a></h2>";
	$html .= "<h2>What You'll Need</h2>";
	$html .= "<ol>";
	$html .= "<li>Ten minutes (or less)</li>";
	$html .= "<li>Your REDCap Administrator handy via email or phone.</li>";
	$html .= "<li>A <a href='".APP_PATH_WEBROOT."API/project_api.php?pid=".$projectId."'>REDCap API Token</a> (32 characters) to this project. (You may need to contact your REDCap Administrator to generate this for you.)</li>";
	$html .= "<li>REDCap Design User Rights. (The API token can be shared among all the project's users, but each Flight Tracker user needs Design rights.)</li>";
	$html .= "<li>Basic configuration information (see below)</li>";
	$html .= "<li>A list of primary academic departments for all institutions represented - at the very least, those that your scholars are part of</li>";
	$html .= "<li>A list of institutional resources you offer for help (e.g., workshops, seminars)</li>";
	$html .= "<li>A list of names, emails, and institutions</li>";
	$html .= "</ol>";
	$html .= "<h2>Federal Data Sources Consulted</h2>";
	$html .= "<p class='centered'>The following data sources need to be accessible (white-listed) from your REDCap server in order for Flight Tracker to work.</p>";
	$html .= CareerDev::getSiteListHTML();
	$html .= "<h2>What You'll Get</h2>";
	$html .= "<ol>";
	$html .= "<li>A REDCap project filled with your scholars.</li>";
	$html .= "<li>Background processes that update your project every week or every day, provided that your REDCap instance has the Cron enabled.</li>";
	$html .= "<li>Downloads of all of your scholars' federal grants and publications.</li>";
	$html .= "<li>Lots of ways to 'slice and dice' your data.</li>";
	$html .= "</ol>";
	$html .= "</td>";
	$html .= "</tr>";

	$html .= "<tr>";
	$html .= "<td colspan='2'><h2>Please Supply the Following</h2></td>";
	$html .= "</tr>";

	$html .= "<tr>";
	$html .= "<td style='text-align: right;'><label for='title'>Title:<br><span class='small'>(i.e., Name of Project)</span></label></td>";
	$html .= "<td><input type='text' id='title' name='title' value='$projectTitle' /></td>";
	$html .= "</tr>";

	$html .= "<tr>";
	$html .= "<td style='text-align: right;'><label for='token'><a href='".APP_PATH_WEBROOT."API/project_api.php?pid=".$projectId."'>REDCap Token</a> (32 characters):<br><span class='small'>with API Import/Export rights<br>(<b>overwrites entire project</b>)</span></label></td>";
	$html .= "<td><input type='text' id='token' name='token' value='$defaultToken' /></td>";
	$html .= "</tr>";

	$html .= "<tr>";
	$html .= "<td style='text-align: right;'><label for='institution'>Full Institution Name:<br><span class='small'>(e.g., Vanderbilt University Medical Center)<br>This should match what is reported on the <a href='https://reporter.nih.gov/' target='_blank'>NIH/Federal RePORTER systems</a> or in a <a href='https://pubmed.ncbi.nlm.nih.gov/' target='_blank'>PubMed paper</a>.</span></label></td>";
	$html .= "<td><input type='text' id='institution' name='institution' /></td>";
	$html .= "</tr>";

	$html .= "<tr>";
	$html .= "<td style='text-align: right;'><label for='short_institution'>Short Institution Name:<br><span class='small'>(e.g., Vanderbilt)<br>This is the institution name that your scholars will be searched under in the NIH.</span></label></td>";
	$html .= "<td><input type='text' id='short_institution' name='short_institution' /></td>";
	$html .= "</tr>";

    $html .= "<tr>";
    $html .= "<td style='text-align: right;'><label for='other_institutions'>Other Affiliated Institutions:<br><span class='small'>(Short Names, List Separated by Commas)<br>E.g., Vanderbilt pools resources to track scholars from Meharry and Tennessee State. These names will be searched from the NIH as well.<br>Optional.</span></label></td>";
    $html .= "<td><input type='text' id='other_institutions' name='other_institutions' /></td>";
    $html .= "</tr>";

    $html .= "<tr>";
    $html .= "<td style='text-align: right;'><label for='display_institutions'>'Home' Institutions that Your Scholars Belong To:<br/><span class='small'>(Short Names, List Separated by Commas)<br/>E.g., Vanderbilt, Meharry, Tennessee State.</span></label></td>";
    $html .= "<td><input type='text' id='display_institutions' name='display_institutions' /></td>";
    $html .= "</tr>";

    $html .= "<tr>";
	$html .= "<td style='text-align: right;'>Class of Project:<br><span class='small'>If the project is affiliated with a grant, specify what type of grant. Small variations exist for these grant classes.</span></td>";
	$html .= "<td>";
	$grantClasses = CareerDev::getGrantClasses();
	$grantClassRadios = array();
	$grantClassName = "grant_class";
	foreach ($grantClasses as $value => $label) {
		$id = $grantClassName."_".$value;
		$grantClassRadios[] = "<input type='radio' id='$id' name='$grantClassName' value='$value' onclick='changeGrantClass(\"$grantClassName\");'><label for='$id'> $label</label>";
	}
	$html .= implode("<br>", $grantClassRadios);
	$html .= "</td>";
	$html .= "</tr>";

	$html .= "<tr id='grant_number_row' style='display: none;'>";
	$html .= "<td style='text-align: right;'><label for='grant_number'>Grant Number (e.g., R01CA654321):</label></td>";
	$html .= "<td><input type='text' id='grant_number' name='grant_number' /></td>";
	$html .= "</tr>";

    $html .= "<tr>";
    $html .= "<td style='text-align: right;'>Class of Server:</td>";
    $html .= "<td>";
    $serverClasses = CareerDev::getServerClasses();
    $serverClassRadios = [];
    $serverClassName = "server_class";
    foreach ($serverClasses as $value => $label) {
        $id = $serverClassName."_".$value;
        $checked = "";
        if ($value == "prod") {
            $checked = " checked";
        }
        $serverClassRadios[] = "<input type='radio' id='$id' name='$serverClassName' value='$value'$checked><label for='$id'> $label</label>";
    }
    $html .= implode("<br>", $serverClassRadios);
    $html .= "</td>";
    $html .= "</tr>";

    $zones = timezone_identifiers_list();
	$currZone = date_default_timezone_get();
	$html .= "<tr>";
	$html .= "<td style='text-align: right;'><label for='timezone'>Timezone:</label></td>";
	$html .= "<td>";
	$html .= "<select name='timezone' id='timezone'>";
	foreach ($zones as $zone) {
		$html .= "<option value='$zone'";
		if ($zone == $currZone) {
			$html .= " selected";
		}
		$html .= ">$zone</option>";
	}
	$html .= "</select>";
	$html .= "</td>";
	$html .= "</tr>";

	$html .= "<tr>";
	$html .= "<td style='text-align: right;'><label for='email'>Admin Email(s):<br><span class='small'>(List Separated by Commas)</span></label></td>";
	$html .= "<td><input type='text' id='email' name='email' value='$currentUserEmail' /></td>";
	$html .= "</tr>";

	// turn off COEUS since few use it
	$html .= "<input type='hidden' value='0' name='coeus'>";

	$html .= "<tr>";
	$html .= "<td colspan='2' style='text-align: center;' ><button>Transform My Project!</button></td>";
	$html .= "</tr>";

	$html .= "</table>";
	$html .= "</form>";

	return $html;
}

function makeInstallHeaders() {
	$html = "";

    $iconUrl = Application::link("img/flight_tracker_icon.png");
    $cssUrl = Application::link("css/career_dev.css");
    $jqueryUrl = Application::link("js/jquery.min.js");
    $version = Application::getVersion();

	$html .= "<head>";
	$html .= "<title>Flight Tracker for Scholars</title>";
	$html .= "<link rel='icon' href='$iconUrl'>";
	$html .= "<link rel='stylesheet' type='text/css' href='$cssUrl&$version'>";
	$html .= "<script src='$jqueryUrl'></script>";
	$html .= "</head>";
	$html .= "<body>";
	$html .= "<p class='centered'>".CareerDev::makeLogo()."</p>";

	return $html;
}

function makeInstallFooter() {
	return "</body>";
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
	$html .= "td { padding: 8px; }";
	$html .= "input[type=text],select { width: 200px; }";
	$html .= "button { font-size: 20px; color: white; background-color: black; }";
	$html .= "textarea.config { width: 400px; height: 250px; }";
	return $html;
}
