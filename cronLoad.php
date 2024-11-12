<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Cohorts;
use Vanderbilt\CareerDevLibrary\CelebrationsEmail;
use \Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\FeatureSwitches;

require_once(dirname(__FILE__)."/classes/Autoload.php");

define("VANDERBILT_NEWMAN_PROJECT", 66635);

function loadMainCronsHelper(&$manager, $token, $server) {
    $pid = CareerDev::getPid($token);
    Application::log("loadMainCronsHelper", $pid);

    try {
        $forms = Download::metadataFormsByPid($pid);
        $metadataFields = Download::metadataFieldsByPid($pid);
        $switches = new FeatureSwitches($token, $server, $pid);
        if (in_array("identifier_stop_collection", $metadataFields)) {
            $allRecords = Download::recordsWithDownloadActive($token, $server);
        } else {
            $allRecords = Download::recordIdsByPid($pid);
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

        if (in_array('patent', $forms) && !$securityTestMode) {
            $manager->addCron("drivers/18_getPatents.php", "getPatents", "Thursday", $records, 100);
        }
        if (in_array("nsf", $forms)) {
            $manager->addCron("drivers/20_nsf.php", "getNSFGrants", "Monday", $records, 100);
        }
        $manager->addCron("drivers/12_reportStats.php", "reportStats", "Friday", $allRecords, 100000);

        # limited group because bibliometric updates take a lot of time due to rate limiters
        $bibliometricRecordsToUpdate = getRecordsToUpdateBibliometrics($pid, date("d"), date("t"));
        $bibliometricsSwitch = $switches->getValue("Update Bibliometrics Monthly");
        if (!empty($bibliometricRecordsToUpdate) && ($bibliometricsSwitch == "On")) {
            $manager->addCron("publications/updateBibliometrics.php", "updateBibliometrics", date("Y-m-d"), $bibliometricRecordsToUpdate);
        }
    } catch(\Exception $e) {
        Application::log("ERROR in runMainCrons: ".$e->getMessage(), $pid);
    }
}

function loadLocalCrons(&$manager, $token, $server) {
    $pid = CareerDev::getPid($token);
    Application::log("loadLocalCrons", $pid);

    try {
        $forms = Download::metadataFormsByPid($pid);
        $metadataFields = Download::metadataFieldsByPid($pid);
        if (in_array("identifier_stop_collection", $metadataFields)) {
            $allRecords = Download::recordsWithDownloadActive($token, $server);
        } else {
            $allRecords = Download::recordIdsByPid($pid);
        }
        $switches = new FeatureSwitches($token, $server, $pid);
        $records = $switches->downloadRecordIdsToBeProcessed($allRecords);

        if (Application::isVanderbilt()) {
            if (in_array('ldapds', $forms)) {
                $manager->addCron("drivers/17_getLDAP.php", "getLDAPs", "Monday", $allRecords, 10000);
            }
            $manager->addCron("drivers/grantRepositoryFetch.php", "checkGrantRepository", "Monday", $allRecords, 500);
            $manager->addCron("drivers/2p_updateStudioUse.php", "copyStudios", "Friday", $allRecords, 500);
            if (
                in_array('coeus', $forms)
                && !Application::isServer("redcaptest.vumc.org")
                && !Application::isLocalhost()
            ) {
                $manager->addCron("drivers/19_updateNewCoeus.php", "sendUseridsToCOEUS", "Friday", $allRecords, 500);
            }
            if ($pid == VANDERBILT_NEWMAN_PROJECT) {
                $manager->addCron("drivers/getUids.php", "refreshMentorUids", "Wednesday", $allRecords, 10000);
                $manager->addCron("drivers/getUids.php", "refreshUids", "Friday", $allRecords, 10000);
                $manager->addCron("dashboard/saveStats.php", "saveEFSStats", "Sunday", $allRecords, 10000);
                $manager->addCron("drivers/updateStudioCSV.php", "updateStudioCSV", date("Y-m-28"), $allRecords, 10000);
                foreach (["02", "16"] as $day) {
                    $manager->addCron("drivers/21_updateEdgeOutcomes.php", "updateEdgeOutcomesInEdge", date("Y-m-$day"), $allRecords, 10000);
                }
                foreach (["01", "05", "09"] as $month) {
                    $manager->addCron("drivers/21_updateEdgeOutcomes.php", "sendEdgeReminderEmail", date("Y-$month-21"), $allRecords, 10000);
                }
                $months = ["02" => "06", "06" => "10", "10" => "02"];
                foreach ($months as $triggerMonth => $workshopMonth) {
                    $manager->addCron("drivers/sendGPWEmail.php", "sendGPWEmail", date("Y-$triggerMonth-16"), $allRecords, 10000, $workshopMonth);
                }
                $manager->addCron("drivers/efsWebsite.php", "makeEFSWebsiteStatsForFrontPage", "Monday", $allRecords, 10000);
                $manager->addCron("drivers/updateNewmanFigures.php", "updateNewmanFigures", date("Y-m-20"), $allRecords, 10000);
                $manager->addCron("drivers/refreshAlumniAssociations.php", "refreshAlumniAssociations", "Monday", $allRecords, 10000);
                $manager->addCron("drivers/26_workday.php", "identifyNewFaculty", date("Y-m-01"), $allRecords, 10000);
            }
        }

        if (in_array('citation', $forms)) {
            $manager->addCron("publications/getAllPubs_func.php", "getPubs", "Tuesday", $records, 20);
            if (Application::isVanderbilt() && !Application::getSetting("initializedLexTranslator", $pid)) {
                $manager->addCron("drivers/initializeLexicalTranslator.php", "initialize", date("Y-m-d"), $records, 10);
                Application::saveSetting("initializedLexTranslator", TRUE, $pid);
            }
        }
        if (in_array("eric", $forms)) {
            $manager->addCron("drivers/23_getERIC.php", "getERIC", "Friday", $records, 100);
        }
    } catch (\Exception $e) {
        Application::log("ERROR in loadLocalCrons: ".$e->getMessage(), $pid);
    }
}

# internal resources, either based in Vanderbilt, Flight Tracker, or REDCap
function loadIntenseCronsHelper(&$manager, $token, $server) {
    $pid = CareerDev::getPid($token);
    Application::log("loadIntenseCronsHelper", $pid);

    try {
        $metadataFields = Download::metadataFieldsByPid($pid);
        $switches = new FeatureSwitches($token, $server, $pid);
        if (in_array("identifier_stop_collection", $metadataFields)) {
            $allRecords = Download::recordsWithDownloadActive($token, $server);
        } else {
            $allRecords = Download::recordIdsByPid($pid);
        }
        $records = $switches->downloadRecordIdsToBeProcessed($allRecords);

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

        $celebrations = new CelebrationsEmail($token, $server, $pid, []);
        if ($celebrations->hasEmail("weekly")) {
            $manager->addCron("drivers/25_emailHighlights.php", "sendWeeklyEmailHighlights", "Monday", $allRecords, 100000);
        }
        if ($celebrations->hasEmail("monthly")) {
            $manager->addCron("drivers/25_emailHighlights.php", "sendMonthlyEmailHighlights", date("Y-m-01"), $allRecords, 100000);
        }
        if ($celebrations->hasEmail("quarterly")) {
            $month = (int) date("m");
            if (($month - 1) % 3 === 0) {
                $manager->addCron("drivers/25_emailHighlights.php", "sendQuarterlyEmailHighlights", date("Y-m-01"), $allRecords, 100000);
            }
        }

        # Increasing this number from 15 to 50. These only run in off-hours, so the challenge is grabbing
        # a cron rather than limiting the size of each one that's processing.
        $numRecordsForSummary = 50;
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
        Application::log("ERROR in loadIntenseCronsHelper: ".$e->getMessage(), $pid);
    }
}

# supply day of the week or YYYY-MM-DD
function loadCrons(&$manager, $specialOnly = FALSE, $token = "", $server = "") {
    if (!$token) { global $token; }
    if (!$server) { global $server; }

    if ($token && $server && !$specialOnly) {
        loadMainCronsHelper($manager, $token, $server);
    }
}

# supply day of the week or YYYY-MM-DD
function loadIntenseCrons(&$manager, $specialOnly = FALSE, $token = "", $server = "") {
    if (!$token) { global $token; }
    if (!$server) { global $server; }

    if ($specialOnly) {
        if (
            Application::isVanderbilt()
            && !Application::isLocalhost()
            && !Application::isServer("redcaptest.vumc.org")
        ) {
            $manager->addCron("drivers/19_updateNewCoeus.php", "sendUseridsToCOEUS", date("Y-m-d"));
            $manager->addCron("drivers/19_updateNewCoeus.php", "updateCOEUSGrants", date("Y-m-d"));
            $manager->addCron("drivers/19_updateNewCoeus.php", "updateCOEUSSubmissions", date("Y-m-d"));
        }
    } else if ($token && $server) {
        loadIntenseCronsHelper($manager, $token, $server);
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

        try {
            $forms = Download::metadataFormsByPid($pid);
            $records = Download::recordIdsByPid($pid);
            $securityTestMode = Application::getSetting("security_test_mode", $pid);

            if (Application::isVanderbilt() && !Application::isLocalhost() && in_array("pre_screening_survey", $forms)) {
                $manager->addCron("drivers/11_vfrs.php", "updateVFRS", $date, $records, 100);
            }
            if (in_array("coeus", $forms)) {
                $manager->addCron("drivers/19_updateNewCoeus.php", "updateCOEUSGrants", $date, $records, 500);
            } else if (in_array("coeus2", $forms)) {
                $manager->addCron("drivers/2r_updateCoeus2.php", "processCoeus2", $date, $records, 100);
            }
            if (in_array("coeus_submission", $forms)) {
                $manager->addCron("drivers/19_updateNewCoeus.php", "updateCOEUSSubmissions", $date, $records, 500);
                $manager->addCron("drivers/importHistoricalCOEUS.php", "importHistoricalCOEUS", $date, $records, 500);
            }
            if (
                Application::isVanderbilt()
                && in_array("coeus", $forms)
                && !Application::isServer("redcaptest.vumc.org")
                && !Application::isLocalhost()
            ) {
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
            Application::log("loadInitialCrons loaded", $pid);
        } catch (\Exception $e) {
            Application::log("ERROR in loadInitialCrons: ".$e->getMessage(), $pid);
        }
    } else {
        Application::log("loadInitialCrons without token or server");
    }
}

function getRecordsToUpdateBibliometrics($pid, $dayOfMonth, $daysInMonth) {
    $records = Download::recordIdsByPid($pid);
    $recordsToRun = [];
    $dayOfMonth = (int) $dayOfMonth;
    $daysInMonth = (int) $daysInMonth;
    if ($daysInMonth == 0) {
        # This should never happen.
        return $records;
    }
    foreach ($records as $i => $recordId) {
        if (is_numeric($recordId)) {
            $numericalRecordId = (int) $recordId;
            if (($numericalRecordId - 1) % $daysInMonth == $dayOfMonth - 1) {
                $recordsToRun[] = $recordId;
            }
        } else {
            if ($i % $daysInMonth == $dayOfMonth - 1) {
                $recordsToRun[] = $recordId;
            }
        }
    }
    return $recordsToRun;
}

function loadLongRunningCrons(&$manager, $currToken, $currServer, $currPid) {
    if ($currToken && $currServer && $currPid) {
        try {
            $cohorts = new Cohorts($currToken, $currServer, Application::getModule());
            if ($cohorts->hasReadonlyProjects()) {
                $allRecords = Download::recordIdsByPid($currPid);
                $manager->addCron("drivers/2q_refreshCohortProjects.php", "copyAllCohortProjects", "Monday", $allRecords, 100000);
            }
            if (Application::isVanderbilt() && ($currPid == VANDERBILT_NEWMAN_PROJECT)) {
                $allRecords = Download::recordIdsByPid($currPid);
                $manager->addCron("drivers/2p_updateStudioUse.php", "copyStudios", "Thursday", $allRecords, 10000);
            }
        } catch (\Exception $e) {
            Application::log("ERROR in loadLongRunningCrons: ".$e->getMessage(), $currPid);
        }
    }
}

function loadMultiProjectCrons(&$manager, $pids) {
    try {
        if (Application::isVanderbilt() && !Application::isLocalhost()) {
            $manager->addMultiCron("drivers/11_vfrs.php", "updateVFRSMulti", "Thursday", $pids);
            $manager->addMultiCron("drivers/19_updateNewCoeus.php", "updateAllCOEUSMulti", "Wednesday", $pids);
            $manager->addMultiCron("drivers/22_getVERA.php", "getVERAMulti", "Monday", $pids);
            $manager->addMultiCron("drivers/updateAllMetadata.php", "updateMetadataMulti", "Monday", $pids);
            if (Application::isServer("redcap.vanderbilt.edu") || Application::isServer("redcap.vumc.org")) {
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
    } catch(\Exception $e) {
        foreach ($pids as $currPid) {
            Application::log("ERROR in loadMultiProjectCrons: ".$e->getMessage(), $currPid);
        }
    }
}

function loadInternalSharingCrons(&$manager, $pids) {
    $preprocessingPids = [];
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
    } else if (Application::isServer(FlightTrackerExternalModule::VANDERBILT_TEST_SERVER)) {
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
