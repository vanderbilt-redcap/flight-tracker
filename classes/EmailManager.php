<?php

namespace Vanderbilt\CareerDevLibrary;


require_once(dirname(__FILE__)."/Download.php");
require_once(dirname(__FILE__)."/Upload.php");

class EmailManager {
	public function __construct($token, $server, $pid, $module = NULL, $metadata = array()) {
		$this->token = $token;
		$this->server = $server;
		$this->pid = $pid;
		$this->metadata = $metadata;

		$this->settingName = "prior_emails";
		$this->module = $module;
		$this->data = self::loadData($this->settingName, $this->module);
	}

	public static function getFormat() {
		return "m-d-Y H:i A";
	}

	public function deleteEmail($name) {
		if (isset($this->data[$name])) {
			unset($this->data[$name]);
		}
	}

	public function getItem($name) {
		if (isset($this->data[$name])) {
			return $this->data[$name];
		}
		return self::getBlankSetting();
	}

	public static function getBlankSetting() {
		return array("who" => array(), "what" => array(), "when" => array());
	}

	# default is all names and to the actual 'to' emails
	# $to, if specified, denotes a test email
	public function prepareRelevantEmails($to = "", $names = array()) {
		return $this->enqueueRelevantEmails($to, $names, "prepareEmail");
	}

	public function sendPreparedEmails($messages, $isTest = FALSE) {
		if (!is_array($messages)) {
			$messages = json_decode($messages, TRUE);
		}
		if ($messages) {
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
		if (!preg_match("/[Mm]$/", $datetime)) {
			$datetime = $datetime."M";
		}

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

	private function enqueueRelevantEmails($to = "", $names = array(), $func = "sendEmail") {
		if (!is_array($names)) {
			$names = array($names);
		}
		$results = array();
		foreach ($this->data as $name => $emailSetting) {
			if (in_array($name, $names) || empty($names)) {
				$results[$name] = array();
				$when = $emailSetting["when"];
				$sent = $emailSetting["sent"];
				foreach ($when as $type => $datetime) {
					$ts = self::transformToTS($datetime);
					$result = FALSE;
					if ($to) {
						# This is a test email because a $to is specified
						$result = $this->$func($emailSetting, $to);
					} else {
						if (self::afterAllTimestamps($ts, $sent) && ($ts > time())) {
							$result = $this->$func($emailSetting);
						}
					}
					if ($result) {
						$results[$name][$type] = $result;
					}
				}
			}
		}
		return $results;
	}

	public function saveSetting($name, $emailSetting) {
		if (!isset($emailSetting["who"]) || !isset($emailSetting["what"]) || !isset($emailSetting["when"])) {
			throw new \Exception("Email setting invalid! A who, what, and when must be specified.");
		}
		$this->data[$name] = $emailSetting;    // overwrites if previously exist
		$this->saveData();
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
	private function sendEmail($emailSetting, $toField = "who") {
		$emailData = $this->prepareEmail($emailSetting, $toField);
		return $this->sendPreparedEmail($emailData, ($toField != "who"));
	}

	# returns records of emails
	private function sendPreparedEmail($emailData, $isTest = FALSE) {
		$name = $emailData["name"];
		$mssgs = $emailData["mssgs"];
		$to = $emailData["to"];
		$from = $emailData["from"];
		$subjects = $emailData["subjects"];

		foreach ($mssgs as $recordId => $mssg) {
			error_log(date("Y-m-d h:i:s")." $recordId: EmailManager sending to {$to[$recordId]}; from $from; {$subjects[$recordId]}");
			\REDCap::email($to[$recordId], $from, $subjects[$recordId], $mssg);
			usleep(200000); // wait 0.2 seconds for other items to process
		}
		$records = array_keys($mssgs);
		if (!$isTest) {
			if (!isset($this->data[$name]['who']['sent'])) {
				$this->data[$name]['who']['sent'] = array();
			}
			$sentAry = array("ts" => time(), "records" => $records);
			array_push($this->data[$name]['who']['sent'], $sentAry);
		}
		return $records;
	}

	private function prepareEmail($emailSetting, $toField = "who") {
		$data = array();
		$rows = $this->getRows($emailSetting["who"]);
		$emails = self::processEmails($rows);
		$names = self::processNames($rows);
		$subject = self::getSubject($emailSetting["what"]);
		$data['mssgs'] = $this->getMessages($emailSetting["what"], array_keys($rows), $names);
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
		return array_keys($this->data);
	}

	public function getNames($who) {
		$rows = $this->getRows($who);
		return self::processNames($rows);
	}

	public function getEmails($who) {
		$rows = $this->getRows($who);
		return self::processEmails($rows);
	}

	private function getMessages($what, $recordIds, $names) {
		$token = $this->token;
		$server = $this->server;

		$mssg = $what["message"];

		$mssgs = array();
		foreach ($recordIds as $recordId) {
			$mssgs[$recordId] = $mssg;
		}

		$repeatingForms = $this->getRepeatingForms();

		foreach ($recordIds as $recordId) {
			while (preg_match("/\[survey_link_(.+?)\]/", $mssgs[$recordId], $matches)) {
				$formName = $matches[1];
				if (in_array($formName, $repeatingForms)) {
					$redcapData = Download::formForRecords($token, $server, $formName, array($recordId));
					error_log("Got REDCap data for record $recordId and form $formName: ".count($redcapData)." rows");
					$maxInstance = 0;
					foreach ($redcapData as $row) {
						if (($row['redcap_repeat_instrument'] == $formName) && ($row['redcap_repeat_instance'] > $maxInstance)) {
							$maxInstance = $row['redcap_repeat_instance'];
						}
					}
					$surveyLink = \REDCap::getSurveyLink($recordId, $formName, NULL, ($maxInstance + 1));
				} else {
					$surveyLink = \REDCap::getSurveyLink($recordId, $formName);
				}

				error_log("$recordId: Got $formName's surveyLink: $surveyLink");
				if ($surveyLink) {
					$mssgs[$recordId] = str_replace("[survey_link_".$formName."]", $surveyLink, $mssgs[$recordId]);
				}
			}
			if (preg_match("/\[name\]/", $mssgs[$recordId])) {
				if ($names[$recordId]) {
					$mssgs[$recordId] = str_replace("[name]", $names[$recordId], $mssgs[$recordId]);
				}
			}
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
		$pid = $this->pid;
		$sql = "SELECT form_name, title FROM redcap_surveys WHERE project_id = '".$pid."'";
		$q = db_query($sql);
		$forms = array();
		while ($row = db_fetch_assoc($q)) {
			# filter out original survey; this is a REDCap bug
			if ($row['form_name'] !== "check_professional_characteristics") {
				$forms[$row['form_name']] = $row['title'];
			}
		}
		return $forms;
	}

	private function getRepeatingForms() {
		$pid = $this->pid;

		$sql = "SELECT DISTINCT(r.form_name) AS form_name FROM redcap_events_metadata AS m INNER JOIN redcap_events_arms AS a ON (a.arm_id = m.arm_id) INNER JOIN redcap_events_repeat AS r ON (m.event_id = r.event_id) WHERE a.project_id = '$pid'";
		$q = db_query($sql);
		if ($error = db_error()) {
			error_log("ERROR: ".$error);
			throw new \Exception("ERROR: ".$error);
		}
		$repeatingForms = array();
		while ($row = db_fetch_assoc($q)) {
			array_push($repeatingForms, $row['form_name']);
		}
		return $repeatingForms;
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

	private function getRows($who) {
		if (($who['filter'] == "all") || ($who['recipient'] == "individuals")) {
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
		} else if ($who['individuals']) {
			# checked off emails, specified in who
			$pickedEmails = explode(",", strtolower($who['individuals']));
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
		} else {
			# filter == some
			$fields = array("record_id", "identifier_first_name", "identifier_last_name", "identifier_email", "summary_ever_r01_or_equiv");
			$surveys = $this->getSurveys();
			foreach ($surveys as $form => $title) {
				array_push($fields, $form."_complete");
				array_push($fields, self::getDateField($form));
			}

			# structure data
			$redcapData = Download::fields($this->token, $this->server, $fields);
			$identifiers = array();
			$lastUpdate = array();
			$complete = array();
			$converted = array();
			foreach ($redcapData as $row) {
				$recordId = $row['record_id'];
				if ($row['redcap_repeat_instrument'] == "") {
					$converted[$recordId] = $row['summary_ever_r01_or_equiv'];
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

			$queue = array_keys($identifiers);
			if ($who['last_complete']) {
				$queue = self::filterForMonths($who['last_complete'], $lastUpdate, $queue);
			}
			if ($who['none_complete']) {
				$queue = self::filterForNoComplete($lastUpdate, $complete, $queue);
			}
			if ($who['converted']) {
				$queue = self::filterForConverted($who['converted'], $converted, $queue);
			}
			if ($who['max_emails']) {
				$created = array();
				foreach ($queue as $recordId) {
					$createDate = self::findRecordCreateDate($this->pid, $recordId);
					if ($createDate) {
						$created[$recordId] = strtotime($createDate);
					}
				}
				$queue = $this->filterForMaxEmails($who['max_emails'], $created, $queue);
			}

			# build row of names and emails
			$rows = array();
			foreach ($queue as $recordId) {
				$rows[$recordId] = $identifiers[$recordId];
			}
			return $rows;
		}
	}

	# not static because requires access to sent dates in $this->data
	private function filterForMaxEmails($maxEmails, $created, $queue) {
		$newQueue = array();
		foreach ($queue as $recordId) {
			$createdTs = $created[$recordId];
			$numEmailsSent = 0;
			foreach ($this->data as $name => $emailSetting) {
				# must have been applicable for email
				if ($emailSetting['who'] && $emailSetting['who']['sent']) {
					foreach ($emailSetting['who']['sent'] as $sentAry) {
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
		if ($this->module) {
			return $this->module->setProjectSetting($settingName, $this->data);
		} else {
			throw new \Exception("Could not save settings to $settingName! No module available!");
		}
	}

	private static function loadData($settingName, $module) {
		if ($module) {
			$setting = $module->getProjectSetting($settingName);
			if ($setting) {
				return $setting;
			} else {
				return array();
			}
		} else {
			throw new \Exception("Could not load value from $settingName! No module available!");
		}
	}

	public static function findRecordCreateDate($pid, $record) {
		$sql = "SELECT ts FROM redcap_log_event WHERE project_id = '".$pid."' AND pk='".db_real_escape_string($record)."' AND event='INSERT' ORDER BY ts ASC LIMIT 1";
		$q = db_query($sql);
		if ($error = db_error()) {
			throw new \Exception("ERROR: ".$error);
		}
		while ($row = db_fetch_assoc($q)) {
			$nodes = array();
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

	private $token;
	private $server;
	private $pid;
	private $metadata;
	private $module;
	private $settingName;
	protected $data;
}
