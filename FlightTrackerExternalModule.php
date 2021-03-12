<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\CronManager;
use Vanderbilt\CareerDevLibrary\EmailManager;
use Vanderbilt\CareerDevLibrary\NavigationBar;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\NameMatcher;
use Vanderbilt\CareerDevLibrary\Upload;

require_once(dirname(__FILE__)."/CareerDev.php");
require_once(dirname(__FILE__)."/classes/Crons.php");
require_once(dirname(__FILE__)."/classes/EmailManager.php");
require_once(dirname(__FILE__)."/classes/NavigationBar.php");
require_once(dirname(__FILE__)."/classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/classes/Download.php");
require_once(dirname(__FILE__)."/classes/NameMatcher.php");
require_once(dirname(__FILE__)."/cronLoad.php");
require_once(APP_PATH_DOCROOT."Classes/System.php");

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
        $activePids = REDCapManagement::getActiveProjects($pids);
        // CareerDev::log($this->getName()." sending emails for pids ".json_encode($pids));
		foreach ($activePids as $pid) {
			if (REDCapManagement::isActiveProject($pid)) {
				$token = $this->getProjectSetting("token", $pid);
				$server = $this->getProjectSetting("server", $pid);
				if ($token && $server) {
                    $tokenName = $this->getProjectSetting("tokenName", $pid);
                    $adminEmail = $this->getProjectSetting("admin_email", $pid);
                    $cronStatus = $this->getProjectSetting("send_cron_status", $pid);
                    if ($cronStatus) {
                        $mgr = new CronManager($token, $server, $pid);
                        loadTestingCrons($mgr);
                        $mgr->run($adminEmail, $tokenName);
                    }

                    $mgr = new EmailManager($token, $server, $pid, $this);
                    $mgr->sendRelevantEmails();
                }
			}
		}
	}

	# returns a boolean; modifies $normativeRow
	private static function copyDataFromRowToNormative($sourceRow, $completeValue, $prefix, $metadataFields, $choices, &$normativeRow, $instrument) {
	    $hasChanged = self::copyDataFromRowToRow($sourceRow, $prefix, $metadataFields, $choices, "", $normativeRow);
	    if ($hasChanged) {
	        $normativeRow[$instrument."_complete"] = $completeValue;
        }
	    return $hasChanged;
    }

    # returns a boolean; modifies $destRow
    private static function copyDataFromRowToRow($sourceRow, $prefix, $metadataFields, $choices, $instrument, &$destRow) {
	    $hasChanged = FALSE;
        if ($sourceRow["redcap_repeat_instrument"] == $instrument) {
            foreach ($sourceRow as $sourceField => $sourceValue) {
                if (preg_match("/^$prefix/", $sourceField)
                    && in_array($sourceField, $metadataFields)
                    && REDCapManagement::isValidChoice($sourceValue, $choices[$sourceField])) {
                    $destRow[$sourceField] = $sourceValue;
                    $hasChanged = TRUE;
                }
            }
        }
	    return $hasChanged;
    }

    # returns a row if data can be copied; otherwise returns FALSE
    private static function copyDataFromRowToNewRow($sourceRow, $completeValue, $prefix, $metadataFields, $choices, $recordId, $instrument, $newInstance) {
	    $newRow = [
	        "record_id" => $recordId,
            "redcap_repeat_instrument" => $instrument,
            "redcap_repeat_instance" => $newInstance,
            $instrument."_complete" => $completeValue,
        ];
	    $hasChanged = self::copyDataFromRowToRow($sourceRow, $prefix, $metadataFields, $choices, $instrument, $newRow);
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

	public function cleanupLogs($pid)
    {
        CareerDev::log("Cleaning up logs for $pid");
        $daysPrior = 28;
        $this->cleanupExtModLogs($pid, $daysPrior);
    }

    public function cleanupExtModLogs($pid, $daysPrior) {
        $ts = time() - $daysPrior * 24 * 3600;
        $thresholdTs = date("Y-m-d", $ts);
        $this->removeLogs("timestamp <= '$thresholdTs' AND project_id = '$pid'");
    }

    private static function isValidToCopy($fields, $sourceRow, $destRow, $sourceChoices, $destChoices) {
        if ((count($fields) == 1) && self::fieldBlank($fields[0], $sourceRow)) {
            # one blank field => not valid enough to copy
            // Application::log("isValidToCopy Rejecting because one field blank: ".json_encode($fields));
            return FALSE;
        } else if (self::allFieldsMatch($fields, $sourceRow, $destRow, $sourceChoices, $destChoices)) {
            # already copied => skip
            // Application::log("isValidToCopy Rejecting because all fields match");
            return FALSE;;
        }
        // Application::log("isValidToCopy returning TRUE");
        return TRUE;
    }

    private static function getSharingInformation() {
        return [
            "initial_survey" => ["prefix" => "check", "formType" => "single", "test_fields" => ["check_date"], "always_copy" => TRUE, ],
            "followup" => ["prefix" => "followup", "formType" => "repeating", "test_fields" => ["followup_date"], "always_copy" => TRUE, ],
            "position_change" => [ "prefix" => "promotion", "formType" => "repeating", "test_fields" => ["promotion_job_title", "promotion_date"], "always_copy" => FALSE, ],
            "resources" => [ "prefix" => "resources", "formType" => "repeating", "test_fields" => ["resources_date", "resources_resource"], "always_copy" => FALSE, ],
            "honors_and_awards" => [ "prefix" => "honor", "formType" => "repeating", "test_fields" => ["honor_name", "honor_date"], "always_copy" => FALSE, ],
        ];
    }

    public static function getConfigurableForms() {
	    $forms = self::getSharingInformation();
	    $formsForCopy = [];
	    foreach ($forms as $instrument => $config) {
            if (!$config["always_copy"]) {
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

	public function shareDataInternally($pids) {
	    # all test_fields must be equal and, if by self (i.e., list of one), non-blank
        $forms = self::getSharingInformation();
	    $firstNames = [];
        $lastNames = [];
        $completes = [];
        $servers = [];
        $tokens = [];
        $metadataFields = [];
        $choices = [];
        $pidsUpdated = [];

        foreach ($pids as $pid) {
            // Application::log("Getting project data for pids: ".json_encode($pids), $pid);
        }
	    foreach ($pids as $pid) {
	        Application::log("Getting project data for pid ".$pid, $pid);
            $token = $this->getProjectSetting("token", $pid);
            $server = $this->getProjectSetting("server", $pid);
            if ($token && $server) {
                Application::log("Got token with length of ".strlen($token)." for pid $pid", $pid);
                $tokens[$pid] = $token;
                $servers[$pid] = $server;
            }
        }
        $credentialsFile = "/app001/credentials/career_dev/credentials.php";
        if (preg_match("/redcap.vanderbilt.edu/", SERVER_NAME)  && file_exists($credentialsFile)) {
            include($credentialsFile);
            if (isset($info)) {
                $prodPid = $info["prod"]["pid"];
                $prodToken = $info["prod"]["token"];
                $prodServer = $info["prod"]["server"];
                $pids[] = $prodPid;
                $tokens[$prodPid] = $prodToken;
                $servers[$prodPid] = $prodServer;
                Application::log("Searching through Vanderbilt Master Project ($prodPid)", $prodPid);
            }
        }

        foreach ($pids as $pid) {
            if ($tokens[$pid] && $servers[$pid]) {
                Application::log("Downloading data for pid $pid", $pid);
                $token = $tokens[$pid];
                $server = $servers[$pid];
                $firstNames[$pid] = Download::firstnames($token, $server);
                $lastNames[$pid] = Download::lastnames($token, $server);
                $metadata = Download::metadata($token, $server);
                $choices[$pid] = REDCapManagement::getChoices($metadata);
                $metadataFields[$pid] = REDCapManagement::getFieldsFromMetadata($metadata);
                foreach (array_keys($forms) as $instrument) {
                    $field = $instrument."_complete";
                    if (!isset($completes[$instrument])) {
                        $completes[$instrument] = [];
                    }
                    $completes[$instrument][$pid] = Download::oneField($token, $server, $field);
                }
            }
        }

	    # push
	    foreach ($pids as $sourcePid) {
	        Application::log("Searching through pid $sourcePid", $sourcePid);
	        if ($tokens[$sourcePid] && $servers[$sourcePid]) {
                $sourceToken = $tokens[$sourcePid];
                $sourceServer = $servers[$sourcePid];
                foreach ($pids as $destPid) {
                    if (($destPid != $sourcePid) && $tokens[$destPid] && $servers[$destPid]) {
                        Application::log("Communicating between $sourcePid and $destPid", $destPid);
                        $destToken = $tokens[$destPid];
                        $destServer = $servers[$destPid];
                        foreach (array_keys($firstNames[$destPid]) as $destRecordId) {
                            $combos = [];
                            foreach (NameMatcher::explodeFirstName($firstNames[$destPid][$destRecordId]) as $firstName) {
                                foreach (NameMatcher::explodeLastName($lastNames[$destPid][$destRecordId]) as $lastName) {
                                    if ($firstName && $lastName) {
                                        $combos[] = ["first" => $firstName, "last" => $lastName];
                                    }
                                }
                            }
                            foreach ($combos as $nameAry) {
                                $firstName = $nameAry["first"];
                                $lastName = $nameAry["last"];
                                // CareerDev::log("Searching for $firstName $lastName from $destPid in $sourcePid");
                                if ($sourceRecordId = NameMatcher::matchName($firstName, $lastName, $sourceToken, $sourceServer)) {
                                    CareerDev::log("Match in above: source ($sourcePid, $sourceRecordId) to dest ($destPid, $destRecordId)");

                                    $sourceInfo = [
                                        "token" => $sourceToken,
                                        "server" => $sourceServer,
                                        "pid" => $sourcePid,
                                        "record" => $sourceRecordId,
                                    ];
                                    $destInfo = [
                                        "token" => $destToken,
                                        "server" => $destServer,
                                        "pid" => $destPid,
                                        "record" => $destRecordId,
                                    ];
                                    $this->copyFormData($completes, $pidsUpdated, $forms, $sourceInfo, $destInfo, $metadataFields, $choices);
                                    $this->copyWranglerData($pidsUpdated, $sourceInfo, $destInfo);
                                    break; // combos foreach
                                    # if more than one match, match only first name matched
                                }
                            }
                        }
                    }
                }
            }
        }
	    return $pidsUpdated;
	}

	public function copyWranglerData(&$pidsUpdated, $sourceInfo, $destInfo) {
	    $hasCopiedPubData = $this->copyPubData($sourceInfo, $destInfo);
	    $hasCopiedGrantData = $this->copyGrantData($sourceInfo, $destInfo);
	    if ($hasCopiedGrantData || $hasCopiedPubData) {
	        $pidsUpdated[] = $destInfo['pid'];
        }
    }

    private function copyPubData($sourceInfo, $destInfo) {
	    $fields = ['record_id', 'citation_pmid', 'citation_include'];
        $sourceData = Download::fieldsForRecords($sourceInfo['token'], $sourceInfo['server'], $fields, [$sourceInfo['record']]);
        $destData = Download::fieldsForRecords($destInfo['token'], $destInfo['server'], $fields, [$destInfo['record']]);
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

    private static function getToImport($token, $server, $recordId) {
        $fields = ["record_id", "summary_calculate_to_import"];
        $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
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

    private function copyGrantData($sourceInfo, $destInfo) {
	    # ??? Custom Grants

	    $sourceToImport = self::getToImport($sourceInfo['token'], $sourceInfo['server'], $sourceInfo['record']);
        if (!empty($sourceToImport)) {
            $destToImport = self::getToImport($destInfo['token'], $destInfo['server'], $destInfo['record']);
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

	private function copyFormData(&$completes, &$pidsUpdated, $forms, $sourceInfo, $destInfo, $metadataFieldsByPid, $choicesByPid) {
        $sourceToken = $sourceInfo['token'];
        $sourceServer = $sourceInfo['server'];
        $sourcePid = $sourceInfo['pid'];
        $sourceRecordId = $sourceInfo['record'];
        $destToken = $destInfo['token'];
        $destServer = $destInfo['server'];
        $destPid = $destInfo['pid'];
        $destRecordId = $destInfo['record'];

        $markedAsComplete = [2];
        $sharedFormsForSource = $this->getProjectSetting("shared_forms", $sourcePid);
        if (!$sharedFormsForSource) {
            $sharedFormsForSource = [];
        }
        $sharedFormsForDest = $this->getProjectSetting("shared_forms", $destPid);
        if (!$sharedFormsForDest) {
            $sharedFormsForDest = [];
        }

        $normativeRow = [
            "record_id" => $destRecordId,
            "redcap_repeat_instrument" => "",
            "redcap_repeat_instance" => "",
        ];
        foreach (array_keys($forms) as $instrument) {
            $field = $instrument . "_complete";
            $completes[$instrument][$destPid] = Download::oneField($destToken, $destServer, $field);
            $completes[$instrument][$sourcePid] = Download::oneField($sourceToken, $sourceServer, $field);
        }
        $uploadNormativeRow = FALSE;
        $repeatingRows = [];
        $sourceData = [];
        $destData = [];
        foreach ($completes as $instrument => $completeData) {
            if (!in_array($completeData[$destPid][$destRecordId], $markedAsComplete)
                && in_array($completeData[$sourcePid][$sourceRecordId], $markedAsComplete)) {
                # copy over from source to dest and mark as same as $projectData[$sourceRecordId]
                // CareerDev::log("Matched complete for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId]);
                if (empty($sourceData) || empty($destData)) {
                    $sourceData = Download::records($sourceToken, $sourceServer, array($sourceRecordId));
                    $destData = Download::records($destToken, $destServer, array($destRecordId));
                }
                $config = $forms[$instrument];
                $newInstance = REDCapManagement::getMaxInstance($destData, $instrument, $destRecordId) + 1;
                foreach ($sourceData as $sourceRow) {
                    $continueToCopyFromSource = TRUE;
                    foreach ($destData as $destRow) {
                        if ($config["formType"] == "single") {
                            if ($destRow["redcap_repeat_instrument"] == "") {
                                if (!self::isValidToCopy($config["test_fields"], $sourceRow, $destRow, $choicesByPid[$sourcePid], $choicesByPid[$destPid])) {
                                    // CareerDev::log("Not valid to copy single for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId]);
                                    $continueToCopyFromSource = FALSE;
                                }
                            }
                        } else if ($config["formType"] == "repeating") {
                            if ($destRow["redcap_repeat_instrument"] == $instrument) {
                                if (!self::isValidToCopy($config["test_fields"], $sourceRow, $destRow, $choicesByPid[$sourcePid], $choicesByPid[$destPid])) {
                                    // CareerDev::log("Not valid to repeating single for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId]);
                                    $continueToCopyFromSource = FALSE;
                                }
                            }
                        }
                    }
                    if ($continueToCopyFromSource
                        && ($config["always_copy"]
                            || (in_array($instrument, $sharedFormsForDest) && in_array($instrument, $sharedFormsForSource)))) {
                        if ($config["formType"] == "single") {
                            if ($sourceRow["redcap_repeat_instrument"] == "") {
                                // CareerDev::log("copyDataFromRowToNormative for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId]);
                                $hasChanged = self::copyDataFromRowToNormative($sourceRow,
                                    $completeData[$sourcePid][$sourceRecordId],
                                    $config["prefix"],
                                    $metadataFieldsByPid[$destPid],
                                    $choicesByPid[$destPid],
                                    $normativeRow,
                                    $instrument);
                                if ($hasChanged) {
                                    // CareerDev::log("uploadNormativeRow for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId]);
                                    $uploadNormativeRow = TRUE;
                                }
                            }
                        } else if ($config["formType"] == "repeating") {
                            if ($sourceRow["redcap_repeat_instrument"] == $instrument) {
                                // CareerDev::log("copyDataFromRowToNewRow for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId]);
                                $repeatingRow = self::copyDataFromRowToNewRow($sourceRow,
                                    $completeData[$sourcePid][$sourceRecordId],
                                    $config["prefix"],
                                    $metadataFieldsByPid[$destPid],
                                    $choicesByPid[$destPid],
                                    $destRecordId,
                                    $instrument,
                                    $newInstance);
                                if ($repeatingRow && is_array($repeatingRow)) {
                                    // CareerDev::log("add repeatingRow for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId]);
                                    $repeatingRows[] = $repeatingRow;
                                    $newInstance++;
                                }
                            }
                        }
                    }
                }
                $upload = [];
                if ($uploadNormativeRow) {
                    $upload[] = $normativeRow;
                }
                $upload = array_merge($upload, $repeatingRows);
                if (!empty($upload)) {
                    // Application::log("Uploading for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $destPid);
                    try {
                        $feedback = Upload::rows($upload, $destToken, $destServer);
                        // Application::log("$destPid: Uploaded ".count($upload)." rows for record $destRecordId from pid $sourcePid record $sourceRecordId", $destPid);
                        // Application::log(json_encode($upload), $destPid;
                        if (!in_array($destPid, $pidsUpdated)) {
                            $pidsUpdated[] = $destPid;
                        }
                    } catch (\Exception $e) {
                        Application::log("ERROR: Could not copy from $sourcePid record $sourceRecordId, into $destPid record $destRecordId");
                        Application::log($e->getMessage());
                    }
                } else {
                    // Application::log("Skipping uploading for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $destPid);
                }
            } else {
                // Application::log("Could not match complete for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $destPid);
            }
        }
    }

	function cron() {
        \System::increaseMaxExecTime(28800);   // 8 hours

		$this->setupApplication();
        $pids = $this->framework->getProjectsWithModuleEnabled();
		CareerDev::log($this->getName()." running for pids ".json_encode($pids));
		$activePids = REDCapManagement::getActiveProjects($pids);
		$pidsUpdated = [];
        CareerDev::log("Checking for redcaptest in ".SERVER_NAME);
        if (preg_match("/redcaptest.vanderbilt.edu/", SERVER_NAME)) {
            CareerDev::log("Sharing because redcaptest");
            try {
                $pidsUpdated = $this->shareDataInternally($activePids);
            } catch (\Exception $e) {
                if (count($activePids) > 0) {
                    \REDCap::email("scott.j.pearson@vumc.org", Application::getSetting("default_from", $activePids[0]), "Error in sharing surveys on redcaptest", $e->getMessage());
                }
            }
        } else if (date("N") == "6") {
            # only on Saturdays
            $pidsUpdated = $this->shareDataInternally($activePids);
        }
		foreach ($activePids as $pid) {
            $this->cleanupLogs($pid);
            $token = $this->getProjectSetting("token", $pid);
            $server = $this->getProjectSetting("server", $pid);
            $tokenName = $this->getProjectSetting("tokenName", $pid);
            $adminEmail = $this->getProjectSetting("admin_email", $pid);
            $turnOffSet = $this->getProjectSetting("turn_off", $pid);
            $GLOBALS['namesForMatch'] = array();
            CareerDev::setPid($pid);
            CareerDev::log("Using $tokenName $adminEmail", $pid);
            if ($token && $server && !$turnOffSet) {
                # only have token and server in initialized projects
                $mgr = new CronManager($token, $server, $pid);
                if ($this->getProjectSetting("run_tonight", $pid)) {
                    $this->setProjectSetting("run_tonight", FALSE, $pid);
                    loadInitialCrons($mgr, FALSE, $token, $server);
                } else {
                    loadCrons($mgr, FALSE, $token, $server);
                }
                CareerDev::log($this->getName().": Running ".$mgr->getNumberOfCrons()." crons for pid $pid", $pid);
                $addlEmailText = in_array($pid, $pidsUpdated) ? "Surveys shared from other Flight Tracker projects" : "";
                $mgr->run($adminEmail, $tokenName, $addlEmailText);
                CareerDev::log($this->getName().": cron run complete for pid $pid", $pid);
			}
		}
		REDCapManagement::cleanupDirectory(APP_PATH_TEMP, "/RePORTER_PRJ/");
	}

	function setupApplication() {
		CareerDev::$passedModule = $this;
	}

	function redcap_module_link_check_display($project_id, $link) {
		if (SUPER_USER) {
			return $link;
		}

		if (!empty($project_id) && self::hasAppropriateRights(USERID, $project_id)) {
			return $link;
		}

		return null;
	}

	function hook_every_page_before_render($project_id) {
		$this->setupApplication();
		if (PAGE == "DataExport/index.php") {
			echo "<script src='".CareerDev::link("/js/jquery.min.js")."'></script>\n";
			echo "<script src='".CareerDev::link("/js/colorCellFunctions.js")."'></script>\n";
			echo "
<script>
	$(document).ready(function() { setTimeout(function() { transformColumn(); }, 500); });
</script>\n";
            } else if (PAGE == "ProjectSetup/index.php") {
            echo "<script src='".CareerDev::link("/js/jquery.min.js")."'></script>\n";
            echo "
<script>
    $(document).ready(function() { $('.chklist.round:eq(6)').hide(); });
</script>\n";
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
			if (!preg_match("/surveys/", $url) && !isset($_GET['s'])) {
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
		require_once(dirname(__FILE__)."/hooks/setDateHook.php");
	}

	function hook_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
		require_once(dirname(__FILE__)."/hooks/saveHook.php");
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
		$str .= "<div class='topBar' style='float: left; padding-left: 5px;'><a href='https://redcap.vanderbilt.edu/plugins/career_dev/consortium/'><img alt='Flight Tracker for Scholars' src='".CareerDev::link("/img/flight_tracker_logo_small.png")."'></a></div>\n";
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
			$str .= "<a href='https://ncats.nih.gov/ctsa'><img src='".CareerDev::link("/img/ctsa.png")."' style='height: 22px;'></a></div>\n";
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
		return $this->getProjectSetting($this->getBrandLogoName(), $_GET['pid']);
	}

	public function canRedirectToInstall() {
		$bool = !self::isAJAXPage() && !self::isAPITokenPage() && !self::isUserRightsPage() && !self::isExternalModulePage() && ($_GET['page'] != "install");
		if ($_GET['pid']) {
			# project context
			$bool = $bool && self::hasAppropriateRights(USERID, $_GET['pid']);
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

	private static function isModuleEnabled($pid) {
		return \ExternalModules\ExternalModules::getProjectSetting("flightTracker", $pid, \ExternalModules\ExternalModules::KEY_ENABLED);
	}

	private static function hasAppropriateRights($userid, $pid) {
		$sql = "SELECT design FROM redcap_user_rights WHERE project_id = '".db_real_escape_string($pid)."' AND username = '".db_real_escape_string($userid)."'"; 
		$q =  db_query($sql);
		if ($row = db_fetch_assoc($q)) {
			return $row['design'];
		}
		return FALSE;
	}

	private $prefix = "flightTracker";
	private $name = "Flight Tracker";
}
