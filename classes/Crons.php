<?php

namespace Vanderbilt\CareerDevLibrary;

// require_once(dirname(__FILE__)."/../../../redcap_connect.php");
require_once(__DIR__ . '/ClassLoader.php');

class CronManager {
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

	# file is relative to career_dev's root
	# dayOfWeek is in string format - "Monday", "Tuesday", etc. or a date in form Y-M-D
    # records here, if specified, overrides the records specified in function run
	public function addCron($file, $method, $dayOfWeek, $records = [], $numRecordsAtATime = FALSE, $firstParameter = FALSE) {
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

	private function addCronForBatch($file, $method, $dayOfWeek, $records, $numRecordsAtATime, $firstParameter = FALSE) {
        if (empty($records)) {
            $records = Download::recordIds($this->token, $this->server);
        }

        $possibleDays = self::getDaysOfWeek();
        $dateTs = strtotime($dayOfWeek);
        if (!in_array($dayOfWeek, $possibleDays) && !$dateTs) {
            throw new \Exception("The dayOfWeek ($dayOfWeek) must be a string - 'Monday', 'Tuesday', 'Wednesday', etc. or a date (Y-M-D)");
        }

        $absFile = dirname(__FILE__)."/../".$file;
        if (!file_exists($absFile)) {
            throw new \Exception("File $absFile does not exist!");
        }

        if ($this->isDebug) {
            Application::log("Has day of week $dayOfWeek and timestamp for ".date("Y-m-d", $dateTs));
        }
        if (in_array($dayOfWeek, $possibleDays)) {
            # Weekday
            if (date("l") == $dayOfWeek) {
                $this->enqueueBatch($absFile, $method, $records, $numRecordsAtATime, $firstParameter);
                if ($this->isDebug) {
                    Application::log("Assigned cron for $method on $dayOfWeek");
                }
            }
        } else if ($dateTs) {
            # Y-M-D
            $date = date(self::getDateFormat(), $dateTs);
            if ($date == date(self::getDateFormat())) {
                $this->enqueueBatch($absFile, $method, $records, $numRecordsAtATime, $firstParameter);
                if ($this->isDebug) {
                    Application::log("Assigned cron for $date");
                }
            }
        }
    }

    public static function resetBatchSettings($module) {
	    self::saveBatchQueueToDB([], $module);
	    self::saveErrorsToDB([], $module);
	    self::saveRunResultsToDB([], $module);
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

	private function enqueueBatch($file, $method, $records, $numRecordsAtATime, $firstParameter = FALSE) {
        $batchQueue = self::getBatchQueueFromDB($this->module);
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
        self::saveBatchQueueToDB($batchQueue, $this->module);
    }

    private static function saveErrorsToDB($errorQueue, $module) {
	    for ($i = 0; $i < count($errorQueue); $i++) {
	        if (isset($errorQueue[$i]['token'])) {
	            unset($errorQueue[$i]['token']);
            }
        }
        $module->setSystemSetting(self::$errorSetting, $errorQueue);
    }

    private static function saveRunResultsToDB($runQueue, $module) {
        $module->setSystemSetting(self::$runSetting, $runQueue);
    }

    private static function saveBatchQueueToDB($batchQueue, $module) {
	    $newBatchQueue = [];
	    for ($i = 0; $i < count($batchQueue); $i++) {
	        $newBatchQueue[] = $batchQueue[$i];
        }
	    $module->setSystemSetting(self::$batchSetting, $newBatchQueue);
    }

    private static function getRunResultsFromDB($module) {
        $runQueue = $module->getSystemSetting(self::$runSetting);
        if (!$runQueue) {
            return [];
        }
        return $runQueue;
    }

    private static function getBatchQueueFromDB($module) {
        $batchQueue = $module->getSystemSetting(self::$batchSetting);
        if (!$batchQueue) {
            return [];
        }
        return $batchQueue;
    }

    private static function getErrorsFromDB($module) {
        $errors = $module->getSystemSetting(self::$errorSetting);
        if (!$errors) {
            return [];
        }
        return $errors;
    }

    private static function addRunJobToDB($runJob, $module) {
	    $runJobs = self::getRunResultsFromDB($module);
	    if (!$runJobs) {
	        $runJobs = [];
        }
	    $runJobs[] = $runJob;
	    self::saveRunResultsToDB($runJobs, $module);
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
            self::saveBatchQueueToDB($batchQueue, $module);
        }
    }

    public static function getChangedRecords($records, $hours, $pid) {
        if (empty($records)) {
            return $records;
        }
        $threshold = date("YmdHis", time() - $hours * 3600);
        $recordStr = "('".implode("','", $records)."')";
        $log_event_table = method_exists('\REDCap', 'getLogEventTable') ? \REDCap::getLogEventTable($pid) : "redcap_log_event";
        $sql = "SELECT DISTINCT pk FROM $log_event_table WHERE project_id = '".db_real_escape_string($pid)."' AND pk IN $recordStr AND ts >= ".db_real_escape_string($threshold)." AND data_values NOT LIKE 'summary_last_calculated = \'____-__-__ __:__\''";
        $changedRecords = [];
        $startTime = time();
        $q = db_query($sql);
        if ($error = db_error()) {
            throw new \Exception("Database error $error");
        }
        while ($row = db_fetch_assoc($q)) {
            if (!in_array($row['pk'], $changedRecords)) {
                $changedRecords[] = $row['pk'];
            }
        }
        $endTime = time();
        $elapsedTime = $endTime - $startTime;
        Application::log("Changed record count from ".count($records)." to ".count($changedRecords)." records in $elapsedTime seconds.", $pid);
        return $changedRecords;
    }


    public function runBatchJobs() {
	    $module = $this->module;
	    $validBatchStatuses = ["DONE", "ERROR", "RUN", "WAIT"];
        $batchQueue = self::getBatchQueueFromDB($module);

        if (empty($batchQueue)) {
            return;
        }
        self::upgradeBatchQueueIfNecessary($batchQueue, $module);
        if ((count($batchQueue) == 1) && in_array($batchQueue[0]['status'], ["ERROR", "DONE"])) {
            self::saveBatchQueueToDB([], $module);
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
                $errorJobs = self::getErrorsFromDB($module);
                $errorJobs[] = $batchQueue[0];
                self::saveErrorsToDB($errorJobs, $module);
            }
            array_shift($batchQueue);
        }
        if (empty($batchQueue)) {
            return;
        }
        self::saveBatchQueueToDB($batchQueue, $module);

        if ($batchQueue[0]['status'] == "WAIT") {
            register_shutdown_function([$this, "reportCronErrors"]);
            $startTimestamp = self::getTimestamp();
            $cronjob = new CronJob($batchQueue[0]['file'], $batchQueue[0]['method']);
            $cronjob->setRecords($batchQueue[0]['records']);
            if ($batchQueue[0]['firstParameter']) {
                $cronjob->setFirstParameter($batchQueue[0]['firstParameter']);
            }
            $batchQueue[0]['startTs'] = time();
            $batchQueue[0]['status'] = "RUN";
            Application::log("Promoting ".$batchQueue[0]['method']." for ".$batchQueue[0]['pid']." to RUN (".count($batchQueue)." items in batch queue; ".count($batchQueue[0]['records'])." records) at ".self::getTimestamp(), $batchQueue[0]['pid']);
            self::saveBatchQueueToDB($batchQueue, $module);
            $row = $batchQueue[0];
            try {
                $cronjob->run($row['token'], $row['server'], $row['pid'], $row['records']);
                $batchQueue = self::getBatchQueueFromDB($module);
                if (empty($batchQueue)) {
                    # queue was cleared
                    return;
                }
                Application::log("Done with ".$batchQueue[0]['method']." at ".self::getTimestamp());
                $batchQueue[0]['status'] = "DONE";
                $batchQueue[0]['endTs'] = time();
                self::saveBatchQueueToDB($batchQueue, $module);
                $runJob = [
                    "text" => "Succeeded",
                    "records" => $row['records'],
                    "start" => $startTimestamp,
                    "end" => self::getTimestamp(),
                    "pid" => $row['pid'],
                    "method" => $row['method'],
                ];
                self::addRunJobToDB($runJob, $module);
            } catch (\Throwable $e) {
                Application::log($e->getMessage()."\n".$e->getTraceAsString());
                self::handleBatchError($batchQueue, $module, $startTimestamp, $e);
            } catch (\Exception $e) {
                Application::log($e->getMessage()."\n".$e->getTraceAsString());
                self::handleBatchError($batchQueue, $module, $startTimestamp, $e);
            }
        } else if (!in_array($batchQueue[0]['status'], $validBatchStatuses)) {
            throw new \Exception("Improper batch status ".$batchQueue[0]['status']);
        }
    }

    public static function sendEmails($pids, $module, $additionalEmailText = "") {
        $batchQueue = self::getBatchQueueFromDB($module);
        $runJobs = self::getRunResultsFromDB($module);
        $errorQueue = self::getErrorsFromDB($module);
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
            $text = "Project: $projectTitle<br>";
            $text .= "Pid: ".$pid."<br>";
            $text .= "Server: ".$server."<br><br>";

            $hasData = FALSE;
            $errorQueue = self::getErrorsFromDB($module);
            $remainingErrors = [];
            foreach ($errorQueue as $errorJob) {
                if ($errorJob['pid'] == $pid) {
                    $text .= "ERROR ".$errorJob['method']."<br>";
                    $text .= "Records in batch job: ".implode(", ", $errorJob['records'])."<br>";
                    if (isset($errorJob['record'])) {
                        $text .= "Failed in record ".$errorJob['record']."<br>";
                    }
                    if (isset($errorJob['error'])) {
                        $text .= $errorJob['error']."<br>";
                        if (isset($errorJob['error_location'])) {
                            $text .= $errorJob['error_location']."<br>";
                        }
                    } else {
                        $text .= REDCapManagement::json_encode_with_spaces($errorJob)."<br><br>";
                    }
                    $text .= "<br>";
                } else {
                    $remainingErrors[] = $errorJob;
                }
            }
            self::saveErrorsToDB($remainingErrors, $module);

            $runJobs = self::getRunResultsFromDB($module);
            $remainingJobs = [];
            $methods = [];
            foreach ($runJobs as $job) {
                if ($job['pid'] == $pid) {
                    $method = $job['method'];
                    if (!isset($methods[$method])) {
                        $methods[$method] = [
                            "succeededRecords" => [],
                            "attemptedRecords" => [],
                            "succeededLastTs" => 0,
                            "attemptedLastTs" => 0,
                            "succeededFirstTs" => time(),
                            "attemptedFirstTs" => time(),
                        ];
                    }
                    $prefix = strtolower($job['text']);
                    $methods[$method][$prefix."Records"] = array_merge($methods[$method][$prefix."Records"] ?? [], $job['records']);
                    $endTs = strtotime($job['end']);
                    if ($endTs > $methods[$method][$prefix."LastTs"]) {
                        $methods[$method][$prefix."LastTs"] = $endTs;
                    }
                    $startTs = strtotime($job['start']);
                    if ($startTs < $methods[$method][$prefix."FirstTs"]) {
                        $methods[$method][$prefix."FirstTs"] = $startTs;
                    }
                } else if (is_array($job)) {
                    $remainingJobs[] = $job;
                }
            }

            foreach ($methods as $method => $settings) {
                foreach (["attempted", "succeeded"] as $prefix) {
                    if (!empty($settings[$prefix.'Records'])) {
                        $hasData = TRUE;
                        $text .= "$method<br>";
                        $text .= ucfirst($prefix)."<br>";
                        if (is_numeric($settings[$prefix.'FirstTs'])) {
                            $text .= "Start: ".date("Y-m-d H:i:s", $settings[$prefix.'FirstTs'])."<br>";
                        }
                        if (is_numeric($settings[$prefix.'LastTs'])) {
                            $text .= "End: ".date("Y-m-d H:i:s", $settings[$prefix.'LastTs'])."<br>";
                        }
                        $text .= "<br>";
                    }
                }
            }
            $text .= "<br>".$additionalEmailText;

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
                \REDCap::email($adminEmail, Application::getSetting("default_from", $pid), Application::getProgramName()." Cron Report".$addlSubject, $text);
            }

            if (empty($remainingJobs)) {
                REDCapManagement::cleanupDirectory(APP_PATH_TEMP, "/RePORTER_PRJ/");
            }

            self::saveRunResultsToDB($remainingJobs, $module);
        }
    }

    public function getBatchQueue() {
	    return self::getBatchQueueFromDB($this->module);
    }

    private static function handleBatchError($batchQueue, $module, $startTimestamp, $exception, $record = FALSE) {
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
        self::saveBatchQueueToDB($batchQueue, $module);

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
        self::addRunJobToDB($runJob, $module);
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

	public static function reportCronErrors() {
        $error = error_get_last();

        if ($error && Application::getModule()) {
    		# no DB access???
			$adminEmail = self::$lastAdminEmail;
			$sendErrorLogs = self::$lastSendErrorLogs;
			$pid = self::$lastPid;
            $message = "<p>Your cron job failed for pid $pid with the following error message:<br>";
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
			$run[$cronjob->getTitle()] = array("text" => "Attempted", "start" => self::getTimestamp());
			try {
				if (!$this->token || !$this->server) {
					throw new \Exception("Could not pass token '".$this->token."' or server '".$this->server."' to cron job");
				}
				if (!$this->isDebug) {
                    $cronjob->run($this->token, $this->server, $this->pid, $records);
                    gc_collect_cycles();
                }
				$run[$cronjob->getTitle()]["text"] = "Succeeded";
				$run[$cronjob->getTitle()]["end"] = self::getTimestamp();
			} catch(\Throwable $e) {
                $run[$cronjob->getTitle()]["end"] = self::getTimestamp();
				$this->handle($e, $adminEmail, $cronjob);
			} catch(\Exception $e) {
                $run[$cronjob->getTitle()]["end"] = self::getTimestamp();
				$this->handle($e, $adminEmail, $cronjob);
			}
		}
		if (count($toRun) > 0) {
		    $projectTitle = Download::projectTitle($this->token, $this->server);
			$text = "Project: $projectTitle<br>";
			$text .= "Pid: ".$this->pid."<br>";
			$text .= "Server: ".$this->server."<br><br>";
			foreach ($run as $title => $mssgAry) {
				$mssg = $mssgAry['text'];
                $start = $mssgAry['start'];
				$text .= $title."<br>";
				$text .= $mssg."<br>";
                $text .= "Started: $start<br>";
                if (isset($mssgAry['end'])) {
                    $text .= "Ended: {$mssgAry['end']}<br>";
                }
                $text .= "<br>";
			}
			if ($additionalEmailText) {
			    $text .= "<br><br>".$additionalEmailText;
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
                \REDCap::email($adminEmail, Application::getSetting("default_from", $this->pid), Application::getProgramName()." Cron Report".$addlSubject, $text);
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

	public function getMethod() {
	    return $this->method;
    }

	public function run($passedToken, $passedServer, $passedPid, $records) {
		if (!$passedToken || !$passedServer || !$passedPid) {
			throw new \Exception("In cronjob at beginning, could not find token '$passedToken' and/or server '$passedServer' and/or pid '$passedPid'");
		}
		error_reporting(E_ALL);
		ini_set('display_errors', '1');
        $_GET['pid'] = $passedPid;
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
		unset($_GET['pid']);
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
