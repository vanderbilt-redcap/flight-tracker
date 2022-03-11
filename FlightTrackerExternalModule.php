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
use Vanderbilt\CareerDevLibrary\MMAHelper;

require_once(dirname(__FILE__)."/classes/Autoload.php");
require_once(dirname(__FILE__)."/CareerDev.php");
require_once(dirname(__FILE__)."/cronLoad.php");
require_once(APP_PATH_DOCROOT."Classes/System.php");

class FlightTrackerExternalModule extends AbstractExternalModule
{
	function getPrefix() {
	    if (Application::isLocalhost()) {
	        return "flight_tracker";
        }
		return $this->prefix;
	}

	function getName() {
		return $this->name;
	}

	function enqueueTonight() {
		$this->setProjectSetting("run_tonight", TRUE);
	}

	function batch() {
        \System::increaseMaxExecTime(28800);   // 8 hours
        $this->setupApplication();
        $activePids = $this->getPids();
        foreach ($activePids as $pid) {
            # note return at end of successful run because only need to run once
            $token = $this->getProjectSetting("token", $pid);
            $server = $this->getProjectSetting("server", $pid);
            $adminEmail = $this->getProjectSetting("admin_email", $pid);
            if ($token && $server) {
                try {
                    CronManager::sendEmails($activePids, $this);
                    $mgr = new CronManager($token, $server, $pid, $this);
                    $mgr->runBatchJobs();
                } catch (\Exception $e) {
                    # should only happen in rarest of circumstances
                    $mssg = $e->getMessage()."<br><br>".$e->getTraceAsString();
                    \REDCap::email($adminEmail, "noreply.flighttracker@vumc.org", "Flight Tracker Batch Job Exception", $mssg);
                }
                return;
            }
        }
    }

    function getPids() {
	    return $this->framework->getProjectsWithModuleEnabled();
    }

	function emails() {
	    $this->setupApplication();
        $activePids = $this->getPids();
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
                        $mgr = new CronManager($token, $server, $pid, $this);
                        loadTestingCrons($mgr);
                        $mgr->run($adminEmail, $tokenName);
                    }
                    try {
                        $mgr = new EmailManager($token, $server, $pid, $this);
                        $mgr->sendRelevantEmails();
                    } catch (\Exception $e) {
                        # should only happen in rarest of circumstances
                        $mssg = $e->getMessage()."<br><br>".$e->getTraceAsString();
                        \REDCap::email($adminEmail, "noreply.flighttracker@vumc.org", "Flight Tracker Email Exception", $mssg);
                    }
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

	public function cleanupLogs($pid) {
        $daysPrior = 28;
        $this->cleanupExtModLogs($pid, $daysPrior);
    }

    public function cleanupExtModLogs($pid, $daysPrior) {
        $ts = time() - $daysPrior * 24 * 3600;
        $thresholdTs = date("Y-m-d", $ts);
        Application::log("Removing logs prior to $thresholdTs", $pid);
        $this->removeLogs("timestamp <= '$thresholdTs' AND project_id = '$pid'");
        Application::log("Done removing logs", $pid);
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
                // Application::log("Got token with length of ".strlen($token)." for pid $pid", $pid);
                $tokens[$pid] = $token;
                $servers[$pid] = $server;
            }
        }
        $credentialsFile = "/app001/credentials/career_dev/credentials.php";
        if (preg_match("/redcap.vanderbilt.edu/", SERVER_NAME)  && file_exists($credentialsFile)) {
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

        foreach ($pids as $i => $pid) {
            if ($tokens[$pid] && $servers[$pid]) {
                CareerDev::setPid($pid);
                $token = $tokens[$pid];
                $server = $servers[$pid];
                $metadata = Download::metadata($token, $server);
                if (REDCapManagement::isMetadataFilled($metadata)) {
                    // Application::log("Downloading data for pid $pid", $pid);
                    $firstNames[$pid] = Download::firstnames($token, $server);
                    $lastNames[$pid] = Download::lastnames($token, $server);
                    $choices[$pid] = REDCapManagement::getChoices($metadata);
                    $metadataFields[$pid] = REDCapManagement::getFieldsFromMetadata($metadata);
                    foreach (array_keys($forms) as $instrument) {
                        $field = $instrument."_complete";
                        if (!isset($completes[$instrument])) {
                            $completes[$instrument] = [];
                        }
                        $completes[$instrument][$pid] = Download::oneField($token, $server, $field);
                    }
                } else {
                    unset($pids[$i]);
                }
            }
        }

	    # push
	    foreach ($pids as $i => $sourcePid) {
	        // Application::log("Searching through pid $sourcePid", $sourcePid);
	        if ($tokens[$sourcePid] && $servers[$sourcePid]) {
                $sourceToken = $tokens[$sourcePid];
                $sourceServer = $servers[$sourcePid];
                foreach ($pids as $i2 => $destPid) {
                    if (($destPid != $sourcePid) && $tokens[$destPid] && $servers[$destPid]) {
                        // Application::log("Communicating between $sourcePid and $destPid", $destPid);
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
                                // Application::log("Searching for $firstName $lastName from $destPid in $sourcePid", $sourcePid);
                                // Application::log("Searching for $firstName $lastName from $destPid in $sourcePid", $destPid);
                                $originalPid = CareerDev::getPid();
                                CareerDev::setPid($sourcePid);
                                if ($sourceRecordId = NameMatcher::matchName($firstName, $lastName, $sourceToken, $sourceServer)) {
                                    // Application::log("Match in above: source ($sourcePid, $sourceRecordId) to dest ($destPid, $destRecordId)", $sourcePid);
                                    // Application::log("Match in above: source ($sourcePid, $sourceRecordId) to dest ($destPid, $destRecordId)", $destPid);

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
                                CareerDev::setPid($originalPid);
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
	    $originalPid = CareerDev::getPid();
        CareerDev::setPid($sourceInfo['pid']);
        $sourceData = Download::fieldsForRecords($sourceInfo['token'], $sourceInfo['server'], $fields, [$sourceInfo['record']]);
        CareerDev::setPid($destInfo['pid']);
        $destData = Download::fieldsForRecords($destInfo['token'], $destInfo['server'], $fields, [$destInfo['record']]);
        CareerDev::setPid($originalPid);
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
        $originalPid = CareerDev::getPid();
        CareerDev::setPid($destPid);
        foreach (array_keys($forms) as $instrument) {
            $field = $instrument . "_complete";
            $completes[$instrument][$destPid] = Download::oneField($destToken, $destServer, $field);
        }
        CareerDev::setPid($sourcePid);
        foreach (array_keys($forms) as $instrument) {
            $field = $instrument . "_complete";
            $completes[$instrument][$sourcePid] = Download::oneField($sourceToken, $sourceServer, $field);
        }
        CareerDev::setPid($originalPid);
        $uploadNormativeRow = FALSE;
        $repeatingRows = [];
        $sourceData = [];
        $destData = [];
        foreach ($completes as $instrument => $completeData) {
            if (!in_array($completeData[$destPid][$destRecordId], $markedAsComplete)
                && in_array($completeData[$sourcePid][$sourceRecordId], $markedAsComplete)) {
                if (empty($sourceData) || empty($destData)) {
                    $originalPid = CareerDev::getPid();
                    CareerDev::setPid($sourcePid);
                    $sourceData = Download::records($sourceToken, $sourceServer, array($sourceRecordId));
                    CareerDev::setPid($destPid);
                    $destData = Download::records($destToken, $destServer, array($destRecordId));
                    CareerDev::setPid($originalPid);
                }
                # copy over from source to dest and mark as same as $projectData[$sourceRecordId]
                // CareerDev::log("Matched complete for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId]);
                $config = $forms[$instrument];
                $newInstance = REDCapManagement::getMaxInstance($destData, $instrument, $destRecordId) + 1;
                foreach ($sourceData as $sourceRow) {
                    $continueToCopyFromSource = TRUE;
                    foreach ($destData as $destRow) {
                        if ($config["formType"] == "single") {
                            if ($destRow["redcap_repeat_instrument"] == "") {
                                if (!self::isValidToCopy($config["test_fields"], $sourceRow, $destRow, $choicesByPid[$sourcePid], $choicesByPid[$destPid])) {
                                    Application::log("Not valid to copy single for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $destPid);
                                    Application::log("Not valid to copy single for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $sourcePid);
                                    $continueToCopyFromSource = FALSE;
                                }
                            }
                        } else if ($config["formType"] == "repeating") {
                            if ($destRow["redcap_repeat_instrument"] == $instrument) {
                                if (!self::isValidToCopy($config["test_fields"], $sourceRow, $destRow, $choicesByPid[$sourcePid], $choicesByPid[$destPid])) {
                                    Application::log("Not valid to repeating single for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $destPid);
                                    Application::log("Not valid to repeating single for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $sourcePid);
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
                                Application::log("copyDataFromRowToNormative for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $destPid);
                                Application::log("copyDataFromRowToNormative for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $sourcePid);
                                $hasChanged = self::copyDataFromRowToNormative($sourceRow,
                                    $completeData[$sourcePid][$sourceRecordId],
                                    $config["prefix"],
                                    $metadataFieldsByPid[$destPid],
                                    $choicesByPid[$destPid],
                                    $normativeRow,
                                    $instrument);
                                if ($hasChanged) {
                                    Application::log("uploadNormativeRow for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $destPid);
                                    Application::log("uploadNormativeRow for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $sourcePid);
                                    $uploadNormativeRow = TRUE;
                                }
                            }
                        } else if ($config["formType"] == "repeating") {
                            if ($sourceRow["redcap_repeat_instrument"] == $instrument) {
                                Application::log("copyDataFromRowToNewRow for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $destPid);
                                Application::log("copyDataFromRowToNewRow for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $sourcePid);
                                $repeatingRow = self::copyDataFromRowToNewRow($sourceRow,
                                    $completeData[$sourcePid][$sourceRecordId],
                                    $config["prefix"],
                                    $metadataFieldsByPid[$destPid],
                                    $choicesByPid[$destPid],
                                    $destRecordId,
                                    $instrument,
                                    $newInstance);
                                if ($repeatingRow && is_array($repeatingRow)) {
                                    Application::log("add repeatingRow for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $destPid);
                                    Application::log("add repeatingRow for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $sourcePid);
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
                        Application::log("$destPid: Uploaded ".count($upload)." rows for record $destRecordId from pid $sourcePid record $sourceRecordId", $destPid);
                        Application::log("$destPid: Uploaded ".count($upload)." rows for record $destRecordId from pid $sourcePid record $sourceRecordId", $sourcePid);
                        // Application::log(json_encode($upload), $destPid;
                        if (!in_array($destPid, $pidsUpdated)) {
                            $pidsUpdated[] = $destPid;
                        }
                    } catch (\Exception $e) {
                        Application::log("ERROR: Could not copy from $sourcePid record $sourceRecordId, into $destPid record $destRecordId", $sourcePid);
                        Application::log("ERROR: Could not copy from $sourcePid record $sourceRecordId, into $destPid record $destRecordId", $destPid);
                        Application::log($e->getMessage());
                    }
                } else {
                    Application::log("Skipping uploading for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $sourcePid);
                    Application::log("Skipping uploading for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $destPid);
                }
            } else {
                Application::log("Could not match complete for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $sourcePid);
                Application::log("Could not match complete for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $destPid);
            }
        }
    }

    function cron() {
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

	function executeCron() {
        \System::increaseMaxExecTime(28800);   // 8 hours

		$this->setupApplication();
        $activePids = $this->framework->getProjectsWithModuleEnabled();
		CareerDev::log($this->getName()." running for pids ".json_encode($activePids));
		$pidsUpdated = [];
        CareerDev::log("Checking for redcaptest in ".SERVER_NAME);
        if (preg_match("/redcaptest.vanderbilt.edu/", SERVER_NAME)) {
            CareerDev::log("Sharing because redcaptest");
            $pidsUpdated = $this->shareDataInternally($activePids);
        } else if ((date("N") == "6") || $this->hasProjectToRunTonight($activePids)) {
            # only on Saturdays or when data update is requested
            try {
                $pidsUpdated = $this->shareDataInternally($activePids);
            } catch (\Exception $e) {
                if ((count($activePids) > 0) && CareerDev::isVanderbilt()) {
                    \REDCap::email("scott.j.pearson@vumc.org", Application::getSetting("default_from", $activePids[0]), Application::getProgramName().": Error in sharing surveys", $e->getMessage());
                } else {
                    Application::log("Error in data sharing", $activePids[0]);
                }
            }
        }
		foreach ($activePids as $pid) {
            $this->cleanupLogs($pid);
            $token = $this->getProjectSetting("token", $pid);
            $server = $this->getProjectSetting("server", $pid);
            $tokenName = $this->getProjectSetting("tokenName", $pid);
            $adminEmail = $this->getProjectSetting("admin_email", $pid);
            $turnOffSet = $this->getProjectSetting("turn_off", $pid);
            $GLOBALS['namesForMatch'] = [];
            CareerDev::setPid($pid);
            CareerDev::log("Using $tokenName $adminEmail", $pid);
            if ($token && $server && !$turnOffSet) {
                try {
                    # only have token and server in initialized projects
                    $mgr = new CronManager($token, $server, $pid, $this);
                    if ($this->getProjectSetting("run_tonight", $pid)) {
                        $this->setProjectSetting("run_tonight", FALSE, $pid);
                        loadInitialCrons($mgr, FALSE, $token, $server);
                    } else {
                        loadCrons($mgr, FALSE, $token, $server);
                    }
                    Application::log($this->getName().": $tokenName enqueued ".$mgr->getNumberOfCrons()." crons", $pid);
                    $addlEmailText = in_array($pid, $pidsUpdated) ? "Surveys shared from other Flight Tracker projects" : "";
//                     $mgr->run($adminEmail, $tokenName, $addlEmailText);
                    // CareerDev::log($this->getName().": cron run complete for pid $pid", $pid);
                } catch(\Exception $e) {
                    Application::log("Error in cron logic", $pid);
                    \REDCap::email($adminEmail, Application::getSetting("default_from", $pid), Application::getProgramName()." Error in Cron", $e->getMessage());
                }
            }
		}
	}

	function hasProjectToRunTonight($pids) {
	    foreach ($pids as $pid) {
            if ($this->getProjectSetting("run_tonight", $pid)) {
                return TRUE;
            }
        }
	    return FALSE;
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

        if (self::hasAppropriateRights($userid, $project_id)) {
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

	function hook_every_page_before_render() {
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
	    if ($project_id) {
            $this->setupApplication();
            $tokenName = $this->getProjectSetting("tokenName", $project_id);
            $token = $this->getProjectSetting("token", $project_id);
            $server = $this->getProjectSetting("server", $project_id);
            if ($tokenName && $token && $server) {
                # turn off for surveys and login pages
                $url = $_SERVER['PHP_SELF'];
                if (
                    !preg_match("/surveys/", $url)
                    && !isset($_GET['s'])
                    && class_exists('\Vanderbilt\CareerDevLibrary\NavigationBar')
                ) {
                    echo $this->makeHeaders($token, $server, $project_id, $tokenName);
                }
                if (preg_match("/online_designer\.php/", $url)) {
                    $_SESSION['metadata'.$project_id] = [];
                    $_SESSION['lastMetadata'.$project_id] = 0;
                }
            } else {
                if (self::canRedirectToInstall()) {
                    header("Location: ".$this->getUrl("install.php"));
                }
            }
        }
	}

	function hook_data_entry_form($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance) {
		$this->setupApplication();
		if ($instrument == "summary") {
			require_once(dirname(__FILE__)."/hooks/summaryHook.php");
		} else if (in_array($instrument, ["initial_survey", "initial_short_survey"])) {
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
		} else if (in_array($instrument, ["initial_survey", "initial_short_survey"])) {
			require_once(dirname(__FILE__)."/hooks/checkHook.php");
		} else if ($instrument == "followup") {
			require_once(dirname(__FILE__)."/hooks/followupHook.php");
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

        if (isset($_GET['test'])) {
            Application::log("Comparing $userid to " . json_encode($validUserids));
        }
        if (in_array($userid, $validUserids)) {
            return TRUE;
        }
        if (DEBUG && in_array($_GET['uid'], $validUserids)) {
            return TRUE;
        }
        return FALSE;
    }

    function makeHeaders($token, $server, $pid, $tokenName) {
		$str = "";
		$str .= "<link rel='stylesheet' href='".CareerDev::link("/css/w3.css")."'>\n";
		$str .= "<style>\n";

		# must add fonts here or they will not show up in REDCap menus
		$str .= "@font-face { font-family: 'Museo Sans'; font-style: normal; font-weight: normal; src: url('".CareerDev::link("/fonts/exljbris - MuseoSans-500.otf")."'); }\n";

		$str .= ".w3-dropdown-hover { display: inline-block !important; float: none !important; }\n";
		$str .= ".w3-dropdown-hover button,a.w3-bar-link { font-size: 12px; }\n";
		$str .= "a.w3-bar-link { display: inline-block !important; float: none !important; }\n";
		$str .= ".w3-bar { font-family: 'Museo Sans', Arial, Helvetica, sans-serif; text-align: center !important; }\n";
        $str .= "a.w3-button,button.w3-button { padding: 6px 4px !important; }\n";
        $str .= "a.w3-button,button.w3-button.with-image { padding: 8px 4px 6px 4px !important; }\n";
		$str .= "a.w3-button { color: black !important; float: none !important; }\n";
		$str .= ".w3-button a,.w3-dropdown-content a { color: white !important; font-size: 13px !important; }\n";
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
        $navBar->addMenu("<img src='".CareerDev::link("/img/grant_small.png")."'>Grants", CareerDev::getMenu("Grants"));
        $navBar->addFAMenu("sticky-note", "Pubs", CareerDev::getMenu("Pubs"));
        $navBar->addFAMenu("table", "View", CareerDev::getMenu("View"));
		$navBar->addFAMenu("calculator", "Wrangle", CareerDev::getMenu("Wrangler"));
		$navBar->addFAMenu("school", "Scholars", CareerDev::getMenu("Scholars"));
		$navBar->addMenu("<img src='".CareerDev::link("/img/redcap_translucent_small.png")."'>REDCap", CareerDev::getMenu("REDCap"));
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
		$bool = !self::isAJAXPage() && !self::isAPITokenPage() && !self::isUserRightsPage() && !self::isExternalModulePage() && (!isset($_GET['page']) || ($_GET['page'] != "install"));
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

	public function getDirectoryPrefix() {
	    return $this->getPrefix();
    }

	private function isModuleEnabled($pid) {
		return ExternalModules::getProjectSetting($this->getDirectoryPrefix(), $pid, \ExternalModules\ExternalModules::KEY_ENABLED);
	}

	private static function hasAppropriateRights($userid, $pid) {
		$sql = "SELECT design, role_id FROM redcap_user_rights WHERE project_id = '".db_real_escape_string($pid)."' AND username = '".db_real_escape_string($userid)."'";
		$q =  db_query($sql);
		$roleId = FALSE;
		if ($row = db_fetch_assoc($q)) {
			if ($row['design']) {
			    return TRUE;
            }
			$roleId = $row['role_id'];
		}
		if ($roleId) {
		    $sql = "SELECT roles.design AS design FROM redcap_user_rights AS rights INNER JOIN redcap_user_roles AS roles ON rights.role_id = roles.role_id WHERE rights.project_id = '".db_real_escape_string($pid)."' AND rights.username = '".db_real_escape_string($userid)."'";
            $q =  db_query($sql);
            if ($row = db_fetch_assoc($q)) {
                if ($row['design']) {
                    return TRUE;
                }
            }
        }

		return FALSE;
	}

	private $prefix = "flightTracker";
	private $name = "Flight Tracker";
}
