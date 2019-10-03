<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/Upload.php");
require_once(dirname(__FILE__)."/CohortConfig.php");

class Cohorts {
	public function __construct($token, $server, $module) {
		$this->token = $token;
		$this->server = $server;
		$this->module = $module;

		$this->settingName = "configs";
		$this->configs = $this->getConfigs();
	}

	private function getConfigs() {
		if ($this->module) {
			$configs = $this->module->getProjectSetting($this->settingName); 
			if ($configs) {
				return $configs;
			}
		}
		return array();
	}

	public function isIn($cohort) {
		$titles = $this->getCohortTitles();
		return in_array($cohort, $titles);
	}

	public function getCohortTitles() {
		return $this->getCohortNames();
	}

	public function getCohortNames() {
		if ($this->configs) {
			return array_keys($this->configs);
		}
		return array();
	}

	public function getCohort($name) {
		if (isset($this->configs[$name])) {
			return new CohortConfig($name, $this->configs[$name]);
		}
		return null;
	}

	public function addCohort($name, $config) {
		$cohortConfig = new CohortConfig($name);
		$cohortConfig->setCombiner($config['combiner']);
		foreach ($config['rows'] as $row) {
			if (CohortConfig::isValidRow($row)) {
				$cohortConfig->addRow($row);
			}
		}
		$this->configs[$cohortConfig->getName()] = $cohortConfig->getArray();
		$this->save();
	}

	public function nameExists($name) {
		$keys = $this->getCohortNames();
		return in_array($name, $keys);
	}

	public function deleteCohort($name) {
		if (isset($this->configs[$name])) {
			unset($this->configs[$name]);
		}
		$this->save();
	}

	public function modifyCohortName($old, $new) {
		if (isset($this->configs[$old])) {
			$config = $this->configs[$old];
			unset($this->configs[$old]);
			$this->configs[$new] = $config;
		}
		$this->save();
	}

	public function modifyCohort($name, $config) {
		if (!isset($this->configs[$name])) {
			throw new \Exception("$name not found in existing configs!");
		}
		$this->configs[$name] = $config;
		$this->save();
	}

	private function save() {
		if ($this->module) {
			$this->module->setProjectSetting($this->settingName, $this->configs);
		}
	}

	private $settingName;
	private $token;
	private $server;
	private $module;
	private $configs;
}
