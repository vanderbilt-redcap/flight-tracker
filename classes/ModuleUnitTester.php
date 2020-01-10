<?php
namespace Vanderbilt\ModuleUnitTester;


use Vanderbilt\CareerDevLibrary;
use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

class ModuleUnitTester
{
	public function runSystem($module) {
		$this->run($module, "System");
	}

	public function runProject($module) {
		$this->run($module, "Project");
	}

	/********************************** ASSERT METHODS *****************************/

	public function assertIn($a, $ary) {
		$bool = in_array($a, $ary);
		if ($bool) {
			$this->currResults[$this->count] = "assertIn TRUE"; 
		} else {
			$this->currResults[$this->count] = "assertIn FALSE: $a is not in ary"; 
		}
		$this->count++;
		return $bool;
	}

	public function assertNotIn($a, $ary) {
		$bool = !in_array($a, $ary);
		if ($bool) {
			$this->currResults[$this->count] = "assertNotIn TRUE"; 
		} else {
			$this->currResults[$this->count] = "assertNotIn FALSE: $a is in ary"; 
		}
		$this->count++;
		return $bool;
	}

	public function assertNull($obj) {
		$bool = ($obj === NULL);
		if ($bool) {
			$this->currResults[$this->count] = "assertNull TRUE"; 
		} else {
			$this->currResults[$this->count] = "assertNull FALSE: $obj is not NULL"; 
		}
		$this->count++;
		return $bool;
	}

	public function assertNotNull($obj) {
		$bool = ($obj === NULL);
		if ($bool) {
			$this->currResults[$this->count] = "assertNotNull TRUE"; 
		} else {
			$this->currResults[$this->count] = "assertNotNull FALSE: $obj is NULL"; 
		}
		$this->count++;
		return $bool;
	}

	public function assertEqual($a, $b) {
		$bool = ($a == $b);
		if ($bool) {
			$this->currResults[$this->count] = "assertEqual TRUE"; 
		} else {
			$this->currResults[$this->count] = "assertEqual FALSE: $a != $b"; 
		}
		$this->count++;
		return $bool;
	}
	
	public function assertTripleEqual($a, $b) {
		$bool = ($a === $b);
		if ($bool) {
			$this->currResults[$this->count] = "assertTripleEqual TRUE"; 
		} else {
			$this->currResults[$this->count] = "assertTripleEqual FALSE: $a !== $b"; 
		}
		$this->count++;
		return $bool;
	}
	
	public function assertNotEqual($a, $b) {
		$bool = ($a != $b);
		if ($bool) {
			$this->currResults[$this->count] = "assertNotEqual TRUE"; 
		} else {
			$this->currResults[$this->count] = "assertNotEqual FALSE: $a == $b"; 
		}
		$this->count++;
		return $bool;
	}
	
	public function assertNotTripleEqual($a, $b) {
		$bool = ($a !== $b);
		if ($bool) {
			$this->currResults[$this->count] = "assertNotTripleEqual TRUE"; 
		} else {
			$this->currResults[$this->count] = "assertNotTripleEqual FALSE: $a === $b"; 
		}
		$this->count++;
		return $bool;
	}
	
	public function assertNotBlank($str) {
		$bool = ($str !== "");
		if ($bool) {
			$this->currResults[$this->count] = "assertNotBlank TRUE '$str'";
		} else {
			$this->currResults[$this->count] = "assertNotBlank FALSE: '$str' is blank";
		}
		$this->count++;
		return $bool;
	}

	public function assertBlank($str) {
		$bool = ($str === "");
		if ($bool) {
			$this->currResults[$this->count] = "assertBlank TRUE";
		} else {
			$this->currResults[$this->count] = "assertBlank FALSE: '$str' is not blank"; 
		}
		$this->count++;
		return $bool;
	}

	public function assertTrue($bool, $label = "") {
		if ($bool) {
			$this->currResults[$this->count] = "assertTrue $label TRUE";
		} else {
			$this->currResults[$this->count] = "assertTrue $label FALSE"; 
		}
		$this->count++;
		return $bool;
	}

	/**************************************************************************/

	public function getResults() {
		return $this->testResults;
	}

	public function getFailures() {
		$badResults = array();
		foreach ($testResults as $method => $ary) {
			foreach ($ary['results'] as $result) {
				if (preg_match("/FALSE/", $result)) {
					if (!isset($badResults[$method])) {
						$badResults[$method] = array();
					}
					$badResults[$method][] = $result;
				}
			}
		}
		return $badResults;
	}

	public function reset() {
		$this->testResults = array();
	}

	public function setPid($myPid) {
		$this->pid = $myPid;
	}

	private $testResults = array(); // an array indexed by method name
					// contains: array("results" => all of the test results,
					//		   "description" => plain-text description,
					//		)
	private $currMethod = "";       // current test that's being run
	private $currResults = array(); // current results that are being saved
	private $count = 1;		// keeps count of the assert statements
					//  starts at 1 for each method
	private $pid = "";

	/*
	 * @params
	 * $module - AbstractExternalModule
	 * $type - enumeration: "System" or "Project"
	*/
	private function run($module, $type) {
		$classes = array(
					"/_test$/" => $module,
				);
		if ($type == "System") {
			$classes["/_test_system$/"] = $module;
		}
		if ($type == "Project") {
			$classes["/_test_project$/"] = $module;
			$classes["/^test_\d+$/"] = $this;
		}

		foreach ($classes as $re => $obj) {
			$methods = get_class_methods(get_class($obj));
			foreach ($methods as $method) {
				if (preg_match($re, $method)) {
					$this->currMethod = $method;
					$this->count = 1;
					call_user_func(array($obj, $method));
				}
				if ($this->currMethod != "") {
					$ary = array(
							"results" => $this->currResults,
							);
					foreach ($methods as $method2) {
						if ($method2 == $method."_descript") {
							$descript = call_user_func(array($obj, $method2));
							$ary["description"] = $descript;
							break;
						}
					}
					$this->testResults[$this->currMethod] = $ary;
				}
				$this->currMethod = "";
				$this->currResults = array();
			}
		}
	}

	private function test_1() {
		$pid = $this->pid;
		if ($pid) {
			$sql = "SELECT DISTINCT table_name FROM information_schema.tables WHERE table_schema='".db_real_escape_string($db)."'";
			$tablesQ = db_query($sql);

			$tables = array();
			while ($row = db_fetch_assoc($tablesQ)) {
				$tables[] = $row['table_name'];
			}

			$moduleId = "-1";
			$prefix = "module_tester";
			$key = "previousTables"; 

			// GET PREVIOUS TABLES FROM MODULE/PROJECT
			$sql = "SELECT external_module_id FROM redcap_external_modules WHERE directory_prefix = '".db_real_escape_string($prefix)."' LIMIT 1";
			$idQ = db_query($sql);
			if ($row = db_fetch_assoc($idQ)) {
				$moduleId = $row['external_module_id'];
			}

			$sql = "SELECT value FROM redcap_external_module_settings WHERE external_module_id = '".db_real_escape_string($moduleId)."' AND project_id = ".db_real_escape_string($pid)." AND `key` = '".db_real_escape_string($key)."' LIMIT 1";
			$q = db_query($sql);
			$priorTables = NULL;
			if ($row = db_fetch_assoc($q)) {
				$priorTables = json_decode($row['value']);
			}
			
			if ($priorTables) {
				foreach($tables as $table) {
					assertIn($table, $priorTables);
				}
			}
			$priorTables = $tables;
	
			// SAVE INTO MODULE/PROJECT
			$sql = "REPLACE INTO redcap_external_module_settings(external_module_id, project_id, `key`, `type`, value) VALUES('".db_real_escape_string($moduleId)."', '".db_real_escape_string($pid)."', '".db_real_escape_string($key)."', 'json-array', '".json_encode($priorTables)."'";
			db_query($sql);
		}
	}
	private function test_1_descript() {
		return "Tells whether the REDCap tables has changed from the last time this module was run.";
	}

	private function test_2() {
		$pid = $this->pid;
		if ($pid) {
			$sql = "SELECT project_id FROM redcap_projects WHERE project_id = ".db_real_escape_string($pid);
			$q1 = db_query($sql);

			assertNotEqual(db_num_rows($q1), 0);
		}
	}
	private function test_2_descript() {
		return "Tells whether redcap_data matches with redcap_projects.";
	}

	private function test_3() {
		$pid = $this->pid;
		if ($pid) {
			$sql = "SELECT DISTINCT record FROM redcap_data WHERE project_id = ".db_real_escape_string($pid)." ORDER BY ASC"; 
			$dataQ = db_query($sql);

			$sql = "SELECT DISTINCT record FROM redcap_record_list WHERE project_id = ".db_real_escape_string($pid)." ORDER BY ASC"; 
			$recordQ = db_query($sql);

			$dataRecords = array();
			while ($row = db_fetch_assoc($dataQ)) {
				$dataRecords[] = $row['record'];
			}
			$recordRecords = array();
			while ($row = db_fetch_assoc($recordQ)) {
				$recordRecords[] = $row['record'];
			}

			foreach ($dataRecords as $record) {
				rssertIn($record, $recordRecords);
			}
			foreach ($recordRecords as $record) {
				assertIn($record, $dataRecords);
			}
		}
	}
	private function test_3_descript() {
		return "Tells whether redcap_data matches with redcap_record_lists.";
	}

	private function test_4() {
		$pid = $this->pid;
		if ($pid) {
			$sql = "SELECT DISTINCT field_name FROM redcap_data WHERE project_id = ".db_real_escape_string($pid);
			$dataQ = db_query($sql);

			$sql = "SELECT DISTINCT field_name FROM redcap_metadata WHERE project_id = ".db_real_escape_string($pid);
			$metadataQ = db_query($sql);

			$dataFields = array();
			while ($row = db_fetch_assoc($dataQ)) {
				$dataFields[] = $row['field_name'];
			}
			$metadataFields = array();
			while ($row = db_fetch_assoc($metadataQ)) {
				$metadataFields[] = $row['field_name'];
			}

			foreach ($dataFields as $dataField) {
				assertIn($dataField, $metadataFields);
			}
		}
	}
	private function test_4_descript() {
		return "Tells whether redcap_data matches with redcap_metadata.";
	}

	private function test_5() {
		$pid = $this->pid;
		if ($pid) {
			$sql = "SELECT DISTINCT event_id FROM redcap_data WHERE project_id = ".db_real_escape_string($pid);
			$dataQ = db_query($sql);

			$sql = "SELECT DISTINCT m.event_id AS event_id FROM redcap_events_metadata AS m INNER JOIN redcap_event_arms AS a ON a.arm_id = m.arm_id WHERE a.project_id = ".db_real_escape_string($pid);
			$eMetadataQ = db_query($sql);

			$dataEvents = array();
			while ($row = db_fetch_assoc($dataQ)) {
				$dataEvents[] = $row['event_id'];
			}
			$eMetadataEvents = array();
			while ($row = db_fetch_assoc($eMetadataQ)) {
				$eMetadataEvents[] = $row['event_id'];
			}

			foreach ($dataEvents as $eventId) {
				assertIn($eventId, $eMetadataEvents);
			}
		}
	}
	private function test_5_descript() {
		return "Tells whether redcap_data matches with redcap_events_metadata";
	}

	private function test_6() {
		$pid = $this->pid;
		if ($pid) {
			$sql = "SELECT DISTINCT value FROM redcap_data WHERE project_id = ".db_real_escape_string($pid)." AND field_name = 'GROUPID'";
			$dataQ = db_query($sql);

			$sql = "SELECT DISTINCT group_id FROM redcap_data_access_groups WHERE project_id = ".db_real_escape_string($pid);
			$dagQ = db_query($sql);

			$dagIDs = array();
			while ($row = db_fetch_assoc($dagQ)) {
				$dagIDs[] = $row['group_id'];
			}
			$dataIDs = array();
			while ($row = db_fetch_assoc($dataQ)) {
				$dataIDs[] =  $row['value'];
			}

			foreach ($dataIDs as $id) {
				assertIn($id, $dagIDs);
			}
		}
	}
	private function test_6_descript() {
		return "Tells whether data-access groups match between redcap_data and redcap_data_access_groups";
	}

	private function test_7() {
		$pid = $this->pid;
		if ($pid) {
			$sql = "SELECT DISTINCT d.value AS value FROM redcap_data AS d INNER JOIN redcap_metadata AS m ON d.field_name = m.field_name AND d.project_id = m.project_id WHERE d.project_id = ".db_real_escape_string($pid)." AND m.element_type = 'file'";
			$dataQ = db_query($sql);

			$sql = "SELECT doc_id FROM redcap_edocs_metadata WHERE project_id = ".db_real_escape_string($pid);
			$edocQ = db_query($sql);

			$dataFiles = array();
			while ($row = db_fetch_assoc($dataQ)) {
				$dataFiles[] = $row['value'];
			}
			$edocFiles = array();
			while ($row = db_fetch_assoc($edocQ)) {
				$edocFiles[] = $row['doc_id'];
			}

			foreach ($dataFiles as $id) {
				assertIn($id, $edocFiles);
			}
		}
	}
	private function test_7_descript() {
		return "Tells whether redcap_edocs_metadata matches with redcap_data";
	}

	private function test_8() {
		$pid = $this->pid;
		if ($pid) {
			$sql = "SELECT stored_name FROM redcap_edocs_metadata WHERE project_id = ".db_real_escape_string($pid)." AND date_deleted_server IS NULL";
			$edocQ = db_query($sql);

			$edocFiles = array();
			while ($row = db_fetch_assoc($edocQ)) {
				$edocFiles[] = $row['stored_name'];
			}

			foreach ($edocFiles as $filename) {
				assertTrue(file_exists(EDOC_PATH.$filename), $filename);
			}
		}
	}
	private function test_8_descript() {
		return "Tells whether redcap_edocs_metadata matches with a real file in the filesystem";
	}

	private function test_9() {
		$pid = $this->pid;
		if ($pid) {
			$sql = "SELECT DISTINCT f.form_name AS form_name FROM redcap_events_forms AS f INNER JOIN redcap_events_metadata AS m ON f.event_id = m.event_id INNER JOIN redcap_events_arms AS a ON a.arm_id = m.arm_id WHERE a.project_id = ".db_real_escape_string($pid); 
			$formsQ = db_query($sql);

			$sql = "SELECT DISTINCT form_name FROM redcap_metadata WHERE project_id = ".db_real_escape_string($pid);
			$metadataQ = db_query($sql);

			$forms = array();
			while ($row = db_fetch_assoc($formsQ)) {
				$forms[] = $row['form_name'];
			}
			$metadataForms = array();
			while ($row = db_fetch_assoc($metadataQ)) {
				$metadataForms[] = $row['form_name'];
			}

			foreach ($forms as $form) {
				assertIn($form, $metadataForms);
			}
			foreach ($metadata as $form) {
				assertIn($form, $forms);
			}
		}
	}
	private function test_9_descript() {
		return "Tells whether redcap_event_forms matches with redcap_metadata";
	}
}

