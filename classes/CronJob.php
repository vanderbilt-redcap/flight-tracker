<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class CronJob
{
	public function __construct($file, $method) {
		$this->file = $file;
		$this->method = $method;
		$this->firstParameter = false;
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
			if ($this->firstParameter !== false) {
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
	private $firstParameter = false;
}
