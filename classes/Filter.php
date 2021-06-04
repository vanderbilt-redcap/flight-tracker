<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

define('GET_CHOICES', 'choices');
define('GET_VALUE', 'values');

class Filter {
	public function __construct($token, $server, $metadataOrModule) {
		$this->token = $token;
		$this->server = $server;
		if (is_array($metadataOrModule)) {
            $this->metadata = $metadataOrModule;
        } else {
		    $this->metadata = Download::metadata($token, $server);
        }
		$this->choices = Scholar::getChoices($this->metadata);
	}

	# function used in dynamic variable
	public function calc_employment($type, $rows = array()) {
		$func = "getEmploymentStatus";
		if ($type == GET_CHOICES) {
			$fields = array_unique(array_merge(Application::$institutionFields, array("identifier_last_name", "identifier_first_name")));
			$bigCalcSettings = $this->getCalcSettingsChoicesFromData($fields, $func);

			$summedChoices = array();
			foreach ($bigCalcSettings->getChoices() as $choice) {
				if (preg_match("/[aA]t Vanderbilt/", $choice)) {
					if (!in_array($choice, $summedChoices)) {
						array_push($summedChoices, $choice);
					}
				} else {
					$choice = "Left Vanderbilt";
					if (!in_array($choice, $summedChoices)) {
						array_push($summedChoices, $choice);
					}
				}
			}

			$smallerCalcSettings = new CalcSettings("choices");
			$smallerCalcSettings->setChoices($summedChoices);
			return $smallerCalcSettings;
		} else if ($type == GET_VALUE) {
			return $this->$func($rows);
		}
	}

    public static function getStringComparisons() {
        return [
            "eq" => "=",
            "neq" => "!=",
            "contains" => "contains",
            "not_contains" => "does not contain",
        ];
    }

    # function used in dynamic variable
	public function calc_email_domain($type, $rows = array()) {
		$func = "getEmailDomain";
		if ($type == GET_CHOICES) {
			$fields = array("record_id", "identifier_email");
			return $this->getCalcSettingsChoicesFromData($fields, $func);
		} else if ($type == GET_VALUE) {
			return $this->$func($rows);
		}
	}

	# function used in dynamic variable
	public function calc_sponsorno($type, $rows = array()) {
		if ($type == GET_CHOICES) {
			return new CalcSettings("string");
		} else if ($type == GET_VALUE) {
			return $this->getSponsorNumbers($rows);
		}
	}

	# function used in dynamic variable
	public function calc_award_type($type, $rows = array()) {
		if ($type == GET_CHOICES) {
			$choicesHash = Grant::getReverseAwardTypes();
			$calcSettings = new CalcSettings("choices");
			$calcSettings->setChoicesHash($choicesHash);
			return $calcSettings;
		} else if ($type == GET_VALUE) {
			return $this->getAwardTypes($rows);
		}
	}

	# function used in dynamic variable
	public function calc_activity_code($type, $rows = array()) {
		$func = "getActivityCodes";
		if ($type == GET_CHOICES) {
			$fields = array();
			for ($i = 1; $i < Grants::$MAX_GRANTS; $i++) {
				array_push($fields, "summary_award_sponsorno_".$i);
			}
			return $this->getCalcSettingsChoicesFromData($fields, $func);
		} else if ($type == GET_VALUE) {
			return $this->$func($rows);
		}
	}

	# function used in dynamic variable
	public function calc_pub_category($type, $rows = array()) {
		if ($type == GET_CHOICES) {
			$hashOfChoices = Citation::getCategories();
			foreach ($hashOfChoices as $value => $label) {
				if ($label == "") {
					$hashOfChoices[$value] = "Uncategorized";
				}
			}
			$calcSettings = new CalcSettings("choices");
			$calcSettings->setChoicesHash($hashOfChoices);
			return $calcSettings;
		} else if ($type == GET_VALUE) {
			$cats = $this->getPubCategories($rows);
			if (!empty($cats)) {
				Application::log("career_dev: calc_pub_category: ".json_encode($cats));
			}
			return $cats;
		}
	}

	# function used in dynamic variable
	public function calc_rcr($type, $rows = array()) {
		if ($type == GET_CHOICES) {
			$calcSettings = new CalcSettings("number");
			return $calcSettings;
		} else if ($type == GET_VALUE) {
			$pubs = new Publications($this->token, $this->server, $this->metadata);
			$pubs->setRows($rows);
			return $pubs->getAverageRCR("Original Included");
		}
	}

	# function used in dynamic variable
	public function calc_pub_type($type, $rows = array()) {
		if ($type == GET_CHOICES) {
			$pubTypes = Publications::getAllPublicationTypes($this->token, $this->server);
			$calcSettings = new CalcSettings("choices");
			$calcSettings->set1DToHash($pubTypes);
			return $calcSettings;
		} else if ($type == GET_VALUE) {
			return $this->runFuncOnCitation("getPubTypes", $rows);
		}
	}

	# function used in dynamic variable
	public function calc_mesh_term($type, $rows = array()) {
		if ($type == GET_CHOICES) {
			$meshTerms = Publications::getAllMESHTerms($this->token, $this->server);
			$calcSettings = new CalcSettings("choices");
			$calcSettings->set1DToHash($meshTerms);
			return $calcSettings;
		} else if ($type == GET_VALUE) {
			return $this->runFuncOnCitation("getMESHTerms", $rows);
		}
	}

	private function runFuncOnCitation($func, $rows) {
		$pubs = new Publications($this->token, $this->server, $this->metadata);
		$pubs->setRows($rows);
		$items = array();
		foreach ($pubs->getCitations("Original Included") as $citation) {
			foreach ($citation->$func() as $item) {
				if (!in_array($item, $items)) {
					array_push($items, $item);
				}
			}
		}
		return $items;
	}

	# function used in dynamic variable
	public function calc_num_pubs($type, $rows = array()) {
		if ($type == GET_CHOICES) {
			$calcSettings = new CalcSettings("number");
			return $calcSettings;
		} else if ($type == GET_VALUE) {
			return $this->getNumPubs($rows);
		}
	}

	# function used in dynamic variable
	public function calc_from_time($type, $rows = array()) {
		if ($type == GET_CHOICES) {
			$calcSettings = new CalcSettings("date");
			return $calcSettings;
		} else if ($type == GET_VALUE) {
			return $this->getPubTimestamps($rows);
		}
	}

	# helper function
	private function getNumPubs($rows) {
		$pubs = new Publications($this->token, $this->server, $this->metadata);
		$pubs->setRows($rows);
		$cits = $pubs->getCitations("Original Included");
		return count($cits);
	}

	# helper function
	private function getPubTimestamps($rows) {
		$pubs = new Publications($this->token, $this->server, $this->metadata);
		$pubs->setRows($rows);
		$cits = self::getOriginals($pubs->getCitations("Included"));

		$timestamps = array();
		foreach ($cits as $cit) {
			array_push($timestamps, $cit->getTimestamp());
		}
		return $timestamps;
	}

	# helper function
	private function getPubCategories($rows) {
		$pubs = new Publications($this->token, $this->server, $this->metadata);
		$pubs->setRows($rows);
		$cits = $pubs->getCitations();
		$recordId = $pubs->getRecordId();

		$categories = array();
		foreach ($cits as $cit) {
			$cat = $cit->getCategory();
			if (!in_array($cat, $categories)) {
				array_push($categories, $cat);
			}
		}
		if (!empty($cits)) {
			Application::log("career_dev: $recordId Searching through ".count($cits)." citations");
			Application::log("career_dev: $recordId returning ".json_encode($categories));
		}
		return $categories;
	}

	# helper function
	private function getCalcSettingsChoicesFromData($fields, $func, $choices = array()) {
		$redcapData = Download::getIndexedRedcapData($this->token, $this->server, $fields);

		$allChoices = array();
		foreach ($redcapData as $recordId => $rows) {
			$choicesAry = $this->$func($rows);
			if ($choicesAry && !is_array($choicesAry)) {
				$choicesAry = array($choicesAry);
			}
			foreach ($choicesAry as $currChoice) {
				if ($currChoice) {
					if ($choices[$currChoice]) {
						$currChoice = $choices[$currChoice];
					}
					if (!in_array($currChoice, $allChoices)) {
						array_push($allChoices, $currChoice);
					}
				}
			}
		}

		$calcSettings = new CalcSettings("choices");
		$calcSettings->setChoices($allChoices);
		return $calcSettings;
	}

	private function getEmailDomain($rows) {
		foreach ($rows as $row) {
			if ($row['identifier_email']) {
				$parts = preg_split("/@/", $row['identifier_email']);
				if (count($parts) == 2) {
					return strtolower($parts[1]);
				}
			}
		}
		return "";
	}

	private function getEmploymentStatus($rows) {
		$scholar = new Scholar($this->token, $this->server, $this->metadata);
		$scholar->setRows($rows);
		return $scholar->getEmploymentStatus();
	}

	private function getSponsorNumbers($rows) {
		$numbers = array();
		foreach ($rows as $row) {
			for ($i = 1; $i < Grants::$MAX_GRANTS; $i++) {
				$field = "summary_award_sponsorno_".$i;
				if ($row[$field]) {
					if (!in_array($row[$field], $numbers)) {
						array_push($numbers, $row[$field]);
					}
				}	
			}
		}
		return $numbers;
	}

	private function getAwardTypes($rows) {
		$types = array();
		foreach ($rows as $row) {
			for ($i = 1; $i < Grants::$MAX_GRANTS; $i++) {
				$field = "summary_award_type_".$i;
				if ($row[$field]) {
					if (!in_array($row[$field], $types)) {
						array_push($types, $row[$field]);
					}
				}	
			}
		}
		return $types;
	}

	private function getActivityCodes($rows) {
		$codes = array();
		foreach ($rows as $row) {
			for ($i = 1; $i < Grants::$MAX_GRANTS; $i++) {
				$field = "summary_award_sponsorno_".$i;
				if ($row[$field]) {
					if ($code = Grant::getActivityCode($row[$field])) {
						array_push($codes, $code);
					}
				}
			}
		}
		return $codes;
	}

	# variable => label
	public static function getDemographicChoices() {
		$ary = array(
				"summary_gender" => "Gender",
				"summary_race_ethnicity" => "Race/Ethnicity",
				"summary_primary_dept" => "Primary Department",
                "summary_current_division" => "Current Academic Division",
				"summary_dob" => "Date of Birth",
				"summary_degrees" => "Academic Degrees",
				"summary_citizenship" => "Citizenship",
				"summary_urm" => "Under-Represented Minority Status",
				"summary_disability" => "Disability Status",
				"summary_disadvantaged" => "Disadvantaged Status",
				"summary_training_start" => "Start of Training Program",
				"summary_training_end" => "End of Training Program",
				// "calc_institution" => "Institution",
				"summary_current_rank" => "Current Academic Rank",
				"calc_employment" => "Employment Status",
				"calc_email_domain" => "Email Domain",
				);
		return $ary;
	}

	# variable => label
	public static function getGrantChoices() {
		$ary = [
				"calc_award_type" => "Award Type",
                "summary_ever_last_any_k_to_r01_equiv" => "Conversion Status",
                "summary_award_type_1" => "First Award Type",
				"summary_award_sponsorno_1" => "First Award Sponsor Number",
				"calc_sponsorno" => "Any Award Sponsor Number",
				"calc_activity_code" => "Activity Code",
				"summary_first_any_k" => "First Any K",
				"summary_last_any_k" => "Last Any K",
				"summary_first_r01_or_equiv" => "First R01 or Equivalent",
				];
		return $ary;
	}

	# variable => label
	public static function getPublicationChoices() {
		$ary = array(
				"calc_pub_category" => "Publication Category",
				"calc_num_pubs" => "Number of Research Articles",
				"calc_from_time" => "Number of Research Articles from Date",
				"calc_rcr" => "Scholars with Relative Citation Ratio",
				"calc_pub_type" => "Scholars with Publication Type",
				"calc_mesh_term" => "Scholars with MESH Term",
				);
		return $ary;
	}

	public static function getAllChoices() {
		return array_merge(self::getDemographicChoices(), self::getGrantChoices(), self::getPublicationChoices());
	}

	public function getChoices($var) {
		if (isset($this->choices[$var])) {
			return $this->choices[$var];
		}
		foreach ($this->metadata as $row) {
			if ($row['field_name'] == $var) {
				if ($row['field_type'] == "yesno") {
					return array("0" => "No", "1" => "Yes");
				} else if ($row['field_type'] == "truefalse") {
					return array("0" => "False", "1" => "True");
				}
			}
		}
		return array();
	}

	public static function getMaxNumberOfVariables() {
		return 15;
	}

	public static function getContainsSettings() {
		return array(
				"eq" => "Has",
				"neq" => "Does Not Have",
                "contains" => "Contains",
                "not_contains" => "Does Not Contain",
				);
	}

	private function makeCombinerSelect($i) {
		$html = "";
		$html .= "<select id='combination$i'>\n";
		$allowedCombiners = CohortConfig::getAllowedCombiners();
		$firstCombiner = TRUE;
		foreach ($allowedCombiners as $combiner) {
			if ($firstCombiner) {
				$html .= "<option value='$combiner' selected>$combiner</option>\n";
				$firstCombiner = FALSE;
			} else {
				$html .= "<option value='$combiner'>$combiner</option>\n";
			}
		}
		$html .= "</select>\n";

		return $html;
	}

	public function getHTML() {
		$num = self::getMaxNumberOfVariables();
		$html = "";

		$html .= "<p class='centered' style='font-size: 16px;'>\n";
		$html .= "<b>Title</b>: <input type='text' id='title' onblur='checkForDuplicates(\"#title\"); return false;'><br>\n";
		$html .= "<b>Precedence Rules</b>: XOR &gt; AND &gt; OR\n";
		$html .= "</p>\n";

		$html .= "<br><br>\n";

		$cohorts = new Cohorts($this->token, $this->server, Application::getModule());

		$workshopChoices = $this->getChoices('resources_resource');
		$blankOption = array("" => "---SELECT---");

		$html .= "<script>\n";
		$html .= "function commit() {\n";
		$html .= "\tvar title = $('#title').val();\n";
		$html .= "\tif (!title) {\n";
		$html .= "\t\talert('No title specified!')\n";
		$html .= "\t} else {\n";
		$html .= "\t\tvar config = {};\n";
		# the below line is the original configuration; must be able to parse in order to maintain backwards-compatibility
		// $html .= "\t\tconfig['combiner'] = $('#combination').val();\n";
		$html .= "\t\tconfig['rows'] = [];\n";
		$html .= "\t\tfor (var i = 1; i <= ".$num."; i++) {\n";
		$html .= "\t\t\tif (($('#type'+i).val() != '') && ($('#variable'+i).val() != '')) {\n";
		$html .= "\t\t\t\tvar row = {};\n";
		$html .= "\t\t\t\trow['type'] = $('#type'+i).val();\n";
		$html .= "\t\t\t\trow['variable'] = $('#variable'+i).val();\n";
		$html .= "\t\t\t\tif ((i > 1) && $('#combination'+i).is(':visible')) {\n";
		$html .= "\t\t\t\t\trow['combiner'] = $('#combination'+i).val();\n";
		$html .= "\t\t\t\t}\n";
		$html .= "\t\t\t\tif ($('#choice'+i).val()) {\n";
		$html .= "\t\t\t\t\trow['choice'] = $('#choice'+i).val();\n";
		$html .= "\t\t\t\t\trow['comparison'] = $('#comparison'+i).val();\n";
		$html .= "\t\t\t\t\tconfig['rows'].push(row);\n";
		$html .= "\t\t\t\t} else if ($('#value'+i).val() != '') {\n";
		$html .= "\t\t\t\t\trow['value'] = $('#value'+i).val();\n";
		$html .= "\t\t\t\t\trow['comparison'] = $('#comparison'+i).val();\n";
		$html .= "\t\t\t\t\tconfig['rows'].push(row);\n";
		$html .= "\t\t\t\t} else if ($('#type'+i).val() == 'resources') {\n";
		$html .= "\t\t\t\t\tconfig['rows'].push(row);\n";
		$html .= "\t\t\t\t}\n";
		$html .= "\t\t\t}\n";
		$html .= "\t\t}\n";
		$html .= "\t\tif (config['rows'].length > 0) {\n";
		$html .= "\t\t\tpresentScreen('Saving...');\n";
		$html .= "\t\t\t$.post('".Application::link("cohorts/addCohort.php")."', { title: title, config: config }, function(data) {\n";
		$html .= "\t\t\t\tclearScreen();\n";
		$html .= "\t\t\t\tif (data.match(/success/)) {\n";
		$html .= "\t\t\t\t\tvar mssg = 'Upload successful!';\n";
		$html .= "\t\t\t\t\twindow.location.href = '".Application::link("cohorts/viewCohort.php")."&title='+encodeURI(title)+'&mssg='+encodeURI(mssg);\n";
		$html .= "\t\t\t\t} else {\n";
		$html .= "\t\t\t\t\talert(data);\n";
		$html .= "\t\t\t\t}\n";
		$html .= "\t\t\t});\n";
		$html .= "\t\t}\n";
		$html .= "\t}\n";
		$html .= "}\n";
		$html .= "function checkForDuplicates(selector) {\n";
		$html .= "\tvar existing = ".json_encode($cohorts->getCohortNames()).";\n";
		$html .= "\tvar found = false;\n";
		$html .= "\tfor (var i=0; i < existing.length; i++) {\n";
		$html .= "\t\tif (existing[i].toLowerCase() == $(selector).val().toLowerCase()) {\n";
		$html .= "\t\t\tfound = true;\n";
		$html .= "\t\t}\n";
		$html .= "\t}\n";
		$html .= "\tif (found) {\n";
		$html .= "\t\t$(selector).addClass('red');\n";
		$html .= "\t\talert('Duplicate name found! Please change the name of your configuration.');\n";
		$html .= "\t} else {\n";
		$html .= "\t\t$(selector).removeClass('red');\n";
		$html .= "\t}\n";
		$html .= "}\n";
		// $html .= "function changeCombine(val) {\n";
		// $html .= "\t$('.combinator').html(val);\n";
		// $html .= "}\n";
		$html .= "function add(selector, i) {\n";
		$html .= "\t$(selector).prop('disabled', true);\n";
		$html .= "\t$('#filter'+(parseInt(i)+1)).show();\n";
		$html .= "\t$('#combiner'+(parseInt(i)+1)).show();\n";
		$html .= "\t$('#commitButton').show();\n";
		$html .= "}\n";
		$html .= "function combineHashes(hash1, hash2) {\n";
		$html .= "\tvar newHash = {};\n";
		$html .= "\tvar key;\n";
		$html .= "\tfor (key in hash1) {\n";
		$html .= "\t\tnewHash[key] = hash1[key];\n";
		$html .= "\t}\n";
		$html .= "\tfor (key in hash2) {\n";
		$html .= "\t\tnewHash[key] = hash2[key];\n";
		$html .= "\t}\n";
		$html .= "\treturn newHash;\n";
		$html .= "}\n";
		$html .= "function change(selector, i) {\n";
		$html .= "\tvar val = $(selector).val();\n";
		$html .= "\tvar options = {};\n";
		$html .= "\tvar comparisons = false;\n";
		$html .= "\tvar nextSelector = '';\n";
		$html .= "\tvar hideSelector = '';\n";
		$html .= "\tif (selector.match(/type/)) {\n";
		$html .= "\t\tif (val == 'demographic') {\n";
		$html .= "\t\t\toptions = ".json_encode(array_merge($blankOption, self::getDemographicChoices())).";\n";
		$html .= "\t\t} else if (val == 'grant') {\n";
		$html .= "\t\t\toptions = ".json_encode(array_merge($blankOption, self::getGrantChoices())).";\n";
		$html .= "\t\t} else if (val == 'publication') {\n";
		$html .= "\t\t\toptions = ".json_encode(array_merge($blankOption, self::getPublicationChoices())).";\n";
		$html .= "\t\t} else if (val == 'resources') {\n";
		$html .= "\t\t\toptions = ".json_encode(array_merge($blankOption, $workshopChoices)).";\n";
		$html .= "\t\t}\n"; 
		$html .= "\t\tnextSelector = '#variable'+i;\n";
		$html .= "\t\t$('#comparison'+i).hide();\n";
		$html .= "\t\t$('#choice'+i).hide();\n";
		$html .= "\t\t$('#choice'+i).parent().find('.custom-combobox').hide();\n";
		$html .= "\t\t$('#value'+i).hide();\n";
		$html .= "\t\t$('#button'+i).hide();\n";
		$html .= "\t} else if (selector.match(/variable/)) {\n";

		$textChoices = array("string", "number");
		$dateChoices = array("date");
		$allChoices = array_merge(self::getAllChoices(), $workshopChoices); 
		foreach ($allChoices as $var => $label) {
			$html .= "\t\tif (val == '$var') {\n";
			if (preg_match("/^calc_/", $var)) {
				$calcSettings = $this->$var(GET_CHOICES);
				$calcSettingsType = $calcSettings->getType();
				if ($calcSettingsType == "choices") {
					$html .= "\t\t\toptions = ".json_encode($calcSettings->getChoices()).";\n";
					$html .= "\t\t\tnextSelector = '#choice'+i;\n";
					$html .= "\t\t\t$('#value'+i).hide();\n";
					$html .= "\t\t\t$('#button'+i).hide();\n";
					$html .= "\t\t\tcomparisons = ".json_encode(self::getContainsSettings()).";\n";
				} else if (in_array($calcSettingsType, array_merge($dateChoices, $textChoices))) {
					$inputType = CalcSettings::transformToInputType($calcSettingsType);
					if ($inputType) {
						$html .= "\t\t\t$('#value'+i).prop('type', '$inputType');\n";
					}

					$html .= "\t\t\toptions = false;\n";
					$html .= "\t\t\tnextSelector = '#value'+i;\n";
					$html .= "\t\t\t$('#choice'+i).hide();\n";
					$html .= "\t\t\t$('#choice'+i).parent().find('.custom-combobox').hide();\n";
					$html .= "\t\t\t$('#button'+i).show();\n";
					$html .= "\t\t\tif (i == 1) { $('#commitButton').show(); }\n";
					$html .= "\t\t\tcomparisons = ".json_encode($calcSettings->getComparisons()).";\n";
				}
			} else if (isset($workshopChoices[$var])) {
				$html .= "\t\t\toptions = false;\n";
				$html .= "\t\t\t$('#button'+i).show();\n";
				$html .= "\t\t\tif (i == 1) { $('#commitButton').show(); }\n";
				$html .= "\t\t\tcomparisons = ".json_encode(self::getContainsSettings()).";\n";
			} else if ($this->getChoices($var)) {
				$html .= "\t\t\toptions = ".json_encode($this->getChoices($var)).";\n";
				$html .= "\t\t\tnextSelector = '#choice'+i;\n";
				$html .= "\t\t\t$('#value'+i).hide();\n";
				$html .= "\t\t\t$('#button'+i).hide();\n";
				$html .= "\t\t\tcomparisons = ".json_encode(self::getContainsSettings()).";\n";
			} else {
				# number, string, or date
				$calcSettingsType = CalcSettings::getTypeFromMetadata($var, $this->metadata);
				$inputType = CalcSettings::transformToInputType($calcSettingsType);
				if ($calcSettingsType && $inputType) {
					$calcSettings = new CalcSettings($calcSettingsType);
					$html .= "\t\t\toptions = false;\n";
					$html .= "\t\t\tnextSelector = '#value'+i;\n";
					$html .= "\t\t\t$('#choice'+i).hide();\n";
					$html .= "\t\t\t$('#choice'+i).parent().find('.custom-combobox').hide();\n";
					$html .= "\t\t\t$('#button'+i).show();\n";
					$html .= "\t\t\tif (i == 1) { $('#commitButton').show(); }\n";
					$html .= "\t\t\tcomparisons = ".json_encode($calcSettings->getComparisons()).";\n";
					$html .= "\t\t\t$('#value'+i).prop('type', '$inputType');\n";
				} else {
				    Application::log("Warning! Could not find values for $var ($calcSettingsType $inputType)");
                }
			}
			$html .= "\t\t}\n";
		}

		$html .= "\t} else if (selector.match(/choice/)) {\n";
		$html .= "\t\t$('#button'+i).show();\n";
		$html .= "\t\tif (i == 1) { $('#commitButton').show(); }\n";
		$html .= "\t}\n";
		$html .= "\tif (comparisons) {\n";
		$html .= "\t\t$('#comparison'+i).find('option').remove();\n";
		$html .= "\t\tfor (var value in comparisons) {\n";
		$html .= "\t\t\t$('#comparison'+i).append('<option value=\"'+value+'\">'+comparisons[value]+'</option>');\n";
		$html .= "\t\t}\n";
		$html .= "\t\t$('#comparison'+i).show();\n";
		$html .= "\t} else if (nextSelector && (nextSelector.match(/type/) || nextSelector.match(/variable/))) {\n";
		$html .= "\t\t$('#comparison'+i).hide();\n";
		$html .= "\t}\n";
		$html .= "\tif (nextSelector) {\n";
		$html .= "\t\tif (options) {\n";
		$html .= "\t\t\t$(nextSelector).find('option').remove();\n";
		$html .= "\t\t\tif (typeof options[''] != 'undefined') {\n";
		$html .= "\t\t\t\t$(nextSelector).append('<option value=\"\" selected>---SELECT---</option>');\n";
		$html .= "\t\t\t}\n";
		$html .= "\t\t\tfor (var value in options) {\n";
		$html .= "\t\t\t\tif (value !== '') {\n";
		$html .= "\t\t\t\t\t$(nextSelector).append('<option value=\"'+value+'\">'+options[value]+'</option>');\n";
		$html .= "\t\t\t\t}\n";
		$html .= "\t\t\t}\n";
		$html .= "\t\t\tif (nextSelector.match(/choice/)) {\n";
		$html .= "\t\t\t\t$(nextSelector).parent().find('.custom-combobox').show();\n";
		$html .= "\t\t\t} else {\n";
		$html .= "\t\t\t\t$(nextSelector).show();\n";
		$html .= "\t\t\t}\n";
		$html .= "\t\t} else {\n";
		$html .= "\t\t\t$(nextSelector).show();\n";
		$html .= "\t\t}\n";
		$html .= "\t}\n";
		$html .= "}\n";
		$html .= "</script>\n";

		$html .= "<table style='width: 1100px; margin-left: auto; margin-right: auto; border: 1px dotted #888888; border-radius: 10px;'>\n";

		$html .= "<tr>\n";
		$html .= "<th></th>\n";
		$html .= "<th>Filter Type</th>\n";
		$html .= "<th>Variable</th>\n";
		$html .= "<th>Value</th>\n";
		$html .= "<th></th>\n";
		$html .= "</tr>\n";

		for ($i = 1; $i <= $num; $i++) {
			$html .= "<tr id='filter$i' class='filter'";
			if ($i > 1) {
				$html .= " style='display: none;'";
			}
			$html .= ">\n";
			$html .= "<th class='cells' style='width: 60px;'>Filter $i</th>\n";
			$html .= "<td class='cells' style='width: 140px;'><select class='options$i' id='type$i' onchange='change(\"#type$i\", \"$i\");'>\n";
			$html .= "<option value='' selected>---SELECT---</option>\n";
			$html .= "<option value='demographic'>Demographic</option>\n";
			$html .= "<option value='grant'>Grant</option>\n";
			$html .= "<option value='publication'>Publication</option>\n";
			$html .= "<option value='resources'>Resources</option>\n";
			$html .= "</select></td>\n";
			$html .= "<td class='cells' style='width: 330px;'><select class='options$i' style='display: none;' id='variable$i' onchange='change(\"#variable$i\", \"$i\");'>\n";
			$html .= "</select></td>\n";
			$html .= "<td class='cells' style='width: 400px;'>\n";
			$html .= "<select class='options$i' style='display: none;' id='comparison$i'></select>\n";
			$html .= "<select class='options$i combobox' style='display: none;' id='choice$i' onchange='change(\"#choice$i\", \"$i\");'></select>\n";
			$html .= "<input type='date' class='options$i' style='display: none;' id='value$i'>\n";
			$html .= "</td>\n";
			$html .= "<td class='cells' style='width: 90px;'>\n";
			$html .= "<button onclick='add(\".options$i\", \"$i\"); return false;' class='biggerButton' style='display: none;' id='button$i'>Add Row</button>\n";
			$html .= "</td>\n";
			$html .= "</tr>\n";
			if ($i != $num) {
				$html .= "<tr><td class='cells' colspan='5'><div id='combiner".($i+1)."' class='combinator' style='display: none;'>".self::makeCombinerSelect($i+1)."</div></td></tr>\n";
			} else {
				$html .= "<tr><td colspan='5' class='centered'><button onclick='commit(); return false;' id='commitButton' style='display: none;' class='biggerButton'>Commit Filter</button></td></tr>\n";
			}
		}

		$html .= "</table>\n";

		return $html;
	}

	public function getRecords($config, $redcapData = array()) {
		if (empty($redcapData)) {
			$fields = array_merge(array("record_id"), $config->getFields($this->metadata));
			$redcapData = Download::getIndexedRedcapData($this->token, $this->server, $fields);
		}

        $in = [];
		$records = $config->getManualRecords();
		if (!empty($records)) {
            foreach ($redcapData as $recordId => $rows) {
                if (in_array($recordId, $records)) {
                    array_push($in, $recordId);
                }
            }
        } else {
            foreach ($redcapData as $recordId => $rows) {
                if ($config->isIn($rows, $this)) {
                    array_push($in, $recordId);
                }
            }
        }
		return $in;
	}

	protected $token;
	protected $server;
	protected $metadata;
	protected $choices;
}

class CalcSettings {
	public function __construct($type) {
		$valid = self::getValidTypes();
		if (in_array($type, $valid)) {
			$this->type = $type;
		} else {
			throw new \Exception("Invalid type $type.");
		}
	}

	public function set1DToHash($ary1D) {
		$this->choices = array();
		foreach ($ary1D as $item) {
			$this->choices[$item] = $item;
		}
	}

	public function setChoicesHash($hash) {
		$this->choices = array();
		foreach ($hash as $value => $label) {
			$this->choices[$value] = $label;
		}
	}

	public function setChoices($ary) {
		$this->choices = array();
		foreach ($ary as $item) {
			$this->choices[$item] = $item;
		}
	}

	public function getChoices() {
		if ($this->type == "choices") {
			if ($this->choices) {
				return $this->choices;
			}
		}
		return array();
	}

	public function getComparisons() {
		if ($this->type == "string") {
			return Filter::getStringComparisons();
		} else if (($this->type == "number") || ($this->type == "date")) {
			return CohortConfig::getComparisons();
		}
		return array();
	}

	public static function getValidTypes() {
		return array("choices", "string", "number", "date");
	}

	public static function transformToInputType($calcSettingsType) {
		switch($calcSettingsType) {
			case "string":
				return "text";
			case "number":
				return "number";
			case "date":
				return "date";
		}
		return "";
	}

	public static function getTypeFromMetadata($field, $metadata) {
		$numberValidationTypes = array("integer", "number");
		$choiceFieldTypes = array("radio", "checkbox", "dropdown");

		foreach ($metadata as $row) {
			if ($row['field_name'] == $field) {
				if ($row['select_choices_or_calculations'] && in_array($row['field_type'], $choiceFieldTypes)) {
					return "choices";
				} else if (preg_match("/^date/", $row['text_validation_type_or_show_slider_number'])) {
					return "date";
				} else if (in_array($row['text_validation_type_or_show_slider_number'], $numberValidationTypes)) {
					return "number";
				} else {
					# not number nor date => string
					return "string";
				}
			}
		}
		# invalid $field
		return "";
	}

	public function getType() {
		return $this->type;
	}

	private $type;
	private $choices;
}
