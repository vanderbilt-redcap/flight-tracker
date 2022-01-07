<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Cohorts;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\CronManager;

require_once(dirname(__FILE__)."/classes/Autoload.php");

# supply day of the week or YYYY-MM-DD
function loadCrons(&$manager, $specialOnly = FALSE, $token = "", $server = "") {
	if (!$token) { global $token; }
	if (!$server) { global $server; }

	if ($specialOnly) {
		// $manager->addCron("drivers/2m_updateExPORTER.php", "updateExPORTER", date("Y-m-d"));
		// $manager->addCron("drivers/2n_updateReporters.php", "updateReporter", date("Y-m-d"));
		// $manager->addCron("drivers/2o_updateCoeus.php", "processCoeus", date("Y-m-d"));
		// $manager->addCron("publications/getAllPubs_func.php", "getPubs", date("Y-m-d"));
		// $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", date("Y-m-d"));
        if (!Application::isLocalhost()) {
            $manager->addCron("drivers/19_updateNewCoeus.php", "sendUseridsToCOEUS", date("Y-m-d"));
            $manager->addCron("drivers/19_updateNewCoeus.php", "updateCOEUSGrants", date("Y-m-d"));
            $manager->addCron("drivers/19_updateNewCoeus.php", "updateCOEUSSubmissions", date("Y-m-d"));
        }
	} else if ($token && $server) {
        $has = checkMetadataForFields($token, $server);
        $pid = CareerDev::getPid($token);
        $records = Download::recordIds($token, $server);

        $manager->addCron("drivers/2s_updateRePORTER.php", "updateFederalRePORTER", "Tuesday", $records, 40);
        if ($has['nih_reporter']) {
            $manager->addCron("drivers/2s_updateRePORTER.php", "updateNIHRePORTER", "Monday", $records, 30);
        } else {
            $manager->addCron("drivers/2m_updateExPORTER.php", "updateExPORTER", "Monday", $records, 20);
        }
        if ($has['news']) {
            $manager->addCron("news/getNewsItems_func.php", "getNewsItems", "Friday", $records, 100);
        }
        if ($has['ldap']) {
            $manager->addCron("drivers/17_getLDAP.php", "getLDAPs", "Thursday", $records, 10000);
        }
        if (!Application::isLocalhost()) {
            if ($has['coeus']) {
                $manager->addCron("drivers/19_updateNewCoeus.php", "updateCOEUSGrants", "Wednesday", $records, 200);
            } else if ($has['coeus2']) {
                $manager->addCron("drivers/2r_updateCoeus2.php", "processCoeus2", "Thursday", $records, 100);
            }
            if ($has['coeus_submissions']) {
                $manager->addCron("drivers/19_updateNewCoeus.php", "updateCOEUSSubmissions", "Wednesday", $records, 200);
            }
        }
        $manager->addCron("drivers/13_pullOrcid.php", "pullORCIDs", "Friday", $records, 40);
        $manager->addCron("publications/getAllPubs_func.php", "getPubs", "Saturday", $records, 10);
        if (!Application::getSetting("fixedPMCs", $pid)) {
            $manager->addCron("clean/updatePMCs.php", "updatePMCs", date("Y-m-d"), $records, 1000);
            Application::saveSetting("fixedPMCs", TRUE, $pid);
        }
        if (!Application::getSetting("fixedPMCsBlank", $pid)) {
            $manager->addCron("clean/updatePMCs.php", "cleanUpBlankInstances", date("Y-m-d"), $records, 1000);
            Application::saveSetting("fixedPMCsBlank", TRUE, $pid);
        }
        if (Application::isVanderbilt() && !Application::getSetting("initializedLexTranslator", $pid)) {
            $manager->addCron("drivers/initializeLexicalTranslator.php", "initialize", date("Y-m-d"), $records, 10);
            Application::saveSetting("initializedLexTranslator", TRUE, $pid);
        }

        # limited group because bibliometric updates take a lot of time due to rate limiters
		$bibliometricRecordsToUpdate = getRecordsToUpdateBibliometrics($token, $server, date("d"), date("t"));
		if (!empty($bibliometricRecordsToUpdate)) {
            $manager->addCron("publications/updateBibliometrics.php", "updateBibliometrics", date("Y-m-d"), $bibliometricRecordsToUpdate);
        }
        $manager->addCron("drivers/12_reportStats.php", "reportStats", "Friday", $records, 100000);
        if (Application::isVanderbilt() && !Application::isLocalhost()) {
            $manager->addCron("drivers/19_updateNewCoeus.php", "sendUseridsToCOEUS", "Wednesday", $records, 500);
        }
		if ($has['vfrs']) {
			$manager->addCron("drivers/11_vfrs.php", "updateVFRS", "Thursday", $records, 80);
		}
        if ($has['patent']) {
            $manager->addCron("drivers/18_getPatents.php", "getPatents", "Tuesday", $records, 100);
        }

        $cohorts = new Cohorts($token, $server, Application::getModule());
        if ($cohorts->hasReadonlyProjects()) {
            $manager->addCron("drivers/2q_refreshCohortProjects.php", "copyAllCohortProjects", "Monday", $records, 100000);
        }

        $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Monday", $records, 30);
        $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Tuesday", $records, 30);
        $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Wednesday", $records, 30);
        $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Thursday", $records, 30);
        $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Friday", $records, 30);
        $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Saturday", $records, 30, TRUE);
	}
}

function loadTestingCrons(&$manager) {
    $date = date("Y-m-d");
    $manager->addCron("drivers/14_connectivity.php", "testConnectivity", $date);
}
function loadInitialCrons(&$manager, $specialOnly = FALSE, $token = "", $server = "") {
	if (!$token) { global $token; }
	if (!$server) { global $server; }

	$date = date("Y-m-d");

	if ($token && $server) {
		$has = checkMetadataForFields($token, $server);
		$records = Download::recordIds($token, $server);

		# summarize institutions first
        $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", $date, $records);

		if ($has['vfrs']) {
			$manager->addCron("drivers/11_vfrs.php", "updateVFRS", $date, $records);
		}
		if ($has['news']) {
			$manager->addCron("news/getNewsItems_func.php", "getNewsItems", $date, $records);
		}
        if ($has['coeus']) {
            $manager->addCron("drivers/19_updateNewCoeus.php", "updateCOEUSGrants", $date, $records);
        } else if ($has['coeus2']) {
            $manager->addCron("drivers/2r_updateCoeus2.php", "processCoeus2", $date, $records);
        }
        if ($has['coeus_submissions']) {
            $manager->addCron("drivers/19_updateNewCoeus.php", "updateCOEUSSubmissions", $date, $records);
        }
        if (Application::isVanderbilt()) {
            $manager->addCron("drivers/19_updateNewCoeus.php", "sendUseridsToCOEUS", $date, $records);
        }
        if ($has['ldap']) {
            $manager->addCron("drivers/17_getLDAP.php", "getLDAPs", $date, $records);
        }
        if ($has['patent']) {
            $manager->addCron("drivers/18_getPatents.php", "getPatents", $date, $records);
        }

        $manager->addCron("drivers/2s_updateRePORTER.php", "updateFederalRePORTER", $date, $records);
        if ($has['nih_reporter']) {
            $manager->addCron("drivers/2s_updateRePORTER.php", "updateNIHRePORTER", $date, $records);
        } else {
            $manager->addCron("drivers/2m_updateExPORTER.php", "updateExPORTER", $date, $records);
        }
		$manager->addCron("publications/getAllPubs_func.php", "getPubs", $date, $records);
        $manager->addCron("drivers/13_pullOrcid.php", "pullORCIDs", $date, $records);
		$manager->addCron("drivers/6d_makeSummary.php", "makeSummary", $date, $records);

		# last because may not have setup. Will fail last
		if ($has['coeus']) {
			$manager->addCron("drivers/2o_updateCoeus.php", "processCoeus", $date);
		}
	}
}

function checkMetadataForFields($token, $server) {
	$metadata = Download::metadata($token, $server);

	$vars = array();
    $vars['coeus2'] = FALSE;
    $vars['coeus'] = FALSE;
	$vars['vfrs'] = FALSE;
    $vars['news'] = FALSE;
    $vars['ldap'] = FALSE;
    $vars['nih_reporter'] = FALSE;
    $vars['patent'] = FALSE;
    $vars['coeus_submissions'] = FALSE;

    $regexes = [
        "/^coeus_/" => "coeus",
        "/^coeus2_/" => "coeus2",
        "/^vfrs_/" => "vfrs",
        "/^ldap_/" => "ldap",
        "/^summary_news$/" => "news",
        "/^nih_/" => "nih_reporter",
        "/^patent_/" => "patent",
        "/^coeussubmission_/" => "coeus_submissions",
    ];

	foreach ($metadata as $row) {
		$field = $row['field_name'];
		foreach ($regexes as $regex => $setting) {
            if (!$vars[$setting] && preg_match($regex, $field)) {
                $vars[$setting] = TRUE;
            }
        }
	}
	return $vars;
}

function getRecordsToUpdateBibliometrics($token, $server, $dayOfMonth, $daysInMonth) {
    $records = Download::recordIds($token, $server);
    $recordsToRun = [];
    foreach ($records as $recordId) {
        if (($recordId - 1) % $daysInMonth == $dayOfMonth - 1) {
            $recordsToRun[] = $recordId;
        }
    }
    return $recordsToRun;
}