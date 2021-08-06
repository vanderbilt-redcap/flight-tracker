<?php
 
namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class EmailManager {
	public function __construct($token, $server, $pid, $module = NULL, $metadata = array()) {
		$this->token = $token;
		$this->server = $server;
		$this->pid = $pid;
		$this->metadata = $metadata;
		$this->hijackedField = "";

		$possibleFields = array("identifier_vunet", "identifier_userid");
		foreach ($this->metadata as $row) {
			if (in_array($row['field_name'], $possibleFields)) {
				$this->hijackedField = $row['field_name'];
				break;
			}
		}

		$this->settingName = "prior_emails";
		$this->module = $module;
		if ($module) {
			$this->data = self::loadData($this->settingName, $this->module, $this->hijackedField, $this->pid);
		} else {
			$this->data = self::loadData($this->settingName, $this->metadata, $this->hijackedField, $this->pid);
		}
	}

	public static function isEmailAddress($str) {
		if (preg_match("/^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,3})+$/", $str)) {
			return TRUE;
		}
		return FALSE;
	}

	public static function getFormat() {
		return "m-d-Y H:i";
	}

	public function deleteEmail($name) {
		if (isset($this->data[$name])) {
			unset($this->data[$name]);
		}
	}

	public function hasItem($name) {
		return isset($this->data[$name]);
	}

	public function getItem($name) {
		if (isset($this->data[$name])) {
			return $this->data[$name];
		}
		return self::getBlankSetting();
	}

	public static function getBlankSetting() {
		return array("who" => array(), "what" => array(), "when" => array(), "enabled" => FALSE);
	}

	# default is all names and to the actual 'to' emails
	# $to, if specified, denotes a test email
	# prepares one email per recipient per setting-name specified (in other words, prepares many emails)
	public function prepareRelevantEmails($to = "", $names = array()) {
		return $this->enqueueRelevantEmails($to, $names, "prepareEmail");
	}

	# names contain the names of the email setting(s) to test
	# prepares one email per setting-name specified
	public function prepareSummaryTestEmail($to, $names) {
		if (!is_array($names)) {
			$names = array($names);
		}
		$func = "prepareOneEmail";

		$results = array();
		foreach ($this->data as $name => $emailSetting) {
			if (in_array($name, $names) || empty($names)) {
				$results[$name] = array();
				$when = $emailSetting["when"];
				foreach ($when as $type => $datetime) {
					if ($type == "initial_time") {
						$ts = self::transformToTS($datetime);
						$result = $this->$func($emailSetting, $name, $type, $to);
						$results[$name][$type] = $result;
					}
				}
			}
		}
		return $results;
	}

	public function sendPreparedEmails($messages, $isTest = FALSE) {
		if (!is_array($messages)) {
			$messages = json_decode($messages, TRUE);
		}
		if ($messages !== NULL) {
			foreach ($messages as $name => $types) {
				foreach ($types as $type => $emailData) {
					$this->sendPreparedEmail($emailData, $isTest);
				}
			}
		} else {
			throw new \Exception("No messages specified!");
		}
	}

	# default is all names and to the actual 'to' emails
	# $to, if specified, denotes a test email
	# main way of sending emails
	public function sendRelevantEmails($to = "", $names = array()) {
		$messages = $this->enqueueRelevantEmails($to, $names, "sendEmail");
		$this->sendPreparedEmails($messages, ($to !== ""));
	}

	private function transformToTS($datetime) {
		if (preg_match("/^\d+-\d+-\d\d\d\d/", $datetime, $matches)) {
			# assume MDY
			$match = $matches[0];
			$nodes = preg_split("/-/", $match);

			# return YMD
			$datetime = str_replace($match, $nodes[2]."-".$nodes[0]."-".$nodes[1], $datetime);
		}

		$ts = strtotime($datetime);
		if (!$ts) {
			throw new \Exception("Could not create timestamp from ".$datetime);
		}
		return $ts;
	}

	public function getQueue($thresholdTs) {
	    $rows = [];
        foreach ($this->data as $name => $emailSetting) {
            foreach ($emailSetting["when"] as $whenType => $datetime) {
                $ts = self::transformToTS($datetime);
                if ($ts >= $thresholdTs) {
                    $processedRows = $this->getRows($emailSetting["who"], $whenType, $this->getForms($emailSetting["what"]));
                    $emailsByRecord = self::processEmails($processedRows);
                    $emails = [];
                    foreach ($emailsByRecord as $recordId => $email) {
                        if ($email) {
                            $emails[] = Links::makeRecordHomeLink($this->pid, $recordId, "Record $recordId").": $email";
                        }
                    }

                    $row = [];
                    $row['name'] = $name;
                    list($row['date'], $row['time']) = explode(" ", $datetime);
                    $row['from'] = $emailSetting['what']['from'];
                    $row['subject'] = self::getSubject($emailSetting["what"]);;
                    $row['to'] = $emails;
                    $row['to_count'] = count($emails);
                    $rows[] = $row;
                }
            }
        }
        return $rows;
    }

	private function enqueueRelevantEmails($to = "", $names = array(), $func = "sendEmail", $currTime = FALSE) {
		if (!is_array($names)) {
			$names = array($names);
		}
		$results = [];
		if (!$currTime) {
			$currTime = $_SERVER['REQUEST_TIME_FLOAT'];
		}
		foreach ($this->data as $name => $emailSetting) {
			if (in_array($name, $names) || empty($names)) {
                // Application::log("Checking if $name is enabled");
				if ($emailSetting['enabled'] || ($func == "prepareEmail")) {
                    if (!Application::isLocalhost()) {
                        Application::log("$name is enabled");
                    }
                    $when = $emailSetting["when"];
					foreach ($when as $type => $datetime) {
						$ts = self::transformToTS($datetime);
						$result = FALSE;
						if ($to) {
							# This is a test email because a $to is specified
							if ($type == "initial_time") {
								$result = $this->$func($emailSetting, $name, $type, $to);
							}
						} else {
							if ($this->isReadyToSend($ts, $currTime)) {
							    if (!Application::isLocalhost()) {
                                    Application::log("Sending $name");
                                }
                                $result = $this->$func($emailSetting, $name, $type);
							}
						}
						if ($result && !empty($result)) {
                            if (!isset($results[$name])) {
                                $results[$name] = [];
                            }
							$results[$name][$type] = $result;
						}
					}
				} else {
					// echo "$name is not enabled: ".json_encode($emailSetting)."\n";
				}
			}
		}
		return $results;
	}

	public function isReadyToSend($ts1, $ts2) {
		return (date("Y-m-d H:i", $ts1) == date("Y-m-d H:i", $ts2));
	}

	private static function within15MinutesAfter($proposedTs, $currTs) {
		return ($currTs <= $proposedTs) && ($currTs + 15 * 60 > $proposedTs);
	}

	public function saveSetting($name, $emailSetting) {
		if (!isset($emailSetting["who"]) || !isset($emailSetting["what"]) || !isset($emailSetting["when"])) {
			throw new \Exception("Email setting invalid! A who, what, and when must be specified.");
		}
		$this->data[$name] = $emailSetting;    // overwrites if previously exist
		return $this->saveData();
	}

	public function getMessageHash() {
		$messages = array();
		foreach ($this->getSettingsNames() as $name) {
			$mssg = $this->data[$name]["what"]["message"];
			if ($mssg) {
				$messages[$name] = $mssg;
			}
		}
		return $messages;
	}

	public function getSelectForExistingNames($elemName, $settingName = "") {
		$names = $this->getSettingsNames();

		$html = "";
		if (!empty($names)) {
			$html .= "<select id='$elemName' name='$elemName'>\n";
			$html .= "<option value=''>---SELECT---</option>\n";
			foreach ($names as $name) {
				$html .= "<option value='$name'";
				if ($settingName == $name) {
					$html .= " selected";
				}
				$html .= ">$name</option>\n";
			}
			$html .= "</select>\n";
		} else {
			$html .= "Nothing has been saved.";
		}
		return $html;
	}

	# returns records of emails
	private function sendEmail($emailSetting, $settingName, $whenType, $toField = "who") {
		$emailData = $this->prepareEmail($emailSetting, $settingName, $whenType, $toField);
		if (!empty($emailData)) {
			return $this->sendPreparedEmail($emailData, ($toField != "who"));
		}
		return array();
	}

	# returns records of emails
	private function sendPreparedEmail($emailData, $isTest = FALSE) {
		$name = $emailData["name"];
		$mssgs = $emailData["mssgs"];
		$to = $emailData["to"];
		$from = $emailData["from"];
		$subjects = $emailData["subjects"];

		foreach ($mssgs as $recordId => $mssg) {
			Application::log(date("Y-m-d H:i:s")." $recordId: EmailManager sending $name to {$to[$recordId]}; from $from; {$subjects[$recordId]}");
			if (!class_exists("\REDCap") || !method_exists("\REDCap", "email")) {
				require_once(dirname(__FILE__)."/../../../redcap_connect.php");
			}
			if (!class_exists("\REDCap") || !method_exists("\REDCap", "email")) {
				throw new \Exception("Could not find REDCap class!");
			}

			\REDCap::email($to[$recordId], $from, $subjects[$recordId], $mssg);
			usleep(200000); // wait 0.2 seconds for other items to process
		}
		$records = array_keys($mssgs);
		if (!$isTest) {
			if (!isset($this->data[$name]['sent'])) {
				$this->data[$name]['sent'] = array();
			}
			if ($records) {
				$sentAry = array("ts" => time(), "records" => $records);
				array_push($this->data[$name]['sent'], $sentAry);
				$this->saveData();
			}
		}
		return $records;
	}

	# returns array of sent items for given $settingName
	# each array contains a field for 'ts' and the 'records' (ids) communicated with
	# if $settingName == "all", returns indexed array of all settings
	public function getSentEmails($settingName = "all") {
		if ($settingName = "all") {
			$sentAry = array();
			foreach ($this->data as $name => $setting) {
				if ($setting['sent']) {
					$sentAry[$name] = $setting['sent'];
				}
			}
			return $sentAry; 
		} else if ($this->data[$settingName] && $this->data[$settingName]['sent']) {
			return $this->data[$settingName]['sent'];
		}
		return array();
	}

	private function getLastNames($recordIds) {
		$allLastNames = Download::lastnames($this->token, $this->server);
		$filteredLastNames = array();
		foreach ($recordIds as $recordId) {
			$filteredLastNames[$recordId] = $allLastNames[$recordId];
		}
		return $filteredLastNames;
	}

	private function getForms($what) {
		$forms = array();
		if (isset($what["message"])) {
			$mssg = $what["message"];
			$surveys = $this->getSurveys();
			foreach ($surveys as $survey) {
				if (preg_match("/\[survey_link_$survey\]/", $mssg)) {
					array_push($forms, $survey);
				}
			}
		}
		return $forms;
	}

	private function prepareOneEmail($emailSetting, $settingName, $whenType, $toField = "who") {
		$data = $this->prepareEmail($emailSetting, $settingName, $whenType, $toField);
		if (!empty($data)) {
            $mssgRecords = array_keys($data['mssgs']);
			$numMssgs = count($mssgRecords);
			if ($numMssgs > 0) {
				$recordId = reset($mssgRecords);
			}
			if ($recordId) {
				$data['mssgs'] = array($recordId => $data['mssgs'][$recordId]);
				$data['subjects'] = array($recordId => "Sample Email (of $numMssgs total): ".$data['subjects'][$recordId]);
				$data['to'] = array($recordId => $data['to'][$recordId]);
			}
		}
		return $data;
	}

	private function prepareEmail($emailSetting, $settingName, $whenType, $toField = "who") {
		$rows = $this->getRows($emailSetting["who"], $whenType, $this->getForms($emailSetting["what"]));
		if (empty($rows)) {
			return [];
		}

		$data = array();
		$emails = self::processEmails($rows);
		$names = self::processNames($rows);
		$lastNames = $this->getLastNames(array_keys($rows));
		$subject = self::getSubject($emailSetting["what"]);
		$data['name'] = $settingName;
		$data['mssgs'] = $this->getMessages($emailSetting["what"], array_keys($rows), $names, $lastNames);
		$data['subjects'] = array();
		$data['to'] = array();
		if ($toField == "who") {
			foreach (array_keys($rows) as $recordId) {
				$data['to'][$recordId] = $emails[$recordId];
				$data['subjects'][$recordId] = $subject;
			}
		} else {
			foreach (array_keys($rows) as $recordId) {
				$data['to'][$recordId] = $toField;
				$data['subjects'][$recordId] = $emails[$recordId].": ".$subject;
			}
		}
		$data['from'] = $emailSetting["who"]["from"];

		return $data;
	}

	public function getSettingsNames() {
		$allEmailKeys = array_keys($this->data);
		// Application::log("Email keys: ".json_encode($allEmailKeys));
		if (method_exists("Application", "getEmailName")) {
			$allRecords = Download::recordIds($this->token, $this->server);
			$unmatchedKeys = array();
			foreach ($allEmailKeys as $key) {
				$keyFound = FALSE;
				foreach ($allRecords as $recordId) {
					if ($key == Application::getEmailName($recordId)) {
						$keyFound = TRUE;
					}
				}
				if (!$keyFound) {
					array_push($unmatchedKeys, $key);
				}
			}
			return $unmatchedKeys;
		} else {
			return $allEmailKeys;
		}
	}

	public function getNames($who) {
		$rows = $this->getRows($who);
		return self::processNames($rows);
	}

	public function getEmails($who) {
		$rows = $this->getRows($who);
		return self::processEmails($rows);
	}

	public static function getSurveyLinks($pid, $records, $instrument, $maxInstances = array()) {
		$newInstances = array();
		$_GET['pid'] = $pid;    // for the Application::link on the cron
		foreach ($records as $recordId) {
			if ($maxInstances[$recordId]) {
				$newInstances[$recordId] = $maxInstances[$recordId] + 1;
			} else {
				$newInstances[$recordId] = 1;
			}
		}
		$data = array(
				"records" => $records,
				"instrument" => $instrument,
				"instances" => $newInstances,
				);
		$ch = curl_init();
		$url = Application::link("emailMgmt/makeSurveyLinks.php")."&NOAUTH";
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
		$output = curl_exec($ch);
		curl_close($ch);
		if ($returnList = json_decode($output, TRUE)) {
			return $returnList;
		} else {
		    Application::log($url);
		    Application::log("Warning! Could not decode JSON: $output");
			return $output;
		}
	}

	private function getMessages($what, $recordIds, $names, $lastNames) {
		$token = $this->token;
		$server = $this->server;

		if (empty($this->metadata)) {
		    $this->metadata = Download::metadata($token, $server);
        }

		$mssg = $what["message"];

		$mssgs = array();
		foreach ($recordIds as $recordId) {
			$mssgs[$recordId] = $mssg;
		}

		$repeatingForms = $this->getRepeatingForms();

		$fields = array("record_id", "identifier_first_name", "identifier_last_name", "identifier_email", "summary_ever_r01_or_equiv");
		$surveys = $this->getSurveys();
		foreach ($surveys as $form => $title) {
			array_push($fields, $form."_complete");
			array_push($fields, self::getDateField($form));
		}
		$fields = REDCapManagement::filterOutInvalidFields($this->metadata, $fields);

		# structure data
		$redcapData = Download::fields($this->token, $this->server, $fields);

		$mssgFrags = preg_split("/[\[\]]/", $mssg);
		$formRecords = array();
		foreach ($mssgFrags as $fragment) {
			if (preg_match("/^survey_link_(.+)$/", $fragment, $matches)) {
				$formName = $matches[1];
				if (!isset($formRecords[$formName])) {
					$formRecords[$formName] = array();
				}
				foreach ($recordIds as $recordId) {
					array_push($formRecords[$formName], $recordId);
				}
			}
		}
		$surveyLinks = array();
		$maxInstances = array();
		foreach ($formRecords as $formName => $records) {
			if (in_array($formName, $repeatingForms)) {
				$maxInstances[$formName] = array();
				foreach ($records as $recordId) {
					$maxInstances[$formName][$recordId] = 0;
					foreach ($redcapData as $row) {
						if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == $formName) && ($row['redcap_repeat_instance'] > $maxInstances[$formName][$recordId])) {
							$maxInstances[$formName][$recordId] = $row['redcap_repeat_instance'];
						}
					}
				}
			}

			if (in_array($formName, $repeatingForms)) {
				$surveyLinks[$formName] = self::getSurveyLinks($this->pid, $records, $formName, $maxInstances[$formName]);
			} else {
				$surveyLinks[$formName] = self::getSurveyLinks($this->pid, $records, $formName);
			}
		}
		foreach ($recordIds as $recordId) {
			while (preg_match("/\[survey_link_(.+?)\]/", $mssgs[$recordId], $matches)) {
				$formName = $matches[1];
				$surveyLink = FALSE;
				if ($surveyLinks[$formName] && $surveyLinks[$formName][$recordId]) {
					$surveyLink = $surveyLinks[$formName][$recordId];
				}

				$mssgs[$recordId] = str_replace("[survey_link_".$formName."]", $surveyLink, $mssgs[$recordId]);
			}
            if (preg_match("/\[name\]/", $mssgs[$recordId])) {
                if ($names[$recordId]) {
                    $mssgs[$recordId] = str_replace("[name]", $names[$recordId], $mssgs[$recordId]);
                }
            }
            if (preg_match("/\[mentoring_agreement\]/", $mssgs[$recordId])) {
                $mssgs[$recordId] = str_replace("[mentoring_agreement]", Application::link("mentor/intro.php"), $mssgs[$recordId]);
            }
			if (preg_match("/\[last_name\]/", $mssgs[$recordId])) {
				if ($lastNames[$recordId]) {
					$mssgs[$recordId] = str_replace("[last_name]", $lastNames[$recordId], $mssgs[$recordId]);
				}
			}
			// $mssgs[$recordId] = str_replace("</p><p>", "<br>", $mssgs[$recordId]);
			// $mssgs[$recordId] = str_replace("<p><br></p>", "<br>", $mssgs[$recordId]);
			// $mssgs[$recordId] = str_replace("<br><br><br>", "<br><br>", $mssgs[$recordId]);
		}

		return $mssgs;
	}

	public function getSurveySelect($id = "survey") {
		$html = "";
		$html .= "<select id='$id'>\n";
		$html .= "<option value=''>---SELECT---</option>\n";
		foreach ($this->getSurveys() as $survey => $title) {
			$html .= "<option value='$survey'>$title</option>\n";
		}
		$html .= "</select>\n";
		return $html;
	}

	private function getSurveys() {
		return REDCapManagement::getSurveys($this->pid, $this->metadata);
	}

	private function getRepeatingForms() {
		return REDCapManagement::getRepeatingForms($this->pid);
	}

	private static function processEmails($rows) {
		$emails = array();
		foreach ($rows as $recordId => $row) {
			$emails[$recordId] =  $row['email'];
		}
		return $emails;
	}

	private static function processNames($rows) {
		$lastNames = array();
		foreach ($rows as $recordId => $row) {
			$lastNames[$recordId] = $row['last_name'];
		}
		asort($lastNames);

		$names = array();
		foreach ($lastNames as $recordId => $lastName) {
			$row = $rows[$recordId];
			$names[$recordId] = $row['first_name']." ".$row['last_name'];
		}
		return $names;
	}

	private function getDateField($form) {
		if ($form == "initial_survey") {
			return "check_date";
		}
		return $form."_date";
	}

	public function collectAllEmails() {
		# get all names, either when specifying that the recipients are individual emails or if this is a mass email to all
		$emails = Download::emails($this->token, $this->server);
		$firstNames = Download::firstNames($this->token, $this->server);
		$lastNames = Download::lastNames($this->token, $this->server);
		$recordIds = array_unique(array_merge(array_keys($emails), array_keys($firstNames), array_keys($lastNames)));

		$rows = array();
		foreach ($recordIds as $recordId) {
			$rows[$recordId] = array(
							"last_name" => $lastNames[$recordId],
							"first_name" => $firstNames[$recordId],
							"email" => $emails[$recordId],
						);

		}
		return $rows;
	}

	# coordinate with emailMgmtNew.js
	public static function makeEmailIntoID($email) {
	    return preg_replace("/\@/", "_at_", $email);
    }

	public function getAllCheckedEmails($who) {
		# checked off emails, specified in who
		if (is_array($who['individuals'])) {
			$pickedEmails = array();
			for ($i = 0; $i < count($who['individuals']); $i++) {
				array_push($pickedEmails, strtolower($who['individuals'][$i]));
			}
		} else {
			$pickedEmails = explode(",", strtolower($who['individuals']));
		}
		$allEmails = Download::emails($this->token, $this->server);
		$firstNames = Download::firstNames($this->token, $this->server);
		$lastNames = Download::lastNames($this->token, $this->server);
		$recordIds = array_unique(array_merge(array_keys($allEmails), array_keys($firstNames), array_keys($lastNames)));

		$rows = array();
		foreach ($recordIds as $recordId) {
			$email = strtolower($allEmails[$recordId]);
			if (in_array($email, $pickedEmails)) {
				$rows[$recordId] = array(
								"last_name" => $lastNames[$recordId],
								"first_name" => $firstNames[$recordId],
								"email" => $email,
							);
			}

		}
		return $rows;
	}

	public function filterSome($who, $whenType, $when, $what) {
	    // Application::log("filterSome: ".json_encode_with_spaces($who));
        if (count($this->metadata) == 0) {
            $this->metadata = Download::metadata($this->token, $this->server);
        }
		$fields = array("record_id", "identifier_first_name", "identifier_last_name", "identifier_email", "summary_ever_r01_or_equiv");
		$surveys = $this->getSurveys();
        $steps = [];
        $steps["surveys"] = $surveys;
        $steps["metadata"] = $this->metadata;
		foreach ($surveys as $form => $title) {
			array_push($fields, $form."_complete");
			array_push($fields, self::getDateField($form));
		}
        $steps["fields 1"] = $fields;
		$fields = REDCapManagement::filterOutInvalidFields($this->metadata, $fields);

		# structure data
        $steps["fields 2"] = $fields;
		$redcapData = Download::fields($this->token, $this->server, $fields);
		$steps["redcapData"] = count($redcapData);
		$identifiers = array();
		$lastUpdate = array();
		$complete = array();
		$converted = array();
		foreach ($redcapData as $row) {
			$recordId = $row['record_id'];
			if ($row['redcap_repeat_instrument'] == "") {
			    if (isset($row['summary_ever_r01_or_equiv'])) {
                    $converted[$recordId] = $row['summary_ever_r01_or_equiv'];
                }
				$identifiers[$recordId] = array(
								"last_name" => $row['identifier_last_name'],
								"first_name" => $row['identifier_first_name'],
								"email" => $row['identifier_email'],
								);
			}
			foreach ($surveys as $currForm => $title) {
				$dateField = self::getDateField($currForm);
				if ($row[$dateField]) {
					$ts = strtotime($row[$dateField]);
					if (!isset($lastUpdate[$recordId])) {
						$lastUpdate[$recordId] = array( $currForm => $ts );
					} else if ($ts > $lastUpdate[$recordId][$currForm]) {
						$lastUpdate[$recordId][$currForm] = $ts;
					}
				} else if ($row[$currForm."_complete"] == "2") {
					# date blank
					if (!isset($complete[$recordId])) {
						$complete[$recordId] = array($currForm);
					} else {
						array_push($complete[$recordId], $currForm);
					}
				}
			}
		}

		$created = array();
		$queue = array_keys($identifiers);
		$steps["queue"] = $queue;
		$filterFields = array(
					"affiliations",
					"primary_affiliation",
					"department",
					"paf",
					"research_admin",
					"role",
					"building",
					"floor",
					"team",
					"leadership_teams",
					"grad_teaching",
					"med_student_teaching",
					"undergrad_teaching",
					"imph_pi",
					"other_pi",
					"imph_coi",
					);
		$fieldsToDownload = array();
		$filtersToApply = array();
		foreach ($filterFields as $whoFilterField) {
			if (isset($who[$whoFilterField])) {
                $redcapField = self::getFieldAssociatedWithFilter($whoFilterField);
                array_push($fieldsToDownload, $redcapField);
                array_push($filtersToApply, $whoFilterField);
			}
		}
		if (!empty($fieldsToDownload)) {
			array_push($fieldsToDownload, "record_id");
			$filterREDCapData = Download::fields($this->token, $this->server, $fieldsToDownload);
			foreach ($filtersToApply as $filter) {
				$redcapField = self::getFieldAssociatedWithFilter($filter);
				if ($filter == "team") {
                    $queue = self::filterForTeams($redcapField, $who[$filter], $filterREDCapData, $queue);
                } else {
                    $queue = self::filterForField($redcapField, $who[$filter], $filterREDCapData, $queue);
                }
			}
		}

        if ($who['last_complete']) {
			$queue = self::filterForMonths($who['last_complete'], $lastUpdate, $queue);
			$steps["1"] = $queue;
		}
		if ($who['none_complete'] == "true") {
			$queue = self::filterForNoComplete($lastUpdate, $complete, $queue);
            $steps["2"] = $queue;
		} else if ($who['none_complete'] == "false") {
            $queue = self::filterForComplete($lastUpdate, $complete, $queue);
            $steps["3"] = $queue;
        }
		if ($who['converted']) {
			$queue = self::filterForConverted($who['converted'], $converted, $queue);
            $steps["4"] = $queue;
		}
		if ($who['max_emails'] || $who['new_records_since']) {
			foreach ($queue as $recordId) {
				$createDate = self::findRecordCreateDate($this->pid, $recordId);
				if ($createDate) {
					$created[$recordId] = strtotime($createDate);
				}
			}
            $steps["5"] = $queue;
		}
		if ($who['max_emails']) {
			$queue = $this->filterForMaxEmails($who['max_emails'], $created, $queue);
            $steps["6"] = $queue;
		}
		if ($who['new_records_since']) {
			$queue = self::filterForNewRecordsSince($who['new_records_since'], $created, $queue);
            $steps["7"] = $queue;
		}
		if ($whenType == "followup_time") {
			$queue = self::filterOutSurveysCompleted($this->getForms($what), $when, $complete, $lastUpdate, $queue);
            $steps["8"] = $queue;
		}

		# build row of names and emails
		$rows = array();
		foreach ($queue as $recordId) {
			$rows[$recordId] = $identifiers[$recordId];
		}
        // Application::log("filterSome rows: ".count($rows));
		foreach ($steps as $num => $items) {
            // Application::log("filterSome step $num: ".count($items));
        }

        return $rows;
	}

	public function getRows($who, $whenType = "", $when = array(), $what = array()) {
		if (($who['filter'] == "all") || ($who['recipient'] == "individuals")) {
			return $this->collectAllEmails();
		} else if ($who['individuals']) {
			return $this->getAllCheckedEmails($who);
		} else if ($who['filter'] == "some") {
			return $this->filterSome($who, $whenType, $when, $what);
		} else if (empty($who)) {
			return array();
		} else {
			throw new \Exception("Could not interpret who: ".json_encode($who));
		}
	}

	private static function filterOutSurveysCompleted($usedForms, $when, $complete, $lastUpdate, $queue) {
		if (!empty($when) && !empty($usedForms)) {
			$whenTs = array();
			foreach ($when as $type => $datetime) {
				$whenTs[$type] = self::transformToTS($datetime);
			}
			$newQueue = array();
			foreach ($queue as $recordId) {
				$recentlyFilledOut = FALSE;
				$formsComplete = array();
				if (isset($complete[$recordId])) {
					$formsComplete = $complete[$recordId];
				}
				foreach ($usedForms as $form) {
					if (in_array($form, $formsComplete) && isset($lastUpdate[$recordId]) && isset($lastUpdate[$recordId][$form])) {
						# push forward to end of day to correct for below comparison
						$ts = $lastUpdate[$recordId][$form] + 24 * 3600;
						foreach ($whenTs as $type => $emailTs) {
							if ($ts > $emailTs) {
								$recentlyFilledOut = TRUE;
								break;
							}
						}
					}
					if ($recentlyFilledOut) {
						break;
					}
				}
				if (!$recentlyFilledOut) {
					array_push($newQueue, $recordId);
				}
			}
			return $newQueue;
		}
		return $queue;
	}

	public static function getFieldAssociatedWithFilter($whoFilter) {
		switch($whoFilter) {
			case "affiliations":
				return "summary_affiliations";
			case "team":
				return "survey_team";
            case "leadership_teams":
                return "survey_leadership_teams";
            case "floor":
                return "survey_floor";
            case "suite":
                return "survey_suite";
			case "primary_affiliation":
				return "summary_primary_affiliation";
			case "department":
				return "summary_department";
			case "paf":
				return "summary_paf";
			case "research_admin":
				return "summary_research_admin";
			case "role":
				return "summary_role";
			case "building":
				return "summary_building";
			case "grad_teaching":
				return "survey_graduate_students";
			case "med_student_teaching":
				return "survey_medical_students";
			case "undergrad_teaching":
				return "survey_undergraduate_students";
			case "imph_pi":
				return "tracker_active_imph_pi";
			case "other_pi":
				return "tracker_active_other_pi";
			case "imph_coi":
				return "tracker_active_imph_coi";
			default:
				return "";
		}
	}

	private static function filterForNewRecordsSince($sinceMonths, $created, $queue) {
		$sinceTs = time();
		for ($i = 0; $i < $sinceMonths; $i++) {
			$month = date("n", $sinceTs);
			$year = date("Y", $sinceTs);
			$month--;
			if ($month <= 0) {
				$month = 12;
				$year--;
			}
			$sinceTs = strtotime("$year-$month-01");
		}

		$newQueue = array();
		foreach ($queue as $recordId) {
			$createdTs = $created[$recordId];
			if ($createdTs > $sinceTs) {
				array_push($newQueue, $recordId);
			}
		}
		return $newQueue;
	}

	# not static because requires access to sent dates in $this->data
	private function filterForMaxEmails($maxEmails, $created, $queue) {
		$newQueue = array();
		foreach ($queue as $recordId) {
			$createdTs = $created[$recordId];
			$numEmailsSent = 0;
			foreach ($this->data as $name => $emailSetting) {
				# must have been applicable for email
				if ($emailSetting['sent']) {
					foreach ($emailSetting['sent'] as $sentAry) {
						# must be a prior email after the record was created
						// array_push($newQueue, $recordId." ".json_encode($sentAry)." ".$createdTs);
						$ts = $sentAry['ts'];
						$recordsSent = $sentAry['records'];
						if ($recordsSent == "all") {
							$recordsSent = $queue;
						}
						if ($ts && $recordsSent && ($ts < time()) && ($ts >= $createdTs) && in_array($recordId, $recordsSent)) {
							$numEmailsSent++;
						}
					}
				}
			}
			if ($numEmailsSent < $maxEmails) {
				array_push($newQueue, $recordId);
			}
		}
		return $newQueue;
	}

	private static function filterForMonths($months, $lastUpdate, $queue) {
		$threshold = time() - $months * 30 * 24 * 3600;
		foreach ($lastUpdate as $recordId => $forms) {
			if (in_array($recordId, $queue)) {
				$afterThreshold = FALSE;
				foreach ($forms as $form => $ts) {
					if ($ts > $threshold) {
						$afterThreshold = TRUE;
						break;
					}
				}
				if ($afterThreshold) {
					unset($queue[array_search($recordId, $queue)]);
				}
			}
		}
		return $queue;
	}

    # if no surveys ever completed
    private static function filterForNoComplete($lastUpdate, $complete, $queue) {
        foreach ($queue as $recordId) {
            if (isset($lastUpdate[$recordId]) || isset($complete[$recordId])) {
                unset($queue[array_search($recordId, $queue)]);
            }
        }
        return $queue;
    }

    # if any surveys ever completed
    private static function filterForComplete($lastUpdate, $complete, $queue) {
        foreach ($queue as $recordId) {
            if (!isset($lastUpdate[$recordId]) && !isset($complete[$recordId])) {
                unset($queue[array_search($recordId, $queue)]);
            }
        }
        return $queue;
    }

    private static function filterForConverted($status, $converted, $queue) {
		$convValue = "";
		if ($status == "yes") {
			$convValue = "1";
		} else if ($status == "no") {
			$convValue = "0";
		}
		foreach($converted as $recordId => $currVal) {
			if (($convValue !== "") && in_array($recordId, $queue) && ($convValue !== $currVal)) {
				unset($queue[array_search($recordId, $queue)]);
			}
		}
		return $queue;
	}

	private static function getSubject($what) {
		if ($what && $what['subject']) {
			return $what['subject'];
		}
		return "";
	}

	private static function afterAllTimestamps($currTs, $sent) {
		foreach ($sent as $ary) {
			$ts = $ary['ts'];
			if ($ts && ($ts >= $currTs)) {
				return FALSE;
			}
		}
		return TRUE;
	}

	private function saveData() {
		$settingName = $this->settingName;
		$data = self::cleanUpEmails($this->data, $this->token, $this->server);
		if ($this->module) {
		    Application::log("Saving email data into $settingName");
			return $this->module->setProjectSetting($settingName, $data, $this->pid);
		} else if ($this->metadata) {
			$json = json_encode($data);
			$newMetadata = array();
			foreach ($this->metadata as $row) {
				if ($row['field_name'] == $this->hijackedField) {
					$row['field_annotation'] = $json;
				}
				array_push($newMetadata, $row);
			}
			$this->metadata = $newMetadata;
			$feedback = Upload::metadata($newMetadata, $this->token, $this->server);
			Application::log("Email Manager save: ".json_encode($feedback));
			return $feedback;
		} else {
			throw new \Exception("Could not save settings to $settingName! No module available!");
		}
	}

	private static function cleanUpEmails($data, $token, $server) {
		$recordIds = Download::recordIds($token, $server);
		$cleanedData = array();
		$currTime = time();
		foreach ($data as $key => $setting) {
			$include = TRUE;
			$when = $setting["when"];
			$sent = $setting["sent"];
			foreach ($recordIds as $recordId) {
				if ((Application::getEmailName($recordId) == $key)
					&& self::afterAllTimestamps($currTime, $sent)
					&& self::afterAllTimestamps($currTime, $when)
					&& self::areAllSent($when, $sent)) {

					$include = FALSE;
					break;
				}
			}
			if ($include) {
				$cleanedData[$key] = $setting;
			}
		}
		return $cleanedData;
	}

	private static function areAllSent($when, $sent) {
		foreach ($when as $type => $datetime) {
			$ts = self::transformToTS($datetime); 
			if (self::afterAllTimestamps($ts, $sent)) {
				return FALSE;
			}
		}
		return TRUE;
	}

	private static function loadData($settingName, $moduleOrMetadata, $hijackedField, $pid) {
		if ($moduleOrMetadata && !is_array($moduleOrMetadata)) {
			$setting = $moduleOrMetadata->getProjectSetting($settingName, $pid);
			if ($setting) {
				return $setting;
			} else {
				return array();
			}
		} else if ($moduleOrMetadata && is_array($moduleOrMetadata)) {
			foreach ($moduleOrMetadata as $row) {
				if ($row['field_name'] == $hijackedField) {
					$json = $row['field_annotation'];
					if ($json) {
						$setting = json_decode($json, true);
						if ($setting) {
							return $setting;
						}
					}
					return array();
				}
			}
			throw new \Exception("Could not find field in metadata: '".$hijackedField."'");
		} else {
			throw new \Exception("Could not load value from $settingName! No module available!");
		}
	}

	public static function findRecordCreateDate($pid, $record) {
		$logEventTable = method_exists('\REDCap', 'getLogEventTable') ? \REDCap::getLogEventTable(pid) : "redcap_log_event";
		if (!function_exists("db_query")) {
			require_once(dirname(__FILE__)."/../../../redcap_connect.php");
		}
		$sql = "SELECT ts FROM $logEventTable WHERE project_id = '".$pid."' AND pk='".db_real_escape_string($record)."' AND event='INSERT' ORDER BY log_event_id";
		$q = db_query($sql);
		if ($error = db_error()) {
			throw new \Exception("ERROR: ".$error);
		}
		$allTimestamps = array();
		while ($row = db_fetch_assoc($q)) {
			array_push($allTimestamps, $row['ts']);
		}
		asort($allTimestamps);

		if (count($allTimestamps) > 0) {
			$nodes = array();
			$ts = $allTimestamps[0];
			$i = 0;
			$len = 2;
			while ($i < strlen($ts)) {
				$sub = substr($ts, $i, $len);
				array_push($nodes, $sub);
				$i += $len;
			}
			if (count($nodes) >= 7) {
				$date = $nodes[0].$nodes[1]."-".$nodes[2]."-".$nodes[3]." ".$nodes[4].":".$nodes[5].":".$nodes[6];
				return $date;
			}
		}
		return FALSE;
	}

	private static function filterForTeams($field, $values, $redcapData, $queue) {
        $newQueue = array();
        if (in_array("", $values)) {
            # ANY option
            return $queue;
        }
        foreach ($values as $recordId) {
            foreach ($redcapData as $row) {
                if ($recordId == $row['record_id']) {
                    $teamRecords = array();
                    $team = preg_split("/\n/", $row[$field]);
                    foreach ($team as $line) {
                        if ($line) {
                            $items = preg_split("/\s*,\s*/", $line);
                            if (is_numeric($items[0])) {
                                $teamRecords[] = $items[0];
                            }
                        }
                    }
                    foreach ($teamRecords as $teamRecordId) {
                        if (in_array($teamRecordId, $queue) && !in_array($teamRecordId, $newQueue)) {
                            $newQueue[] = $teamRecordId;
                        }
                    }
                }
            }
        }
        return $newQueue;
    }

	private static function filterForField($field, $value, $redcapData, $queue) {
		$newQueue = array();
		foreach ($queue as $recordId) {
			foreach ($redcapData as $row) {
				if (is_array($value)) {
					if (empty($value)) {
						# select all
						array_push($newQueue, $recordId);
					} else if (($recordId == $row['record_id']) && in_array($row[$field], $value)) {
						array_push($newQueue, $recordId);
					}
				} else {
					if (($recordId == $row['record_id']) && ($row[$field] === $value)) {
						array_push($newQueue, $recordId);
					}
				}
			}
		}
		return $newQueue;
	}

	public function loadRealData() {
		if ($this->module) {
			$this->data = self::loadData($this->settingName, $this->module, $this->hijackedField, $this->pid);
		} else {
			$this->data = self::loadData($this->settingName, $this->metadata, $this->hijackedField, $this->pid);
		}
	}

	private function setupTestData() {
		$emailSetting = self::getBlankSetting();

		$surveys = $this->getSurveys();
		$firstSurvey = "";
		foreach ($surveys as $form => $title) {
			$firstSurvey = $form;
		}

		$oneDayLater = date("Y-m-d H:i", time() + 24 * 3600);

		$testEmailAddress = "scott.j.pearson@vumc.org";
		$emailSetting["who"]["individuals"] = array($testEmailAddress);
		$emailSetting["who"]["from"] = $testEmailAddress;
		$emailSetting["what"]["message"] = "Last Name: [last_name]<br>Full Name: [name]<br>Link: [survey_link_$firstSurvey]"; 
		$emailSetting["what"]["subject"] = "Test Subject";
		$emailSetting["when"]["initial_time"] = $oneDayLater;

		$this->data = array();
		$this->data['test'] = $emailSetting;
	}

	public function prepareEmail_test($tester) {
		// function prepareEmail($emailSetting, $settingName, $whenType, $toField = "who")
		$this->setupTestData();
		$settingName = "test";
		$emailData = $this->prepareEmail($this->data[$settingName], $settingName, "initial_time"); 
		if (!empty($emailData)) {
			$emailMessages = $emailData["mssgs"];
			$tester->assertNotEqual(count($emailMessages), 0);
			$tester->assertTrue(isset($emailData["from"]), "from ".json_encode($emailData["from"]));
			$this->checkEmailData($tester, $emailData, "prepareEmail_test");
			$this->checkEmailMessages($tester, $emailData["mssgs"], "prepareEmail_test");
		}

		$this->loadRealData();
		foreach ($this->data as $settingName => $emailSetting) {
			$emailData = $this->prepareEmail($emailSetting, $settingName, "initial_time"); 
			if (!empty($emailData)) {
				$emailMessages = $emailData["mssgs"];
				$tester->tag("Email Messages not zero");
				$tester->assertNotEqual(count($emailMessages), 0);
				$tester->tag("From correct");
				$tester->assertTrue(isset($emailData["from"]), "from ".json_encode($emailData["from"]));
				$this->checkEmailData($tester, $emailData, "prepareEmail_test");
			}
		}
	}

	public function checkEmailMessages($tester, $emailMessages, $referringFunc) {
		$res = array(
				"/^Last Name: [^\[]+/",
				"/^Full Name: [^\[]+/",
				"/^Link: [^\[]+/",
				);
		foreach ($emailMessages as $recordId => $mssg) {
			$lines = preg_split("/<br>/", $mssg);
			foreach ($res as $re) {
				$found = FALSE;
				foreach ($lines as $line) {
					if (preg_match($re, $line)) {
						$found = TRUE;
						break;
					}
				}
				$tester->assertTrue($found, "Record $recordId: ".$re." ".implode("-BR-", $lines)." ".$referringFunc);
			}
		}
	}

	public function checkEmailData($tester, $emailData, $referringFunc) {
		$tester->tag("to=mssgs");
		$tester->assertEqual(array_keys($emailData["mssgs"]), array_keys($emailData["to"]));
		$tester->tag("subjects=mssgs");
		$tester->assertEqual(array_keys($emailData["mssgs"]), array_keys($emailData["subjects"]));
		foreach ($emailData["mssgs"] as $recordId => $mssg) {
			$tester->assertTrue(isset($emailData["subjects"][$recordId]), "Record $recordId subject $referringFunc");
			$tester->assertTrue(isset($emailData["to"][$recordId]), "Record $recordId to $referringFunc");
		} 
	}

	public function prepareOneEmail_test($tester) {
		// function prepareOneEmail($emailSetting, $settingName, $whenType, $toField = "who")
		$this->setupTestData();
		$settingName = "test";
		$emailData = $this->prepareOneEmail($this->data[$settingName], $settingName, "initial_time"); 
		if (!empty($emailData)) {
			$tester->assertEqual(count($emailData["subjects"]), 1);
			$tester->assertEqual(count($emailData["to"]), 1);
			$tester->assertEqual(count($emailData["mssgs"]), 1);
			$this->checkEmailData($tester, $emailData, "prepareOneEmail_test");
		}
	}

	public function getMessages_test($tester) {
		// function getMessages($what, $recordIds, $names, $lastNames)
		$this->setupTestData();
		$settingName = "test";
		$emailSetting = $this->data[$settingName];
		$recordIds = Download::recordIds($this->token, $this->server);
		$names = Download::names($this->token, $this->server);
		$lastNames = Download::lastnames($this->token, $this->server);
		$tester->assertEqual(count($names), count($lastNames));
		$tester->assertEqual(count($names), count($recordIds));
		$messages = $this->getMessages($emailSetting["what"], $recordIds, $names, $lastNames);
		$tester->assertEqual(count($messages), count($recordIds));

		$this->checkEmailMessages($tester, $messages, "getMessages_test");

		$firstRecordId = array($recordIds[0]);
		$messages2 = $this->getMessages($emailSetting["what"], $firstRecordId, $names, $lastNames);
		$tester->assertEqual(count($messages2), count($firstRecordId));

		$this->loadRealData();
		foreach ($this->data as $settingName => $emailSetting) {
			$rows = $this->getRows($emailSetting["who"]);
                	$messages = $this->getMessages($emailSetting["what"], array_keys($rows), $names, $lastNames);
			$tester->tag($settingName." messages from ".json_encode($emailSetting["who"]));
                	$tester->assertEqual(count($messages), count($rows));
		}
	}

	public function enqueueRelevantEmails_test($tester) {
		// function enqueueRelevantEmails($to = "", $names = array(), $func = "sendEmail", $currTime = FALSE)
		$this->setupTestData();
		$settingName = "test";
		$invalidSettingName = "invalid_name";
		$testEmail = "scott.j.pearson@vumc.org";

		$testResults = $this->enqueueRelevantEmails($testEmail, array($settingName), "prepareEmail");
		$incorrectResults = $this->enqueueRelevantEmails($testEmail, array($invalidSettingName), "prepareEmail");

		if (!empty($testResults)) {
			$tester->assertTrue(isset($testResults[$settingName]), "$settingName is/isn't set! ".json_encode($testResults));
			$tester->assertTrue(isset($testResults[$settingName]["initial_time"]), "$settingName's initial_time is/isn't set!");
		}
		$tester->assertTrue(!isset($incorrectResults[$invalidSettingName]), "$invalidSettingName is/isn't improperly set! ".json_encode($incorrectResults));

		# test timing feature
		$settingTs = strtotime($this->data[$settingName]["when"]["initial_time"]);
		$inOneMinuteTs = strtotime($settingName["when"]["initial_time"]) + 60;
		$inFiveMinutesTs = strtotime($settingName["when"]["initial_time"]) + 300;
		$nowResults = $this->enqueueRelevantEmails("", array($settingName), "prepareEmail", time());
		$launchResults = $this->enqueueRelevantEmails("", array($settingName), "prepareEmail", $settingTs);
		$oneMinResults = $this->enqueueRelevantEmails("", array($settingName), "prepareEmail", $inOneMinuteTs);
		$fiveMinsResults = $this->enqueueRelevantEmails("", array($settingName), "prepareEmail", $inFiveMinutesTs);
		if (!empty($nowResults)) {
			$tester->assertTrue(isset($nowResults[$settingName]), "results for now!");
		}
		if (!empty($launchResults)) {
			$tester->assertTrue(isset($launchResults[$settingName]), "results for launch time!");
		}
		if (!empty($oneMinResults)) {
			$tester->assertTrue(empty($oneMinResults[$settingName]), "results for launch time + one minute! ".json_encode($oneMinResults));
		}
		if (!empty($fiveMinsResults)) {
			$tester->assertTrue(empty($fiveMinsResults[$settingName]), "results for launch time + five minutes! ".json_encode($fiveMinsResults));
		}

		sleep(60);
		$nowResults = $this->enqueueRelevantEmails("", array($settingName), "prepareEmail", time());
		$launchResults = $this->enqueueRelevantEmails("", array($settingName), "prepareEmail", $settingTs);
		$inOneMinuteResults = $this->enqueueRelevantEmails("", array($settingName), "prepareEmail", $inOneMinuteTs);
		$inFiveMinsResults = $this->enqueueRelevantEmails("", array($settingName), "prepareEmail", $inFiveMinutesTs);
		if (!empty($nowResults)) {
			$tester->assertTrue(isset($nowResults[$settingName]), "results for now!");
		}
		if (!empty($launchResults)) {
			$tester->assertTrue(isset($launchResults[$settingName]), "results for launch time!");
		}
		if (!empty($inOneMinuteResults)) {
			$tester->assertTrue(empty($inOneMinuteResults[$settingName]), "results for launch time + 1 minute! ".json_encode($inOneMinuteResults));
		}
		if (!empty($inFiveMinsResults)) {
			$tester->assertTrue(empty($inFiveMinsResults[$settingName]), "results for launch time + 5 minutes! ".json_encode($inFiveMinsResults));
		}

		$sixtyDaysDuration = 60 * 24 * 3600;
		$currTime = time();
		$sendTs = strtotime($testResults[$settingName]["when"]["initial_time"]);
		for ($ts = $currTime; $ts < $currTime + $sixtyDaysDuration; $ts += 60) {
		    $results = $this->enqueueRelevantEmails("", array($settingName), "prepareEmail", $ts);
		    if (date("Y-m-d H:i", $sendTs) == date("Y-m-d H:i", $ts)) {
		        $tester->assertTrue(isset($results[$settingName]), "at set time, found results");
            } else {
                $tester->assertTrue(!isset($results[$settingName]), "at not set time, found no results");
            }
        }

	}

	public function getSurveyLinks_test($tester) {
		// static function getSurveyLinks($pid, $records, $instrument, $maxInstances = array())
		$repeating = $this->getRepeatingForms();
		$recordIds = Download::recordIds($this->token, $this->server);
		$tester->assertNotBlank(Application::link("emailMgmt/makeSurveyLinks.php"));
		foreach ($this->getSurveys() as $survey => $title) {
			if (in_array($survey, $repeating)) {
				$fields = array("record_id", $survey."_complete");
				$redcapData = Download::fields($this->token, $this->server, $fields);
				$maxInstances = array();
				foreach ($redcapData as $row) {
					if (!isset($maxInstances[$row['record_id']])) {
						$maxInstances[$row['record_id']] = 0;
					}
					if ($row['redcap_repeat_instance'] > $maxInstances[$row['record_id']]) {
						$maxInstances[$row['record_id']] = $row['redcap_repeat_instance'];
					}
				}
				$links = self::getSurveyLinks($this->pid, $recordIds, $survey, $maxInstances);
			} else {
				$links = self::getSurveyLinks($this->pid, $recordIds, $survey); 
			}
			foreach ($recordIds as $recordId) {
				$tester->assertTrue(isset($links[$recordId]), "Link is not set for Record $recordId ".json_encode($links));
				$tester->assertTrue($links[$recordId] !== "", "Link is blank for Record $recordId");
			}
		}
	}

	public function getRepeatingForms_test($tester) {
		$forms = $this->getRepeatingForms();
		$instruments = \REDCap::getInstrumentNames();
		foreach ($forms as $form) {
			$tester->assertIn($form, array_keys($instruments));
		}
	}

	public function getSurveys_test($tester) {
		$instruments = \REDCap::getInstrumentNames();
		$surveys = $this->getSurveys();
		$tester->assertTrue(count($surveys) > 0, "Number of surveys > 0 ".json_encode($surveys));
		$tester->assertTrue(count($instruments) >= count($surveys), "Number of instruments >= number of surveys");
		foreach ($surveys as $survey => $title) {
			$tester->assertIn($survey, array_keys($instruments));
		}
	}

	public function allSettings_test($tester) {
		$recordIds = Download::recordIds($this->token, $this->server);
		$this->loadRealData();
		foreach ($this->data as $name => $emailSetting) {
			$who = $emailSetting["who"];
			$rows = $this->getRows($who);

			if (($who['filter'] == "all") || ($who['recipient'] == "individuals")) {
				$tester->tag("Setting $name ".json_encode($who)." record count - might be unequal if one name not in database");
				$tester->assertEqual(count($recordIds), count($rows));
				$tester->tag("Setting $name rows not zero - might be zero if all names not in database");
				$tester->assertNotEqual(count($rows), 0);
			} else if ($who["individuals"]) {
				$checkedIndivs = $who["individuals"];
				$tester->tag("Setting $name ".json_encode($who)." count");
				$tester->assertEqual(count($checkedIndivs), count($rows));
				$tester->tag("Setting $name rows not zero");
				$tester->assertNotEqual(count($rows), 0);
			} else {
				# can't test
			}
		}
	}

	private $token;
	private $server;
	private $pid;
	private $metadata;
	private $hijackedField;
	private $module;
	private $settingName;
	protected $data;
}
