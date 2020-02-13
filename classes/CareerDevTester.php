<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/Download.php");
require_once(dirname(__FILE__)."/Scholar.php");
require_once(dirname(__FILE__)."/../Application.php");

class CareerDevTester {
	public function __construct($token, $server, $pid) {
		$this->metadata = Download::metadata($token, $server);
		$this->module = Application::getModule();
		$this->token = $token;
		$this->server = $server;
		$this->pid = $pid;
		$this->testRecordId = "99999";
	}

	public function vfrsImport_test($tester) {
		if (method_exists("\\Vanderbilt\\CareerDevLibrary\\Application", "getVFRSToken")) {
			$fields = array("participant_id", "name_last", "name_first");
			$firstNames = Download::firstnames($this->token, $this->server);
			$lastNames = Download::lastnames($this->token, $this->server);

			$vfrsToken = Application::getVFRSToken();
			$vfrsData = Download::fields($vfrsToken, "https://redcap.vanderbilt.edu/api/", $fields);
			$vfrsFirsts = array();
			$vfrsLasts = array();
			$vfrsPKs = array();
			foreach ($vfrsData as $row) {
				array_push($vfrsFirsts, $row['name_first']);
				array_push($vfrsLasts, $row['name_last']);
				array_push($vfrsPKs, $row['participant_id']);
			}
			$vfrsMatches = matchNames($vfrsFirsts, $vfrsLasts);    // Flight Tracker matches

			$key = array();
			$i = 0;
			foreach ($vfrsMatches as $recordId) {
				if ($recordId) {
					// $tester->tag("Check for duplicate VFRS matches with $recordId (participants ".$vfrsPKs[$i]." and ".$key[$recordId].")");
					// $tester->assertTrue(!isset($key[$recordId]));
					if (!isset($key[$recordId])) {
						$key[$recordId] = $vfrsPKs[$i];
					}
				}
				$i++;
			}

			$recordIds = Download::recordIds($this->token, $this->server);
			foreach ($recordIds as $recordId) {
				if (isset($key[$recordId]) && !$this->hasVFRSDataImported($recordId)) {
					# matched in database, but no data imported
					$tester->tag("No VFRS data imported but in VFRS database; checking if Record $recordId is < 2 weeks old");
					$tester->assertTrue(self::isLessThanXDaysOld($recordId, $this->pid));
				}
			}
		}
	}

	private function getVFRSFields() {
		$fields = array("record_id");
		foreach ($this->metadata as $row) {
			if (preg_match("/^vfrs_/", $row['field_name'])) {
				array_push($fields, $row['field_name']);
			}
		}
		return $fields;
	}

	private function hasVFRSDataImported($recordId) {
		$vfrsFields = $this->getVFRSFields();
		if (!empty($vfrsFields)) {
			$redcapData = Download::fieldsForRecords($this->token, $this->server, $vfrsFields, array($recordId));
			foreach ($redcapData as $row) {
				if ($row["vfrs_participant_id"]) {
					return TRUE;
				}
			}
		}
		return FALSE;
	}

	private static function isLessThanXDaysOld($recordId, $pid, $thresholdDays = 14) {
		$thresholdTs = time() - $thresholdDays * 24 * 3600;
		$thresholdREDCap = self::phpToREDCapTimestamp($thresholdTs);

		$sql = "SELECT ts FROM redcap_log_event WHERE ts > $thresholdREDCap AND project_id = ".db_real_escape_string($pid)." AND pk='".db_real_escape_string($recordId)."' AND description LIKE 'Create record%' LIMIT 1";
		$q = db_query($sql);
		if (db_num_rows($q) > 0) {
			return TRUE; 
		}
		return FALSE;
	}

	private static function phpToREDCapTimestamp($ts) {
		return date("YmdHis", $ts);
	}

	public function emailMgmtSmokeTest_test($tester) {
		$recordIds = Download::recordIds($this->token, $this->server);
		$mgr = new EmailManager($this->token, $this->server, $this->pid, $this->module, $this->metadata);
		$mgr->loadRealData();

		foreach ($mgr->getSettingsNames() as $name) {
			$emailSetting = $mgr->getItem($name);
			$tester->tag("$name who: ".json_encode($emailSetting["who"]));
			$tester->assertNotEmpty($emailSetting["who"]);
			$tester->tag("$name when: ".json_encode($emailSetting["when"]));
			$tester->assertNotEmpty($emailSetting["when"]);
			$tester->tag("$name what");
			$tester->assertNotEmpty($emailSetting["what"]);

			$who = $emailSetting["who"];
			$when = $emailSetting["when"];
			$rows = $mgr->getRows($who);

			$toSendTs = strtotime($when['initial_time']);
			if ($toSendTs < time()) {
				# in past
				$currTime = time();
				$daysToCheck = 21;
				for ($newTs = $currTime; $newTs < $currTime + $daysToCheck * 24 * 3600; $newTs += 60) {
					$tester->tag("$name: ".date("Y-m-d H:i", $newTs)." vs. ".date("Y-m-d H:i", $toSendTs));
					$tester->assertTrue(!$mgr->isReadyToSend($newTs, $toSendTs));
				}
			} else {
				# in future
				$oneMinuteAfter = $toSendTs + 60;
				$oneMinuteBefore = $toSendTs - 60;
				$tester->tag("$name after: ".date("Y-m-d H:i", $oneMinuteAfter)." vs. ".date("Y-m-d H:i", $toSendTs));
				$tester->assertTrue(!$mgr->isReadyToSend($oneMinuteAfter, $toSendTs));
				$tester->tag("$name before: ".date("Y-m-d H:i", $oneMinuteBefore)." vs. ".date("Y-m-d H:i", $toSendTs));
				$tester->assertTrue(!$mgr->isReadyToSend($oneMinuteBefore, $toSendTs));

				$oneOff = $toSendTs + 1;
				if (date("Y-m-d H:i", $oneOff) != date("Y-m-d H:i", $toSendTs)) {
					$oneOff = $toSendTs - 1;
				}
				$tester->tag("$name one off: ".date("Y-m-d H:i", $oneOff)." vs. ".date("Y-m-d H:i", $toSendTs));
				$tester->assertTrue($mgr->isReadyToSend($oneOff, $toSendTs));
			}

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
			} else if ($who['filter'] == "some") {
				# $who['filter'] == "some"
				$names = $mgr->getNames($who);
				$emails = $mgr->getNames($who);

				$tester->tag("Setting $name count(names) == count(rows)");
				$tester->assertEqual(count($names), count($rows));
				$tester->tag("Setting $name count(emails) == count(rows)");
				$tester->assertEqual(count($emails), count($rows));
				foreach ($names as $recordId => $indivName) {
					$tester->tag("Record $recordId is in recordIds");
					$tester->assertIn($recordId, $recordIds);
				}
			} else {
				$tester->assertBlank("Illegal Who ".json_encode($who));
			} 
		}

	}

	private $pid;
	private $token;
	private $server;
	private $module;
	private $metadata;
	private $testRecordId;
}
