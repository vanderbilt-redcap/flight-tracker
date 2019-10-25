<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/Upload.php");
require_once(dirname(__FILE__)."/CohortConfig.php");

class Cohorts {
	public function __construct($token, $server, $moduleOrMetadata) {
		$this->token = $token;
		$this->server = $server;
		$this->hijackedField = "identifier_first_name";
		if (is_array($moduleOrMetadata)) {
			$this->metadata = $moduleOrMetadata;
			$this->module = NULL;
		} else {
			$this->module = $moduleOrMetadata;
			$this->metadata = array();
		}

		$this->settingName = "configs";
		$this->configs = $this->getConfigs();
	}

	private function getConfigs() {
		if ($this->module) {
			$configs = $this->module->getProjectSetting($this->settingName); 
			if ($configs) {
				return $configs;
			}
		} else if ($this->metadata) {
			foreach ($this->metadata as $row) {
				if ($row['field_name'] == $this->hijackedField) {
					$json = $row['field_annotation'];
					if ($json) {
						$configs = json_decode($json, true);
						if ($configs) {
							return $configs;
						} else {
							throw new \Exception("Could not decode config JSON: '".$json."'");
						}
					}
				}
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
		} else if ($this->metadata) {
			$json = json_encode($this->configs);
			$newMetadata = array();
			foreach ($this->metadata as $row) {
				if ($row['field_name'] == $this->hijackedField) {
					$row['field_annotation'] = $json;
				}
				array_push($newMetadata, $row);
			}
			$this->metadata = $newMetadata;
			$feedback = Upload::metadata($newMetadata, $this->token, $this->server);
			error_log("Config save: ".json_encode($feedback));
		}
	}

	private $settingName;
	private $token;
	private $server;
	private $module;
	private $metadata;
	private $hijackedField;
	private $configs;
}
