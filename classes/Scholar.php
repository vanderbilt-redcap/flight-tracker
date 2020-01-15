<?php

namespace Vanderbilt\CareerDevLibrary;


require_once(dirname(__FILE__)."/Grants.php");
require_once(dirname(__FILE__)."/Upload.php");
require_once(dirname(__FILE__)."/Download.php");
require_once(dirname(__FILE__)."/../Application.php");

define("INTERNAL_K_LENGTH", 3);
define("EXTERNAL_K_LENGTH", 5);
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

	public static function getChoices($metadata) {
		$choicesStrs = array();
		$multis = array("checkbox", "dropdown", "radio");
		foreach ($metadata as $row) {
			if (in_array($row['field_type'], $multis) && $row['select_choices_or_calculations']) {
				$choicesStrs[$row['field_name']] = $row['select_choices_or_calculations'];
			} else if ($row['field_type'] == "yesno") {
				$choicesStrs[$row['field_name']] = "0,No|1,Yes";
			} else if ($row['field_type'] == "truefalse") {
				$choicesStrs[$row['field_name']] = "0,False|1,True";
			}
		}
		$choices = array();
		foreach ($choicesStrs as $fieldName => $choicesStr) {
			$choicePairs = preg_split("/\s*\|\s*/", $choicesStr);
			$choices[$fieldName] = array();
			foreach ($choicePairs as $pair) {
				$a = preg_split("/\s*,\s*/", $pair);
				if (count($a) == 2) {
					$choices[$fieldName][$a[0]] = $a[1];
				} else if (count($a) > 2) {
					$a = preg_split("/,/", $pair);
					$b = array();
					for ($i = 1; $i < count($a); $i++) {
						$b[] = $a[$i];
					}
					$choices[$fieldName][trim($a[0])] = implode(",", $b);
				}
			}
		}
		return $choices;
	}

	public static function getKLength($type) {
		if ($type == "Internal") {
			return INTERNAL_K_LENGTH;
		} else if ($type == "External") {
			return EXTERNAL_K_LENGTH;
		}
		return 0;
	}

	public function setGrants($grants) {
		$this->grants = $grants;
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
			if (in_array($row['identifier_institution'], Application::getInstitutions())) {
				return "Employed at ".$row['identifier_institution'];
			} else {
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

	public function process() {
		if ((count($this->rows) == 1) && ($this->rows[0]['redcap_repeat_instrument'] == "")) {
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

	private function makeUploadRow() {
		$uploadRow = array(
					"record_id" => $this->recordId,
					"redcap_repeat_instrument" => "",
					"redcap_repeat_instance" => "",
					"summary_last_calculated" => date("Y-m-d H:i"),
					);
		foreach ($this->name as $var => $value) {
			$uploadRow[$var] = $value;
		}
		foreach ($this->demographics as $var => $value) {
			$uploadRow[$var] = $value;
		}
		foreach ($this->metaVariables as $var => $value) {
			$uploadRow[$var] = $value;
		}

		$grantUpload = $this->grants->makeUploadRow();
		foreach ($grantUpload as $var => $value) {
			if (!isset($uploadRow[$var])) {
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

	private static function calculateKLengthInSeconds($type = "Internal") {
		if ($type == "Internal") {
			return INTERNAL_K_LENGTH * 365 * 24 * 3600;
		} else if ($type == "External") {
			return EXTERNAL_K_LENGTH * 365 * 24 * 3600;
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
						if ($rTime > $lastTime + self::calculateKLengthInSeconds("Internal")) {
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
						if (time() > $lastTime + self::calculateKLengthInSeconds("Internal")) {
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


	# returns an array of (variableName, variableType) for to where (whither) they left VUMC
	# used for the Scholars' survey and Follow-Up surveys
	private function getWhereLeftInstitution($rows) {
		$followupInstitutionField = "followup_institution";
		$checkInstitutionField = "check_institution";
		$institutionCurrent = "1";

		$currentInstitutions = Application::getInstitutions(); 
		for ($i = 0; $i < count($currentInstitutions); $i++) {
			$currentInstitutions[$i] = trim(strtolower($currentInstitutions[$i]));
		}
		$choices = self::getChoices($this->metadata);
		$followupRows = self::selectFollowupRows($rows);
		foreach ($followupRows as $instance => $row) {
			if ($row[$followupInstitutionField] && ($row[$followupInstitutionField] != $institutionCurrent)) {
				$value = $choices[$followupInstitutionField][$row[$followupInstitutionField]];
				if (strtolower($value) == "other") {
					$value = $row[$followupInstitutionField."_oth"];
				}
				if (!in_array(strtolower($value), $currentInstitutions)) {
					return new Result($value, "followup", "", "", $this->pid);
				}
			}
		}

		$normativeRow = self::getNormativeRow($rows);
		if ($row[$checkInstitutionField] && ($row[$checkInstitutionField] != $institutionCurrent)) {
			$value = $choices[$checkInstitutionField][$row[$checkInstitutionField]];
			if (strtolower($value) == "other") {
				$value = $row[$checkInstitutionField."_oth"];
			}
			if (!in_array(strtolower($value), $currentInstitutions)) {
				return new Result($value, "scholars", "", "", $this->pid);
			}
		}
		if ($normativeRow['imported_institution']) {
			$value = $normativeRow['imported_institution'];
			if (!in_array(strtolower($value), $currentInstitutions)) {
				return new Result($value, "manual", "", "", $this->pid);
			}
		}
		return new Result($normativeRow['identifier_institution'], $normativeRow['identifier_institution_source'], "", "", $this->pid);
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

	public function getOrder($defaultOrder, $field) {
		foreach ($this->metadata as $row) {
			if (($row['field_name'] == $field) && ($row['field_annotation'] != "")) {
				$newOrder = json_decode($row['field_annotation'], TRUE); 
				if ($newOrder) {
					$newVars = array();
					switch($field) {
						case "summary_degrees":
							foreach ($newOrder as $newField => $newSource) {
								if ($newField != $newSource) {
									$newVars[$newField] = $newSource;
								} else {
									foreach ($defaultOrder as $ary) {
										$newAry = array();
										foreach ($ary as $field => $source) {
											if ($source == $newSource) {
												$newAry[$field] = $source;
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
													if ($source == $newSource) {
														$newVars[$type][$field] = $source;
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
									$newVars[$newField] = $newSource;
								} else {
									foreach ($defaultOrder as $field => $source) {
										if ($source == $newSource) {
											$newVars[$field] = $source;
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
		$orders["summary_primary_dept"] = array(
							"override_department1" => "manual",
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
							"override_gender" => "manual",
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
						"imported_dob" => "manual",
						"override_dob" => "manual",
						"newman_new_date_of_birth" => "new2017",
						"newman_demographics_date_of_birth" => "demographics",
						"newman_data_date_of_birth" => "data",
						"newman_nonrespondents_date_of_birth" => "nonrespondents",
						);
		$orders["summary_citizenship"] = array(
							"followup_citizenship" => "followup",
							"check_citizenship" => "scholars",
							"imported_citizenship" => "manual",
							"override_citizenship" => "manual",
							);
		$orders["identifier_institution"] = array(
								"identifier_institution" => "manual",
								"imported_institution" => "manual",
								"check_institution" => "scholars",
								);
		$orders["summary_current_division"] = array(
								"followup_division" => "followup",
								"check_division" => "scholars",
								"override_division" => "manual",
								"vfrs_division" => "vfrs",
								);
		$orders["summary_current_rank"] = array(
							"promotion_rank" => "manual",
							"override_rank" => "manual",
							"followup_academic_rank" => "followup",
							"check_academic_rank" => "scholars",
							"newman_new_rank" => "new2017",
							);
		$orders["summary_current_start"] = array(
							"promotion_in_effect" => "manual",
							"followup_academic_rank_dt" => "followup",
							"check_academic_rank_dt" => "scholars",
							"override_position_start" => "manual",
							"vfrs_when_did_this_appointment" => "vfrs",
							);
		$orders["summary_current_tenure"] = array(
								"followup_tenure_status" => "followup",
								"check_tenure_status" => "scholars",
								"override_tenure" => "manual",
								);
		$orders["summary_mentor"] = array(
							"imported_mentor" => "manual",
							"override_mentor" => "manual",
							"followup_primary_mentor" => "followup",
							"check_primary_mentor" => "scholars",
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

	private function getGender($rows) {
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
	private function getRaceEthnicity($rows) {
		$order = self::getDefaultOrder("summary_race_ethnicity");
		$order = $this->getOrder($order, "summary_race_ethnicity");
		$normativeRow = self::getNormativeRow($rows);

		$race = "";
		$raceSource = "";
		foreach ($order["race"] as $variable => $source) {
			if (($normativeRow[$variable] !== "") && ($normativeRow[$variable] != 8)) {
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
			if (($normativeRow[$variable] !== "") && ($normativeRow[$variable] != 4)) {
				$eth = $normativeRow[$variable];
				$ethSource = $source;
				break;
			}
		}
		$val = "";
		if ($race == 2) {   # Asian
			$val = 5;
		}
		if ($eth == "") {
			return new RaceEthnicityResult("", "", "");
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
	private function getDOB($rows) {
		$vars = self::getDefaultOrder("summary_dob");
		$vars = $this->getOrder($vars, "summary_dob");
		$result = self::searchRowsForVars($rows, $vars, FALSE, $this->pid);
		$date = $result->getValue();
		if ($date) {
			$date = self::convertToYYYYMMDD($date);
		}

		return new Result($date, $result->getSource(), "", "", $this->pid);
	}

	private function getCitizenship($rows) {
		$vars = self::getDefaultOrder("summary_citizenship");
		$vars = $this->getOrder($vars, "summary_citizenship");
		return self::searchRowsForVars($rows, $vars, FALSE, $this->pid);
	}

	private static function getDateFieldForSource($source, $field) {
		switch($source) {
			case "followup":
				return "followup_date";
			case "manual":
				if (preg_match("/^promotion_/", $field)) {
					return "promotion_date";
				} else if (preg_match("/^override_", $field)) {
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
			foreach ($rows as $row) {
				if ($row[$var]) {
					$dateField = self::getDateFieldForSource($source, $var);
					$date = "";
					if ($dateField && $row[$dateField]) {
						$date = $row[$dateField];
					} else if ($dateField && ($dateField != "check_date")) {
						$date = $dateField;
					}
					if ($byLatest) {
						# order by date
						if ($date) {
							$currTs = strtotime($date);
							if ($currTs > $latestTs) {
								$latestTs = $currTs;
								$result = new Result(self::transformIfDate($row[$var]), $source, "", $date, $pid);
							}
						} else if (!$latestTs) {
							$result = new Result(self::transformIfDate($row[$var]), $source, "", "", $pid);
							$latestTs = 1; // nominally low value
						}
					} else {
						if ($row['redcap_repeat_instrument'] == $source) {
							# get earliest instance - i.e., lowest repeat_instance
							if (!$aryInstance
								|| ($aryInstance > $row['redcap_repeat_instance'])) {
								$result = new Result(self::transformIfDate($row[$var]), $source, "", $date, $pid);
								$aryInstance = $row['redcap_repeat_instance'];
							}
						} else {
							return new Result(self::transformIfDate($row[$var]), $source, "", $date, $pid);
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

		if ($value == "1") {
			return new Result("Vanderbilt", $source, "", "", $this->pid);
		} else if ($value == "2") {
			return new Result("Meharry", $source, "", "", $this->pid);
		} else if ($value == "") {
			return new Result("", ""); 
		} else if (is_numeric($value)) {
			# Other
			$otherVars = array(
					"check_institution_oth" => "scholars",
					);
			$otherResult = self::searchRowsForVars($rows, $otherVars, FALSE, $this->pid);
			if (!$otherResult->getValue()) {
				$otherValue = "Other";
				$otherSource = $source;
				return new Result($otherValue, $otherSource, "", "", $this->pid);
			} else {
				return $otherResult;
			}
		} else {
			return new Result($value, $source, "", "", $this->pid);
		}
	}

	private function getCurrentDivision($rows) {
		$vars = self::getDefaultOrder("summary_current_division");
		$vars = $this->getOrder($vars, "summary_current_division");
		return self::searchRowsForVars($rows, $vars, FALSE, $this->pid);
	}

	private function getCurrentRank($rows) {
		$vars = self::getDefaultOrder("summary_current_rank");
		$vars = $this->getOrder($vars, "summary_current_rank");
		$result = self::searchRowsForVars($rows, $vars, TRUE, $this->pid);
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
		return self::searchRowsForVars($rows, $vars, FALSE, $this->pid);
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

	private static function getDemographicFields() {
		return array(
				"summary_coeus_name" => "calculateCOEUSName",
				"summary_survey" => "getSurvey",
				"identifier_left_date" => "getWhenLeftInstitution",
				"identifier_institution" => "getWhereLeftInstitution",
				"summary_degrees" => "getDegrees",
				"summary_primary_dept" => "getPrimaryDepartment",
				"summary_gender" => "getGender",
				"summary_race_ethnicity" => "getRaceEthnicity",
				"summary_dob" => "getDOB",
				"summary_citizenship" => "getCitizenship",
				"summary_current_institution" => "getInstitution",
				"summary_current_division" => "getCurrentDivision",
				"summary_current_rank" => "getCurrentRank",
				"summary_current_start" => "getCurrentAppointmentStart",
				"summary_current_tenure" => "getTenureStatus",
				);
	}

	private function processDemographics() {
		$this->demographics = array();
		$fields = self::getDemographicFields();
		$rows = $this->rows;

		$specialCases = array("summary_degrees", "summary_coeus_name", "summary_survey", "summary_race_ethnicity");
		foreach ($fields as $field => $func) {
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

	private $token;
	private $server;
	private $metadata;
	private $grants;
	private $rows;
	private $recordId;
	private $name = array();
	private $demographics = array();    // key for demographics is REDCap field name; value is REDCap value
	private $metaVariables = array();   // copied from the Grants class
}

class Result {
	public function __construct($value, $source, $sourceType = "", $date = "", $pid = "") {
		$this->value = $value;
		$this->source = $source;
		$this->sourceType = $sourceType;
		$this->date = $date;
		$this->pid = $pid;
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
	protected $pid;
}

class RaceEthnicityResult extends Result {
	public function __construct($value, $raceSource, $ethnicitySource, $pid = "") {
		$this->value = $value;
		$this->raceSource = $raceSource;
		$this->ethnicitySource = $ethnicitySource;
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
