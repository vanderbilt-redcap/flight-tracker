<?php

namespace Vanderbilt\CareerDevLibrary;

use Vanderbilt\FlightTrackerExternalModule\CareerDev;

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
		self::$NA = self::makeComment(self::$notAvailable);
	}

	private static function makeRemove($recordId, $grantName) {
	    $url = Application::link("reporting/grants.php")."&record=$recordId&name=".urlencode($grantName);
	    return "<a onclick='$(this).parent().hide(); $.post(\"$url\", {}, function(html) { console.log(\"Removed \"+html); });' href='javascript:;' class='redtext smallest nounderline'>[x]</a>";
    }

    private static function makeReset($recordId) {
	    $url = Application::link("reporting/grants.php")."&record=$recordId&reset";
        return "<div class='alignright'><a onclick='$.post(\"$url\", {}, function(html) { console.log(\"Reset \"+html); $(\".subsequentGrants_$recordId\").show(); });' href='javascript:;' class='bluetext smallest nounderline'>[reset]</a></div>";
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
        $appointmentMssg = "for Appointments Only";

		$group = "Postdoctoral";
		if (preg_match("/A/i", $table)) {
			$group = "Predoctoral";
		}

		if ($table == "5") {
			return $five;
        } else if (preg_match("/^5.$/", $table)) {
            return "$five: $group";
        } else if (preg_match("/^5.-VUMC$/", $table)) {
            return "$five: $group $appointmentMssg";
		} else if ($table == "6") {
			return $six;
        } else if (preg_match("/^6.II$/", $table)) {
            return "$six: $group - Characteristics";
        } else if (preg_match("/^6.II-VUMC$/", $table)) {
            return "$six: $group - Characteristics $appointmentMssg";
		} else if ($table == "8") {
			return $eight;
        } else if (preg_match("/^8.I$/", $table)) {
            return "$eight: $group - Those Appointed to the Training Grant";
        } else if (preg_match("/^8.I-VUMC$/", $table)) {
            return "$eight: $group - Those Appointed to the Training Grant $appointmentMssg";
		} else if (preg_match("/^8.II$/", $table)) {
			return "$eight: $group - Those Clearly Associated with the Training Grant";
        } else if (preg_match("/^8.III$/", $table)) {
            return "$eight: $group - Recent Graduates";
        } else if (preg_match("/^8.III-VUMC$/", $table)) {
            return "$eight: $group - Recent Graduates $appointmentMssg";
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
            $namesPre = $this->downloadPredocNames();
            $namesPost = $this->downloadPostdocNames($table);
            return self::getHTMLPrefix($table).self::makeDataIntoHTML($data, $namesPre, $namesPost);
		} else if (self::beginsWith($table, array("6A", "6B"))) {
			$html = "";
			$html .= self::getHTMLPrefix($table);
			foreach (self::get6Years() as $year) {
				$data = $this->get6Data($table, $year);
				$html .= self::makeDataIntoHTML($data)."<br><br>";
			}
			return $html;
		} else if (self::beginsWith($table, array("8A", "8C"))) {
			$data = $this->get8Data($table);
			return $this->makeJS().self::getHTMLPrefix($table).self::makeDataIntoHTML($data);
		} else if ($table == "Common Metrics") {
            $data = $this->getCommonMetricsData($table);
            return self::getHTMLPrefix($table).self::makeDataIntoHTML($data);
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
        $redcapData = Download::fieldsForRecords($this->token, $this->server, $fieldsToDownload, [$recordId]);
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

	public function get6Data($table, $yearspan, $records = []) {
		$data = array();
		if (preg_match("/^6[AB]II$/", $table)) {
			return $this->get6IIData($table, $yearspan, $records);
		}
		return $data;
	}

	private function get6IIData($table, $yearspan, $records = []) {
	    $choices = $this->getAlteredChoices();
		$data = array();
		$names = $this->downloadRelevantNames($table, $records);
		$doctorateInstitutions = Download::doctorateInstitutions($this->token, $this->server, $this->metadata);
		$trainingTypes = self::getTrainingTypes($table);
		if (!empty($names)) {
			$trainingGrantData = Download::trainingGrants($this->token, $this->server, [], $trainingTypes, [], $this->metadata);
		} else {
		    $trainingGrantData = array();
        }
		$trainingStarts = Download::oneField($this->token, $this->server, "summary_training_start");
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

            $recordData = Download::fieldsForRecords($this->token, $this->server, $fields, array($recordId));
            $recordClasses = array();
            if (self::appointedDuringYear($yearspan, $recordId, $currentGrantData, $trainingTypes)) {
                $recordClasses[] = $newAppointments;
            }
            if (self::startedTrainingDuringYear($yearspan, $recordId, $trainingStarts)) {
                $recordClasses[] = $newProgramEntrants;
            }
            foreach ($recordClasses as $currClass) {
                foreach ($recordData as $row) {
                    if ($row['redcap_repeat_instrument'] == "") {
                        if ($row['summary_urm'] == "1") {
                            array_push($resultData["urm"][$currClass], "1");
                        } else if (($row['summary_urm'] === "0") || ($row['summary_urm'] === 0)) {
                            array_push($resultData["urm"][$currClass], "0");
                        }
                        if ($row['summary_disability'] == "1") {
                            array_push($resultData["disability"][$currClass], "1");
                        } else if (($row['summary_disability'] === "0") || ($row['summary_disability'] === 0)) {
                            array_push($resultData["disability"][$currClass], "0");
                        }

                        if ($row['check_undergrad_gpa']) {
                            array_push($resultData["gpa"][$currClass], $row['check_undergrad_gpa']);
                        }

                        if (self::isPredocTable($table)) {
                            # use undergrad institution
                            if ($row['check_undergrad_institution'] !== "") {
                                $institution = $row['check_undergrad_institution'];
                                if (is_numeric($institution)) {
                                    $institution = $choices['check_undergrad_institution'][$institution];
                                }
                                array_push($resultData["institutions"][$currClass], $institution);
                            }
                        } else if (self::isPostdocTable($table)) {
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

		$data[0] = array(
				$title => "Mean Months of Prior, Full-Time Research Experience (range)",
				"Total Applicant Pool" => self::$blank,
				"Applicants Eligible for Support" => self::$blank,
				$newProgramEntrants => self::findAverageAndRange($resultData["research_months"][$newProgramEntrants]),
				$newEligibleEntrants => self::$NA,
				$newAppointments => self::findAverageAndRange($resultData["research_months"][$newAppointments]),
				);
		$data[1] = array(
				$title => "Prior Institutions",
				"Total Applicant Pool" => self::$blank,
				"Applicants Eligible for Support" => self::$blank,
				$newProgramEntrants => self::findInstitutions($resultData["institutions"][$newProgramEntrants]),
				$newEligibleEntrants => self::$NA,
				$newAppointments => self::findInstitutions($resultData["institutions"][$newAppointments]),
				);
		$data[2] = array(
				$title => "Percent with a Disability",
				"Total Applicant Pool" => self::$blank,
				"Applicants Eligible for Support" => self::$blank,
				$newProgramEntrants => self::findPercent($resultData["disability"][$newProgramEntrants]),
				$newEligibleEntrants => self::$NA,
				$newAppointments => self::findPercent($resultData["disability"][$newAppointments]),
				);
		$data[3] = array(
				$title => "Percent from Underrepresented Racial &amp; Ethnic Groups",
				"Total Applicant Pool" => self::$blank,
				"Applicants Eligible for Support" => self::$blank,
				$newProgramEntrants => self::findPercent($resultData["urm"][$newProgramEntrants]),
				$newEligibleEntrants => self::$NA,
				$newAppointments => self::findPercent($resultData["urm"][$newAppointments]),
				);
		$data[4] = array(
				$title => "Mean GPA (range)",
				"Total Applicant Pool" => self::$NA,
				"Applicants Eligible for Support" => self::$NA,
				$newProgramEntrants => self::findAverageAndRange($resultData["gpa"][$newProgramEntrants]),
				$newEligibleEntrants => self::$NA,
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

    private static function appointedDuringYear($yearspan, $recordId, $trainingGrantData, $trainingTypes) {
        foreach ($trainingGrantData as $row) {
            if (($row['record_id'] == $recordId) && in_array($row['custom_role'], $trainingTypes) && $row['custom_start']) {
                $trainingGrantStartTs = strtotime($row['custom_start']);
                if (($trainingGrantStartTs >= $yearspan['begin']) && ($trainingGrantStartTs <= $yearspan['end'])) {
                    return TRUE;
                }
            }
        }
        return FALSE;
    }

    private static function startedTrainingDuringYear($yearspan, $recordId, $trainingStarts) {
	    if ($trainingStarts[$recordId]) {
            $trainingStartTs = strtotime($trainingStarts[$recordId]);
            if (($trainingStartTs >= $yearspan['begin']) && ($trainingStartTs <= $yearspan['end'])) {
                return TRUE;
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

	private function getAllDegreeFieldsIndexed($year = TRUE, $institution = FALSE) {
	    $field = "summary_degrees";
        $vars = Scholar::getDefaultOrder($field);
        $scholar = new Scholar($this->token, $this->server, $this->metadata, $this->pid);
        $scholar->getOrder($vars, $field);
        $metadataFields = REDCapManagement::getFieldsFromMetadata($this->metadata);
        $fields = array();
        foreach ($vars as $ary) {
            foreach ($ary as $field => $source) {
                if (in_array($field, $metadataFields)) {
                    $ary = [];
                    if ($field == "override_degrees") {
                        if ($year) { $ary[] = "override_degrees_year"; }
                        $fields[$field] = $ary;
                    } else if ($field == "imported_degree") {
                        if ($year) { $ary[] = "imported_degree_year"; }
                        $fields[$field] = $ary;
                    } else if ($field == "followup_degree") {
                        if ($institution) { $ary[] = "followup_degree_institution"; }
                        $fields[$field] = $ary;
                    } else if (preg_match("/^check_degree/", $field)) {
                        if ($year) { $ary[] = $field."_year"; }
                        if ($institution) { $ary[] = $field."_institution"; }
                        $fields[$field] = $ary;
                    } else if (preg_match("/^init_import_degree/", $field)) {
                        if ($year) { $ary[] = $field."_year"; }
                        if ($institution) { $ary[] = $field."_institution"; }
                        $fields[$field] = $ary;
                    } else if (preg_match("/^vfrs_degree/", $field)) {
                        if ($year) { $ary[] = $field."_year"; }
                        if ($institution) { $ary[] = $field."_institution"; }
                        $fields[$field] = $ary;
                    } else if ($field == "vfrs_graduate_degree") {
                        if ($year) { $ary[] = "vfrs_degree1_year"; }
                        if ($institution) { $ary[] = "vfrs_degree1_institution"; }
                        $fields[$field] = $ary;
                    } else if (preg_match("/^newman_new_degree/", $field)) {
                        $fields[$field] = $ary;
                    } else if (preg_match("/^newman_data_degree/", $field)) {
                        $fields[$field] = $ary;
                    } else if (preg_match("/^newman_sheet2_degree/", $field)) {
                        $fields[$field] = $ary;
                    } else if ($field == "newman_demographics_degrees") {
                        if ($year) {
                            $ary[] = "newman_demographics_last_degree_year";
                            $ary[] = "newman_demographics_degrees_years";
                        }
                        if ($institution) {
                            $ary[] = "newman_demographics_last_degree_institution";
                        }
                        $fields[$field] = $ary;
                    }
                }
            }
        }
        return $fields;
    }

    private function separateAllDegreeSubFields($degreeFields, $year = TRUE, $institution = FALSE) {
	    if (!is_array($degreeFields)) {
            $degreeFields = [$degreeFields];
        }
	    $degreeMatches = $this->getAllDegreeFieldsIndexed($year, $institution);
	    foreach ($degreeFields as $currField) {
            $degreeYearFields = array_merge($degreeYearFields, $degreeMatches[$currField]);
        }
	    return $degreeYearFields;
    }

    # returns array with $degree => [$year, $institution]
    public function getDegreesAndInstitutions($recordId) {
        $degreesAndAddOns = $this->getDegreesAndAddOns($recordId, TRUE, TRUE);
        if (empty($degreesAndAddOns)) {
            $fields = ["record_id", "summary_training_start", "summary_training_end"];
            $redcapData = Download::fieldsForRecords($this->token, $this->server, $fields, [$recordId]);
            if (REDCapManagement::findField($redcapData, $recordId, "summary_training_end")) {
                return ["None Received" => ""];
            } else {
                return ["In Training" => ""];
            }
        }
        return $degreesAndAddOns;
    }

    private static function formatDegreesAndYears($degreesAndYears, $returnOneEntry) {
        $default = "Unknown";
        $texts = [];
        foreach ($degreesAndYears as $degree => $year) {
            if ($degree == "In Training") {
                return self::$NA;
            }
            if ($year == self::$unknownYearText) {
                $default = $degree." ".$year;
            } else {
                $texts[] = $degree." ".$year;
            }
        }

        if (empty($texts)) {
            if ($default) {
                return $default;
            } else {
                return self::$NA;
            }
        } else if ($returnOneEntry) {
            return $texts[0];
        } else {
            return implode("<br>", $texts);
        }
    }

    private function getDoctoralDegreesAndYears($recordId, $asText = TRUE) {
        $degreesAndYears = $this->getDegreesAndAddOns($recordId, TRUE, FALSE);
        if ((count($degreesAndYears) == 1) && ((isset($degreesAndYears["None Received"])) || (isset($degreesAndYears["In Training"])))) {
            return $degreesAndYears;
        }

        $doctorateRegExes = array("/MD/", "/PhD/i", "/DPhil/i", "/PharmD/i", "/PsyD/i");
        $doctorateDegreesAndYears = array();
        foreach ($degreesAndYears as $degree => $year) {
            foreach ($doctorateRegExes as $regEx) {
                if (preg_match($regEx, $degree)) {
                    if (!isset($doctorateDegreesAndYears[$degree])) {
                        $doctorateDegreesAndYears[$degree] = $year;
                    } else if ($year < $doctorateDegreesAndYears[$degree]) {
                        $doctorateDegreesAndYears[$degree] = $year;
                    }
                }
            }
        }

        arsort($doctorateDegreesAndYears);
        if ($asText) {
            return self::formatDegreesAndYears($doctorateDegreesAndYears, FALSE);
        } else {
            return $doctorateDegreesAndYears;
        }
    }

    private function getPostdocDegreesAndYears($recordId, $asText = TRUE) {
        $doctoralDegreesAndYears = $this->getTerminalDegreesAndYears($recordId, FALSE);
        $earliestDoctorate = FALSE;
        $unknownYearFound = FALSE;
        foreach ($doctoralDegreesAndYears as $degree => $year) {
            if ($year < $earliestDoctorate) {
                $earliestDoctorate = $year;
            } else if ($year == self::$unknownYearText) {
                $unknownYearFound = TRUE;
            }
        }
        if ((count($doctoralDegreesAndYears) > 0) && !$earliestDoctorate && !$unknownYearFound) {
            if ($asText) {
                return "Unknown";
            } else {
                return ["Unknown" => ""];
            }
        }

        $allDegreesAndYears = $this->getDegreesAndAddOns($recordId, TRUE, FALSE);
        $degreesAndYears = [];
        $allUnknownYears = TRUE;
        foreach ($allDegreesAndYears as $degree => $year) {
            if (!isset($doctoralDegreesAndYears[$degree])
                && (count($doctoralDegreesAndYears) > 0)
                && (($year == self::$unknownYearText) || ($year >= $earliestDoctorate))) {
                if ($year != self::$unknownYearText) {
                    $allUnknownYears = FALSE;
                    $degreesAndYears[$degree] = $year;
                }
            }
        }
        if ($allUnknownYears) {
            foreach ($allDegreesAndYears as $degree => $year) {
                if (!isset($doctoralDegreesAndYears[$degree])
                    && (count($doctoralDegreesAndYears) > 0)
                    && (($year == self::$unknownYearText) || ($year >= $earliestDoctorate))) {
                    $degreesAndYears[$degree] = $year;
                }
            }
        }
        arsort($degreesAndYears);
        if ($asText) {
            return self::formatDegreesAndYears($degreesAndYears, FALSE);
        } else {
            return $degreesAndYears;
        }
    }

    public function getTerminalDegreesAndYears($recordId, $asText = FALSE) {
	    $degreesAndYears = $this->getDegreesAndAddOns($recordId, TRUE, FALSE);
        if ((count($degreesAndYears) == 1) && ((isset($degreesAndYears["None Received"])) || (isset($degreesAndYears["In Training"])))) {
            return $degreesAndYears;
        }

        $doctorateDegreesAndYears = $this->getDoctoralDegreesAndYears($recordId, FALSE);
        foreach ($degreesAndYears as $degree => $year) {
            if (!isset($doctorateDegreesAndYears[$degree])) {
                $predocDegreesAndYears[$degree] = $year;
            }
        }
        if (empty($doctorateDegreesAndYears) && empty($predocDegreesAndYears)) {
            $fields = ["record_id", "summary_training_start", "summary_training_end"];
            $redcapData = Download::fieldsForRecords($this->token, $this->server, $fields, [$recordId]);
            if (REDCapManagement::findField($redcapData, $recordId, "summary_training_end")) {
                if ($asText) {
                    return "None Received";
                } else {
                    return ["None Received" => ""];
                }
            } else {
                if ($asText) {
                    return "In Training";
                } else {
                    return ["In Training" => ""];
                }
            }
        }
        if (!empty($doctorateDegreesAndYears)) {
            if ($asText) {
                return self::formatDegreesAndYears($doctorateDegreesAndYears, FALSE);
            } else {
                return $doctorateDegreesAndYears;
            }
        } else {
            if ($asText) {
                return self::formatDegreesAndYears($predocDegreesAndYears, FALSE);
            } else {
                return $predocDegreesAndYears;
            }
        }
    }

    # years are in MM/YYYY if possible
    private function getDegreesAndAddOns($recordId, $getYear = TRUE, $getInstitution = FALSE) {
	    $numCategories = 0;
	    if ($getYear) { $numCategories++; }
	    if ($getInstitution) { $numCategories++; }

        $degreeMatches = $this->getAllDegreeFieldsIndexed();
        $yearMatches = $this->getAllDegreeFieldsIndexed(TRUE, FALSE);
        $institutionMatches = $this->getAllDegreeFieldsIndexed(FALSE, TRUE);
        $degreeFields = array_keys($degreeMatches);
        $degreeYearFields = $this->separateAllDegreeSubFields($degreeFields, $getYear, $getInstitution);
        $fields = array_unique(array_merge(["record_id"], $degreeFields, $degreeYearFields));
        $redcapData = Download::fieldsForRecords($this->token, $this->server, $fields, array($recordId));
        $choices = $this->getAlteredChoices();
        $degreesAndAddOns = [];
        foreach ($degreeFields as $field) {
            foreach ($redcapData as $row) {
                if ($row[$field]) {
                    if ($choices[$field] && isset($choices[$field][$row[$field]])) {
                        $degree = $choices[$field][$row[$field]];
                    } else {
                        $degree = $row[$field];
                    }
                    if ($getYear) {
                        $year = self::$unknownYearText;
                        foreach ($yearMatches[$field] as $yearField) {
                            if (intval($row[$yearField])) {
                                $year = $row[$yearField];
                                break;
                            } else if (strtotime($row[$yearField])) {
                                $year = REDCapManagement::YMD2MY($row[$yearField]);
                                break;
                            } else if ($row[$yearField]) {
                                $year = $row[$yearField];
                                break;
                            }
                        }
                    }
                    if ($getInstitution) {
                        $institution = self::$unknownInstitutionText;
                        foreach ($institutionMatches[$field] as $institutionField) {
                            if ($row[$institutionField]) {
                                $institution = $row[$institutionField];
                                break;
                            }
                        }
                    }
                    if ($numCategories == 1) {
                        if ($getYear) {
                            $degreesAndAddOns[$degree] = $year;
                        } else if ($getInstitution) {
                            $degreesAndAddOns[$degree] = $institution;
                        }
                    } else {
                        $degreesAndAddOns[$degree] = [$year, $institution];
                    }
                }
            }
        }
        if (isset($_GET['test'])) {
            echo "Returning for $recordId: ".json_encode($degreesAndAddOns)."<br>";
        }
        return $degreesAndAddOns;
	}

	private function getTerminalDegreeAndYear($recordId) {
	    $degreesAndYears = $this->getTerminalDegreesAndYears($recordId, FALSE);
        arsort($degreesAndYears);
        return self::formatDegreesAndYears($degreesAndYears, TRUE);
    }

    private static function getResearchTopicSource() {
	    global $grantClass;
	    if ($grantClass == "K") {
	        return "Grant Title of K Award";
        } else if ($grantClass == "T") {
	        return "check_degree0_topic or custom_title";
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
            # if T => from survey (check_degree0_topic or custom_title)
            $topics = Download::oneField($this->token, $this->server, "check_degree0_topic");
            if ($topics[$recordId]) {
                return $topics[$recordId];
            }
            $redcapData = Download::fieldsForRecords($this->token, $this->server, ["record_id", "custom_type", "custom_title"], [$recordId]);
            $kType = self::getTrainingType();
            foreach ($redcapData as $row) {
                if ($row['custom_title'] && ($row['custom_type'] == $kType)) {
                    return $row['custom_title'];
                }
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

    private static function formatPosition($position) {
	    return $position["title"]."<br>".$position["department"]."<br>".$position["institution"]."<br>".$position["activity"];
    }

    private function getAllPositionsInOrder($redcapData, $recordId) {
	    $positions = array();
	    $choices = $this->getAlteredChoices();
        foreach ($redcapData as $row) {
            if ($row['record_id'] == $recordId) {
                if ($row['redcap_repeat_instrument'] == "position_change") {
                    $date = $row['promotion_in_effect'];
                    if ($date && strtotime($date)) {
                        $ts = strtotime($date);

                        # Position<br>Department<br>Institution<br>Activity
                        $descriptions = [];
                        if ($row['promotion_job_title']) {
                            $descriptions["title"] = $row['promotion_job_title'];
                        } else if ($row['promotion_rank']) {
                            $descriptions["title"] = $choices['promotion_rank'][$row['promotion_rank']];
                        } else {
                            $descriptions["title"] = "";
                        }
                        if (($row["promotion_department"] == "999999") && $row["promotion_department_other"]) {
                            $descriptions["department"] = $row["promotion_department_other"];
                        } else if ($row['promotion_department'] && $choices['promotion_department'][$row['promotion_department']]) {
                            $descriptions["department"] = $choices["promotion_department"][$row["promotion_department"]];
                        } else if ($row['promotion_department']) {
                            $descriptions["department"] = $row["promotion_department"];
                        } else {
                            $descriptions["department"] = $row['promotion_division'];
                        }
                        $descriptions["institution"] = $row['promotion_institution'];
                        $descriptions["activity"] = self::translateJobCategoryToActivity($row['promotion_job_category']);
                        $descriptions["original_category"] = ($row["promotion_job_category"] ? $choices["promotion_job_category"][$row["promotion_job_category"]] : "");
                        $descriptions["original_category_num"] = $row["promotion_job_category"];
                        self::fillInBlankValues($descriptions);
                        $descriptionStr = implode("<br>", $descriptions);
                        $numNotAvailablesInDescription = substr_count($descriptionStr, self::$notAvailable);
                        $numNotAvailablesInExistingItem = substr_count($positions[$ts], self::$notAvailable);
                        # if new timestamps -OR- if less not available comments, then set
                        if (!isset($positions[$ts])
                            || ($positions[$ts] && ($numNotAvailablesInDescription < $numNotAvailablesInExistingItem))) {
                            $positions[$ts] = $descriptions;
                        }
                    }
                }
            }
        }
        ksort($positions);
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
	            $ary[$key] = self::makeComment(self::$notAvailable." (".ucfirst($key).")");
            }
        }
    }

    public function getInitialPosition($redcapData, $recordId, $returnHTML = TRUE) {
        $positions = $this->getAllPositionsInOrder($redcapData, $recordId);
        if (count($positions) > 0) {
            $position = $positions[0];
            if ($returnHTML) {
                return self::formatPosition($position);
            } else {
                return $position;
            }
        }
        if ($returnHTML) {
            return "";
        } else {
            return [];
        }
    }

    public function getCurrentPosition($redcapData, $recordId, $returnHTML = TRUE) {
        $positions = $this->getAllPositionsInOrder($redcapData, $recordId);
        if (count($positions) > 0) {
            $position = $positions[count($positions) - 1];
            if ($returnHTML) {
                return self::formatPosition($position);
            } else {
                return $position;
            }
        }
        if ($returnHTML) {
            return "";
        } else {
            return [];
        }
    }

    private static function abbreviateAwardNo($awardNo, $fundingSource = "Other", $fundingType = "Other") {
        $ary = Grant::parseNumber($awardNo);

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
        } else {
            $supportType = "";
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
            $supportSource = $ary["institute_code"];
        } else if ($ary["institute_code"]) {
            $supportSource = $ary["institute_code"];
        } else {
            $supportSource = "";
        }
        if (($supportSource == "HX") && ($supportType == "I01")) {
            return "VA Merit";
        } else if (($supportType == "") && ($supportType == "")) {
            return "Other";
        }
	    return "$supportSource $supportType";
	}

	private static function getSourceAndType($type) {
        $ks = [1, 2, 3, 4];
        $trainingTypes = [10];
	    if (!is_numeric($type)) {
	        $ks = Grant::convertGrantTypesToStrings($ks);
	        $trainingTypes = Grant::convertGrantTypesToStrings($trainingTypes);
        }
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

    # $grantCategory correlates with Grants::getGrants
    private function getGrantSummary($recordId, $table, $grantCategory = "all") {
        # Subsequent Grant(s)/Role/Year Awarded
        if ($grantCategory == "all") {
            $redcapData = Download::records($this->token, $this->server, [$recordId]);
        } else if ($grantCategory == "prior") {
            $redcapData = Download::fieldsForRecords($this->token, $this->server, Application::$summaryFields, [$recordId]);
        } else {
            throw new \Exception("Unknown category: ".$grantCategory);
        }
        $isPredoc = self::isPredocTable($table);
        $grants = new Grants($this->token, $this->server, $this->metadata);
        $grants->setRows($redcapData);
        if ($grantCategory != "prior") {
            $grants->compileGrants();
        }
        $idx = 1;
        $grantDescriptions = [];
        foreach ($grants->getGrants($grantCategory) as $grant) {
            $awardNo = $grant->getNumber();
            if ($awardNo && $grant->getVariable("start") && self::includeGrant($grant->getVariable("type"), $isPredoc, $idx)) {
                $role = $grant->getVariable("role") ? $grant->getVariable("role") : self::$NA;
                $year = REDCapManagement::getYear($grant->getVariable("start"));
                list($fundingSource, $fundingType) = self::getSourceAndType($grant->getVariable("type"));
                if ($fundingSource) {
                    if ($awardNo == "Peds K12") {
                        $shortAwardNo = "HD K12";
                    } else {
                        $shortAwardNo = self::abbreviateAwardNo($awardNo, $fundingSource, $fundingType);
                    }
                    if (preg_match("/Other/", $shortAwardNo)) {
                        $shortAwardNo .= " ".self::makeComment("(".$awardNo.")");
                    }
                    $ary = [$shortAwardNo, $role, $year];
                    $style = "";
                    if ($this->isRemovedGrant($recordId, $awardNo)) {
                        $style = " style='display: none;'";
                        // $style = " style='color: green;'";
                    }
                    array_push($grantDescriptions, "<p$style class='subsequentGrants_$recordId'>".implode(" / ", $ary)." ".self::makeRemove($recordId, $awardNo)."</p>");
                } else {
                    # exclude - this information is for debug only
                    $role = $grant->getVariable("role") ? $grant->getVariable("role") : self::$NA;
                    $year = REDCapManagement::getYear($grant->getVariable("start"));
                    list($fundingSource, $fundingType) = self::getSourceAndType($grant->getVariable("type"));
                    $shortAwardNo = self::abbreviateAwardNo($awardNo, $fundingSource, $fundingType);
                    $ary = [$awardNo, $shortAwardNo, $role, $year, $fundingSource, $fundingType];
                    // array_push($grantDescriptions, "<p style='color: darkorange;'>No fund ".implode(" / ", $ary)."</p>");
                }
            } else {
                # exclude - this information is for debug only
                $role = $grant->getVariable("role") ? $grant->getVariable("role") : self::$NA;
                $year = REDCapManagement::getYear($grant->getVariable("start"));
                list($fundingSource, $fundingType) = self::getSourceAndType($grant->getVariable("type"));
                $shortAwardNo = self::abbreviateAwardNo($awardNo, $fundingSource, $fundingType);
                $ary = [$awardNo, $shortAwardNo, $role, $year, $fundingSource, $fundingType];
                // array_push($grantDescriptions, "<p style='color: blue;'>Excl. ".implode(" / ", $ary)."</p>");
            }
            $idx++;
        }
        if (count($grantDescriptions) > 0) {
            return implode("", $grantDescriptions).self::makeReset($recordId);
        }
        return "";
    }

    public static function getSubsequentGrantsSettingName() {
	    return "subsequent_grants";
    }

    public function isRemovedGrant($recordId, $title) {
	    $settingName = self::getSubsequentGrantsSettingName();
	    $setting = Application::getSetting($settingName, $this->pid);
	    if ($setting && $setting[$recordId]) {
	        return in_array($title, $setting[$recordId]);
        }
	    return FALSE;
    }

    private static function includeGrant($type, $isPredoc, $i) {
        $internalKTypes = [1, 2];
        if ($isPredoc) {
            return TRUE;
        }
        if (!is_numeric($type)) {
            $internalKTypes = Grant::convertGrantTypesToStrings($internalKTypes);
        }
        return (!in_array($type, $internalKTypes) && ($i != 1));
    }

    # best guess
    private static function transformNamesToLastFirst($aryOfNames) {
	    $transformedNames = array();
	    foreach ($aryOfNames as $name) {
	        list($first, $last) = NameMatcher::splitName($name);
            array_push($transformedNames, "$last, $first");
        }
	    return $transformedNames;
    }

    private function hasSupportSummary() {
        $metadataFields = REDCapManagement::getFieldsFromMetadata($this->metadata);
        return in_array("identifier_support_summary", $metadataFields);
    }

    public function get8Data($table, $records = []) {
	    $part = self::getPartNumber($table);
	    if (in_array($part, [1, 2, 3])) {
            $names = $this->downloadRelevantNames($table, $records);
            if (isset($_GET['test'])) {
                echo count($names)." names downloaded<br>";
            }
	        $firstNames = Download::firstnames($this->token, $this->server);
	        $lastNames = Download::lastnames($this->token, $this->server);
            $mentors = Download::primaryMentors($this->token, $this->server);
            $trainingGrants = Download::trainingGrants($this->token, $this->server, [], [5, 6, 7], [], $this->metadata);
            $hasSupportSummary = $this->hasSupportSummary();
            if ($hasSupportSummary) {
                $supportSummaries = Download::oneField($this->token, $this->server, "identifier_support_summary");
            } else {
                $supportSummaries = array();
            }

	        $data = [];
	        $baseLineStart = (date("Y") - self::$maxYearsOfGrantReporting)."-01-01";
	        $startingDates = [];
	        foreach ($names as $recordId => $name) {
                $currentTrainingGrants = self::getTrainingGrantsForRecord($trainingGrants, $recordId);

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
                $startingDates[$recordId] = strtotime($countingStartDate);
            }
            asort($startingDates);
	        foreach ($startingDates as $recordId => $ts) {
	            $countingStartDate = date("m/Y", $ts);
                $positionData = Download::fieldsForRecords($this->token, $this->server, Application::$positionFields, array($recordId));
                $terminalDegree = $this->getTerminalDegreeAndYear($recordId);
                $doctoralDegrees = $this->getDoctoralDegreesAndYears($recordId, TRUE);
                $postdocDegrees = $this->getPostdocDegreesAndYears($recordId, TRUE);
	            $topic = $this->getResearchTopic($recordId);
	            $initialPos = $this->getInitialPosition($positionData, $recordId);
	            $currentPos = $this->getCurrentPosition($positionData, $recordId);
	            if ($terminalDegree == "In Training") {
                    $subsequentGrants = "Current Scholar";
                } else {
                    $subsequentGrants = $this->getGrantSummary($recordId, $table);
                }
                $supportSummary = $supportSummaries[$recordId] ? $supportSummaries[$recordId] : "";

                if ($hasSupportSummary) {
                    $supportSummaryHTML = self::makeComment("Please Edit")."<br><textarea class='support_summary' record='$recordId'>$supportSummary</textarea><br><button class='support_summary' record='$recordId' onclick='saveSupportSummary(\"$recordId\"); return false;' style='display: none; font-size: 10px;'>Save Changes</button>";
                } else {
                    $supportSummaryHTML = self::makeComment("Manually Entered");
                }
	            $transformedFacultyNames = self::transformNamesToLastFirst($mentors[$recordId]);

                # if modify column headers, need to modify reporting/getData.php
                if (self::beginsWith($table, ['8A'])) {
                    $dataRow = [
                        "Trainee" => "{$lastNames[$recordId]}, {$firstNames[$recordId]}",
                        "Terminal Degree(s)<br>Received and Year(s)" => $terminalDegree,
                        "Faculty Member" => "<p>".implode("</p><p>", $transformedFacultyNames)."</p>",
                        "Start Date" => $countingStartDate,
                        "Summary of Support During Training" => $supportSummaryHTML,
                        "Topic of Research Project<br>(From ".self::getResearchTopicSource().")" => $topic,
                        "Initial Position<br>Department<br>Institution<br>Activity" => $initialPos,
                        "Current Position<br>Department<br>Institution<br>Activity" => $currentPos,
                        "Subsequent Grant(s)/Role/Year Awarded" => $subsequentGrants,
                    ];
                } else if (self::beginsWith($table, ['8C'])) {
                    $dataRow = [
                        "Trainee" => "{$lastNames[$recordId]}, {$firstNames[$recordId]}",
                        "Doctoral<br>Degree(s)<br>and Year(s)" => $doctoralDegrees,
                        "Faculty Member" => "<p>".implode("</p><p>", $transformedFacultyNames)."</p>",
                        "Start Date" => $countingStartDate,
                        "Summary of Support During Training" => $supportSummaryHTML,
                        "Degree(s)<br>Resulting from<br>Postdoctoral<br>Training and<br>Year(s)" => $postdocDegrees,
                        "Topic of Research Project<br>(From ".self::getResearchTopicSource().")" => $topic,
                        "Initial Position<br>Department<br>Institution<br>Activity" => $initialPos,
                        "Current Position<br>Department<br>Institution<br>Activity" => $currentPos,
                        "Subsequent Grant(s)/Role/Year Awarded" => $subsequentGrants,
                    ];
                } else {
                    throw new \Exception("Invalid table number $table!");
                }
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
	    return self::$NA;
    }

    private function get8IVData() {
	    $predocs = $this->downloadPredocNames();
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
            $degreesAndYears = $this->getTerminalDegreesAndYears($recordId, FALSE);
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
	    if (preg_match("/".self::$unknownYearText."/", $year)) {
	        return FALSE;
        }
	    return TRUE;
   }

    private function getAverageTimeToPhD($recordIds) {
	    $timesToPhD = array();
	    $currYear = date("Y");
	    foreach ($recordIds as $recordId) {
            $degreesAndYears = $this->getTerminalDegreesAndYears($recordId, FALSE);
            foreach ($degreesAndYears as $degree => $year) {
                if (self::isKnownDate($degree, $year)) {
                    if (preg_match("/PhD/", $degree)) {
                        array_push($timesToPhD, $currYear - $year);
                    }
                }
            }
        }
	    if (count($timesToPhD)) {
            return array_sum($timesToPhD) / count($timesToPhD);
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
	    $table = preg_replace("/-VUMC$/", "", $table);
	    $romanNumeral = preg_replace("/^\d[A-G]/", "", $table);

	    return self::integerToRoman($romanNumeral);
    }

	private static function makeDataIntoHTML($data, $namesPre = [], $namesPost = []) {
		if (count($data) == 0) {
		    $prefatoryRemarks = "";
		    if (isset($_GET['test'])) {
                $prefatoryRemarks = "<p class='centered'>".count($namesPre)." predoc names and ".count($namesPost)." postdoc names.</p>\n";
            }
			return $prefatoryRemarks."<p class='centered'>No data available.</p>\n";
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
			        if ($value == self::$blank) {
                        $style = " class='leftAlignedCell grey'";
                    } else {
                        $style = " class='leftAlignedCell'";
                    }
                }
                if ($value == self::$blank) {
                    array_push($currRow, "<td".$style."></td>");
                } else if ($value) {
                    array_push($currRow, "<td".$style.">$value</td>");
                } else {
			        array_push($currRow, "<td".$style.">".self::$NA."</td>");
                }
			}
			array_push($htmlRows, "<tr>".implode("", $currRow)."</tr>\n"); 
		}

		$html = "<table class='centered bordered'>".implode("", $htmlRows)."</table>\n";
		return $html;
	}

	private static function getHTMLPrefix($table) {
	    if (self::beginsWith($table, array("6A", "6B"))) {
	        return "<p class='centered'>Note: The prior research experiences, prior institutions, and GPAs are entered by the scholar in the Initial Survey.</p>";
        }
	    return "";
    }

	public static function beginsWith($table, $ary) {
		foreach ($ary as $a) {
			$regex = "/^".$a."/i";
			if (preg_match($regex, $table)) {
				return TRUE;
			}
		}
		return FALSE;
	}

	private static function isPredocTable($table) {
	    return self::beginsWith($table, array("5A", "6A", "8A"));
    }

    private static function isPostdocTable($table) {
	    return self::beginsWith($table, array("5B", "6B", "8C"));
    }

	private static function getTrainingTypes($table) {
		if (self::isPredocTable($table)) {
			$types = array(6);
		} else if (self::isPostdocTable($table)) {
			$types = array(7);
		} else {
			$types = array();
		}
		return $types;
	}

	public function isPredoc($recordId) {
        $types = Download::appointmentsForRecord($this->token, $this->server, $recordId);
        foreach ($types as $type) {
            if (in_array($type, [6])) {
                return TRUE;
            }
        }
        return FALSE;
    }

    public function isPostdoc($recordId) {
        $types = Download::appointmentsForRecord($this->token, $this->server, $recordId);
        foreach ($types as $type) {
            if (in_array($type, [7])) {
                return TRUE;
            }
        }
        return FALSE;
    }

    public function downloadPostdocNames($table = "") {
        if (preg_match("/-VUMC$/", $table)) {
            if ($_GET['cohort']) {
                $names = Download::postdocAppointmentNames($this->token, $this->server, $this->metadata, $_GET['cohort']);
            } else {
                $names = Download::postdocAppointmentNames($this->token, $this->server);
            }
        } else {
            if ($_GET['cohort']) {
                $names = Download::postdocNames($this->token, $this->server, $this->metadata, $_GET['cohort']);
            } else {
                $names = Download::postdocNames($this->token, $this->server);
            }
        }
        return $names;
    }

    public function downloadPredocNames() {
        if ($_GET['cohort']) {
            $names = Download::predocNames($this->token, $this->server, $this->metadata, $_GET['cohort']);
        } else {
            $names = Download::predocNames($this->token, $this->server);
        }
        return $names;
    }

    private static function getTrainingTypesForGrantClass() {
	    global $grantClass;
        if (in_array($grantClass, ["T", "Other"])) {
            return [10];   // Other is training grant
        } else if (in_array($grantClass, ["K"])) {
            return [2, 10];
        } else if ($grantClass == "") {
            return [2];    // K12 by default
        }
        throw new \Exception("Invalid Grant Class: $grantClass");
	}

    private function downloadRelevantNames($table, $records) {
	    if ($records === NULL) {
	        $records = [];
        }
	    if (!is_array($records)) {
	        $records = [$records];
        }
	    if (empty($records)) {
	        $records = Download::recordIds($this->token, $this->server);
        }
		if (self::isPredocTable($table)) {
            $allNames = $this->downloadPredocNames();
        } else if (self::isPostdocTable($table)) {
            $allNames = $this->downloadPostdocNames($table);
		} else {
            $allNames = [];
		}
		if (isset($_GET['test'])) {
		    echo "Returning ".count($allNames)." for ".count($records)." records.<br>";
            if (self::isPredocTable($table)) {
                echo "predoc table $table<br>";
            } else if (self::isPostdocTable($table)) {
                echo "postdoc table $table<br>";
            }
        }

		$names = [];
		foreach ($records as $record) {
		    if ($allNames[$record]) {
                $names[$record] = $allNames[$record];
            }
        }

		if (self::beginsWith($table, ["8A", "8C", "5B"])) {
		    $filteredNames = [];
		    # 1 = all K12s/KL2s or Training Grant, depending on class of project; 2 = Friends of Grant; 3 = Recent Graduates (Internal Ks)
		    if (self::beginsWith($table, ["5B"])) {
		        $part = 1;
            } else {
                $part = self::getPartNumber($table);
            }
		    $thisGrantTypes = self::getTrainingTypesForGrantClass();
            $internalKType = 1;
            if (isset($_GET['test'])) {
                echo "Looking in part $part with grant types ".json_encode($thisGrantTypes)."<br>";
            }
            if (in_array($part, [1, 3])) {
                $trainingData = Download::trainingGrants($this->token, $this->server, [], [5, 6, 7], [], $this->metadata);
                if (isset($_GET['test'])) {
                    echo "Downloaded ".count($trainingData)." rows of training grants<br>";
                }
                foreach ($names as $recordId => $name) {
                    $currentGrants = self::getTrainingGrantsForRecord($trainingData, $recordId);
                    if (isset($_GET['test'])) {
                        echo count($currentGrants)." found for Record $recordId<br>";
                    }
                    foreach ($currentGrants as $row) {
                        if ($row['redcap_repeat_instrument'] == "custom_grant") {
                            if ($part == 1) {
                                if (self::isRecentGraduate($row['custom_type'], $row['custom_start'], $row['custom_end'], 15) && in_array($row['custom_type'], $thisGrantTypes)) {
                                    if (isset($_GET['test'])) {
                                        echo "Record $recordId ($name) is a recent graduate for part $part ".REDCapManagement::json_encode_with_spaces($row)."<br>";
                                    }
                                    $filteredNames[$recordId] = $name;
                                } else if (isset($_GET['test'])) {
                                    echo "Record $recordId ($name) is not a recent graduate for part $part ".REDCapManagement::json_encode_with_spaces($row)."<br>";
                                }
                            } else if ($part == 3) {
                                # recent graduates - those whose appointments have ended
                                # for new applications only (currently)
                                if (self::isRecentGraduate($row['custom_type'], $row['custom_start'], $row['custom_end'], 5) && ($row['custom_type'] == $internalKType)) {
                                    if (isset($_GET['test'])) {
                                        echo "Record $recordId ($name) is a recent graduate for part $part ".REDCapManagement::json_encode_with_spaces($row)."<br>";
                                    }
                                    $filteredNames[$recordId] = $name;
                                } else if (isset($_GET['test'])) {
                                    echo "Record $recordId ($name) is not a recent graduate for part $part ".REDCapManagement::json_encode_with_spaces($row)."<br>";
                                }
                            }
                        }
                    }
                }
            } else if ($part == 2) {
                # friends of the grant => fill in by hand
            }
            if (isset($_GET['test'])) {
                echo "Returning ".count($filteredNames)." names<br>";
            }
            return $filteredNames;
        }
		return $names;
	}

	private static function isRecentGraduate($type, $start, $end, $yearsAgo) {
	    if (!$end) {
	        if (!$start) {
                return FALSE;
            }
	        if ($type == 1) {
                $end = REDCapManagement::addYears($start, Application::getInternalKLength());
            } else if ($type == 2) {
                $end = REDCapManagement::addYears($start, Application::getK12KL2Length());
            } else if (in_array($type, [3, 4])) {
                $end = REDCapManagement::addYears($start, Application::getIndividualKLength());
            } else {
	            return FALSE;
            }
        }
	    $endTs = strtotime($end);
	    $currYear = date("Y");
	    $currYear -= $yearsAgo;
	    $yearsAgoDate = $currYear.date("-m-d");
	    $yearsAgoTs = strtotime($yearsAgoDate);
        if (isset($_GET['test'])) {
            echo "Comparing $end ($endTs) >= $yearsAgoDate ($yearsAgoTs) ($yearsAgo years ago)<br>";
        }
	    if ($endTs >= $yearsAgoTs) {
	        if (isset($_GET['test'])) {
                echo "Returning TRUE<br>";
            }
	        return TRUE;
        } else {
            if (isset($_GET['test'])) {
                echo "Returning FALSE<br>";
            }
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

    private function getStartAndEndFromSummary($recordId, $eligibleKs) {
        $awardFields = self::getSummaryAwardFields($this->metadata);
        $summaryData = Download::fieldsForRecords($this->token, $this->server, $awardFields, [$recordId]);
        $kDates = [];
        foreach ($summaryData as $row) {
            for ($i = 1; $i <= MAX_GRANTS; $i++) {
                if ($row['summary_award_type_' . $i] && in_array($row['summary_award_type_' . $i], $eligibleKs)) {
                    $kDates[] = ["begin" => $row['summary_award_date_' . $i], "end" => $row['summary_award_end_date_' . $i]];
                }
            }
        }
        list($beginDate, $endDate) = self::combineDates($kDates);
        $startYear = ($beginDate ? REDCapManagement::getYear($beginDate) : self::$NA);
        $startTs = ($beginDate ? strtotime($beginDate) : "");
        $endTs = ($endDate ? strtotime($endDate) : "");
        if ($endTs && $endTs > time()) {
            # in future
            $endYear = self::$presentMarker;
        } else {
            # in past
            $endYear = ($endDate ? REDCapManagement::getYear($endDate) : self::$presentMarker);
        }
        return [$startYear, $startTs, $endYear, $endTs];
    }

	public function get5Data($table, $records = []) {
        $eligibleKs = [2];     // K12/KL2 only
        $data = array();
		$names = $this->downloadRelevantNames($table, $records);
		if (isset($_GET['test'])) {
		    echo "<p class='centered'>".count($names)." being considered</p>";
        }
		if (!empty($names)) {
			$lastNames = Download::lastNames($this->token, $this->server);
			$firstNames = Download::firstNames($this->token, $this->server);
			$mentors = Download::primaryMentors($this->token, $this->server); 
			$trainingData = Download::trainingGrants($this->token, $this->server, [], [5, 6, 7], [], $this->metadata);
            $trainingStarts = Download::oneField($this->token, $this->server, "summary_training_start");
        }
		$fields = array_unique(array_merge(Application::getCitationFields($this->metadata), array("record_id")));
		foreach ($names as $recordId => $name) {
			$pubData = Download::fieldsForRecords($this->token, $this->server, $fields, array($recordId));
			$traineeName = $name;

			# fill $trainingPeriod, $pastOrCurrent, $startTs, $startYear, $endTs, $endYear
            $pastOrCurrent = "";
			$trainingPeriod = "";
			$startTs = 0;
			if ($trainingStarts[$recordId]) {
			    $startTs = strtotime($trainingStarts[$recordId]);
                $startYear = REDCapManagement::getYear($trainingStarts[$recordId]);
            }
			$endTs = time();
			$currentGrants = self::getTrainingGrantsForRecord($trainingData, $recordId);
			if ($_GET['test']) {
			    echo "Record $recordId has ".count($currentGrants)." grants.<br>";
            }
			if (empty($currentGrants)) {
			    list($startYear, $startTs, $endYear, $endTs) = $this->getStartAndEndFromSummary($recordId, $eligibleKs);
            } else {
                foreach ($currentGrants as $row) {
                    if ($row['redcap_repeat_instrument'] == "custom_grant") {
                        $currStartTs = strtotime($row['custom_start']);
                        if (!$startTs || ($currStartTs < $startTs)) {
                            $startTs = $currStartTs;
                            $startYear = REDCapManagement::getYear($row['custom_start']);
                        } else {
                            $startYear = self::$NA;
                        }
                        if ($row['custom_end'] && in_array($row['custom_type'], $eligibleKs)) {
                            $endTs = strtotime($row['custom_end']);
                            if ($endTs > time()) {
                                # in future
                                $endYear = self::$presentMarker;
                                $calculatedEndYear = $this->getEndYearOfInternalKOrK12($recordId);
                                if ($calculatedEndYear && ($calculatedEndYear < $endYear)) {
                                    $endYear = $calculatedEndYear;
                                }
                            } else {
                                # in past
                                $endYear = REDCapManagement::getYear($row['custom_end']);
                            }
                        } else {
                            list($startYearCopy, $startTsCopy, $endYear, $endTs) = $this->getStartAndEndFromSummary($recordId, $eligibleKs);
                            if (!$startTs) {
                                $startTs = $startTsCopy;
                                $startYear = $startYearCopy;
                            }
                        }
                    }
                }
            }

			if (!$endYear || ($endYear == self::$presentMarker)) {
			    list($autocalcEndYear, $autocalcEndTs) = $this->autocalculateKLength($currentGrants, $recordId, [1, 2]);
			    if ($autocalcEndTs && ($autocalcEndTs < time())) {
			        $endYear = $autocalcEndYear;
			        $endTs = $autocalcEndTs;
                }
                if (!$endYear) {
                    $endYear = self::$presentMarker;
                }
                if ($endYear == self::$presentMarker) {
                    $endYear = self::$presentMarker;
                }
            }

            if ($endYear == self::$presentMarker) {
                $pastOrCurrent = "Current";
            } else {
                $pastOrCurrent = "Past";
            }
            $trainingPeriod = "$startYear-$endYear";

            # track 18 months after the end of the training grant
            if ($endTs) {
                $eighteenMonthsDuration = 18 * 30 * 24 * 3600;
                $endTs += $eighteenMonthsDuration;
            } else {
                $endTs = time();
            }

			$pubs = new Publications($this->token, $this->server, $this->metadata);
			$pubs->setRows($pubData);

            $transformedFacultyNames = self::transformNamesToLastFirst($mentors[$recordId]);
            $noPubsRow = array(
                "Trainee Name" => $traineeName,
                "Faculty Member" => "<p>".implode("</p><p>", $transformedFacultyNames)."</p>",
                "Past or Current Trainee" => $pastOrCurrent,
                "Training Period" => $trainingPeriod,
                "Publication" => "No Publications: ".self::makeComment("Explanation Needed"),
            );

            if ($pubs->getCitationCount() == 0) {
                if (isset($_GET['test'])) {
                    echo "Record $recordId has no confirmed pubs.<br>";
                }
				array_push($data, $noPubsRow);
			} else {
				$citations = $pubs->getSortedCitations("Included");
				$nihFormatCits = array();
				foreach ($citations as $citation) {
                    if ($citation->inTimespan($startTs, $endTs)) {
                        $nihFormatCits[] = $citation->getNIHFormat($lastNames[$recordId], $firstNames[$recordId], CareerDev::isVanderbilt());
                    }
				}
				if (count($nihFormatCits) == 0) {
                    array_push($data, $noPubsRow);
                } else {
                    $transformedFacultyNames = self::transformNamesToLastFirst($mentors[$recordId]);
                    $dataRow = array(
                        "Trainee Name" => $traineeName,
                        "Faculty Member" => "<p>".implode("</p><p>", $transformedFacultyNames)."</p>",
                        "Past or Current Trainee" => $pastOrCurrent,
                        "Training Period" => $trainingPeriod,
                        "Publication" => "<p class='citation'>".implode("</p><p class='citation'>", $nihFormatCits)."</p>",
                    );
                    array_push($data, $dataRow);

                }
			}
		}
		return $data;
	}

	private function autocalculateKLength($currentGrants, $recordId, $eligibleKs) {
	    foreach ($currentGrants as $row) {
            $startDate = $row['custom_start'];
            $type = $row['custom_type'];
            if ($startDate && $type && in_array($type, $eligibleKs)) {
                return self::transformStartAndTypeToEnd($startDate, $type);
            }
        }

	    $summaryData = Download::fieldsForRecords($this->token, $this->server, self::getSummaryAwardFields($this->metadata), [$recordId]);
        $row = REDCapManagement::getNormativeRow($summaryData);
        $offTrainingStartDate = FALSE;
        $offTrainingTypes = [3, 5, 6];
        $transformedEnd = FALSE;
        for ($i = 1; $i <= MAX_GRANTS; $i++) {
            $startDate = $row["summary_award_date_".$i];
            $type = $row["summary_award_type_".$i];
            if (!$transformedEnd && $startDate && $type && in_array($type, $eligibleKs)) {
                $transformedEnd = self::transformStartAndTypeToEnd($startDate, $type);
            }
            if (!$offTrainingStartDate && $row['summary_award_type_'.$i] && in_array($row['summary_award_type_'.$i], $offTrainingTypes)) {
                $offTrainingStartDate = $row['summary_award_date_'.$i];
            }
        }

        if ($offTrainingStartDate) {
            $offTrainingStartTs = strtotime($offTrainingStartDate);
            $oneDay = 24 * 3600;
            $endTs = $offTrainingStartTs - $oneDay;
            $endDate = date("Y-m-d", $endTs);
            return [$endDate, $endTs];
        }
        if ($transformedEnd) {
            return $transformedEnd;
        }
	    return [self::$presentMarker, FALSE];
    }

    private static function transformStartAndTypeToEnd($startDate, $type) {
        $yearLength = Grant::autocalculateGrantLength($type);
        $endDate = REDCapManagement::addYears($startDate, $yearLength);
        $endTs = strtotime($endDate);
        $endYear = date("Y", $endTs);
        return [$endYear, $endTs];
    }

	private static function getSummaryAwardFields($metadata) {
        $awardDateFields = Scholar::getAwardDateFields($metadata);
        $awardTypeFields = Scholar::getAwardTypeFields($metadata);
        return array_unique(array_merge($awardDateFields, $awardTypeFields, ["record_id"]));
    }

    private function getEndYearOfInternalKOrK12($recordId) {
	    $date = $this->getEndDateOfInternalKOrK12($recordId);
	    if ($date) {
	        $ts = strtotime($date);
	        return date("Y", $ts);
        }
	    return FALSE;
    }

	private function getEndDateOfInternalKOrK12($recordId) {
	    $awardFields = self::getSummaryAwardFields($this->metadata);
        $summaryData = Download::fieldsForRecords($this->token, $this->server, $awardFields, [$recordId]);
        $eligibleKs = [3];
        foreach ($summaryData as $row) {
            for ($i = 1; $i <= MAX_GRANTS; $i++) {
                if ($row['summary_award_type_'.$i] && in_array($row['summary_award_type_'.$i], $eligibleKs) && $row['summary_award_date_'.$i]) {
                    $oneDay = 24 * 3600;
                    $ts = strtotime($row['summary_award_date_'.$i]);
                    return date("Y-m-d", $ts - $oneDay);
                }
            }
        }
	    return FALSE;
    }

	private static function combineDates($dates) {
	    if (count($dates) == 0) {
	        return [FALSE, FALSE];
        }
	    $oneYear = 365 * 24 * 3600;
	    $span = 20 * $oneYear;
	    $earliestTs = time() + $span;
	    $latestTs = 0;
	    foreach ($dates as $date) {
	        $beginTs = $date['begin'] ? strtotime($date['begin']) : time() + $span;
	        $endTs = $date['end'] ? strtotime($date['end']) : 0;
	        $earliestTs = ($beginTs < $earliestTs) ? $beginTs : $earliestTs;
	        $latestTs = ($endTs > $latestTs) ? $endTs : $latestTs;
        }

	    if ($earliestTs && $latestTs) {
            $format = "Y-m-d";
            return [date($format, $earliestTs), date($format, $latestTs)];
        }
        return [FALSE, FALSE];
    }

	private $token;
	private $server;
	private $pid;
	private $metadata;
	private $choices;
	private static $notAvailable = "Not Available";
	private static $NA;
	private static $blank = "[Blank]";
	private static $presentMarker = "Present";
	private static $unknownYearText = "Unknown Year";
	private static $unknownInstitutionText = "Unknown Institution";
	public static $maxYearsOfGrantReporting = 15;
}
