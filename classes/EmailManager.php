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

        $this->preparingMins = Application::getWarningEmailMinutes($pid);
        $adminEmail = Application::getSetting("admin_email", $pid);
        if (!$adminEmail) {
            global $adminEmail;
        }
        $this->adminEmail = $adminEmail;
        $defaultFrom = Application::getSetting("default_from", $pid);
        if (!$defaultFrom) {
            $defaultFrom = "noreply.flighttracker@vumc.org";
        }
        $this->defaultFrom = $defaultFrom;

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

	public static function makeEmailSetting($datetimeToSend, $to, $from, $subject, $message, $isEnabled = FALSE) {
	    $setting = self::getBlankSetting();
        $setting["who"]["individuals"] = $to;
        $setting["who"]["from"] = $from;
        $setting["what"]["message"] = $message;
        $setting["what"]["subject"] = $subject;
        $setting["when"]["initial_time"] = $datetimeToSend;
        $setting['enabled'] = $isEnabled;

	    return $setting;
    }

	public static function getBlankSetting() {
		return ["who" => [], "what" => [], "when" => [], "enabled" => FALSE];
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

	private static function transformToTS($datetime) {
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
        $logHeader = "Flight Tracker Email Manager";
		$results = [];
		if ($currTime) {
		    $currTimes = [$currTime];
        } else {
		    $currTimes = [];
		    $currTime = $_SERVER['REQUEST_TIME'];
		    $lastRunTs = Application::getSetting("emails_last_run", $this->pid);
		    if ($lastRunTs) {
                if ($currTime > $lastRunTs) {
                    # turns away older crons who are just reappearing
                    $minsSinceEpochCurr = floor($currTime / 60);
                    $minsSinceEpochLastRun = floor($lastRunTs / 60);
                    $oneHourInMinutes = 60;
                    if ($minsSinceEpochCurr - $minsSinceEpochLastRun > $oneHourInMinutes) {
                        $minsSinceEpochLastRun = $minsSinceEpochCurr - $oneHourInMinutes;
                    }
                    for ($mins = $minsSinceEpochCurr; $mins > $minsSinceEpochLastRun; $mins--) {
                        $currTimes[] = $mins * 60;
                    }
                    try {
                        Application::saveSetting("emails_last_run", $currTime, $this->pid);
                    } catch (\Exception $e) {
                        Application::log("Exception ".$e->getMessage()."\n".$e->getTraceAsString(), $this->pid);
                        sleep(1);
                        Application::saveSetting("emails_last_run", $currTime, $this->pid);
                    }
                    if (Application::isVanderbilt() && $lastRunTs) {
                        foreach ($currTimes as $time) {
                            if ($time <= $lastRunTs) {
                                $mssg = "Tried to run Flight Tracker's email cron at an earlier time. This should never happen. The time requested is ".date("Y-m-d H:i", (int) $time).". The last run was at ".date("Y-m-d H:i", $lastRunTs).".";
                                \REDCap::email("scott.j.pearson@vumc.org", "noreply.flighttracker@vumc.org", Application::getProgramName()." Running out of order", $mssg);
                            }
                        }
                    }
                }
            } else {
		        $currTimes = [$currTime];
                Application::saveSetting("emails_last_run", $currTime, $this->pid);
            }
		}

        $oneMinute = 60;
		$sentEmails = [];
		foreach ($this->data as $name => $emailSetting) {
			if (in_array($name, $names) || empty($names)) {
                // Application::log("Checking if $name is enabled");
				if ($emailSetting['enabled'] || ($func == "prepareEmail")) {
                    $when = $emailSetting["when"];
                    if (!Application::isLocalhost()) {
                        $whenKeys = array_keys($when);
                        // if (!empty($whenKeys)) {
                            // $firstKey = array_shift($whenKeys);
                            // foreach ($currTimes as $currTime) {
                                // Application::log("$name is enabled for send at ".$when[$firstKey].". Current time is ".date("Y-m-d H:i", (int) $currTime)."; process spawned at ".date("Y-m-d H:i", $_SERVER['REQUEST_TIME']), $this->pid);
                            // }
                        // }
                    }
					foreach ($when as $type => $datetime) {
						$ts = self::transformToTS($datetime);
						$result = FALSE;
						if ($to) {
							# This is a test email because a $to is specified
							if ($type == "initial_time") {
								$result = $this->$func($emailSetting, $name, $type, $to);
                                $sentEmails[$name] = time();
							}
						} else {
							if ($this->isReadyToSend($ts, $currTimes)) {
							    if (!Application::isLocalhost()) {
                                    Application::log("Sending $name scheduled at ".date("Y-m-d H:i", $ts), $this->pid);
                                }
                                $result = $this->$func($emailSetting, $name, $type);
							    $sentEmails[$name] = time();
							} else if ($this->isReadyToSend($ts - $this->preparingMins * $oneMinute, $currTimes)) {
                                $this->sendPreviewEmail($emailSetting, $name);
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
        if (Application::isVanderbilt()) {
            # log notes will break on localhost because $_GET['test'] is enabled for verbose output
            $format = "Y-m-d H:i";
            foreach ($sentEmails as $sendName => $ts) {
                if (!Application::isLocalhost()) {
                    Application::log("$logHeader: $sendName sent at ".date($format, $ts).".", $this->pid);
                }
            }
            if (!empty($sentEmails)) {
                foreach ($currTimes as $currTime) {
                    if (!Application::isLocalhost()) {
                        Application::log("$logHeader: Sending emails for " . date($format, (int)$currTime) . "; process spawned at " . date($format, $_SERVER['REQUEST_TIME']), $this->pid);
                    }
                }
            }
        }
        return $results;
	}

    public function sendPreviewEmail($emailSetting, $name) {
        $to = $this->adminEmail;
        $from = $this->defaultFrom ?: "noreply.flighttracker@vumc.org";
        $subject = self::getSubject($emailSetting["what"]);
        $link = Application::link("emailMgmt/cancelEmail.php", $this->pid, TRUE)."&name=".urlencode($name);
        $oneMinute = 60;
        $sendDateTime = $emailSetting["when"]["initial_time"] ?: time() + $this->preparingMins * $oneMinute;

        $sendInfo = $this->getRows($emailSetting["who"]);
        $emailAddresses = [];
        foreach ($sendInfo as $row) {
            if ($row['first_name'] && $row['last_name']) {
                $name = $row['first_name']." ".$row['last_name'];
                $emailAddresses[] = $name." &lt;".$row['email']."&gt;";
            } else {
                $emailAddresses[] = $row['email'];
            }
        }
        $emailFrom = $emailSetting["who"]["from"] ?: "<strong>[BLANK]</strong>";

        $mssg = "<h1>Preparing Email</h1>";
        $mssg .= "<style>
a.button { font-weight: bold; background-image: linear-gradient(45deg, #fff, #ddd); color: black; text-decoration: none; padding: 5px 20px; text-align: center; font-size: 24px; };
</style>";
        $mssg .= "<p>A version of the below email will be sent at $sendDateTime (".$this->preparingMins." minutes from the time that this email was sent) <strong>unless you cancel it below.</strong></p>";
        $mssg .= "<p><a class='button' href='$link'>Cancel Email Now</a> (before $sendDateTime)</p>";
        $mssg .= "<p>You must be a user on the Flight Tracker/REDCap project to cancel this email.</p>";
        $mssg .= "<p>Doing nothing will cause the email to send automatically at $sendDateTime.</p>";
        $mssg .= "<hr/>";
        $mssg .= "<p><strong>To (".count($emailAddresses)."):</strong><br/>".implode("<br/>", $emailAddresses)."<br/><strong>From:</strong> $emailFrom</p>";
        $mssg .= $emailSetting["what"]["message"];

        Application::log(date("Y-m-d H:i:s")." EmailManager sending preparation email $name to {$to}; from $from; $subject", $this->pid);
        if (Application::isLocalhost()) {
            \REDCap::email("scott.j.pearson@vumc.org", $from, "PREPARING EMAIL: $subject", $mssg);
        } else {
            \REDCap::email($to, $from, "PREPARING EMAIL: $subject", $mssg);
        }
    }

	public function isReadyToSend($ts1, $arrayOfTs) {
	    $minutesSinceEpoch1 = floor($ts1 / 60);
	    foreach ($arrayOfTs as $ts2) {
	        $minutesSinceEpoch2 = floor($ts2 / 60);
            if ($minutesSinceEpoch1 == $minutesSinceEpoch2) {
                return TRUE;
            }
        }
	    return FALSE;
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

	private static function filterOutSettingsWithPrefix($names, $prefix) {
	    $newNames = [];
	    foreach ($names as $name) {
            if (strpos($name, $prefix) === FALSE) {
                $newNames[] = $name;
            }
        }
	    return $newNames;
    }

	public function getSelectForExistingNames($elemName, $settingName = "") {
		$names = $this->getSettingsNames();
		$names = self::filterOutSettingsWithPrefix($names, "MMA");

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

    public function disable($name) {
        $settingNames = $this->getSettingsNames();
        if (in_array($name, $settingNames)) {
            $emailSetting = $this->data[$name] ?? [];
            if (!empty($emailSetting)) {
                if ($emailSetting["enabled"]) {
                    $emailSetting["enabled"] = FALSE;
                    $this->saveSetting($name, $emailSetting);
                } else {
                    throw new \Exception("The email setting $name is already not enabled!");
                }
            } else {
                throw new \Exception("The email setting $name is empty!");
            }
        } else {
            throw new \Exception("Invalid email setting name $name!");
        }
    }

	# returns records of emails
	private function sendPreparedEmail($emailData, $isTest = FALSE) {
		$name = $emailData["name"];
		$mssgs = $emailData["mssgs"];
		$to = $emailData["to"];
		$from = $emailData["from"];
		$subjects = $emailData["subjects"];

		foreach ($mssgs as $recordId => $mssg) {
			Application::log(date("Y-m-d H:i:s")." $recordId: EmailManager sending $name to {$to[$recordId]}; from $from; {$subjects[$recordId]}", $this->pid);
			if (!class_exists("\REDCap") || !method_exists("\REDCap", "email")) {
				require_once(dirname(__FILE__)."/../../../redcap_connect.php");
			}
			if (!class_exists("\REDCap") || !method_exists("\REDCap", "email")) {
				throw new \Exception("Could not find REDCap class!");
			}

            if (Application::isLocalhost()) {
                \REDCap::email("scott.j.pearson@vumc.org", $from, $to[$recordId].": ".$subjects[$recordId], $mssg);
            } else {
                \REDCap::email($to[$recordId], $from, $subjects[$recordId], $mssg);
            }
			usleep(200000); // wait 0.2 seconds for other items to process
		}
		$records = array_keys($mssgs);
		if (!$isTest) {
			if (!isset($this->data[$name]['sent'])) {
				$this->data[$name]['sent'] = array();
			}
			if ($records) {
				$sentAry = array("ts" => time(), "records" => $records);
				$this->data[$name]['sent'][] = $sentAry;
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
			$sentAry = [];
			foreach ($this->data as $name => $setting) {
				if ($setting['sent']) {
					$sentAry[$name] = $setting['sent'];
				}
			}
            if (Application::isPluginProject() && Application::isCopiedProject($this->pid)) {
                $sourcePid = Application::getSourcePid($this->pid);
                $sourceData = self::loadData($this->settingName, $this->module, $this->hijackedField, $sourcePid);
                if (!empty($sourceData)) {
                    foreach ($sourceData as $name => $setting) {
                        if ($setting['sent']) {
                            $sentAry[$name] = $setting['sent'];
                        }
                    }
                }
            }
			return $sentAry; 
		} else if ($this->data[$settingName] && $this->data[$settingName]['sent']) {
			return $this->data[$settingName]['sent'];
		} else if (Application::isPluginProject() && Application::isCopiedProject($this->pid)) {
                $sourcePid = Application::getSourcePid($this->pid);
                $sourceData = self::loadData($this->settingName, $this->module, $this->hijackedField, $sourcePid);
                if (!empty($sourceData) && $sourceData[$settingName] && $sourceData[$settingName]['sent']) {
                    return $sourceData[$settingName]['sent'];
                }
            }
		return [];
	}

    private function getLastNames($recordIds) {
        $allLastNames = Download::lastnames($this->token, $this->server);
        $filteredLastNames = array();
        foreach ($recordIds as $recordId) {
            $filteredLastNames[$recordId] = $allLastNames[$recordId];
        }
        return $filteredLastNames;
    }

    private function getFirstNames($recordIds) {
        $allFirstNames = Download::firstnames($this->token, $this->server);
        $filteredFirstNames = array();
        foreach ($recordIds as $recordId) {
            $filteredFirstNames[$recordId] = $allFirstNames[$recordId];
        }
        return $filteredFirstNames;
    }

    private function getForms($what) {
		$forms = array();
		if (isset($what["message"])) {
			$mssg = $what["message"];
			$surveys = $this->getSurveys();
			foreach ($surveys as $survey) {
				if (preg_match("/\[survey_link_$survey\]/", $mssg)) {
					$forms[] = $survey;
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
			$recordId = FALSE;
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
        $firstNames = $this->getFirstNames(array_keys($rows));
		$subject = self::getSubject($emailSetting["what"]);
		$data['name'] = $settingName;
		$data['mssgs'] = $this->getMessages($emailSetting["what"], array_keys($rows), $names, $lastNames, $firstNames);
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
					$unmatchedKeys[] = $key;
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
		$url = Application::link("emailMgmt/makeSurveyLinks.php", $pid, TRUE)."&NOAUTH";
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, Upload::isProductionServer());
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
		$output = curl_exec($ch);
		curl_close($ch);
		if ($returnList = json_decode((string) $output, TRUE)) {
			return $returnList;
		} else {
		    Application::log($url);
		    Application::log("Warning! Could not decode JSON: $output");
			return $output;
		}
	}

	private function getMessages($what, $recordIds, $names, $lastNames, $firstNames) {
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
			$fields[] = $form . "_complete";
			$fields[] = self::getDateField($form);
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
					$formRecords[$formName][] = $recordId;
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
				$surveyLink = "NOT FOUND: ".json_encode($surveyLinks);
				if ($surveyLinks[$formName] && $surveyLinks[$formName][$recordId]) {
					$surveyLink = $surveyLinks[$formName][$recordId];
				}

				$mssgs[$recordId] = str_replace("[survey_link_".$formName."]", Links::makeLink($surveyLink, $surveyLink), $mssgs[$recordId]);
			}
            if (preg_match("/\[name\]/", $mssgs[$recordId])) {
                if ($names[$recordId]) {
                    $mssgs[$recordId] = str_replace("[name]", $names[$recordId], $mssgs[$recordId]);
                }
            }
            if (preg_match("/\[mentoring_agreement\]/", $mssgs[$recordId])) {
                $menteeLink = Application::getMenteeAgreementLink($this->pid);
                $mssgs[$recordId] = str_replace("[mentoring_agreement]", Links::makeLink($menteeLink, $menteeLink), $mssgs[$recordId]);
            }
            if (preg_match("/\[last_name\]/", $mssgs[$recordId])) {
                if ($lastNames[$recordId]) {
                    $mssgs[$recordId] = str_replace("[last_name]", $lastNames[$recordId], $mssgs[$recordId]);
                }
            }
            if (preg_match("/\[first_name\]/", $mssgs[$recordId])) {
                if ($lastNames[$recordId]) {
                    $mssgs[$recordId] = str_replace("[first_name]", $firstNames[$recordId], $mssgs[$recordId]);
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
				$pickedEmails[] = strtolower($who['individuals'][$i]);
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
		$fields = array("record_id", "summary_training_start", "summary_training_end", "identifier_first_name", "identifier_last_name", "identifier_email", "summary_ever_r01_or_equiv");
		$surveys = $this->getSurveys();
        $steps = [];
        $steps["surveys"] = $surveys;
        $steps["metadata"] = $this->metadata;
		foreach ($surveys as $form => $title) {
			$fields[] = $form . "_complete";
			$fields[] = self::getDateField($form);
		}
        $steps["fields 1"] = $fields;
		$fields = REDCapManagement::filterOutInvalidFields($this->metadata, $fields);

		# structure data
        $steps["fields 2"] = $fields;
		$redcapData = Download::fields($this->token, $this->server, $fields);
		$steps["redcapData"] = count($redcapData);
		$identifiers = [];
		$lastUpdate = [];
		$complete = [];
		$converted = [];
		$trainingEnds = [];
		$trainingStarts = [];
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
			    if ($row['summary_training_start']) {
                    $trainingStarts[$recordId] = $row['summary_training_start'];
                }
			    if ($row['summary_training_end']) {
                    $trainingEnds[$recordId] = $row['summary_training_end'];
                }
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
						$complete[$recordId][] = $currForm;
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
                $fieldsToDownload[] = $redcapField;
                $filtersToApply[] = $whoFilterField;
			}
		}
		if (!empty($fieldsToDownload)) {
			$fieldsToDownload[] = "record_id";
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
            $created = self::findRecordCreateDates($this->pid, $queue);
            # no change in queue
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
		if ($who['trainee_class']) {
            $queue = self::filterByTraineeClass($who['trainee_class'], $trainingStarts, $trainingEnds, $queue);
		    $steps["9"] = $queue;
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

	public static function getFieldForCurrentEmailSetting() {
	    return "existingName";
    }

	private static function filterByTraineeClass($traineeClass, $trainingStarts, $trainingEnds, $records) {
	    if ($traineeClass == "current") {
	        $currTs = time();
	        $filteredRecords = [];
	        foreach ($records as $recordId) {
                $include = FALSE;
	            if (isset($trainingStarts[$recordId])) {
	                $startTs = strtotime($trainingStarts[$recordId]);
	                if ($startTs <= $currTs) {
                        $include = TRUE;
                    }
                }
	            if ($include && isset($trainingEnds[$recordId])) {
	                $endTs = $trainingEnds[$recordId];
	                if ($endTs < $currTs) {
	                    $include = FALSE;
                    }
                }
	            if ($include) {
	                $filteredRecords[] = $recordId;
                }
            }
	        return $filteredRecords;
        } else  if ($traineeClass == "alumni") {
            $currTs = time();
            $filteredRecords = [];
            foreach ($records as $recordId) {
                $include = FALSE;
                if (isset($trainingEnds[$recordId])) {
                    $endTs = $trainingEnds[$recordId];
                    if ($endTs < $currTs) {
                        $include = TRUE;
                    }
                }
                if ($include) {
                    $filteredRecords[] = $recordId;
                }
            }
            return $filteredRecords;
        } else {
	        return $records;
        }
    }

	public function getRows($who, $whenType = "", $when = array(), $what = array()) {
		if (($who['filter'] == "all") || ($who['recipient'] == "individuals")) {
			$rows = $this->collectAllEmails();
		} else if ($who['individuals']) {
			$rows = $this->getAllCheckedEmails($who);
		} else if ($who['filter'] == "some") {
			$rows = $this->filterSome($who, $whenType, $when, $what);
		} else if (empty($who)) {
			return array();
		} else {
			throw new \Exception("Could not interpret who: ".json_encode($who));
		}
        if (!empty($this->metadata)) {
            $metadataFields = DataDictionaryManagement::getFieldsFromMetadata($this->metadata);
        } else {
            $metadataFields = Download::metadataFields($this->token, $this->server);
        }
        if (in_array("identifier_stop_collection", $metadataFields)) {
            $stops = Download::oneField($this->token, $this->server, "identifier_stop_collection");
            $newRows = [];
            foreach ($rows as $recordId => $row) {
                if (!isset($stops[$recordId]) || ($stops[$recordId] !== "1")) {
                    $newRows[$recordId] = $row;
                }
            }
            return $newRows;
        }
        return $rows;
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
			$createdTs = strtotime($created[$recordId]);
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

    private function changeWhoScopeForAnotherProject($emailSetting) {
        if (isset($emailSetting["who"]["individuals"])) {
            return $emailSetting;
        }

        $emails = [];
        $rows = $this->getRows($emailSetting["who"]);
        foreach ($rows as $recordId => $person) {
            if (isset($person['email']) && $person['email']) {
                $emails[] = $person['email'];
            }
        }

        $emailSetting["who"]["individuals"] = implode(",", $emails);
        unset($emailSetting["who"]["filter"]);
        return $emailSetting;
    }

	private function saveData() {
        $settingName = $this->settingName;
        $data = self::cleanUpEmails($this->data, $this->token, $this->server);
        if (Application::isPluginProject() && Application::isCopiedProject($this->pid)) {
            $savePid = Application::getSourcePid($this->pid);
            if ($savePid) {
                $combinedData = [];
                foreach ($data as $name => $emailSetting) {
                    $combinedData[$name] = $this->changeWhoScopeForAnotherProject($emailSetting);
                }
                $sourceData = self::loadData($settingName, $this->module, $this->hijackedField, $savePid);
                foreach ($sourceData as $name => $setting) {
                    if (!isset($combinedData[$name])) {
                        $combinedData[$name] = $setting;
                    }
                    # in case of conflict, prefer newer data ($data)
                }
            } else {
                $savePid = $this->pid;
                $combinedData = $data;
            }
        } else {
            $savePid = $this->pid;
            $combinedData = $data;
        }

        if ($this->module) {
            Application::log("Saving email data into $settingName");
            return $this->module->setProjectSetting($settingName, $combinedData, $savePid);
        } else if ($this->metadata) {
            $json = json_encode($data);
            $newMetadata = [];
            foreach ($this->metadata as $row) {
                if ($row['field_name'] == $this->hijackedField) {
                    $row['field_annotation'] = $json;
                }
                $newMetadata[] = $row;
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
			$when = $setting["when"] ?? [];
			$sent = $setting["sent"] ?? [];
			foreach ($recordIds as $recordId) {
				if ((Application::getEmailName($recordId) == $key)
					&& self::afterAllTimestamps($currTime, $sent)
					&& self::afterAllTimestamps($currTime, [$when])
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

	public static function findRecordCreateDates($pid, $records) {
	    $pullSize = 100;
	    $batchedRecords = [];
	    for ($i = 0; $i < count($records); $i += $pullSize) {
	        $batch = [];
	        for ($j = $i; $j < $i + $pullSize && $j < count($records); $j++) {
	            $batch[] = db_real_escape_string($records[$j]);
            }
	        if (!empty($batch)) {
                $batchedRecords[] = $batch;
            }
        }

		$logEventTable = method_exists('\REDCap', 'getLogEventTable') ? \REDCap::getLogEventTable(pid) : "redcap_log_event";
		if (!function_exists("db_query")) {
			require_once(dirname(__FILE__)."/../../../redcap_connect.php");
		}

        $allTimestamps = [];
		$createTimestamps = [];
		foreach ($batchedRecords as $batch) {
            $sql = "SELECT pk, ts, description FROM $logEventTable WHERE project_id = '".$pid."' AND pk IN ('".implode("','", $batch)."') AND event='INSERT' ORDER BY log_event_id";
            $q = db_query($sql);
            if ($error = db_error()) {
                throw new \Exception("ERROR: ".$error);
            }
            while ($row = db_fetch_assoc($q)) {
                if (!isset($allTimestamps[$row['pk']])) {
                    $allTimestamps[$row['pk']] = [];
                }
                $allTimestamps[$row['pk']][] = $row['ts'];

                if ($row['description'] == "Create record") {
                    if (!isset($createTimestamps[$row['pk']])) {
                        $createTimestamps[$row['pk']] = [];
                    }
                    $createTimestamps[$row['pk']][] = $row['ts'];
                }
            }
            foreach (array_keys($allTimestamps) as $recordId) {
                asort($allTimestamps[$recordId]);       // get earliest
                if (isset($createTimestamps[$recordId])) {
                    rsort($createTimestamps[$recordId]);   // get latest
                }
            }
        }

        $created = [];
        foreach (array_keys($allTimestamps) as $recordId) {
		    if (count($allTimestamps[$recordId]) > 0) {
                $nodes = [];
                $ts = isset($createTimestamps[$recordId]) ? $createTimestamps[$recordId][0] : $allTimestamps[$recordId][0];
                $i = 0;
                $len = 2;
                while ($i < strlen($ts)) {
                    $sub = substr($ts, $i, $len);
                    $nodes[] = $sub;
                    $i += $len;
                }
                if (count($nodes) >= 7) {
                    $date = $nodes[0] . $nodes[1] . "-" . $nodes[2] . "-" . $nodes[3] . " " . $nodes[4] . ":" . $nodes[5] . ":" . $nodes[6];
                    $created[$recordId] = $date;
                }
            }
		}
		return $created;
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
						$newQueue[] = $recordId;
					} else if (($recordId == $row['record_id']) && in_array($row[$field], $value)) {
						$newQueue[] = $recordId;
					}
				} else {
					if (($recordId == $row['record_id']) && ($row[$field] === $value)) {
						$newQueue[] = $recordId;
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

	private $token;
	private $server;
	private $pid;
	private $metadata;
	private $hijackedField;
	private $module;
	private $settingName;
	protected $data;
    private $adminEmail;
    private $defaultFrom;
    private $preparingMins;
}
