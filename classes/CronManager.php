<?php

namespace Vanderbilt\CareerDevLibrary;

// require_once(dirname(__FILE__)."/../../../redcap_connect.php");
use Vanderbilt\FlightTrackerExternalModule\FlightTrackerExternalModule;

require_once(__DIR__ . '/ClassLoader.php');

class CronManager {
    const MAX_BATCHES_IN_ONE_CRON = 5;
    const REPEAT_BATCH_WHEN_LESS_THAN = 15;
    const EXCEPTION_EMAIL_SUBJECT = "Flight Tracker Batch Job Exception";
    const DIGEST_EMAIL_SETTING = "exception_emails";
    const BATCH_PREFIX = "batch_setting___";
    const BATCH_FILE_PREFIX = "batch_file_setting___";
    const RUN_JOB_PREFIX = "run_setting___";
    const ERROR_PREFIX = "error_setting___";
    const MAX_BATCHES_IN_QUEUE = 100000;
    const MAX_BATCHES_ALLOWED = 5000;
    const BATCH_SYSTEM_SETTING = "batchCronJobs";
    const ERROR_SYSTEM_SETTING = "cronJobsErrors";
    const RUN_SYSTEM_SETTING = "cronJobsCompleted";
    const UNRESTRICTED_START = "00:00:00";

	public function __construct($token, $server, $pid, $module, $suffix) {
		$this->token = $token;
		$this->server = $server;
		$this->pid = $pid;
		$this->module = $module;
        if ($suffix && !preg_match("/^_/", $suffix)) {
            $this->settingSuffix = "_".$suffix;
        } else {
            $this->settingSuffix = $suffix;
        }
        $this->title = self::getTitle($suffix);

		$this->crons = [];
		$days = self::getDaysOfWeek();
		foreach ($days as $day) {
			$this->crons[$day] = [];
		}

        self::$lastPid = $pid;
        self::$lastAdminEmail = Application::getSetting("admin_email", $pid);
        self::$lastSendErrorLogs = Application::getSetting("send_error_logs", $pid);
	}

    public function addMultiCronInBatches($file, $method, $dateOrDay, $allPids, $batchSize) {
        foreach (array_chunk($allPids, $batchSize) as $batchPids) {
            $this->addMultiCron($file, $method, $dateOrDay, $allPids, $batchPids);
        }
    }



    public function addMultiCron($file, $method, $dayOfWeek, $pids, $firstParameter = FALSE) {
        if ($this->module && !Application::isPluginProject($this->pid)) {
            $this->addCronForBatchMulti($file, $method, $dayOfWeek, $pids, $firstParameter);
        }
    }

	# file is relative to career_dev's root
	# dayOfWeek is in string format - "Monday", "Tuesday", etc. or a date in form Y-M-D
    # records here, if specified, overrides the records specified in function run
	public function addCron($file, $method, $dayOfWeek, $records = [], $numRecordsAtATime = FALSE, $firstParameter = FALSE) {
        try {
	        if ($this->isJobAlreadyQueued($file, $method, $records)) {
		        return;
	        }
            if ($this->module && !Application::isPluginProject($this->pid)) {
                if (is_numeric($records)) {
                    $numRecordsAtATime = $records;
                    $records = [];
                }
                if (!$numRecordsAtATime) {
                    $numRecordsAtATime = self::getNumberOfRecordsForMethod($method);
                }
                $this->addCronForBatch($file, $method, $dayOfWeek, $records, $numRecordsAtATime, $firstParameter);
            } else {
                $this->addCronToRunOnce($file, $method, $dayOfWeek, $records, $firstParameter);
            }
        } catch(\Exception $e) {
            Application::log("ERROR: ".$e->getMessage(), $this->pid);
        }
	}

	private function addCronToRunOnce($file, $method, $dayOfWeek, $records, $firstParameter = FALSE) {
        $possibleDays = self::getDaysOfWeek();
        $dateTs = strtotime($dayOfWeek);
        if (!in_array($dayOfWeek, $possibleDays) && !$dateTs) {
            throw new \Exception("The dayOfWeek ($dayOfWeek) must be a string - 'Monday', 'Tuesday', 'Wednesday', etc. or a date (Y-M-D)");
        }

        $absFile = dirname(__FILE__)."/../".$file;
        if (!file_exists($absFile)) {
            throw new \Exception("File $absFile does not exist!");
        }

        $cronjob = new CronJob($absFile, $method);
        if (!empty($records)) {
            $cronjob->setRecords($records);
        }
        if ($firstParameter !== FALSE) {
            $cronjob->setFirstParameter($firstParameter);
        }
        if ($this->isDebug) {
            Application::log("Has day of week $dayOfWeek and timestamp for ".date("Y-m-d", $dateTs), $this->pid);
        }
        if (in_array($dayOfWeek, $possibleDays)) {
            # Weekday
            if (!isset($this->crons[$dayOfWeek])) {
                $this->crons[$dayOfWeek] = [];
                if ($this->isDebug) {
                    Application::log("Reset cron list for $dayOfWeek", $this->pid);
                }
            }
            $this->crons[$dayOfWeek][] = $cronjob;
            if ($this->isDebug) {
                Application::log("Assigned cron for $dayOfWeek", $this->pid);
            }
        } else if ($dateTs) {
            # Y-M-D
            $date = date(self::getDateFormat(), $dateTs);
            if (!isset($this->crons[$date])) {
                $this->crons[$date] = [];
                if ($this->isDebug) {
                    Application::log("Reset cron list for $date", $this->pid);
                }
            }
            $this->crons[$date][] = $cronjob;
            if ($this->isDebug) {
                Application::log("Assigned cron for $date", $this->pid);
            }
        }
        if ($this->isDebug) {
            Application::log("Added cron $method: ".$this->getNumberOfCrons()." total crons now", $this->pid);
        }
 	}

     public static function enqueueExceptionsMessageInDigest($to, $mssg) {
        $priorMessages = Application::getSystemSetting(self::DIGEST_EMAIL_SETTING) ?: [];
        if (!isset($priorMessages[$to])) {
            $priorMessages[$to] = [];
        }
        $priorMessages[$to][] = date("Y-m-d H:i:s")."<br/>".$mssg;
        try {
            Application::saveSystemSetting(self::DIGEST_EMAIL_SETTING, $priorMessages);
        } catch (\Exception $e) {
            if (self::exceedsMaxCharacters($e->getMessage())) {
                self::sendExceptionDigests();  // clears out array
                if (!self::exceedsMaxCharacters($mssg)) {
                    # avoid infinite loop
                    self::enqueueExceptionsMessageInDigest($to, $mssg);
                }
            } else {
                throw $e;
            }
        }
     }

     public static function sendExceptionDigests() {
         $priorMessages = Application::getSystemSetting(self::DIGEST_EMAIL_SETTING) ?: [];
         foreach ($priorMessages as $to => $messages) {
             \REDCap::email($to, "noreply.flighttracker@vumc.org", self::EXCEPTION_EMAIL_SUBJECT, implode("<hr/>", $messages));
         }
         Application::saveSystemSetting(self::DIGEST_EMAIL_SETTING, []);
     }

    private function addCronForBatchMulti($file, $method, $dayOfWeek, $pids, $firstParameter = FALSE) {
        if (empty($pids)) {
            return;
        }
		if ($this->isJobAlreadyQueued($file, $method, $pids)) {
			return;
		}

        $absFile = dirname(__FILE__)."/../".$file;
        $dateTs = strtotime($dayOfWeek);
        $this->screenFileAndDate($absFile, $dayOfWeek, $dateTs);
        if (in_array($dayOfWeek, self::getDaysOfWeek())) {
            # Weekday
            if (date("l") == $dayOfWeek) {
                $this->enqueueBatchMulti($absFile, $method, $pids);
                if ($this->isDebug) {
                    foreach ($pids as $myPid) {
                        Application::log("Assigned cron for $method on $dayOfWeek", $myPid);
                    }
                }
            }
        } else if ($dateTs) {
            # Y-M-D
            $date = date(self::getDateFormat(), $dateTs);
            if ($date == date(self::getDateFormat())) {
                $this->enqueueBatchMulti($absFile, $method, $pids, $firstParameter);
                if ($this->isDebug) {
                    foreach ($pids as $myPid) {
                        Application::log("Assigned cron for $date", $myPid);
                    }
                }
            }
        }
    }

    private function screenFileAndDate($absFile, $dayOfWeek, $dateTs) {
        if (!in_array($dayOfWeek, self::getDaysOfWeek()) && !$dateTs) {
            throw new \Exception("The dayOfWeek ($dayOfWeek) must be a string - 'Monday', 'Tuesday', 'Wednesday', etc. or a date (Y-M-D)");
        }

        if (!file_exists($absFile)) {
            throw new \Exception("File $absFile does not exist!");
        }

        if ($this->isDebug) {
            Application::log("Has day of week $dayOfWeek and timestamp for ".date("Y-m-d", $dateTs), $this->pid);
        }
    }

	private function addCronForBatch($file, $method, $dayOfWeek, $records, $numRecordsAtATime, $firstParameter = FALSE) {
        if (empty($records)) {
            $metadataFields = Download::metadataFields($this->token, $this->server);
            if (in_array("identifier_stop_collection", $metadataFields)) {
                $records = Download::recordsWithDownloadActive($this->token, $this->server);
            } else {
                $records = Download::recordIds($this->token, $this->server);
            }
        }
        $index = array_search("", $records, TRUE);
        if ($index !== FALSE) {
            array_splice($records, $index, 1);
        }
        if (empty($records)) {
            return;
        }

        $dateTs = strtotime($dayOfWeek);
        $absFile = dirname(__FILE__)."/../".$file;
        $this->screenFileAndDate($absFile, $dayOfWeek, $dateTs);

        if (in_array($dayOfWeek, self::getDaysOfWeek())) {
            # Weekday
            if (date("l") == $dayOfWeek) {
                $this->enqueueBatch($absFile, $method, $records, $numRecordsAtATime, $firstParameter);
                if ($this->isDebug) {
                    Application::log("Assigned cron for $method on $dayOfWeek", $this->pid);
                }
            }
        } else if ($dateTs) {
            # Y-M-D
            $date = date(self::getDateFormat(), $dateTs);
            if ($date == date(self::getDateFormat())) {
                $this->enqueueBatch($absFile, $method, $records, $numRecordsAtATime, $firstParameter);
                if ($this->isDebug) {
                    Application::log("Assigned cron for $date", $this->pid);
                }
            }
        }
    }

    public function clearBatchQueue() {
        $this->saveBatchQueueToDB([]);
    }

    public function resetBatchSettings() {
	    $this->saveBatchQueueToDB([]);
	    $this->saveErrorsToDB([]);
	    $this->saveRunResultsToDB([]);
    }

    private static function getNumberOfRecordsForMethod($method) {
        if (in_array($method, ["updateBibliometrics", "getPubs", "updatePMCs"])) {
            return 10;
        } else if (in_array($method, ["makeSummary", "updateNIHRePORTER"])) {
            return 20;
        } else if (in_array($method, ["processCoeus", "processCoeus2", "getPatents"])) {
            return 100;
        } else if (in_array($method, ["sendUseridsToCOEUS", "getLDAPs", "updateVFRS"])) {
            return 500;
        } else if (in_array($method, ["copyAllCohortProjects", "initialize"])) {
            return 10000;     // not used => run once
        } else {
            return 40;
        }
    }

    private function enqueueBatchMulti($file, $method, $pids, $firstParameter = FALSE) {
        if (!empty($pids)) {
            $batchQueue = $this->getBatchQueueFromDB();
            $batchRow = [
                "file" => $file,
                "method" => $method,
                "pids" => $pids,
                "status" => "WAIT",
                "enqueueTs" => time(),
                "firstParameter" => $firstParameter,
            ];
            $index = $this->getNewBatchQueueIndex();
            $setting = self::BATCH_FILE_PREFIX.$this->settingSuffix.$index;
            Application::saveSystemSetting($setting, $batchRow);
            $batchQueue[] = $setting;
            $this->saveBatchQueueToDB($batchQueue);
        }
    }

	private function enqueueBatch($file, $method, $records, $numRecordsAtATime, $firstParameter = FALSE) {
        $batchQueue = $this->getBatchQueueFromDB();
        $index = $this->getNewBatchQueueIndex();
        for ($i = 0; $i < count($records); $i += $numRecordsAtATime) {
            $subRecords = [];
            for ($j = $i; ($j < count($records)) && ($j < $i + $numRecordsAtATime); $j++) {
                $subRecords[] = $records[$j];
            }
            $batchRow = [
                "file" => $file,
                "method" => $method,
                "pid" => $this->pid,
                "token" => $this->token,
                "server" => $this->server,
                "records" => $subRecords,
                "status" => "WAIT",
                "enqueueTs" => time(),
                "firstParameter" => $firstParameter,
            ];
            $setting = self::BATCH_FILE_PREFIX.$this->settingSuffix.$index;
            Application::saveSystemSetting($setting, $batchRow);
            $batchQueue[] = $setting;
            $index++;
        }
        $this->saveBatchQueueToDB($batchQueue);
    }

    private function saveErrorsToDB($errorQueue) {
        if (empty($errorQueue)) {
            Application::saveSystemSetting(self::ERROR_SYSTEM_SETTING.$this->settingSuffix, []);
            return;
        }
        if (is_array($errorQueue[0])) {
            $this->convertErrorQueueToSettings($errorQueue);
            return;
        }
        try {
            Application::saveSystemSetting(self::ERROR_SYSTEM_SETTING.$this->settingSuffix, $errorQueue);
        } catch (\Exception $e) {
            if (self::exceedsMaxCharacters($e->getMessage())) {
                Application::saveSystemSetting(self::ERROR_SYSTEM_SETTING.$this->settingSuffix, []);
            } else {
                throw $e;
            }
        }
    }

    private function saveRunResultsToDB($runQueue) {
        if (!empty($runQueue) && is_array($runQueue[0])) {
            $this->convertRunQueueToSettings($runQueue);
            return;
        }
        try {
            Application::saveSystemSetting(self::RUN_SYSTEM_SETTING.$this->settingSuffix, $runQueue);
        } catch (\Exception $e) {
            if (self::exceedsMaxCharacters($e->getMessage())) {
                Application::saveSystemSetting(self::RUN_SYSTEM_SETTING.$this->settingSuffix, []);
            } else {
                throw $e;
            }
        }
    }

    private function saveBatchQueueToDB($batchQueue) {
        if (empty($batchQueue)) {
            Application::saveSystemSetting(self::BATCH_SYSTEM_SETTING.$this->settingSuffix, []);
        } else {
            $newBatchQueue = [];
            for ($i = 0; $i < count($batchQueue); $i++) {
                $newBatchQueue[] = $batchQueue[$i];
            }
            try {
                Application::saveSystemSetting(self::BATCH_SYSTEM_SETTING.$this->settingSuffix, $newBatchQueue);
            } catch (\Exception $e) {
                if (self::exceedsMaxCharacters($e->getMessage())) {
                    Application::saveSystemSetting(self::BATCH_SYSTEM_SETTING.$this->settingSuffix, []);
                } else {
                    throw $e;
                }
            }
        }
    }

    private static function exceedsMaxCharacters($mssg) {
        return (
                (
                preg_match("/Cannot save the setting/", $mssg)
                && preg_match("/because the value is larger than the/", $mssg)
                && preg_match("/byte limit/", $mssg)
            )
            || preg_match('/The size of BLOB\/TEXT data inserted in one transaction/', $mssg)
        );
    }

    private function getRunResultsFromDB() {
        $runQueue = Application::getSystemSetting(self::RUN_SYSTEM_SETTING.$this->settingSuffix);
        if (empty($runQueue)) {
            return [];
        }
        if (is_array($runQueue[0])) {
            $this->convertRunQueueToSettings($runQueue);
            $runQueue = Application::getSystemSetting(self::RUN_SYSTEM_SETTING.$this->settingSuffix);
        }
        return $runQueue;
    }

    private function getBatchQueueFromDB() {
        $batchQueue = Application::getSystemSetting(self::BATCH_SYSTEM_SETTING.$this->settingSuffix);
        if (empty($batchQueue)) {
            return [];
        }
        if (is_array($batchQueue[0])) {
            # old system that requires large JSONs to be unnecessarily downloaded
            $this->convertBatchQueueToSettings($batchQueue);
            return $this->getBatchQueueFromDB();
        }
        return $batchQueue;
    }

    private static function getNewIndex($prefix) {
        $i = 1;
        while (
            Application::getSystemSetting($prefix.$i)
            && ($i < self::MAX_BATCHES_IN_QUEUE)
        ) {
            $i++;
        }
        return $i;
    }

    private function getNewRunJobIndex() {
        return $this->getNewIndex(self::RUN_JOB_PREFIX.$this->settingSuffix);
    }

    private function getNewErrorIndex() {
        return $this->getNewIndex(self::ERROR_PREFIX.$this->settingSuffix);
    }

    private function getNewBatchQueueIndex() {
        $i = 0;
        while (
            (
                Application::getSystemSetting(self::BATCH_PREFIX.$this->settingSuffix.$i)
                || Application::getSystemSetting(self::BATCH_FILE_PREFIX.$this->settingSuffix.$i)
            )
            && ($i < self::MAX_BATCHES_IN_QUEUE)
        ) {
            $i++;
        }
        return $i;
    }

    private function convertErrorQueueToSettings($queue) {
        $index = $this->getNewErrorIndex();
        $settings = [];
        for ($i = 0; $i < count($queue); $i++) {
            $setting = self::RUN_JOB_PREFIX.$this->settingSuffix.$index;
            Application::saveSystemSetting($setting, $queue[$i]);
            $settings[] = $setting;
            $index++;
        }
        $this->saveErrorsToDB($settings);
    }

    private function convertRunQueueToSettings($queue) {
        $index = $this->getNewRunJobIndex();
        $settings = [];
        for ($i = 0; $i < count($queue); $i++) {
            $setting = self::RUN_JOB_PREFIX.$this->settingSuffix.$index;
            Application::saveSystemSetting($setting, $queue[$i]);
            $settings[] = $setting;
            $index++;
        }
        Application::saveSystemSetting(self::RUN_SYSTEM_SETTING.$this->settingSuffix, $settings);
    }

    private function convertBatchQueueToSettings($queue) {
        $index = $this->getNewBatchQueueIndex();
        $settings = [];
        for ($i = 0; $i < count($queue); $i++) {
            $index++;
            if (isset($queue[$i]["file"])) {
                $setting = self::BATCH_FILE_PREFIX.$this->settingSuffix.$index;
            } else {
                $setting = self::BATCH_PREFIX.$this->settingSuffix.$index;
            }
            Application::saveSystemSetting($setting, $queue[$i]);
            $settings[] = $setting;
        }
        Application::saveSystemSetting(self::BATCH_SYSTEM_SETTING.$this->settingSuffix, $settings);
    }

    private function getErrorsFromDB() {
        $errors = Application::getSystemSetting(self::ERROR_SYSTEM_SETTING.$this->settingSuffix);
        if (empty($errors)) {
            return [];
        }
        if (is_array($errors[0])) {
            $this->convertErrorQueueToSettings($errors);
            $errors = Application::getSystemSetting(self::ERROR_SYSTEM_SETTING.$this->settingSuffix);
        }
        return $errors;
    }

    private function addRunJobToDB($runJob) {
	    $runJobs = $this->getRunResultsFromDB();
	    if (!$runJobs) {
	        $runJobs = [];
        }
        $index = $this->getNewRunJobIndex();
        $setting = self::RUN_JOB_PREFIX.$this->settingSuffix.$index;
	    $runJobs[] = $setting;
        Application::saveSystemSetting($setting, $runJob);
	    $this->saveRunResultsToDB($runJobs);
    }

    public function upgradeBatchQueueIfNecessary(&$batchQueue, $module) {
        if (empty($batchQueue)) {
            return;
        }
        if (is_array($batchQueue[0])) {
            $this->convertBatchQueueToSettings($batchQueue);
            $batchQueue = $this->getBatchQueueFromDB();
        }
	    $currentVersion = $module->getSystemSetting("version");
        $firstItem = $this->getFirstBatchQueueItem($batchQueue) ?: [];
        if (is_array($firstItem) && isset($firstItem['file']) && !preg_match("/$currentVersion/", $firstItem['file'])) {
            for ($i = 0; $i < count($batchQueue); $i++) {
                $setting = $batchQueue[$i];
                if (strpos($setting, self::BATCH_FILE_PREFIX.$this->settingSuffix) !== FALSE) {
                    $item = Application::getSystemSetting($setting) ?: [];
                    $item['file'] = preg_replace("/_v\d+\.\d+\.\d+\//", "_$currentVersion/", $item['file']);
                    Application::saveSystemSetting($setting, $item);
                }
            }
        }
    }

    public static function getRestrictedTime($cronType, $startEnd) {
        if ($cronType == "intense") {
            if ($startEnd == "start") {
                return "07:00:00";
            } else if ($startEnd == "end") {
                return "19:00:00";
            }
        } else if ($cronType == "long") {
            if ($startEnd == "start") {
                return "06:00:00";
            } else if ($startEnd == "end") {
                return "19:15:00";
            }
        }
        return self::UNRESTRICTED_START;
    }

    public static function getChangedRecords($records, $hours, $pid) {
        if (empty($records)) {
            return $records;
        }
        $threshold = date("YmdHis", time() - $hours * 3600);
        $questionMarks = [];
        while (count($questionMarks) < count($records)) {
            $questionMarks[] = "?";
        }
        $log_event_table = method_exists('\REDCap', 'getLogEventTable') ? \REDCap::getLogEventTable($pid) : "redcap_log_event";
        $module = Application::getModule();
        $sql = "SELECT DISTINCT pk FROM $log_event_table WHERE project_id = ? AND pk IN (".implode(",", $questionMarks).") AND ts >= ? AND data_values NOT LIKE 'summary_last_calculated = \'____-__-__ __:__\''";
        $params = array_merge([$pid], $records, [$threshold]);
        $changedRecords = [];
        $startTime = time();
        $q = $module->query($sql, $params);
        while ($row = $q->fetch_assoc()) {
            if (!in_array($row['pk'], $changedRecords)) {
                $changedRecords[] = $row['pk'];
            }
        }
        $endTime = time();
        $elapsedTime = $endTime - $startTime;
        Application::log("Changed record count from ".count($records)." to ".count($changedRecords)." records in $elapsedTime seconds.", $pid);
        return $changedRecords;
    }

    private function getFirstBatchQueueItem($batchQueue) {
        if (empty($batchQueue)) {
            return [];
        }
        $setting = $batchQueue[0];
        if (is_array($setting)) {
            $this->convertBatchQueueToSettings($batchQueue);
            $batchQueue = $this->getBatchQueueFromDB();
            $setting = $batchQueue[0];
        }
        return Application::getSystemSetting($setting);
    }

    private function cleanBatchQueue() {
        $maxIndex = $this->getNewBatchQueueIndex();
        for ($i = 0; $i < $maxIndex - 1; $i++) {
            Application::saveSystemSetting(self::BATCH_PREFIX.$this->settingSuffix.$i, "");
            Application::saveSystemSetting(self::BATCH_FILE_PREFIX.$this->settingSuffix.$i, "");
        }
    }

    public static function getTitle($suffix) {
        if (in_array($suffix, ["", "_", "main", "_main"])) {
            return "External-API";
        } else if (in_array($suffix, [FlightTrackerExternalModule::LONG_RUNNING_BATCH_SUFFIX, "_".FlightTrackerExternalModule::LONG_RUNNING_BATCH_SUFFIX])) {
            return "Long-Running";
        } else if (in_array($suffix, [FlightTrackerExternalModule::INTENSE_BATCH_SUFFIX, "_".FlightTrackerExternalModule::INTENSE_BATCH_SUFFIX])) {
            return "Resource-Intensive";
        } else if (in_array($suffix, [FlightTrackerExternalModule::LOCAL_BATCH_SUFFIX, "_".FlightTrackerExternalModule::LOCAL_BATCH_SUFFIX])) {
            return "Publication &amp; Local-Resource";
        } else {
            $suffix = preg_replace("/^_/", "", $suffix);
            $suffix = str_replace("_", " ", $suffix);
            return ucfirst($suffix);
        }
    }

    public function runBatchJobs($numRunBeforeInCron = 0) {
	    $module = $this->module;
	    $validBatchStatuses = ["DONE", "ERROR", "RUN", "WAIT"];
        $batchQueue = $this->getBatchQueueFromDB();
        $firstBatchQueue = $this->getFirstBatchQueueItem($batchQueue);

        if (empty($batchQueue)) {
            return;
        }
        $this->upgradeBatchQueueIfNecessary($batchQueue, $module);
        if (
            (
                (count($batchQueue) == 1) && in_array($firstBatchQueue['status'], ["ERROR", "DONE"])
            )
            || (count($batchQueue) > self::MAX_BATCHES_ALLOWED)
        ){
            $this->cleanBatchQueue();
            $this->saveBatchQueueToDB([]);
            return;
        }

        Application::log($this->title.": Currently running ".$firstBatchQueue['method']." for pid ".$firstBatchQueue['pid']." with status ".$firstBatchQueue['status'], $firstBatchQueue['pid']);
        if ($firstBatchQueue['status'] == "RUN") {
            $startTs = isset($firstBatchQueue['startTs']) && is_numeric($firstBatchQueue['startTs']) ? $firstBatchQueue['startTs'] : 0;
            $timespan = 90 * 60;   // max of 90 minutes per segment
            Application::log("Running until ".date("Y-m-d H:i:s", $startTs + $timespan), $firstBatchQueue['pid']);
            if (time() > $startTs + $timespan) {
                // failed batch - probably due to syntax error to avoid shutdown function
                $firstBatchQueue['status'] = "ERROR";
                $firstBatchQueue['cause'] = "Long-running";
            } else {
                # let run
                return;
            }
        }
        if (in_array($firstBatchQueue['status'], ["DONE", "ERROR"])) {
            if ($firstBatchQueue['status'] == "ERROR") {
                Application::log("Saving ERROR ".json_encode($firstBatchQueue), $firstBatchQueue['pid']);
                $errorJobs = $this->getErrorsFromDB();
                $this->addErrorToDB($firstBatchQueue, $errorJobs);
            }
            array_shift($batchQueue);
            $this->saveBatchQueueToDB($batchQueue);
            $firstBatchQueue = $this->getFirstBatchQueueItem($batchQueue);
        }
        if (empty($batchQueue)) {
            return;
        }

        if ($firstBatchQueue['status'] == "WAIT") {
            # Commenting this out on 05-21-2024 due to Mark McEver's request.
            # The only time in the prior year that this method has given me an alert was from outside systems
            # and all of those were for other modules. Thus, registering a shutdown command isn't improving the
            # code but only creating outside noise.
            // register_shutdown_function([$this, "reportCronErrors"]);
            $startTimestamp = self::getTimestamp();
            do {
                $queueHasRun = FALSE;
                if (
                    (count($batchQueue) > 0)
                    && (
                        REDCapManagement::isActiveProject($firstBatchQueue['pid'])
                        || isset($firstBatchQueue['pids'])
                    )
                ) {
                    try {
                        $cronjob = new CronJob($firstBatchQueue['file'], $firstBatchQueue['method']);
                        $firstBatchQueue['startTs'] = time();
                        $firstBatchQueue['status'] = "RUN";
                        if ($firstBatchQueue['firstParameter'] !== FALSE) {
                            $cronjob->setFirstParameter($firstBatchQueue['firstParameter']);
                        }
                        if (isset($firstBatchQueue['pid'])) {
                            $pidMssg = $firstBatchQueue['pid'];
                        } else if (isset($firstBatchQueue['pids'])) {
                            $pidMssg = count($firstBatchQueue['pids'])." pids: ".implode(", ", $firstBatchQueue['pids']);
                        } else {
                            $pidMssg = "[No pids specified]";
                        }
                        $pidsToRun = $firstBatchQueue["pids"] ?? [$firstBatchQueue["pid"]];
                        if (is_array($pidsToRun)) {
                            foreach ($pidsToRun as $myPid) {
                                Application::log($this->title.": Promoting ".$firstBatchQueue['method']." for $pidMssg to RUN (".count($batchQueue)." items in batch queue; ".count($firstBatchQueue['records'] ?? [])." records) at ".self::getTimestamp(), $myPid);
                            }
                        }
                        $this->saveFirstBatchQueueItemToDB($firstBatchQueue, $batchQueue);
                        if (isset($firstBatchQueue['pids'])) {
                            $queueHasRun = TRUE;
                            $cronjob->runMulti($firstBatchQueue['pids']);
                            $this->markFirstItemAsDone();
                            $runJob = [
                                "text" => "Succeeded",
                                "pids" => $firstBatchQueue['pids'],
                                "start" => $startTimestamp,
                                "end" => self::getTimestamp(),
                                "method" => $firstBatchQueue['method'],
                                "file" => $firstBatchQueue['file'],
                                "queue" => $this->settingSuffix,
                            ];
                            $this->addRunJobToDB($runJob);
                        } else if (isset($firstBatchQueue['records'])) {
                            $cronjob->setRecords($firstBatchQueue['records']);
                            if ($firstBatchQueue['firstParameter'] !== FALSE) {
                                $cronjob->setFirstParameter($firstBatchQueue['firstParameter']);
                            }
                            $queueHasRun = TRUE;
                            $cronjob->run($firstBatchQueue['token'], $firstBatchQueue['server'], $firstBatchQueue['pid'], $firstBatchQueue['records']);
                            $this->markFirstItemAsDone();
                            $endTimestamp = self::getTimestamp();
                            $runJob = [
                                "text" => "Succeeded",
                                "records" => $firstBatchQueue['records'],
                                "start" => $startTimestamp,
                                "end" => $endTimestamp,
                                "pid" => $firstBatchQueue['pid'],
                                "method" => $firstBatchQueue['method'],
                                "file" => $firstBatchQueue['file'],
                                "queue" => $this->settingSuffix,
                            ];
                            $this->addRunJobToDB($runJob);

                            $elapsedSeconds = strtotime($endTimestamp) - strtotime($startTimestamp);
                            $numRunBeforeInCron++;
                            if (
                                ($elapsedSeconds < self::REPEAT_BATCH_WHEN_LESS_THAN)
                                && ($numRunBeforeInCron <= self::MAX_BATCHES_IN_ONE_CRON)
                            ) {
                                sleep(1);
                                Application::log("Flight Tracker repeating cron $this->title", $firstBatchQueue['pid']);
                                $this->runBatchJobs($numRunBeforeInCron);
                            }
                        } else {
                            throw new \Exception("Invalid batch job ".REDCapManagement::json_encode_with_spaces($firstBatchQueue));
                        }
                    } catch (\Throwable $e) {
                        Application::log($e->getMessage()."\n".$e->getTraceAsString());
                        $this->handleBatchError($startTimestamp, $e);
                    }
                } else if (count($batchQueue) > 0) {
                    # inactive project
                    array_shift($batchQueue);
                    $this->saveBatchQueueToDB($batchQueue);
                } else {
                    # empty batchQueue
                    $this->saveBatchQueueToDB($batchQueue);
                    return;
                }
				if (!$queueHasRun) {
					sleep(60);
					Application::log("End of CronManager.php While Loop $this->title");
				}
            } while (!$queueHasRun);
        } else if (!in_array($firstBatchQueue['status'], $validBatchStatuses)) {
            throw new \Exception("Improper batch status ".$firstBatchQueue['status']);
        }
    }
    
    private function addErrorToDB($job, $errorQueue) {
        if (isset($errorQueue[0]) && is_array($errorQueue)) {
            $this->convertErrorQueueToSettings($errorQueue);
            $errorQueue = $this->getErrorsFromDB();
        }
        $index = $this->getNewErrorIndex();
        $setting = self::ERROR_PREFIX.$this->settingSuffix.$index;
        Application::saveSystemSetting($setting, $job);
        $errorQueue[] = $setting;
        Application::saveSystemSetting(self::ERROR_SYSTEM_SETTING.$this->settingSuffix, $errorQueue);
    }

    public function markFirstItemAsDone() {
        $batchQueue = $this->getBatchQueueFromDB();
        if (empty($batchQueue)) {
            # queue was cleared
            return;
        }
        $firstBatchItem = $this->getFirstBatchQueueItem($batchQueue);
        $pidsToRun = $firstBatchItem["pids"] ?? [$firstBatchItem["pid"]];
        if (is_array($pidsToRun)) {
            foreach ($pidsToRun as $myPid) {
                Application::log($this->title.": Done with ".$firstBatchItem['method']." at ".self::getTimestamp(), $myPid);
            }
        }
        $firstBatchItem['status'] = "DONE";
        $firstBatchItem['endTs'] = time();
        $this->saveFirstBatchQueueItemToDB($firstBatchItem, $batchQueue);
    }

    private static function saveFirstBatchQueueItemToDB($firstBatchItem, $batchQueue) {
        if (empty($batchQueue)) {
            return;
        }
        $setting = $batchQueue[0];
        Application::saveSystemSetting($setting, $firstBatchItem);
    }

    # likely for non-active projects
    private function cleanOldResults($runJobs, $errorQueue) {
        $oneDay = 24 * 3600;
        $thresholdTs = time() - 14 * $oneDay;

        if (!empty($runJobs) && is_array($runJobs[0])) {
            $this->convertRunQueueToSettings($runJobs);
            $runJobs = $this->getRunResultsFromDB();
        }
        if (!empty($errorQueue) && is_array($errorQueue[0])) {
            $this->convertErrorQueueToSettings($errorQueue);
            $errorQueue = $this->getErrorsFromDB();
        }

        $newRunJobs = [];
        $hasDeletedRunJob = FALSE;
        foreach ($runJobs as $setting) {
            $row = Application::getSystemSetting($setting) ?: [];
            if (isset($row['end']) && $row['end']) {
                $ts = strtotime($row['end']);
                if ($ts > $thresholdTs) {
                    $newRunJobs[] = $setting;
                } else {
                    $hasDeletedRunJob = TRUE;
                }
            }
        }
        if ($hasDeletedRunJob) {
            $this->saveRunResultsToDB($newRunJobs);
        }

        $newErrorQueue = [];
        $hasDeletedErrors = FALSE;
        foreach ($errorQueue as $setting) {
            $row = Application::getSystemSetting($setting) ?: [];
            if (isset($row['endTs']) && $row['endTs']) {
                $ts = $row['endTs'];
                if ($ts > $thresholdTs) {
                    $newErrorQueue[] = $setting;
                } else {
                    $hasDeletedErrors = TRUE;
                }
            }
        }
        if ($hasDeletedErrors) {
            $this->saveErrorsToDB($newErrorQueue);
        }
        return [$newRunJobs, $newErrorQueue];
    }

    public function sendEmails($pids, $module, $additionalEmailText = "") {
        $batchQueue = $this->getBatchQueueFromDB();
        $runJobs = $this->getRunResultsFromDB();
        $errorQueue = $this->getErrorsFromDB();
        list($runJobs, $errorQueue) = $this->cleanOldResults($runJobs, $errorQueue);
        if (empty($batchQueue)) {
            foreach ($pids as $pid) {
                if (self::hasDataForPid($runJobs, $pid) || self::hasDataForPid($errorQueue, $pid)) {
                    $this->sendEmailForProjectIfPossible($pid, $module, $additionalEmailText);
                }
            }
        }
    }

    private static function hasDataForPid($queue, $pid) {
	    foreach ($queue as $item) {
	        if (is_array($item) && ($item['pid'] == $pid)) {
	            return TRUE;
            } else if (is_string($item)) {
                $setting = $item;
                $item = Application::getSystemSetting($setting) ?: [];
                if ($item['pid'] == $pid) {
                    return TRUE;
                }
            }
        }
	    return FALSE;
    }

    private static function getProjectTitle($pid) {
        try {
            return Download::projectTitle($pid);
        } catch (\Exception $e) {
            if (preg_match("/You do not have permissions to use the API/", $e->getMessage())) {
                return "[Project Title Unavailable]";
            } else {
                throw $e;
            }
        }
    }

    private function sendEmailForProjectIfPossible($pid, $module, $additionalEmailText) {
        $token = $module->getProjectSetting("token", $pid);
        $server = $module->getProjectSetting("server", $pid);
        if ($token && $server) {
            $adminEmail = $module->getProjectSetting("admin_email", $pid);
            $projectTitle = self::getProjectTitle($pid);

            $text = "";
            $hasData = FALSE;
            $hasErrors = FALSE;
            $errorQueue = $this->getErrorsFromDB();
            foreach ($errorQueue as $setting) {
                $errorJob = Application::getSystemSetting($setting) ?: [];
                if (isset($errorJob['pid']) && ($errorJob['pid'] == $pid)) {
                    $hasErrors = TRUE;
                }
            }

            if ($hasErrors) {
                $text .= "<h2>Errors &amp; Warnings!</h2>";
            }


            $remainingErrors = [];
            foreach ($errorQueue as $setting) {
                $errorJob = Application::getSystemSetting($setting) ?: [];
                if (isset($errorJob['pid']) && ($errorJob['pid'] == $pid)) {
                    $method = $errorJob['method'];
                    # throw an error for every method EXCEPT copyAllCohortProjects
                    if ($method != "copyAllCohortProjects") {
                        $text .= "<h3>$method</h3>";
                        $text .= "<p>";
                        $text .= "Records in batch job: ".implode(", ", $errorJob['records'])."<br/>";
                        if (isset($errorJob['record'])) {
                            $text .= "Failed in record ".$errorJob['record']."<br/>";
                        }
                        if (isset($errorJob['error'])) {
                            $text .= "Error Message: ".$errorJob['error']."<br/>";
                            if (isset($errorJob['error_location'])) {
                                $text .= "Location:".$errorJob['error_location']."<br/>";
                            }
                        } else if (isset($errorJob['cause'])) {
                            $cause = $errorJob['cause'];
                            if ($cause == "Long-running") {
                                $text .= "This cron-job (for $method) was <strong>running longer than expected</strong>. This likely is not a major issue. Sometimes, jobs run longer than expected because one scholar has a lot of data.<br/>";
                            } else {
                                $text .= "Cause: $cause<br/>";
                            }
                        } else {
                            $text .= "<strong>Unknown error</strong><br/>";
                            foreach ($errorJob as $header => $item) {
                                $text .= "$header: $item<br/>";
                            }
                        }
                        $text = preg_replace("/<br\/>$/", "", $text);
                        $text .= "</p>";
                    }
                    Application::saveSystemSetting($setting, []);
                } else {
                    $remainingErrors[] = $setting;
                }
            }
            $this->saveErrorsToDB($remainingErrors);

            $runJobs = $this->getRunResultsFromDB();
            $remainingJobs = [];
            $methods = [];
            $newMethod = [
                "succeededRecords" => [],
                "attemptedRecords" => [],
                "succeededLastTs" => 0,
                "attemptedLastTs" => 0,
                "succeededFirstTs" => time(),
                "attemptedFirstTs" => time(),
            ];
            $allRecords = Download::recordIds($token, $server);
            foreach ($runJobs as $setting) {
                $job = Application::getSystemSetting($setting) ?: [];
                if (isset($job['pid']) && ($job['pid'] == $pid)) {
                    $method = $job['method'];
                    $prefix = strtolower($job['text']);
                    if (!isset($methods[$method])) {
                        $methods[$method] = [];
                    }
                    $currMethod = $newMethod;
                    $priorRecords = [];
                    foreach ($methods[$method] as $settingsAry) {
                        $priorRecords = array_unique(array_merge($priorRecords, $settingsAry[$prefix . "Records"] ?? []));
                    }
                    $currMethod[$prefix . "Records"] = array_merge($priorRecords, $job['records']);
                    $endTs = strtotime($job['end']);
                    if ($endTs > $currMethod[$prefix . "LastTs"]) {
                        $currMethod[$prefix . "LastTs"] = $endTs;
                    }
                    $startTs = strtotime($job['start']);
                    if ($startTs < $currMethod[$prefix . "FirstTs"]) {
                        $currMethod[$prefix . "FirstTs"] = $startTs;
                    }
                    $methods[$method][] = $currMethod;
                    Application::saveSystemSetting($setting, []);
                } else if (is_array($job) && !isset($job['pids'])) {
                    $remainingJobs[] = $setting;
                }
            }

            $starts = [];
            $ends = [];
            foreach ($methods as $method => $settingsAry) {
                if (REDCapManagement::isAssoc($settingsAry)) {
                    $settingsAry = [$settingsAry];
                }
                if (!empty($settingsAry)) {
                    if (count($settingsAry) > 1) {
                        $batchesText = " (".count($settingsAry)." Batches)";
                    } else {
                        $batchesText = "";
                    }
                    $text .= "<h3>$method$batchesText</h3>";
                }
                $starts[$method] = time();
                $ends[$method] = 0;
                foreach ($settingsAry as $settings) {
                    foreach (["attempted", "succeeded"] as $prefix) {
                        if (!empty($settings[$prefix.'Records'])) {
                            $hasData = TRUE;
                            $text .= "<p>".ucfirst($prefix)."<br/>";
                            $text .= self::getRecordsText($settings[$prefix."Records"], $allRecords)."<br/>";
                            if (is_numeric($settings[$prefix.'FirstTs'])) {
                                $text .= "Start: ".date("Y-m-d H:i:s", $settings[$prefix.'FirstTs'])."<br/>";
                            }
                            if (is_numeric($settings[$prefix.'LastTs'])) {
                                $text .= "End: ".date("Y-m-d H:i:s", $settings[$prefix.'LastTs'])."<br/>";
                            }
                            $text .= "</p>";
                            if ($settings[$prefix."FirstTs"] < $starts[$method]) {
                                $starts[$method] = $settings[$prefix."FirstTs"];
                            }
                            if ($settings[$prefix."LastTs"] > $ends[$method]) {
                                $ends[$method] = $settings[$prefix."LastTs"];
                            }
                        }
                    }
                }
            }

            if ($hasData) {
                if (!class_exists("\REDCap") || !method_exists("\REDCap", "email")) {
                    require_once(dirname(__FILE__)."/../../../redcap_connect.php");
                }
                if (!class_exists("\REDCap") || !method_exists("\REDCap", "email")) {
                    throw new \Exception("Could not instantiate REDCap class!");
                }
                $addlSubject = "";
                if (Application::isLocalhost()) {
                    $addlSubject = " from localhost";
                }
                Application::log("Sending ".Application::getProgramName()." email for pid ".$pid." to $adminEmail", $pid);
                $emailMssg = $this->makeEmailMessage($token, $server, $pid, $text, $additionalEmailText, $starts, $ends);
                \REDCap::email($adminEmail, Application::getSetting("default_from", $pid), Application::getProgramName()." Cron Report".$addlSubject, $emailMssg);
            }

            $this->saveRunResultsToDB($remainingJobs);
        }
    }

    private static function getRecordsText($records, $allRecords) {
        $recordsAreSequential = self::areRecordsSequential($records, $allRecords);
        $numRecords = count($records);
        if (($numRecords > 1) && $recordsAreSequential) {
            $firstRecord = $records[0];
            $lastRecord = $records[$numRecords - 1];
            return count($records) . " Records ($firstRecord - $lastRecord)";
        } else if (($numRecords > 1) && !$recordsAreSequential) {
            return "Records ".REDCapManagement::makeConjunction($records);
        } else {
            $singleRecord = $records[0];
            return "Record $singleRecord";
        }
    }

    private static function areRecordsSequential($records, $allRecords) {
        if (empty($records)) {
            return TRUE;
        }
        $firstRecord = $records[0];
        $start = 0;
        foreach ($allRecords as $i => $record) {
            if ($record == $firstRecord) {
                $start = $i;
            }
        }

        $numRecords = count($records);
        $numAllRecords = count($allRecords);
        for ($i = 0; $i < $numRecords; $i++) {
            if ($start + $i > $numAllRecords) {
                return FALSE;
            }
            if ($records[$i] != $allRecords[$start + $i]) {
                return FALSE;
            }
        }
        return TRUE;
    }

    private static function makeEmailMessage($token, $server, $pid, $completedText, $finalMessage, $startsByMethod, $endsByMethod) {
        $metadata = Download::metadata($token, $server);
        $firstNames = Download::firstnames($token, $server);
        $lastNames = Download::lastnames($token, $server);
        $metadataFields = DataDictionaryManagement::getFieldsFromMetadata($metadata);
        $validInstruments = [
            "updateAllCOEUS" => "coeus",
            "updateCOEUSSubmissions" => "coeus_submission",
            "updateNIHRePORTER" => "nih_reporter",
            "getPubs" => "citation",
            "updateBibliometrics" => "citation",
            "getPatents" => "patent",
            "getNSFGrants" => "nsf",
            "getIES" => "ies_grant",
            "getERIC" => "eric",
        ];
        $projectTitle = self::getProjectTitle($pid);

        $html = "<style>
p.header { background-color: #d4d4eb; padding: 10px; }
h2 { background-color: #f4c3ff; padding: 2px; }
h3,h4 { margin-bottom: 0; }
h3~p,h4~p { margin-top: 0; }
h4 { font-size: 1.1em; }
.light_green { background-color: #b0d87a; }
body { font-size: 1.2em; }
</style>";
        $html .= "<h1>".Application::getProgramName()." Nightly Update</h1>";
        $html .= "<p class='header'>Project: ".Links::makeProjectHomeLink($pid, $projectTitle)."<br/>";
        $html .= "PID: $pid<br/>";
        $html .= "Server: $server</p>";
        $impact = [];
        $impactedRecords = [];
        foreach ($startsByMethod as $method => $startTs) {
            $newDataRows = [];
            if (!isset($validInstruments[$method])) {
                continue;
            }
            $instrument = $validInstruments[$method];
            $endTs = 0;
            if (isset($endsByMethod[$method]) && is_numeric($endsByMethod[$method])) {
                $endTs = (int) $endsByMethod[$method];
            } else if (isset($endsByMethod[$method]) && strtotime($endsByMethod[$method])) {
                $endTs = strtotime($endsByMethod[$method]);
            }
            $startDate = date("Y-m-d", $startTs);
            $endDate = date("Y-m-d", $endTs);
            $dates = self::getDatesInRange($startDate, $endDate);
            $prefix = REDCapManagement::getPrefixFromInstrument($instrument);
            if (!preg_match("/_$/", $prefix)) {
                $prefix .= "_";
            }
            $lastUpdateField = $prefix."last_update";
            if (in_array($lastUpdateField, $metadataFields) && in_array($instrument, $validInstruments)) {
                $fields = [$lastUpdateField];
                if ($method == "updateBibliometrics") {
                    $fields[] = "citation_icite_last_update";
                    $fields[] = "citation_altmetric_last_update";
                }
                $matchedInstances = [];
                foreach ($fields as $field) {
                    if (in_array($field, $metadataFields)) {
                        $values = Download::oneFieldWithInstances($token, $server, $field);
                        foreach ($dates as $currDate) {
                            foreach ($values as $recordId => $instances) {
                                foreach ($instances as $instance => $date) {
                                    if (($date == $currDate) && !in_array($instance, $matchedInstances[$recordId] ?? [])) {
                                        if (!isset($matchedInstances[$recordId])) {
                                            $matchedInstances[$recordId] = [];
                                        }
                                        $matchedInstances[$recordId][] = $instance;
                                    }
                                }
                            }
                        }
                    } else {
                        Application::log("Could not find $field in metadata",  $pid);
                    }
                }

                if (!empty($matchedInstances)) {
                    $redcapData = Download::formForRecords($token, $server, $instrument, array_keys($matchedInstances));
                    foreach ($redcapData as $row) {
                        $recordId = $row['record_id'];
                        if (!isset($matchedInstances[$recordId])) {
                            throw new \Exception("Cannot find Record $recordId for records ".json_encode(array_keys($matchedInstances)));
                        }
                        if (($row['redcap_repeat_instrument'] == $instrument) && in_array($row['redcap_repeat_instance'], $matchedInstances[$recordId])) {
                            if (!isset($newDataRows[$recordId])) {
                                $newDataRows[$recordId] = [];
                            }
                            if (!in_array($recordId, $impactedRecords)) {
                                $impactedRecords[] = $recordId;
                            }
                            $newDataRows[$recordId][] = $row;
                        }
                    }
                    $methodImpact = self::calculateImpact($newDataRows, $firstNames, $lastNames, $dates, $token, $server, $pid, $metadata);
                    $impact = array_merge($impact, $methodImpact);
                }
            }
            if (!empty($impactedRecords)) {
                $impact["Total Number of Impacted Records"] = count($impactedRecords);
            }
        }

        if (!empty($impact)) {
            $html .= "<h2>Impact</h2>";
            $lines = [];
            foreach ($impact as $header => $description) {
                $lines[] = "<h3 class='light_green'>$header</h3><p style='margin-top: 0;'>$description</p>";
            }
            $html .= implode("<br/>", $lines);
        }

        $html .= "<h2>Overnight Cron Jobs Run</h2>";
        $html .= $completedText;
        if ($finalMessage) {
            $html .= "<h2>Final Message</h2>".$finalMessage;
        }

        return $html;
    }

    public static function getDatesInRange($startDate, $endDate) {
        if ($startDate == $endDate) {
            return [$startDate];
        }
        $ts = strtotime($startDate);
        $endTs = strtotime($endDate);
        $oneDay = 24 * 3600;

        $dates = [];
        do {
            $currDate = date("Y-m-d", $ts);
            $dates[] = $currDate;
            $ts += $oneDay;
        } while ($ts <= $endTs);
        return $dates;
    }

    public static function calculateImpact($indexedREDCapData, $firstNames, $lastNames, $dates, $token, $server, $pid, $metadata) {
        if (empty($indexedREDCapData)) {
            return [];
        }
        $maxToDisplay = 20;
        $rcrThreshold = 8;
        $altmetricThreshold = 100;
        $lexicalTranslator = new GrantLexicalTranslator($token, $server, Application::getModule(), $pid);

        $impact = [];
        $numNewPubMedPubs = 0;
        $scholarsWithNewPubMedPubs = [];
        $numNewERICPubs = 0;
        $scholarsWithNewERICPubs = [];
        $newGrants = [];
        $newPubsWithHighRCR = [];
        $newPubsWithHighAltmetric = [];
        $updatedPubsWithHighRCR = [];    // not yet used
        $updatedPubsWithHighAltmetric = [];     // not yet used
        $newPatents = [];

        foreach ($indexedREDCapData as $recordId => $rows) {
            $hasPatent = FALSE;
            $name = NameMatcher::formatName($firstNames[$recordId] ?? "", "", $lastNames[$recordId] ?? "");
            foreach ($rows as $row) {
                if ($row['redcap_repeat_instrument'] == "citation") {
                    $citation = new Citation($token, $server, $recordId, $row['redcap_repeat_instance'], $row, $metadata, $lastNames[$recordId] ?? "", $firstNames[$recordId] ?? "");
                    $citationText = $citation->getEtAlCitationWithLink(FALSE);
                    $scores = " RCR: ".$row['citation_rcr'].". Altmetric Score: ".$row['citation_altmetric_score'].".";
                    if (in_array($row['citation_last_update'], $dates)) {
                        $numNewPubMedPubs++;
                        if (!isset($scholarsWithNewPubMedPubs[$recordId])) {
                            $scholarsWithNewPubMedPubs[$recordId] = 0;
                        }
                        $scholarsWithNewPubMedPubs[$recordId]++;
                        if ($row['citation_rcr'] >= $rcrThreshold) {
                            if (!isset($newPubsWithHighRCR[$recordId])) {
                                $newPubsWithHighRCR[$recordId] = [];
                            }
                            $newPubsWithHighRCR[$recordId][] = $citationText.$scores;
                        }
                        if ($row['citation_altmetric_score'] >= $altmetricThreshold) {
                            if (!isset($newPubsWithHighAltmetric[$recordId])) {
                                $newPubsWithHighAltmetric[$recordId] = [];
                            }
                            $newPubsWithHighAltmetric[$recordId][] = $citationText.$scores;
                        }
                    } else {
                        if (
                            in_array($row['citation_icite_last_update'], $dates)
                            && ($row['citation_rcr'] >= $rcrThreshold)
                        ) {
                            if (!isset($updatedPubsWithHighAltmetric[$recordId])) {
                                $updatedPubsWithHighRCR[$recordId] = [];
                            }
                            $updatedPubsWithHighRCR[$recordId][] = $citationText.$scores;
                        }
                        if (
                            in_array($row['citation_altmetric_last_update'], $dates)
                            && ($row['citation_altmetric_score'] >= $altmetricThreshold)
                        ) {
                            if (!isset($updatedPubsWithHighAltmetric[$recordId])) {
                                $updatedPubsWithHighAltmetric[$recordId] = [];
                            }
                            $updatedPubsWithHighAltmetric[$recordId][] = $citationText.$scores;
                        }
                    }
                } else if ($row['redcap_repeat_instrument'] == "eric") {
                    if (in_array($row['eric_last_update'], $dates)) {
                        $numNewERICPubs++;
                        if (!isset($scholarsWithNewERICPubs[$recordId])) {
                            $scholarsWithNewERICPubs[$recordId] = 0;
                        }
                        $scholarsWithNewERICPubs[$recordId]++;
                    }

                } else if ($row['redcap_repeat_instrument'] == "patent") {
                    $hasPatent = TRUE;
                } else if (in_array($row['redcap_repeat_instrument'], ["nih_reporter", "coeus", "nsf"])) {
                    $gf = NULL;
                    if (
                        ($row['redcap_repeat_instrument'] == "nih_reporter")
                        && in_array($row["nih_last_update"], $dates)
                    ) {
                        $gf = new NIHRePORTERGrantFactory($name, $lexicalTranslator, $metadata, $token, $server);
                    } else if (
                        ($row['redcap_repeat_instrument'] == "coeus")
                        && in_array($row["coeus_last_update"], $dates)
                    ) {
                        $gf = new CoeusGrantFactory($name, $lexicalTranslator, $metadata, $token, $server);
                    } else if (
                        ($row['redcap_repeat_instrument'] == "nsf")
                        && in_array($row["nsf_last_update"], $dates)
                    ) {
                        $gf = new NSFGrantFactory($name, $lexicalTranslator, $metadata, $token, $server);
                    }
                    if (isset($gf)) {
                        $gf->processRow($row, $rows, $token);
                        $grants = $gf->getGrants();
                        if (!isset($newGrants[$recordId])) {
                            $newGrants[$recordId] = [];
                        }
                        $newGrants[$recordId] = array_merge($newGrants[$recordId], $grants);
                    }
                }
            }
            if ($hasPatent) {
                $patents = new Patents($recordId, $pid, $firstNames[$recordId] ?? "", $lastNames[$recordId] ?? "");
                $patents->setRows($rows);
                $html = $patents->getHTML();
                if ($html) {
                    $newPatents[$recordId] = $html;
                }
            }
        }

        $numNewGrants = REDCapManagement::totalValuesInArray($newGrants);
        if ($numNewGrants > 0) {
            if ($numNewGrants <= $maxToDisplay) {
                $html = "";
                foreach ($newGrants as $recordId => $grants) {
                    $name = NameMatcher::formatName($firstNames[$recordId] ?? "", "", $lastNames[$recordId] ?? "");
                    $name = Links::makeRecordHomeLink($pid, $recordId, $name);
                    $html .= "<h4>$name</h4>";
                    foreach ($grants as $grant) {
                        $html .= "<p>";
                        $awardNo = $grant->getNumber();
                        $awardDescript = Grant::parseNumber($awardNo);
                        $descriptionNodes = [];
                        if ($awardNo) {
                            $descriptionNodes[] = "<strong>$awardNo</strong>";
                        } else if ($grant->getVariable("original_award_number")) {
                            $descriptionNodes[] = $grant->getVariable("original_award_number");
                        }
                        if (isset($awardDescript["support_year"])) {
                            if ($grant->getVariable("subproject")) {
                                $descriptionNodes[] = "Sub-Project";
                            } else if ($awardDescript['support_year'] == "01") {
                                $descriptionNodes[] = "New";
                            } else {
                                $descriptionNodes[] = "Renewal";
                            }
                        }
                        if ($grant->getVariable("start") && $grant->getVariable("end")) {
                            $budgetPeriod = DateManagement::YMD2MDY($grant->getVariable("start"))." - ".DateManagement::YMD2MDY($grant->getVariable("end"));
                            if ($grant->getVariable("project_start") && $grant->getVariable("project_end")) {
                                $projectPeriod = DateManagement::YMD2MDY($grant->getVariable("project_start"))." - ".DateManagement::YMD2MDY($grant->getVariable("project_end"));
                                $descriptionNodes[] = "Project Period ($projectPeriod), Budget Period ($budgetPeriod)";
                            } else {
                                $descriptionNodes[] = "Budget Period ($budgetPeriod)";
                            }
                        }
                        if ($grant->getVariable("sponsor")) {
                            $descriptionNodes[] = "from ".$grant->getVariable("sponsor");
                        } else if ($awardDescript["funding_institute"]) {
                            $descriptionNodes[] = "from ".$awardDescript['funding_institute'];
                        }
                        if ($grant->getVariable("role")) {
                            $descriptionNodes[] = "Role ".$grant->getVariable("role");
                        }
                        if ($grant->getVariable("direct_budget")) {
                            $descriptionNodes[] = "Direct Budget ".REDCapManagement::prettyMoney($grant->getVariable("direct_budget"));
                        } else if ($grant->getVariable("budget")) {
                            $descriptionNodes[] = "Total Budget ".REDCapManagement::prettyMoney($grant->getVariable("budget"));
                        }

                        $html .= implode("<br/>", $descriptionNodes)."<br/>";
                        $html .= "</p>";
                    }
                }
            } else {
                $affectedNames = [];
                foreach ($newGrants as $recordId => $grants) {
                    $name = NameMatcher::formatName($firstNames[$recordId] ?? "", "", $lastNames[$recordId] ?? "");
                    $name = Links::makeRecordHomeLink($pid, $recordId, $name);
                    if (count($grants) > 1) {
                        $affectedNames[] = $name." (".count($grants).")";
                    } else {
                        $affectedNames[] = $name;
                    }
                }
                $html = "For Scholars: ".REDCapManagement::makeConjunction($affectedNames);
            }
            if (($numNewGrants == 1) && $html) {
                $impact["$numNewGrants New Grant"] = $html;
            } else if ($html) {
                $impact["$numNewGrants New Grants"] = $html;
            }
        }

        self::addPublications($impact, $numNewERICPubs, $scholarsWithNewERICPubs, "ERIC", $pid, $firstNames, $lastNames);
        self::addPublications($impact, $numNewPubMedPubs, $scholarsWithNewPubMedPubs, "PubMed", $pid, $firstNames, $lastNames);

        if (!empty($newPubsWithHighRCR) && !empty($newPubsWithHighAltmetric)) {
            $html = "";
            $newPubs = [
                "High Relative Citation Ratio (&gt; $rcrThreshold)" => $newPubsWithHighRCR,
                "High Altmetric Score (&gt; $altmetricThreshold)" => $newPubsWithHighAltmetric,
            ];
            foreach ($newPubs as $title => $pubAry) {
                $totalPubs = REDCapManagement::totalValuesInArray($pubAry);
                if (($totalPubs > 0) && ($totalPubs < $maxToDisplay)) {
                    $html .= "<h4>$title</h4>";
                    foreach ($pubAry as $recordId => $citationAry) {
                        $name = NameMatcher::formatName($firstNames[$recordId] ?? "", "", $lastNames[$recordId] ?? "");
                        $citationCount = count($citationAry);
                        if ($citationCount == 1) {
                            $html .= "<p><strong>$name</strong>: ".$citationAry[0]."</p>";
                        } else if ($citationCount > 1) {
                            $html .= "<p><strong>$name</strong> (".$citationCount.")<br/>".implode("<br/>", $citationAry)."</p>";
                        }
                    }
                }
            }
            if ($html) {
                $impact["New High-Impact Papers"] = $html;
            }
        }

        if (!empty($newPatents)) {
            if (count($newPatents) <= $maxToDisplay) {
                $html = "";
                foreach ($newPatents as $recordId => $patentHTML) {
                    $name = NameMatcher::formatName($firstNames[$recordId] ?? "", "", $lastNames[$recordId] ?? "");
                    $html .= "<h4>$name</h4>".$patentHTML;
                }
            } else {
                $affectedNames = [];
                foreach (array_keys($newPatents) as $recordId) {
                    $name = NameMatcher::formatName($firstNames[$recordId] ?? "", "", $lastNames[$recordId] ?? "");
                    $affectedNames[] = $name;
                }
                $html = count($newPatents)." Downloaded for ".REDCapManagement::makeConjunction($affectedNames);
            }
            if ($html) {
                $impact["New Patents"] = $html;
            }
        }

        return $impact;
    }

    private static function addPublications(&$impact, $numNewPubs, $scholarsWithNewPubs, $pubType, $pid, $firstNames, $lastNames) {
        if ($numNewPubs > 0) {
            $affectedNames = [];
            foreach ($scholarsWithNewPubs as $recordId => $numPubs) {
                $name = NameMatcher::formatName($firstNames[$recordId] ?? "", "", $lastNames[$recordId] ?? "");
                $suffix = ($numPubs > 1) ? " ($numPubs)" : "";
                $affectedNames[] = Links::makeRecordHomeLink($pid, $recordId, $name.$suffix);
            }
            $scholarHTML = "<strong>For Scholars</strong>: ".REDCapManagement::makeConjunction($affectedNames);
            if ($numNewPubs == 1) {
                $impact["$numNewPubs New $pubType Publication"] = $scholarHTML;
            } else {
                $impact["$numNewPubs New $pubType Publications"] = $scholarHTML;
            }
        }
    }

    public function getBatchQueue() {
	    return $this->getBatchQueueFromDB();
    }

    private function handleBatchError($startTimestamp, $exception, $record = FALSE) {
        $batchQueue = $this->getBatchQueueFromDB();
        $firstBatchItem = $this->getFirstBatchQueueItem($batchQueue);
	    $mssg = $exception->getMessage();
	    $trace = $exception->getTraceAsString();
        $pidsToUpdate = $firstBatchItem["pids"] ?? [$firstBatchItem["pid"]];
        foreach ($pidsToUpdate as $myPid) {
            Application::log("handleBatchError: ".json_encode($firstBatchItem), $myPid);
            Application::log($mssg." ".$trace, $myPid);
        }

        $firstBatchItem['status'] = "ERROR";
        $firstBatchItem['cause'] = "Exception";
        $firstBatchItem['endTs'] = time();
        $firstBatchItem['error'] = $mssg;
        $firstBatchItem['error_location'] = $trace;
        if ($record) {
            $firstBatchItem['record'] = $record;
        }
        $this->saveFirstBatchQueueItemToDB($firstBatchItem, $batchQueue);

        if (isset($firstBatchItem['pids'])) {
            $runJob = [
                "method" => $firstBatchItem['method'],
                "text" => "Attempted",
                "start" => $startTimestamp,
                "end" => self::getTimestamp(),
                "pids" => $firstBatchItem['pids'],
                "error" => $mssg,
                "error_location" => $trace,
            ];
        } else if (isset($firstBatchItem['records'])) {
            $runJob = [
                "method" => $firstBatchItem['method'],
                "text" => "Attempted",
                "records" => $firstBatchItem['records'],
                "start" => $startTimestamp,
                "end" => self::getTimestamp(),
                "pid" => $firstBatchItem['pid'],
                "error" => $mssg,
                "error_location" => $trace,
            ];
        } else {
            throw new \Exception("Invalid batch queue job: ".REDCapManagement::json_encode_with_spaces($firstBatchItem));
        }
        $this->addRunJobToDB($runJob);
    }

	private static function getDaysOfWeek() {
		return ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
	}

	public function getNumberOfCrons() {
		$total = 0;
		foreach ($this->crons as $day => $orderedCrons) {
			$total += count($orderedCrons);
		}
		return $total;
	}

	private static function getTimestamp() {
		return date("Y-m-d H:i:s");
	}

	private static function getDateFormat() {
		return "Y-m-d";
	}

	public static function reportCronErrors($processType = "cron") {
        $error = error_get_last();

        if ($error && Application::getModule()) {
    		# no DB access???
			$adminEmail = self::$lastAdminEmail;
			$sendErrorLogs = self::$lastSendErrorLogs;
			$pid = self::$lastPid;
            $message = "<p>Your $processType job failed for pid $pid with the following error message:<br>";
            $message .= 'Error Message: ' . $error['message'] . "<br>";
            $message .= 'File: ' . $error['file'] . "<br>";
            $message .= 'Line: ' . $error['line'] . "<br>";
            $message .= REDCapManagement::json_encode_with_spaces($error);
            $message .= "</p>";
            # stack trace???

			if ($sendErrorLogs) {
				$adminEmail .= ",".Application::getFeedbackEmail();
			}

			Application::log("reportCronErrors: ".$message);
			if (Application::isLocalhost()) {
			    $addlSubject = " from localhost";
            } else {
			    $addlSubject = "";
            }
			\REDCap::email($adminEmail, Application::getSetting("default_from", $pid),  Application::getProgramName()." Cron Improper Shutdown".$addlSubject, $message);
		}
	}

	public function run($adminEmail = "", $tokenName = "", $additionalEmailText = "", $recordsToRun = []) {
		$dayOfWeek = date("l");
		$date = date(self::getDateFormat());
		$keys = array($date, $dayOfWeek);     // in order that they will run

		Application::log($this->title." CRONS RUN AT ".date("Y-m-d h:i:s")." FOR PID ".$this->pid, $this->pid);
		Application::log("adminEmail ".$adminEmail, $this->pid);
		Application::log("Looking in ".$this->getNumberOfCrons()." cron jobs", $this->pid);
		$run = [];
		$toRun = [];
		foreach ($keys as $key) {
		    if (isset($this->crons[$key])) {
				foreach ($this->crons[$key] as $cronjob) {
				    $toRun[] = $cronjob;
				}
			}
		}

        # Commenting this out on 05-21-2024 due to Mark McEver's request.
        # The only time in the prior year that this method has given me an alert was from outside systems
        # and all of those were for other modules. Thus, registering a shutdown command isn't improving the
        # code but only creating outside noise.
		// register_shutdown_function([$this, "reportCronErrors"]);

		Application::log("Running ".count($toRun)." crons for pid ".$this->pid." with keys ".json_encode($keys), $this->pid);
		foreach ($toRun as $cronjob) {
		    $records = $cronjob->getRecords();
            if (empty($records)) {
                if (empty($recordsToRun)) {
                    $records = Download::recordIds($this->token, $this->server);
                } else {
                    $records = $recordsToRun;
                }
            }
			Application::log("Running ".$cronjob->getTitle()." with ".count($records)." records", $this->pid);
			$currMethod = [
                "text" => "Attempted",
                "start" => self::getTimestamp(),
                "file" => $cronjob->getFile(),
                "records" => $records,
            ];
			try {
				if (!$this->token || !$this->server) {
					throw new \Exception("Could not pass token '".$this->token."' or server '".$this->server."' to cron job");
				}
				if (!$this->isDebug) {
                    $cronjob->run($this->token, $this->server, $this->pid, $records);
                    gc_collect_cycles();
                }
				$currMethod["text"] = "Succeeded";
				$currMethod["end"] = self::getTimestamp();
			} catch(\Throwable $e) {
                $currMethod["end"] = self::getTimestamp();
				$this->handle($e, $adminEmail, $cronjob);
			} catch(\Exception $e) {
                $currMethod["end"] = self::getTimestamp();
				$this->handle($e, $adminEmail, $cronjob);
			} finally {
                $method = $cronjob->getMethod();
                if (!isset($run[$method])) {
                    $run[$method] = [];
                }
                $run[$method][] = $currMethod;
            }
		}
		if (count($toRun) > 0) {
            $starts = [];
            $ends = [];
		    $projectTitle = self::getProjectTitle($this->pid);
            $allRecords = Download::recordIdsByPid($this->pid);
            $text = "";
			foreach ($run as $method => $aryOfMessages) {
                if (REDCapManagement::isAssoc($aryOfMessages)) {
                    $aryOfMessages = [$aryOfMessages];
                }
                $starts[$method] = time();
                $ends[$method] = 0;

                if (count($aryOfMessages) > 1) {
                    $batchesText = " (".count($aryOfMessages)." Batches)";
                } else {
                    $batchesText = "";
                }
                $text .= "<h3>$method$batchesText</h3>";
                foreach ($aryOfMessages as $mssgAry) {
                    $mssg = $mssgAry['text'];
                    $start = $mssgAry['start'];
                    $text .= "<p>";
                    if (isset($mssgAry["file"])) {
                        $text .= basename($mssgAry["file"], ".php")."<br/>";
                    }
                    $text .= "$mssg<br/>";
                    if (!empty($mssgAry["records"])) {
                        $text .= self::getRecordsText($mssgAry["records"], $allRecords)."<br/>";
                    }
                    if (isset($mssgAry['queue'])) {
                        $queueTitle = self::getTitle($mssgAry['queue']);
                        $text .= "Batch Queue: $queueTitle<br/>";
                    }
                    $text .= "Started: $start<br/>";
                    if (isset($mssgAry['end'])) {
                        $text .= "Ended: {$mssgAry['end']}<br/>";
                    }
                    $text .= "</p>";

                    if ($starts[$method] > $mssgAry['start']) {
                        $starts[$method] = $mssgAry['start'];
                    }
                    if (isset($mssgAry['end']) && ($ends[$method] < $mssgAry['end'])) {
                        $ends[$method] = $mssgAry['end'];
                    }
                }
			}

			if (!class_exists("\REDCap") || !method_exists("\REDCap", "email")) {
				require_once(dirname(__FILE__)."/../../../redcap_connect.php");
			} 
			if (!class_exists("\REDCap") || !method_exists("\REDCap", "email")) {
				throw new \Exception("Could not instantiate REDCap class!");
			}
			if (!$this->isDebug) {
			    $addlSubject = "";
			    if (Application::isLocalhost()) {
			        $addlSubject = " from localhost";
                }
                Application::log("Sending ".Application::getProgramName()." email for pid ".$this->pid." to $adminEmail", $this->pid);
                $emailMessage = $this->makeEmailMessage($this->token, $this->server, $this->pid, $text, $additionalEmailText, $starts, $ends);
                \REDCap::email($adminEmail, Application::getSetting("default_from", $this->pid), Application::getProgramName()." Cron Report".$addlSubject, $emailMessage);
            }
		}
	}

	public function setDebug($isDebug) {
	    $this->isDebug = $isDebug;
    }

	public function handle($e, $adminEmail, $cronjob) {
		if (!class_exists("\REDCap") || !method_exists("\REDCap", "email")) {
			require_once(dirname(__FILE__)."/../../../redcap_connect.php");
		}
		if (!class_exists("\REDCap") || !method_exists("\REDCap", "email")) {
			throw new \Exception("Could not instantiate REDCap class!");
		}

		$sendErrorLogs = Application::getSetting("send_error_logs", $this->pid);
		if ($sendErrorLogs) {
			$adminEmail .= ",".Application::getFeedbackEmail();
		}

		$projectTitle = self::getProjectTitle($this->pid);
		$mssg = "";
		$mssg .= "Cron: ".$cronjob->getTitle()."<br>";
		$mssg .= "PID: ".$this->pid."<br>";
		$mssg .= "Project: $projectTitle<br><br>";
		$mssg .= $e->getMessage()."<br/>Line: ".$e->getLine()." in ".$e->getFile()."<br/>".$e->getTraceAsString();

		\REDCap::email($adminEmail, Application::getSetting("default_from", $this->pid), Application::getProgramName()." Cron Error", $mssg);
		Application::log("Exception: ".$cronjob->getTitle().": ".$e->getMessage()."\nLine: ".$e->getLine()." in ".$e->getFile()."\n".$e->getTraceAsString(), $this->pid);
	}

	private function isJobAlreadyQueued(string $file, string $method, array $records) {
		$batchQueue = $this->getBatchQueueFromDB();
		$compareFile = dirname(__FILE__)."/../".$file;
		foreach ($batchQueue as $item) {
			$settings = Application::getSystemSetting($item);
			if ($settings['file'] != $compareFile) {
				continue;
			}
			if ($settings['method'] != $method) {
				continue;
			}
			if ((isset($settings['records']) && $settings['records'] == $records) || (isset($settings['pids']) && $settings['pids'] == $records)) {
				return TRUE;
			}
		}
		return false;
	}

	private $token;
	private $server;
	private $pid;
	private $crons;
    private $settingSuffix;
    private $title;
	private static $lastAdminEmail;
	private static $lastSendErrorLogs;
	private static $lastPid;
	private $isDebug = FALSE;
    private $module = NULL;
}

