<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\CronManager;
use Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use Vanderbilt\CareerDevLibrary\EmailManager;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\NameMatcher;
use Vanderbilt\CareerDevLibrary\Upload;
use Vanderbilt\CareerDevLibrary\MMAHelper;
use Vanderbilt\CareerDevLibrary\Sanitizer;
use Vanderbilt\CareerDevLibrary\Portal;
use Vanderbilt\CareerDevLibrary\Links;
use Vanderbilt\CareerDevLibrary\CelebrationsEmail;
use Vanderbilt\CareerDevLibrary\MSTP;

require_once(dirname(__FILE__)."/classes/Autoload.php");
require_once(dirname(__FILE__)."/cronLoad.php");
require_once(APP_PATH_DOCROOT."Classes/System.php");

class FlightTrackerExternalModule extends AbstractExternalModule
{
    const RECENT_YEARS = CelebrationsEmail::RECENT_YEARS;
    const SHARING_SETTING = "matches";
    const COMPLETE_INDICES = [2];
    const MATCHES_BATCH_SIZE = 200;
    const LONG_RUNNING_BATCH_SUFFIX = "long";
    const INTENSE_BATCH_SUFFIX = "intense";
    const TEST_CRON = "test";
    const WEEKDAYS = [1, 2, 3, 4, 5];
    const OLD_FIRST_NAMES = "old_first_names";
    const OLD_LAST_NAMES = "old_last_names";
    const FIRST_NAMES = "first_names";
    const LAST_NAMES = "last_names";
    const VANDERBILT_TEST_SERVER = "redcaptest.vumc.org";

	function getPrefix() {
        return Application::getPrefix();
	}

	function getName() {
		return $this->name;
	}

	function enqueueTonight() {
		$this->setProjectSetting("run_tonight", TRUE);
	}

    # Vanderbilt's REDCap traffic tends to speed up around 8am and decrease after 6pm
    # give one hour for crons to complete before traffic speedup
    # these should already be partitioned to run more quickly
    function intense_batch() {
        $currTs = time();
        # purposefully switch windows - start of restricted time = end of window
        $endWindow = strtotime(CronManager::getRestrictedTime("intense", "start"));
        $startWindow = strtotime(CronManager::getRestrictedTime("intense", "end"));
        if (
            !in_array(date("N"), self::WEEKDAYS)
            || ($currTs < $endWindow)
            || ($currTs > $startWindow)
            || Application::isLocalhost()
        ) {
            $this->runBatch(self::INTENSE_BATCH_SUFFIX);
        }
    }

    # Vanderbilt's REDCap traffic tends to speed up around 8am and decrease after 6pm
    # give two hours for long-running crons to complete before traffic speedup
    function long_batch() {
        $currTs = time();
        # purposefully switch windows - start of restricted time = end of window
        $endWindow = strtotime(CronManager::getRestrictedTime("long", "start"));
        $startWindow = strtotime(CronManager::getRestrictedTime("long", "end"));
        if (
            !in_array(date("N"), self::WEEKDAYS)
            || ($currTs < $endWindow)
            || ($currTs > $startWindow)
            || Application::isLocalhost()
        ) {
            $this->runBatch(self::LONG_RUNNING_BATCH_SUFFIX);
        }
    }

    function main_batch() {
        $this->runBatch("");
    }

    private function runBatch($suffix) {
        Application::increaseProcessingMax(8);
        $this->setupApplication();
        $activePids = $this->getPids();

        foreach ($activePids as $pid) {
            # note return at end of successful run because only need to run once
            $token = $this->getProjectSetting("token", $pid);
            $server = $this->getProjectSetting("server", $pid);
            $adminEmail = $this->getProjectSetting("admin_email", $pid);
            if ($token && $server) {
                try {
                    $this->cronManager = new CronManager($token, $server, $pid, $this, $suffix);
                    $this->cronManager->sendEmails($activePids, $this);
                    $this->cronManager->runBatchJobs();
                } catch (\Exception $e) {
                    # should only happen in rarest of circumstances
                    if (preg_match("/'batchCronJobs' because the value is larger than the \d+ byte limit/", $e->getMessage())) {
                        Application::saveSetting("batchCronJobs", [], $pid);
                    }
                    $mssg = $e->getMessage()."<br><br>".$e->getTraceAsString();
                    if (Application::isVanderbilt()) {
                        \REDCap::email($adminEmail, "noreply.flighttracker@vumc.org", CronManager::EXCEPTION_EMAIL_SUBJECT, $mssg);
                    } else {
                        CronManager::enqueueExceptionsMessageInDigest($adminEmail, $mssg);
                    }
                }
                return;
            } else {
                Application::log("No token or server", $pid);
            }
        }
    }

    function getPids() {
	    return $this->framework->getProjectsWithModuleEnabled();
    }

	function emails() {
	    $this->setupApplication();
        $activePids = $this->getPids();
        $oneHour = 3600;
        // CareerDev::log($this->getName()." sending emails for pids ".json_encode($pids));
		foreach ($activePids as $pid) {
			if (REDCapManagement::isActiveProject($pid)) {
				$token = $this->getProjectSetting("token", $pid);
				$server = $this->getProjectSetting("server", $pid);
				if ($token && $server) {
                    try {
                        $tokenName = $this->getProjectSetting("tokenName", $pid);
                        $adminEmail = $this->getProjectSetting("admin_email", $pid);
                        $cronStatus = $this->getProjectSetting("send_cron_status", $pid);
                        if ($cronStatus && (time() <= $cronStatus + $oneHour)) {
                            $this->cronManager = new CronManager($token, $server, $pid, $this, self::TEST_CRON);
                            loadTestingCrons($this->cronManager);
                            $this->cronManager->run($adminEmail, $tokenName);
                        }
                        $mgr = new EmailManager($token, $server, $pid, $this);
                        $mgr->sendRelevantEmails();
                        if (
                            Application::isMSTP($pid)
                            && MSTP::isTimeToSend($pid)
                        ) {
                            MSTP::sendReminders($pid);
                        }
                    } catch (\Exception $e) {
                        # should only happen in rarest of circumstances
                        if (preg_match("/'batchCronJobs' because the value is larger than the \d+ byte limit/", $e->getMessage())) {
                            Application::saveSetting("batchCronJobs", [], $pid);
                        }
                        $mssg = $e->getMessage()."<br/><br/>".$e->getTraceAsString();
                        \REDCap::email($adminEmail, "noreply.flighttracker@vumc.org", "Flight Tracker Email Exception", $mssg);
                    }
                }
			}
		}
	}

	# returns a boolean; modifies $normativeRow
	private static function copyDataFromRowToNormative($sourceRow, $completeValue, $prefix, $metadataFields, $sourceChoices, $destChoices, &$normativeRow, $instrument) {
	    $hasChanged = self::copyDataFromRowToRow($sourceRow, $prefix, $metadataFields, $sourceChoices, $destChoices, "", $normativeRow);
	    if ($hasChanged) {
	        $normativeRow[$instrument."_complete"] = $completeValue;
        }
	    return $hasChanged;
    }

    # returns a boolean; modifies $destRow
    private static function copyDataFromRowToRow($sourceRow, $prefix, $metadataFields, $sourceChoices, $destChoices, $instrument, &$destRow) {
	    $hasChanged = FALSE;
        if ($sourceRow["redcap_repeat_instrument"] == $instrument) {
            foreach ($sourceRow as $sourceField => $sourceValue) {
                if (
                    preg_match("/^$prefix/", $sourceField)
                    && in_array($sourceField, $metadataFields)
                    && !preg_match("/honor_imported$/", $sourceField)
                ) {
                    if (
                        isset($sourceChoices[$sourceField])
                        && isset($sourceChoices[$sourceField][$sourceValue])
                        && isset($destChoices[$sourceField])
                    ) {
                        $destValue = "";
                        foreach ($destChoices[$sourceField] as $destIdx => $destLabel) {
                            if (strtolower($destLabel) == strtolower($sourceChoices[$sourceField][$sourceValue])) {
                                $destValue = $destIdx;
                            }
                        }
                        if ($destValue !== "") {
                            $destRow[$sourceField] = $destValue;
                            $hasChanged = TRUE;
                        }
                    } else {
                        $destRow[$sourceField] = $sourceValue;
                        $hasChanged = TRUE;
                    }
                }
            }
        }
	    return $hasChanged;
    }

    # returns a row if data can be copied; otherwise returns FALSE
    private static function copyDataFromRowToNewRow($sourceRow, $completeValue, $prefix, $metadataFields, $sourceChoices, $destChoices, $recordId, $instrument, $newInstance) {
	    $newRow = [
	        "record_id" => $recordId,
            "redcap_repeat_instrument" => $instrument,
            "redcap_repeat_instance" => $newInstance,
            $instrument."_complete" => $completeValue,
        ];
	    $hasChanged = self::copyDataFromRowToRow($sourceRow, $prefix, $metadataFields, $sourceChoices, $destChoices, $instrument, $newRow);
	    if ($hasChanged) {
	        return $newRow;
        }
	    return $hasChanged;
    }

    private static function allFieldsMatch($fields, $row1, $row2, $choices1, $choices2) {
        $allMatch = TRUE;
        foreach ($fields as $testField) {
            if ($choices1[$testField] && $choices2[$testField]) {
                $value1 = $choices1[$testField][$row1[$testField]];
                $value2 = $choices2[$testField][$row2[$testField]];
            } else {
                $value1 = $row1[$testField];
                $value2 = $row2[$testField];
            }
            if ($value1 != $value2) {
                $allMatch = FALSE;
            }
        }
        return $allMatch;
    }

    private static function fieldBlank($field, $row) {
	    if (!isset($row[$field])) {
	        return TRUE;
        }
	    if ($row[$field] === "") {
	        return TRUE;
        }
	    return FALSE;
    }

	public function cleanupLogs($pid) {
        $daysPrior = 10;
        $this->cleanupExtModLogs($pid, $daysPrior);
    }

    public function cleanupExtModLogs($pid, $daysPrior) {
        Application::log("Cleaning up logs...", $pid);
        $ts = time() - $daysPrior * 24 * 3600;
        $thresholdDate = date("Y-m-d", $ts);
        $externalModuleId = CareerDev::getModuleId();
        Application::log("Removing logs prior to $thresholdDate", $pid);
        $numIterations = 0;
        $maxRowsToDelete = 50000000;
        $numRowsToDelete = 1000;
        $maxIterations = $maxRowsToDelete / $numRowsToDelete;
        do {
            $moreToDelete = $this->deleteLogs($externalModuleId, $thresholdDate, $pid, $numRowsToDelete);
            $numIterations++;
            usleep(100000);
        } while ($moreToDelete && ($numIterations < $maxIterations));
        Application::log("Done removing logs in $numIterations iterations", $pid);
    }

    public function deleteLogs($externalModuleId, $thresholdDate, $pid, $numToDelete) {
        $params = [$externalModuleId, $thresholdDate, $pid];
        $fromAndWhereClause = "FROM redcap_external_modules_log WHERE external_module_id = ? AND timestamp <= ? AND project_id = ?";
        $deleteSql = "DELETE $fromAndWhereClause LIMIT $numToDelete";
        $selectSql = "SELECT log_id $fromAndWhereClause LIMIT 1";
        $this->query($deleteSql, $params);
        $result = $this->query($selectSql, $params);
        return $result->fetch_assoc();
    }

    private static function isValidToCopy($fields, $sourceRow, $destRow, $sourceChoices, $destChoices) {
        if ((count($fields) == 1) && self::fieldBlank($fields[0], $sourceRow)) {
            # one blank field => not valid enough to copy
            // Application::log("isValidToCopy Rejecting because one field blank: ".json_encode($fields));
            return FALSE;
        } else if (self::allFieldsMatch($fields, $sourceRow, $destRow, $sourceChoices, $destChoices)) {
            # already copied => skip
            // Application::log("isValidToCopy Rejecting because all fields match");
            return FALSE;
        }
        // Application::log("isValidToCopy returning TRUE");
        return TRUE;
    }

    private static function repeatingAlreadyUploadedToPid($fields, $sourceRow, $priorDestUploads, $sourceChoices, $destChoices) {
        if (empty($priorDestUploads)) {
            return TRUE;
        }
        $uploadedDestData = [];
        foreach ($priorDestUploads as $sourceLicensePlate => $rows) {
            # it doesn't matter if a normative row is duplicated because we're looking for a repeating instrument
            $uploadedDestData = array_merge($uploadedDestData, $rows);
        }
        return self::isValidToCopyRepeating($fields, $sourceRow, $priorDestUploads, $sourceChoices, $destChoices);
    }

    private static function isValidToCopyRepeating($fields, $sourceRow, $destData, $sourceChoices, $destChoices) {
        if ((count($fields) == 1) && self::fieldBlank($fields[0], $sourceRow)) {
            # one blank field => not valid enough to copy
            // Application::log("isValidToCopyRepeating Rejecting because one field blank: ".json_encode($fields));
            return FALSE;
        } else {
            foreach ($destData as $destRow) {
                if (
                    ($destRow['redcap_repeat_instrument'] == $sourceRow['redcap_repeat_instrument'])
                    && self::allFieldsMatch($fields, $sourceRow, $destRow, $sourceChoices, $destChoices)
                ) {
                    # already copied => skip
                    // Application::log("isValidToCopyRepeating Rejecting because all fields match");
                    return FALSE;
                }
            }
        }
        // Application::log("isValidToCopyRepeating returning TRUE");
        return TRUE;
    }

    private static function getSharingInformation() {
        $ary = [
            "initial_survey" => ["prefix" => "check", "formType" => "single", "test_fields" => ["check_date"], "always_copy" => TRUE, ],
            "followup" => ["prefix" => "followup", "formType" => "repeating", "test_fields" => ["followup_date"], "debug_field" => "followup_name_last", "always_copy" => TRUE, ],
            "position_change" => [ "prefix" => "promotion", "formType" => "repeating", "test_fields" => ["promotion_job_title", "promotion_date"], "always_copy" => FALSE, ],
            "resources" => [ "prefix" => "resources", "formType" => "repeating", "test_fields" => ["resources_date", "resources_resource"], "debug_field" => "resources_resource", "always_copy" => FALSE, ],
            "old_honors_and_awards" => [ "prefix" => "honor", "formType" => "repeating", "test_fields" => ["honor_name", "honor_date"], "debug_field" => "honor_name", "always_copy" => FALSE, ],
            "honors_awards_and_activities" => [ "prefix" => "activityhonor", "formType" => "repeating", "test_fields" => ["activityhonor_name", "activityhonor_datetime"], "debug_field" => "activityhonor_name", "always_copy" => TRUE, ],
            "honors_awards_and_activities_survey" => [ "prefix" => "surveyactivityhonor", "formType" => "repeating", "test_fields" => ["surveyactivityhonor_datetime"], "debug_field" => "surveyactivityhonor_datetime", "always_copy" => TRUE, ],
        ];
        if (Application::isVanderbilt()) {
            $ary['resources']['always_copy'] = TRUE;
            $ary['old_honors_and_awards']['always_copy'] = TRUE;
            $ary['position_change']['always_copy'] = TRUE;
        }
        return $ary;
    }

    public static function getConfigurableForms() {
	    $forms = self::getSharingInformation();
	    $formsForCopy = [];
	    foreach ($forms as $instrument => $config) {
            if (!isset($config["always_copy"]) || !$config["always_copy"]) {
                $formsForCopy[] = $instrument;
            }
        }
        $instrumentsWithLabels = [];
	    foreach ($formsForCopy as $instrument) {
	        $label = preg_replace("/_/", " ", $instrument);
	        $label = ucwords($label);
	        $instrumentsWithLabels[$instrument] = $label;
        }
	    return $instrumentsWithLabels;
    }

    private function getTokensAndServersAndPids($requestedPids) {
        $tokens = [];
        $servers = [];
        $pids = [];
        foreach ($requestedPids as $pid) {
            if (REDCapManagement::isActiveProject($pid)) {
                $token = $this->getProjectSetting("token", $pid);
                $server = $this->getProjectSetting("server", $pid);
                if ($token && $server) {
                    $tokens[$pid] = $token;
                    $servers[$pid] = $server;
                    $pids[] = $pid;
                }
            }
        }
        $credentialsFile = Application::getCredentialsDir() . "/career_dev/credentials.php";
        if (preg_match("/redcap.vanderbilt.edu/", SERVER_NAME) && file_exists($credentialsFile)) {
            include($credentialsFile);
            if (isset($info["prod"])) {
                $prodPid = $info["prod"]["pid"];
                $prodToken = $info["prod"]["token"];
                $prodServer = $info["prod"]["server"];
                $pids[] = $prodPid;
                $tokens[$prodPid] = $prodToken;
                $servers[$prodPid] = $prodServer;
                Application::log("Searching through Vanderbilt Master Project ($prodPid)", $prodPid);
            }
        }
        return [$tokens, $servers, $pids];
    }

    # will be run weekly, in which case $pidsSource = $pidsDest = all active pids
    # can be run on a one-time basis for a set of projects, in which case $pidsDest is a handful of pids
	public function findMatches($pidsSource, $pidsDest) {
        # all test_fields must be equal and, if by self (i.e., list of one), non-blank
        $firstNames = [];
        $lastNames = [];
        $updatedRecords = [];

        $allPids = array_unique(array_merge($pidsSource, $pidsDest));
        list($tokens, $servers, $pids) = $this->getTokensAndServersAndPids($allPids);

        # checks a list of names in an ExtMod setting to see what records have been updated.
        # add to $updatedRecords when a record is new or a name has changed.
        $originalPid = CareerDev::getPid();
        foreach ($pids as $pid) {
            if ($tokens[$pid] && $servers[$pid]) {
                CareerDev::setPid($pid);
                $token = $tokens[$pid];
                $server = $servers[$pid];
                $firstNames[$pid] = Download::firstnames($token, $server);
                $lastNames[$pid] = Download::lastnames($token, $server);
                if (in_array($pid, $pidsDest)) {
                    $oldFirstNames = Application::getSetting(self::OLD_FIRST_NAMES, $pid) ?: [];
                    $oldLastNames = Application::getSetting(self::OLD_LAST_NAMES, $pid) ?: [];
                    $updatedRecords[$pid] = [];
                    foreach ($firstNames[$pid] as $recordId => $fn1) {
                        $fn2 = $oldFirstNames[$recordId] ?? "";
                        if ($fn1 != $fn2) {
                            $updatedRecords[$pid][] = $recordId;
                        }
                    }
                    foreach ($lastNames[$pid] as $recordId => $ln1) {
                        $ln2 = $oldLastNames[$recordId] ?? "";
                        if (($ln1 != $ln2) && !in_array($recordId, $updatedRecords[$pid])) {
                            $updatedRecords[$pid][] = $recordId;
                        }
                    }
                    if (empty($updatedRecords[$pid])) {
                        unset($updatedRecords[$pid]);
                    }
                    Application::saveSetting(self::OLD_FIRST_NAMES, $firstNames[$pid], $pid);
                    Application::saveSetting(self::OLD_LAST_NAMES, $lastNames[$pid], $pid);
                }
            }
        }
        CareerDev::setPid($originalPid);

        # push
        if (!empty($updatedRecords)) {
            $this->updateMatches($pidsSource, $firstNames, $lastNames, $updatedRecords);
        }
        foreach ($pidsDest as $currPid) {
            if (isset($updatedRecords[$currPid])) {
                Application::log("Found matches", $currPid);
            } else {
                Application::log("No updated records found", $currPid);
            }
        }
    }

    public function searchForMissingEmails($allPids) {
        list($tokens, $servers, $pids) = $this->getTokensAndServersAndPids($allPids);
        $usedMatches = Application::getSystemSetting(self::SHARING_SETTING) ?: [];
        $emailsToUpdate = [];
        foreach ($usedMatches as $matchAry) {
            $infoArray = self::makeInfoArray($matchAry, $tokens, $servers);
            $emailMatches = $this->alertForBlankEmails($infoArray);
            foreach ($emailMatches as $emailData) {
                $emailPid = $emailData["dest"]["pid"];
                if (!isset($emailsToUpdate[$emailPid])) {
                    $emailsToUpdate[$emailPid] = [];
                }
                $emailsToUpdate[$emailPid][] = $emailData;
            }
        }
        foreach ($emailsToUpdate as $emailPid => $items) {
            $adminEmail = Application::getSetting("admin_email", $emailPid);
            $defaultFrom = Application::getSetting("default_from", $emailPid) ?: "noreply@flightTracker.vumc.org";
            if ($adminEmail && REDCapManagement::isEmailOrEmails($adminEmail)) {
                $htmlRows = [];
                $htmlRows[] = self::makeEmailCopyHTML($items);
                $projectTitle = Download::projectTitle($emailPid);
                $projectTitleHTML = Links::makeProjectHomeLink($emailPid, $projectTitle);
                $introHTML = "<p>For $projectTitleHTML, the following Flight Tracker scholars have been matched. An email exists from the below source projects, but it is blank in yours. Do you want to change them? If so, please click the link to copy. <span style='text-decoration: underline; font-weight: bold;'>Note: A scholar might be matched to more than one possible email.</span></p>";
                $html = $introHTML . implode("", $htmlRows);
                \REDCap::email($adminEmail, $defaultFrom, "Flight Tracker New Email Matches - " . $projectTitle, $html);
            }
        }
    }

    public function addMatchProcessingToCron($destPids) {
        if (!isset($this->cronManager)) {
            return;
        }
        $matchQueueSize = count(Application::getSystemSetting(FlightTrackerExternalModule::SHARING_SETTING) ?: []);
        foreach ($destPids as $pid) {
            Application::log("Adding $matchQueueSize sharing matches to cron", $pid);
        }
        for ($index = 0; $index < ceil($matchQueueSize / FlightTrackerExternalModule::MATCHES_BATCH_SIZE); $index++) {
            $this->cronManager->addMultiCron("drivers/preprocess.php", "preprocessSharingMatches", date("Y-m-d"), $destPids, $index);
        }
    }

    private static function cleanSharingItems() {
        $matches = Application::getSystemSetting(self::SHARING_SETTING) ?: [];
        $newMatches = [];
        $changed = FALSE;
        $recordsByPid = [];
        foreach ($matches as $matchAry) {
            $newMatchAry = [];
            foreach ($matchAry as $licensePlate) {
                list($currPid, $currRecordId) = explode(":", $licensePlate);
                if (!isset($recordsByPid[$currPid])) {
                    $recordsByPid[$currPid] = Download::recordIdsByPid($currPid);
                }
                if (in_array($currRecordId, $recordsByPid[$currPid])) {
                    $newMatchAry[] = $licensePlate;
                } else {
                    $changed = TRUE;
                }
            }
            if (count($newMatchAry) >= 2) {
                $newMatches[] = $newMatchAry;
            }
        }
        if ($changed) {
            Application::saveSystemSetting(self::SHARING_SETTING, $newMatches);
        }
    }

    # will be run weekly, in which case $pidsSource = $pidsDest = all active pids
    # can be run on a one-time basis for a set of projects, in which case $pidsDest is a handful of pids
    public function processFoundMatches($allPids, $destPids, $idx) {
        self::cleanSharingItems();
        $usedMatches = Application::getSystemSetting(self::SHARING_SETTING) ?: [];
        $matchChunks = array_chunk($usedMatches, self::MATCHES_BATCH_SIZE);
        if ($idx >= count($matchChunks)) {
            return [];
        }
        $currChunk = $matchChunks[$idx];
        list($tokens, $servers, $pids) = $this->getTokensAndServersAndPids($allPids);
        $forms = self::getSharingInformation();
        $choices = [];
        $metadataFields = [];
        foreach ($pids as $pid) {
            $currMetadataFields = Download::metadataFieldsByPid($pid);
            if (DataDictionaryManagement::isMetadataFieldsFilled($currMetadataFields)) {
                $relevantFields = [];
                foreach ($forms as $instrument => $array) {
                    $relevantFields = array_merge($relevantFields, $array["test_fields"] ?: []);
                }
                $relevantFields = array_unique($relevantFields);
                $choices[$pid] = DataDictionaryManagement::getChoicesForFields($pid, $relevantFields);
                $metadataFields[$pid] = $currMetadataFields;
                Application::log("Processing ".count($currChunk)." matches in batch ".($idx + 1)." of ".count($matchChunks), $pid);
            }
        }

        $pidsUpdated = $this->processMatchesForList($currChunk, $tokens, $servers, $forms, $metadataFields, $choices, $destPids);
        foreach ($pidsUpdated as $currPid) {
            Application::log("Updated match information", $currPid);
        }
        return $pidsUpdated;
	}

    private static function makeInfoArray($matchAry, $tokens, $servers) {
        $pidsAffected = [];
        $infoArray = [];
        foreach ($matchAry as $licensePlate) {
            list($currPid, $recordId) = explode(":", $licensePlate);
            if (($tokens[$currPid] ?? FALSE) && ($servers[$currPid] ?? FALSE)) {
                $infoArray[] = [
                    "token" => $tokens[$currPid],
                    "server" => $servers[$currPid],
                    "pid" => $currPid,
                    "record" => $recordId,
                ];
                $pidsAffected[] = $currPid;
            }
        }
        return [$infoArray, $pidsAffected];
    }

    private function processMatchesForList($matches, $tokens, $servers, $forms, $metadataFields, $choices, $destPids) {
        $pidsUpdated = [];
        $usedPids = [];
        foreach ($matches as $matchAry) {
            if (count($matchAry) > 1) {
                foreach ($matchAry as $licensePlate) {
                    $currPid = explode(":", $licensePlate)[0];
                    if (!in_array($currPid, $usedPids)) {
                        $usedPids[] = $currPid;
                    }
                }
            }
        }
        if (empty($usedPids)) {
            return [];
        }
        $completes = [];
        foreach (array_keys($forms) as $instrument) {
            $completes[$instrument] = [];
        }
        foreach ($usedPids as $currPid) {
            $repeatingForms = DataDictionaryManagement::getRepeatingForms($currPid);
            $validForms = Download::metadataFormsByPid($currPid);
            foreach (array_keys($forms) as $instrument) {
                if (in_array($instrument, $validForms)) {
                    $field = $instrument . "_complete";
                    if (in_array($instrument, $repeatingForms)) {
                        $completes[$instrument][$currPid] = Download::oneFieldWithInstancesByPid($currPid, $field);
                    } else {
                        $completes[$instrument][$currPid] = Download::oneFieldByPid($currPid, $field);
                    }
                }
            }
        }

        foreach ($matches as $matchAry) {
            list($infoArray, $pidsAffected) = self::makeInfoArray($matchAry, $tokens, $servers);
            if (!empty(array_intersect($pidsAffected, $destPids))) {
                $this->copyFormData($completes, $pidsUpdated, $forms, $infoArray, $metadataFields, $choices);
                $this->copyWranglerData($pidsUpdated, $infoArray);
                $this->dedupPositionChanges($infoArray, $metadataFields);  // does not update pidsUpdated
                foreach ($pidsAffected as $currPid) {
                    Application::log("Shared data among ".implode(", ", $matchAry), $currPid);
                }
                $pidsUpdated = array_unique(array_merge($pidsUpdated, $pidsAffected));
            }
        }
        return $pidsUpdated;
    }

    private static function makeEmailCopyHTML($items) {
        $infoByLicensePlate = [];
        foreach ($items as $copyInfo) {
            $destPid = $copyInfo['dest']['pid'];
            $destRecord = $copyInfo['dest']['record'];
            $licensePlate = "$destPid:$destRecord";
            if (!isset($infoByLicensePlate[$licensePlate])) {
                $infoByLicensePlate[$licensePlate] = [];
            }
            $infoByLicensePlate[$licensePlate][] = $copyInfo;
        }

        $html = "";
        $style = "padding: 6px; border: 1px solid #444; background-color: #AAA; color: black; text-decoration: none;";
        foreach ($infoByLicensePlate as $destLicensePlate => $copyInfos) {
            $numEmails = self::getNumberOfEmailAddresses($copyInfos);
            if ($numEmails > 0) {
                $baselineInfo = $copyInfos[0];
                $destInfo = $baselineInfo['dest'];
                $destRecord = $destInfo['record'];
                $destName = Download::fullName($destInfo['token'], $destInfo['server'], $destRecord);
                $matchWord = ($numEmails == 1) ? "Match" : "Matches";
                $header = "<h2>$destName: Record $destRecord ($numEmails $matchWord)</h2>";
                $htmlByEmail = [];
                foreach ($copyInfos as $copyInfo) {
                    $sourceInfo = $copyInfo['source'];
                    $email = $copyInfo['email'];
                    $sourceName = Download::fullName($sourceInfo['token'], $sourceInfo['server'], $sourceInfo['record']);
                    $sourceProject = Download::projectTitle($sourceInfo['pid']);
                    $adoptLink = Application::link("copyEmail.php", $destInfo['pid'])."&sourcePid=".$sourceInfo['pid']."&sourceRecord=".$sourceInfo['record']."&destRecord=$destRecord";
                    $notAdoptLink = Application::link("copyEmail.php", $destInfo['pid'])."&skip=".urlencode($email)."&destRecord=$destRecord";
                    $itemHTML = "<div style='margin-top: 1em;'>Matched to $sourceName from $sourceProject. The proposed email is <strong>$email</strong>.</div>";
                    $itemHTML .= "<div style='margin-bottom: 1.5em; margin-top: 6px;'><a href='$adoptLink' style='$style'>Yes, please adopt this one</a> -or- <a href='$notAdoptLink' style='$style'>do not adopt this one</a></div>";
                    $htmlByEmail[$email] = $itemHTML;
                }
                $html .= $header.implode("", array_values($htmlByEmail));
            }
        }
        return $html;
    }

    private static function getNumberOfEmailAddresses($infoAry) {
        $emails = [];
        foreach ($infoAry as $info) {
            if ($info['email'] && !in_array($info['email'], $emails) && REDCapManagement::isEmailOrEmails($info['email'])) {
                $emails[] = $info['email'];
            }
        }
        return count($emails);
    }

    private function alertForBlankEmails(array $infoArray) : array {
        $hasEmails = [];
        $missingEmails = [];
        foreach ($infoArray as $info) {
            $currPid = $info['pid'];
            $recordId = $info['record'];
            $omitted = Application::getSetting("omittedEmails", $currPid) ?: [];
            $email = Download::oneFieldForRecordByPid($currPid, "identifier_email", $recordId);
            $proceed = TRUE;
            foreach ($omitted[$recordId] ?? [] as $omittedEmail) {
                if (strtolower($omittedEmail) == strtolower($email)) {
                    $proceed = FALSE;
                }
            }
            if ($proceed) {
                if (REDCapManagement::isEmailOrEmails($email)) {
                    $hasEmails[$email] = $info;
                } else {
                    $missingEmails[] = $info;
                }
            }
        }
        if (!empty($hasEmails) && !empty($missingEmails)) {
            $returnItems = [];
            foreach ($hasEmails as $sourceEmail => $sourceInfo) {
                foreach ($missingEmails as $destInfo) {
                    $returnItems[] = [
                        "source" => $sourceInfo,
                        "dest" => $destInfo,
                        "email" => $sourceEmail,
                    ];
                }
            }
            return $returnItems;
        } else {
            return [];
        }
    }

    # the main function that updates the matches hash based on name-matching
    # takes a while at first run, but only updates for those specified in $updatedRecordsByPid
    private function updateMatches($pidsSource, $firstNames, $lastNames, $updatedRecordsByPid) {
        if (date("d") <= 7) {
            # reset once a month, on the first week of the month, to weed out dated matches
            # cleanSharingItems() already removes bad matches
            $priorSearchedFor = [];
        } else {
            $priorSearchedFor = Application::getSystemSetting(self::SHARING_SETTING) ?: [];
        }
        foreach ($updatedRecordsByPid as $pid => $records) {
            Application::log("Looking for matches", $pid);
            foreach ($records as $recordId) {
                $firstName = $firstNames[$pid][$recordId] ?? "";
                $lastName = $lastNames[$pid][$recordId] ?? "";
                foreach ($pidsSource as $sourcePid) {
                    if (!empty($firstNames[$sourcePid]) && !empty($lastNames[$sourcePid]) && ($sourcePid != $pid)) {
                        foreach ($lastNames[$sourcePid] as $sourceRecordId => $ln) {
                            $fn = $firstNames[$sourcePid][$sourceRecordId] ?? "";
                            $foundMatch = FALSE;
                            foreach (NameMatcher::explodeFirstName($firstName) as $pidFn) {
                                foreach (NameMatcher::explodeLastName($lastName) as $pidLn) {
                                    if (NameMatcher::matchName($pidFn, $pidLn, $fn, $ln)) {
                                        $foundMatch = TRUE;
                                        $newLicensePlate = "$pid:$recordId";
                                        $oldLicensePlate = "$sourcePid:$sourceRecordId";
                                        Application::log("New Match between $oldLicensePlate and $newLicensePlate: $pidFn $pidLn and $fn $ln", $pid);
                                        Application::log("New Match between $oldLicensePlate and $newLicensePlate: $pidFn $pidLn and $fn $ln", $sourcePid);
                                        $foundInPrior = FALSE;
                                        foreach ($priorSearchedFor as $i => $matches) {
                                            if (in_array($oldLicensePlate, $matches) && in_array($newLicensePlate, $matches)) {
                                                $foundInPrior = TRUE;
                                            } else if (in_array($oldLicensePlate, $matches) && !in_array($newLicensePlate, $matches)) {
                                                $priorSearchedFor[$i][] = $newLicensePlate;
                                                $foundInPrior = TRUE;
                                            }
                                        }
                                        if (!$foundInPrior) {
                                            $priorSearchedFor[] = [$oldLicensePlate, $newLicensePlate];
                                        }
                                        break;
                                    }
                                }
                                if ($foundMatch) {
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }
        $this->disableUserBasedSettingPermissions();
        Application::saveSystemSetting(self::SHARING_SETTING, $priorSearchedFor);
    }

    private static function explodeAllNames($lastName, $firstName) {
        $combos = [];
        foreach (NameMatcher::explodeFirstName($firstName) as $fn) {
            foreach (NameMatcher::explodeLastName($lastName) as $ln) {
                if ($fn && $ln) {
                    $name = "$fn $ln";
                    $combos[$name] = ["first" => $fn, "last" => $ln];
                }
            }
        }
        return array_values($combos);
    }


	public function copyWranglerData(&$pidsUpdated, $infoArray) {
        if (count($infoArray) < 2) {
            return;
        }
        $pubDataByPid = [];
        $grantDataByPid = [];
        $originalPid = CareerDev::getPid();
        foreach ($infoArray as $info) {
            $currPid = $info['pid'];
            if (!isset($pubDataByPid[$currPid]) || !isset($grantDataByPid[$currPid])) {
                $citationFields = ['record_id', 'citation_pmid', 'citation_include'];
                CareerDev::setPid($currPid);
                $pubDataByPid[$currPid] = Download::fieldsForRecordsByPid($currPid, $citationFields, [$info['record']]);
                $grantDataByPid[$currPid] = self::getToImport($currPid, $info['record']);
            }
        }
        CareerDev::setPid($originalPid);
        foreach ($infoArray as $sourceInfo) {
            foreach ($infoArray as $destInfo) {
                if (($destInfo['pid'] != $sourceInfo['pid']) || ($destInfo['record'] != $sourceInfo['record'])) {
                    $sourcePubData = $pubDataByPid[$sourceInfo['pid']] ?? [];
                    $destPubData = $pubDataByPid[$destInfo['pid']] ?? [];
                    $sourceToImport = $grantDataByPid[$sourceInfo['pid']] ?? [];
                    $destToImport = $grantDataByPid[$destInfo['pid']] ?? [];
                    $hasCopiedPubData = $this->copyPubData($sourceInfo, $destInfo, $sourcePubData, $destPubData);
                    $hasCopiedGrantData = $this->copyGrantData($destInfo, $sourceToImport, $destToImport);
                    if (
                        ($hasCopiedGrantData || $hasCopiedPubData)
                        && !in_array($destInfo['pid'], $pidsUpdated)
                    ) {
                        $pidsUpdated[] = $destInfo['pid'];
                    }
                }
            }
        }
    }

    private function copyPubData($sourceInfo, $destInfo, $sourceData, $destData) {
        $sourcePMIDs = self::transformREDCapDataToPMIDsAndIncludes($sourceData, $sourceInfo['record']);
        $destPMIDs = self::transformREDCapDataToPMIDsAndIncludes($destData, $destInfo['record']);

        $uploadData = [];
        foreach ($sourcePMIDs as $pmid => $sourceAry) {
            $sourceIncludeStatus = $sourceAry['include'];
            if (($sourceIncludeStatus !== "") && isset($destPMIDs[$pmid])) {
                $destIncludeStatus = $destPMIDs[$pmid]['include'];
                $destInstance = $destPMIDs[$pmid]['instance'];
                if ($destIncludeStatus === "") {
                    $uploadData[] = [
                        "record_id" => $destInfo['record'],
                        "redcap_repeat_instrument" => "citation",
                        "redcap_repeat_instance" => $destInstance,
                        "citation_include" => $sourceIncludeStatus,
                    ];
                }
            }
        }
        if (!empty($uploadData)) {
            Application::log("Sharing publication wrangling data from {$sourceInfo['pid']} (record {$sourceInfo['record']}) to {$destInfo['pid']}(record {$destInfo['record']})");
            try {
                Upload::rows($uploadData, $destInfo['token'], $destInfo['server']);
                return TRUE;     // data shared
            } catch (\Exception $e) {
                Application::log("Warning: Data sharing publication upload failed! ".$e->getMessage());
                return FALSE;    // data sharing failed
            }
        } else {
            return FALSE;    // no data shared
        }
    }

    private static function transformREDCapDataToPMIDsAndIncludes($redcapData, $recordId) {
	    $pmids = [];
	    foreach ($redcapData as $row) {
	        if (($row['redcap_repeat_instrument'] == "citation") && ($row['record_id'] == $recordId) && $row['citation_pmid']) {
	            $pmids[$row['citation_pmid']] = [
	                "include" => $row['citation_include'],
                    "instance" => $row['redcap_repeat_instance'],
                ];
            }
        }
	    return $pmids;
    }

    private static function getToImport($pid, $recordId) {
        $fields = ["record_id", "summary_calculate_to_import"];
        $redcapData = Download::fieldsForRecordsByPid($pid, $fields, [$recordId]);
        $normativeRow = REDCapManagement::getNormativeRow($redcapData);
        $toImport = [];
        if ($normativeRow['summary_calculate_to_import']) {
            $toImport = json_decode($normativeRow['summary_calculate_to_import'], TRUE);
            if ($toImport === FALSE) {
                $toImport = [];
            }
        }
        return $toImport;
    }

    private function copyGrantData($destInfo, $sourceToImport, $destToImport) {
	    # ??? Custom Grants
        if (!empty($sourceToImport)) {
            $destChanged = FALSE;
            foreach ($sourceToImport as $key => $value) {
                if (!isset($destToImport[$key])) {        // copies only new information; does not copy in case of conflict
                    $destToImport[$key] = $value;
                    $destChanged = TRUE;
                }
            }
            if ($destChanged) {
                $uploadRow = [
                    "record_id" => $destInfo['record'],
                    "redcap_repeat_instrument" => "",
                    "redcap_repeat_instance" => "",
                    "summary_calculate_to_import" => json_encode($destToImport),
                ];
                try {
                    Upload::oneRow($uploadRow, $destInfo['token'], $destInfo['server']);
                    return TRUE;     // data sharing succeeded
                } catch (\Exception $e) {
                    Application::log("Warning: Data sharing publication upload failed! ".$e->getMessage());
                    return FALSE;    // data sharing failed
                }
            }
        }
        return FALSE;   // no data to copy
    }

    private function cleanupFormData($forms, $destInfo, $destMetadataFields) {
        foreach ($forms as $instrument => $sharingData) {
            if (($sharingData['formType'] == "repeating") && ($sharingData['debug_field'] ?? FALSE)) {
                $debugField = $sharingData['debug_field'];
                $completeField = $instrument."_complete";
                $relevantFields = array_unique(array_merge(["record_id", $completeField], DataDictionaryManagement::filterFieldsForPrefix($destMetadataFields, $sharingData['prefix'])));
                $redcapData = Download::fieldsForRecords($destInfo['token'], $destInfo['server'], $relevantFields, [$destInfo['record']]);
                $instancesToDelete = [];
                foreach ($redcapData as $row) {
                    if (($row['redcap_repeat_instrument'] == $instrument) && isset($row[$debugField]) && ($row[$debugField] === "")) {
                        $instancesToDelete[] = $row['redcap_repeat_instance'];
                    }
                }
                if (!empty($instancesToDelete)) {
                    Upload::deleteFormInstances($destInfo['token'], $destInfo['server'], $destInfo['pid'], $sharingData['prefix'], $destInfo['record'], $instancesToDelete);
                }
            }
        }
    }

	private function copyFormData(&$completes, &$pidsUpdated, $forms, $infoArray, $metadataFieldsByPid, $choicesByPid) {
        $originalPid = CareerDev::getPid();
        $repeatingFormsByPid = [];
        foreach ($infoArray as $info) {
            CareerDev::setPid($info['pid']);
            $repeatingFormsByPid[$info['pid']] = DataDictionaryManagement::getRepeatingForms($info['pid']);
            foreach (array_keys($forms) as $instrument) {
                if (!isset($completes[$instrument][$info['pid']])) {
                    $field = $instrument . "_complete";
                    if (in_array($instrument, $repeatingFormsByPid[$info['pid']])) {
                        $completes[$instrument][$info['pid']] = Download::oneFieldWithInstances($info['token'], $info['server'], $field);
                    } else {
                        $completes[$instrument][$info['pid']] = Download::oneField($info['token'], $info['server'], $field);
                    }
                }
            }
        }
        CareerDev::setPid($originalPid);
        foreach ($completes as $instrument => $completeData) {
            $pidsProcessed = $this->processInstrument(
                $instrument,
                $forms,
                $completeData,
                $infoArray,
                $choicesByPid,
                $metadataFieldsByPid,
                $repeatingFormsByPid
                );
            $pidsUpdated = array_unique(array_merge($pidsProcessed, $pidsUpdated));
        }
    }

    private function dedupPositionChanges($infoArray, $metadataFieldsForPid) {
        $instrument = "position_change";
        $prefix = "promotion";
        foreach ($infoArray as $info) {
            $fields = array_unique(array_merge(["record_id", $instrument."_complete"], DataDictionaryManagement::filterFieldsForPrefix($metadataFieldsForPid[$info['pid']], $prefix)));
            $redcapData = Download::fieldsForRecordsByPid($info['pid'], $fields, [$info['record']]);
            $instancesToDelete = [];
            $seenItems = [];
            foreach ($redcapData as $row) {
                $item = $row['promotion_date'].":".$row['promotion_job_title'].":".$row['promotion_in_effect'].":".$row['promotion_institution'];
                if (($row['redcap_repeat_instrument'] == $instrument) && in_array($item, $seenItems)) {
                    $instancesToDelete[] = $row['redcap_repeat_instance'];
                } else if ($row['redcap_repeat_instrument'] == $instrument) {
                    $seenItems[] = $item;
                }
            }
            if (!empty($instancesToDelete)) {
                Upload::deleteFormInstances($info['token'], $info['server'], $info['pid'], $prefix, $info['record'], $instancesToDelete);
            }
        }
    }

    private function dedupChoicesOnlyError($projToken, $projServer, $projPid, $instrument) {
        $prefix = REDCapManagement::getPrefixFromInstrument($instrument);
        if (!$prefix) {
            return;
        }

        $records = Download::recordIds($projToken, $projServer);
        $metadata = Download::metadata($projToken, $projServer);
        $instrumentFields = DataDictionaryManagement::getFieldsFromMetadata($metadata, $instrument);
        if (!in_array("record_id", $instrumentFields)) {
            $instrumentFields[] = "record_id";
        }
        if (!in_array($instrument."_complete", $instrumentFields)) {
            $instrumentFields[] = $instrument."_complete";
        }
        $fieldsByType = [];
        foreach (['dropdown', 'radio', 'checkboxes', 'yesno', 'truefalse'] as $fieldType) {
            $fieldsByType[$fieldType] = DataDictionaryManagement::getFieldsOfType($metadata, $fieldType);
        }
        foreach ($records as $recordId) {
            $instancesToDelete = [];
            $redcapData = Download::fieldsForRecords($projToken, $projServer, $instrumentFields, [$recordId]);

            foreach ($redcapData as $row) {
                if ($row['redcap_repeat_instrument']) {
                    $rowHasData = FALSE;
                    foreach ($instrumentFields as $field) {
                        if (preg_match("/^$prefix/", $field)) {
                            $fieldHasChoices = FALSE;
                            foreach ($fieldsByType as $fieldType => $fields) {
                                if (in_array($field, $fields)) {
                                    $fieldHasChoices = TRUE;
                                    break;
                                }
                            }
                            if (!$fieldHasChoices && ($row[$field] !== "")) {
                                $rowHasData = TRUE;
                                break;
                            }
                        }
                    }
                    if (!$rowHasData) {
                        $instancesToDelete[] = $row['redcap_repeat_instance'];
                    }
                }
            }
            if (!empty($instancesToDelete)) {
                Upload::deleteFormInstances($projToken, $projServer, $projPid, $prefix, $recordId, $instancesToDelete);
            }
        }
    }

    private static function isMismatchedType($formType, $instrument, $infoArray, $repeatingFormsByPid) {
        if ($formType == "repeating") {
            foreach ($infoArray as $info) {
                $currPid = $info['pid'];
                if (!in_array($instrument, $repeatingFormsByPid[$currPid])) {
                    # non-repeating instrument
                    # mismatch between repeating and non-repeating
                    return TRUE;
                }
            }
        } else if ($formType == "single") {
            foreach ($infoArray as $info) {
                $currPid = $info['pid'];
                if (in_array($instrument, $repeatingFormsByPid[$currPid])) {
                    # repeating instrument
                    # mismatch between repeating and non-repeating
                    return TRUE;
                }
            }
        }
        return FALSE;
    }

    # an imprecise screen that looks at whether the _complete fields mismatch with each other
    # if returning true, it avoids having to download data for all pids
    # for repeating, return TRUE if-and-only-if a source is marked as complete
    # for a single form, return TRUE if-and-only-if a source is marked as complete AND a destination is not complete
    private static function doCompleteDataSayToDownload($infoArray, $formType, $completeData) {
        foreach ($infoArray as $sourceInfo) {
            $sourcePid = $sourceInfo['pid'];
            $sourceRecordId = $sourceInfo['record'];
            if ($formType == "repeating") {
                if (is_array($completeData[$sourcePid][$sourceRecordId] ?: [])) {
                    $dataValues = array_values($completeData[$sourcePid][$sourceRecordId] ?: []);
                } else {
                    $dataValues = [$completeData[$sourcePid][$sourceRecordId]];
                }
                foreach (self::COMPLETE_INDICES as $completeValue) {
                    if (in_array($completeValue, $dataValues)) {
                        return TRUE;
                    }
                }
            } else if ($formType == "single") {
                foreach ($infoArray as $destInfo) {
                    $destPid = $destInfo['pid'];
                    $destRecordId = $destInfo['record'];
                    if (($sourcePid != $destPid) || ($sourceRecordId != $destRecordId)) {
                        if (
                            !in_array($completeData[$destPid][$destRecordId], self::COMPLETE_INDICES)
                            && in_array($completeData[$sourcePid][$sourceRecordId], self::COMPLETE_INDICES)
                        ) {
                            return TRUE;
                        }
                    }
                }
            } else {
                throw new \Exception("This should never happen: invalid formType $formType");
            }
        }
        return FALSE;
    }

    private function processInstrument($instrument, $forms, $completeData, $infoArray, $choicesByPid, $metadataFieldsByPid, $repeatingFormsByPid) {
        $pidsUpdated = [];
        $config = $forms[$instrument];
        if (self::isMismatchedType($config['formType'], $instrument, $infoArray, $repeatingFormsByPid)) {
            return [];
        }

        if (self::doCompleteDataSayToDownload($infoArray, $config['formType'], $completeData)) {
            $prefix = $forms[$instrument]["prefix"];
            $originalPid = CareerDev::getPid();
            $dataByLicensePlate = [];
            foreach ($infoArray as $info) {
                $currPid = $info['pid'];
                $recordId = $info['record'];
                CareerDev::setPid($currPid);
                $fields = array_unique(array_merge(["record_id", $instrument."_complete"], DataDictionaryManagement::filterFieldsForPrefix($metadataFieldsByPid[$currPid], $prefix)));
                $dataByLicensePlate["$currPid:$recordId"] = Download::fieldsForRecordsByPid($currPid, $fields, [$recordId]);
            }
            CareerDev::setPid($originalPid);

            $upload = [];
            foreach ($infoArray as $sourceInfo) {
                foreach ($infoArray as $destInfo) {
                    self::uploadIfAble(
                        $pidsUpdated,
                        $upload,
                        $sourceInfo,
                        $destInfo,
                        $dataByLicensePlate,
                        $choicesByPid,
                        $metadataFieldsByPid,
                        $instrument,
                        $forms,
                        $completeData);
                }
            }
        } else {
            // Application::log("Could not match complete for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $sourcePid);
            // Application::log("Could not match complete for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $destPid);
        }
        return $pidsUpdated;
    }

    # this function attempts to upload from source into dest if warranted
    private static function uploadIfAble(&$pidsUpdated, &$upload, $sourceInfo, $destInfo, $dataByLicensePlate, $choicesByPid, $metadataFieldsByPid, $instrument, $forms, $completeData) {
        $isDebug = Application::isLocalhost();
        $config = $forms[$instrument];

        $destPid = $destInfo['pid'];
        $destRecordId = $destInfo['record'];
        $sourcePid = $sourceInfo['pid'];
        $sourceRecordId = $sourceInfo['record'];
        $sourceLicensePlate = "$sourcePid:$sourceRecordId";
        $destLicensePlate = "$destPid:$destRecordId";
        $sharedFormsForSource = Application::getSetting("shared_forms", $sourcePid) ?: [];
        $sharedFormsForDest = Application::getSetting("shared_forms", $destPid) ?: [];

        $repeatingRows = [];
        $uploadNormativeRow = FALSE;
        $normativeRow = [
            "record_id" => $destRecordId,
            "redcap_repeat_instrument" => "",
            "redcap_repeat_instance" => "",
        ];
        if ($destLicensePlate != $sourceLicensePlate) {
            $sourceData = $dataByLicensePlate[$sourceLicensePlate] ?? [];
            $destData = $dataByLicensePlate[$destLicensePlate] ?? [];
            $sourceChoices = $choicesByPid[$sourcePid] ?? [];
            $destChoices = $choicesByPid[$destPid] ?? [];

            $newInstance = REDCapManagement::getMaxInstance(
                    self::combineDataWithUploads($destData, $upload[$destLicensePlate]),
                    $instrument,
                    $destRecordId) + 1;
            foreach ($sourceData as $sourceRow) {
                $continueToCopyFromSource = TRUE;
                $destNormativeRow = REDCapManagement::getNormativeRow($destData) ?: [];
                if (
                    ($config["formType"] == "single")
                    && !self::isValidToCopy($config["test_fields"], $sourceRow, $destNormativeRow, $sourceChoices, $destChoices)
                ) {
                    if ($isDebug) {
                        Application::log("Not valid to copy single for $instrument in dest $destPid $destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid $sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $destPid);
                        Application::log("Not valid to copy single for $instrument in dest $destPid $destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid $sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $sourcePid);
                    }
                    $continueToCopyFromSource = FALSE;
                } else if ($config["formType"] == "repeating") {
                    if (!self::isValidToCopyRepeating($config["test_fields"], $sourceRow, $destData, $sourceChoices, $destChoices)) {
                        if ($isDebug) {
                            Application::log("Not valid to copy repeating for $instrument in dest $destPid $destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid $sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $destPid);
                            Application::log("Not valid to copy repeating for $instrument in dest $destPid $destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid $sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $sourcePid);
                        }
                        $continueToCopyFromSource = FALSE;
                    } else if (self::repeatingAlreadyUploadedToPid($config['test_fields'], $sourceRow, $upload[$destLicensePlate] ?? [], $sourceChoices, $destChoices)) {
                        if ($isDebug) {
                            Application::log("Already uploaded for repeating for $instrument in dest $destPid $destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid $sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $destPid);
                            Application::log("Already uploaded for repeating for $instrument in dest $destPid $destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid $sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $sourcePid);
                        }
                        $continueToCopyFromSource = FALSE;
                    }
                }
                if (
                    $continueToCopyFromSource
                    && ($config["always_copy"]
                        || (
                            in_array($instrument, $sharedFormsForDest)
                            && in_array($instrument, $sharedFormsForSource)
                        )
                    )
                ) {
                    # we're ready to share data from $sourceRow into a new row
                    # functions will adjust the indices of items with choices if their labels match
                    if (
                        ($config["formType"] == "single")
                        && ($sourceRow["redcap_repeat_instrument"] == "")
                    ) {
                        if ($isDebug) {
                            Application::log("copyDataFromRowToNormative for $instrument in dest $destPid:$destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid:$sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $destPid);
                            Application::log("copyDataFromRowToNormative for $instrument in dest $destPid:$destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid:$sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $sourcePid);
                        }
                        $hasChanged = self::copyDataFromRowToNormative($sourceRow,
                            "2",
                            $config["prefix"],
                            $metadataFieldsByPid[$destPid],
                            $sourceChoices,
                            $destChoices,
                            $normativeRow,
                            $instrument);
                        # if we overwrite a normative row, not a big deal - it's hard to see which value is authoritative
                        # it's a bigger deal to keep adding repeating instances
                        if ($hasChanged) {
                            Application::log("uploadNormativeRow for $instrument in dest $destPid:$destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid:$sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $destPid);
                            Application::log("uploadNormativeRow for $instrument in dest $destPid:$destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid:$sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $sourcePid);
                            $uploadNormativeRow = TRUE;
                        }
                    } else if (
                        ($config["formType"] == "repeating")
                        && ($sourceRow["redcap_repeat_instrument"] == $instrument)
                    ) {
                        if ($isDebug) {
                            Application::log("copyDataFromRowToNewRow for $instrument in dest $destPid:$destRecordId:$newInstance ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid:$sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $destPid);
                            Application::log("copyDataFromRowToNewRow for $instrument in dest $destPid:$destRecordId:$newInstance ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid:$sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $sourcePid);
                        }
                        $repeatingRow = self::copyDataFromRowToNewRow($sourceRow,
                            "2",
                            $config["prefix"],
                            $metadataFieldsByPid[$destPid],
                            $sourceChoices,
                            $destChoices,
                            $destRecordId,
                            $instrument,
                            $newInstance);
                        if (is_array($repeatingRow) && !empty($repeatingRow)) {
                            if ($isDebug) {
                                Application::log("add repeatingRow for $instrument in dest $destPid:$destRecordId:$newInstance ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid:$sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $destPid);
                                Application::log("add repeatingRow for $instrument in dest $destPid:$destRecordId:$newInstance ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid:$sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $sourcePid);
                            }
                            $repeatingRows[] = $repeatingRow;
                            $newInstance++;
                        }
                    }
                }
            }
        }
        if (!$uploadNormativeRow && empty($repeatingRows)) {
            # no matches => done
            return;
        }

        # store uploaded data so that we won't upload duplicates
        $destMetadataForms = Download::metadataFormsByPid($destPid);
        if (!isset($upload[$destLicensePlate])) {
            $upload[$destLicensePlate] = [];
        }
        $upload[$destLicensePlate][$sourceLicensePlate] = [];
        if ($uploadNormativeRow) {
            self::clearRowOfSpecialFields($normativeRow, $metadataFieldsByPid[$destPid], $destMetadataForms);
            $upload[$destLicensePlate][$sourceLicensePlate][] = $normativeRow;
        }

        # Last check: a debug field must have a non-blank value to be copied
        $debugField = $forms[$instrument]["debug_field"] ?? "";
        if ($debugField) {
            foreach ($repeatingRows as $prospectiveRow) {
                if (isset($prospectiveRow[$debugField]) && ($prospectiveRow[$debugField] !== "")) {
                    self::clearRowOfSpecialFields($prospectiveRow, $metadataFieldsByPid[$destPid], $destMetadataForms);
                    $upload[$destLicensePlate][$sourceLicensePlate][] = $prospectiveRow;
                }
            }
        } else {
            # no debug field => good to go
            for ($i = 0; $i < count($repeatingRows); $i++) {
                self::clearRowOfSpecialFields($repeatingRows[$i], $metadataFieldsByPid[$destPid], $destMetadataForms);
            }
            $upload[$destLicensePlate][$sourceLicensePlate] = array_merge($upload[$destLicensePlate][$sourceLicensePlate], $repeatingRows);
        }

        if (!empty($upload[$destLicensePlate][$sourceLicensePlate])) {
            self::uploadSharingData($upload[$destLicensePlate][$sourceLicensePlate], $sourceInfo, $destInfo);
            if (!in_array($destPid, $pidsUpdated)) {
                $pidsUpdated[] = $destPid;
            }
        } else {
            Application::log("Skipping uploading for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $sourcePid);
            Application::log("Skipping uploading for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $destPid);
        }
    }

    private static function clearRowOfSpecialFields(&$row, $metadataFields, $metadataForms) {
        foreach (["departments", "resources", "mentoring", "optional", "institutions"] as $fieldType) {
            $fields = REDCapManagement::getSpecialFieldsByFields($fieldType, $metadataFields, $metadataForms);
            foreach ($fields as $field) {
                if (isset($row[$field])) {
                    unset($row[$field]);
                }
            }
        }
    }

    private static function uploadSharingData($uploadRows, $sourceInfo, $destInfo) {
        // Application::log("Uploading for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $destPid);
        $destPid = $destInfo['pid'];
        $destRecordId = $destInfo['record'];
        $sourcePid = $sourceInfo['pid'];
        $sourceRecordId = $sourceInfo['record'];
        try {
            $uploadedInstruments = [];
            foreach ($uploadRows as $row) {
                if (is_string($row['redcap_repeat_instrument']) && $row['redcap_repeat_instrument']) {
                    $uploadedInstruments[] = $row['redcap_repeat_instrument'];
                } else {
                    $uploadedInstruments[] = "Normative Row";
                }
            }
            Upload::rows($uploadRows, $destInfo['token'], $destInfo['server']);
            $instrumentList = implode(", ", $uploadedInstruments);
            Application::log("$destPid: Uploaded ".count($uploadRows)." rows for record $destRecordId from pid $sourcePid record $sourceRecordId: $instrumentList", $destPid);
            Application::log("$destPid: Uploaded ".count($uploadRows)." rows for record $destRecordId from pid $sourcePid record $sourceRecordId: $instrumentList", $sourcePid);
            // Application::log(json_encode($upload[$destLicensePlate][$sourceLicensePlate]), $destPid;
        } catch (\Exception $e) {
            Application::log("ERROR: Could not copy from $sourcePid record $sourceRecordId, into $destPid record $destRecordId", $sourcePid);
            Application::log("ERROR: Could not copy from $sourcePid record $sourceRecordId, into $destPid record $destRecordId", $destPid);
            Application::log($e->getMessage(), $sourcePid);
            Application::log($e->getMessage(), $destPid);
        }
    }

    private static function combineDataWithUploads($redcapData, $uploadArrays) {
        if (empty($uploadArrays)) {
            return $redcapData;
        }
        foreach ($uploadArrays as $licensePlate => $rows) {
            # it doesn't matter if a normative row is duplicated for this case
            $redcapData = array_merge($redcapData, $rows);
        }
        return $redcapData;
    }

    function cron() {
        CronManager::sendExceptionDigests();
	    if (CareerDev::isVanderbilt()) {
	        try {
	            $this->executeCron();
            } catch (\Exception $e) {
                \REDCap::email("scott.j.pearson@vumc.org", "noreply.flighttracker@vumc.org", "Error in Flight Tracker cron", $e->getMessage()."<br><br>".$e->getTraceAsString());
            }
        } else {
	        # Send bugs to REDCap Administrator
            $this->executeCron();
        }
    }

    public function preprocessScholarPortalPersonalData($pids) {
        foreach ($pids as $pid) {
            CareerDev::setPid($pid);
            Application::log("Preprocessing lists for Scholar Portal", $pid);
            $token = $this->getProjectSetting("token", $pid);
            $server = $this->getProjectSetting("server", $pid);
            if ($token && $server) {
                $userids = Download::userids($token, $server);
                $firstNames = Download::firstnames($token, $server);
                $lastNames = Download::lastnames($token, $server);

                $this->setProjectSetting("userids", $userids, $pid);
                $this->setProjectSetting(self::FIRST_NAMES, $firstNames, $pid);
                $this->setProjectSetting(self::LAST_NAMES, $lastNames, $pid);
            }
        }
    }

    public function preprocessScholarPortal($allPids, $pidsToSearch) {
        $today = date("Y-m-d");
        foreach ($pidsToSearch as $pid) {
            Application::log("Preprocessing userids for Scholar Portal", $pid);
            $userids = $this->getProjectSetting("userids", $pid);
            $firstNames = $this->getProjectSetting(self::FIRST_NAMES, $pid);
            $lastNames = $this->getProjectSetting(self::LAST_NAMES, $pid);
            foreach ($userids as $recordId => $userid) {
                if ($userid) {
                    $previousSetting = Application::getSystemSetting($userid) ?: [];
                    $needToUpdate = empty($previousSetting) || !$previousSetting["done"] || ($previousSetting["date"] != $today);
                    if ($needToUpdate) {
                        $firstName = $firstNames[$recordId] ?? "";
                        $lastName = $lastNames[$recordId] ?? "";
                        list($matches, $projectTitles, $photoBase64) = Portal::getMatchesForUserid($userid, $firstName, $lastName, $allPids);
                        $storedData = [
                            "date" => $today,
                            "matches" => $matches,
                            "projectTitles" => $projectTitles,
                            "photo" => $photoBase64,
                            "done" => TRUE,
                        ];
                        Application::saveSystemSetting($userid, $storedData);
                    }
                }
            }
            Application::log("Done preprocessing", $pid);
        }
    }

	function executeCron() {
        Application::increaseProcessingMax(8);

		$this->setupApplication();
		if (isset($_GET['pid']) && Application::isVanderbilt()) {
            $activePids = [Sanitizer::sanitizePid($_GET['pid'])];
        } else {
            $activePids = $this->framework->getProjectsWithModuleEnabled();
            if (Application::isVanderbilt() && Application::isServer("redcap.vanderbilt.edu")) {
                $activePids[] = NEWMAN_SOCIETY_PROJECT;
            }
        }
		Application::log($this->getName()." running for pids ".json_encode($activePids));
        foreach ($activePids as $pid) {
            Application::log("Flight Tracker 'midnight cron' running", $pid);
            if (Application::isMSTP($pid)) {
                Application::saveSetting(MSTP::REMINDER_SETTING, "", $pid);
            }
        }

        $this->enqueueInitialCrons($activePids);
        $this->enqueueMultiProjectCrons($activePids);

		foreach ($activePids as $pid) {
            $this->cleanupLogs($pid);
            $token = $this->getProjectSetting("token", $pid);
            $server = $this->getProjectSetting("server", $pid);
            $tokenName = $this->getProjectSetting("tokenName", $pid);
            $adminEmail = $this->getProjectSetting("admin_email", $pid);
            $turnOffSet = $this->getProjectSetting("turn_off", $pid);
            $GLOBALS['namesForMatch'] = [];
            CareerDev::setPid($pid);
            Application::log("Using $tokenName $adminEmail", $pid);
            if ($token && $server && !$turnOffSet) {
                try {
                    # only have token and server in initialized projects
                    if ($this->getProjectSetting("run_tonight", $pid)) {
                        $this->setProjectSetting("run_tonight", FALSE, $pid);
                        # already done in enqueueInitialCrons
                    } else {
                        $regularManager = new CronManager($token, $server, $pid, $this, "");
                        loadCrons($regularManager, FALSE, $token, $server);

                        $intenseManager = new CronManager($token, $server, $pid, $this, self::INTENSE_BATCH_SUFFIX);
                        loadIntenseCrons($intenseManager, FALSE, $token, $server);
                    }
                    Application::log($this->getName().": $tokenName enqueued crons", $pid);
                } catch(\Exception $e) {
                    Application::log("Error in cron logic", $pid);
                    \REDCap::email($adminEmail, Application::getSetting("default_from", $pid), Application::getProgramName()." Error in Cron", $e->getMessage());
                }
            }
		}
	}

    function enqueueMultiProjectCrons($pids) {
        if (!empty($pids)) {
            $standbyToken = "";
            $standbyServer = "";
            $standbyPid = "";
            for ($i = 0; $i < count($pids); $i++) {
                $pid = $pids[$i];
                $token = $this->getProjectSetting("token", $pid);
                $server = $this->getProjectSetting("server", $pid);
                $longManager = new CronManager($token, $server, $pid, $this, self::LONG_RUNNING_BATCH_SUFFIX);
                loadLongRunningCrons($longManager, $token, $server, $pid);
                if (!$standbyPid && !$standbyServer && !$standbyToken && $token && $server && $pid) {
                    $standbyServer = $server;
                    $standbyToken = $token;
                    $standbyPid = $pid;
                }
            }
            if ($standbyPid && $standbyServer && $standbyToken) {
                $longManager = new CronManager($standbyToken, $standbyServer, $standbyPid, $this, self::LONG_RUNNING_BATCH_SUFFIX);
                loadMultiProjectCrons($longManager, $pids);
            }
        }
    }

	function enqueueInitialCrons($pids) {
        foreach ($pids as $pid) {
            $token = $this->getProjectSetting("token", $pid);
            $server = $this->getProjectSetting("server", $pid);
            $tokenName = $this->getProjectSetting("tokenName", $pid);
            $adminEmail = $this->getProjectSetting("admin_email", $pid);
            $turnOffSet = $this->getProjectSetting("turn_off", $pid);
            if ($token && $server && !$turnOffSet) {
                try {
                    if ($this->getProjectSetting("run_tonight", $pid)) {
                        $initialCronManager = new CronManager($token, $server, $pid, $this, "");
                        loadInitialCrons($initialCronManager, FALSE, $token, $server);
                        Application::log($this->getName().": $tokenName enqueued initial crons", $pid);
                    }
                } catch (\Exception $e) {
                    Application::log("Error in initial cron logic", $pid);
                    \REDCap::email($adminEmail, Application::getSetting("default_from", $pid), Application::getProgramName() . " Error in Initial Cron", $e->getMessage());
                }
            }
        }
	}

	function getProjectsToRunTonight($pids) {
	    $toRun = [];
        foreach ($pids as $pid) {
            if ($this->getProjectSetting("run_tonight", $pid)) {
                $toRun[] = $pid;
            }
        }
        return $toRun;
    }

	function hasProjectToRunTonight($pids) {
	    return !empty($this->getProjectsToRunTonight($pids));
    }

	function setupApplication() {
		CareerDev::$passedModule = $this;
	}

	function getUsername() {
        if (method_exists("\ExternalModules\ExternalModules", "getUsername")) {
            return ExternalModules::getUsername();
        }
        if (USERID) {
            return USERID;
        }
        return "";
    }

	function redcap_module_link_check_display($project_id, $link) {
	    $url = $link['url'];
        $userid = $this->getUsername();
        # hide mentor links by returning $emptyLink
        $isMentorPage = preg_match("/mentor/", $url);
        $emptyLink = [
            "name" => "",
            "icon" => "",
			"url" => "",
        ];

        if (Application::isSuperUser()) {
            if (!$isMentorPage) {
                return $link;
            } else {
                return $emptyLink;
            }
		}

		if (empty($project_id)) {
		    return null;
        }

        if ($this->hasAppropriateRights($userid, $project_id)) {
            if (!$isMentorPage) {
                return $link;
            } else {
                return $emptyLink;
            }
		}

        if ($isMentorPage) {
            $params = REDCapManagement::getParameters($url);
            if (preg_match("/^mentor\//", urldecode($params['page'])) && $this->hasMentorAgreementRights($project_id, $userid)) {
                return $emptyLink;
            }
        }

        return NULL;
	}

	function redcap_every_page_before_render($project_id) {
		$this->setupApplication();
        if (Application::isTable1Project($project_id)) {
        } else if (PAGE == "DataExport/index.php") {
			echo "<script src='".CareerDev::link("/js/jquery.min.js")."'></script>\n";
			echo "<script src='".CareerDev::link("/js/colorCellFunctions.js")."'></script>\n";
			echo "
<script>
	$(document).ready(function() { setTimeout(function() { transformColumn(); }, 500); });
</script>";
        } else if (PAGE == "ProjectSetup/index.php") {
            echo "<script src='".CareerDev::link("/js/jquery.min.js")."'></script>\n";
            echo "
<script>
    $(document).ready(function() {
        $('.chklist.round:eq(6)')
            .hide()
            .after('<p>By design, Flight Tracker projects should not move to production status because they need to update their Data Dictionaries without manual review. This must be done in development mode. Therefore, moving to production is disabled.</p>')
    });
</script>\n";
        } else if (PAGE == "DataEntry/index.php") {
            echo "<script src='".CareerDev::link("/js/jquery.min.js")."'></script>\n";
            echo "<script>
    (function(proxied) {
        confirm = function() {
            if (!arguments[0].match(/ERASE THE VALUE OF THE FIELD/)) {
                return proxied.apply(this, arguments);
            }
        };
    })(confirm);
    $(document).ready(() => {
        const ob = $('[data-rc-lang=data_entry_532]').parent().parent();
        const id = ob.attr('id');
        if (id !== 'center') {
            // hide development mode note, but some systems put this under #center
            ob.hide();
        }
    });
</script>";
            if (Application::isMSTP($project_id) && ($_GET['page'] == "mstp_mentee_mentor_agreement")) {
                $token = Application::getSetting("token", $project_id);
                $server = Application::getSetting("server", $project_id);
                $records = Download::recordIds($token, $server);
                $record = Sanitizer::getSanitizedRecord($_GET['id'], $records);
                $instance = Sanitizer::sanitizeInteger($_GET['instance'] ?? 1);
                if ($record && $instance) {
                    $viewLink = Application::link("mstp/downloadMMA.php")."&record=$record&instance=$instance";
                    echo "
<script>
	$(document).ready(function() {
        $('#mstpmma_document_html-tr td.data').append('<div style=\"text-align: right;\"><a href=\"$viewLink\">View Document</a></div>')
	 });
</script>";
                }
            }
        }
	}

	function redcap_every_page_top($project_id) {
        $this->setupApplication();
        if (
            Application::isTable1Project($project_id)
            || Application::isSocialMediaProject($project_id)
        ) {
            # Do nothing
        } else if ($project_id && Application::getUsername()) {
            $tokenName = $this->getProjectSetting("tokenName", $project_id);
            $token = $this->getProjectSetting("token", $project_id);
            $server = $this->getProjectSetting("server", $project_id);
            if ($tokenName && $token && $server) {
                # turn off for surveys and login pages
                $url = $_SERVER['PHP_SELF'] ?? "";
                if (
                    !preg_match("/surveys/", $url)
                    && !isset($_GET['s'])
                    && class_exists('\Vanderbilt\CareerDevLibrary\NavigationBar')
                ) {
                    header("Cross-Origin-Resource-Policy: cross-origin");
                    header("Access-Control-Allow-Origin: *");
                    echo $this->makeHeaders($token, $server, $project_id, $tokenName);
                }
                if (preg_match("/online_designer\.php/", $url)) {
                    $_SESSION['metadata'.$project_id] = [];
                    $_SESSION['lastMetadata'.$project_id] = 0;
                } else if (PAGE == "DataEntry/record_status_dashboard.php") {
                    include(__DIR__."/hooks/recordStatusDashboardHook.php");
                } else if (PAGE == "DataEntry/record_home.php") {
                    include(__DIR__."/hooks/dataFormHook.php");
                }
            } else {
                if ($this->canRedirectToInstall()) {
                    header("Location: ".$this->getUrl("install.php"));
                }
            }
        }
	}

    function redcap_survey_acknowledgement_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
        $this->setupApplication();
        if ($instrument == "mstp_individual_development_plan_idp") {
            require_once(dirname(__FILE__) . "/hooks/mstpIDPAcknowledgementHook.php");
        }
    }

	function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
        $this->setupApplication();
        if (Application::isTable1Project($project_id)) {
            return;
        } else if ($instrument == "summary") {
			require_once(dirname(__FILE__)."/hooks/summaryHook.php");
		} else if (in_array($instrument, ["initial_survey", "initial_short_survey"])) {
			require_once(dirname(__FILE__)."/hooks/checkHook.php");
        } else if ($instrument == "followup") {
            require_once(dirname(__FILE__)."/hooks/followupHook.php");
        } else if (in_array($instrument, ["honors_awards_and_activities", "honors_awards_and_activities_survey"])) {
            require_once(dirname(__FILE__)."/hooks/honorHook.php");
		} else if ($instrument == "mstp_individual_development_plan_idp") {
            require_once(dirname(__FILE__)."/hooks/mstpIDPFormHook.php");
        }
		require_once(dirname(__FILE__)."/hooks/setDateHook.php");
	}

	function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
        if (Application::isTable1Project($project_id)) {
            return;
        }
        $formsToSkip = [
            "honors_awards_and_activities",
            "honors_awards_and_activities_survey",
            "mstp_individual_development_plan_idp",
            "mstp_mentee_mentor_agreement",
        ];
        if (in_array($instrument, $formsToSkip)) {
            return;
        }
        require_once(dirname(__FILE__)."/hooks/saveHook.php");
	}

	function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
		$this->setupApplication();
        $pid = $project_id;
        $token = Application::getSetting("token", $pid);
        $server = Application::getSetting("server", $pid);
        if (Application::isTable1Project($project_id)) {
            require_once(dirname(__FILE__) . "/hooks/table1SurveyHook.php");
        } else if ($instrument == "summary") {
			require_once(dirname(__FILE__)."/hooks/summaryHook.php");
		} else if (in_array($instrument, ["initial_survey", "initial_short_survey"])) {
			require_once(dirname(__FILE__)."/hooks/checkHook.php");
		} else if ($instrument == "followup") {
			require_once(dirname(__FILE__)."/hooks/followupHook.php");
		} else if ($instrument == "mstp_individual_development_plan_idp") {
            require_once(dirname(__FILE__) . "/hooks/mstpIDPHook.php");
        }
	}

    function hasMentorAgreementRights($project_id, $userid)
    {
        $token = $this->getProjectSetting("token", $project_id);
        $server = $this->getProjectSetting("server", $project_id);
        $menteeRecord = FALSE;
        if (isset($_REQUEST['menteeRecord'])) {
            $menteeRecord = REDCapManagement::sanitize($_REQUEST['menteeRecord']);
        } else if (isset($_REQUEST['record'])) {
            $menteeRecord = REDCapManagement::sanitize($_REQUEST['record']);
        } else if (method_exists("\Vanderbilt\CareerDevLibrary\MMAHelper", "getRecordsAssociatedWithUserid")) {
            $records = MMAHelper::getRecordsAssociatedWithUserid($userid, $token, $server);
            if (isset($_GET['test'])) {
                Application::log("Got records ".json_encode($records));
            }
            if (!empty($records)) {
                return TRUE;
            }
        }
        $validUserids = [];
        if ($menteeRecord) {
            $mentorUserids = Download::primaryMentorUserids($token, $server);
            $menteeUserids = Download::userids($token, $server);
            if (!$menteeUserids[$menteeRecord]) {
                $menteeUserids[$menteeRecord] = [];
            } else {
                $menteeUserids[$menteeRecord] = preg_split("/\s*[,;]\s*/", $menteeUserids[$menteeRecord]);
            }
            if (!isset($mentorUserids[$menteeRecord])) {
                $mentorUserids[$menteeRecord] = [];
            }
            $validUserids = array_unique(array_merge($validUserids, $menteeUserids[$menteeRecord], $mentorUserids[$menteeRecord]));
        }

        if (MMAHelper::getMMADebug()) {
            Application::log("Comparing $userid to " . json_encode($validUserids));
        }
        if (in_array($userid, $validUserids)) {
            return TRUE;
        }
        if (MMAHelper::getMMADebug() && isset($_GET['uid']) && in_array($_GET['uid'], $validUserids)) {
            return TRUE;
        }
        return FALSE;
    }

    function makeHeaders($token, $server, $pid, $tokenName) {
        if (isset($_GET['prefix']) && ($_GET['prefix'] != self::getPrefix())) {
            # another ExtMod - sometimes, scripts don't work well; e.g., jquery declared twice
            return "";
        }
	    $str = "";
	    $str .= Application::getHeader($tokenName, $token, $server, $pid);
        if (!CareerDev::isFAQ() && CareerDev::isHelpOn()) {
            $currPage = CareerDev::getCurrPage();
            $str .= "<script>$(document).ready(function() { showHelp('".CareerDev::getHelpLink()."', '".$currPage."'); });</script>";
        }
		if (!CareerDev::isREDCap()) {
		    $str .= Application::getFooter();
		}
	
		if (!CareerDev::isFAQ()) {
			$str .= "<div class='shadow' id='help'></div>\n";
		}

		return $str;
	}

	public function getBrandLogoName() {
		return "brand_logo";
	}

	public function getBrandLogo() {
		return $this->getProjectSetting($this->getBrandLogoName(), $_GET['pid']);
	}

	public function canRedirectToInstall() {
        $page = (isset($_GET['page']) && !is_array($_GET['page'])) ? $_GET['page'] : "";
		$bool = (
            !self::isAJAXPage()
            && !self::isAPITokenPage()
            && !self::isUserRightsPage()
            && !self::isExternalModulePage()
            && (!$page || ($_GET['page'] != "install"))
            && (!$page || !preg_match("/^projects/", $page))
        );
		if ($_GET['pid']) {
			# project context
			$bool = $bool && $this->hasAppropriateRights(USERID, $_GET['pid']);
		}
		return $bool;
	}

	private static function isAJAXPage() {
		$page = $_SERVER['PHP_SELF'] ?? "";
		if (preg_match("/ajax/", $page)) {
			return TRUE;
		}
		if (preg_match("/index.php/", $page) && isset($_GET['route'])) {
			return TRUE;
		}
		return FALSE;
	}

	private static function isAPITokenPage() {
		$page = $_SERVER['PHP_SELF'] ?? "";
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
		$page = $_SERVER['PHP_SELF'] ?? "";
		if (preg_match("/\/UserRights\//", $page)) {
			return TRUE;
		}
		return FALSE;
	}

	private static function isExternalModulePage() {
		$page = $_SERVER['PHP_SELF'] ?? "";
		if (preg_match("/ExternalModules\/manager\/project.php/", $page)) {
			return TRUE;
		}
		if (preg_match("/ExternalModules\/manager\/ajax\//", $page)) {
			return TRUE;
		}
		if (preg_match("/external_modules\/manager\/project.php/", $page)) {
			return TRUE;
		}
		if (preg_match("/external_modules\/manager\/ajax\//", $page)) {
			return TRUE;
		}
		return FALSE;
	}

	public function getDirectoryPrefix() {
	    return $this->getPrefix();
    }

	private function isModuleEnabled($pid) {
		return ExternalModules::getProjectSetting($this->getDirectoryPrefix(), $pid, \ExternalModules\ExternalModules::KEY_ENABLED);
	}

	private function hasAppropriateRights($userid, $pid) {
		$sql = "SELECT design, role_id FROM redcap_user_rights WHERE project_id = ? AND username = ?";
		$q =  $this->query($sql, [$pid, $userid]);
		$roleId = FALSE;
		if ($row = $q->fetch_assoc()) {
			if ($row['design']) {
			    return TRUE;
            }
			$roleId = $row['role_id'];
		}
		if ($roleId) {
		    $sql = "SELECT roles.design AS design FROM redcap_user_rights AS rights INNER JOIN redcap_user_roles AS roles ON rights.role_id = roles.role_id WHERE rights.project_id = ? AND rights.username = ?";
            $q =  $this->query($sql, [$pid, $userid]);
            if ($row = $q->fetch_assoc()) {
                if ($row['design']) {
                    return TRUE;
                }
            }
        }

		return FALSE;
	}

	private $name = "Flight Tracker";
    public $cronManager = NULL;
}
