<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class PatentsView
{
	public function __construct($recordId, $pid, $startDate = "none", $metadata = []) {
		if (!$recordId) {
			throw new \Exception("recordId is required to access a patent");
		}

		$this->recordId = $recordId;
		$this->pid = $pid;
		if ($startDate == "none") {
			$this->startDate = "";
		} elseif (REDCapManagement::isDate($startDate)) {
			$this->startDate = $startDate;
		} else {
			throw new \Exception("Invalid Patents View start date for Record $recordId: $startDate");
		}
		$this->metadata = $metadata;
	}

	public function setName($lastName, $firstName) {
		if (!$lastName) {
			throw new \Exception("Blank last name for record ".$this->recordId);
		}
		$this->lastName = $lastName;
		$this->firstName = $firstName;
	}

	private function formNameQuery() {
		if (!$this->lastName) {
			throw new \Exception("Does not have last name in Record ".$this->recordId);
		} else {
			$vars = [];
			$vars[] = ["inventor_last_name" => urlencode($this->lastName)];
			if ($this->firstName) {
				$vars[] = ["inventor_first_name" => urlencode($this->firstName)];
			}
			if ($this->startDate) {
				$vars[] = ["_gte" => ["patent_date" => urlencode($this->startDate)]];
			}

			if (count($vars) == 1) {
				return $vars;
			} else {
				return ["_and" => $vars];

			}
		}
	}

	public function getFilteredPatentsAsREDCap($institutions, $maxInstance = 0, $previousPatentNumbers = []) {
		if (empty($institutions)) {
			Application::log("Warning! Institutions is empty in getFilteredPatents for record ".$this->recordId.". Will match against all organizations.");
		}
		$allPatents = $this->getPatents();
		$filteredPatents = [];
		foreach ($allPatents as $patent) {
			$hasInstitution = false;
			foreach ($patent["assignees"] as $orgAry) {
				$org = strtolower($orgAry["assignee_organization"]);
				foreach ($institutions as $institution) {
					if (preg_match("/".$institution."/i", $org)) {
						$hasInstitution = true;
						break;
					}
				}
				if ($hasInstitution || empty($institutions)) {
					if (!in_array($patent['patent_number'], $previousPatentNumbers)) {
						$filteredPatents[] = $patent;
						$previousPatentNumbers[] = $patent['patent_number'];
					}
					break;
				}
			}
		}
		Application::log("Matched ".count($filteredPatents)." patents to Record ".$this->recordId, $this->pid);
		return $this->patents2REDCap($filteredPatents, $maxInstance);
	}

	public function patents2REDCap($patents, $maxInstance) {
		$rows = [];
		$instance = $maxInstance;
		$metadataFields = DataDictionaryManagement::getFieldsFromMetadata($this->metadata);
		foreach ($patents as $patent) {
			$instance++;
			$row = ["record_id" => $this->recordId, "redcap_repeat_instrument" => "patent", "redcap_repeat_instance" => $instance];
			$inventors = ["names" => [], "ids" => []];

			foreach ($patent['inventors'] as $inventor) {
				$inventors["names"][] = $inventor['inventor_first_name']." ".$inventor['inventor_last_name'];
				$inventors["ids"][] = $inventor['inventor_key_id'];
			}
			$assignees = ["names" => [], "ids" => []];
			foreach ($patent['assignees'] as $assignee) {
				$assignees["names"][] = $assignee['assignee_organization'];
				$assignees["ids"][] = $assignee['assignee_key_id'];
			}

			$row['patent_number'] = $patent["patent_number"] ?? "";
			$row['patent_date'] = $patent["patent_date"] ?? "";
			$row['patent_title'] = $patent["patent_title"];
			$row['patent_abstract'] = $patent["patent_abstract"];
			foreach (self::getGovIntFields() as $pvField) {
				if (in_array("patent_".$pvField, $metadataFields)) {
					$row['patent_'.$pvField] = $patent[$pvField] ?? "";
				}
			}
			$row['patent_inventors'] = implode(", ", $inventors["names"]);
			$row['patent_inventor_ids'] = implode(", ", $inventors["ids"]);
			$row['patent_assignees'] = implode(", ", $assignees["names"]);
			$row['patent_assignee_ids'] = implode(", ", $assignees["ids"]);
			$row['patent_last_update'] = date("Y-m-d");
			if (in_array("patent_created", $metadataFields)) {
				$row['patent_created'] = date("Y-m-d");
			}
			$row['patent_complete'] = "2";
			$rows[] = $row;
		}
		return $rows;
	}

	private static function getGovIntFields() {
		return [
			"govint_contract_award_number",
			"govint_org_level_one",
			"govint_org_level_two",
			"govint_org_level_three",
			"govint_org_name",
			"govint_raw_statement",
		];
	}

	public static function getPatentNumbers($redcapData) {
		$numbers = [];
		foreach ($redcapData as $row) {
			if ($row['patent_number'] && ($row['redcap_repeat_instrument'] == "patent")) {
				$numbers[] = $row['patent_number'];
			}
		}
		return $numbers;
	}

	private function formPatentQuery($patentNo) {
		return ["patent_number" => $patentNo];
	}

	public function getDetails($patentNo) {
		$query = $this->formPatentQuery($patentNo);
		if (!empty($query)) {
			$fields = self::getFields();
			$data = $this->getData($query, $fields);
			return $data;
		} else {
			Application::log("Empty query for Record {$this->recordId}");
		}
		return [];
	}

	private function getData($query, $fields) {
		$numPerPage = 50;
		$page = 0;
		$returnQueue = [];
		do {
			$hasMore = false;
			$page++;
			$o = ["page" => $page, "per_page" => $numPerPage];
			$url = "https://api.patentsview.org/patents/query?q=".json_encode($query)."&f=".json_encode($fields)."&o=".json_encode($o);
			list($resp, $json) = URLManagement::downloadURL($url, $this->pid);
			if (REDCapManagement::isJSON($json)) {
				$data = json_decode($json, true);
				if (($data["patents"] === null) || empty($data["patents"])) {
					return [];
				} elseif (isset($data["patents"])) {
					$returnQueue = array_merge($returnQueue, $data["patents"]);
					$hasMore = isset($data['count'])
						&& isset($data['total_patent_count'])
						&& ($data['count'] == $numPerPage)
						&& ($data['count'] < $data['total_patent_count']);
				} else {
					throw new \Exception("Could not find 'patents' in data: ".json_encode($data));
				}
				usleep(500000);
			} else {
				Application::log("Could not decode JSON for Record {$this->recordId} from URL ".$url, $this->pid);
			}
		} while ($hasMore);
		Application::log("Returning ".count($returnQueue)." rows from $page steps", $this->pid);
		return $returnQueue;
	}

	private static function getFields() {
		$patentFields = [
			"patent_number",
			"patent_date",
			"inventor_first_name",
			"inventor_last_name",
			"assignee_organization",
			"patent_abstract",
			"patent_title"
		];
		$govIntFields = self::getGovIntFields();
		return array_merge($patentFields, $govIntFields);
	}

	private function getPatents() {
		$query = $this->formNameQuery();
		if (!empty($query)) {
			$fields = self::getFields();
			return $this->getData($query, $fields);
		} else {
			Application::log("Empty query for Record {$this->recordId}");
		}
		return [];
	}

	protected $lastName = "";
	protected $firstName = "";
	protected $startDate = "";
	protected $recordId = "";
	protected $pid;
	protected $metadata = [];
}
