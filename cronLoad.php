<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Cohorts;
use Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\CronManager;
use Vanderbilt\CareerDevLibrary\FeatureSwitches;

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
        Application::log("loadCrons");
        $metadata = Download::metadata($token, $server);
        $forms = DataDictionaryManagement::getFormsFromMetadata($metadata);
        $pid = CareerDev::getPid($token);
        $switches = new FeatureSwitches($token, $server, $pid);
        $allRecords = Download::records($token, $server);
        $records = $switches->downloadRecordIdsToBeProcessed();

        CareerDev::clearDate("Last Federal RePORTER Download", $pid);

        if (in_array('reporter', $forms)) {
            // $manager->addCron("drivers/2s_updateRePORTER.php", "updateFederalRePORTER", "Tuesday", $records, 40);
        }
        if (in_array('nih_reporter', $forms)) {
            $manager->addCron("drivers/2s_updateRePORTER.php", "updateNIHRePORTER", "Monday", $records, 30);
        } else if (in_array('exporter', $forms)) {
            $manager->addCron("drivers/2m_updateExPORTER.php", "updateExPORTER", "Monday", $records, 20);
        }
        if (in_array('ldap', $forms)) {
            $manager->addCron("drivers/17_getLDAP.php", "getLDAPs", "Monday", $records, 10000);
        }
        if (!Application::isLocalhost()) {
            if (in_array('coeus', $forms)) {
                $manager->addCron("drivers/19_updateNewCoeus.php", "updateAllCOEUS", "Wednesday", $allRecords, 1000);
            } else if (in_array('coeus2', $forms)) {
                $manager->addCron("drivers/2r_updateCoeus2.php", "processCoeus2", "Thursday", $records, 100);
            }
            // if (in_array('coeus_submission', $forms)) {
                // $manager->addCron("drivers/19_updateNewCoeus.php", "updateCOEUSSubmissions", "Wednesday", $allRecords, 1000);
            // }
        }
        $manager->addCron("drivers/13_pullOrcid.php", "pullORCIDs", "Friday", $allRecords, 100);
        if (in_array('citation', $forms)) {
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
        }

        $manager->addCron("drivers/12_reportStats.php", "reportStats", "Friday", $allRecords, 100000);
        if (Application::isVanderbilt() && !Application::isLocalhost() && in_array("coeus", $forms)) {
            $manager->addCron("drivers/19_updateNewCoeus.php", "sendUseridsToCOEUS", "Friday", $allRecords, 500);
        }
		if (in_array("pre_screening_survey", $forms)) {
			$manager->addCron("drivers/11_vfrs.php", "updateVFRS", "Thursday", $records, 80);
		}
        if (in_array('patent', $forms)) {
            $manager->addCron("drivers/18_getPatents.php", "getPatents", "Tuesday", $records, 100);
        }
        if (in_array("nsf", $forms)) {
            $manager->addCron("drivers/20_nsf.php", "getNSFGrants", "Monday", $records, 100);
        }
        if (in_array("eric", $forms)) {
            $manager->addCron("drivers/23_getERIC.php", "getERIC", "Friday", $records, 100);
        }
        if (in_array("vera", $forms) && in_array("vera_submission", $forms)) {
            $manager->addCron("drivers/22_getVERA.php", "getVERA", "Friday", $allRecords, 100000);
        }

        $cohorts = new Cohorts($token, $server, Application::getModule());
        if ($cohorts->hasReadonlyProjects()) {
            $manager->addCron("drivers/2q_refreshCohortProjects.php", "copyAllCohortProjects", "Monday", $allRecords, 100000);
        }

        $numRecordsForSummary = 15;
        if (Application::isVanderbilt()) {
            $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Tuesday", $allRecords, $numRecordsForSummary, TRUE);
        } else {
            $numDaysPerWeek = $switches->getValue("Days per Week to Build Summaries");
            if (!$numDaysPerWeek) {
                $numDaysPerWeek = 1;
            }
            if ($numDaysPerWeek == 1) {
                $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Tuesday", $records, $numRecordsForSummary, TRUE);
            } else if ($numDaysPerWeek == 3) {
                $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Monday", $records, $numRecordsForSummary, TRUE);
                $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Wednesday", $records, $numRecordsForSummary);
                $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Friday", $records, $numRecordsForSummary);
            } else if ($numDaysPerWeek == 5) {
                $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Monday", $records, $numRecordsForSummary, TRUE);
                $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Tuesday", $records, $numRecordsForSummary);
                $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Wednesday", $records, $numRecordsForSummary);
                $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Thursday", $records, $numRecordsForSummary);
                $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Friday", $records, $numRecordsForSummary);
            }
        }
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
        Application::log("loadInitialCrons");
        $metadata = Download::metadata($token, $server);
        $forms = DataDictionaryManagement::getFormsFromMetadata($metadata);
        Application::log("Forms: ".json_encode($forms));
		$records = Download::recordIds($token, $server);

		if (in_array("pre_screening_survey", $forms)) {
			$manager->addCron("drivers/11_vfrs.php", "updateVFRS", $date, $records, 100);
		}
        if (in_array("coeus", $forms)) {
            $manager->addCron("drivers/19_updateNewCoeus.php", "updateCOEUSGrants", $date, $records, 500);
        } else if (in_array("coeus2", $forms)) {
            $manager->addCron("drivers/2r_updateCoeus2.php", "processCoeus2", $date, $records, 100);
        }
        if (in_array("coeus_submission", $forms)) {
            $manager->addCron("drivers/19_updateNewCoeus.php", "updateCOEUSSubmissions", $date, $records, 500);
        }
        if (Application::isVanderbilt() && in_array("coeus", $forms)) {
            $manager->addCron("drivers/19_updateNewCoeus.php", "sendUseridsToCOEUS", $date, $records, 500);
        }
        if (in_array("ldap", $forms)) {
            $manager->addCron("drivers/17_getLDAP.php", "getLDAPs", $date, $records, 500);
        }
        if (in_array("patent", $forms)) {
            $manager->addCron("drivers/18_getPatents.php", "getPatents", $date, $records, 100);
        }
        if (in_array("nsf", $forms)) {
            $manager->addCron("drivers/20_nsf.php", "getNSFGrants", $date, $records, 100);
        }

        if (in_array("nih_reporter", $forms)) {
            $manager->addCron("drivers/2s_updateRePORTER.php", "updateNIHRePORTER", $date, $records, 100);
        } else if (in_array("exporter", $forms)) {
            $manager->addCron("drivers/2m_updateExPORTER.php", "updateExPORTER", $date, $records, 500);
        }
        if (in_array("citation", $forms)) {
            $manager->addCron("publications/getAllPubs_func.php", "getPubs", $date, $records, 10);
        }
        $manager->addCron("drivers/13_pullOrcid.php", "pullORCIDs", $date, $records, 100);
		$manager->addCron("drivers/6d_makeSummary.php", "makeSummary", $date, $records, 30);
        Application::log("loadInitialCrons loaded");
	} else {
        Application::log("loadInitialCrons without token or server");
    }
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