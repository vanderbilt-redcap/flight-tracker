<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use Vanderbilt\CareerDevLibrary\CronManager;
use Vanderbilt\CareerDevLibrary\EmailManager;
use Vanderbilt\CareerDevLibrary\NavigationBar;

require_once(dirname(__FILE__)."/CareerDev.php");
require_once(dirname(__FILE__)."/classes/Crons.php");
require_once(dirname(__FILE__)."/classes/EmailManager.php");
require_once(dirname(__FILE__)."/classes/NavigationBar.php");
require_once(dirname(__FILE__)."/cronLoad.php");

class FlightTrackerExternalModule extends AbstractExternalModule
{
	function getPrefix() {
		return $this->prefix;
	}

	function getName() {
		return $this->name;
	}

	function enqueueTonight() {
		$this->setProjectSetting("run_tonight", TRUE);
	}

	function emails() {
		$this->setupApplication();
		$pids = $this->framework->getProjectsWithModuleEnabled();
		error_log($this->getName()." sending emails for pids ".json_encode($pids));
		echo $this->getName()." sending emails for pids ".json_encode($pids)."\n";
		foreach ($pids as $pid) {
			$token = $this->getProjectSetting("token", $pid);
			$server = $this->getProjectSetting("server", $pid);
			$tokenName = $this->getProjectSetting("tokenName", $pid);
			error_log("Sending emails for $tokenName (pid $pid)");
			echo "Sending emails for $tokenName (pid $pid)\n";
			$mgr = new EmailManager($token, $server, $pid, $this);
			$mgr->sendRelevantEmails();
		}
	}

	function cron() {
		$this->setupApplication();
		$pids = $this->framework->getProjectsWithModuleEnabled();
		error_log($this->getName()." running for pids ".json_encode($pids));
		foreach ($pids as $pid) {
			$token = $this->getProjectSetting("token", $pid);
			$server = $this->getProjectSetting("server", $pid);
			$tokenName = $this->getProjectSetting("tokenName", $pid);
			$adminEmail = $this->getProjectSetting("admin_email", $pid);
			error_log("Using $tokenName $adminEmail");
			CareerDev::setPid($pid);
			if ($token && $server) {
				# only have token and server in initialized projects
				$mgr = new CronManager($token, $server, $pid);
				if ($this->getProjectSetting("run_tonight", $pid)) {
					$this->setProjectSetting("run_tonight", FALSE, $pid);
					loadInitialCrons($mgr, FALSE, $token, $server); 
				} else {
					echo $this->getName().": Loading crons for pid $pid\n";
					loadCrons($mgr, FALSE, $token, $server);
				}
				error_log($this->getName().": Running crons for pid $pid");
				$mgr->run($adminEmail, $tokenName, $pid);
				error_log($this->getName().": cron run complete for pid $pid");
			}
		}
	}

	function setupApplication() {
		CareerDev::$passedModule = $this;
	}

	function hook_every_page_before_render($project_id) {
		$this->setupApplication();
		if (PAGE == "DataExport/index.php") {
			echo "<script src='".CareerDev::link("/js/jquery.min.js")."'></script>\n";
			echo "<script src='".CareerDev::link("/js/colorCellFunctions.js")."'></script>\n";
			echo "<script>\n";
			echo "$(document).ready(function() { setTimeout(function() { transformColumn(); }, 500); });\n";
			echo "</script>\n";
		}
	}

	function hook_every_page_top($project_id) {
		$this->setupApplication();
		$tokenName = $this->getProjectSetting("tokenName", $project_id);
		$token = $this->getProjectSetting("token", $project_id);
		$server = $this->getProjectSetting("server", $project_id);
		if ($tokenName) {
			# turn off for surveys and login pages
			$url = $_SERVER['PHP_SELF'];
			if (USERID && !preg_match("/surveys/", $url) && !isset($_GET['s'])) {
				echo $this->makeHeaders($this, $token, $server, $project_id, $tokenName);
			}
		} else {
			if (self::canRedirectToInstall()) {
				header("Location: ".$this->getUrl("install.php"));
			}
		}
	}

	function hook_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
		$this->setupApplication();
		if ($instrument == "summary") {
			require_once(dirname(__FILE__)."/hooks/summaryHook.php");
		} else if ($instrument == "initial_survey") {
			require_once(dirname(__FILE__)."/hooks/checkHook.php");
		} else if ($instrument == "followup") {
			require_once(dirname(__FILE__)."/hooks/followupHook.php");
		}
	}

	function hook_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
		$this->setupApplication();
		if ($instrument == "summary") {
			require_once(dirname(__FILE__)."/hooks/summaryHook.php");
		} else if ($instrument == "initial_survey") {
			require_once(dirname(__FILE__)."/hooks/checkHook.php");
		} else if ($instrument == "followup") {
			require_once(dirname(__FILE__)."/hooks/followupHook.php");
		}
	}

	function makeHeaders($token, $server, $pid, $tokenName) {
		$str = "";
		$str .= "<link rel='stylesheet' href='".CareerDev::link("/css/w3.css")."'>\n";
		$str .= "<style>\n";

		# must add fonts here or they will not show up in REDCap menus
		$str .= "@font-face { font-family: 'Museo Sans'; font-style: normal; font-weight: normal; src: url('".CareerDev::link("/fonts/exljbris - MuseoSans-500.otf")."'); }\n";

		$str .= ".w3-dropdown-hover { display: inline-block !important; float: none !important; }\n";
		$str .= ".w3-dropdown-hover button,a.w3-bar-link { font-size: 14px; }\n";
			$str .= "a.w3-bar-link { display: inline-block !important; float: none !important; }\n";
		$str .= ".w3-bar { font-family: 'Museo Sans', Arial, Helvetica, sans-serif; text-align: center !important; }\n";
		$str .= "a.w3-button,button.w3-button { padding: 8px 12px !important; }\n";
		$str .= "a.w3-button { color: black !important; float: none !important; }\n";
		$str .= ".w3-button a,.w3-dropdown-content a { color: white !important; font-size: 16px !important; }\n";
		$str .= "p.recessed { font-size: 12px; color: #888888; font-size: 11px; margin: 4px 12px 4px 12px; }\n";
		$str .= ".topHeaderWrapper { background-color: white; height: 80px; top: 0px; width: 100%; }\n";
		$str .= ".topHeader { margin: 0 auto; max-width: 1200px; }\n";
		$str .= ".topBar { font-family: 'Museo Sans', Arial, Helvetica, sans-serif; padding: 0px; }\n";
		if (!CareerDev::isREDCap()) {
			$str .= "body { margin-bottom: 60px; }\n";    // for footer
			$str .= ".bottomFooter { z-index: 1000000; position: fixed; left: 0; bottom: 0; width: 100%; background-color: white; }\n";
			$str .= ".bottomBar { font-family: 'Museo Sans', Arial, Helvetica, sans-serif; padding: 5px; }\n";
		}
		$str .= "a.nounderline { text-decoration: none; }\n";
		$str .= "a.nounderline:hover { text-decoration: dotted; }\n";
		$str .= "img.brandLogo { height: 40px; margin: 20px; }\n";
		$str .= ".recessed,.recessed a { color: #888888; font-size: 11px; }\n";
		$str .= "p.recessed,div.recessed { margin: 2px; }\n";
		$str .= "</style>\n";
	
		if (!CareerDev::isFAQ() && CareerDev::isHelpOn()) {
			$currPage = CareerDev::getCurrPage();
			$str .= "<script>$(document).ready(function() { showHelp('".CareerDev::getHelpLink()."', '".$currPage."'); });</script>\n";
		}
	
		$str .= "<div class='topHeaderWrapper'>\n";
		$str .= "<div class='topHeader'>\n";
		$str .= "<div class='topBar' style='float: left; padding-left: 5px;'><img alt='Flight Tracker for Scholars' src='".CareerDev::link("/img/flight_tracker_logo_small.png")."'></div>\n";
		if ($base64 = $this->getBrandLogo()) {
			$str .= "<div class='topBar' style='float:right;'><img src='$base64' class='brandLogo'></div>\n";
		} else {
			$str .= "<div class='topBar' style='float:right;'><p class='recessed'>$tokenName</p></div>\n";
		}
		$str .= "</div>\n";      // topHeader
		$str .= "</div>\n";      // topHeaderWrapper
	
		if (!CareerDev::isREDCap()) {
			$px = 300;
			$str .= "<div class='bottomFooter'>\n";
			$str .= "<div class='bottomBar' style='float: left;'>\n";
			$str .= "<div class='recessed' style='width: $px"."px;'>Copyright &#9400 ".date("Y")." <a class='nounderline' href='https://vumc.org/'>Vanderbilt University Medical Center</a></div>\n";
			$str .= "<div class='recessed' style='width: $px"."px;'>from <a class='nounderline' href='https://edgeforscholars.org/'>Edge for Scholars</a></div>\n";
			$str .= "<div class='recessed' style='width: $px"."px;'><a class='nounderline' href='https://projectredcap.org/'>Powered by REDCap</a></div>\n";
			$str .= "</div>\n";    // bottomBar
			$str .= "<div class='bottomBar' style='float: right;'><span class='recessed'>funded by</span><br>\n";
			$str .= "<img src='".CareerDev::link("/img/ctsa.png")."' style='height: 22px;'></div>\n";
			$str .= "</div>\n";    // bottomBar
			$str .= "</div>\n";    // bottomFooter
		}
	
		$navBar = new \Vanderbilt\CareerDevLibrary\NavigationBar();
		$navBar->addFALink("home", "Home", CareerDev::getHomeLink());
		$navBar->addFAMenu("clinic-medical", "General", CareerDev::getMenu("General"));
		$navBar->addFAMenu("table", "View", CareerDev::getMenu("Data"));
		$navBar->addFAMenu("calculator", "Wrangle", CareerDev::getMenu("Wrangler"));
		$navBar->addFAMenu("school", "Scholars", CareerDev::getMenu("Scholars"));
		$navBar->addMenu("<img src='".CareerDev::link("/img/redcap_translucent_small.png")."'> REDCap", CareerDev::getMenu("REDCap"));
		$navBar->addFAMenu("tachometer-alt", "Dashboards", CareerDev::getMenu("Dashboards"));
		$navBar->addFAMenu("filter", "Cohorts / Filters", CareerDev::getMenu("Cohorts"));
		$navBar->addFAMenu("chalkboard-teacher", "Mentors", CareerDev::getMenu("Mentors"));
		$navBar->addFAMenu("pen", "Resources", CareerDev::getMenu("Resources"));
		$navBar->addFAMenu("question-circle", "Help", CareerDev::getMenu("Help"));
		$str .= $navBar->getHTML();
	
		if (!CareerDev::isFAQ()) {
			$str .= "<div class='shadow' id='help'></div>\n";
		}
	
		return $str;
	}

	public function getBrandLogoName() {
		return "brand_logo";
	}

	public function getBrandLogo() {
		return $this->getProjectSetting($this->getBrandLogoName(), $base64);
	}

	public function canRedirectToInstall() {
        	$bool = !self::isAJAXPage() && !self::isAPITokenPage() && !self::isUserRightsPage() && !self::isExternalModulePage() && ($_GET['page'] != "install");
        	if ($_GET['pid']) {
                	# project context
                	$bool = $bool && self::hasAppropriateRights(USERID);
        	}
        	return $bool;
	}

	private static function isAJAXPage() {
        	$page = $_SERVER['PHP_SELF'];
        	if (preg_match("/ajax/", $page)) {
                	return TRUE;
        	}
        	if (preg_match("/index.php/", $page) && isset($_GET['route'])) {
                	return TRUE;
        	}
        	return FALSE;
	}

	private static function isAPITokenPage() {
        	$page = $_SERVER['PHP_SELF'];
        	$tokenPages = array("project_api_ajax.php", "project_api.php");
        	if (preg_match("/API/", $page)) {
                	foreach ($tokenPages as $tokenPage) {
                        	if (preg_match("/$tokenPage/", $page)) {
                                	return TRUE;
                        	}
                	}
        	}
        	if (preg_match("/plugins/", $page)) {
                	return TRUE;
        	}
        	return FALSE;
	}

	private static function isUserRightsPage() {
        	$page = $_SERVER['PHP_SELF'];
        	if (preg_match("/\/UserRights\//", $page)) {
                	return TRUE;
        	}
        	return FALSE;
	}

	private static function isExternalModulePage() {
        	$page = $_SERVER['PHP_SELF'];
        	if (preg_match("/external_modules\/manager\/project.php/", $page)) {
                	return TRUE;
        	}
        	if (preg_match("/external_modules\/manager\/ajax\//", $page)) {
                	return TRUE;
        	}
        	return FALSE;
	}

	private static function isModuleEnabled($pid) {
        	return \ExternalModules\ExternalModules::getProjectSetting("flightTracker", $pid, \ExternalModules\ExternalModules::KEY_ENABLED);
	}

	private static function hasAppropriateRights($userid) {
        	$rights = \REDCap::getUserRights($userid);
        	return $rights[$userid]['design'];
	}

	private $prefix = "flightTracker";
	private $name = "Flight Tracker";
}
