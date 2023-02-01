<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Cohorts;
use Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\CronManager;
use Vanderbilt\CareerDevLibrary\FeatureSwitches;

require_once(dirname(__FILE__)."/classes/Autoload.php");

function runMainCrons(&$manager, $token, $server) {
    $pid = CareerDev::getPid($token);
    Application::log("loadCrons", $pid);

    try {
        $metadata = Download::metadata($token, $server);
        $forms = DataDictionaryManagement::getFormsFromMetadata($metadata);
        $metadataFields = DataDictionaryManagement::getFieldsFromMetadata($metadata);
        $switches = new FeatureSwitches($token, $server, $pid);
        if (in_array("identifier_stop_collection", $metadataFields)) {
            $allRecords = Download::recordsWithDownloadActive($token, $server);
        } else {
            $allRecords = Download::recordIds($token, $server);
        }
        $records = $switches->downloadRecordIdsToBeProcessed($allRecords);
        $securityTestMode = Application::getSetting("security_test_mode", $pid);

        CareerDev::clearDate("Last Federal RePORTER Download", $pid);

        $sanitizeQuotesSetting = "sanitizeQuotes";
        if (!Application::getSetting($sanitizeQuotesSetting, $pid) && (time() < strtotime("2022-12-01"))) {
            $manager->addCron("clean/sanitizerQuotes.php", "transformBadQuotes", date("Y-m-d"), $allRecords, 10000);
            Application::saveSetting($sanitizeQuotesSetting, "1", $pid);
        }


        if (
            in_array("promotion_workforce_sector", $metadataFields)
            && in_array("promotion_activity", $metadataFields)
            && Application::getSetting("updated_job_categories", $pid)
        ) {
            $manager->addCron("drivers/updateJobCategories.php", "updateJobCategories", date("Y-m-d"));
        }

        if (in_array('reporter', $forms)) {
            // $manager->addCron("drivers/2s_updateRePORTER.php", "updateFederalRePORTER", "Tuesday", $records, 40);
        }
        if (in_array('nih_reporter', $forms)) {
            $manager->addCron("drivers/2s_updateRePORTER.php", "updateNIHRePORTER", "Monday", $records, 30);
        } else if (in_array('exporter', $forms)) {
            $manager->addCron("drivers/2m_updateExPORTER.php", "updateExPORTER", "Monday", $records, 20);
        }
        if (in_array('ldapds', $forms)) {
            $manager->addCron("drivers/17_getLDAP.php", "getLDAPs", "Monday", $records, 10000);
            $manager->addCron("drivers/17_getLDAP.php", "getLDAPs", "2022-10-06", $records, 10000);
        }
        if (in_array("ies_grant", $forms)) {
            $manager->addCron("drivers/24_getIES.php", "getIES", "Friday", $records, 10000);
        }
        if (!Application::isLocalhost() && Application::isVanderbilt()) {
            $manager->addCron("drivers/grantRepositoryFetch.php", "checkGrantRepository", "Monday", $allRecords, 500);
            $manager->addCron("drivers/2p_updateStudioUse.php", "copyStudios", "Monday", $allRecords, 500);
            $manager->addCron("drivers/2p_updateStudioUse.php", "deleteAllStudios", "2022-11-13");
            $manager->addCron("drivers/2p_updateStudioUse.php", "copyStudios", "2022-11-13");
            if (in_array('coeus', $forms)) {
                $manager->addCron("drivers/19_updateNewCoeus.php", "updateAllCOEUS", "Wednesday", $allRecords, 1000);
                $manager->addCron("drivers/19_updateNewCoeus.php", "sendUseridsToCOEUS", "Friday", $allRecords, 500);
            } else if (in_array('coeus2', $forms)) {
                $manager->addCron("drivers/2r_updateCoeus2.php", "processCoeus2", "Thursday", $records, 100);
            }
            # Already in updateAllCOEUS
            // if (in_array('coeus_submission', $forms)) {
            // $manager->addCron("drivers/19_updateNewCoeus.php", "updateCOEUSSubmissions", "Wednesday", $allRecords, 1000);
            // }
        }
        if (!$securityTestMode) {
            $manager->addCron("drivers/13_pullOrcid.php", "pullORCIDs", "Friday", $allRecords, 100);
        }
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
        if (in_array("pre_screening_survey", $forms)) {
            $manager->addCron("drivers/11_vfrs.php", "updateVFRS", "Thursday", $records, 100000);
            if ($pid == 149668) {
                # MSTP
                $manager->addCron("drivers/11_vfrs.php", "updateVFRS", "2022-12-17", $records, 100000);
            }
        }
        if (in_array('patent', $forms) && !$securityTestMode) {
            $manager->addCron("drivers/18_getPatents.php", "getPatents", "Tuesday", $records, 100);
        }
        if (in_array("nsf", $forms)) {
            $manager->addCron("drivers/20_nsf.php", "getNSFGrants", "Monday", $records, 100);
        }
        if (in_array("eric", $forms)) {
            $manager->addCron("drivers/23_getERIC.php", "getERIC", "Friday", $records, 100);
        }
        if (in_array("vera", $forms) && in_array("vera_submission", $forms) && !Application::isLocalhost()) {
            $manager->addCron("drivers/22_getVERA.php", "getVERA", date("Y-m-d"), $allRecords, 100000);
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
    } catch(\Exception $e) {
        Application::log("ERROR in runMainCrons: ".$e->getMessage(), $pid);
    }
}

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
        runMainCrons($manager, $token, $server);
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
        $pid = Application::getPID($token);
        Application::log("loadInitialCrons");
        $metadata = Download::metadata($token, $server);
        $forms = DataDictionaryManagement::getFormsFromMetadata($metadata);
        Application::log("Forms: ".json_encode($forms));
		$records = Download::recordIds($token, $server);
        $securityTestMode = Application::getSetting("security_test_mode", $pid);

        // if (in_array("pre_screening_survey", $forms)) {
            // $manager->addCron("drivers/11_vfrs.php", "updateVFRS", $date, $records, 100);
		// }
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
        if (in_array("ldapds", $forms)) {
            $manager->addCron("drivers/17_getLDAP.php", "getLDAPs", $date, $records, 500);
        }
        if (in_array("patent", $forms) && !$securityTestMode) {
            $manager->addCron("drivers/18_getPatents.php", "getPatents", $date, $records, 100);
        }
        if (in_array("nsf", $forms)) {
            $manager->addCron("drivers/20_nsf.php", "getNSFGrants", $date, $records, 100);
        }
        if (in_array("eric", $forms)) {
            $manager->addCron("drivers/23_getERIC.php", "getERIC", $date, $records, 100);
        }
        if (in_array("ies_grant", $forms)) {
            $manager->addCron("drivers/24_getIES.php", "getIES", $date, $records, 10000);
        }

        if (in_array("nih_reporter", $forms)) {
            $manager->addCron("drivers/2s_updateRePORTER.php", "updateNIHRePORTER", $date, $records, 100);
        } else if (in_array("exporter", $forms)) {
            $manager->addCron("drivers/2m_updateExPORTER.php", "updateExPORTER", $date, $records, 500);
        }
        if (in_array("citation", $forms)) {
            $manager->addCron("publications/getAllPubs_func.php", "getPubs", $date, $records, 10);
        }
        if (!$securityTestMode) {
            $manager->addCron("drivers/13_pullOrcid.php", "pullORCIDs", $date, $records, 100);
        }
        if (!Application::isLocalhost() && Application::isVanderbilt()) {
            $manager->addCron("drivers/grantRepositoryFetch.php", "checkGrantRepository", $date, $records, 500);
            $manager->addCron("drivers/2p_updateStudioUse.php", "copyStudios", $date, $records, 500);
        }
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

function loadMultiProjectCrons(&$manager, $pids) {
    if (!Application::isLocalhost()) {
        $manager->addMultiCron("drivers/11_vfrs.php", "updateVFRSMulti", "Thursday", $pids);
        $manager->addMultiCron("drivers/19_updateNewCoeus.php", "updateAllCOEUSMulti", "Wednesday", $pids);
        $manager->addMultiCron("drivers/22_getVERA.php", "getVERAMulti", "Monday", $pids);
    }
}