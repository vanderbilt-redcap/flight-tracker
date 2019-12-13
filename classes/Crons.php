<?php

namespace Vanderbilt\CareerDevLibrary;

// require_once(dirname(__FILE__)."/../../../redcap_connect.php");

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
	}

	# file is relative to career_dev's root
	# dayOfWeek is in string format - "Monday", "Tuesday", etc. or a date in form Y-M-D
	public function addCron($file, $method, $dayOfWeek) {
		$possibleDays = self::getDaysOfWeek();
		$dateTs = strtotime($dayOfWeek);
		if (!in_array($dayOfWeek, $possibleDays) && !$dateTs) {
			throw new Exception("The dayOfWeek ($dayOfWeek) must be a string - 'Monday', 'Tuesday', 'Wednesday', etc. or a date (Y-M-D)");
		}

		$absFile = dirname(__FILE__)."/../".$file;
		if (!file_exists($absFile)) {
			throw new Exception("File $absFile does not exist!");
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

	public function run($adminEmail = "", $tokenName = "") {
		$dayOfWeek = date("l");
		$date = date(self::getDateFormat());
		$keys = array($date, $dayOfWeek);     // in order that they will run

		error_log("CRONS RUN AT ".date("Y-m-d h:i:s")." FOR PID ".$this->pid);
		error_log("adminEmail ".$adminEmail);
		$run = array();
		$toRun = array();
		foreach ($keys as $key) {
			if (isset($this->crons[$key])) {
				foreach ($this->crons[$key] as $cronjob) {
					array_push($toRun, $cronjob);
				}
			}
		}
		error_log("Running ".count($toRun)." crons for pid ".$this->pid." with keys ".json_encode($keys));
		foreach ($toRun as $cronjob) {
			error_log("Running ".$cronjob->getTitle());
			$run[$cronjob->getTitle()] = array("text" => "Attempted", "ts" => self::getTimestamp());
			try {
				$cronjob->run($this->token, $this->server, $this->pid);
				$run[$cronjob->getTitle()] = array("text" => "Succeeded", "ts" => self::getTimestamp());
			} catch(\Exception $e) {
				if (!class_exists("\REDCap") || !method_exists("\REDCap", "email")) {
					require_once(dirname(__FILE__)."/../../../redcap_connect.php");
				}
				if (!class_exists("\REDCap") || !method_exists("\REDCap", "email")) {
					throw new \Exception("Could not instantiate REDCap class!");
				}
				\REDCap::email($adminEmail, "noreply@vumc.org", PROGRAM_NAME." Cron Error", $cronjob->getTitle()."<br><br>".$e->getMessage()."<br>".json_encode($e->getTrace()));
				error_log("Exception: ".$cronjob->getTitle().": ".$e->getMessage()."\n".json_encode($e->getTrace()));
			}
		}
		if (count($toRun) > 0) {
			$text = $tokenName." ".$this->server."<br><br>";
			foreach ($run as $title => $mssgAry) {
				$mssg = $mssgAry['text'];
				$ts = $mssgAry['ts'];
				$text .= $title."<br>".$mssg."<br>".$ts."<br><br>";
			}
			if (!class_exists("\REDCap") || !method_exists("\REDCap", "email")) {
				require_once(dirname(__FILE__)."/../../../redcap_connect.php");
			} 
			if (!class_exists("\REDCap") || !method_exists("\REDCap", "email")) {
				throw new \Exception("Could not instantiate REDCap class!");
			} 
			error_log("Sending ".PROGRAM_NAME." email for pid ".$this->pid." to $adminEmail");
			\REDCap::email($adminEmail, "noreply@vumc.org", PROGRAM_NAME." Cron Report", $text);
		}
	}

	private $token;
	private $server;
	private $pid;
	private $crons;
}

class CronJob {
	public function __construct($file, $method) {
		$this->file = $file;
		$this->method = $method;
	}

	public function getTitle() {
		return $this->file.": ".$this->method;
	}

	public function run($token, $server, $pid) {
		require_once($this->file);
		if ($this->method) {
			$method = $this->method;
			$method($token, $server, $pid);
		} else {
			throw new \Exception("No method specified in cronjob using ".$this->file);
		}
	}

	private $file = "";
	private $method = "";
}
