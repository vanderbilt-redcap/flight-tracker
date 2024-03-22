<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Cohorts;
use Vanderbilt\CareerDevLibrary\CelebrationsEmail;
use \Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\FeatureSwitches;

require_once(dirname(__FILE__)."/classes/Autoload.php");

function runMainCrons(&$manager, $token, $server) {
    $pid = CareerDev::getPid($token);
    Application::log("loadCrons", $pid);

    try {
        $forms = Download::metadataForms($token, $server);
        $metadataFields = Download::metadataFields($token, $server);
        $switches = new FeatureSwitches($token, $server, $pid);
        if (in_array("identifier_stop_collection", $metadataFields)) {
            $allRecords = Download::recordsWithDownloadActive($token, $server);
        } else {
            $allRecords = Download::recordIds($token, $server);
        }
        $records = $switches->downloadRecordIdsToBeProcessed($allRecords);
        $securityTestMode = Application::getSetting("security_test_mode", $pid);

        if (in_array('nih_reporter', $forms)) {
            $manager->addCron("drivers/2s_updateRePORTER.php", "updateNIHRePORTER", "Monday", $records, 100);
        }
        if (in_array("ies_grant", $forms)) {
            $manager->addCron("drivers/24_getIES.php", "getIES", "Thursday", $allRecords, 10000);
        }
        if (!$securityTestMode) {
            $manager->addCron("drivers/13_pullOrcid.php", "pullORCIDs", "Thursday", $allRecords, 100);
        }
        if (in_array('citation', $forms)) {
            $manager->addCron("publications/getAllPubs_func.php", "getPubs", "Tuesday", $records, 20);
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
            $bibliometricsSwitch = $switches->getValue("Update Bibliometrics Monthly");
            if (!empty($bibliometricRecordsToUpdate) && ($bibliometricsSwitch == "On")) {
                $manager->addCron("publications/updateBibliometrics.php", "updateBibliometrics", date("Y-m-d"), $bibliometricRecordsToUpdate);
            }
        }

        if (in_array('patent', $forms) && !$securityTestMode) {
            $manager->addCron("drivers/18_getPatents.php", "getPatents", "Thursday", $records, 100);
        }
        if (in_array("nsf", $forms)) {
            $manager->addCron("drivers/20_nsf.php", "getNSFGrants", "Monday", $records, 100);
        }
        if (in_array("eric", $forms)) {
            $manager->addCron("drivers/23_getERIC.php", "getERIC", "Friday", $records, 100);
        }
        if (Application::isLocalhost()) {
            $manager->addCron("publications/getAllPubs_func.php", "getPubs", "2024-01-10", $records, 20);
            $manager->addCron("publications/getAllPubs_func.php", "getPubs", "2024-01-11", $records, 20);
        }

    } catch(\Exception $e) {
        Application::log("ERROR in runMainCrons: ".$e->getMessage(), $pid);
    }
}

# internal resources, either based in Vanderbilt, Flight Tracker, or REDCap
function runIntenseCrons(&$manager, $token, $server) {
    $pid = CareerDev::getPid($token);
    Application::log("loadIntenseCrons", $pid);

    try {
        $forms = Download::metadataForms($token, $server);
        $metadataFields = Download::metadataFields($token, $server);
        $switches = new FeatureSwitches($token, $server, $pid);
        if (in_array("identifier_stop_collection", $metadataFields)) {
            $allRecords = Download::recordsWithDownloadActive($token, $server);
        } else {
            $allRecords = Download::recordIds($token, $server);
        }
        $records = $switches->downloadRecordIdsToBeProcessed($allRecords);
        $securityTestMode = Application::getSetting("security_test_mode", $pid);

        $manager->addCron("drivers/updateInstitution.php", "updateInstitution", "Saturday", $allRecords, 10000);

        if (!Application::getSetting("dedupResources122023", $pid)) {
            $manager->addCron("drivers/preprocess.php", "dedupResources", date("Y-m-d"));
            Application::saveSetting("dedupResources122023", TRUE, $pid);
        }

        if (
            in_array("promotion_workforce_sector", $metadataFields)
            && in_array("promotion_activity", $metadataFields)
            && Application::getSetting("updated_job_categories", $pid)
        ) {
            $manager->addCron("drivers/updateJobCategories.php", "updateJobCategories", date("Y-m-d"));
        }

        if (
            Application::getSystemSetting("table1Pid")
            && (Application::getSystemSetting("lastTable1Email") != date("Y-m-d"))
        ) {
            # A few months before due dates, but after the last due date.
            # Due dates are January, May, and October.
            if (
                in_array(date("m"), ["11", "02", "06"])
                && (date("d") == "15")
            ) {
                Application::saveSystemSetting("lastTable1Email", date("Y-m-d"));
                $manager->addCron("drivers/sendTable1Emails.php", "sendTable1PredocEmails", date("Y-m-d"));
            }
            # Once a year: the Ides of March
            if (date("m-d") == "03-15") {
                Application::saveSystemSetting("lastTable1Email", date("Y-m-d"));
                $manager->addCron("drivers/sendTable1Emails.php", "sendTable1PostdocEmails", date("Y-m-d"));
            }
        }

        if (in_array('ldapds', $forms)) {
            $manager->addCron("drivers/17_getLDAP.php", "getLDAPs", "Monday", $allRecords, 10000);
        }
        if (!Application::isLocalhost() && Application::isVanderbilt() && !Application::isServer("redcaptest.vanderbilt.edu")) {
            # only on redcap.vanderbilt.edu
            $manager->addCron("drivers/grantRepositoryFetch.php", "checkGrantRepository", "Monday", $allRecords, 500);
            $manager->addCron("drivers/2p_updateStudioUse.php", "copyStudios", "Friday", $allRecords, 500);
            $manager->addCron("drivers/importHistoricalCOEUS.php", "importHistoricalCOEUS", "2024-03-25", $allRecords, 10000);
            if (in_array('coeus', $forms)) {
                # Put in Multi crons
                // $manager->addCron("drivers/19_updateNewCoeus.php", "updateAllCOEUS", "Wednesday", $allRecords, 1000);
                $manager->addCron("drivers/19_updateNewCoeus.php", "sendUseridsToCOEUS", "Friday", $allRecords, 500);
            } else if (in_array('coeus2', $forms)) {
                $manager->addCron("drivers/2r_updateCoeus2.php", "processCoeus2", "Thursday", $records, 100);
            }
        }
        $manager->addCron("drivers/12_reportStats.php", "reportStats", "Friday", $allRecords, 100000);

        $celebrations = new CelebrationsEmail($token, $server, $pid, []);
        if ($celebrations->hasEmail("weekly")) {
            $manager->addCron("drivers/25_emailHighlights.php", "sendWeeklyEmailHighlights", "Monday", $allRecords, 100000);
        }
        if ($celebrations->hasEmail("monthly")) {
            $manager->addCron("drivers/25_emailHighlights.php", "sendMonthlyEmailHighlights", date("Y-m-01"), $allRecords, 100000);
        }

        $numRecordsForSummary = 15;
        if (Application::isVanderbilt()) {
            $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", "Saturday", $allRecords, $numRecordsForSummary, TRUE);
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
        Application::log("ERROR in runIntenseCrons: ".$e->getMessage(), $pid);
    }
}

# supply day of the week or YYYY-MM-DD
function loadCrons(&$manager, $specialOnly = FALSE, $token = "", $server = "") {
    if (!$token) { global $token; }
    if (!$server) { global $server; }

    if ($specialOnly) {
        // $manager->addCron("drivers/2m_updateExPORTER.php", "updateExPORTER", date("Y-m-d"));
        // $manager->addCron("drivers/2n_updateReporters.php", "updateReporter", date("Y-m-d"));
        // $manager->addCron("publications/getAllPubs_func.php", "getPubs", date("Y-m-d"));
        // $manager->addCron("drivers/6d_makeSummary.php", "makeSummary", date("Y-m-d"));
    } else if ($token && $server) {
        runMainCrons($manager, $token, $server);
    }
}

# supply day of the week or YYYY-MM-DD
function loadIntenseCrons(&$manager, $specialOnly = FALSE, $token = "", $server = "") {
    if (!$token) { global $token; }
    if (!$server) { global $server; }

    if ($specialOnly) {
        if (!Application::isLocalhost()) {
            $manager->addCron("drivers/19_updateNewCoeus.php", "sendUseridsToCOEUS", date("Y-m-d"));
            $manager->addCron("drivers/19_updateNewCoeus.php", "updateCOEUSGrants", date("Y-m-d"));
            $manager->addCron("drivers/19_updateNewCoeus.php", "updateCOEUSSubmissions", date("Y-m-d"));
        }
    } else if ($token && $server) {
        runIntenseCrons($manager, $token, $server);
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
        Application::log("loadInitialCrons", $pid);
        $forms = Download::metadataForms($token, $server);
		$records = Download::recordIds($token, $server);
        $securityTestMode = Application::getSetting("security_test_mode", $pid);

        if (Application::isVanderbilt() && !Application::isLocalhost() && in_array("pre_screening_survey", $forms)) {
            $manager->addCron("drivers/11_vfrs.php", "updateVFRS", $date, $records, 100);
		}
        $manager->addCron("drivers/updateInstitution.php", "updateInstitution", "Tuesday");
        if (in_array("coeus", $forms)) {
            $manager->addCron("drivers/19_updateNewCoeus.php", "updateCOEUSGrants", $date, $records, 500);
        } else if (in_array("coeus2", $forms)) {
            $manager->addCron("drivers/2r_updateCoeus2.php", "processCoeus2", $date, $records, 100);
        }
        if (in_array("coeus_submission", $forms)) {
            $manager->addCron("drivers/19_updateNewCoeus.php", "updateCOEUSSubmissions", $date, $records, 500);
            $manager->addCron("drivers/importHistoricalCOEUS.php", "importHistoricalCOEUS", $date, $records, 500);
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
        }
        if (!$securityTestMode) {
            $manager->addCron("drivers/13_pullOrcid.php", "pullORCIDs", $date, $records, 100);
        }
        if (in_array("citation", $forms)) {
            $manager->addCron("publications/getAllPubs_func.php", "getPubs", $date, $records, 20);
        }
        if (!Application::isLocalhost() && Application::isVanderbilt()) {
            $manager->addCron("drivers/grantRepositoryFetch.php", "checkGrantRepository", $date, $records, 500);
            $manager->addCron("drivers/2p_updateStudioUse.php", "copyStudios", $date, $records, 500);
        }
        if (Application::isVanderbilt() && !Application::isLocalhost() && in_array("workday", $forms)) {
            $manager->addCron("drivers/26_workday.php", "getWorkday", $date, $records, 10000);
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

function loadLongRunningCrons(&$manager, $currToken, $currServer, $currPid) {
    if ($currToken && $currServer && $currPid) {
        $cohorts = new Cohorts($currToken, $currServer, Application::getModule());
        if ($cohorts->hasReadonlyProjects()) {
            $allRecords = Download::recordIdsByPid($currPid);
            $manager->addCron("drivers/2q_refreshCohortProjects.php", "copyAllCohortProjects", "Monday", $allRecords, 100000);
        }
    }
}

function loadMultiProjectCrons(&$manager, $pids)
{
    if (Application::isVanderbilt() && !Application::isLocalhost()) {
        $manager->addMultiCron("drivers/11_vfrs.php", "updateVFRSMulti", "Thursday", $pids);
        $manager->addMultiCron("drivers/19_updateNewCoeus.php", "updateAllCOEUSMulti", "Wednesday", $pids);
        $manager->addMultiCron("drivers/22_getVERA.php", "getVERAMulti", "Monday", $pids);
        $manager->addMultiCron("drivers/updateAllMetadata.php", "updateMetadataMulti", "Monday", $pids);
        if (Application::isServer("redcap.vanderbilt.edu")) {
            $manager->addMultiCron("drivers/26_workday.php", "getAllWorkday", "Friday", $pids);
        }
    }
    $manager->addMultiCron("drivers/preprocess.php", "downloadPortalPersonalData", "Monday", $pids);
    $manager->addMultiCron("drivers/preprocess.php", "downloadPortalPersonalData", "Thursday", $pids);

    # setting at batches of 10 took about one hour per batch at Vanderbilt
    # Therefore, setting at batches of 7 projects per batch is a little more conservative
    $manager->addMultiCronInBatches("drivers/preprocess.php", "preprocessPortal", "Monday", $pids, 7);
    $manager->addMultiCronInBatches("drivers/preprocess.php", "preprocessPortal", "Thursday", $pids, 7);
    loadInternalSharingCrons($manager, $pids);
}

function loadInternalSharingCrons(&$manager, $pids) {
    $preprocessingPids = [];
    if (Application::isVanderbilt() && Application::isServer("redcap.vanderbilt.edu")) {
        $preprocessingPids[] = NEWMAN_SOCIETY_PROJECT;
    } else if (Application::isVanderbilt() && Application::isLocalhost()) {
        $preprocessingPids[] = LOCALHOST_TEST_PROJECT;
    }
    foreach ($pids as $pid) {
        if (
            Application::getSetting("token", $pid)
            && Application::getSetting("server", $pid)
            && !Application::getSetting("turn_off", $pid)
            && !in_array($pid, $preprocessingPids)
        ) {
            $preprocessingPids[] = $pid;
        }
    }
    if (empty($preprocessingPids)) {
        return;
    }

    if (Application::isVanderbilt()) {
        $manager->addMultiCron("drivers/updateVanderbiltResources.php", "updateResourcesMulti", "2024-03-25", $preprocessingPids);
    }

    if (Application::isLocalhost()) {
        $manager->addMultiCron("drivers/preprocess.php", "preprocessFindSharingMatches", date("Y-m-d"), $preprocessingPids);
        $manager->addMultiCron("drivers/preprocess.php", "preprocessMissingEmails", date("Y-m-d"), $preprocessingPids);
    } else if (Application::isServer("redcaptest.vanderbilt.edu")) {
        $manager->addMultiCron("drivers/preprocess.php", "preprocessFindSharingMatches", date("Y-m-d"), $preprocessingPids);
        $manager->addMultiCron("drivers/preprocess.php", "preprocessSharingMatches", date("Y-m-d"), $preprocessingPids);
    } else {
        # this is the most time-consuming process in Flight Tracker
        # time required increases by n^2, where n is the number of scholars
        # Have to be careful that it doesn't exceed REDCap limits --> separate into batches
        $preprocessDayOfWeek = "Friday";   # lightest day
        $module = Application::getModule();
        $destPids = $module->getProjectsToRunTonight($preprocessingPids);
        if (!empty($destPids) && (date("l") !== $preprocessDayOfWeek)) {
            # just run for newer projects requested to "run tonight"
            $manager->addMultiCron("drivers/preprocess.php", "preprocessFindSharingMatches", date("Y-m-d"), $preprocessingPids, $destPids);
        } else {
            $manager->addMultiCron("drivers/preprocess.php", "preprocessFindSharingMatches", $preprocessDayOfWeek, $preprocessingPids);
        }
        $manager->addMultiCron("drivers/preprocess.php", "preprocessMissingEmails", $preprocessDayOfWeek, $preprocessingPids);
    }
}
