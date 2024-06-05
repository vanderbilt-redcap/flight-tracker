<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class Filter {
    const GET_CHOICES = "choices";
    const GET_VALUE = "values";

    public function __construct($token, $server, $metadata) {
		$this->token = $token;
		$this->server = $server;
		if (is_array($metadata)) {
            $this->metadata = $metadata;
        } else {
		    $this->metadata = Download::metadata($token, $server);
        }
		$this->choices = Scholar::getChoices($this->metadata);
	}

	# function used in dynamic variable
	public function calc_employment($type, $rows = array()) {
		$func = "getEmploymentStatus";
		if ($type == self::GET_CHOICES) {
			$fields = array_unique(array_merge(Application::$institutionFields, array("identifier_last_name", "identifier_first_name")));
			$bigCalcSettings = $this->getCalcSettingsChoicesFromData($fields, $func);

			$summedChoices = array();
            $institution = Application::getInstitution();
			foreach ($bigCalcSettings->getChoices() as $choice) {
				if (preg_match("/[aA]t $institution/", $choice)) {
					if (!in_array($choice, $summedChoices)) {
						$summedChoices[] = $choice;
					}
				} else {
					$choice = "Left ".$institution;
					if (!in_array($choice, $summedChoices)) {
						$summedChoices[] = $choice;
					}
				}
			}

			$smallerCalcSettings = new CalcSettings("choices");
			$smallerCalcSettings->setChoices($summedChoices);
			return $smallerCalcSettings;
		} else if ($type == self::GET_VALUE) {
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
		if ($type == self::GET_CHOICES) {
			$fields = array("record_id", "identifier_email");
			return $this->getCalcSettingsChoicesFromData($fields, $func);
		} else if ($type == self::GET_VALUE) {
			return $this->$func($rows);
		}
	}

	# function used in dynamic variable
	public function calc_sponsorno($type, $rows = array()) {
		if ($type == self::GET_CHOICES) {
			return new CalcSettings("string");
		} else if ($type == self::GET_VALUE) {
			return $this->getSponsorNumbers($rows);
		}
	}

	# function used in dynamic variable
	public function calc_award_type($type, $rows = array()) {
		if ($type == self::GET_CHOICES) {
			$choicesHash = Grant::getReverseAwardTypes();
			$calcSettings = new CalcSettings("choices");
			$calcSettings->setChoicesHash($choicesHash);
			return $calcSettings;
		} else if ($type == self::GET_VALUE) {
			return $this->getAwardTypes($rows);
		}
	}

    # function used in dynamic variable
    public function calc_activity_code($type, $rows = array()) {
        $func = "getActivityCodes";
        if ($type == self::GET_CHOICES) {
            $fields = REDCapManagement::getGrantNumberFields($this->metadata);
            return $this->getCalcSettingsChoicesFromData($fields, $func);
        } else if ($type == self::GET_VALUE) {
            return $this->$func($rows);
        }
    }

    # function used in dynamic variable
    public function calc_institute($type, $rows = array()) {
        $func = "getInstitutes";
        if ($type == self::GET_CHOICES) {
            $fields = REDCapManagement::getGrantNumberFields($this->metadata);
            return $this->getCalcSettingsChoicesFromData($fields, $func);
        } else if ($type == self::GET_VALUE) {
            return $this->$func($rows);
        }
    }

    # function used in dynamic variable
	public function calc_pub_category($type, $rows = array()) {
		if ($type == self::GET_CHOICES) {
			$hashOfChoices = Citation::getCategories();
			foreach ($hashOfChoices as $value => $label) {
				if ($label == "") {
					$hashOfChoices[$value] = "Uncategorized";
				}
			}
			$calcSettings = new CalcSettings("choices");
			$calcSettings->setChoicesHash($hashOfChoices);
			return $calcSettings;
		} else if ($type == self::GET_VALUE) {
			$cats = $this->getPubCategories($rows);
			if (!empty($cats)) {
				Application::log("career_dev: calc_pub_category: ".json_encode($cats));
			}
			return $cats;
		}
	}

	# function used in dynamic variable
	public function calc_rcr($type, $rows = array()) {
		if ($type == self::GET_CHOICES) {
			$calcSettings = new CalcSettings("number");
			return $calcSettings;
		} else if ($type == self::GET_VALUE) {
			$pubs = new Publications($this->token, $this->server, $this->metadata);
			$pubs->setRows($rows);
			return $pubs->getAverageRCR("Original Included");
		}
	}

	# function used in dynamic variable
	public function calc_pub_type($type, $rows = array()) {
		if ($type == self::GET_CHOICES) {
			$pubTypes = Publications::getAllPublicationTypes($this->token, $this->server);
			$calcSettings = new CalcSettings("choices");
			$calcSettings->set1DToHash($pubTypes);
			return $calcSettings;
		} else if ($type == self::GET_VALUE) {
			return $this->runFuncOnCitation("getPubTypes", $rows);
		}
	}

	# function used in dynamic variable
	public function calc_mesh_term($type, $rows = array()) {
		if ($type == self::GET_CHOICES) {
			$meshTerms = Publications::getAllMESHTerms($this->token, $this->server);
			$calcSettings = new CalcSettings("choices");
			$calcSettings->set1DToHash($meshTerms);
			return $calcSettings;
		} else if ($type == self::GET_VALUE) {
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
		if ($type == self::GET_CHOICES) {
			$calcSettings = new CalcSettings("number");
			return $calcSettings;
		} else if ($type == self::GET_VALUE) {
			return $this->getNumPubs($rows);
		}
	}

	# function used in dynamic variable
	public function calc_from_time($type, $rows = array()) {
		if ($type == self::GET_CHOICES) {
			$calcSettings = new CalcSettings("date");
			return $calcSettings;
		} else if ($type == self::GET_VALUE) {
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
		$cits = $pubs->getCitations("Included");

		$timestamps = array();
		foreach ($cits as $cit) {
			$timestamps[] = $cit->getTimestamp();
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
					if (isset($choices[$currChoice])) {
						$currChoice = $choices[$currChoice];
					}
					if (!in_array($currChoice, $allChoices)) {
						$allChoices[] = $currChoice;
					}
				}
			}
		}

        sort($allChoices);
		$calcSettings = new CalcSettings("choices");
		$calcSettings->setChoices($allChoices);
		return $calcSettings;
	}

	private function getEmailDomain($rows) {
		foreach ($rows as $row) {
			if ($row['identifier_email']) {
				$parts = explode("@", $row['identifier_email']);
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
		$status = $scholar->getEmploymentStatus();
        $institution = Application::getInstitution();
        if (preg_match("/Left $institution/", $status)) {
            return "Left $institution";
        } else {
            return $status;
        }
	}

	private function getSponsorNumbers($rows) {
		$numbers = array();
		foreach ($rows as $row) {
			for ($i = 1; $i < Grants::$MAX_GRANTS; $i++) {
				$field = "summary_award_sponsorno_".$i;
				if ($row[$field]) {
					if (!in_array($row[$field], $numbers)) {
						$numbers[] = $row[$field];
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
						$types[] = $row[$field];
					}
				}	
			}
		}
		return $types;
	}

    private function getInstitutes($rows) {
        $codes = $this->getCodesFromAwardNumber($rows, "getInstituteCode");
        $texts = [];
        foreach ($codes as $code) {
            $institute = Grant::decodeInstituteCode($code, TRUE);
            if ($institute) {
                $texts[] = "$institute ($code)";
            }
        }
        return $texts;
    }

    private function getCodesFromAwardNumber($rows, $grantClassFunc) {
        $codes = [];
        $awardNumberFields = REDCapManagement::getGrantNumberFields($this->metadata);
        foreach ($rows as $row) {
            foreach ($awardNumberFields as $field) {
                if ($row[$field] ?? FALSE) {
                    if ($code = Grant::$grantClassFunc($row[$field])) {
                        $codes[] = $code;
                    }
                }
            }
        }
        return array_unique($codes);
    }

    private function getActivityCodes($rows) {
        return $this->getCodesFromAwardNumber($rows, "getActivityCode");
	}

	# variable => label
	public function getDemographicChoices() {
        # optional fields added via foreach loop below
		$ary = [
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
            "summary_did_not_complete" => "Did Not Complete Program",
            "summary_current_tenure" => "Recorded Tenure Status",
            // "calc_institution" => "Institution",
            "summary_current_rank" => "Current Academic Rank",
            "calc_employment" => "Employment Status",
            "calc_email_domain" => "Email Domain",
        ];
        $metadataFields = DataDictionaryManagement::getFieldsFromMetadata($this->metadata);
        $labels = DataDictionaryManagement::getLabels($this->metadata);
        foreach (REDCapManagement::getOptionalFields() as $field) {
            if (in_array($field, $metadataFields)) {
                $ary[$field] = $labels[$field] ?? $field;
            }
        }
		return $ary;
	}

	# variable => label
	public function getGrantChoices() {
		$ary = [
            "calc_award_type" => "Award Type",
            "summary_ever_last_any_k_to_r01_equiv" => "Conversion Status",
            "summary_award_type_1" => "First Award Type",
            "summary_award_sponsorno_1" => "First Award Sponsor Number",
            "calc_sponsorno" => "Any Award Sponsor Number",
            "calc_activity_code" => "Activity Code",
            "calc_institute" => "Institute/Center Abbrev.",
            "summary_t_start" => "Start of First Training Grant",
            "summary_t_end" => "End of Last Training Grant",
            "summary_first_any_k" => "First Any K",
            "summary_last_any_k" => "Last Any K",
            "summary_first_r01_or_equiv" => "First R01 or Equivalent",
        ];
        $metadataFields = DataDictionaryManagement::getFieldsFromMetadata($this->metadata);
        foreach ($ary as $field => $label) {
            if (preg_match("/^summary_/", $field) && !in_array($field, $metadataFields)) {
                unset($ary[$field]);
            }
        }
		return $ary;
	}

	# variable => label
	public function getPublicationChoices() {
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

	public function getAllChoices() {
		return array_merge($this->getDemographicChoices(), $this->getGrantChoices(), $this->getPublicationChoices());
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

	public function getHTML($prefillCohort = "") {
		$num = self::getMaxNumberOfVariables();
		$html = "";

		$html .= "<p class='centered' style='font-size: 16px;'>\n";
		$html .= "<b>Title</b>: <input type='text' id='title' onblur='checkForDuplicates(\"#title\"); return false;' value='$prefillCohort'><br>\n";
		$html .= "<b>Precedence Rules</b>: XOR &gt; AND &gt; OR\n";
		$html .= "</p>\n";

		$html .= "<br><br>\n";

		$cohorts = new Cohorts($this->token, $this->server, Application::getModule());
		if ($prefillCohort && in_array($prefillCohort, $cohorts->getCohortNames())) {
            $editableCohort = $cohorts->getCohort($prefillCohort);
        } else {
		    $editableCohort = NULL;
        }

		$workshopChoices = $this->getChoices('resources_resource');
		$blankOption = ["" => "---SELECT---"];

		$link = Application::link("this");
		$viewLink = Application::link("cohorts/viewCohort.php");
		$existingCohortJSON = json_encode($cohorts->getCohortNames());
        $demographicChoicesJSON = json_encode(array_merge($blankOption, $this->getDemographicChoices()));
        $grantChoicesJSON = json_encode(array_merge($blankOption, $this->getGrantChoices()));
        $publicationChoicesJSON = json_encode(array_merge($blankOption, $this->getPublicationChoices()));
        $workshopChoicesInOrder = $blankOption + $workshopChoices;
        $workshopChoicesJSON = json_encode($workshopChoicesInOrder);
		$html .= "<script>
		function commit() {
		    let title = $('#title').val();
		    if (!title) {
		        alert('No title specified!')
		    } else {
                let config = {};
                // the below line is the original configuration; must be able to parse in order to maintain backwards-compatibility
		        // config['combiner'] = $('#combination').val();
		        config['rows'] = [];
		        for (let i = 1; i <= ".$num."; i++) {
		            if (($('#type'+i).val() !== '') && ($('#variable'+i).val() !== '')) {
		                let row = {};
		                row['type'] = $('#type'+i).val();
		                row['variable'] = $('#variable'+i).val();
		                if ((i > 1) && $('#combination'+i).is(':visible')) {
		                    row['combiner'] = $('#combination'+i).val();
		                }
		                if ($('#choice'+i).val()) {
		                    row['choice'] = $('#choice'+i).val();
		                    row['comparison'] = $('#comparison'+i).val();
		                    config['rows'].push(row);
		                } else if ($('#type'+i).val() === 'resources') {
		                    config['rows'].push(row);
		                } else if ($('#type'+i).val() !== '') {
		                    row['value'] = $('#value'+i).val();
		                    row['comparison'] = $('#comparison'+i).val();
		                    config['rows'].push(row);
		                }
		            }
		        }
		        if (config['rows'].length > 0) {
		            console.log('saving rows: '+JSON.stringify(config['rows']));
		            presentScreen('Saving...');
		            $.post('$link', { 'redcap_csrf_token': getCSRFToken(), title: title, config: config }, function(data) {
		                clearScreen();
		                if (data.match(/success/)) {
		                    let mssg = 'Upload successful!';
		                    window.location.href = '$viewLink&title='+encodeURI(title)+'&mssg='+encodeURI(mssg);
		                } else {
		                    alert(data);
		                }
		            });
		        }
		    }
		}
		
		function checkForDuplicates(selector) {
		    let existing = $existingCohortJSON;
		    let found = false;
		    for (let i=0; i < existing.length; i++) {
		        if (existing[i].toLowerCase() == $(selector).val().toLowerCase()) {
		            found = true;
		        }
		    }
		    if (found) {
		        $(selector).addClass('red');
		            alert('Duplicate name found! Please change the name of your configuration.');
		        } else {
		            $(selector).removeClass('red');
		        }
		    }
		    
		// function changeCombine(val) {
		//     $('.combinator').html(val);
		// }
		
		function showNextRow(selector, i) {
		    // $(selector).prop('disabled', true);
		    $('#filter'+(parseInt(i)+1)).show();
		    $('#combiner'+(parseInt(i)+1)).show();
		    $('#commitButton').show();
		}
		
		function combineHashes(hash1, hash2) {
		    let newHash = {};
		    let key;
		    for (key in hash1) {
		        newHash[key] = hash1[key];
		    }
		    for (key in hash2) {
		        newHash[key] = hash2[key];
		    }
		    return newHash;
		}
		
		function change(selector, i) {
            let val = $(selector).val();
		    console.log('change '+selector+' '+i+'; val='+val);
		    let options = {};
		    let comparisons = false;
		    let nextSelector = '';
		    let hideSelector = '';
		    if (selector.match(/type/)) {
		        if (val == 'demographic') {
		            options = $demographicChoicesJSON;
		        } else if (val == 'grant') {
		            options = $grantChoicesJSON;
		        } else if (val == 'publication') {
		            options = $publicationChoicesJSON;
		        } else if (val == 'resources') {
		            options = $workshopChoicesJSON;
		        }
		        nextSelector = '#variable'+i;
		        $('#comparison'+i).hide();
		        $('#choice'+i).hide();
		        $('#choice'+i).parent().find('.custom-combobox').hide();
		        $('#value'+i).hide();
		        $('#button'+i).hide();
		    } else if (selector.match(/variable/)) {
		    ";

		$textChoices = ["string", "number"];
		$dateChoices = ["date"];
		$allChoices = array_merge($this->getAllChoices(), $workshopChoices);
		foreach ($allChoices as $var => $label) {
			$html .= "\t\tif (val == '$var') {\n";
			if (preg_match("/^calc_/", $var)) {
				$calcSettings = $this->$var(self::GET_CHOICES);
				$calcSettingsType = $calcSettings->getType();
				if ($calcSettingsType == "choices") {
				    $optionsJSON = json_encode($calcSettings->getChoices());
				    $comparisonsJSON = json_encode(self::getContainsSettings());
					$html .= "
					options = $optionsJSON;
					nextSelector = '#choice'+i;
					$('#value'+i).hide();
					$('#button'+i).hide();
					comparisons = $comparisonsJSON;
					";
				} else if (in_array($calcSettingsType, array_merge($dateChoices, $textChoices))) {
					$inputType = CalcSettings::transformToInputType($calcSettingsType);
					if ($inputType) {
						$html .= "\t\t\t$('#value'+i).prop('type', '$inputType');\n";
					}
					$comparisonsJSON = json_encode($calcSettings->getComparisons());

					$html .= "
					options = false;
					nextSelector = '#value'+i;
					$('#choice'+i).hide();
					$('#choice'+i).parent().find('.custom-combobox').hide();
					$('#button'+i).show();
					if (i == 1) { $('#commitButton').show(); }
					comparisons = $comparisonsJSON;
					";
				}
			} else if (isset($workshopChoices[$var])) {
			    $comparisonsJSON = json_encode(self::getContainsSettings());
				$html .= "
				options = false;
				$('#button'+i).show();
				if (i == 1) { $('#commitButton').show(); }
				comparisons = $comparisonsJSON;
				";
			} else if ($this->getChoices($var)) {
			    $optionsJSON = json_encode($this->getChoices($var));
			    $comparisonsJSON = json_encode(self::getContainsSettings());
				$html .= "
				options = $optionsJSON;
				nextSelector = '#choice'+i;
				$('#value'+i).hide();
				$('#button'+i).hide();
				comparisons = $comparisonsJSON;
				";
			} else {
				# number, string, or date
				$calcSettingsType = CalcSettings::getTypeFromMetadata($var, $this->metadata);
				$inputType = CalcSettings::transformToInputType($calcSettingsType);
				if ($calcSettingsType && $inputType) {
					$calcSettings = new CalcSettings($calcSettingsType);
					$comparisonsJSON = json_encode($calcSettings->getComparisons());
					$html .= "
					options = false;
					nextSelector = '#value'+i;
					$('#choice'+i).hide();
					$('#choice'+i).parent().find('.custom-combobox').hide();
					$('#button'+i).show();
					if (i == 1) { $('#commitButton').show(); }
					comparisons = $comparisonsJSON;
					$('#value'+i).prop('type', '$inputType');
					";
				} else if ($var) {
				    Application::log("Warning! Could not find values for $var ($calcSettingsType $inputType)");
                }
			}
			$html .= "\t\t}\n";
		}

		$html .= "
		} else if (selector.match(/choice/)) {
		    $('#button'+i).show();
		    if (i == 1) { $('#commitButton').show(); }
		}
		if (comparisons) {
		    $('#comparison'+i).find('option').remove();
		    for (let value in comparisons) {
		        $('#comparison'+i).append('<option value=\"'+value+'\">'+comparisons[value]+'</option>');
		    }
		    $('#comparison'+i).show();
		} else if (nextSelector && (nextSelector.match(/type/) || nextSelector.match(/variable/))) {
		    $('#comparison'+i).hide();
		}
		if (nextSelector) {
		    if (options) {
		        $(nextSelector).find('option').remove();
		        if (typeof options[''] != 'undefined') {
		            $(nextSelector).append('<option value=\"\" selected>---SELECT---</option>');
		        }
		        for (let value in options) {
		            if (value !== '') {
		                $(nextSelector).append('<option value=\"'+value+'\">'+options[value]+'</option>');
		            }
		        }
		        if (nextSelector.match(/choice/)) {
		            $(nextSelector).parent().find('.custom-combobox').show();
		        } else {
		            $(nextSelector).show();
		        }
		    } else {
		        $(nextSelector).show();
		    }
		}
    }
    ";
		if ($editableCohort) {
            $defaultRows = $editableCohort->getRows();
            if (!empty($defaultRows)) {
                $html .= "
    		    $(document).ready(function() {
	    	    ";

                # order is important
                $fields = ["type", "variable", "choice", "comparison", "value"];
                for ($i = 1; $i <= count($defaultRows); $i++) {
                    $defaultRow = $defaultRows[$i - 1];
                    if (!empty($defaultRow)) {
                        foreach ($fields as $field) {
                            if ($defaultRow[$field]) {
                                $html .= "\t\t\t\t\t$('#$field$i').val('{$defaultRow[$field]}').trigger('change');\n";
                                if ($field == "choice") {
                                    $html .= "\t\t\t\t\tlet text$i = $('#$field$i option:selected').text();\n";
                                    $html .= "\t\t\t\t\t$('#$field$i').parent().find('.custom-combobox-input').val(text$i).trigger('change');\n";
                                }
                            }
                        }
                        $combiner = $defaultRow['combiner'] ?? $editableCohort->getCombiner();
                        $html .= "\t\t\t\t\t$('#combination$i').val('$combiner').trigger('change');\n";
                        $html .= "\t\t\t\t\tshowNextRow('.options$i', '$i');\n";
                    }
                }

                $html .= "
		        });
		        ";
            }
        } 

		$html .= "</script>";

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
			$html .= "<button onclick='showNextRow(\".options$i\", \"$i\"); return false;' class='biggerButton' style='display: none;' id='button$i'>Add Row</button>\n";
			$html .= "</td>\n";
			$html .= "</tr>\n";
			if ($i != $num) {
				$html .= "<tr><td class='cells' colspan='5'><div id='combiner".($i+1)."' class='combinator' style='display: none;'>".self::makeCombinerSelect($i+1)."</div></td></tr>\n";
			} else {
				$html .= "<tr><td colspan='5' class='centered'><button onclick='commit(); return false;' id='commitButton' style='display: none;' class='biggerButton'>Commit Filter</button></td></tr>\n";
			}
		}

		$html .= "</table>\n";
		$html .= "<br><br><br><br><br><br>";

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
                    $in[] = $recordId;
                }
            }
        } else {
            foreach ($redcapData as $recordId => $rows) {
                if ($config->isIn($rows, $this)) {
                    $in[] = $recordId;
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
