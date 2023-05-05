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

        if (in_array('reporter', $forms)) {
            // $manager->addCron("drivers/2s_updateRePORTER.php", "updateFederalRePORTER", "Tuesday", $records, 40);
        }
        if (in_array('nih_reporter', $forms)) {
            $manager->addCron("drivers/2s_updateRePORTER.php", "updateNIHRePORTER", "Monday", $records, 30);
        } else if (in_array('exporter', $forms)) {
            $manager->addCron("drivers/2m_updateExPORTER.php", "updateExPORTER", "Monday", $records, 20);
        }
        if (in_array('ldapds', $forms)) {
            $manager->addCron("drivers/17_getLDAP.php", "getLDAPs", "Monday", $allRecords, 10000);
        }
        if (in_array("ies_grant", $forms)) {
            $manager->addCron("drivers/24_getIES.php", "getIES", "Friday", $allRecords, 10000);
        }
        if (!Application::isLocalhost() && Application::isVanderbilt()) {
            $manager->addCron("drivers/grantRepositoryFetch.php", "checkGrantRepository", "Monday", $allRecords, 500);
            $manager->addCron("drivers/2p_updateStudioUse.php", "copyStudios", "Friday", $allRecords, 500);
            if (in_array('coeus', $forms)) {
                # Put in Multi crons
                // $manager->addCron("drivers/19_updateNewCoeus.php", "updateAllCOEUS", "Wednesday", $allRecords, 1000);
                $manager->addCron("drivers/19_updateNewCoeus.php", "sendUseridsToCOEUS", "Friday", $allRecords, 500);
            } else if (in_array('coeus2', $forms)) {
                $manager->addCron("drivers/2r_updateCoeus2.php", "processCoeus2", "Thursday", $records, 100);
            }
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
        # put in multi-crons
        // if (in_array("pre_screening_survey", $forms)) {
            // $manager->addCron("drivers/11_vfrs.php", "updateVFRS", "Thursday", $allRecords, 100000);
        // }
        if (in_array('patent', $forms) && !$securityTestMode) {
            $manager->addCron("drivers/18_getPatents.php", "getPatents", "Tuesday", $records, 100);
        }
        if (in_array("nsf", $forms)) {
            $manager->addCron("drivers/20_nsf.php", "getNSFGrants", "Monday", $records, 100);
        }
        if (in_array("eric", $forms)) {
            $manager->addCron("drivers/23_getERIC.php", "getERIC", "Friday", $records, 100);
        }
        # now in multi crons
        // if (in_array("vera", $forms) && in_array("vera_submission", $forms) && !Application::isLocalhost()) {
            // $manager->addCron("drivers/22_getVERA.php", "getVERA", "Monday", $allRecords, 100000);
        // }

        $cohorts = new Cohorts($token, $server, Application::getModule());
        if ($cohorts->hasReadonlyProjects()) {
            $manager->addCron("drivers/2q_refreshCohortProjects.php", "copyAllCohortProjects", "Monday", $allRecords, 100000);
        }

        if (Application::getSetting("email_highlights_to", $pid)) {
            $frequency = Application::getSetting("highlights_frequency", $pid);
            if ($frequency == "weekly") {
                $manager->addCron("drivers/25_emailHighlights.php", "sendEmailHighlights", "Monday", $allRecords, 100000);
            } else if ($frequency == "monthly") {
                $manager->addCron("drivers/25_emailHighlights.php", "sendEmailHighlights", date("Y-m-01"), $allRecords, 100000);
            } else {
                Application::log("25_: highlights_frequency: $frequency", $pid);
            }
        } else {
            Application::log("25_: No email_highlights_to", $pid);
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