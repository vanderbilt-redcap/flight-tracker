<?php

namespace Vanderbilt\FlightTrackerExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\CronManager;
use Vanderbilt\CareerDevLibrary\DataDictionaryManagement;
use Vanderbilt\CareerDevLibrary\EmailManager;
use Vanderbilt\CareerDevLibrary\NavigationBar;
use Vanderbilt\CareerDevLibrary\REDCapManagement;
use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\NameMatcher;
use Vanderbilt\CareerDevLibrary\Upload;
use Vanderbilt\CareerDevLibrary\MMAHelper;
use Vanderbilt\CareerDevLibrary\Sanitizer;

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
                    CronManager::sendEmails($activePids, $this);
                    $mgr = new CronManager($token, $server, $pid, $this);
                    $mgr->runBatchJobs();
                } catch (\Exception $e) {
                    # should only happen in rarest of circumstances
                    if (preg_match("/'batchCronJobs' because the value is larger than the \d+ byte limit/", $e->getMessage())) {
                        Application::saveSetting("batchCronJobs", [], $pid);
                    }
                    Application::log("batch F $pid: ".memory_get_usage());
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
                // Application::log(REDCapManagement::pretty(memory_get_usage())." bytes used at beginning of email processing for project.", $pid);
				$token = $this->getProjectSetting("token", $pid);
				$server = $this->getProjectSetting("server", $pid);
				if ($token && $server) {
                    try {
                        $tokenName = $this->getProjectSetting("tokenName", $pid);
                        $adminEmail = $this->getProjectSetting("admin_email", $pid);
                        $cronStatus = $this->getProjectSetting("send_cron_status", $pid);
                        if ($cronStatus) {
                            $mgr = new CronManager($token, $server, $pid, $this);
                            loadTestingCrons($mgr);
                            $mgr->run($adminEmail, $tokenName);
                        }
                        $mgr = new EmailManager($token, $server, $pid, $this);
                        $mgr->sendRelevantEmails();
                    } catch (\Exception $e) {
                        # should only happen in rarest of circumstances
                        if (preg_match("/'batchCronJobs' because the value is larger than the \d+ byte limit/", $e->getMessage())) {
                            Application::saveSetting("batchCronJobs", [], $pid);
                        }
                        $mssg = $e->getMessage()."<br/><br/>".$e->getTraceAsString();
                        \REDCap::email($adminEmail, "noreply.flighttracker@vumc.org", "Flight Tracker Email Exception", $mssg);
                    }
                }
                // Application::log(REDCapManagement::pretty(memory_get_usage())." bytes used at end of email processing for project.", $pid);
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
                ) {
                    if (
                        isset($sourceChoices[$sourceField])
                        && isset($sourceChoices[$sourceField][$sourceValue])
                        && isset($destChoices[$sourceField])
                    ) {
                        $destValue = "";
                        foreach ($destChoices[$sourceField] as $destIdx => $destLabel) {
                            if (strtolower($destLabel) == strtolower($sourceChoices[$sourceChoices][$sourceValue])) {
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

    private static function getSharingInformation() {
        $ary = [
            "initial_survey" => ["prefix" => "check", "formType" => "single", "test_fields" => ["check_date"], "always_copy" => TRUE, ],
            "followup" => ["prefix" => "followup", "formType" => "repeating", "test_fields" => ["followup_date"], "always_copy" => TRUE, ],
            "position_change" => [ "prefix" => "promotion", "formType" => "repeating", "test_fields" => ["promotion_job_title", "promotion_date"], "always_copy" => FALSE, ],
            "resources" => [ "prefix" => "resources", "formType" => "repeating", "test_fields" => ["resources_date", "resources_resource"], "always_copy" => FALSE, ],
            "honors_and_awards" => [ "prefix" => "honor", "formType" => "repeating", "test_fields" => ["honor_name", "honor_date"], "always_copy" => FALSE, ],
        ];
        if (Application::isVanderbilt()) {
            $ary['resources']['always_copy'] = TRUE;
            $ary['honors_and_awards']['always_copy'] = TRUE;
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

	public function shareDataInternally($pidsSource, $pidsDest) {
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

        $allPids = array_unique(array_merge($pidsSource, $pidsDest));
	    foreach ($allPids as $pid) {
	        Application::log("Getting project data for pid ".$pid, $pid);
            $token = $this->getProjectSetting("token", $pid);
            $server = $this->getProjectSetting("server", $pid);
            if ($token && $server) {
                Application::log("Got token with length of ".strlen($token)." for pid $pid", $pid);
                $tokens[$pid] = $token;
                $servers[$pid] = $server;
            }
        }
        $credentialsFile = Application::getCredentialsDir()."/career_dev/credentials.php";
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

        foreach ($allPids as $i => $pid) {
            if ($tokens[$pid] && $servers[$pid]) {
                CareerDev::setPid($pid);
                $token = $tokens[$pid];
                $server = $servers[$pid];
                $metadata = Download::metadata($token, $server);
                $repeatingForms = DataDictionaryManagement::getRepeatingForms($pid);
                if (REDCapManagement::isMetadataFilled($metadata)) {
                    Application::log("Downloading data for pid $pid", $pid);
                    $firstNames[$pid] = Download::firstnames($token, $server);
                    $lastNames[$pid] = Download::lastnames($token, $server);
                    $choices[$pid] = REDCapManagement::getChoices($metadata);
                    $metadataFields[$pid] = REDCapManagement::getFieldsFromMetadata($metadata);
                    foreach (array_keys($forms) as $instrument) {
                        $field = $instrument."_complete";
                        if (!isset($completes[$instrument])) {
                            $completes[$instrument] = [];
                        }
                        if (in_array($instrument, $repeatingForms)) {
                            $completes[$instrument][$pid] = Download::oneFieldWithInstances($token, $server, $field);
                        } else {
                            $completes[$instrument][$pid] = Download::oneField($token, $server, $field);
                        }
                    }
                } else {
                    unset($pids[$i]);
                }
            }
        }

	    # push
	    foreach ($pidsSource as $i => $sourcePid) {
	        Application::log("Searching through pid $sourcePid", $sourcePid);
	        if ($tokens[$sourcePid] && $servers[$sourcePid]) {
                $sourceToken = $tokens[$sourcePid];
                $sourceServer = $servers[$sourcePid];
                foreach ($pidsDest as $i2 => $destPid) {
                    if (($destPid != $sourcePid) && $tokens[$destPid] && $servers[$destPid]) {
                        Application::log("Communicating between $sourcePid and $destPid", $destPid);
                        $destToken = $tokens[$destPid];
                        $destServer = $servers[$destPid];
                        foreach (array_keys($firstNames[$destPid] ?? []) as $destRecordId) {
                            $combos = [];
                            foreach (NameMatcher::explodeFirstName($firstNames[$destPid][$destRecordId] ?? "") as $firstName) {
                                foreach (NameMatcher::explodeLastName($lastNames[$destPid][$destRecordId] ?? "") as $lastName) {
                                    if ($firstName && $lastName) {
                                        $combos[] = ["first" => $firstName, "last" => $lastName];
                                    }
                                }
                            }
                            foreach ($combos as $nameAry) {
                                $firstName = $nameAry["first"];
                                $lastName = $nameAry["last"];
                                Application::log("Searching for $firstName $lastName from $destPid in $sourcePid", $sourcePid);
                                Application::log("Searching for $firstName $lastName from $destPid in $sourcePid", $destPid);
                                $originalPid = CareerDev::getPid();
                                CareerDev::setPid($sourcePid);
                                if ($sourceRecordId = NameMatcher::matchName($firstName, $lastName, $sourceToken, $sourceServer)) {
                                    Application::log("Match in above: source ($sourcePid, $sourceRecordId) to dest ($destPid, $destRecordId)", $sourcePid);
                                    Application::log("Match in above: source ($sourcePid, $sourceRecordId) to dest ($destPid, $destRecordId)", $destPid);

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

    private static function getMarkedAsComplete() {
	    return [2];
    }

	private function copyFormData(&$completes, &$pidsUpdated, $forms, $sourceInfo, $destInfo, $metadataFieldsByPid, $choicesByPid) {
        $sourceToken = $sourceInfo['token'];
        $sourceServer = $sourceInfo['server'];
        $sourcePid = $sourceInfo['pid'];
        $sourceRecordId = $sourceInfo['record'];
        $sourceData =[];
        $destToken = $destInfo['token'];
        $destServer = $destInfo['server'];
        $destPid = $destInfo['pid'];
        $destRecordId = $destInfo['record'];
        $destData = [];

        $sharedFormsForSource = $this->getProjectSetting("shared_forms", $sourcePid);
        if (!$sharedFormsForSource) {
            $sharedFormsForSource = [];
        }
        $sharedFormsForDest = $this->getProjectSetting("shared_forms", $destPid);
        if (!$sharedFormsForDest) {
            $sharedFormsForDest = [];
        }

        $originalPid = CareerDev::getPid();
        $repeatingForms = [];
        foreach (["dest" => $destInfo, "source" => $sourceInfo] as $type => $info) {
            CareerDev::setPid($info['pid']);
            $field = "sharing_copy_dedup";
            $repeatingForms[$type] = DataDictionaryManagement::getRepeatingForms($info['pid']);
            if (!Application::getSetting($field, $info['pid'])) {
                foreach (array_keys(self::getSharingInformation()) as $instrument) {
                    if (in_array($instrument, $repeatingForms[$type])) {
                        $this->dedupChoicesOnlyError($info['token'], $info['server'], $info['pid'], $instrument);
                    }
                }
                Application::saveSetting($field, "1", $info['pid']);
            }
            foreach (array_keys($forms) as $instrument) {
                $field = $instrument . "_complete";
                if (in_array($instrument, $repeatingForms)) {
                    $completes[$instrument][$info['pid']] = Download::oneFieldWithInstances($info['token'], $info['server'], $field);
                } else {
                    $completes[$instrument][$info['pid']] = Download::oneField($info['token'], $info['server'], $field);
                }
            }
        }
        CareerDev::setPid($originalPid);
        foreach ($completes as $instrument => $completeData) {
            if (
                (
                    in_array($instrument, $repeatingForms["source"])
                    && in_array($instrument, $repeatingForms["dest"])
                )
                || (
                    !in_array($instrument, $repeatingForms["source"])
                    && !in_array($instrument, $repeatingForms["dest"])
                )
            ) {
                $pidsProcessed = $this->processInstrument(
                    $instrument,
                    $forms,
                    $completeData,
                    $destData,
                    $destToken,
                    $destServer,
                    $destPid,
                    $destRecordId,
                    $sharedFormsForDest,
                    $choicesByPid[$destPid],
                    $metadataFieldsByPid[$destPid],
                    $sourceData,
                    $sourceToken,
                    $sourceServer,
                    $sourcePid,
                    $sourceRecordId,
                    $sharedFormsForSource,
                    $choicesByPid[$sourcePid]
                );
                $pidsUpdated = array_unique(array_merge($pidsProcessed, $pidsUpdated));
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
        $instrumentFields = REDCapManagement::getFieldsFromMetadata($metadata, $instrument);
        if (!in_array("record_id", $instrumentFields)) {
            $instrumentFields[] = "record_id";
        }
        $fieldsByType = [];
        foreach (['dropdown', 'radio', 'checkboxes', 'yesno', 'truefalse'] as $fieldType) {
            $fieldsByType[$fieldType] = REDCapManagement::getFieldsOfType($metadata, $fieldType);
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

    private function processInstrument($instrument, $forms, $completeData, &$destData, $destToken, $destServer, $destPid, $destRecordId, $sharedFormsForDest, $destChoices, $destFields, &$sourceData, $sourceToken, $sourceServer, $sourcePid, $sourceRecordId, $sharedFormsForSource, $sourceChoices) {
        $isDebug = Application::isLocalhost();
        $markedAsComplete = self::getMarkedAsComplete();
        $pidsUpdated = [];
        $uploadNormativeRow = FALSE;
        $repeatingRows = [];
        $normativeRow = [
            "record_id" => $destRecordId,
            "redcap_repeat_instrument" => "",
            "redcap_repeat_instance" => "",
        ];
        $config = $forms[$instrument];

        if ($config['formType'] == "repeating") {
            $canGo = FALSE;
            $dataValues = array_values($completeData[$sourcePid][$sourceRecordId] ?: []);
            foreach ($markedAsComplete as $completeValue) {
                if (in_array($completeValue, $dataValues)) {
                    $canGo = TRUE;
                }
            }
        } else if ($config['formType'] == "single") {
            $canGo = (
                !in_array($completeData[$destPid][$destRecordId], $markedAsComplete)
                && in_array($completeData[$sourcePid][$sourceRecordId], $markedAsComplete)
            );
        } else {
            throw new \Exception("This should never happen: invalid formType ".$config['formType']);
        }
        if ($canGo) {
            if (empty($sourceData) || empty($destData)) {
                $originalPid = CareerDev::getPid();
                CareerDev::setPid($sourcePid);
                $sourceData = Download::records($sourceToken, $sourceServer, [$sourceRecordId]);
                CareerDev::setPid($destPid);
                $destData = Download::records($destToken, $destServer, [$destRecordId]);
                CareerDev::setPid($originalPid);
            }
            # copy over from source to dest and mark as same as $projectData[$sourceRecordId]
            // CareerDev::log("Matched complete for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId]);
            $newInstance = REDCapManagement::getMaxInstance($destData, $instrument, $destRecordId) + 1;
            foreach ($sourceData as $sourceRow) {
                $continueToCopyFromSource = TRUE;
                foreach ($destData as $destRow) {
                    if (
                        ($config["formType"] == "single")
                        && ($destRow["redcap_repeat_instrument"] == "")
                        && !self::isValidToCopy($config["test_fields"], $sourceRow, $destRow, $sourceChoices, $destChoices)
                    ) {
                        if ($isDebug) {
                            Application::log("Not valid to copy single for $instrument in dest $destPid $destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid $sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $destPid);
                            Application::log("Not valid to copy single for $instrument in dest $destPid $destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid $sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $sourcePid);
                        }
                        $continueToCopyFromSource = FALSE;
                    } else if (
                        ($config["formType"] == "repeating")
                        && ($destRow["redcap_repeat_instrument"] == $instrument)
                        && !self::isValidToCopy($config["test_fields"], $sourceRow, $destRow, $sourceChoices, $destChoices)
                    ) {
                        if ($isDebug) {
                            Application::log("Not valid to repeating single for $instrument in dest $destPid $destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid $sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $destPid);
                            Application::log("Not valid to repeating single for $instrument in dest $destPid $destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid $sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $sourcePid);
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
                    if (
                        ($config["formType"] == "single")
                        && ($sourceRow["redcap_repeat_instrument"] == "")
                    ) {
                        if ($isDebug) {
                            Application::log("copyDataFromRowToNormative for $instrument in dest $destPid $destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid $sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $destPid);
                            Application::log("copyDataFromRowToNormative for $instrument in dest $destPid $destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid $sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $sourcePid);
                        }
                        $hasChanged = self::copyDataFromRowToNormative($sourceRow,
                            $completeData[$sourcePid][$sourceRecordId],
                            $config["prefix"],
                            $destFields,
                            $sourceChoices,
                            $destChoices,
                            $normativeRow,
                            $instrument);
                        if ($hasChanged) {
                            Application::log("uploadNormativeRow for $instrument in dest $destPid $destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid $sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $destPid);
                            Application::log("uploadNormativeRow for $instrument in dest $destPid $destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid $sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $sourcePid);
                            $uploadNormativeRow = TRUE;
                        }
                    } else if (
                        ($config["formType"] == "repeating")
                        && ($sourceRow["redcap_repeat_instrument"] == $instrument)
                    ) {
                        if ($isDebug) {
                            Application::log("copyDataFromRowToNewRow for $instrument in dest $destPid $destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid $sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $destPid);
                            Application::log("copyDataFromRowToNewRow for $instrument in dest $destPid $destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid $sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $sourcePid);
                        }
                        $repeatingRow = self::copyDataFromRowToNewRow($sourceRow,
                            $completeData[$sourcePid][$sourceRecordId][$sourceRow['redcap_repeat_instance']],
                            $config["prefix"],
                            $destFields,
                            $sourceChoices,
                            $destChoices,
                            $destRecordId,
                            $instrument,
                            $newInstance);
                        if ($repeatingRow && is_array($repeatingRow)) {
                            if ($isDebug) {
                                Application::log("add repeatingRow for $instrument in dest $destPid $destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid $sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $destPid);
                                Application::log("add repeatingRow for $instrument in dest $destPid $destRecordId ".($completeData[$destPid][$destRecordId] ? json_encode($completeData[$destPid][$destRecordId]) : "")." and source $sourcePid $sourceRecordId ".($completeData[$sourcePid][$sourceRecordId] ? json_encode($completeData[$sourcePid][$sourceRecordId]) : ""), $sourcePid);
                            }
                            $repeatingRows[] = $repeatingRow;
                            $newInstance++;
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
                    Application::log($e->getMessage(), $sourcePid);
                    Application::log($e->getMessage(), $destPid);
                }
            } else {
                Application::log("Skipping uploading for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $sourcePid);
                Application::log("Skipping uploading for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $destPid);
            }
        } else {
            // Application::log("Could not match complete for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $sourcePid);
            // Application::log("Could not match complete for $instrument in dest $destPid $destRecordId ".$completeData[$destPid][$destRecordId]." and source $sourcePid $sourceRecordId ".$completeData[$sourcePid][$sourceRecordId], $destPid);
        }
        return $pidsUpdated;
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

    function preprocessScholarPortal($token, $server, $pid) {
        $this->setProjectSetting("userids", Download::userids($token, $server), $pid);
        $this->setProjectSetting("first_names", Download::firstnames($token, $server), $pid);
        $this->setProjectSetting("last_names", Download::lastnames($token, $server), $pid);
    }

	function executeCron() {
        Application::increaseProcessingMax(8);

		$this->setupApplication();
		if (isset($_GET['pid']) && Application::isVanderbilt()) {
            $activePids = [Sanitizer::sanitizePid($_GET['pid'])];
        } else {
            $activePids = $this->framework->getProjectsWithModuleEnabled();
        }
		Application::log($this->getName()." running for pids ".json_encode($activePids));
        foreach ($activePids as $pid) {
            Application::log("Flight Tracker 'midnight cron' running", $pid);
        }
		$pidsUpdated = [];
        Application::log("Checking for redcaptest in ".SERVER_NAME);
        if (preg_match("/redcaptest.vanderbilt.edu/", SERVER_NAME)) {
            Application::log("Sharing because redcaptest");
            $pidsUpdated = $this->shareDataInternally($activePids, $activePids);
        } else if (count($activePids) > 1) {
            try {
                if (date("N") == "6") {
                    # only on Saturdays
                    $pidsUpdated = $this->shareDataInternally($activePids, $activePids);
                } else if ($this->hasProjectToRunTonight($activePids)) {
                    # only when clicked to run tonight
                    $destPids = $this->getProjectsToRunTonight($activePids);
                    $pidsUpdated = $this->shareDataInternally($activePids, $destPids);
                }
            } catch (\Exception $e) {
                if ((count($activePids) > 0) && CareerDev::isVanderbilt()) {
                    \REDCap::email("scott.j.pearson@vumc.org", Application::getSetting("default_from", $activePids[0]), Application::getProgramName().": Error in sharing surveys", $e->getMessage());
                } else {
                    Application::log("Error in data sharing", $activePids[0]);
                }
            }
        }

        $this->enqueueInitialCrons($activePids);
        if (Application::isVanderbilt()) {
            $this->enqueueMultiProjectCrons($activePids);
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
            Application::log("Using $tokenName $adminEmail", $pid);
            if ($token && $server && !$turnOffSet) {
                try {
                    $this->preprocessScholarPortal($token, $server, $pid);
                    # only have token and server in initialized projects
                    $mgr = new CronManager($token, $server, $pid, $this);
                    if ($this->getProjectSetting("run_tonight", $pid)) {
                        $this->setProjectSetting("run_tonight", FALSE, $pid);
                        # already done in enqueueInitialCrons
                    } else {
                        loadCrons($mgr, FALSE, $token, $server);
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
            $token = "";
            $server = "";
            $pid = "";
            for ($i = 0; $i < count($pids); $i++) {
                $pid = $pids[$i];
                $token = $this->getProjectSetting("token", $pid);
                $server = $this->getProjectSetting("server", $pid);
                if ($pid && $token && $server) {
                    break;
                }
            }
            if ($pid && $token && $server) {
                $mgr = new CronManager($token, $server, $pid, $this);
                loadMultiProjectCrons($mgr, $pids);
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
                        $mgr = new CronManager($token, $server, $pid, $this);
                        loadInitialCrons($mgr, FALSE, $token, $server);
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
</script>\n";
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
    $(document).ready(() => {
        const originalAlert = alert;
        alert = (mssg) => {
            if (!mssg.match(/ERASE THE VALUE OF THE FIELD/)) {
                originalAlert(mssg);
            }
        }
        window.alert = alert;
        
        $('[data-rc-lang=data_entry_532]').parent().parent().hide();
    });
</script>\n";
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
		} else if ($instrument == "mstp_individual_development_plan_idp") {
            require_once(dirname(__FILE__)."/hooks/mstpIDPFormHook.php");
        }
		require_once(dirname(__FILE__)."/hooks/setDateHook.php");
	}

	function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
        if (Application::isTable1Project($project_id)) {
            return;
        }
        require_once(dirname(__FILE__)."/hooks/saveHook.php");
	}

	function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
		$this->setupApplication();
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

        if (MMA_DEBUG) {
            Application::log("Comparing $userid to " . json_encode($validUserids));
        }
        if (in_array($userid, $validUserids)) {
            return TRUE;
        }
        if (defined(MMA_DEBUG) && MMA_DEBUG && isset($_GET['uid']) && in_array($_GET['uid'], $validUserids)) {
            return TRUE;
        }
        return FALSE;
    }

    function makeHeaders($token, $server, $pid, $tokenName) {
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

	private $prefix = "flightTracker";
	private $name = "Flight Tracker";
}
