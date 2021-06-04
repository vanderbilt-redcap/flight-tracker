<?php


namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

# performs 1:1 case-insensitive comparisons

class GrantLexicalTranslator {
	public function __construct($token, $server, $moduleOrMetadata) {
		if (is_array($moduleOrMetadata)) {
			$this->module = NULL;
			$this->metadata = $moduleOrMetadata;
		} else {
			$this->module = $moduleOrMetadata;
			$this->metadata = NULL;
		}
		$this->token = $token;
		$this->server = $server;
		$this->settingName = "lexical_translations";
		$this->data = array();
		$this->hijackedField = "identifier_last_name";
		if ($this->module) {
		    if ($this->module->PREFIX == "flight_tracker") {
                $this->data = $this->module->getSystemSetting($this->settingName);
            } else {
		        $pid = Application::getPID($this->token);
		        $this->data = $this->module->getProjectSetting($this->settingName, $pid);
            }
		} else if ($this->metadata) {
			foreach ($this->metadata as $row) {
				if ($row['field_name'] == $this->hijackedField) {
					$json = $row['field_annotation'];
					if ($json) {
						$data = json_decode($json, true);
						if ($data) {
							$this->data = $data;
						} else {
							throw new \Exception("Could not read translations from JSON: '$json'");
						}
					}
				}
			}
		}
	}

	public function loadData($data) {
		$this->data = $data;
		if ($this->metadata) {
			$newMetadata = array();
			foreach ($this->metadata as $row) {
				if ($row['field_name'] == $this->hijackedField) {
					$json = json_encode($data);
					$row['field_annotation'] = $json;
				}
				array_push($newMetadata, $row);
			}
			$this->metadata = $newMetadata;
		}
		$this->writeData();
	}

	public function getCategory($awardNo) {
		$awardNoLc = strtolower($awardNo);
		foreach ($this->data as $key => $value) {
			if (strpos($awardNoLc, strtolower($key)) !== FALSE) {
				// Application::log("Returning $value ($key) for $awardNo");
				return $value;
			}
		}
		// Application::log("Returning blank for $awardNo");
		return "";
	}

	public function setCategory($awardNo, $type, $save = TRUE) {
		if ($type && in_array($type, array_keys(Grant::getAwardTypes()))) {
			$this->data[$awardNo] = $type;
		} else {
			throw new \Exception("Could not locate '$type' in award types!");
		}
		if ($save) {
			$this->writeData();
		}
	}

	private function writeData() {
		if ($this->module) {
		    if ($this->module->PREFIX == "flight_tracker") {
                $this->module->setSystemSetting($this->settingName, $this->data);
            } else {
                $pid = Application::getPID($this->token);
                $this->module->setProjectSetting($this->settingName, $this->data, $pid);
            }
		} else if ($this->metadata) {
			$feedback = Upload::metadata($this->metadata, $this->token, $this->server);
		} else {
			throw new \Exception("No module loaded. Could not write lexical-translation data!");
		}
	}

	private static function escape($str) {
		return str_replace("'", "\\'", $str);
	}

	public function deleteKey($key, $save = TRUE) {
		if (isset($this->data[$key])) {
			unset($this->data[$key]);
		}
		if ($save) {
			$this->writeData();
		}
	}

	private static function makeSelectBox($name, $selected = "", $delete = FALSE) {
		$types = array_keys(Grant::getAwardTypes());
		$html = "";
		$blank =  "SELECT";
		if ($delete) {
			$blank = "DELETE";
		}

		$html .= "<select name='$name'>\n";
		$html .= "<option value=''";
		if ($selected == "") {
			$html .= " SELECTED";
		}
		$html .= ">---$blank---</option>\n";
		foreach ($types as $type) {
			$html .= "<option value='$type'";
			if ($selected == $type) {
				$html .= " SELECTED";
			}
			$html .= ">$type</option>\n";
		}
		$html .= "</select>";

		return $html;
	}

	public function setOrder($keys) {
		$newData = array();
		foreach ($keys as $key) {
			if (isset($this->data[$key])) {
				$newData[$key] = $this->data[$key];
			}
		}
		$this->data = $newData;
		$this->writeData();
	}

	public function getOrderHTML() {
		$html = "";

		$html .= "<p class='centered'><button onclick='submitOrder(\"#sortable\", \"#note\"); return false;'>Commit Changes</button></p>\n";
		$html .= "<p class='centered' id='note' style='display: none;'></p>\n";

		$html .= "<ul id='sortable'>\n";
		foreach ($this->data as $key => $value) {
			$html .= "<li id='$key' class='ui-state-default'>$key &rarr; $value</li>\n";
		}
		$html .= "</ul>\n";

		$html .= "<script>\n";
		$html .= "$(document).ready(function() {\n";
		$html .= "\t$('#sortable').sortable();\n";
		$html .= "\t$('#sortable').disableSelection();\n";
		$html .= "});\n";
		$html .= "</script>\n";

		$html .= "<style>\n";
		$html .= "#sortable { list-style-type: none; margin: 0px auto 0px auto; padding: 0; width: 600px; }\n";
		$html .= "#sortable li { margin: 0 3px 3px 3px; padding: 4px; font-size: 16px; height: 24px; }\n";
		$html .= "</style>\n";

		return $html;
	}

	public function getEditHTML() {
		$html = "";

		$page = "?prefix=".urlencode($_GET['prefix'])."&page=".urlencode($_GET['page'])."&pid=".$_GET['pid'];
		$middle = "&nbsp;&nbsp;&rarr;&nbsp;&nbsp;";

		$html .= "<p class='centered'>Each Award Number Parcel must contain a direct (case-insensitive) match to part of the Grant's award number.</p>\n"; 
		$html .= "<form action='$page' method='POST'>\n";
		$html .= "<table style='margin: 0px auto 0px auto;'>\n";
		$idx = 1;
		foreach ($this->data as $key => $value) {
			$html .= "<tr>\n";
			$html .= "<td colspan='5' class='centered'><input type='hidden' value='".self::escape($key)."' name='existing_key$idx'>$key$middle".self::makeSelectBox("existing_value$idx", $value, TRUE)."</td>\n";
			$html .= "</tr>\n";
			$idx++;
		}
		for ($i = 1; $i <= 5; $i++) {
			$html .= "<tr>\n";
			$html .= "<td>Award Number Parcel $i</td>\n";
			$html .= "<td><input type='text' name='key$i' value='' style='width: 150px;'></td>\n";
			$html .= "<td>$middle</td>\n";
			$html .= "<td>Type</td>\n";
			$html .= "<td>".self::makeSelectBox("value".$i)."</td>\n";
			$html .= "</tr>\n";
		}
		$html .= "<tr><td style='text-align: center;' colspan='5'><input type='submit' value='Submit Changes'</td></tr>\n";
		$html .= "</table>\n";
		$html .= "</form>\n";

		return $html;
	}

	public function parsePOST($post) {
		$changes = FALSE;
		foreach ($post as $name => $postValue) {
			$existing = FALSE;
			if (preg_match("/existing_/", $name)) {
				$existing = TRUE;
			}
			if (preg_match("/key\d+$/", $name)) {
				$key = $postValue;
				$valueKey = preg_replace("/key/", "value", $name); 
				if ($postValue && isset($post[$valueKey])) {
					$value = $post[$valueKey];
					if ($existing) {
						if ($value) {
							if ($value != $this->getCategory($key)) {
								$this->setCategory($key, $value, FALSE);
								$changes = TRUE;
							}
						} else {
							$this->deleteKey($key, FALSE);
							$changes = TRUE;
						}
					} else {
						if ($value) {
							$this->setCategory($key, $value, FALSE);
							$changes = TRUE;
						} else {
							# no $value => do nothing
						}
					}
				}
			}
		}
		if ($changes) {
			$this->writeData();
		}
	}

	public function getCount() {
		return count($this->data);
	}

	private $module;
	private $metadata;
	private $hijackedField;
	private $token;
	private $server;
	private $settingName;
	private $data;
}
