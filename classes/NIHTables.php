<?php

namespace Vanderbilt\CareerDevLibrary;

use function Vanderbilt\FlightTrackerExternalModule\avg;
use function Vanderbilt\FlightTrackerExternalModule\json_encode_with_spaces;

require_once(dirname(__FILE__)."/Download.php");
require_once(dirname(__FILE__)."/Citation.php");
require_once(dirname(__FILE__)."/Grants.php");
require_once(dirname(__FILE__)."/Publications.php");
require_once(dirname(__FILE__)."/Scholar.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/REDCapManagement.php");

class NIHTables {
	public function __construct($token, $server, $pid, $metadata = array()) {
		$this->token = $token;
		$this->server = $server;
		$this->pid = $pid;
		if (empty($metadata)) {
			$this->metadata = Download::metadata($this->token, $this->server);
		} else {
			$this->metadata = $metadata;
		}
	}

	public static function formatTableNum($tableNum) {
		if (strlen($tableNum) > 2) {
			$front = substr($tableNum, 0, 2);
			$back = substr($tableNum, 2);
			if (self::isRoman($back)) {
                return $front . " Part " . $back;
            }
		}
		return $tableNum;
	}

	public static function getTableHeader($table) {
		$five = "Publications of Those in Training";
		$six = "Applicants, Entrants, and Their Characteristics for the Past Five Years";
		$eight = "Program Outcomes";

		$group = "Postdoctoral";
		if (preg_match("/A/i", $table)) {
			$group = "Predoctoral";
		}

		if ($table == "5") {
			return $five;
		} else if (preg_match("/^5.$/", $table)) {
			return "$five: $group";
		} else if ($table == "6") {
			return $six;
		} else if (preg_match("/^6.II$/", $table)) {
			return "$six: $group - Characteristics";
		} else if ($table == "8") {
			return $eight;
		} else if (preg_match("/^8.I$/", $table)) {
			return "$eight: $group - Those Appointed to the Training Grant";
		} else if (preg_match("/^8.II$/", $table)) {
			return "$eight: $group - Those Clearly Associated with the Training Grant";
		} else if (preg_match("/^8.III$/", $table)) {
			return "$eight: $group - Recent Graduates";
		} else if (preg_match("/^8.IV$/", $table)) {
			return "$eight: $group - Program Statistics";
		} else if ($table == "Common Metrics") {
		    return "Common Metrics";
        }
		return $table;
	}

    public function getHTML($table) {
		if (self::beginsWith($table, array("5A", "5B"))) {
			$data = $this->get5Data($table);
			return self::makeDataIntoHTML($data);
		} else if (self::beginsWith($table, array("6A", "6B"))) {
			$html = "";
			foreach (self::get6Years() as $year) {
				$data = $this->get6Data($table, $year);
				$html .= self::makeDataIntoHTML($data)."<br><br>";
			}
			return $html;
		} else if (self::beginsWith($table, array("8A", "8C"))) {
			$data = $this->get8Data($table);
			return $this->makeJS().self::makeDataIntoHTML($data);
		} else if ($table == "Common Metrics") {
            $data = $this->getCommonMetricsData($table);
            return self::makeDataIntoHTML($data);
        }
		return "";
	}

	private function makeJS() {
	    $html = "";
        if ($this->hasSupportSummary()) {
            $page = Application::link("reporting/updateSupportSummary.php");
            $html .= "
            <script>
                $(document).ready(function() {
                   $('textarea.support_summary').keyup(function() {
                       var record = $(this).attr('record');
                       $('button.support_summary[record='+record+']').show();
                   });
                });
    
                function saveSupportSummary(record) {
                    let textareaSelector = 'textarea.support_summary[record='+record+']';
                    let text = $(textareaSelector).val();
                    if (record) {
                       $.post('$page', { record: record, text: text }, function(data) {
                            console.log('Updated record '+record+': '+data);
                            $('button.support_summary[record='+record+']').hide();
                       });
                    }
                }
            </script>
            ";
        }
	    return $html;
    }

	private function getCommonMetricsData($table) {
	    $cols1 = array(
            "First Name" => array("field" => "identifier_first_name", "required" => TRUE),
            "Last Name" => array("field" => "identifier_last_name", "required" => TRUE),
        );
	    $cols2K = array(
            "Year Funding Started" => array(
                "main" => "summary_award_date_*",
                "helper" => "summary_award_type_*",
                "helperValues" => [1, 2],
                "required" => TRUE,
                ),
            "Year Funding Ended" => array(
                "main" => "summary_award_end_date_*",
                "helper" => "summary_award_type_*",
                "helperValues" => [1, 2],
            ),
        );

	    $cols2T = array(
            "Year Funding Started" => array(
                "main" => "custom_start",
                "helper" => "custom_role",
                "helperValues" => [5, 6, 7],
                "required" => TRUE,
            ),
            "Year Funding Ended" => array(
                "main" => "custom_end",
                "helper" => "custom_role",
                "helperValues" => [5, 6, 7],
            ),
        );

	    $cols3 = array(
            "Classification" => array("field" => "identifier_left_job_category"),
        );

	    $cols4 = array(
            "Engaged in Research" => "Y",
            "URM?" => array("field" => "summary_urm"),
            "Gender" => array("field" => "summary_gender"),
            "Notes" => "",
        );

        $grantClass = Application::getSetting("grant_class");
	    if ($grantClass == "T") {
	        $cols = array_merge($cols1, $cols2T, $cols3, $cols4);
        } else if ($grantClass == "K") {
            $cols = array_merge($cols1, $cols2K, $cols4);
        } else {
	        # Other
	        $cols = array();
        }

	    $fieldsToDownload = $this->getFieldsFromDriver($cols);
	    $recordIds = Download::recordIds($this->token, $this->server);
	    $choices = $this->getAlteredChoices();
        $data = array();
	    foreach ($recordIds as $recordId) {
	        $values = $this->fillInCommonMetricsForRecord($recordId, $fieldsToDownload, $cols, $choices);
	        if ($values) {
                array_push($data, $values);
            }
        }
	    return $data;
    }

    private function fillInCommonMetricsForRecord($recordId, $fieldsToDownload, $cols, $choices = array()) {
        $redcapData = Download::fieldsForRecords($this->token, $this->server, $fieldsToDownload, array($recordId));
        $values = array();
        foreach ($cols as $header => $specs) {
            $values[$header] = "";
            if (!is_array($specs)) {
                # hard-coded value
                $values[$header] = $specs;
            } else if ($specs['field']) {
                $values[$header] = $this->searchForField($redcapData, $specs['field'], $choices);
            } else {
                $values[$header] = $this->processHelperSpecs($redcapData, $specs, $choices);
            }
        }
        if (self::hasRequiredValues($values, $cols)) {
            return $values;
        }
        return FALSE;
    }

    private function processHelperSpecs($redcapData, $specs, $choices = array()) {
        $helperValues = $specs['helperValues'];
        $pattern = "/_\*/";
        if (empty($choices)) {
            $choices = $this->getAlteredChoices();
        }
        if (preg_match($pattern, $specs["main"]) && preg_match($pattern, $specs["helper"])) {
            $mainFields = self::explodeWildcardField($specs['main']);
            $helperFields = self::explodeWildcardField($specs['helper']);
            for ($i = 0; $i < count($mainFields) && $i < count($helperFields); $i++) {
                $mainField = $mainFields[$i];
                $helperField = $helperFields[$i];
                foreach ($redcapData as $row) {
                    if ($row[$helperField] && in_array($row[$helperField], $helperValues) && $row[$mainField]) {
                        if ($choices[$mainField]) {
                            return $choices[$mainField][$row[$mainField]];
                        }
                        return $row[$mainField];
                    }
                }
            }
        } else {
            $helperField = $specs['helper'];
            $mainField = $specs['main'];
            foreach ($redcapData as $row) {
                if ($row[$helperField] && in_array($row[$helperField], $helperValues) && $row[$mainField]) {
                    if ($choices[$mainField]) {
                        return $choices[$mainField][$row[$mainField]];
                    }
                    return $row[$mainField];
                }
            }
        }
        return "";
	}

    private function searchForField($redcapData, $field, $choices = array()) {
        if (!self::isValidField($field, $this->metadata)) {
            return $field;
        } else {
            if (empty($choices)) {
                $choices = $this->getAlteredChoices();
            }
            foreach ($redcapData as $row) {
                if ($row[$field] !== "") {
                    if ($choices[$field]) {
                        return $choices[$field][$row[$field]];
                    }
                    return $row[$field];
                }
            }
        }
        return "";
	}

    private static function hasRequiredValues($values, $cols) {
	    foreach ($values as $header => $value) {
	        if (is_array($cols[$header]) && $cols[$header]["required"] && !$value) {
	            return FALSE;
            }
        }
	    return TRUE;
    }

    private function getFieldsFromDriver($cols) {
	    $acceptableLabels = array("field", "main", "helper");
        $fieldsToDownload = array("record_id");
        foreach ($cols as $header => $specs) {
            if (is_array($specs)) {
                foreach ($specs as $label => $field) {
                    if (in_array($label, $acceptableLabels)) {
                        $explodedFields = self::explodeWildcardField($field);
                        foreach ($explodedFields as $explodedField) {
                            if (self::isValidField($field, $this->metadata) && !in_array($explodedField, $fieldsToDownload)) {
                                array_push($fieldsToDownload, $explodedField);
                            }
                        }
                    }
                }
            }
        }
        return $fieldsToDownload;
    }

    private static function isValidField($field, $metadata) {
        $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
        return in_array($field, $metadataFields);
    }

    private static function explodeWildcardField($field) {
        $fields = array();
        if (preg_match("/_\*/", $field)) {
            $master = $field;
            for ($i = 1; $i <= MAX_GRANTS; $i++) {
                $field = preg_replace("/_\*/", "_" . $i, $master);
                if (!in_array($field, $fields)) {
                    array_push($fields, $field);
                }
            }
        } else {
            array_push($fields, $field);
        }
        return $fields;
    }

	private static function get6Years() {
		$numYears = 5;
		if (date("n") >= 7) {
			$endYear = date("Y") + 1;
		} else {
			$endYear = date("Y");
		}
		$startYear = $endYear - $numYears;

		$years = array();
		for ($i = $numYears - 1; $i >= 0; $i--) {
			$span = ($startYear + $i)."-".($startYear + $i + 1);
			$ary = array(
					"begin" => strtotime(($startYear + $i)."-07-01 00:00:00"),
					"end" => strtotime(($startYear + $i + 1)."-06-30 23:59:59"),
					"span" => $span,
					);
			if ($i == $numYears - 1) {
				$ary["current"] = TRUE;
			}
			array_push($years, $ary);
		}

		return $years;
	}

	private function get6Data($table, $yearspan) {
		$data = array();
		if (preg_match("/^6[AB]II$/", $table)) {
			return $this->get6IIData($table, $yearspan);
		}
		return $data;
	}

	private function get6IIData($table, $yearspan) {
	    $choices = $this->getAlteredChoices();
		$data = array();
		$names = $this->downloadRelevantNames($table);
		$doctorateInstitutions = Download::doctorateInstitutions($this->token, $this->server, $this->metadata);
		$trainingTypes = self::getTrainingTypes($table);
		if (!empty($names)) {
			$trainingGrantData = Download::trainingGrants($this->token, $this->server, array(), $trainingTypes);
		} else {
		    $trainingGrantData = array();
        }
		if ($yearspan['current']) {
			$title = "Most Recent Program Year: ".$yearspan['span'];
		} else {
			$title = "Previous Year: ".$yearspan['span'];
		}

		$fields = array(
				"record_id",
				"summary_urm",
				"summary_disability",
				"check_undergrad_gpa",
				"check_undergrad_institution",
				"check_degree0_prior_rsch",
                "summary_training_start",
                "summary_training_end",
				);
		# also, prior institutions, date of entry into program

        $newProgramEntrants = "New Entrants to the Program";
        $newEligibleEntrants = "New Entrants Eligible for Support";
        $newAppointments = "New Entrants Appointed to this Grant (Renewal/Revision Applications Only)";
		$allClasses = array(
					$newProgramEntrants,
					$newEligibleEntrants,
					$newAppointments,
					);
		$resultData = array(
					"urm" => array(),
					"disability" => array(),
					"gpa" => array(),
					"institutions" => array(),
					"research_months" => array(),
					);
		foreach ($resultData as $field => $values) {
			foreach ($allClasses as $currClass) {
				$resultData[$field][$currClass] = array();
			}
		}
		foreach ($names as $recordId => $name) {
		    $currentGrantData = self::getTrainingGrantsForRecord($trainingGrantData, $recordId);
			if (self::enteredDuringYear($yearspan, $recordId, $currentGrantData, $trainingTypes)) {
                $recordData = Download::fieldsForRecords($this->token, $this->server, $fields, array($recordId));
                $recordClasses = array($newAppointments);
				foreach ($recordData as $row) {
					if ($row['redcap_repeat_instrument'] == "") {
						foreach ($recordClasses as $currClass) {
							if ($row['summary_urm'] == "1") {
								array_push($resultData["urm"][$currClass], "1");
							} else {
								array_push($resultData["urm"][$currClass], "0");
							}
							if ($row['summary_disability'] == "1") {
								array_push($resultData["disability"][$currClass], "1");
							} else {
								array_push($resultData["disability"][$currClass], "0");
							}

							if ($row['check_undergrad_gpa']) {
								array_push($resultData["gpa"][$currClass], $row['check_undergrad_gpa']);
							}

                            if (self::isPredoc($table)) {
                                # use undergrad institution
                                if ($row['check_undergrad_institution'] !== "") {
                                    $institution = $row['check_undergrad_institution'];
                                    if (is_numeric($institution)) {
                                        $institution = $choices['check_undergrad_institution'][$institution];
                                    }
                                    array_push($resultData["institutions"][$currClass], $institution);
                                }
                            } else if (self::isPostdoc($table)) {
                                #  use last doctorate-granting institution
                                if ($doctorateInstitutions[$recordId] && !empty($doctorateInstitutions[$recordId])) {
                                    array_push($resultData["institutions"][$currClass], implode("/", $doctorateInstitutions[$recordId]));
                                }
                            }

							if (($row['check_degree0_prior_rsch'] !== "") && is_numeric($row['check_degree0_prior_rsch'])) {
								array_push($resultData["research_months"][$currClass], $row['check_degree0_prior_rsch']);
							}
						}
					}
				}
			}
		}

		$data[0] = array(
				$title => "Mean Months of Prior, Full-Time Research Experience (range)",
				"Total Applicant Pool" => "",
				"Applicants Eligible for Support" => "",
				$newProgramEntrants => self::findAverageAndRange($resultData["research_months"][$newProgramEntrants]),
				$newEligibleEntrants => self::findAverageAndRange($resultData["research_months"][$newEligibleEntrants]),
				$newAppointments => self::findAverageAndRange($resultData["research_months"][$newAppointments]),
				);
		$data[1] = array(
				$title => "Prior Institutions",
				"Total Applicant Pool" => "",
				"Applicants Eligible for Support" => "",
				$newProgramEntrants => self::$NA,
				$newEligibleEntrants => self::$NA,
				$newAppointments => self::findInstitutions($resultData["institutions"][$newAppointments]),
				);
		$data[2] = array(
				$title => "Percent with a Disability",
				"Total Applicant Pool" => "",
				"Applicants Eligible for Support" => "",
				$newProgramEntrants => self::findPercent($resultData["disability"][$newProgramEntrants]),
				$newEligibleEntrants => self::findPercent($resultData["disability"][$newEligibleEntrants]),
				$newAppointments => self::findPercent($resultData["disability"][$newAppointments]),
				);
		$data[3] = array(
				$title => "Percent from Underrepresented Racial &amp; Ethnic Groups",
				"Total Applicant Pool" => "",
				"Applicants Eligible for Support" => "",
				$newProgramEntrants => self::findPercent($resultData["urm"][$newProgramEntrants]),
				$newEligibleEntrants => self::findPercent($resultData["urm"][$newEligibleEntrants]),
				$newAppointments => self::findPercent($resultData["urm"][$newAppointments]),
				);
		$data[4] = array(
				$title => "Mean GPA (range)",
				"Total Applicant Pool" => "",
				"Applicants Eligible for Support" => "",
				$newProgramEntrants => self::findAverageAndRange($resultData["gpa"][$newProgramEntrants]),
				$newEligibleEntrants => self::findAverageAndRange($resultData["gpa"][$newEligibleEntrants]),
				$newAppointments => self::findAverageAndRange($resultData["gpa"][$newAppointments]),
				);

		return $data;
	}

	private static function findPercent($ary) {
		if (count($ary) == 0) {
			return self::$NA;
		}
		$cnt = 0;
		foreach ($ary as $item) {
			if ($item) {
				$cnt++;
			}
		}
		return ceil($cnt * 100 / count($ary))."%";
	}

	private static function enteredDuringYear($yearspan, $recordId, $trainingGrantData, $trainingTypes) {
	    foreach ($trainingGrantData as $row) {
	        if (($row['record_id'] == $recordId) && in_array($row['custom_role'], $trainingTypes) && $row['custom_start']) {
	            $trainingStartTs = strtotime($row['custom_start']);
	            if (($trainingStartTs >= $yearspan['begin']) && ($trainingStartTs <= $yearspan['end'])) {
	                return TRUE;
	            }
            }
        }
	    return FALSE;
    }

	private static function findAverageAndRange($ary) {
		if (count($ary) == 0) {
			return self::$NA;
		}
		$mean = ceil(array_sum($ary) / count($ary) * 10) / 10;
		$min = ceil(min($ary) * 10) / 10;
		$max = ceil(max($ary) * 10) / 10;
		return sprintf("%.1f (%.1f-%.1f)", $mean, $min, $max);
	}

	private static function findInstitutions($ary) {
	    if (count($ary) == 0) {
	        return self::$NA;
        }

	    $countHash = array();
	    foreach ($ary as $institution) {
	        if (!isset($countHash[$institution])) {
	            $countHash[$institution] = 0;
            }
	        $countHash[$institution]++;
        }
	    ksort($countHash);

	    $list = array();
	    foreach ($countHash as $institution => $count) {
	        $item = $institution;
	        if ($count > 1) {
	            $item .= " (".$count.")";
            }
	        array_push($list, $item);
        }
	    return implode("<br>", $list);
    }

    private static function getTrainingGrantsForRecord($grantData, $recordId) {
	    $rows = array();
        foreach ($grantData as $row) {
            if ($row['record_id'] == $recordId) {
                array_push($rows, $row);
            }
        }
        return $rows;
    }

    private static function getEarliestStartDate($redcapData, $recordId) {
        $ts = FALSE;
        foreach ($redcapData as $row) {
            if ($row['record_id'] == $recordId) {
                $dateUnderConsideration = "";
                if ($row['redcap_repeat_instrument'] == "custom_grant") {
                    $dateUnderConsideration = $row['custom_start'];
                }
                if ($dateUnderConsideration) {
                    $startTs = strtotime($dateUnderConsideration);
                    if (!$ts || ($ts < $startTs)) {
                        $ts = $startTs;
                    }
                }
            }
        }
        if (!$ts) {
            return "";
        }
        return date("Y-m-d", $ts);
    }

	private function getAllDegreeFields() {
	    $field = "summary_degrees";
        $vars = Scholar::getDefaultOrder($field);
        $scholar = new Scholar($this->token, $this->server, $this->metadata, $this->pid);
        $scholar->getOrder($vars, $field);
        $metadataFields = REDCapManagement::getFieldsFromMetadata($this->metadata);
        $fields = array();
        foreach ($vars as $ary) {
            foreach ($ary as $field => $source) {
                if (in_array($field, $metadataFields)) {
                    array_push($fields, $field);
                }
            }
        }
        return $fields;
    }

    private function getAllDegreeYearFields($degreeFields) {
	    if (!array($degreeFields)) {
            $degreeFields = array($degreeFields);
        }
	    $degreePrefixes = REDCapManagement::transformFieldsIntoPrefixes($degreeFields);
	    $degreeYearFields = array();
	    foreach (REDCapManagement::getFieldsFromMetadata($this->metadata) as $currField) {
	        $currPrefix = REDCapManagement::getPrefix($currField);
	        if (in_array($currPrefix, $degreePrefixes)) {
	            # screen for dates and numeric
                $metadataRow = REDCapManagement::getRowForFieldFromMetadata($currField, $this->metadata);
                if (REDCapManagement::matchAtLeastOneRegex(array("/^date_/", "/^datetime_/", "/^integer/"), $metadataRow['text_validation_type_or_show_slider_number'])) {
                    array_push($degreeYearFields, $currField);
                }
	        }
        }
	    return $degreeYearFields;
    }

    private function getTerminalDegreesAndYears($recordId) {
        $degreeFields = $this->getAllDegreeFields();
        $fields = array_unique(array_merge(array("record_id", "summary_training_start", "summary_training_end"), $degreeFields));
        $redcapData = Download::fieldsForRecords($this->token, $this->server, $fields, array($recordId));
        $choices = $this->getAlteredChoices();
        $doctorateRegExes = array("/MD/", "/PhD/i", "/DPhil/i", "/PharmD/i", "/PsyD/i");
        $doctorateDegreesAndYears = array();
        $predocDegreesAnYears = array();
        foreach ($redcapData as $row) {
            foreach ($degreeFields as $field) {
                if ($row[$field]) {
                    if ($choices[$field] && isset($choices[$field][$row[$field]])) {
                        $value = $choices[$field][$row[$field]];
                    } else {
                        $value = $row[$field];
                    }
                    foreach ($doctorateRegExes as $regEx) {
                        $degreeYearFields = $this->getAllDegreeYearFields($field);
                        $year = "Unknown Year";
                        foreach ($degreeYearFields as $yearField) {
                            if (is_integer($row[$yearField])) {
                                $year = $row[$yearField];
                            } else if (strtotime($row[$yearField])) {
                                $year = REDCapManagement::getYear($row[$yearField]);
                            } else if ($row[$yearField]) {
                                $year = $row[$yearField];
                            }
                        }
                        if (preg_match($regEx, $value)) {
                            $doctorateDegreesAndYears[$value] = $year;
                        } else {
                            $predocDegreesAnYears[$value] = $year;
                        }
                    }
                }
            }
        }
        if (empty($doctorateDegreesAndYears) && empty($predocDegreesAnYears)) {
            if (REDCapManagement::findField($redcapData, $recordId, "summary_training_end")) {
                return array("None Received" => "");
            } else {
                return array("In Training" => "");
            }
        } else if (!empty($doctorateDegreesAndYears)) {
            return $doctorateDegreesAndYears;
        } else {
            return $predocDegreesAnYears;
        }
	}

	private function getTerminalDegreeAndYear($recordId) {
	    $degreesAndYears = $this->getTerminalDegreesAndYears($recordId);
        arsort($degreesAndYears);
        foreach ($degreesAndYears as $degree => $year) {
            if ($degree == "In Training") {
                return $degree;
            }
            return $degree." ".$year;
        }
    }

    private static function getResearchTopicSource() {
	    global $grantClass;
	    if ($grantClass == "K") {
	        return "Grant Title of K Award";
        } else if ($grantClass == "T") {
	        return "check_degree0_topic";
        } else {
	        return "";
        }
    }

    private function getResearchTopic($recordId) {
	    global $grantClass;
        if (method_exists("Application", "getGrantClasses")) {
            $validGrantClasses = Application::getGrantClasses();
        } else {
            $validGrantClasses = array("K", "T", "Other", "");
        }

        if ($grantClass == "K") {
            # if K => K Title
            $fields = array_unique(array_merge(
                array("record_id"),
                self::explodeWildcardField("summary_award_type_*"),
                self::explodeWildcardField("summary_award_title_*")
            ));
            $redcapData = Download::fieldsForRecords($this->token, $this->server, $fields, array($recordId));
            $kTypes = array(1, 2, 3, 4);
            $lastValidKTitle = "";
            foreach ($redcapData as $row) {
                for ($i = 1; $i <= MAX_GRANTS; $i++) {
                    if (in_array($row['summary_award_type_'.$i], $kTypes) && $row['summary_award_title_'.$i]) {
                        $lastValidKTitle = $row['summary_award_title_'.$i];
                    }
                }
            }
            return $lastValidKTitle;
        } else if ($grantClass == "T") {
            # if T => from survey (check_degree0_topic)
            $topics = Download::oneField($this->token, $this->server, "check_degree0_topic");
            if (isset($topics[$recordId])) {
                return $topics[$recordId];
            }
            return "";
        } else if (($grantClass == "Other") || ($grantClass == "")) {
            # if other => blank
            return "";
        } else if (in_array($grantClass, $validGrantClasses)) {
            throw new \Exception("There is a new grant class that is not currently handled.");
        } else {
            throw new \Exception("Your grant class ($grantClass) is invalid!");
        }
    }
    
    private function getAlteredChoices() {
        if (!$this->choices) {
            $this->choices = array();
            $redcapChoices = REDCapManagement::getChoices($this->metadata);
            foreach ($redcapChoices as $field => $fieldChoices) {
                if (preg_match("/job_category/", $field)) {
                    $this->choices[$field] = self::translateJobChoices($fieldChoices);
                } else {
                    $this->choices[$field] = $fieldChoices;
                }
            }

            foreach ($this->metadata as $row) {
                $field = $row['field_name'];
                if ($row['field_type'] == "yesno") {
                    $this->choices[$field] = array("0" => "No", "1" => "Yes");
                } else if ($row['field_type'] == "truefalse") {
                    $this->choices[$field] = array("0" => "False", "1" => "True");
                }
            }
        }
        return $this->choices;
    }

    private static function translateJobChoices($fieldChoices) {
	    $newChoices = array();
	    # 1, Academia, still research-dominant (PI)
        # 5, Academia, still research-dominant (Staff)
        # 2, Academia, not research dominant
        # 7, Academia, training program
        # 3, Private practice
        # 4, Industry, federal, non-profit, or other - research dominant
        # 6, Industry, federal, non-profit, or other - not research dominant
	    foreach ($fieldChoices as $index => $label) {
	        if ($index == 1) {
	            $label = "Researcher/Faculty";
            } else if ($index == 5) {
	            $label = "Researcher";
            } else if ($index == 2) {
                $label = "Faculty";
            } else if ($index == 7) {
                $label = "Further Training";
            } else if ($index == 3) {
                $label = "Private Practice";
            } else if ($index == 4) {
                $label = "Researcher/Industry";
            } else if ($index == 6) {
                $label = "Industry";
            }
	        $newChoices[$index] = $label;
        }
	    return $newChoices;
    }

    private function getAllPositionsInOrder($redcapData, $recordId) {
	    $positions = array();
	    $choices = $this->getAlteredChoices();
        foreach ($redcapData as $row) {
            if ($row['record_id'] == $recordId) {
                if ($row['redcap_repeat_instrument'] == "position_change") {
                    $date = $row['promotion_in_effect'];
                    $ts = 0;
                    if ($date && strtotime($date)) {
                        $ts = strtotime($date);
                    }

                    # Position<br>Department<br>Institution<br>Activity
                    $descriptions = array();
                    $descriptions["title"] = $row['promotion_job_title'];
                    $descriptions["department"] = $row['promotion_department'] ? $choices["promotion_department"][$row["promotion_department"]] : $row['promotion_division'];
                    $descriptions["institution"] = $row['promotion_institution'];
                    $descriptions["activity"] = self::translateJobCategoryToActivity($row['promotion_job_category']);
                    self::fillInBlankValues($descriptions);
                    $positions[$ts] = implode("<br>", array_values($descriptions));
                }
            }
        }
        arsort($positions);
        return array_values($positions);
    }

    private static function translateJobCategoryToActivity($jobCategory) {
	    # 1, Academia, still research-dominant (PI)
        # 5, Academia, still research-dominant (Staff)
        # 2, Academia, not research dominant
        # 7, Academia, training program
        # 3, Private practice
        # 4, Industry, federal, non-profit, or other - research dominant
        # 6, Industry, federal, non-profit, or other - not research dominant
	    $categories = array(
	        "Research-Intensive" => array(1, 5, 4),
            "Research-Related" => array(2, 6),
            "Further Training" => array(7),
            "Other" => array(3),
        );
	    foreach ($categories as $key => $jobCategories) {
	        if (in_array($jobCategory, $jobCategories)) {
	            return $key;
            }
        }
	    return "";
    }

    private static function fillInBlankValues(&$ary) {
	    foreach ($ary as $key => $value) {
	        if (!$value) {
	            $ary[$key] = self::makeComment(self::$naMssg);
            }
        }
    }

    private function getInitialPosition($redcapData, $recordId) {
        $positions = $this->getAllPositionsInOrder($redcapData, $recordId);
        if (count($positions) > 0) {
            return $positions[0];
        }
        return "";
    }
    private function getCurrentPosition($redcapData, $recordId) {
        $positions = $this->getAllPositionsInOrder($redcapData, $recordId);
        if (count($positions) > 0) {
            return $positions[count($positions) - 1];
        }
        return "";
    }

    private static function abbreviateAwardNo($awardNo, $fundingSource = "Other", $fundingType = "Other") {
        $ary = Grant::parseNumber($awardNo);
        $supportSource = "Other";
        $supportType = "Other";

        if ($fundingType == "Research Assistantship") {
            $supportType = "RA";
        } else if ($fundingType == "Teaching Assistantship") {
            $supportType = "TA";
        } else if ($fundingType == "Fellowship") {
            $supportType = "F";
        } else if ($fundingType == "Training Grant") {
            $supportType = "TG";
        } else if ($fundingType == "Scholarship") {
            $supportType = "S";
        } else if ($ary["activity_code"]) {
            $supportType = $ary["activity_code"];
        }

        if ($fundingSource == "NSF") {
            $supportSource = "NSF";
        } else if ($fundingSource == "Other Federal") {
            $supportSource = "Other Fed";
        } else if ($fundingSource == "University") {
            $supportSource = "Univ";
        } else if ($fundingSource == "Foundation") {
            $supportSource = "Fdn";
        } else if ($fundingSource == "Non-US") {
            $supportSource = "Non-US";
        } else if ($fundingSource == "NIH") {
            $supportSource = $ary["funding_institute"];
        }
	    return "$supportSource $supportType";
	}

	private static function getSourceAndType($type) {
	    $ks = array(1, 2, 3, 4);
	    $trainingTypes = array(10);
	    $source = "Other";
	    $fundingType = "Other";
        if (in_array($type, $trainingTypes)) {
            return array("", "");
        }
	    if (in_array($type, $ks)) {
	        if ($type == "3") {
                $source = "Fellowship";
                $fundingType = "Foundation";
            } else if ($type == "1") {
	            $source = "University";
	            $fundingType = "TG";
            } else {
	            # K12 or Individual K
                $source = "NIH";
                $fundingType = "";
            }
        }
	    return array($source, $fundingType);
    }

    private function getGrantSummary($recordId) {
        # Subsequent Grant(s)/Role/Year Awarded
        $redcapData = Download::fieldsForRecords($this->token, $this->server, Application::$summaryFields, array($recordId));
        $names = Download::names($this->token, $this->server);
        $grantDescriptions = array();
        foreach ($redcapData as $row) {
            if ($row['record_id'] == $recordId) {
                for ($i = 1; $i <= MAX_GRANTS; $i++) {
                    $awardNoField = "summary_award_sponsorno_".$i;
                    $dateField = "summary_award_date_".$i;
                    $typeField = "summary_award_type_".$i;
                    $roleField = "summary_award_role_".$i;
                    if ($row[$awardNoField] && $row[$dateField]) {
                        $role = $row[$roleField];
                        $year = REDCapManagement::getYear($row[$dateField]);
                        list($fundingSource, $fundingType) = self::getSourceAndType($row[$typeField]);
                        if ($fundingSource  && $fundingType) {
                            $shortAwardNo = self::abbreviateAwardNo($row[$awardNoField], $fundingSource, $fundingType);
                            $ary = array($shortAwardNo, $role, $year);
                            array_push($grantDescriptions, implode(" / ", $ary));
                        }
                    }
                }
            }
        }
        return implode("<br><br>", $grantDescriptions);
    }

    # best guess
    private static function transformNamesToLastFirst($aryOfNames) {
	    $transformedNames = array();
	    foreach ($aryOfNames as $name) {
            $nodes = array();
            $lastPosition = -1;
            $firstPosition = -1;
            if (preg_match("/,/", $name)) {
                $nodes = preg_split("/\s*,\s*/", $name);
                if (count($nodes) >= 2) {
                    $lastPosition = 0;
                    $firstPosition = 1;
                }
            } else if (preg_match("/\s/", $name)) {
                $nodes = preg_split("/\s+/", $name);
                $lastPosition = 1;
                $firstPosition = 0;
            }

            $last = "";
            $first = "";
            if (($lastPosition >= 0) && ($firstPosition >= 0)) {
                if (strlen($nodes[$lastPosition]) == 1) {
                    # switch if old last name is just an initial; assume initials are first names
                    $a = $firstPosition;
                    $firstPosition = $lastPosition;
                    $lastPosition = $a;
                }
                if ((count($nodes) > $lastPosition) && (count($nodes) > $firstPosition)) {
                    $last = $nodes[$lastPosition];
                    $first = $nodes[$firstPosition];
                }
            }
            array_push($transformedNames, "$last, $first");
        }
	    return $transformedNames;
    }

    private function hasSupportSummary() {
        $metadataFields = REDCapManagement::getFieldsFromMetadata($this->metadata);
        return in_array("identifier_support_summary", $metadataFields);
    }

    private function get8Data($table) {
	    $part = self::getPartNumber($table);
	    if (in_array($part, [1, 2, 3])) {
            $names = $this->downloadRelevantNames($table);
	        $firstNames = Download::firstnames($this->token, $this->server);
	        $lastNames = Download::lastnames($this->token, $this->server);
            $mentors = Download::primaryMentors($this->token, $this->server);
            $trainingGrants = Download::trainingGrants($this->token, $this->server);
            $hasSupportSummary = $this->hasSupportSummary();
            if ($hasSupportSummary) {
                $supportSummaries = Download::oneField($this->token, $this->server, "identifier_support_summary");
            } else {
                $supportSummaries = array();
            }

	        $data = [];
	        $baseLineStart = (date("Y") - self::$maxYearsOfGrantReporting)."-01-01";
	        foreach ($names as $recordId => $name) {
                $currentTrainingGrants = self::getTrainingGrantsForRecord($trainingGrants, $recordId);
                $positionData = Download::fieldsForRecords($this->token, $this->server, Application::$positionFields, array($recordId));

	            $startDate = self::getEarliestStartDate($currentTrainingGrants, $recordId);
	            if (!$startDate) {
	                $countingStartDate = $baseLineStart;
                } else {
                    if (strtotime($startDate) > strtotime($baseLineStart)) {
                        $countingStartDate = $startDate;
                    } else {
                        $countingStartDate = $baseLineStart;
                    }
                }
	            $terminalDegree = $this->getTerminalDegreeAndYear($recordId);
	            $topic = $this->getResearchTopic($recordId);
	            $initialPos = $this->getInitialPosition($positionData, $recordId);
	            $currentPos = $this->getCurrentPosition($positionData, $recordId);
	            $subsequentGrants = $this->getGrantSummary($recordId);
                $supportSummary = $supportSummaries[$recordId] ? $supportSummaries[$recordId] : "";

                if ($hasSupportSummary) {
                    $supportSummaryHTML = self::makeComment("Please Edit")."<br><textarea class='support_summary' record='$recordId'>$supportSummary</textarea><br><button class='support_summary' record='$recordId' onclick='saveSupportSummary(\"$recordId\"); return false;' style='display: none; font-size: 10px;'>Save Changes</button>";
                } else {
                    $supportSummaryHTML = self::makeComment("Manually Entered");
                }
	            $transformedFacultyNames = self::transformNamesToLastFirst($mentors[$recordId]);
	            $dataRow = array(
	                "Trainee" => "{$lastNames[$recordId]}, {$firstNames[$recordId]}",
                    "Faculty Member" => implode("; ", $transformedFacultyNames),
                    "Start Date" => $countingStartDate,
                    "Summary of Support During Training" => $supportSummaryHTML,
                    "Terminal Degree(s)<br>Received and Year(s)" => $terminalDegree,
                    "Topic of Research Project<br>(From ".self::getResearchTopicSource().")" => $topic,
                    "Initial Position<br>Department<br>Institution<br>Activity" => $initialPos,
                    "Current Position<br>Department<br>Institution<br>Activity" => $currentPos,
                    "Subsequent Grant(s)/Role/Year Awarded" => $subsequentGrants,
                );
	            array_push($data, $dataRow);
            }
	        return $data;
        } else if ($part == 4) {
            return $this->get8IVData();
        } else {
	        throw new \Exception("Could not produce Table $table!");
        }
	}

	private static function formatTopic($topic, $src) {
	    if ($topic) {
	        return $topic."<br>($src)";
        }
	    return self::$naMssg;
    }

    private function get8IVData() {
	    $predocs = Download::predocNames($this->token, $this->server);
	    $trainingStarts = Download::oneField($this->token, $this->server, "summary_training_start");

	    $yearspan = 10;
	    $today = date("Y-m-d");
	    $recordsForLast10Years = array();
	    $recordsFor10YearsAgo = array();
	    foreach ($predocs as $recordId => $name) {
	        if ($trainingStarts[$recordId]) {
	            $age = REDCapManagement::getYearDuration($trainingStarts[$recordId], $today);
	            if ($age < $yearspan) {
	                array_push($recordsForLast10Years, $recordId);
	            }
	            if ((ceil($age) == $yearspan) || (floor($age) == $yearspan)) {
	                array_push($recordsFor10YearsAgo, $recordId);
	            }
	        }
	    }

	    $results = array();
        $results["Percentage of Trainees Entering Graduate School $yearspan Years Ago Who Completed the PhD"] = array(
            "value" => $this->getPercentWithPhD($recordsFor10YearsAgo),
            "suffix" => "%",
        );
        $results["Average Time to PhD for Trainee in the Last $yearspan Years"] = array(
            "value" => $this->getAverageTimeToPhD($recordsForLast10Years),
            "suffix" => " years",
        );

	    $data = array();
	    foreach ($results as $descr => $ary) {
	        $value = $ary['value'];
	        if ($value != self::$NA) {
                $data[$descr] = REDCapManagement::pretty($value, 1) . $ary['suffix'];
            } else {
                $data[$descr] = $value;
            }
        }
	    return array($data);
    }

    private function getPercentWithPhD($recordIds) {
	    $numWithPhD = 0;
	    $numTotal = 0;
	    foreach ($recordIds as $recordId) {
            $degreesAndYears = $this->getTerminalDegreesAndYears($recordId);
            foreach ($degreesAndYears as $degree => $year) {
                if (self::isKnownDate($degree, $year)) {
                    $numTotal++;
                    if (preg_match("/PhD/", $degree)) {
                        $numWithPhD++;
                    }
                }
            }
        }
	    if ($numTotal > 0) {
	        return 100 * $numWithPhD / $numTotal;
        } else {
            return self::$NA;
        }
    }

    private static function isKnownDate($degree, $year) {
	    if ($degree == "In Training") {
	        return FALSE;
        }
	    if (preg_match("/Unknown Year/", $year)) {
	        return FALSE;
        }
	    return TRUE;
   }

    private function getAverageTimeToPhD($recordIds) {
	    $timesToPhD = array();
	    $currYear = date("Y");
	    foreach ($recordIds as $recordId) {
            $degreesAndYears = $this->getTerminalDegreesAndYears($recordId);
            foreach ($degreesAndYears as $degree => $year) {
                if (self::isKnownDate($degree, $year)) {
                    if (preg_match("/PhD/", $degree)) {
                        array_push($timesToPhD, $currYear - $year);
                    }
                }
            }
        }
	    if (count($timesToPhD)) {
            return avg($timesToPhD);
        } else {
	        return self::$NA;
        }
    }

    private static function getRomanNumerals() {
	    return array(
            'M' => 1000,
            'CM' => 900,
            'D' => 500,
            'CD' => 400,
            'C' => 100,
            'XC' => 90,
            'L' => 50,
            'XL' => 40,
            'X' => 10,
            'IX' => 9,
            'V' => 5,
            'IV' => 4,
            'I' => 1,
        );
    }

    private static function isRoman($str) {
	    $romans = array_keys(self::getRomanNumerals());
	    for ($i=0; $i < strlen($str); $i++) {
	        $ch = strtoupper($str[$i]);
	        if (!in_array($ch, $romans)) {
	            return FALSE;
            }
        }
	    return TRUE;
    }

	private static function integerToRoman($roman)
    {
        $romans = self::getRomanNumerals();

        $result = 0;

        foreach ($romans as $key => $value) {
            while (strpos($roman, $key) === 0) {
                $result += $value;
                $roman = substr($roman, strlen($key));
            }
        }
        return $result;
    }


    private static function getPartNumber($table) {
	    $romanNumeral = preg_replace("/^\d[A-G]/", "", $table);
	    return self::integerToRoman($romanNumeral);
    }

	private static function makeDataIntoHTML($data) {
		if (count($data) == 0) {
			return "<p class='centered'>No data available.</p>\n";
		}

		$htmlRows = array();
		foreach ($data as $row) {
			if (empty($htmlRows)) {
				$currRow = array();
				foreach ($row as $field => $value) {
					array_push($currRow, "<th>$field</th>");
				}
				array_push($htmlRows, "<tr>".implode("", $currRow)."</tr>\n"); 
			}
			$currRow = array();
			foreach ($row as $field => $value) {
			    $style = "";
			    if ($field == "Publication") {
                    $style = " style='text-align: left;'";
                }
			    if ($value) {
                    array_push($currRow, "<td".$style.">$value</td>");
                } else {
			        array_push($currRow, "<td".$style.">".self::makeComment(self::$naMssg)."</td>");
                }
			}
			array_push($htmlRows, "<tr>".implode("", $currRow)."</tr>\n"); 
		}

		$html = "<table class='centered bordered'>".implode("", $htmlRows)."</table>\n";
		return $html;
	}

	private static function beginsWith($table, $ary) {
		foreach ($ary as $a) {
			$regex = "/^".$a."/i";
			if (preg_match($regex, $table)) {
				return TRUE;
			}
		}
		return FALSE;
	}

	private static function isPredoc($table) {
	    return self::beginsWith($table, array("5A", "6A", "8A"));
    }

    private static function isPostdoc($table) {
	    return self::beginsWith($table, array("5B", "6B", "8C"));
    }

	private static function getTrainingTypes($table) {
		if (self::isPredoc($table)) {
			$types = array(6);
		} else if (self::isPostdoc($table)) {
			$types = array(7);
		} else {
			$types = array();
		}
		return $types;
	}

	private function downloadRelevantNames($table) {
		if (self::beginsWith($table, array("5A", "6A", "8A"))) {
			$names = Download::predocNames($this->token, $this->server);
		} else if (self::beginsWith($table, array("5B", "6B", "8C"))) {
			$names = Download::postdocNames($this->token, $this->server);
		} else {
			$names = array();
		}
		if (self::beginsWith($table, array("8A", "8C"))) {
		    $filteredNames = array();
            $part = self::getPartNumber($table);
            if (in_array($part, array(1, 3))) {
                $trainingData = Download::trainingGrants($this->token, $this->server);
                foreach ($names as $recordId => $name) {
                    $currentGrants = self::getTrainingGrantsForRecord($trainingData, $recordId);
                    foreach ($currentGrants as $row) {
                        if ($row['redcap_repeat_instrument'] == "custom_grant") {
                            if ($part == 1) {
                                $filteredNames[$recordId] = $name;
                            } else if ($part == 3) {
                                # recent graduates - those whose appointments have ended
                                # for new applications only (currently)
                                if (self::isRecentGraduate($row['current_end'])) {
                                    $filteredNames[$recordId] = $name;
                                }
                            }
                        }
                    }
                }
            } else if ($part == 2) {
                # friends of the grant => fill in by hand
            }
            return $filteredNames;
        }
		return $names;
	}

	private static function isRecentGraduate($end) {
	    $yearsAgo = 5;
	    if (!$end) {
	        return FALSE;
        }
	    $endTs = strtotime($end);
	    $currYear = date("Y");
	    $currYear -= $yearsAgo;
	    $yearsAgoDate = $currYear.date("-m-d");
	    $yearsAgoTs = strtotime($yearsAgoDate);
	    if ($endTs >= $yearsAgoTs) {
	        return TRUE;
        } else {
	        return FALSE;
        }
    }

	private static function isActiveTimespan($start, $end) {
        $isActive = FALSE;
        if ($start) {
            $startTs = strtotime($start);
            if ($end) {
                $endTs = strtotime($end);
                if ($endTs > time()) {
                    $isActive = TRUE;
                }
            } else {
                $isActive = TRUE;
            }
            if ($startTs > time()) {
                $isActive = FALSE;
            }
        }
        return $isActive;
    }

    public static function makeComment($str) {
	    return "<span class='action_required'>".$str."</span>";
    }

	private function get5Data($table) {
		$data = array();
		$names = $this->downloadRelevantNames($table);
		if (!empty($names)) {
			$lastNames = Download::lastNames($this->token, $this->server);
			$firstNames = Download::firstNames($this->token, $this->server);
			$mentors = Download::primaryMentors($this->token, $this->server); 
			$trainingData = Download::trainingGrants($this->token, $this->server);
            $trainingStarts = Download::oneField($this->token, $this->server, "summary_training_start");
        }
		$fields = array_unique(array_merge(Application::$citationFields, array("record_id")));
		foreach ($names as $recordId => $name) {
			$pubData = Download::fieldsForRecords($this->token, $this->server, $fields, array($recordId));
            $facultyMembers = $mentors[$recordId];
			$traineeName = $name;
			$pastOrCurrent = "";
			$trainingPeriod = "";
			$startTs = 0;
			if ($trainingStarts[$recordId]) {
			    $startTs = strtotime($trainingStarts[$recordId]);
                $startYear = REDCapManagement::getYear($trainingStarts[$recordId]);
            }
			$endTs = time();
			$currentGrants = self::getTrainingGrantsForRecord($trainingData, $recordId);
			foreach ($currentGrants as $row) {
                if ($row['redcap_repeat_instrument'] == "custom_grant") {
                    $currStartTs = strtotime($row['custom_start']);
                    if (!$startTs || ($currStartTs < $startTs)) {
                        $startTs = $currStartTs;
                        $startYear = REDCapManagement::getYear($row['custom_start']);
                    }
                    if ($row['custom_end']) {
                        $endTs = strtotime($row['custom_end']);
                        if ($endTs > time()) {
                            # in future
                            $endYear = self::$presentMarker;
                        } else {
                            # in past
                            $endYear = REDCapManagement::getYear($row['custom_end']);
                        }
                    } else {
                        $endYear = self::$presentMarker;
                    }
                    if ($endYear == self::$presentMarker) {
                        $pastOrCurrent = "Current";
                    } else {
                        $pastOrCurrent = "Past";
                    }
                    $trainingPeriod = "$startYear-$endYear";
                }
			}

			$eighteenMonthsDuration = 18 * 30 * 24 * 3600;
			$endTs += $eighteenMonthsDuration;

			$pubs = new Publications($this->token, $this->server, $this->metadata);
			$pubs->setRows($pubData);

            $noPubsRow = array(
                "Trainee Name" => $traineeName,
                "Faculty Member" => implode(", ", $facultyMembers),
                "Past or Current Trainee" => $pastOrCurrent,
                "Training Period" => $trainingPeriod,
                "Publication" => "No Publications: ".self::makeComment("Explanation Needed"),
            );

            if ($pubs->getCitationCount() == 0) {
				array_push($data, $noPubsRow);
			} else {
				$citations = $pubs->getSortedCitations("Included");
				$nihFormatCits = array();
				foreach ($citations as $citation) {
				    foreach ($facultyMembers as $facultyMember) {
                        if ($citation->inTimespan($startTs, $endTs)) {
                            $nihFormatCits[] = $citation->getNIHFormat($lastNames[$recordId], $firstNames[$recordId]);
                        }
                    }
				}
				if (count($nihFormatCits) == 0) {
                    array_push($data, $noPubsRow);
                } else {
                    $dataRow = array(
                        "Trainee Name" => $traineeName,
                        "Faculty Member" => $facultyMember,
                        "Past or Current Trainee" => $pastOrCurrent,
                        "Training Period" => $trainingPeriod,
                        "Publication" => implode("<br>", $nihFormatCits),
                    );
                    array_push($data, $dataRow);

                }
			}
		}
		return $data;
	}

	private $token;
	private $server;
	private $pid;
	private $metadata;
	private $choices;
	private static $NA = "N/A";
	private static $presentMarker = "Present";
	public static $maxYearsOfGrantReporting = 15;
	public static $naMssg = "None Specified";
}
