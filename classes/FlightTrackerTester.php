<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class FlightTrackerTester
{
	public function __construct($token, $server, $pid) {
		$this->metadata = Download::metadata($token, $server);
		$this->module = Application::getModule();
		$this->token = $token;
		$this->server = $server;
		$this->pid = $pid;
		$this->testRecordId = "99999";
	}

	private function getGoodFirstName() {
		return "Elvis";
	}

	private function getGoodLastName() {
		return "Akwo";
	}

	public function nameMatcher_test($tester) {
		$first = "Wrong";
		$last = "Name";
		$record = NameMatcher::matchName("Wrong", "Name", $this->token, $this->server);
		$tester->tag("Match $first $last");
		$tester->assertNotTrue($record);

		$data = NameMatcher::downloadNamesForMatch($this->token, $this->server);
		$tester->tag("downloadNamesForMatch numDataPoints");
		$tester->assertNotZero(count($data));

		$goodFirst = $this->getGoodFirstName();
		$goodLast = $this->getGoodLastName();
		$lasts = [$last, $goodLast];
		$firsts = [$first, $goodFirst];
		$records = NameMatcher::matchNames($firsts, $lasts, $this->token, $this->server);
		$tester->tag("number of records in array from matchNames");
		$tester->assertEqual(count($lasts), count($records));
		$tester->tag("Name {$firsts[0]} {$lasts[0]} matches ({$records[0]})".json_encode($records));
		$tester->assertNotTrue($records[0]);
		$tester->tag("Name {$firsts[1]} {$lasts[1]} matches ({$records[1]})");
		$tester->assertTrue($records[1]);
	}

	public function download_test($tester) {
		$recordIds = Download::recordIds($this->token, $this->server);
		$lastnames = Download::lastnames($this->token, $this->server);
		$firstnames = Download::firstnames($this->token, $this->server);
		$tester->tag("last names =? recordIds");
		$tester->assertEqual(count($recordIds), count($lastnames));
		$tester->tag("first names =? recordIds");
		$tester->assertEqual(count($recordIds), count($firstnames));

		$myMetadata = Download::metadata($this->token, $this->server);
		$tester->tag("metadata");
		$tester->assertEqual(count($myMetadata), count($this->metadata));
	}

	private static function assignRandomChoiceIndex($choices, $field) {
		$idx = rand(1, count($choices[$field]));
		$i = 1;
		foreach ($choices[$field] as $choiceIdx => $choiceLabel) {
			if ($i == $idx) {
				return $choiceIdx;
			}
			$i++;
		}
		throw new \Exception("Could not assign random for $idx and $field!");
	}

	public function importFromImported_test($tester) {
		require_once(dirname(__FILE__)."/../drivers/6d_makeSummary.php");

		$numTests = 10;

		for ($i = 1; $i <= $numTests; $i++) {
			$choices = Scholar::getChoices($this->metadata);
			$randomTime = rand(1, time());
			$dob = date("Y-m-d", $randomTime);
			$gender = self::assignRandomChoiceIndex($choices, "imported_gender");
			$race = self::assignRandomChoiceIndex($choices, "imported_race");
			$eth = self::assignRandomChoiceIndex($choices, "imported_ethnicity");
			$citizenship = self::assignRandomChoiceIndex($choices, "imported_citizenship");
			$row = [
					"record_id" => $this->testRecordId,
					"redcap_repeat_instrument" => "",
					"redcap_repeat_instance" => "",
					"identifier_first_name" => "TEST-FIRST",
					"identifier_last_name" => "TEST-LAST",
					"identifier_email" => "noreply@vumc.org",
					"imported_dob" => $dob,
					"imported_gender" => $gender,
					"imported_race" => $race,
					"imported_ethnicity" => $eth,
					"imported_citizenship" => $citizenship,
					];
			$rows = [$row];

			$scholar = new Scholar($this->token, $this->server, $this->metadata, $this->pid);
			$scholar->setRows($rows);
			$scholar->process();
			$scholarDOB = $scholar->getDOB($rows)->getValue();
			$scholarGender = $scholar->getGender($rows)->getValue();
			$scholarCitizenship = $scholar->getCitizenship($rows)->getValue();

			$tester->tag("Scholar DOB same as imported");
			$tester->assertEqual($scholarDOB, $row['imported_dob']);

			$tester->tag("Scholar Gender same as imported");
			$tester->assertEqual($scholarGender, $row['imported_gender']);

			$tester->tag("Scholar Citizenship same as imported");
			$tester->assertEqual($scholarCitizenship, $row['imported_citizenship']);

			$scholarRaceEth = $scholar->getRaceEthnicity($rows)->getValue();
			$tester->tag("Scholar Race/Eth same as imported");
			$tester->assertNotBlank($scholarRaceEth);

			$newREDCapData = makeSummary($this->token, $this->server, $this->pid, $this->testRecordId, $rows);
			foreach ($newREDCapData as $newRow) {
				if ($newRow["redcap_repeat_instrument"] == "") {
					$tester->tag("Record ID Same ".json_encode($newRow));
					$tester->assertEqual($newRow["record_id"], $this->testRecordId);

					$tester->tag("DOB Equal to Summary");
					$tester->assertEqual($newRow["summary_dob"], $dob);
					$tester->tag("Imported DOB Equal to Summary");
					$tester->assertEqual($row["imported_dob"], $newRow["summary_dob"]);

					$tester->tag("Gender Equal to Summary");
					$tester->assertEqual($newRow["summary_gender"], $gender);
					$tester->tag("Imported Gender Equal to Summary");
					$tester->assertEqual($row["imported_gender"], $newRow["summary_gender"]);

					$tester->tag("Citizenship Equal to Summary");
					$tester->assertEqual($newRow["summary_citizenship"], $citizenship);
					$tester->tag("Imported Citizenship Equal to Summary");
					$tester->assertEqual($row["imported_citizenship"], $newRow["summary_citizenship"]);

					if ($eth == 1) {
						# Hispanic
						switch ($race) {
							case "1":
								$expectedRaceEth = "6";
								break;
							case "2":
								$expectedRaceEth = "5";
								break;
							case "3":
								$expectedRaceEth = "6";
								break;
							case "4":
								$expectedRaceEth = "4";
								break;
							case "5":
								$expectedRaceEth = "3";
								break;
							case "6":
								$expectedRaceEth = "6";
								break;
							case "7":
								$expectedRaceEth = "6";
								break;
							default:
								$expectedRaceEth = "";
								break;
						}
					} elseif ($eth == 2) {
						# Non-Hispanic
						switch ($race) {
							case "1":
								$expectedRaceEth = "6";
								break;
							case "2":
								$expectedRaceEth = "5";
								break;
							case "3":
								$expectedRaceEth = "6";
								break;
							case "4":
								$expectedRaceEth = "2";
								break;
							case "5":
								$expectedRaceEth = "1";
								break;
							case "6":
								$expectedRaceEth = "6";
								break;
							case "7":
								$expectedRaceEth = "6";
								break;
							default:
								$expectedRaceEth = "";
								break;
						}
					} else {
						$expectedRaceEth = "";
					}
					$tester->tag("Race/Ethnicity");
					$tester->assertEqual($expectedRaceEth, $newRow['summary_race_ethnicity']);
				}
			}
		}
	}

	public function importOtherData_test($tester) {
	}

	public function changeOrderOfMetadata_test($tester) {
	}

	public function CSVUpload_test($tester) {
		$metadata = Download::metadata($this->token, $this->server);
		$recordIds = Download::recordIds($this->token, $this->server);
		$maxRecordId = max($recordIds);

		$headers = ["record_id", "bad_field_name"];
		$data = [["1", "0"]];
		list($upload, $errors, $newCounts) = Upload::prepareFromCSV($headers, $data, $this->token, $this->server, $this->pid, $metadata);
		$tester->tag("Improper field name");
		$tester->assertNotEmpty($errors);

		$headers = ["record_id", "identifier_email"];
		$data = [["1", "joe.cool@vumc.org", "3"]];
		list($upload, $errors, $newCounts) = Upload::prepareFromCSV($headers, $data, $this->token, $this->server, $this->pid, $metadata);
		$tester->tag("column mismatch");
		$tester->assertNotEmpty($errors);

		$headers = ["record_id", "identifier_email", "followup_orcid_id"];
		$data = [["1", "joe.cool@vumc.org", "ABCDEFG"]];
		list($upload, $errors, $newCounts) = Upload::prepareFromCSV($headers, $data, $this->token, $this->server, $this->pid, $metadata);
		$tester->tag("multiple forms in same row");
		$tester->assertNotEmpty($errors);

		$headers = ["identifier_email"];
		$data = [["joe.cool@vumc.org"]];
		list($upload, $errors, $newCounts) = Upload::prepareFromCSV($headers, $data, $this->token, $this->server, $this->pid, $metadata);
		$tester->tag("no identifying information");
		$tester->assertNotEmpty($errors);

		$headers = ["record_id", "identifier_email"];
		$data = [["1", "joe.cool@vumc.org"]];
		list($upload, $errors, $newCounts) = Upload::prepareFromCSV($headers, $data, $this->token, $this->server, $this->pid, $metadata);
		$tester->tag("proper record_id errors");
		$tester->assertEmpty($errors);
		$tester->tag("proper record_id upload");
		$tester->assertNotEmpty($upload);
		$tester->tag("proper record_id existing record");
		$tester->assertLessThan($upload[0]["record_id"], $maxRecordId + 1);

		$headers = ["identifier_last_name", "identifier_first_name", "identifier_email"];
		$data = [[$this->getGoodLastName(), $this->getGoodFirstName(), "joe.cool@vumc.org"]];
		list($upload, $errors, $newCounts) = Upload::prepareFromCSV($headers, $data, $this->token, $this->server, $this->pid, $metadata);
		$tester->tag("proper matched names errors");
		$tester->assertEmpty($errors);
		$tester->tag("proper matched names upload");
		$tester->assertNotEmpty($upload);
		$tester->tag("proper matched names upload count");
		$tester->assertEqual(count($upload), count($data));
		$tester->tag("proper matched names existing record ".json_encode($upload));
		$tester->assertLessThan($upload[0]["record_id"], $maxRecordId + 1);
		list($recordId, $errors) = Upload::getRecordIdForCSVLine($headers, $data[0], 0, $this->token, $this->server);
		$tester->tag("proper matched names existing record: record_id's equal");
		$tester->assertEqual($upload[0]["record_id"], $recordId);
		$tester->tag("proper matched names existing record: matched");
		$tester->assertLessThan($recordId, $maxRecordId + 1);
		$tester->tag("proper matched names existing record: Errors empty");
		$tester->assertEmpty($errors);

		$headers = ["identifier_last_name", "identifier_first_name", "identifier_email"];
		$data = [[$this->getGoodLastName(), $this->getGoodFirstName(), "joe.cool@vumc.org"], ["Name", "New", "new.name@vumc.org"]];
		list($upload, $errors, $newCounts) = Upload::prepareFromCSV($headers, $data, $this->token, $this->server, $this->pid, $metadata);
		$tester->tag("mixed matched names and new names errors");
		$tester->assertEmpty($errors);
		$tester->tag("mixed matched names and new names upload");
		$tester->assertNotEmpty($upload);
		$tester->tag("mixed matched names and new names upload count");
		$tester->assertEqual(count($upload), count($data));
		$tester->tag("proper matched names existing record ".json_encode($upload[0]));
		$tester->assertLessThan($upload[0]["record_id"], $maxRecordId + 1);
		$tester->tag("proper matched names new record ".json_encode($upload[1]));
		$tester->assertGreaterThan($upload[1]["record_id"], $maxRecordId);

		$headers = ["identifier_last_name", "identifier_first_name", "followup_orcid_id"];
		$data = [[$this->getGoodLastName(), $this->getGoodFirstName(), "ABCDEFG"]];
		list($upload, $errors, $newCounts) = Upload::prepareFromCSV($headers, $data, $this->token, $this->server, $this->pid, $metadata);
		$tester->tag("proper repeatable instance (followup) errors");
		$tester->assertEmpty($errors);
		$tester->tag("proper repeatable instance (followup) upload");
		$tester->assertNotEmpty($upload);
		$tester->tag("proper repeatable instance (followup) upload count");
		$tester->assertEqual(count($upload), count($data));
		$tester->tag("proper repeatable instance (followup) existing record ".json_encode($upload[0]));
		$tester->assertLessThan($upload[0]["record_id"], $maxRecordId + 1);
		$tester->tag("proper repeatable instance (followup) repeat_instrument");
		$tester->assertNotBlank($upload[0]["redcap_repeat_instrument"]);
		$tester->tag("proper repeatable instance (followup) repeat_instance");
		$tester->assertNotBlank($upload[0]["redcap_repeat_instance"]);
	}

	public function getMaxInstanceForRepeatingForm_test($tester) {
		$numTestRecords = 10;

		$recordIds = Download::recordIds($this->token, $this->server);
		$testRecords = [];
		for ($i = 0; $i < $numTestRecords; $i++) {
			$idx = rand(0, count($recordIds));
			array_push($testRecords, $recordIds[$idx]);
		}
		foreach ($testRecords as $recordId) {
			$recordData = Download::records($this->token, $this->server, [$recordId]);

			$maxInstances = [];
			foreach ($recordData as $row) {
				if (isset($row['redcap_repeat_instance']) && isset($row['redcap_repeat_instrument'])) {
					if (!isset($maxInstances[$row['redcap_repeat_instrument']])) {
						$maxInstances[$row['redcap_repeat_instrument']] = 0;
					}
					if ($row['redcap_repeat_instance'] > $maxInstances[$row['redcap_repeat_instrument']]) {
						$maxInstances[$row['redcap_repeat_instrument']] = $row['redcap_repeat_instance'];
					}
				}
			}

			foreach ($maxInstances as $instrument => $expectedMaxInstance) {
				$functionMaxInstance = Download::getMaxInstanceForRepeatingForm($this->token, $this->server, $instrument, $recordId);
				$tester->tag("For Record $recordId, $instrument from getMaxInstanceForRepeatingForm");
				$tester->assertEqual($expectedMaxInstance, $functionMaxInstance);
			}
		}
	}

	public function emailMgmtSmokeTest_test($tester) {
		$recordIds = Download::recordIds($this->token, $this->server);
		$mgr = new EmailManager($this->token, $this->server, $this->pid, $this->module, $this->metadata);
		$mgr->loadRealData();

		foreach ($mgr->getSettingsNames() as $name) {
			$emailSetting = $mgr->getItem($name);
			$who = $emailSetting["who"];
			$rows = $mgr->getRows($who);

			if (($who['filter'] == "all") || ($who['recipient'] == "individuals")) {
				$tester->tag("Setting $name ".json_encode($who)." record count - might be unequal if one name not in database");
				$tester->assertEqual(count($recordIds), count($rows));
				$tester->tag("Setting $name rows not zero - might be zero if all names not in database");
				$tester->assertNotEqual(count($rows), 0);
			} elseif ($who["individuals"]) {
				$checkedIndivs = $who["individuals"];
				$tester->tag("Setting $name ".json_encode($who)." count");
				$tester->assertEqual(count($checkedIndivs), count($rows));
				$tester->tag("Setting $name rows not zero");
				$tester->assertNotEqual(count($rows), 0);
			} elseif ($who['filter'] == "some") {
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
