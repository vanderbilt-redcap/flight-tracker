<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class RePORTER
{
	public const NIH_API_VERSION = "v2";

	public function __construct($pid, $recordId, $category, $recordExcludeList = []) {
		$this->pid = $pid;
		$this->recordId = $recordId;
		$this->category = $category;
		$this->includeFields = [
			"ApplId","SubprojectId","FiscalYear","OrgName","OrgCity",
			"OrgState","OrgStateName","DeptType", "ProjectNum","OrgCountry",
			"ProjectNumSplit","ContactPiName","AllText","FullStudySection",
			"ProjectStartDate","ProjectEndDate","ProjectTitle",
		];
		$this->excludeList = $recordExcludeList;
		if ($this->category == "NIH") {
			$this->server = "https://api.reporter.nih.gov";
		} elseif ($this->category == "Federal") {
			$this->server = "https://api.federalreporter.nih.gov";
		} else {
			throw new \Exception("Wrong category!");
		}
	}

	public function getTitleOfGrant($awardNo) {
		$this->searchAward($awardNo);
		foreach ($this->getData() as $item) {
			if (isset($item['project_title'])) {
				return $item['project_title'];
			}
		}
		return $awardNo;
	}

	public static function decodeICFundings($mangledJSON) {
		if (!$mangledJSON) {
			return [];
		}
		$mangledJSON = str_replace("\"fy\"", "'fy'", $mangledJSON);
		$jsonOfStrings = str_replace("\"total_cost\"", "'total_cost'", $mangledJSON);
		$strEntries = json_decode($jsonOfStrings, true);
		$entries = [];
		foreach ($strEntries as $mangledJSON) {
			if (is_string($mangledJSON)) {
				$mangledJSON = str_replace("'", "\"", $mangledJSON);
				$ary = json_decode($mangledJSON, true);
			} else {
				$ary = $mangledJSON;
			}
			$entries[] = $ary;
		}
		return $entries;
	}

	public function getTitlesOfGrants($awardNumbers) {
		$this->searchAwards($awardNumbers);
		$translate = [];
		foreach ($awardNumbers as $awardNo) {
			$upperAwardNo = strtoupper($awardNo);
			$translate[$awardNo] = $upperAwardNo;
			foreach ($this->getData() as $item) {
				$itemAwardNo = strtoupper($item['project_num']);
				if (preg_match("/$upperAwardNo/", $itemAwardNo) && isset($item['project_title'])) {
					$translate[$awardNo] = $item['project_title'];
					break;
				}
			}
		}
		return $translate;
	}

	public function getTotalDollarsForInstitution($institution, $fiscalYear) {
		$total = 0;
		if ($this->isFederal()) {
			# retired
			return 0;
		} elseif ($this->isNIH()) {
			$payload = [
				"criteria" => [
					"org_names" => [$institution],
				],
			];
			$location = $this->server."/".self::NIH_API_VERSION."/projects/search";
			$this->currData = $this->runPOSTQuery($location, $payload);
			foreach ($this->currData as $line) {
				if ($line['nih_agency_ic_fundings']) {
					$fiscalData = self::decodeICFundings($line['nih_agency_ic_fundings'] ?: '[]');
					foreach ($fiscalData as $oneYearsData) {
						if ($oneYearsData['fy'] == $fiscalYear) {
							$total += $oneYearsData['total_cost'];
						}
					}
				}
			}
		}
		return $total;
	}

	public function getProjectDatesForAward($baseNumber) {
		$largestTimespan = 0;
		$largestStartTs = 0;
		$largestEndTs = 0;
		foreach ($this->getData() as $row) {
			if ($this->isFederal()) {
				$awardNo = $row['projectNumber'];
				$projectStart = REDCapManagement::getReporterDateInYMD($row['projectStartDate']);
				$projectEnd = REDCapManagement::getReporterDateInYMD($row['projectEndDate']);
			} elseif ($this->isNIH()) {
				$awardNo = $row['projectNumber'];
				$projectStart = REDCapManagement::getReporterDateInYMD($row['projectStartDate']);
				$projectEnd = REDCapManagement::getReporterDateInYMD($row['projectEndDate']);
			} else {
				$awardNo = "";
				$projectEnd = "";
				$projectStart = "";
			}
			if ($awardNo && $projectEnd && $projectStart) {
				$projectStartTs = strtotime($projectStart);
				$projectEndTs = strtotime($projectEnd);
				if (preg_match("/$baseNumber/i", $awardNo) && ($projectEndTs - $projectStartTs > $largestTimespan)) {
					$largestTimespan = $projectEndTs - $projectStartTs;
					$largestEndTs = $projectEndTs;
					$largestStartTs = $projectStartTs;
				}
			}
		}
		if ($largestTimespan > 0) {
			return [date("Y-m-d", $largestStartTs), date("Y-m-d", $largestEndTs)];
		}
		return ["", ""];
	}

	public function getInstrument() {
		if ($this->isNIH()) {
			return "nih_reporter";
		} elseif ($this->isFederal()) {
			return "reporter";
		}
		throw new \Exception("No instrument specified!");
	}

	public function getPrefix() {
		if ($this->isNIH()) {
			return "nih_";
		} elseif ($this->isFederal()) {
			return "reporter_";
		}
		throw new \Exception("No instrument specified!");
	}

	public function getRoleForCurrentAward($baseNumber) {
		$currTs = time();
		$role = "";
		$awardNo = "";
		$startTs = time();
		$endTs = 0;
		foreach ($this->getData() as $row) {
			if ($this->isNIH()) {
				$awardNo = $row['project_num'];
				$startTs = strtotime(REDCapManagement::getReporterDateInYMD($row['project_start_date']));
				$endTs = strtotime(REDCapManagement::getReporterDateInYMD($row['project_end_date']));
				if ($row['principal_investigators']) {
					$numNodes = count(preg_split("/\s*;\s*/", $row['principal_investigators']));
				} else {
					$numNodes = 0;
				}
				if ($row['subproject_id']) {
					$role = "Project PI";
				} elseif ($numNodes <= 1) {
					$role = "PI";
				} elseif ($numNodes > 1) {
					$role = "Co-PI";
				} else {
					$role = "";
				}
			} elseif ($this->isFederal()) {
				$awardNo = $row['projectNumber'];
				$startTs = strtotime(REDCapManagement::getReporterDateInYMD($row['budgetEndDate']));
				$endTs = strtotime(REDCapManagement::getReporterDateInYMD($row['budgetStartDate']));
				if ($row['otherPis']) {
					$role = "Co-PI";
				} else {
					$role = "PI";
				}
			}
			if ($role && $awardNo && preg_match("/$baseNumber/i", $awardNo) && ($startTs <= $currTs) && ($endTs >= $currTs)) {
				return $role;
			}
		}
		return "";
	}

	public function deleteMiddleNameOnlyMatches($redcapData, $firstName, $lastName, $middleName, $institutions) {
		$token = Application::getSetting("token", $this->pid);
		$redcapServer = Application::getSetting("server", $this->pid);
		if (NameMatcher::isInitial($middleName)) {
			$firstNames = NameMatcher::explodeFirstName($firstName);
			$lastNames = NameMatcher::explodeLastName($lastName);
			foreach ($lastNames as $lastName) {
				foreach ($firstNames as $firstName) {
					$name = "$firstName $lastName";
					$this->searchPIAndAddToList($name, $institutions);
				}
			}
			$awardNumbersMatchedByFirst = $this->getAwardNumbers();
			$this->clearData();

			$candidateNames = [$middleName];
			foreach ($lastNames as $lastName) {
				foreach ($candidateNames as $candidateName) {
					$name = "$candidateName $lastName";
					$this->searchPIAndAddToList($name, $institutions);
				}
			}
			$awardNumbersMatchedByMiddle = $this->getAwardNumbers();

			$instrument = $this->getInstrument();
			$awardField = $this->getAwardField();
			$instancesToDelete = [];
			foreach ($redcapData as $row) {
				if (($row['record_id'] == $this->recordId) && ($row['redcap_repeat_instrument'] == $instrument)) {
					$awardNo = $row[$awardField];
					if ($awardNo && in_array($awardNo, $awardNumbersMatchedByMiddle) && !in_array($awardNo, $awardNumbersMatchedByFirst)) {
						$instancesToDelete[] = $row['redcap_repeat_instance'];
					}
				}
			}

			if (!empty($instancesToDelete)) {
				$prefix = $this->getPrefix();
				Application::log("Deleting reporter instances for prefix $prefix on record {$this->recordId}: ".implode(", ", $instancesToDelete), $this->pid);
				Upload::deleteFormInstances($token, $redcapServer, $this->pid, $prefix, $this->recordId, $instancesToDelete);
			}
		}
	}

	public function clearData() {
		$this->currData = [];
	}

	private function getAwardField() {
		if ($this->isNIH()) {
			return "nih_project_num";
		} elseif ($this->isFederal()) {
			return "reporter_projectnumber";
		}
		throw new \Exception("No award field!");
	}

	public function getAwardNumbers() {
		$maxInstance = 0;
		$grantsToFilterOut = [];
		$rows = $this->getUploadRows($maxInstance, $grantsToFilterOut);
		$field = $this->getAwardField();
		$values = [];
		foreach ($rows as $row) {
			if (isset($row[$field]) && $row[$field]) {
				$values[] = $row[$field];
			}
		}
		return $values;
	}

	public function getUploadRows(&$maxInstance, &$grantsToFilterOut) {
		if ($this->isNIH()) {
			return $this->getNIHUploadRows($maxInstance, $grantsToFilterOut);
		} elseif ($this->isFederal()) {
			return $this->getFederalUploadRows($maxInstance, $grantsToFilterOut);
		}
		return [];
	}

	private function getNIHUploadRows(&$max, &$existingGrants) {
		$arrayFields = [
			"principal_investigators" => "full_name",
			"program_officers" => "full_name",
			"agency_ic_fundings" => ["fy", "total_cost"],
			"agency_ic_admin" => "name",
		];
		$dateFields = [
			"project_start_date",
			"project_end_date",
			"init_encumbrance_date",
			"award_notice_date",
			"budget_start",
			"budget_end",
			"date_added",
			];
		$skip = ["spending_categories", "organization_type", ];
		$upload = [];
		$metadataFields = Download::metadataFieldsByPid($this->pid);

		foreach ($this->getData() as $item) {
			if (!in_array($item['project_num'], $existingGrants)) {
				$max++;
				$uploadRow = [
					"record_id" => $this->recordId,
					"redcap_repeat_instrument" => "nih_reporter",
					"redcap_repeat_instance" => $max,
					"nih_reporter_complete" => "2",
					"nih_last_update" => date("Y-m-d"),
				];
				if (in_array("nih_created", $metadataFields)) {
					$uploadRow["nih_created"] = date("Y-m-d");
				}
				foreach ($item as $key => $value) {
					if (!in_array($key, $skip)) {
						$newField = "nih_" . strtolower($key);
						if (isset($arrayFields[$key])) {
							$valueKey = $arrayFields[$key];
							$items2 = [];
							if (is_string($valueKey) && isset($value[$valueKey])) {
								$items2[] = $value[$valueKey];
							} else {
								foreach ($value as $item2) {
									if (is_array($valueKey)) {
										$list = [];
										foreach ($valueKey as $item2Key) {
											if (isset($item2[$item2Key])) {
												$list[$item2Key] = $item2[$item2Key];
											} else {
												$list[$item2Key] = "";
											}
										}
										$items2[] = json_encode($list);
									} elseif (is_string($valueKey)) {
										if (isset($item2[$valueKey])) {
											$items2[] = $item2[$valueKey];
										}
									}
								}
							}
							if (is_array($valueKey)) {
								$value = json_encode($items2);
							} else {
								$value = implode("; ", $items2);
							}
						}
						if ($value === true) {
							$value = "1";
						}
						if (in_array($key, $dateFields) && $value) {
							$value = REDCapManagement::getReporterDateInYMD($value);
						}
						if (is_array($value)) {
							$value = json_encode($value);
						}
						if ($value) {
							$value = preg_replace("/\s+/", " ", $value);
						} else {
							$value = "";
						}
						$uploadRow[$newField] = $value;
					}
				}
				$upload[] = $uploadRow;
			}
		}
		return $upload;
	}

	private function getFederalUploadRows(&$max, &$existingGrants) {
		$upload = [];
		$dateFields = ["budgetStartDate", "budgetEndDate", "projectStartDate", "projectEndDate"];
		// Application::log("existingGrants: ".json_encode($existingGrants));
		// Application::log("Looking through ".count($this->getData())." items");
		foreach ($this->getData() as $item) {
			// Application::log("Federal: ".json_encode($item));
			if (!in_array($item['projectNumber'], $existingGrants)) {
				$existingGrants[] = $item['projectNumber'];
				$max++;
				$uploadRow = [
					"record_id" => $this->recordId,
					"redcap_repeat_instrument" => "reporter",
					"redcap_repeat_instance" => $max,
					"reporter_complete" => "2",
					"reporter_last_update" => date("Y-m-d"),
				];
				$uploadRow["record_id"] = $this->recordId;
				foreach ($item as $field => $value) {
					$newField = "reporter_" . strtolower($field);
					if (in_array($field, $dateFields) && $value) {
						$value = REDCapManagement::getReporterDateInYMD($value);
					}
					$uploadRow[$newField] = $value;
				}
				$upload[] = $uploadRow;
			}
		}
		return $upload;
	}

	public function searchAwards($awardNumbers) {
		if (empty($awardNumbers)) {
			return [];
		}
		if ($this->isFederal()) {
			// $query = $this->server."/v1/projects/search?query=projectNumber:*".urlencode($baseAwardNo)."*";
			// $this->currData = $this->runGETQuery($query);
		} elseif ($this->isNIH()) {
			$payload = [
				"criteria" => ["project_nums" => $awardNumbers],
				"include_fields" => $this->includeFields,
			];
			$location = $this->server."/".self::NIH_API_VERSION."/projects/search";
			$this->currData = $this->runPOSTQuery($location, $payload);
		}
		return $this->getData();
	}

	public function searchAward($baseAwardNo) {
		if ($this->isFederal()) {
			$query = $this->server."/v1/projects/search?query=projectNumber:*".urlencode($baseAwardNo)."*";
			$this->currData = $this->runGETQuery($query);
		} elseif ($this->isNIH()) {
			$payload = [
				"criteria" => ["project_nums" => ["?$baseAwardNo*"]],
				"include_fields" => $this->includeFields,
			];
			$location = $this->server."/".self::NIH_API_VERSION."/projects/search";
			$this->currData = $this->runPOSTQuery($location, $payload);
		}
		return $this->getData();
	}

	public function runPOSTQuery($url, $postdata, $limit = 500, $offset = 0) {
		$postdata['limit'] = $limit;
		$postdata['offset'] = $offset;
		$data = [];
		do {
			list($resp, $output) = $this->downloadPOST($url, $postdata);
			$this->sleep();
			$runAgain = false;
			if ($resp == 200) {
				$fullResults = json_decode($output, true);
				$data = array_merge($data, $fullResults['results']);
				if (count($fullResults['results']) == $limit) {
					$offset += $limit;
					$postdata['offset'] = $offset;
					$runAgain = true;
				}
			}
		} while ($runAgain);
		return $data;
	}

	public function downloadPOST($url, $postdata) {
		return REDCapManagement::downloadURLWithPOST($url, $postdata, $this->pid);
	}

	public static function getTypes() {
		return ["NIH"];
	}

	public function searchInstitutionsAndGrantTypes($institutions, $grantTypes) {
		$searchStrings = [];
		foreach ($grantTypes as $grantType) {
			$searchStrings[] = "$grantType";
		}
		if ($this->isFederal()) {
			$this->currData = [];
			foreach ($institutions as $institution) {
				foreach ($searchStrings as $searchString) {
					$query = $this->server . "/v1/projects/search?query=orgName:" . urlencode($institution)."\$projectNumber:".urlencode($searchString);
					$queryData = $this->runGETQuery($query);
					$this->currData = array_merge($this->currData, $queryData);
				}
			}
			$this->deduplicateData();
			$this->currData = $this->filterForExcludeList();
			$this->currData = $this->filterForGrantTypes($grantTypes);
		} elseif ($this->isNIH()) {
			$payload = [
				"criteria" => [
					"org_names" => $institutions,
					"project_nums" => $searchStrings,
				],
			];
			$location = $this->server."/".self::NIH_API_VERSION."/projects/search";
			$this->currData = $this->runPOSTQuery($location, $payload);
			$this->currData = $this->filterForExcludeList();
			$this->currData = $this->filterForGrantTypes($grantTypes);
		}
		return $this->getData();
	}

	public function filterForGrantTypes($grantTypes) {
		$filteredData = [];
		foreach ($this->currData as $line) {
			if ($this->isNIH()) {
				$awardNo = $line['project_num'];
			} elseif ($this->isFederal()) {
				$awardNo = $line['projectNumber'];
			} else {
				throw new \Exception("Invalid category " . $this->category);
			}
			$activityType = Grant::getActivityCode($awardNo);
			if (in_array($activityType, $grantTypes) && !isset($filteredData[$awardNo])) {
				$filteredData[$awardNo] = $line;
			}
		}
		$this->currData = array_values($filteredData);
		return $this->currData;
	}

	public function searchPI($piName, $institutions) {
		$piName = trim($piName);
		if (!$piName || empty($institutions)) {
			return [];
		}
		$searchInstitutions = !((count($institutions) == 1) && ($institutions[0] == "all"));
		if ($this->isFederal()) {
			$query = $this->server."/v1/projects/search?query=PiName:".urlencode($piName);
			$this->currData = $this->runGETQuery($query);
			$this->currData = $this->filterForExcludeList();
			if ($searchInstitutions) {
				$this->currData = $this->filterForInstitutionsAndName($piName, $institutions);
			}
		} elseif ($this->isNIH()) {
			list($firstName, $lastName) = NameMatcher::splitName($piName, 2);
			if ($searchInstitutions) {
				$criteria = [
					"pi_names" => [["last_name" => $lastName]],
					"org_names" => $institutions,
				];
			} else {
				$criteria = [
					"pi_names" => [["last_name" => $lastName]],
				];
			}
			$payload = [
				"criteria" => $criteria,
			];
			$location = $this->server."/".self::NIH_API_VERSION."/projects/search";
			$data = $this->runPOSTQuery($location, $payload);
			$this->currData = self::filterNIHDataForName($data, $firstName, $lastName);
			$this->currData = $this->filterForExcludeList();
		}
		return $this->getData();
	}

	# No-Cost Extensions are times when the NIH extends a grant without extending any of the funding
	# They show up in the NIH RePORTER through extended dates that affect only a small number of fields
	# This updates any prior versions in REDCap to reflect the extended dates
	# Meant to be run before REDCap is deduplicated for the NIH Reporter instrument so that there will be two of the same awards
	public static function updateEndDates($token, $server, $pid, $records, $prefix, $instrument) {
		$metadataFields = Download::metadataFields($token, $server);
		$nihReporterFields = DataDictionaryManagement::filterFieldsForPrefix($metadataFields, $prefix);
		if (empty($nihReporterFields)) {
			return [];
		}
		$fieldsToDownload = array_merge(["record_id"], $nihReporterFields);
		$fieldsToUpdate = ["nih_project_end_date", "nih_agency_ic_fundings", "nih_award_notice_date"];
		$lastUpdateField = "nih_last_update";
		$awardNoField = "nih_project_num";
		$upload = [];
		foreach ($records as $recordId) {
			$awardNoWithInstances = [];
			$redcapData = Download::fieldsForRecords($token, $server, $fieldsToDownload, [$recordId]);
			foreach ($redcapData as $row) {
				if (array_key_exists("redcap_repeat_instrument", $row) && $row["redcap_repeat_instrument"] == $instrument) {
					if (!isset($awardNoWithInstances[$row[$awardNoField]])) {
						$awardNoWithInstances[$row[$awardNoField]] = [];
					}
					$awardNoWithInstances[$row[$awardNoField]][$row['redcap_repeat_instance']] = $row[$lastUpdateField];
				}
			}

			foreach ($awardNoWithInstances as $awardNo => $instancesWithDates) {
				if (count($instancesWithDates) >= 2) {
					$latestInstance = array_keys($instancesWithDates)[0];
					foreach ($instancesWithDates as $instance => $lastUpdate) {
						if (DateManagement::dateCompare($instancesWithDates[$latestInstance], "<", $lastUpdate)) {
							$latestInstance = $instance;
						}
					}
					foreach (array_keys($instancesWithDates) as $instance) {
						if ($instance != $latestInstance) {
							$uploadRow = [
								"record_id" => $recordId,
								"redcap_repeat_instrument" => $instrument,
								"redcap_repeat_instance" => $instance,
							];
							foreach ($fieldsToUpdate as $field) {
								$latestValue = REDCapManagement::findField($redcapData, $recordId, $field, true, $latestInstance);
								$currValue = REDCapManagement::findField($redcapData, $recordId, $field, true, $instance);
								if ($latestValue != $currValue) {
									$uploadRow[$field] = $latestValue;
								}
							}
							if (count($uploadRow) >= 3) {
								Application::log("Record $recordId: Updated No-Cost Extension for $awardNo instance $instance", $pid);
								$upload[] = $uploadRow;
							}
						}
					}
				}
			}
		}
		if (!empty($upload)) {
			return Upload::rows($upload, $token, $server);
		} else {
			return [];
		}
	}

	public function deduplicateData() {
		$filteredData = [];
		foreach ($this->currData as $line) {
			if ($this->isNIH()) {
				$awardNo = $line['project_num'];
			} elseif ($this->isFederal()) {
				$awardNo = $line['projectNumber'];
			} else {
				throw new \Exception("Invalid category " . $this->category);
			}
			if (!isset($filteredData[$awardNo])) {
				$filteredData[$awardNo] = $line;
			}
		}
		$this->currData = array_values($filteredData);
	}

	public function searchPIAndAddToList($piName, $institutions) {
		$oldData = $this->getData();
		$this->searchPI($piName, $institutions);
		if (!empty($oldData)) {
			$this->currData = array_merge($oldData, $this->currData);
		}
	}

	private static function filterNIHDataForName($data, $firstName, $lastName) {
		$newData = [];
		foreach ($data as $line) {
			$found = false;
			if ($line['contact_pi_name']) {
				list($contactFirst, $contactLast) = NameMatcher::splitName($line['contact_pi_name']);
				if (NameMatcher::matchName($contactFirst, $contactLast, $firstName, $lastName)) {
					$newData[] = $line;
					$found = true;
				}
			}
			if (!$found) {
				foreach ($line['principal_investigators'] as $pi) {
					// Application::log("Inspecting PI ".json_encode($pi));
					if (NameMatcher::matchName($pi['first_name'], $pi['last_name'], $firstName, $lastName)) {
						$newData[] = $line;
						break;   // inner
					}
				}
			}
		}
		return $newData;
	}

	public function getAssociatedAwardNumbers($awardNo) {
		$this->searchAward($awardNo);
		$awardNumbers = [];
		foreach ($this->getData() as $item) {
			if (isset($item['projectNumber'])) {
				$awardNumbers[] = $item['projectNumber'];
			} elseif (isset($item['project_num'])) {
				$awardNumbers[] = $item['project_num'];
			}
		}
		return $awardNumbers;
	}

	private function filterForInstitutionsAndName($name, $institutions) {
		if (method_exists("\Vanderbilt\CareerDevLibrary\Application", "getHelperInstitutions")) {
			$helperInstitutions = Application::getHelperInstitutions($this->pid);
		} else {
			$helperInstitutions = [];
		}
		list($firstName, $lastName) = NameMatcher::splitName($name, 2);
		# dissect current data; must have first name to include
		$included = [];
		foreach ($this->getData() as $item) {
			$pis = [];
			$itemName = $item['contactPi'];
			if (!in_array($itemName, $pis)) {
				$pis[] = $itemName;
			}
			if ($item['otherPis']) {
				$otherPis = preg_split("/\s*;\s*/", $item['otherPis']);
				foreach ($otherPis as $otherPi) {
					$otherPi = trim($otherPi);
					if ($otherPi && !in_array($otherPi, $pis)) {
						$pis[] = $otherPi;
					}
				}
			}
			$found = false;
			foreach ($pis as $itemName) {
				$itemNames = preg_split("/\s*,\s*/", $itemName);
				// $itemLastName = $itemNames[0];
				if (count($itemNames) > 1) {
					$itemFirstName = $itemNames[1];
				} else {
					$itemFirstName = $itemNames[0];
				}
				$listOfFirstNames = preg_split("/\s/", strtoupper($firstName));
				foreach ($institutions as $institution) {
					foreach ($listOfFirstNames as $myFirstName) {
						$myFirstName = preg_replace("/^\(/", "", $myFirstName);
						$myFirstName = preg_replace("/\)$/", "", $myFirstName);
						if (preg_match("/".strtoupper($myFirstName)."/", $itemFirstName) && (preg_match("/$institution/i", $item['orgName']))) {
							// Application::log("Possible match $itemFirstName and $institution vs. '{$item['orgName']}'", $this->pid);
							if (in_array($institution, $helperInstitutions)) {
								$proceed = false;
								if (method_exists("\Vanderbilt\CareerDevLibrary\Application", "getCities")) {
									foreach (Application::getCities() as $city) {
										if (preg_match("/".$city."/i", $item['orgCity'])) {
											$proceed = true;
										}
									}
								}
							} else {
								$proceed = true;
								$isVanderbilt = method_exists("\Vanderbilt\CareerDevLibrary\Application", "isVanderbilt") && Application::isVanderbilt();
								if ($isVanderbilt && ((strtoupper($myFirstName) != "HAROLD") && (strtoupper($lastName) == "MOSES") && preg_match("/HAROLD L/i", $myFirstName))) {
									# Hack: exclude HAROLD L MOSES since HAROLD MOSES JR is valid
									$proceed = false;
								}
							}
							if ($proceed) {
								// Application::log("Including $itemFirstName {$item['orgName']}", $this->pid);
								$included[] = $item;
								$found = true;
								break;
							}
						} else {
							// echo "Not including $itemFirstName {$item['orgName']}\n";
						}
					}
					if ($found) {
						break;
					}
				}
				if ($found) {
					break;
				}
			}
		}
		// Application::log($this->recordId.": $firstName $lastName included ".count($included), $this->pid);
		// echo "itemNames: ".json_encode($pis)."\n";
		return $included;
	}

	private function runGETQuery($location) {
		$currData = [];
		$try = 0;
		$max = 0;   // reset with every new name
		$myData = false;
		do {
			$try++;
			$url = $location."&offset=".($max + 1);
			list($resp, $output) = REDCapManagement::downloadURL($url, $this->pid);
			if ($resp == 200) {
				$myData = json_decode($output, true);
				$currDataChanged = false;
				if ($myData) {
					if (isset($myData['items'])) {
						foreach ($myData['items'] as $item) {
							$currData[] = $item;
							$currDataChanged = true;
						}
						// Application::log("Checking {$myData['totalCount']} (".count($myData['items'])." here) and ".($myData['offset'] - 1 + $myData['limit']));
					}
					if (isset($myData['offset']) && isset($myData['limit'])) {
						$max = $myData['offset'] + $myData['limit'] - 1;
					} else {
						$myData = false;
					}
					if (!$currDataChanged) {
						# protect from infinite loops
						$try++;
					}
					$this->sleep();
				} else {
					$myData = false;
					$try++;
				}
				// Application::log("Try $try: Checking {$myData['totalCount']} and {$myData['offset']} and {$myData['limit']}");
			}
		} while (!$myData || (count($currData) < $myData['totalCount']) && ($try <= 5));
		// Application::log($this->recordId.": currData ".count($currData));
		return $currData;
	}

	private function sleep() {
		if ($this->isNIH()) {
			sleep(1);
		} elseif ($this->isFederal()) {
			usleep(400000);     // up to 3 per second
		}
	}

	public function getData() {
		return $this->filterForExcludeList();
	}

	private function filterForExcludeList() {
		if (empty($this->excludeList)) {
			return $this->currData;
		}
		$newData = [];
		foreach ($this->currData as $item) {
			if ($this->isNIH()) {
				$excludeThisItem = false;
				foreach ($this->excludeList as $excludeName) {
					if (strtolower($excludeName) == strtolower($item['contact_pi_name'])) {
						$excludeThisItem = true;
						break;
					}
					foreach ($item['principal_investigators'] as $pi) {
						$piNames = [
							$pi['first_name']." ".$pi['last_name'],
							$pi['last_name']." ".$pi['first_name'],
							$pi['last_name'].", ".$pi['first_name'],
						];
						if ($pi['middle_name'] ?? false) {
							$piNames[] = $pi['first_name']." ".$pi['middle_name']." ".$pi['last_name'];
							$piNames[] = $pi['last_name'].", ".$pi['first_name']." ".$pi['middle_name'];
						}
						foreach ($piNames as $piName) {
							if (strtolower($piName) == strtolower($excludeName)) {
								$excludeThisItem = true;
								break;
							}
						}
						if ($excludeThisItem) {
							break;
						}
					}
				}
				if (!$excludeThisItem) {
					$newData[] = $item;
				}
			} elseif ($this->isFederal()) {
				$pis = [];
				if ($item['contactPi']) {
					$pis[] = trim($item['contactPi']);
				}
				if ($item['otherPis']) {
					foreach (preg_split("/\s*;\s*/", $item['otherPis']) as $pi) {
						$pi = trim($pi);
						if ($pi) {
							$pis[] = $pi;
						}
					}
				}
				$excludeThisItem = false;
				foreach ($this->excludeList as $excludeName) {
					foreach ($pis as $pi) {
						if ($pi && (strtolower($pi) == strtolower($excludeName))) {
							$excludeThisItem = true;
							break;
						}
					}
					if ($excludeThisItem) {
						break;
					}
				}
				if (!$excludeThisItem) {
					$newData[] = $item;
				}
			} else {
				$newData[] = $item;
			}
		}
		$this->currData = $newData;
		return $this->currData;
	}

	private function isNIH() {
		return ($this->category == "NIH");
	}

	private function isFederal() {
		return ($this->category == "Federal");
	}

	private $recordId;
	private $pid;
	private $server;
	private $currData;
	private $category;
	private $includeFields;
	private $excludeList;
}
