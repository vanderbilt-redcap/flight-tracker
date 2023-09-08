<?php

namespace Vanderbilt\CareerDevLibrary;

// require_once(dirname(__FILE__)."/../../../redcap_connect.php");
require_once(__DIR__ . '/ClassLoader.php');

class CronManager {
    const MAX_BATCHES_IN_ONE_CRON = 5;
    const REPEAT_BATCH_WHEN_LESS_THAN = 15;

	public function __construct($token, $server, $pid, $module = NULL) {
		$this->token = $token;
		$this->server = $server;
		$this->pid = $pid;
		$this->module = $module;

		$this->crons = [];
		$days = self::getDaysOfWeek();
		foreach ($days as $day) {
			$this->crons[$day] = [];
		}

        $this->adminEmail = Application::getSetting("admin_email", $pid);
        $this->sendErrorLogs = Application::getSetting("send_error_logs", $pid);
        self::$lastPid = $pid;
        self::$lastAdminEmail = Application::getSetting("admin_email", $pid);
        self::$lastSendErrorLogs = Application::getSetting("send_error_logs", $pid);
	}

    public function addMultiCron($file, $method, $dayOfWeek, $pids, $firstParameter = FALSE) {
        if ($this->module) {
            $this->addCronForBatchMulti($file, $method, $dayOfWeek, $pids, $firstParameter);
        }
    }

	# file is relative to career_dev's root
	# dayOfWeek is in string format - "Monday", "Tuesday", etc. or a date in form Y-M-D
    # records here, if specified, overrides the records specified in function run
	public function addCron($file, $method, $dayOfWeek, $records = [], $numRecordsAtATime = FALSE, $firstParameter = FALSE) {
        try {
            if ($this->module) {
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
        if ($firstParameter) {
            $cronjob->setFirstParameter($firstParameter);
        }
        if ($this->isDebug) {
            Application::log("Has day of week $dayOfWeek and timestamp for ".date("Y-m-d", $dateTs));
        }
        if (in_array($dayOfWeek, $possibleDays)) {
            # Weekday
            if (!isset($this->crons[$dayOfWeek])) {
                $this->crons[$dayOfWeek] = [];
                if ($this->isDebug) {
                    Application::log("Reset cron list for $dayOfWeek");
                }
            }
            $this->crons[$dayOfWeek][] = $cronjob;
            if ($this->isDebug) {
                Application::log("Assigned cron for $dayOfWeek");
            }
        } else if ($dateTs) {
            # Y-M-D
            $date = date(self::getDateFormat(), $dateTs);
            if (!isset($this->crons[$date])) {
                $this->crons[$date] = [];
                if ($this->isDebug) {
                    Application::log("Reset cron list for $date");
                }
            }
            $this->crons[$date][] = $cronjob;
            if ($this->isDebug) {
                Application::log("Assigned cron for $date");
            }
        }
        if ($this->isDebug) {
            Application::log("Added cron $method: ".$this->getNumberOfCrons()." total crons now");
        }
 	}

    private function addCronForBatchMulti($file, $method, $dayOfWeek, $pids, $firstParameter = FALSE) {
        if (empty($pids)) {
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
                    Application::log("Assigned cron for $method on $dayOfWeek");
                }
            }
        } else if ($dateTs) {
            # Y-M-D
            $date = date(self::getDateFormat(), $dateTs);
            if ($date == date(self::getDateFormat())) {
                $this->enqueueBatchMulti($absFile, $method, $pids, $firstParameter);
                if ($this->isDebug) {
                    Application::log("Assigned cron for $date");
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
            Application::log("Has day of week $dayOfWeek and timestamp for ".date("Y-m-d", $dateTs));
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

    public static function clearBatchQueue() {
        self::saveBatchQueueToDB([]);
    }

    public static function resetBatchSettings() {
	    self::saveBatchQueueToDB([]);
	    self::saveErrorsToDB([]);
	    self::saveRunResultsToDB([]);
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
            $batchQueue = self::getBatchQueueFromDB();
            $batchRow = [
                "file" => $file,
                "method" => $method,
                "pids" => $pids,
                "status" => "WAIT",
                "enqueueTs" => time(),
                "firstParameter" => $firstParameter,
            ];
            $batchQueue[] = $batchRow;
            self::saveBatchQueueToDB($batchQueue);
        }
    }

	private function enqueueBatch($file, $method, $records, $numRecordsAtATime, $firstParameter = FALSE) {
        $batchQueue = self::getBatchQueueFromDB();
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
            $batchQueue[] = $batchRow;
        }
        self::saveBatchQueueToDB($batchQueue);
    }

    private static function saveErrorsToDB($errorQueue) {
	    for ($i = 0; $i < count($errorQueue); $i++) {
	        if (isset($errorQueue[$i]['token'])) {
	            unset($errorQueue[$i]['token']);
            }
        }
        try {
            Application::saveSystemSetting(self::$errorSetting, $errorQueue);
        } catch (\Exception $e) {
            if (self::exceedsMaxCharacters($e->getMessage())) {
                Application::saveSystemSetting(self::$errorSetting, []);
            } else {
                throw $e;
            }
        }
    }

    private static function saveRunResultsToDB($runQueue) {
        try {
            Application::saveSystemSetting(self::$runSetting, $runQueue);
        } catch (\Exception $e) {
            if (self::exceedsMaxCharacters($e->getMessage())) {
                Application::saveSystemSetting(self::$runSetting, []);
            } else {
                throw $e;
            }
        }
    }

    private static function saveBatchQueueToDB($batchQueue) {
	    $newBatchQueue = [];
	    for ($i = 0; $i < count($batchQueue); $i++) {
	        $newBatchQueue[] = $batchQueue[$i];
        }
        try {
            Application::saveSystemSetting(self::$batchSetting, $newBatchQueue);
        } catch (\Exception $e) {
            if (self::exceedsMaxCharacters($e->getMessage())) {
                Application::saveSystemSetting(self::$batchSetting, []);
            } else {
                throw $e;
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
            || preg_match('/The size of BLOB/TEXT data inserted in one transaction/', $mssg)
        );
    }

    private static function getRunResultsFromDB() {
        $runQueue = Application::getSystemSetting(self::$runSetting);
        if (!$runQueue) {
            return [];
        }
        return $runQueue;
    }

    private static function getBatchQueueFromDB() {
        $batchQueue = Application::getSystemSetting(self::$batchSetting);
        if (!$batchQueue) {
            return [];
        }
        return $batchQueue;
    }

    private static function getErrorsFromDB() {
        $errors = Application::getSystemSetting(self::$errorSetting);
        if (!$errors) {
            return [];
        }
        return $errors;
    }

    private static function addRunJobToDB($runJob) {
	    $runJobs = self::getRunResultsFromDB();
	    if (!$runJobs) {
	        $runJobs = [];
        }
	    $runJobs[] = $runJob;
	    self::saveRunResultsToDB($runJobs);
    }

    public static function upgradeBatchQueueIfNecessary(&$batchQueue, $module) {
	    $madeChanges = FALSE;
	    $currentVersion = $module->getSystemSetting("version");
        for ($i = 0; $i < count($batchQueue); $i++) {
            if (isset($batchQueue[$i]['file']) && !preg_match("/$currentVersion/", $batchQueue[$i]['file'])) {
                $batchQueue[$i]['file'] = preg_replace("/_v\d+\.\d+\.\d+\//", "_$currentVersion/", $batchQueue[$i]['file']);
                $madeChanges = TRUE;
            }
        }
        if ($madeChanges) {
            self::saveBatchQueueToDB($batchQueue);
        }
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


    public function runBatchJobs($numRunBeforeInCron = 0) {
	    $module = $this->module;
	    $validBatchStatuses = ["DONE", "ERROR", "RUN", "WAIT"];
        $batchQueue = self::getBatchQueueFromDB();

        if (empty($batchQueue)) {
            return;
        }
        self::upgradeBatchQueueIfNecessary($batchQueue, $module);
        if (
            (
                (count($batchQueue) == 1) && in_array($batchQueue[0]['status'], ["ERROR", "DONE"])
            )
            || (count($batchQueue) > 5000)
        ){
            self::saveBatchQueueToDB([]);
            return;
        }

        Application::log("Currently running ".$batchQueue[0]['method']." for pid ".$batchQueue[0]['pid']." with status ".$batchQueue[0]['status'], $batchQueue[0]['pid']);
        if ($batchQueue[0]['status'] == "RUN") {
            $startTs = isset($batchQueue[0]['startTs']) && is_numeric($batchQueue[0]['startTs']) ? $batchQueue[0]['startTs'] : 0;
            $timespan = 90 * 60;   // max of 90 minutes per segment
            Application::log("Running until ".date("Y-m-d H:i:s", $startTs + $timespan));
            if (time() > $startTs + $timespan) {
                // failed batch - probably due to syntax error to avoid shutdown function
                $batchQueue[0]['status'] = "ERROR";
                $batchQueue[0]['cause'] = "Long-running";
            } else {
                # let run
                return;
            }
        }
        if (in_array($batchQueue[0]['status'], ["DONE", "ERROR"])) {
            if ($batchQueue[0]['status'] == "ERROR") {
                Application::log("Saving ERROR ".json_encode($batchQueue[0]));
                $errorJobs = self::getErrorsFromDB();
                $errorJobs[] = $batchQueue[0];
                self::saveErrorsToDB($errorJobs);
            }
            array_shift($batchQueue);
        }
        if (empty($batchQueue)) {
            return;
        }
        self::saveBatchQueueToDB($batchQueue);

        if ($batchQueue[0]['status'] == "WAIT") {
            register_shutdown_function([$this, "reportCronErrors"]);
            $startTimestamp = self::getTimestamp();
            do {
                $queueHasRun = FALSE;
                if (
                    (count($batchQueue) > 0)
                    && (
                        REDCapManagement::isActiveProject($batchQueue[0]['pid'])
                        || isset($batchQueue[0]['pids'])
                    )
                ) {
                    try {
                        $cronjob = new CronJob($batchQueue[0]['file'], $batchQueue[0]['method']);
                        $batchQueue[0]['startTs'] = time();
                        $batchQueue[0]['status'] = "RUN";
                        if ($batchQueue[0]['firstParameter']) {
                            $cronjob->setFirstParameter($batchQueue[0]['firstParameter']);
                        }
                        Application::log("Promoting ".$batchQueue[0]['method']." for ".$batchQueue[0]['pid']." to RUN (".count($batchQueue)." items in batch queue; ".count($batchQueue[0]['records'] ?? [])." records) at ".self::getTimestamp(), $batchQueue[0]['pid']);
                        self::saveBatchQueueToDB($batchQueue);
                        $row = $batchQueue[0];
                        if (isset($row['pids'])) {
                            $queueHasRun = TRUE;
                            $cronjob->runMulti($row['pids']);
                            self::markAsDone($module);
                            $runJob = [
                                "text" => "Succeeded",
                                "pids" => $row['pids'],
                                "start" => $startTimestamp,
                                "end" => self::getTimestamp(),
                                "method" => $row['method'],
                                "file" => $row['file'],
                            ];
                            self::addRunJobToDB($runJob);
                        } else if (isset($batchQueue[0]['records'])) {
                            $cronjob->setRecords($row['records']);
                            if (isset($batchQueue[0]['firstParameter'])) {
                                $cronjob->setFirstParameter($row['firstParameter']);
                            }
                            $queueHasRun = TRUE;
                            $cronjob->run($row['token'], $row['server'], $row['pid'], $row['records']);
                            self::markAsDone($module);
                            $endTimestamp = self::getTimestamp();
                            $runJob = [
                                "text" => "Succeeded",
                                "records" => $row['records'],
                                "start" => $startTimestamp,
                                "end" => $endTimestamp,
                                "pid" => $row['pid'],
                                "method" => $row['method'],
                                "file" => $row['file'],
                            ];
                            self::addRunJobToDB($runJob);

                            $elapsedSeconds = strtotime($endTimestamp) - strtotime($startTimestamp);
                            $numRunBeforeInCron++;
                            if (
                                ($elapsedSeconds < self::REPEAT_BATCH_WHEN_LESS_THAN)
                                && ($numRunBeforeInCron <= self::MAX_BATCHES_IN_ONE_CRON)
                            ) {
                                sleep(1);
                                Application::log("Flight Tracker repeating cron");
                                $this->runBatchJobs($numRunBeforeInCron);
                            }
                        } else {
                            throw new \Exception("Invalid batch job ".REDCapManagement::json_encode_with_spaces($batchQueue[0]));
                        }
                    } catch (\Throwable $e) {
                        Application::log($e->getMessage()."\n".$e->getTraceAsString());
                        self::handleBatchError($module, $startTimestamp, $e);
                    } catch (\Exception $e) {
                        Application::log($e->getMessage()."\n".$e->getTraceAsString());
                        self::handleBatchError($module, $startTimestamp, $e);
                    }
                } else if (count($batchQueue) > 0) {
                    array_shift($batchQueue);
                    self::saveBatchQueueToDB($batchQueue);
                    if (empty($batchQueue)) {
                        return;
                    }
                } else {
                    # empty batchQueue
                    self::saveBatchQueueToDB($batchQueue);
                    return;
                }
            } while (!$queueHasRun);
        } else if (!in_array($batchQueue[0]['status'], $validBatchStatuses)) {
            throw new \Exception("Improper batch status ".$batchQueue[0]['status']);
        }
    }

    public static function markAsDone($module) {
        $batchQueue = self::getBatchQueueFromDB();
        if (empty($batchQueue)) {
            # queue was cleared
            return;
        }
        Application::log("Done with ".$batchQueue[0]['method']." at ".self::getTimestamp());
        $batchQueue[0]['status'] = "DONE";
        $batchQueue[0]['endTs'] = time();
        self::saveBatchQueueToDB($batchQueue);
    }

    # likely for non-active projects
    private static function cleanOldResults($runJobs, $errorQueue, $module) {
        $oneDay = 24 * 3600;
        $thresholdTs = time() - 14 * $oneDay;

        $newRunJobs = [];
        $hasDeletedRunJob = FALSE;
        foreach ($runJobs as $row) {
            if ($row['end']) {
                $ts = strtotime($row['end']);
                if ($ts > $thresholdTs) {
                    $newRunJobs[] = $row;
                } else {
                    $hasDeletedRunJob = TRUE;
                }
            }
        }
        if ($hasDeletedRunJob) {
            self::saveRunResultsToDB($newRunJobs);
        }

        $newErrorQueue = [];
        $hasDeletedErrors = FALSE;
        foreach ($errorQueue as $row) {
            if ($row['endTs']) {
                $ts = $row['endTs'];
                if ($ts > $thresholdTs) {
                    $newErrorQueue[] = $row;
                } else {
                    $hasDeletedErrors = TRUE;
                }
            }
        }
        if ($hasDeletedErrors) {
            self::saveErrorsToDB($newErrorQueue);
        }
        return [$newRunJobs, $newErrorQueue];
    }

    public static function sendEmails($pids, $module, $additionalEmailText = "") {
        $batchQueue = self::getBatchQueueFromDB();
        $runJobs = self::getRunResultsFromDB();
        $errorQueue = self::getErrorsFromDB();
        list($runJobs, $errorQueue) = self::cleanOldResults($runJobs, $errorQueue, $module);
        if (empty($batchQueue)) {
            foreach ($pids as $pid) {
                if (self::hasDataForPid($runJobs, $pid) || self::hasDataForPid($errorQueue, $pid)) {
                    self::sendEmailForProjectIfPossible($pid, $module, $additionalEmailText);
                }
            }
        }
    }

    private static function hasDataForPid($queue, $pid) {
	    foreach ($queue as $item) {
	        if ($item['pid'] == $pid) {
	            return TRUE;
            }
        }
	    return FALSE;
    }

    private static function sendEmailForProjectIfPossible($pid, $module, $additionalEmailText) {
        $token = $module->getProjectSetting("token", $pid);
        $server = $module->getProjectSetting("server", $pid);
        if ($token && $server) {
            $adminEmail = $module->getProjectSetting("admin_email", $pid);

            $projectTitle = Download::projectTitle($token, $server);
            $text = "";
            $hasData = FALSE;
            $hasErrors = FALSE;
            $errorQueue = self::getErrorsFromDB();
            foreach ($errorQueue as $errorJob) {
                if ($errorJob['pid'] == $pid) {
                    $hasErrors = TRUE;
                }
            }

            if ($hasErrors) {
                $text .= "<h2>Errors &amp; Warnings!</h2>";
            }


            $remainingErrors = [];
            foreach ($errorQueue as $errorJob) {
                if ($errorJob['pid'] == $pid) {
                    $method = $errorJob['method'];
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
                } else {
                    $remainingErrors[] = $errorJob;
                }
            }
            self::saveErrorsToDB($remainingErrors);

            $runJobs = self::getRunResultsFromDB();
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
            foreach ($runJobs as $job) {
                if ($job['pid'] == $pid) {
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
                } else if (is_array($job) && !isset($job['pids'])) {
                    $remainingJobs[] = $job;
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
                Application::log("Sending ".Application::getProgramName()." email for pid ".$pid." to $adminEmail");
                $emailMssg = self::makeEmailMessage($token, $server, $pid, $text, $additionalEmailText, $starts, $ends);
                \REDCap::email($adminEmail, Application::getSetting("default_from", $pid), Application::getProgramName()." Cron Report".$addlSubject, $emailMssg);
            }

            if (empty($remainingJobs)) {
                REDCapManagement::cleanupDirectory(APP_PATH_TEMP, "/RePORTER_PRJ/");
            }

            self::saveRunResultsToDB($remainingJobs);
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
        $projectTitle = Download::projectTitle($token, $server);

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
                    } else {
                        throw new \Exception("This should not happen!");
                    }
                    $gf->processRow($row, $rows, $token);
                    $grants = $gf->getGrants();
                    if (!isset($newGrants[$recordId])) {
                        $newGrants[$recordId] = [];
                    }
                    $newGrants[$recordId] = array_merge($newGrants[$recordId], $grants);
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
	    return self::getBatchQueueFromDB();
    }

    private static function handleBatchError($module, $startTimestamp, $exception, $record = FALSE) {
        $batchQueue = self::getBatchQueueFromDB();
	    $mssg = $exception->getMessage();
	    $trace = $exception->getTraceAsString();
        Application::log("handleBatchError: ".json_encode($batchQueue[0]));
	    Application::log($mssg." ".$trace);

        $batchQueue[0]['status'] = "ERROR";
        $batchQueue[0]['cause'] = "Exception";
        $batchQueue[0]['endTs'] = time();
        $batchQueue[0]['error'] = $mssg;
        $batchQueue[0]['error_location'] = $trace;
        if ($record) {
            $batchQueue[0]['record'] = $record;
        }
        self::saveBatchQueueToDB($batchQueue);

        if (isset($batchQueue[0]['pids'])) {
            $runJob = [
                "method" => $batchQueue[0]['method'],
                "text" => "Attempted",
                "start" => $startTimestamp,
                "end" => self::getTimestamp(),
                "pids" => $batchQueue[0]['pids'],
                "error" => $mssg,
                "error_location" => $trace,
            ];
        } else if (isset($batchQueue[0]['records'])) {
            $runJob = [
                "method" => $batchQueue[0]['method'],
                "text" => "Attempted",
                "records" => $batchQueue[0]['records'],
                "start" => $startTimestamp,
                "end" => self::getTimestamp(),
                "pid" => $batchQueue[0]['pid'],
                "error" => $mssg,
                "error_location" => $trace,
            ];
        } else {
            throw new \Exception("Invalid batch queue job: ".REDCapManagement::json_encode_with_spaces($batchQueue[0]));
        }
        self::addRunJobToDB($runJob);
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

		Application::log("CRONS RUN AT ".date("Y-m-d h:i:s")." FOR PID ".$this->pid);
		Application::log("adminEmail ".$adminEmail);
		Application::log("Looking in ".$this->getNumberOfCrons()." cron jobs");
		$run = [];
		$toRun = [];
		foreach ($keys as $key) {
		    if (isset($this->crons[$key])) {
				foreach ($this->crons[$key] as $cronjob) {
				    $toRun[] = $cronjob;
				}
			}
		}

		register_shutdown_function([$this, "reportCronErrors"]);

		Application::log("Running ".count($toRun)." crons for pid ".$this->pid." with keys ".json_encode($keys));
		foreach ($toRun as $cronjob) {
		    $records = $cronjob->getRecords();
            if (empty($records)) {
                if (empty($recordsToRun)) {
                    $records = Download::recordIds($this->token, $this->server);
                } else {
                    $records = $recordsToRun;
                }
            }
			Application::log("Running ".$cronjob->getTitle()." with ".count($records)." records");
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
		    $projectTitle = Download::projectTitle($this->token, $this->server);
            $allRecords = Download::recordIds($this->token, $this->server);
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
                Application::log("Sending ".Application::getProgramName()." email for pid ".$this->pid." to $adminEmail");
                $emailMessage = self::makeEmailMessage($this->token, $this->server, $this->pid, $text, $additionalEmailText, $starts, $ends);
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

		$projectTitle = Download::projectTitle($this->token, $this->server);
		$mssg = "";
		$mssg .= "Cron: ".$cronjob->getTitle()."<br>";
		$mssg .= "PID: ".$this->pid."<br>";
		$mssg .= "Project: $projectTitle<br><br>";
		$mssg .= $e->getMessage()."<br/>Line: ".$e->getLine()." in ".$e->getFile()."<br/>".$e->getTraceAsString();

		\REDCap::email($adminEmail, Application::getSetting("default_from", $this->pid), Application::getProgramName()." Cron Error", $mssg);
		Application::log("Exception: ".$cronjob->getTitle().": ".$e->getMessage()."\nLine: ".$e->getLine()." in ".$e->getFile()."\n".$e->getTraceAsString());
	}

	private $token;
	private $server;
	private $pid;
	private $crons;
	private $adminEmail;
	private $sendErrorLogs;
	private static $lastAdminEmail;
	private static $lastSendErrorLogs;
	private static $lastPid;
	private $isDebug = FALSE;
    private $module = NULL;
    private static $batchSetting = "batchCronJobs";
    private static $errorSetting = "cronJobsErrors";
    private static $runSetting = "cronJobsCompleted";
}

class CronJob {
	public function __construct($file, $method) {
		$this->file = $file;
		$this->method = $method;
		$this->firstParameter = FALSE;
	}

	public function setFirstParameter($value) {
	    $this->firstParameter = $value;
    }

	public function getTitle() {
		return $this->file.": ".$this->method;
	}

    public function getFile() {
        return $this->file;
    }

	public function getMethod() {
	    return $this->method;
    }

    public function runMulti($passedPids) {
        if (empty($passedPids)) {
            throw new \Exception("No pids specified");
        }
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
        Application::setPid($passedPids[0]);
        require_once($this->file);
        if ($this->method) {
            $method = $this->method;
            if (!function_exists($method)) {
                $methodWithNamespace = __NAMESPACE__."\\".$method;
                if (function_exists($methodWithNamespace)) {
                    $method = $methodWithNamespace;
                } else {
                    throw new \Exception("Invalid method $method");
                }
            }
            $startTs = time();
            URLManagement::resetUnsuccessfulCount();
            if ($this->firstParameter) {
                $method($passedPids, $this->firstParameter);
            } else {
                $method($passedPids);
            }
            if (Application::isVanderbilt()) {
                \REDCap::email("scott.j.pearson@vumc.org", "noreply.flighttracker@vumc.org", "Multi Cron Completed!", "$method for ".count($passedPids)." pids<br/>Start: ".date("Y-m-d H:i:s", $startTs)."<br/>Done: ".date("Y-m-d H:i:s"));
            }
        }
        Application::unsetPid();
    }

	public function run($passedToken, $passedServer, $passedPid, $records) {
		if (!$passedToken || !$passedServer || !$passedPid) {
			throw new \Exception("In cronjob at beginning, could not find token '$passedToken' and/or server '$passedServer' and/or pid '$passedPid'");
		}
		error_reporting(E_ALL);
		ini_set('display_errors', '1');
        Application::setPid($passedPid);
		require_once($this->file);
		if ($this->method) {
            $method = $this->method;
            if (!function_exists($method)) {
                $methodWithNamespace = __NAMESPACE__."\\".$method;
                if (function_exists($methodWithNamespace)) {
                    $method = $methodWithNamespace;
                } else {
                    throw new \Exception("Invalid method $method");
                }
            }
			if ($passedToken && $passedServer && $passedPid) {
                URLManagement::resetUnsuccessfulCount();
			    if ($this->firstParameter) {
                    $method($passedToken, $passedServer, $passedPid, $records, $this->firstParameter);
                } else {
                    $method($passedToken, $passedServer, $passedPid, $records);
                }
			} else {
				throw new \Exception("In cronjob while executing $method, could not find token '$passedToken' and/or server '$passedServer' and/or pid '$passedPid'");
			}
		} else {
			throw new \Exception("No method specified in cronjob using ".$this->file);
		}
        Application::unsetPid();
	}

	public function setRecords($records) {
	    $this->records = $records;
    }

    public function getRecords() {
	    return $this->records;
    }

	private $file = "";
	private $method = "";
	private $records = [];
	private $firstParameter = FALSE;
}
