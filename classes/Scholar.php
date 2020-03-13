<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/Grants.php");
require_once(dirname(__FILE__)."/Upload.php");
require_once(dirname(__FILE__)."/Download.php");
require_once(dirname(__FILE__)."/Links.php");
require_once(dirname(__FILE__)."/REDCapManagement.php");
require_once(dirname(__FILE__)."/../Application.php");

define("SOURCETYPE_FIELD", "additional_source_types");

class Scholar {
	public function __construct($token, $server, $metadata = array(), $pid = "") {
		$this->token = $token;
		$this->server = $server;
		$this->pid = $pid;
		if (empty($metadata)) {
			$this->metadata = Download::metadata($token, $server);
		} else {
			$this->metadata = $metadata;
		}
	}

	# for identifier_left_job_category
	public static function isOutsideAcademe($jobCategory) {
		$outside = array(3, 4, 6);
		if (in_array($jobCategory, $outside)) {
			return TRUE;
		}
		return FALSE;
	}

	public static function getRepeatingForms($pid) {
		return REDCapManagement::getRepeatingForms($pid);
	}

	public static function isDependentOnAcademia($field) {
		$dependent = array("summary_current_division", "summary_current_rank", "summary_current_tenure", );
		if (in_array($field, $dependent)) {
			return TRUE;
		}
		return FALSE;
	}

	public static function addSourceType($module, $code, $sourceType, $pid) {
		if ($module) {
			$data = $module->getProjectSetting(SOURCETYPE_FIELD, $pid);
			if (!$data) {
				$data = array();
			}
			if (!isset($data[$sourceType])) {
				$data[$sourceType] = array();
			}
			array_push($data[$sourceType], $code);
			$module->setProjectSetting(SOURCETYPE_FIELD, $data, $pid);
			return TRUE;
		}
		return FALSE;
	}

	public static function getAdditionalSourceTypes($module, $sourceType, $pid) {
		if ($module) { 
			$data = $module->getProjectSetting(SOURCETYPE_FIELD, $pid);
			if (!$data || !isset($data[$sourceType])) {
				return array();
			}
			return $data[$sourceType];
		}
		return array();
	}

	public static function getChoices($metadata = array()) {
		if (!empty($metadata)) {
			self::$choices = REDCapManagement::getChoices($metadata);
		}
		return self::$choices;
	}

	public static function getKLength($type) {
		Application::log("Getting K Length for $type");
		if ($type == "Internal") {
			return Application::getInternalKLength();
		} else if ($type == 1) {
			return Application::getInternalKLength();
		} else if ($type == "K12/KL2") {
			return Application::getK12KL2Length();
		} else if ($type == 2) {
			return Application::getK12KL2Length();
		} else if ($type == "External") {
			return Application::getIndividualKLength();
		} else if (($type == 3) || ($type == 4)) {
			return Application::getIndividualKLength();
		}
		return 0;
	}

	public function setGrants($grants) {
		$this->grants = $grants;
	}

	public function getORCID() {
		$result = $this->getORCIDResult($this->rows);
		return $result->getValue();
	}

	public function getORCIDWithLink() {
		$orcid = $this->getORCID();
		return Links::makeLink("https://orcid.org/".$orcid, $orcid);
	}

	public function getORCIDResult($rows) {
		$row = self::getNormativeRow(rows);

		# by default use identifier; if not specified, get result through default order
		if ($row['identifier_orcid']) {
			$result = new Result($row['identifier_orcid'], "", "", "", $this->pid);
		} else {
			$vars = self::getDefaultOrder("identifier_orcid");
			error_log("getORCIDResult looking through ".json_encode($vars));
			$result = self::searchRowsForVars($rows, $vars, FALSE, $this->pid);
		}
		$value = $result->getValue();
		$searchTerm = "/^https:\/\/orcid.org\//";
		if (preg_match($searchTerm, $value)) {
			# they provided URL instead of number
			$result->setValue(preg_replace($searchTerm, "", $value));
		}
		error_log("getORCIDResult returning ".$result->getValue()." and source ".$result->getSource());
		return $result;
	}

	public function getEmail() {
		$row = self::getNormativeRow($this->rows);
		return $row['identifier_email'];
	}

	public function getResourcesUsed() {
		$resources = array();
		$choices = self::getChoices($this->metadata);
		foreach ($this->rows as $row) {
			$date = "";
			$resource = "";
			foreach ($row as $field => $value) {
				if ($value) {
					if ($field == "resources_used") {
						$date = $value;
					} else if ($field == "resources_resource") {
						$resource = $choices['resources_resource'][$value];
					}
				}
			}
			if ($resource) {
				$title = $resource;
				if ($date) {
					$title .= " (".$date.")";
				}
				array_push($resources, $title);
			}
		}
		return $resources;
	}

	private static function nameInList($name, $list) {
		$name = strtolower($name);
		$lowerList = array();
		foreach ($list as $item) {
			array_push($lowerList, strtolower($item));
		}
		return in_array($name, $lowerList);
	}

	public function getMentors() {
		$mentorFields = $this->getMentorFields();
		$mentors = array();
		foreach ($this->rows as $row) {
			foreach ($row as $field => $value) {
				if ($value && in_array($field, $mentorFields)) {
					if (!self::nameInList($value, $mentors)) { 
						array_push($mentors, $value);
					}
				}
			}
		}
		return $mentors;
	}

	private function getMentorFields() {
		$fields = array();
		$skipRegex = array(
					"/_vunet$/",
					"/_source$/",
					"/_sourcetype$/",
					);

		foreach ($this->metadata as $row) {
			if (preg_match("/mentor/", $row['field_name'])) {
				$skip = FALSE;
				foreach ($skipRegex as $regex) {
					if (preg_match($regex, $row['field_name'])) {
						$skip = TRUE;
						break;
					}
				}
				if (!$skip) {
					array_push($fields, $row['field_name']);
				}
			}
		}
		return $fields;
	}

	public function getEmploymentStatus() {
		$left = $this->getWhenLeftInstitution($this->rows);
		if ($left->getValue()) {
			return $left->getValue();
		}
		$row = self::getNormativeRow($this->rows);
		if ($row['identifier_institution']) {
			if ($row['identifier_institution'] && in_array($row['identifier_institution'], Application::getInstitutions())) {
				return "Employed at ".$row['identifier_institution'];
			} else if ($row['identifier_institution'] && ($row['identifier_institution'] != Application::getUnknown())) {
				$institution = " for ".$row['identifier_institution'];
				$date = "";
				if ($row['identifier_left_date']) {
					$date = " on ".$row['identifier_left_date'];
				}
				return "Left ".Application::getInstitution().$institution.$date;
			}
		} else if ($row['identifier_left_date']) {
			$date = " on ".$row['identifier_left_date'];
			return "Left ".Application::getInstitution().$date;
		}
		return "Employed at ".Application::getInstitution();
	}

	public function getDegreesText() {
		$degreesResult = $this->getDegrees($this->rows);
		$degrees = $degreesResult->getValue();
		$choices = self::getChoices($this->metadata);
		return $choices["summary_degrees"][$degrees];
	}

	public function getPrimaryDepartmentText() {
		$deptResult = $this->getPrimaryDepartment($this->rows);
		$dept = $deptResult->getValue();
		$choices = self::getChoices($this->metadata);
		return $choices["summary_primary_dept"][$dept];
	}

	public function getName($type = "full") {
		$nameAry = $this->getNameAry();

		if ($type == "first") {
			return $nameAry['identifier_first_name'];
		} else if ($type == "last") {
			return $nameAry['identifier_last_name'];
		} else if ($type == "full") {
			return $nameAry['identifier_first_name']." ".$nameAry['identifier_last_name'];
		}
		return "";
	}

	public function getNameAry() {
		if ($this->name) {
			return $this->name;
		}
		return array();
	}

	public function setRows($rows) {
		$this->rows = $rows;
		foreach ($rows as $row) {
			if (isset($row['record_id'])) {
				$this->recordId = $row['record_id'];
			}
			if (($row['redcap_repeat_instance'] == "") && ($row['redcap_repeat_instrument'] == "")) {
				$this->name = array();
				$nameFields = array("identifier_first_name", "identifier_last_name");
				foreach ($nameFields as $field) {
					$this->name[$field] = $row[$field];
				}
			}
		}
		if (!$this->grants) {
			$grants = new Grants($this->token, $this->server, $this->metadata);
			$grants->setRows($this->rows);
			$this->setGrants($grants);
		}
	}

	public function downloadAndSetup($recordId) {
		$rows = Download::records($this->token, $this->server, array($recordId));
		$this->setRows($rows);
	}

	public static function hasDemographics($rows) {
		$fields = self::getDemographicFields();
		$has = array();
		foreach ($fields as $field => $func) {
			$has[$field] = FALSE;
		}
		foreach ($fields as $field => $func) {
			foreach ($rows as $row) {
				if (isset($row[$field])) {
					$has[$field] = TRUE;
				}
			}
		}
		foreach ($has as $field => $b) {
			if (!$b) {
				return FALSE;
			}
		}
		return TRUE;
	}

	public function process() {
		if ((count($this->rows) == 1) && self::hasDemographics($this->rows) && ($this->rows[0]['redcap_repeat_instrument'] == "")) {
			$this->loadDemographics();
		} else {
			$this->processDemographics();
		}
		if (!isset($this->grants)) {
			$this->initGrants();
		}
		$this->getMetaVariables();
	}

	private function setupTests() {
		$records = Download::recordIds($this->token, $this->server);
		$n = rand(0, count($records) - 1);
		$record = $records[$n];
		$this->downloadAndSetup($record);
	}

	public function makeUploadRow() {
		$metadataFields = REDCapManagement::getFieldsFromMetadata($this->metadata);
		$uploadRow = array(
					"record_id" => $this->recordId,
					"redcap_repeat_instrument" => "",
					"redcap_repeat_instance" => "",
					"summary_last_calculated" => date("Y-m-d H:i"),
					);
		foreach ($this->name as $var => $value) {
			if (in_array($var, $metadataFields)) {
				$uploadRow[$var] = $value;
			}
		}
		foreach ($this->demographics as $var => $value) {
			if (in_array($var, $metadataFields)) {
				$uploadRow[$var] = $value;
			}
		}
		foreach ($this->metaVariables as $var => $value) {
			if (in_array($var, $metadataFields)) {
				$uploadRow[$var] = $value;
			}
		}

		$grantUpload = $this->grants->makeUploadRow();
		foreach ($grantUpload as $var => $value) {
			if (!isset($uploadRow[$var]) && in_array($var, $metadataFields)) {
				$uploadRow[$var] = $value;
			}
		}

		$uploadRow['summary_complete'] = '2';

		return $uploadRow;
	}

	public function all_functions_test($tester) {
		$this->setupTests();
		$tester->tag("Record ".$this->recordId);
		$tester->assertNotNull($this->recordId);

		$tester->tag("Record ".$this->recordId.": token A");
		$tester->assertNotNull($this->token);
		$tester->tag("Record ".$this->recordId.": token B");
		$tester->assertNotBlank($this->token);

		$tester->tag("Record ".$this->recordId.": server A");
		$tester->assertNotNull($this->server);
		$tester->tag("Record ".$this->recordId.": server B");
		$tester->assertNotBlank($this->server);

		$this->process();
		$tester->tag("demographics filled");
		$tester->assertNotEqual(0, count($this->demographics));
		$tester->tag("meta variables filled");
		$tester->assertNotEqual(0, count($this->metaVariables));
		$tester->tag("name filled");
		$tester->assertNotEqual(0, count($this->name));

		$uploadRow = $this->makeUploadRow();
		$grantUpload = $this->grants->makeUploadRow();
		$tester->tag("Record ".$this->recordId.": Number of components");
		$tester->assertEqual(count($uploadRow), count($this->name) + count($this->demographics) + count($this->metaVariables) + (count($grantUpload) - 3) + 4);
		$metadata = Download::metadata($this->token, $this->server);
		$indexedMetadata = array();
		foreach ($metadata as $row) {
			$indexedMetadata[$row['field_name']] = $row;
		}

		$skip = array("record_id", "redcap_repeat_instance", "redcap_repeat_instrument");
		foreach ($uploadRow as $var => $value) {
			if (!in_array($var, $skip)) {
				$tester->tag("$var is present in upload row; is it present in metadata?");
				$tester->assertNotNull($metadata[$var]);
			}
		}
	}

	public function upload() {
		$uploadRow = $this->makeUploadRow();
		return Upload::oneRow($uploadRow, $this->token, $this->server);
	}

	private function isLastKExternal() {
		if (isset($this->metaVariables['summary_last_any_k'])
			&& isset($this->metaVariables['summary_last_external_k'])
			&& $this->metaVariables['summary_last_external_k']
			&& $this->metaVariables['summary_last_any_k']) {
			if ($this->metaVariables['summary_last_any_k'] == $this->metaVariables['summary_last_external_k']) {
				return TRUE;
			}
		}
		return FALSE;
	}

	private function isLastKK12KL2() {
		$k12kl2Type = 2;
		if ($this->isLastKExternal()) {
			return FALSE;
		} else {
			$grantAry = $this->grants->getGrants("native");
			if (empty($grantAry)) {
				$grantAry = $this->grants->getGrants("prior");
			}
			$lastKType = FALSE;
			foreach ($grantAry as $grant) {
				$type = $grant->getVariable("type");
				if (in_array($type, $ksInside)) {
					$lastKType = $type;
				}
			}
			return ($lastKType == $k12Kl2Type);
		}
	}

	private static function calculateKLengthInSeconds($type = "Internal") {
		if ($type == "Internal") {
			return Application::getInternalKLength() * 365 * 24 * 3600;
		} else if (($type == "K12KL2") || ($type == "K12/KL2")) {
			return Application::getK12KL2Length() * 365 * 24 * 3600;
		} else if ($type == "External") {
			return Application::getIndividualKLength() * 365 * 24 * 3600;
		}
		return 0;
	}

	private function hasK() {
		if ($this->grants) {
			$ks = array("Internal K", "K12/KL2", "Individual K", "External K");
			$grantAry = $this->grants->getGrants("native");
			if (empty($grantAry)) {
				$grantAry = $this->grants->getGrants("prior");
			}
			foreach ($grantAry as $grant) {
				if (in_array($grant->getVariable("type"), $ks)) {
					return TRUE;
				}
			}
		}
		return FALSE;
	}

	private function hasK99R00() {
		if ($this->grants) {
			foreach ($this->grants->getGrants("native") as $grant) {
				if ($grant->getVariable("type") == "K99/R00") {
					return TRUE;
				}
			}
		}
		return FALSE;
	}

	private function hasR01() {
		if ($this->grants) {
			$grantAry = $this->grants->getGrants("native");
			if (empty($grantAry)) {
				$grantAry = $this->grants->getGrants("prior");
			}
			foreach ($grantAry as $grant) {
				if ($grant->getVariable("type") == "R01") {
					return TRUE;
				}
			}
		}
		return FALSE;
	}

	private function hasR01OrEquiv() {
		if ($this->grants) {
			$grantAry = $this->grants->getGrants("native");
			if (empty($grantAry)) {
				$grantAry = $this->grants->getGrants("prior");
			}
			foreach ($grantAry as $grant) {
				if ($grant->getVariable("type") == "R01") {
					return TRUE;
				}
				if ($grant->getVariable("type") == "R01 Equivalent") {
					return TRUE;
				}
			}
		}
		return FALSE;
	}

	private function hasMetaVariables() {
		return (count($this->metaVariables) > 0) ? TRUE : FALSE;
	}

	public function isConverted($autoCalculate = TRUE) {
		if ($this->hasMetaVariables()) {
			if ($this->hasK99R00()) {
				return "Not Eligible";
			} else if ($this->hasK()) {
				if ($this->hasR01OrEquiv()) {
					$lastTime = strtotime($this->metaVariables['summary_last_any_k']);
					$rTime = strtotime($this->metaVariables['summary_first_r01_or_equiv']);
					if ($this->isLastKExternal()) {
						if ($rTime > $lastTime + self::calculateKLengthInSeconds("External")) {
							return "Converted while not on K";
						} else {
							return "Converted while on K";
						}
					} else {
						if ($this->isLastKK12KL2()) {
							$kLength = self::calculateKLengthInSeconds("K12/KL2");
						} else {
							$kLength = self::calculateKLengthInSeconds("Internal");
						}
						if ($rTime > $lastTime + $kLength) {
							return "Converted while not on K";
						} else {
							return "Converted while on K";
						}
					}
				} else {
					$lastTime = strtotime($this->metaVariables['summary_last_any_k']);
					if ($this->isLastKExternal()) {
						if (time() > $lastTime + self::calculateKLengthInSeconds("External")) {
							return "Not Converted";
						} else {
							return "Not Eligible";
						}
					} else {
						if ($this->isLastKK12KL2()) {
							$kLength = self::calculateKLengthInSeconds("K12/KL2");
						} else {
							$kLength = self::calculateKLengthInSeconds("Internal");
						}
						if (time() > $lastTime + $kLength) {
							return "Not Converted";
						} else {
							return "Not Eligible";
						} 
					}
				}
			} else {
				return "Not Eligible";
			}
		} else if ($autoCalculate) {
			$this->getMetaVariables();
			return $this->isConverted(FALSE);
		} else {
			if ($this->hasK()) {
				return "Not Converted";
			} else {
				return "Not Eligible";
			}
		}
	}

	private function getMetaVariables() {
		if ($this->grants) {
			$this->metaVariables = $this->grants->getSummaryVariables($this->rows);
		} else {
			$this->metaVariables = array();
		}
	}

	private function calculateCOEUSName($rows) {
		foreach ($rows as $row) {
			if ($row['redcap_repeat_instrument'] == "coeus") {
				new Result($row['coeus_person_name'], "", "", "", $this->pid);
			}
		}
		return new Result("", "");
	}

	private function getSurvey($rows) {
		foreach ($rows as $row) {
			if ($row['redcap_repeat_instrument'] == "scholars") {
				if ($row['check_name_first'] || $row['check_name_last']) {
					return new Result(1, "", "", "", $this->pid); // YES
				}
			}
		}
		return new Result(0, "", "", "", $this->pid); // NO
	}

	private static function getNormativeRow($rows) {
		foreach ($rows as $row) {
			if (($row['redcap_repeat_instrument'] == "") && ($row['redcap_repeat_instance'] == "")) {
				return $row;
			}
		}
		return array();
	}

	private static function getResultForPrefices($prefices, $row, $suffix, $pid = "") {
		foreach ($prefices as $prefix => $type) {
			$variable = $prefix."_institution";
			$variable_date = $prefix.$suffix;
			if (isset($row[$variable]) &&
				(preg_match("/".strtolower(Application::getInstitution())."/", strtolower($row[$variable])) || preg_match("/vumc/", strtolower($row[$variable]))) &&
				isset($row[$variable_date]) &&
				($row[$variable_date] != "")) {

				return new Result($row[$variable_date], $type, "", "", $pid);
			}
		}
		return new Result("", "");
	}


	# returns an array of (variableName, variableType) for to where (whither) they left current institution
	# returns blank if at current institution
	private function getAllOtherInstitutions($rows) {
		$followupInstitutionField = "followup_institution";
		$checkInstitutionField = "check_institution";
		$manualField = "imported_institution";
		$institutionCurrent = "1";

		$currentProjectInstitutions = Application::getInstitutions(); 
		for ($i = 0; $i < count($currentInstitutions); $i++) {
			$currentProjectInstitutions[$i] = trim(strtolower($currentProjectInstitutions[$i]));
		}
		$result = $this->getInstitution($rows);
		$value = strtolower($result->getValue());
		if (!in_array($value, $currentProjectInstitutions)) {
			return $result;
		}
		return new Result("", "");
	}

	# returns an array of (variableName, variableType) for when they left VUMC
	# used for the Scholars' survey and Follow-Up surveys
	private function getWhenLeftInstitution($rows) {
		$followupInstitutionField = "followup_institution";
		$checkInstitutionField = "check_institution";
		$institutionCurrent = '1';
		$suffix = "_academic_rank_enddt";
	
		$followupRows = self::selectFollowupRows($rows);
		foreach ($followupRows as $instance => $row) {
			$prefices = array(
						"followup_prev1" => "followup",
						"followup_prev2" => "followup",
						"followup_prev3" => "followup",
						"followup_prev4" => "followup",
						"followup_prev5" => "followup",
					);
			if ($row[$followupInstitutionField] != $institutionCurrent) {
				$res = self::getResultForPrefices($prefices, $row, $suffix, $this->pid);
				if ($res->getValue()) {
					return $res;
				}
			}
		}

		$normativeRow = self::getNormativeRow($rows);
		if ($normativeRow[$checkInstitutionField] != $institutionCurrent) {
			$prefices = array(
						"check_prev1" => "scholars",
						"check_prev2" => "scholars",
						"check_prev3" => "scholars",
						"check_prev4" => "scholars",
						"check_prev5" => "scholars",
					);
			$res = self::getResultForPrefices($prefices, $normativeRow, $suffix, $this->pid);
			if ($res->getValue()) {
				return $res;
			}
		}

		return new Result($normativeRow['identifier_left_date'], $normativeRow['identifier_left_date_source'], $normativeRow['identifier_left_date_sourcetype'], "", $this->pid);
	}

	# key = instance; value = REDCap data row
	private static function selectFollowupRows($rows) {
		foreach ($rows as $row) {
			if ($row['redcap_repeat_instrument'] == "followup") {
				$followupRows[$row['redcap_repeat_instance']] = $row;
			}
		}
		krsort($followupRows);	  // start with most recent survey
		return $followupRows;
	}

	# translates from innate ordering into new categories in summary_degrees
	private static function translateFirstDegree($num) {
		$translate = array(
				"" => "",
				1 => 1,
				2 => 4,
				6 => 6,
				7 => 3,
				8 => 3,
				9 => 3,
				10 => 2,
				11 => 2,
				12 => 3,
				13 => 3,
				14 => 6,
				15 => 3,
				16 => 6,
				17 => 6,
				18 => 6,
				3 => 6,
				4 => 6,
				5 => 6,
				);
		return $translate[$num];
	}



	# transforms a degree select box to something usable by other variables
	private static function transformSelectDegree($num) {
		if (!$num) {
			return "";
		}
		$transform = array(
				1 => 5,   # MS
				2 => 4,   # MSCI
				3 => 3,   # MPH
				4 => 6,   # other
				);
		return $transform[$num];
	}

	public static function getSourceChoices($metadata = array()) {
		$choices = self::getChoices($metadata);
		$exampleField = self::getExampleField();
		if (isset($choices[$exampleField])) {
			return $choices[$exampleField];
		}
		return array();
	}

	public function getOrder($defaultOrder, $fieldForOrder) {
		$sourceChoices = self::getSourceChoices($this->metadata);
		foreach ($this->metadata as $row) {
			if (($row['field_name'] == $fieldForOrder) && ($row['field_annotation'] != "")) {
				$newOrder = json_decode($row['field_annotation'], TRUE); 
				if ($newOrder) {
					$newVars = array();
					switch($fieldForOrder) {
						case "summary_degrees":
							foreach ($newOrder as $newField => $newSource) {
								if ($newField != $newSource) {
									# newly input
									$newVars[$newField] = $newSource;
								} else {
									# original
									foreach ($defaultOrder as $ary) {
										$newAry = array();
										foreach ($ary as $field => $source) {
											if (($sourceChoices[$source] == $newSource) || ($source == $newSource)) {
												$newAry[$field] = $newSource;
											}
										}
										if (!empty($newAry)) {
											array_push($newVars, $newAry);
										}
									}
								}
							}
							break;
						case "summary_race_ethnicity":
							# $type is in (race, ethnicity)
							$possibleTypes = array("race", "ethnicity");
							foreach ($newOrder as $type => $ary) {
								if (in_array($type, $possibleTypes)) {
									$newVars[$type] = array();
									foreach ($ary as $newField => $newSource) {
										if ($newField != $newSource) {
											$newVars[$type][$newField] = $newSource;
										} else {
											if ($defaultOrder[$type]) {
												foreach ($defaultOrder[$type] as $field => $source) {
													if (($sourceChoices[$source] == $newSource) || ($source == $newSource)) {
														$newVars[$type][$field] = $newSource;
													}
												}
											}
										}
									}
								} else {
									throw new \Exception("Encountered type '$type', which is not allowed in order");
								}
							}
							break;
						default:
							foreach ($newOrder as $newField => $newSource) {
								if ($newField != $newSource) {
									# newly input
									$newVars[$newField] = $newSource;
								} else {
									# original
									foreach ($defaultOrder as $field => $source) {
										if (($sourceChoices[$source] == $newSource) || ($source == $newSource)) {
											$newVars[$field] = $newSource;
										}
									}
								}
							}
							break;
					}
					return $newVars;
				}
			}
		}
		return $defaultOrder;
	}

	# to get all, make $field == "all"
	# add new fields here and getCalculatedFields
	public static function getDefaultOrder($field) {
		$orders = array();
		$orders["summary_degrees"] = array(
							array("override_degrees" => "manual"),
							array("followup_degree" => "followup"),
							array("check_degree1" => "scholars", "check_degree2" => "scholars", "check_degree3" => "scholars", "check_degree4" => "scholars", "check_degree5" => "scholars"),
							array("vfrs_graduate_degree" => "vfrs", "vfrs_degree2" => "vfrs", "vfrs_degree3" => "vfrs", "vfrs_degree4" => "vfrs", "vfrs_degree5" => "vfrs", "vfrs_please_select_your_degree" => "vfrs"),
							array("newman_new_degree1" => "new2017", "newman_new_degree2" => "new2017", "newman_new_degree3" => "new2017"),
							array("newman_data_degree1" => "data", "newman_data_degree2" => "data", "newman_data_degree3" => "data"),
							array("newman_demographics_degrees" => "demographics"),
							array("newman_sheet2_degree1" => "sheet2", "newman_sheet2_degree2" => "sheet2", "newman_sheet2_degree3" => "sheet2"),
							);
		$orders["identifier_orcid"] = array(
							"check_orcid_id" => "scholars",
							"followup_orcid_id" => "followup",
							);
		$orders["summary_primary_dept"] = array(
							"override_department1" => "manual",
							"promotion_department" => "manual",
							"check_primary_dept" => "scholars",
							"vfrs_department" => "vfrs",
							"newman_new_department" => "new2017",
							"newman_demographics_department1" => "demographics",
							"newman_data_department1" => "data",
							"newman_sheet2_department1" => "sheet2",
							);
		$orders["summary_gender"] = array(
							"override_gender" => "manual",
							"check_gender" => "scholars",
							"vfrs_gender" => "vfrs",
							"imported_gender" => "manual",
							"newman_new_gender" => "new2017",
							"newman_demographics_gender" => "demographics",
							"newman_data_gender" => "data",
							"newman_nonrespondents_gender" => "nonrespondents",
							);
		$orders["summary_race_ethnicity"] = array();
		$orders["summary_race_ethnicity"]["race"] = array(
									"override_race" => "manual",
									"check_race" => "scholars",
									"vfrs_race" => "vfrs",
									"imported_race" => "manual",
									"newman_new_race" => "new2017",
									"newman_demographics_race" => "demographics",
									"newman_data_race" => "data",
									"newman_nonrespondents_race" => "nonrespondents",
									);
		$orders["summary_race_ethnicity"]["ethnicity"] = array(
									"override_ethnicity" => "manual",
									"check_ethnicity" => "scholars",
									"vfrs_ethnicity" => "vfrs",
									"imported_ethnicity" => "manual",
									"newman_new_ethnicity" => "new2017",
									"newman_demographics_ethnicity" => "demographics",
									"newman_data_ethnicity" => "data",
									"newman_nonrespondents_ethnicity" => "nonrespondents",
									);
		$orders["summary_dob"] = array(
						"check_date_of_birth" => "scholars",
						"vfrs_date_of_birth" => "vfrs",
						"override_dob" => "manual",
						"imported_dob" => "manual",
						"newman_new_date_of_birth" => "new2017",
						"newman_demographics_date_of_birth" => "demographics",
						"newman_data_date_of_birth" => "data",
						"newman_nonrespondents_date_of_birth" => "nonrespondents",
						);
		$orders["summary_citizenship"] = array(
							"followup_citizenship" => "followup",
							"check_citizenship" => "scholars",
							"override_citizenship" => "manual",
							"imported_citizenship" => "manual",
							);
		$orders["identifier_institution"] = array(
								"identifier_institution" => "manual",
								"promotion_institution" => "manual",
								"imported_institution" => "manual",
								"check_institution" => "scholars",
								);
		$orders["identifier_left_job_title"] = array(
								"promotion_job_title" => "manual",
								"check_job_title" => "scholars",
								"followup_job_title" => "scholars",
								);
		$orders["identifier_left_job_category"] = array(
								"promotion_job_category" => "manual",
								"check_job_category" => "scholars",
								"followup_job_category" => "scholars",
		);
		$orders["identifier_left_department"] = array(
								"promotion_department" => "manual",
		);
		$orders["summary_current_division"] = array(
								"promotion_division" => "manual",
								"identifier_left_division" => "manual",
								"followup_division" => "followup",
								"check_division" => "scholars",
								"override_division" => "manual",
								"imported_division" => "manual",
								"identifier_starting_division" => "manual",
								"vfrs_division" => "vfrs",
								);
		$orders["summary_current_rank"] = array(
							"promotion_rank" => "manual",
							"override_rank" => "manual",
							"imported_rank" => "manual",
							"followup_academic_rank" => "followup",
							"check_academic_rank" => "scholars",
							"newman_new_rank" => "new2017",
							"newman_demographics_academic_rank" => "demographics",
							);
		$orders["summary_current_start"] = array(
							"promotion_in_effect" => "manual",
							"followup_academic_rank_dt" => "followup",
							"check_academic_rank_dt" => "scholars",
							"override_position_start" => "manual",
							"imported_position_start" => "manual",
							"vfrs_when_did_this_appointment" => "vfrs",
							);
		$orders["summary_current_tenure"] = array(
								"followup_tenure_status" => "followup",
								"check_tenure_status" => "scholars",
								"override_tenure" => "manual",
								"imported_tenure" => "manual",
								);
		$orders["summary_mentor"] = array(
							"override_mentor" => "manual",
							"imported_mentor" => "manual",
							"followup_primary_mentor" => "followup",
							"check_primary_mentor" => "scholars",
							);
		$orders["summary_disability"] = array(
							"check_disability" => "scholars",
							"vfrs_disability_those_with_phys" => "vfrs",
							);
		$orders["summary_disadvantaged"] = array(
							"check_disadvantaged" => "scholars",
							"vfrs_disadvantaged_the_criteria" => "vfrs",
							);
		$orders["summary_training_start"] = array(
								"check_degree0_start" => "scholars",
								"promotion_in_effect" => "manual",
								);
		$orders["summary_training_end"] = array(
								"check_degree0_month/check_degree0_year" => "scholars",
								"promotion_in_effect" => "manual",
								);

		if (isset($orders[$field])) {
			return $orders[$field];
		} else if ($field == "all") {
			return $orders;
		}
		return array();
	}

	private function getDegrees($rows) {
		# move over and then down
		$order = self::getDefaultOrder("summary_degrees");
		$order = $this->getOrder($order, "summary_degrees");

		$normativeRow = self::getNormativeRow($rows);
		$followupRows = self::selectFollowupRows($rows);

		# combines degrees
		$value = "";
		$degrees = array();
		foreach ($order as $variables) {
			foreach ($variables as $variable => $source) {
				if ($variable == "vfrs_please_select_your_degree") {
					$normativeRow[$variable] = self::transformSelectDegree($normativeRow[$variable]);
				}
				if ($source == "followup") {
					foreach ($followupRows as $row) {
						if ($row[$variable] && !in_array($row[$variable], $degrees)) {
							$degrees[] = $row[$variable];
						}
					}
				}
				if ($normativeRow[$variable] && !in_array($normativeRow[$variable], $degrees)) {
					$degrees[] = $normativeRow[$variable];
				}
			}
		}
		if (empty($degrees)) {
			return new Result("", "");
		} else if (in_array(1, $degrees) || in_array(9, $degrees) || in_array(10, $degrees) || in_array(7, $degrees) || in_array(8, $degrees) || in_array(14, $degrees) || in_array(12, $degrees)) { # MD
			if (in_array(2, $degrees) || in_array(9, $degrees) || in_array(10, $degrees)) {
				$value = 10;  # MD/PhD
			} else if (in_array(3, $degrees) || in_array(16, $degrees) || in_array(18, $degrees)) { # MPH
				$value = 7;
			} else if (in_array(4, $degrees) || in_array(7, $degrees)) { # MSCI
				$value = 8;
			} else if (in_array(5, $degrees) || in_array(8, $degrees)) { # MS
				$value = 9;
			} else if (in_array(6, $degrees) || in_array(13, $degrees) || in_array(14, $degrees)) { # Other
				$value = 7;     # MD + other
			} else if (in_array(11, $degrees) || in_array(12, $degrees)) { # MHS
				$value = 12;
			} else {
				$value = 1;   # MD only
			}
		} else if (in_array(2, $degrees)) { # PhD
			if (in_array(11, $degrees)) {
				$value = 10;  # MD/PhD
			} else if (in_array(3, $degrees)) { # MPH
				$value = 2;
			} else if (in_array(4, $degrees)) { # MSCI
				$value = 2;
			} else if (in_array(5, $degrees)) { # MS
				$value = 2;
			} else if (in_array(6, $degrees)) { # Other
				$value = 2;
			} else {
				$value = 2;     # PhD only
			}
		} else if (in_array(6, $degrees)) {  # Other
			if (in_array(1, $degrees)) {   # MD
				$value = 7;  # MD + other
			} else if (in_array(2, $degrees)) {  # PhD
				$value = 2;
			} else {
				$value = 6;
			}
		} else if (in_array(3, $degrees)) {  # MPH
			$value = 6;
		} else if (in_array(4, $degrees)) {  # MSCI
			$value = 6;
		} else if (in_array(5, $degrees)) {  # MS
			$value = 6;
		} else if (in_array(15, $degrees)) {  # PsyD
			$value = 6;
		}

		$newValue = self::translateFirstDegree($value);
		$newSource = "";
		return new Result($newValue, $newSource, "", "", $this->pid);
	}

	private function getPrimaryDepartment($rows) {
		$vars = self::getDefaultOrder("summary_primary_dept");
		$vars = $this->getOrder($vars, "summary_primary_dept");
		$result = self::searchRowsForVars($rows, $vars, FALSE, $this->pid);
		$value = $result->getValue();
		if ($result->getSource() == "vfrs") {
			$value = self::transferVFRSDepartment($value);
		}

		return new Result($value, $result->getSource(), "", "", $this->pid);
	}

	# VFRS did not use the 6-digit classification, so we must translate
	private static function transferVFRSDepartment($dept) {
		$exchange = array(
					1       => 104300,
					2       => 104250,
					3       => 104785,
					4       => 104268,
					5       => 104286,
					6       => 104705,
					7       => 104280,
					8       => 104791,
					9       => 999999,
					10      => 104782,
					11      => 104368,
					12      => 104270,
					13      => 104400,
					14      => 104705,
					15      => 104425,
					16      => 104450,
					17      => 104366,
					18      => 104475,
					19      => 104781,
					20      => 104500,
					21      => 104709,
					22      => 104595,
					23      => 104290,
					24      => 104705,
					25      => 104625,
					26      => 104529,
					27      => 104675,
					28      => 104650,
					29      => 104705,
					30      => 104726,
					31      => 104775,
					32      => 999999,
					33      => 106052,
					34      => 104400,
					35      => 104353,
					36      => 120430,
					37      => 122450,
					38      => 120660,
					39      => 999999,
					40      => 104705,
					41      => 104366,
					42      => 104625,
					43      => 999999,
				);
		if (isset($exchange[$dept])) {
			return $exchange[$dept];
		}
		return "";
	}

	public function getGender($rows) {
		$vars = self::getDefaultOrder("summary_gender");
		$vars = $this->getOrder($vars, "summary_gender");
		$result = self::searchRowsForVars($rows, $vars, FALSE, $this->pid);

		# must reverse for certain sources
		$tradOrder = array("manual", "scholars", "followup");
		if ($result->getValue()) {
			if (in_array($result->getSource(), $tradOrder)) {
				return $result;
			}
			$source = $result->getSource();
			$value = $result->getValue();
			if ($value == 1) {  # Male
				return new Result(2, $source, "", "", $this->pid);
			} else if ($value == 2) {   # Female
				return new Result(1, $source, "", "", $this->pid);
			}
			# forget no-reports and others
		}
		return new Result("", "");
	}

	# returns array of 3 (overall classification, race source, ethnicity source)
	public function getRaceEthnicity($rows) {
		$order = self::getDefaultOrder("summary_race_ethnicity");
		$order = $this->getOrder($order, "summary_race_ethnicity");
		$normativeRow = self::getNormativeRow($rows);

		$race = "";
		$raceSource = "";
		foreach ($order["race"] as $variable => $source) {
			if (isset($normativeRow[$variable]) && ($normativeRow[$variable] !== "") && ($normativeRow[$variable] != 8)) {
				$race = $normativeRow[$variable];
				$raceSource = $source;
				break;
			}
		}
		if ($race === "") {
			return new RaceEthnicityResult("", "", "");
		}
		$eth = "";
		$ethSource = "";
		foreach ($order["ethnicity"] as $variable => $source) {
			if (isset($normativeRow[$variable]) && ($normativeRow[$variable] !== "") && ($normativeRow[$variable] != 4)) {
				$eth = $normativeRow[$variable];
				$ethSource = $source;
				break;
			}
		}
		$val = "";
		if ($race == 2) {   # Asian
			$val = 5;
			return new RaceEthnicityResult($val, $raceSource, "", $this->pid);
		} else if ($race == 1) {    # American Indian or Native Alaskan
			$val = 9;
			return new RaceEthnicityResult($val, $raceSource, "", $this->pid);
		} else if ($race == 3) {    # Hawaiian or Other Pacific Islander
			$val = 10;
			return new RaceEthnicityResult($val, $raceSource, "", $this->pid);
		}
		if ($eth == "") {
			$choices = REDCapManagement::getChoices($this->metadata);
			if ($race == 5) { # White
				$val = 7;
			} else if ($race == 4) { # Black
				$val = 8;
			}
			if ($val) {
				if (!isset($choices["summary_race_ethnicity"][$val])) {
					if ($val == 7) {
						$val = 1;   // white, non-Hisp
					} else if ($val == 8) {
						$val = 2;   // black, non-Hisp
					}
				}
				return new RaceEthnicityResult($val, $raceSource, "", $this->pid);
			}
		}
		if ($eth == 1) { # Hispanic
			if ($race == 5) { # White
				$val = 3;
			} else if ($race == 4) { # Black
				$val = 4;
			}
		} else if ($eth == 2) {  # non-Hisp
			if ($race == 5) { # White
				$val = 1;
			} else if ($race == 4) { # Black
				$val = 2;
			}
		}
		if ($val === "") {
			$val = 6;  # other
		}
		return new RaceEthnicityResult($val, $raceSource, $ethSource, $this->pid);
	}

	# convert date
	private static function convertToYYYYMMDD($date) {
		$nodes = preg_split("/[\-\/]/", $date);
		if (($nodes[0] == 0) || ($nodes[1] == 0)) {
			return "";
		}
		if ($nodes[0] > 1900) {
			return $nodes[0]."-".$nodes[1]."-".$nodes[2];
		}
		if ($nodes[2] < 1900) {
			if ($nodes[2] < 20) {
				$nodes[2] = 2000 + $nodes[2];
			} else {
				$nodes[2] = 1900 + $nodes[2];
			}
		}
		// from MDY
		return $nodes[2]."-".$nodes[0]."-".$nodes[1];
	}

	# finds date-of-birth
	public function getDOB($rows) {
		$vars = self::getDefaultOrder("summary_dob");
		$vars = $this->getOrder($vars, "summary_dob");
		$result = self::searchRowsForVars($rows, $vars, FALSE, $this->pid);
		$date = $result->getValue();
		if ($date) {
			$date = self::convertToYYYYMMDD($date);
		}

		return new Result($date, $result->getSource(), "", "", $this->pid);
	}

	public function getCitizenship($rows) {
		$vars = self::getDefaultOrder("summary_citizenship");
		$vars = $this->getOrder($vars, "summary_citizenship");
		$result = self::searchRowsForVars($rows, $vars, FALSE, $this->pid);
		if ($result->getValue() == "") {
			$selectChoices = array(
						"vfrs_citizenship" => "vfrs",
						);
			foreach ($selectChoices as $field => $fieldSource) {
				if ($fieldSource == "vfrs") {
					foreach ($rows as $row) {
						if (isset($row[$field]) && ($row[$field])) {
							$fieldValue = trim(strtolower($row[$field]));
							if ($fieldValue == "1") {
								# U.S. citizen, source unknown
								return new Result('5', $fieldSource, "", "", $this->pid);
							} else if ($fieldValue) {
								# Non U.S. citizen, status unknown
								return new Result('6', $fieldSource, "", "", $this->pid);
							}
						}
					}
				}
			}

			$usValues = array("us", "u.s.", "united states", "usa", "u.s.a.");    // lower case
			$textSources = array(
						"newman_demographics_citizenship" => "demographics",
						);
			foreach ($textSources as $field => $fieldSource) {
				if ($fieldSource == "demographics") {
					foreach ($rows as $row) {
						if (isset($row[$field]) && ($row[$field])) {
							$fieldValue = trim(strtolower($row[$field]));
							if (in_array($fieldValue, $usValues)) {
								# U.S. citizen, source unknown
								return new Result('5', $fieldSource, "", "", $this->pid);
							} else if ($fieldValue) {
								# Non U.S. citizen, status unknown
								return new Result('6', $fieldSource, "", "", $this->pid);
							}
						}
					}
				}
			}
		}
		return $result;
	}

	private static function getDateFieldForSource($source, $field) {
		switch($source) {
			case "followup":
				return "followup_date";
			case "manual":
				if (preg_match("/^promotion_/", $field)) {
					return "promotion_date";
				} else if (preg_match("/^override_/", $field)) {
					return $field."_time";
				}
				return "";
			case "new_2017":
				return "2017-10-01";
			case "scholars":
				return "check_date";
		}
		return "";
	}

	# $vars is listed in order of priority; key = variable, value = data source
	private static function searchRowsForVars($rows, $vars, $byLatest = FALSE, $pid = "") {
		$result = new Result("", "");
		foreach ($vars as $var => $source) {
			$ary = array("", "");
			$aryInstance = "";
			$latestTs = "";
			$splitVar = explode("/", $var);
			foreach ($rows as $row) {
				if ($row[$var] || ((count($splitVar) > 1) && $row[$splitVar[0]] && $row[$splitVar[1]])) {
					$date = "";
					if (count($splitVar) > 1) {
						# YYYY-mm-dd
						$varValues = array();
						foreach ($splitVar as $v) {
							array_push($varValues, $row[$v]);
						}
						if (count($varValues) == 3) {
							$date = implode("-", $varValues);
						} else if (count($varValues) == 2) {
							# YYYY-mm
							$startDay = "01";
							$date = implode("-", $varValues)."-".$startDay;
						} else {
							throw new \Exception("Cannot interpret split variables: ".json_encode($varValues));
						}
					} else {
						$dateField = self::getDateFieldForSource($source, $var);
						if ($dateField && $row[$dateField]) {
							$date = $row[$dateField];
						} else if ($dateField && ($dateField != "check_date")) {
							$date = $dateField;
						}
					}
					if ($byLatest) {
						# order by date
						if ($date) {
							$currTs = strtotime($date);
							if ($currTs > $latestTs) {
								$latestTs = $currTs;
								$result = new Result(self::transformIfDate($row[$var]), $source, "", $date, $pid);
								$result->setField($var);
								$result->setInstance($row['redcap_repeat_instance']);
							}
						} else if (!$latestTs) {
							$result = new Result(self::transformIfDate($row[$var]), $source, "", "", $pid);
							$result->setField($var);
							$result->setInstance($row['redcap_repeat_instance']);
							$latestTs = 1; // nominally low value
						}
					} else {
						if ($row['redcap_repeat_instrument'] == $source) {
							# get earliest instance - i.e., lowest repeat_instance
							if (!$aryInstance
								|| ($aryInstance > $row['redcap_repeat_instance'])) {
								$result = new Result(self::transformIfDate($row[$var]), $source, "", $date, $pid);
								$result->setField($var);
								$result->setInstance($row['redcap_repeat_instance']);
								$aryInstance = $row['redcap_repeat_instance'];
							}
						} else {
							$result = new Result(self::transformIfDate($row[$var]), $source, "", $date, $pid);
							$result->setField($var);
							return $result;
						}
					}
				}
			}
			if ($aryInstance) {
				return $result;
			}
		}
		if ($byLatest) {
			return $result;
		}
		return new Result("", "");
	}

	private static function transformIfDate($value) {
		if (preg_match("/^(\d+)[\/\-](\d\d\d\d)$/", $value, $matches)) {
			# MM/YYYY
			$month = $matches[1];
			$year = $matches[2];
			$day = "01";
			return $year."-".$month."-".$day;
			
		} else if (preg_match("/^\d+[\/\-]\d+[\/\-]\d\d\d\d$/", $value)) {
			# MM/DD/YYYY
			return self::convertToYYYYMMDD($value);
		}
		return $value;
	}

	public function getInstitutionText() {
		$result = $this->getInstitution($this->rows);
		return $result->getValue();
	}

	private function getInstitution($rows) {
		$vars = self::getDefaultOrder("identifier_institution");
		$vars = $this->getOrder($vars, "identifier_institution");
		$result = self::searchRowsForVars($rows, $vars, FALSE, $this->pid);
		$value = $result->getValue();
		$source = $result->getSource();

		if (is_numeric($value)) {
			$choices = self::getChoices($this->metadata);
			$fieldName = $result->getField();
			if (isset($choices[$fieldName]) && isset($choices[$fieldName][$value])) {
				$newValue = $choices[$fieldName][$value];
				if ($newValue == "Other") {
					foreach ($rows as $row) {
						if ($row[$fieldName."_oth"]) {
							$newValue = $row[$fieldName."_oth"];
							break;
						} else if ($row[$fieldName."_other"]) {
							$newValue = $row[$fieldName."_other"];
							break;
						}
					}
				}
				$result->setValue($newValue);
			}
			return $result;
		} else if (($value == "") || ($value == Application::getUnknown())) {
			return new Result("", ""); 
		} else {
			# typical case
			return $result;
		}
	}

	private function getCurrentDivision($rows) {
		$vars = self::getDefaultOrder("summary_current_division");
		$vars = $this->getOrder($vars, "summary_current_division");
		$result = self::searchRowsForVars($rows, $vars, FALSE, $this->pid);
		if ($result->getValue() == "N/A") {
			return new Result("", "");
		}
		if ($result->getValue() == "") {
			$deptName = $this->getPrimaryDepartmentText();
			$nodes = preg_split("/\//", $deptName);
			if (count($nodes) == 2) {
				$deptResult = $this->getPrimaryDepartment($rows);
				return new Result($nodes[1], $deptResult->getSource(), "", "", $this->pid);
			}
		}
		return $result;
	}

	private function getCurrentRank($rows) {
		$vars = self::getDefaultOrder("summary_current_rank");
		$vars = $this->getOrder($vars, "summary_current_rank");
		$result = self::searchRowsForVars($rows, $vars, TRUE, $this->pid);
		if (!$result->getValue()) {
			$otherFields = array(
						"vfrs_current_appointment" => "vfrs",
						);
			foreach ($otherFields as $field => $fieldSource) {
				if ($fieldSource == "vfrs") {
					foreach ($rows as $row) {
						if (isset($row[$field]) && ($row[$field] != "")) {
							# VFRS: 1, Research Instructor|2, Research Assistant Professor|3, Instructor|4, Assistant Professor|5, Other
							# Summary: 1, Research Fellow | 2, Clinical Fellow | 3, Instructor | 4, Research Assistant Professor | 5, Assistant Professor | 6, Associate Professor | 7, Professor | 8, Other
							switch($row[$field]) {
								case 1:
									$val = 3;
									break;
								case 2:
									$val = 4;
									break;
								case 3:
									$val = 3;
									break;
								case 4:
									$val = 5;
									break;
								case 5:
									$val = 8;
									break;
								default:
									$val = "";
									break;
							}
							if ($val) {
								return new Result($val, $fieldSource, "", "", $this->pid);
							}
						}
					}
				}
			}
		}
		return $result;
	}

	private function getCurrentAppointmentStart($rows) {
		$vars = self::getDefaultOrder("summary_current_start");
		$vars = $this->getOrder($vars, "summary_current_start");
		return self::searchRowsForVars($rows, $vars, FALSE, $this->pid);
	}

	private function getTenureStatus($rows) {
		$vars = self::getDefaultOrder("summary_current_tenure");
		$vars = $this->getOrder($vars, "summary_current_tenure");
		$result = self::searchRowsForVars($rows, $vars, FALSE, $this->pid);
		if ($result->getValue() == "") {
			$otherFields = array(
						"vfrs_tenure" => "vfrs",
						);
			foreach ($otherFields as $field => $fieldSource) {
				foreach ($rows as $row) {
					if (isset($row[$field]) && ($row[$field] != "")) {
						return new Result($row[$field], $fieldSource, "", "", $this->pid);
					}
				}
			} 
		}
		return $result;
	}

	private static function isNormativeRow($row) {
		return (($row['redcap_repeat_instrument'] == "") && ($row['redcap_repeat_instance'] == ""));
	}

	private function loadDemographics() {
		$this->demographics = array();
		$fields = self::getDemographicFields();
		$rows = $this->rows;

		foreach ($rows as $row) {
			if (self::isNormativeRow($row)) {
				foreach ($fields as $field => $func) {
					if (isset($row[$field])) {
						$this->demographics[$field] = $row[$field];
					} else {
						$this->demographics[$field] = "";
					}
				}
			}
		}
	}

	private function getJobCategory($rows) {
		return $this->searchForJobMove("identifier_left_job_category", $rows);
	}

	private function getNewDepartment($rows) {
		return $this->searchForJobMove("identifier_left_department", $rows);
	}

	private function getJobTitle($rows) {
		return $this->searchForJobMove("identifier_left_job_title", $rows);
	}

	private function searchForJobMove($field, $rows) {
		$institutionResult = $this->getInstitution($rows);
		$value = $institutionResult->getValue();
		$vars = self::getDefaultOrder($field);
		if ($value) {
			return $this->matchWithInstitutionResult($institutionResult, $rows);
		} else {
			# no institution information
			$result = self::searchRowsForVars($rows, $vars, TRUE, $this->pid);
			return $result;
		}
	}

	private function matchWithInstitutionResult($institutionResult, $rows) {
		$fieldName = $institutionResult->getField();
		$instance = $institutionResult->getInstance();
		$source = $institutionResult->getSource();
		if (!$instance) {
			$instances = array("", "1");
		} else {
			$instances = array($instance);
		}
		foreach ($rows as $row) {
			$currInstance = ($row['redcap_repeat_instance'] ? $row['redcap_repeat_instance'] : "");
			if (($row[$fieldName] == $value) && in_array($currInstance, $instances)) {
				foreach ($vars as $origField => $origSource) {
					if (($source == $origSource) && $row[$origField]) {
						$result = new Result($row[$origField], $source, "", "", $this->pid);
						$result->setField($origField);
						$result->setInstance($currInstance);
						return $result;
					}
				}
			}
		}
		return new Result("", "");
	}

	public static function getDemographicsArray() {
		return $this->demographics;
	}

	private static function getDemographicFields() {
		return self::getCalculatedFields();
	}

	# add new fields here and getDefaultOrder
	private static function getCalculatedFields() {
		return array(
				"summary_coeus_name" => "calculateCOEUSName",
				"summary_survey" => "getSurvey",
				"identifier_left_date" => "getWhenLeftInstitution",
				"identifier_institution" => "getAllOtherInstitutions",
				"identifier_left_job_title" => "getJobTitle",
				"identifier_left_job_category" => "getJobCategory",
				"identifier_left_department" => "getNewDepartment",
				"identifier_orcid" => "getORCIDResult",
				"summary_degrees" => "getDegrees",
				"summary_primary_dept" => "getPrimaryDepartment",
				"summary_gender" => "getGender",
				"summary_race_ethnicity" => "getRaceEthnicity",
				"summary_dob" => "getDOB",
				"summary_citizenship" => "getCitizenship",
				"summary_current_institution" => "getInstitution",
				"summary_current_division" => "getCurrentDivision",
				"identifier_left_division" => "getCurrentDivision",    // deliberate duplicate
				"summary_current_rank" => "getCurrentRank",
				"summary_current_start" => "getCurrentAppointmentStart",
				"summary_current_tenure" => "getTenureStatus",
				"summary_urm" => "getURMStatus",
				"summary_disability" => "getDisabilityStatus",
				"summary_disadvantaged" => "getDisadvantagedStatus",
				"summary_training_start" => "getTrainingStart",
				"summary_training_end" => "getTrainingEnd",
				);
	}

	private function getTrainingStart($rows) {
		$vars = self::getDefaultOrder("summary_training_start");
		$result = self::searchRowsForVars($rows, $vars, FALSE, $this->pid);
		$fieldName = $result->getField();
		if (preg_match("/^promotion_/", $fieldName)) {
			$positionChanges = self::getOrderedPromotionRows($rows);
			$trainingRanks = array(9, 10);
			foreach ($positionChanges as $startTs => $row) {
				if ($row['promotion_rank'] && in_array($row['promotion_rank'], $trainingRanks) && $row['promotion_in_effect']) {
					return new Result($row['promotion_in_effect'], "manual", "", "", $this->pid);
				}
			}
			return new Result("", "");   // undecipherable
		}
		return $result;
	}

	private static function getOrderedPromotionRows($rows) {
		$changes = array();
		$startField = "promotion_in_effect";
		foreach ($rows as $row) {
			if (($row['redcap_repeat_instrument'] == "position_change") && $row[$startField]) {
				$changes[strtotime($row[$startField])] = $row;
			}
		}

		krsort($changes);    // get most recent
		return $changes;
	}

	private function getTrainingEnd($rows) {
		$vars = self::getDefaultOrder("summary_training_end");
		$result = self::searchRowsForVars($rows, $vars, FALSE, $this->pid);
		$fieldName = $result->getField();
		if (preg_match("/^promotion_/", $fieldName)) {
			$positionChanges = self::getOrderedPromotionRows($rows);
			$trainingRanks = array(9, 10);
			$trainingStart = FALSE;
			foreach ($positionChanges as $startTs => $row) {
				if ($row['promotion_rank'] && in_array($row['promotion_rank'], $trainingRanks) && $row['promotion_in_effect']) {
					$trainingStart = $startTs;
				}
			}
			if ($trainingStart) {
				$nextStart = "";
				foreach ($positionChanges as $startTs => $row) {
					if ($startTs == $trainingStart) {
						if ($nextStart) {
							return new Result($nextStart, "manual", "", "", $this->pid);
						}
					}
					$nextStart = $row['promotion_in_effect'];
				}
			}
			return new Result("", "");   // undecipherable
		}
		return $result;
	}

	private function getURMStatus($rows) {
		$raceEthnicityValue = $this->getRaceEthnicity($rows)->getValue();
		$disadvValue = $this->getDisadvantagedStatus($rows)->getValue();
		$disabilityValue = $this->getDisabilityStatus($rows)->getValue();

		$minorities = array(2, 3, 4, 5, 6, 8, 9, 10);
		$value = "0";
		if (($raceEthnicityValue === "") && ($disadvValue === "") && ($disabilityValue === "")) {
			$value = "";
		}
		if (in_array($raceEthnicityValue, $minorities)) {
			$value = "1";
		}
		if ($disadvValue == "1") {
			$value = "1";
		}
		if ($disabilityValue == "1") {
			$value = "1";
		}
		return new Result($value, "", "", "", $this->pid);
	}

	private function getDisadvantagedStatus($rows) {
		$vars = self::getDefaultOrder("summary_disadvantaged");
		$result = self::searchRowsForVars($rows, $vars, TRUE, $this->pid);
		if ($result->getValue() == 1) {
			# Yes
			$value = "1";
		} else if ($result->getValue() == 2) {
			# No
			$value = "0";
		} else {
			$value = "";
		}
		$result->setValue($value);
		return $result;
	}

	private function getDisabilityStatus($rows) {
		$vars = self::getDefaultOrder("summary_disability");
		$result = self::searchRowsForVars($rows, $vars, TRUE, $this->pid);
		if ($result->getValue() == 1) {
			# Yes
			$value = "1";
		} else if ($result->getValue() == 2) {
			# No
			$value = "0";
		} else {
			$value = "";
		}
		$result->setValue($value);
		return $result;
	}

	private function processDemographics() {
		$this->demographics = array();
		$fields = self::getDemographicFields();
		$rows = $this->rows;

		$metadataFields = REDCapManagement::getFieldsFromMetadata($this->metadata);

		$specialCases = array("summary_degrees", "summary_coeus_name", "summary_survey", "summary_race_ethnicity");
		foreach ($fields as $field => $func) {
			if (in_array($field, $metadataFields)) {
				if (in_array($field, $specialCases)) {
					# special cases
					if (($field == "summary_degrees") || ($field == "summary_survey") || ($field == "summary_coeus_name")) {
						$result = $this->$func($rows);
						$this->demographics[$field] = $result->getValue();
					} else if ($field == "summary_race_ethnicity") {
						$result = $this->$func($rows);
	
						$this->demographics[$field] = $result->getValue();
						$this->demographics["summary_race_source"] = $result->getRaceSource();
						$this->demographics["summary_race_sourcetype"] = $result->getRaceSourceType();
						$this->demographics["summary_ethnicity_source"] = $result->getEthnicitySource();
						$this->demographics["summary_ethnicity_sourcetype"] = $result->getEthnicitySourceType();
					}
				} else {
					$result = $this->$func($rows);
	
					$this->demographics[$field] = $result->getValue();
					$this->demographics[$field."_source"] = $result->getSource();
					$this->demographics[$field."_sourcetype"] = $result->getSourceType();
				}
			}
			# no else because they probably have not updated their metadata
		}
	}

	public function getDemographic($demo) {
		if (!preg_match("/^summary_/", $demo)) {
			$demo = "summary_".$demo;
		}
		$choices = self::getChoices($this->metadata);
		if (isset($this->demographics[$demo])) {
			if (isset($choices[$demo]) && isset($this->demographics[$demo])) {
				return $choices[$demo][$this->demographics[$demo]];
			} else {
				return $this->demographics[$demo];
			}
		}
		return "";
	}

	private function initGrants() {
		$grants = new Grants($this->token, $this->server);
		if (iset($this->rows)) {
			$grants->setRows($this->rows);
			$grants->compileGrants();
			$this->grants = $grants;
		}
	}

	public static function getExampleField() {
		return "identifier_left_date_source";
	}

	private $token;
	private $server;
	private $metadata;
	private $grants;
	private $rows;
	private $recordId;
	private $name = array();
	private $demographics = array();    // key for demographics is REDCap field name; value is REDCap value
	private $metaVariables = array();   // copied from the Grants class
	private static $choices;
}

class Result {
	public function __construct($value, $source, $sourceType = "", $date = "", $pid = "") {
		$this->value = $value;
		$this->source = self::translateSourceIfNeeded($source);
		$this->sourceType = $sourceType;
		$this->date = $date;
		$this->pid = $pid;
		$this->field = "";
		$this->instance = "";
	}

	public function setInstance($instance) {
		$this->instance = $instance;
	}

	public function getInstance() {
		return $this->instance;
	}

	public function setField($field) {
		$this->field = $field;
	}

	public function getField() {
		return $this->field;
	}

	public function setValue($val) {
		$this->value = $val;
	}

	public function getValue() {
		return $this->value;
	}

	public function getSource() {
		return $this->source;
	}

	public function getSourceType() {
		if (!$this->sourceType) {
			$this->sourceType = self::calculateSourceType($this->source, $this->pid);
		}
		return $this->sourceType;
	}

	public function getDate() {
		return $this->date;
	}

	# returns index from source's choice array
	protected static function translateSourceIfNeeded($source) {
		$sourceChoices = Scholar::getSourceChoices();
		foreach ($sourceChoices as $index => $label) {
			if (($label == $source) || ($index == $source)) {
				return $index;
			}
		}
		return "";
	}

	public static function calculateSourceType($source, $pid = "") {
		$selfReported = array("scholars", "followup", "vfrs");
		$newman = array( "data", "sheet2", "demographics", "new2017", "k12", "nonrespondents", "manual" );

		if ($source == "") {
			$sourcetype = "";
		} else if (in_array($source, $selfReported) || in_array($source, Scholar::getAdditionalSourceTypes(Application::getModule(), "1", $pid))) {
			$sourcetype = "1";
		} else if (in_array($source, $newman) || in_array($source, Scholar::getAdditionalSourceTypes(Application::getModule(), "2", $pid))) {
			$sourcetype = "2";
		} else {
			$sourcetype = "0";
		}

		return $sourcetype;
	}

	protected $value;
	protected $source;
	protected $sourceType;
	protected $date;
	protected $field;
	protected $instance;
	protected $pid;
}

class RaceEthnicityResult extends Result {
	public function __construct($value, $raceSource, $ethnicitySource, $pid = "") {
		$this->value = $value;
		$this->raceSource = self::translateSourceIfNeeded($raceSource);
		$this->ethnicitySource = self::translateSourceIfNeeded($ethnicitySource);
		$this->pid = $pid;
	}

	public function getRaceSource() {
		return $this->raceSource;
	}

	public function getEthnicitySource() {
		return $this->ethnicitySource;
	}

	public function getRaceSourceType() {
		return self::calculateSourceType($this->raceSource, $this->pid);
	}

	public function getEthnicitySourceType() {
		return self::calculateSourceType($this->ethnicitySource, $this->pid);
	}

	private $raceSource;
	private $ethnicitySource;
}

class Results {
	public function __construct() {
		$this->results = array();
		$this->fields = array();
	}

	public function addResult($field, $result) {
		array_push($this->results, $result);
		array_push($this->fields, $field);
	}

	# precondition: count($this->results) == count($this->fields)
	public function getNumberOfResults() {
		return count($this->results);
	}

	public function getField($i) {
		if ($i < $this->getNumberOfResults()) {
			return $this->fields[$i];
		}
		return "";
	}

	public function getResult($i) {
		if ($i < $this->getNumberOfResults()) {
			return $this->results[$i];
		}
		return NULL;
	}

	private $results;
	private $fields;
}
