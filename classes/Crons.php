<?php

namespace Vanderbilt\CareerDevLibrary;

// require_once(dirname(__FILE__)."/../../../redcap_connect.php");
require_once(dirname(__FILE__)."/../Application.php");

class CronManager {
	public function __construct($token, $server, $pid) {
		$this->token = $token;
		$this->server = $server;
		$this->pid = $pid;

		$this->crons = array();
		$days = self::getDaysOfWeek();
		foreach ($days as $day) {
			$this->crons[$day] = array();
		}

        $this->adminEmail = Application::getSetting("admin_email", $pid);
        $this->sendErrorLogs = Application::getSetting("send_error_logs", $pid);
        self::$lastAdminEmail = Application::getSetting("admin_email", $pid);
        self::$lastSendErrorLogs = Application::getSetting("send_error_logs", $pid);
	}

	# file is relative to career_dev's root
	# dayOfWeek is in string format - "Monday", "Tuesday", etc. or a date in form Y-M-D
	public function addCron($file, $method, $dayOfWeek) {
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
		if (in_array($dayOfWeek, $possibleDays)) {
			# Weekday
			array_push($this->crons[$dayOfWeek], $cronjob);
		} else if ($dateTs) {
			# Y-M-D
			$date = date(self::getDateFormat(), $dateTs);
			if (!isset($this->crons[$date])) {
				$this->crons[$date] = array();
			}
			array_push($this->crons[$date], $cronjob);
		}
	}

	private static function getDaysOfWeek() {
		return array("Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday");
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
		error_log("reportCronErrors");
		# no DB access
		if ($module = Application::getModule()) {
			$adminEmail = self::$lastAdminEmail;
			$sendErrorLogs = self::$lastSendErrorLogs;
			$error = error_get_last();

			$message = "Your cron job failed with the following error message:<br>";
			$message .= 'Error Message: ' . $error['message'] . "<br>";
			$message .= 'File: ' . $error['file'] . "<br>";
			$message .= 'Line: ' . $error['line'] . "<br>";
			# stack trace???

			if ($sendErrorLogs) {
				$adminEmail .= ",".Application::getFeedbackEmail();
			}

			error_log("reportCronErrors: ".$message);
			\REDCap::email($adminEmail, "noreply@vumc.org",  Application::getProgramName()." Cron Improper Shutdown", $message);
		}
	}

	public function run($adminEmail = "", $tokenName = "", $additionalEmailText = "") {
		$dayOfWeek = date("l");
		$date = date(self::getDateFormat());
		$keys = array($date, $dayOfWeek);     // in order that they will run

		Application::log("CRONS RUN AT ".date("Y-m-d h:i:s")." FOR PID ".$this->pid);
		Application::log("adminEmail ".$adminEmail);
		$run = array();
		$toRun = array();
		foreach ($keys as $key) {
			if (isset($this->crons[$key])) {
				foreach ($this->crons[$key] as $cronjob) {
					array_push($toRun, $cronjob);
				}
			}
		}

		register_shutdown_function("CronManager::reportCronErrors");

		Application::log("Running ".count($toRun)." crons for pid ".$this->pid." with keys ".json_encode($keys));
		foreach ($toRun as $cronjob) {
			Application::log("Running ".$cronjob->getTitle());
			$run[$cronjob->getTitle()] = array("text" => "Attempted", "ts" => self::getTimestamp());
			try {
				if (!$this->token || !$this->server) {
					throw new \Exception("Could not pass token '".$this->token."' or server '".$this->server."' to cron job");
				}
				$cronjob->run($this->token, $this->server, $this->pid);
				$run[$cronjob->getTitle()] = array("text" => "Succeeded", "ts" => self::getTimestamp());
			} catch(\Throwable $e) {
				$this->handle($e, $adminEmail, $cronjob);
			} catch(\Exception $e) {
				$this->handle($e, $adminEmail, $cronjob);
			}
		}
		if (count($toRun) > 0) {
			$text = $tokenName." ".$this->server."<br><br>";
			foreach ($run as $title => $mssgAry) {
				$mssg = $mssgAry['text'];
				$ts = $mssgAry['ts'];
				$text .= $title."<br>".$mssg."<br>".$ts."<br><br>";
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
			Application::log("Sending ".Application::getProgramName()." email for pid ".$this->pid." to $adminEmail");
			\REDCap::email($adminEmail, "noreply@vumc.org", Application::getProgramName()." Cron Report", $text);
		}
	}

	public function handle($e, $adminEmail, $cronjob) {
		Application::log("Exception ".json_encode($e));
		if (!class_exists("\REDCap") || !method_exists("\REDCap", "email")) {
			require_once(dirname(__FILE__)."/../../../redcap_connect.php");
		}
		if (!class_exists("\REDCap") || !method_exists("\REDCap", "email")) {
			throw new \Exception("Could not instantiate REDCap class!");
		}

		$sendErrorLogs = Application::getSetting("send_error_logs");
		if ($sendErrorLogs) {
			$adminEmail .= ",".Application::getFeedbackEmail();
		}

		\REDCap::email($adminEmail, "noreply@vumc.org", Application::getProgramName()." Cron Error", $cronjob->getTitle()."<br><br>".$e->getMessage()."<br>".$e->getTraceAsString());
		Application::log("Exception: ".$cronjob->getTitle().": ".$e->getMessage()."\n".$e->getTraceAsString());
	}

	private $token;
	private $server;
	private $pid;
	private $crons;
	private $adminEmail;
	private $sendErrorLogs;
	private static $lastAdminEmail;
	private static $lastSendErrorLogs;
}

class CronJob {
	public function __construct($file, $method) {
		$this->file = $file;
		$this->method = $method;
	}

	public function getTitle() {
		return $this->file.": ".$this->method;
	}

	public function run($passedToken, $passedServer, $passedPid) {
		if (!$passedToken || !$passedServer || !$passedPid) {
			throw new \Exception("In cronjob at beginning, could not find token '$passedToken' and/or server '$passedServer' and/or pid '$passedPid'");
		}
		error_reporting(E_ALL);
		ini_set('display_errors', 1);
		require_once($this->file);
		if ($this->method) {
            $method = $this->method;
			if ($passedToken && $passedServer && $passedPid) {
				$method($passedToken, $passedServer, $passedPid);
			} else {
				throw new \Exception("In cronjob while executing $method, could not find token '$passedToken' and/or server '$passedServer' and/or pid '$passedPid'");
			}
		} else {
			throw new \Exception("No method specified in cronjob using ".$this->file);
		}
	}

	private $file = "";
	private $method = "";
}
