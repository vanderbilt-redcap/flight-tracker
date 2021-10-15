<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\FlightTrackerExternalModule\CareerDevHelp;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

define("NOAUTH", TRUE);
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../../../redcap_connect.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../CareerDevHelp.php");

session_start();

$html = "";
$pageTitle = "";
$pageMenu = "";
if (isset($_POST['fullPage'])) {
	$fullPage = $_POST['fullPage'];
	$menus = CareerDev::getMenus();
	foreach ($menus as $menu) {
		$menuPages = CareerDev::getMenu($menu);
		foreach ($menuPages as $itemTitle => $url) {
			if (!preg_match("/toggleHelp\(.+\)/", $url)) {
				$itemPage = CareerDev::getPageFromUrl($url);
				if (strpos($itemPage, $fullPage) !== FALSE) {
					$pageMenu = $menu;
					$pageTitle = $itemTitle;
					break;
				}
			}
		}
		if ($pageTitle) {
			break;
		}
	}
	$homeLink = "index.php";
	if ($homeLink == $fullPage) {
		$pageTitle = "Front Page";
		$pageMenu = "";
	}
	$html = CareerDevHelp::getHelp($pageTitle, $pageMenu);
	if ($html) {
		echo "<div style='text-align: right;'><a class='smallest' href='javascript:;' onclick='hideHelp(\"".CareerDev::getHelpHiderLink()."\");'>close</a></div>\n";
	}
} else if ($_GET['htmlPage']) {
	if ($_GET['htmlPage'] == "REDCapFAQReports") {
		header("Location: ".APP_PATH_WEBROOT_FULL."index.php?action=help#ss63");
	} else if ($_GET['htmlPage'] == "Codebook") {
		header("Location: Codebook.pdf");
	} else {
		echo "<script src='".CareerDev::link("/js/jquery.min.js")."'></script>\n";
		echo "<script src='".CareerDev::link("/js/jquery-ui.min.js")."'></script>\n";
		echo "<script src='".CareerDev::link("/js/base.js")."&".CareerDev::getVersion()."'></script>\n";
		echo "<link rel='stylesheet' href='".CareerDev::link("/css/jquery-ui.css")."'>\n";
		echo "<link rel='stylesheet' href='".CareerDev::link("/css/career_dev.css")."&".CareerDev::getVersion()."'>\n";

		$htmlPage = REDCapManagement::sanitize($_GET['htmlPage']);
		$possiblePages = getPossibleHelpPages();
		foreach ($possiblePages as $possiblePage) {
		    if ($htmlPage == $possiblePage) {
                $html .= CareerDevHelp::getHelpPage($possiblePage);
                $pageTitle = CareerDevHelp::getPageTitle($possiblePage);
            }
        }
	}
}

$titleHTML = "<h2>Help</h2>\n";
if ($pageTitle) {
	if ($pageMenu) {
		$pageMenu .= ": ";
	}
	$titleHTML = "<h2>Help for ".$pageMenu.$pageTitle."</h2>\n";
}

if ($html) {
	$_SESSION['showHelp'] = TRUE;
	echo $titleHTML;
	echo "<div id='mainHelp'>".$html."</div>\n";
}

function getPossibleHelpPages() {
    return [
        "addIdentifiers.html",
        "copyProject.html",
        "pubWrangler.html",
        "useCaseConversion.html",
        "useCaseResources.html",
        "addNewDataSources.html",
        "departure.html",
        "roi.html",
        "useCaseDemographics.html",
        "useCaseSearches.html",
        "addNewGrants.html",
        "emailMgmt.html",
        "situations.html",
        "useCaseExporting.html",
        "useCaseStats.html",
        "addScholars.html",
        "grantWrangler.html",
        "socialNetworks.html",
        "useCaseGrantsAndPubs.html",
        "whitelist.html",
        "bins.html",
        "kaplanMeierCurves.html",
        "timelines.html",
        "useCaseMentors.html",
        "who.html",
        "changes.html",
        "missingness.html",
        "useCaseBins.html",
        "useCaseProfiles.html",
        "why.html",
        "cohortDesign.html",
        "nihReporting.html",
        "useCaseCohortMetrics.html",
        "useCasePubs.html",
    ];
}