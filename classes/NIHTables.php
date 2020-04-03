<?php

namespace Vanderbilt\CareerDevLibrary;

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/Download.php");
require_once(dirname(__FILE__)."/Citation.php");
require_once(dirname(__FILE__)."/Publications.php");
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
			return $front." Part ".$back;
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
		}
		return "";
	}

	public function getHTML($table) {
		if (self::beginsWith($table, array("5A", "5B"))) {
			$data = $this->get5Data($table);
			return self::makeDataIntoHTML($data);
		} else if (self::beginsWith($table, array("6A", "6B"))) {
			$html = "";
			foreach (self::get6Years() as $year) {
				$data = $this->get6Data($table, $year);
				$html .= self::makeDataIntoHTML($data);
			}
			return $html;
		} else if (self::beginsWith($table, array("8A", "8C"))) {
			$data = $this->get8Data($table);
			return self::makeDataIntoHTML($data);
		}
		return "";
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
		for ($i = 0; $i < $numYears; $i++) {
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
	    $choices = REDCapManagement::getChoices($this->metadata);
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
			if (self::enteredDuringYear($yearspan, $recordId, $trainingGrantData, $trainingTypes)) {
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

	private function get8Data($table) {
		$data = array();
		return $data;
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
				array_push($currRow, "<td>$value</td>");
			}
			array_push($htmlRows, "<tr>".implode("", $currRow)."</tr>\n"); 
		}

		$html = "<table>".implode("", $htmlRows)."</table>\n";
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
		return $names;
	}

	private function get5Data($table) {
		$data = array();
		$names = $this->downloadRelevantNames($table);
		if (!empty($names)) {
			$lastNames = Download::lastNames($this->token, $this->server);
			$firstNames = Download::firstNames($this->token, $this->server);
			$mentors = Download::primaryMentors($this->token, $this->server); 
			$trainingData = Download::trainingGrants($this->token, $this->server);
		}
		foreach ($names as $recordId => $name) {
			$facultyMember = $mentors[$recordId] ? $mentors[$recordId] : "";
			$traineeName = $name;
			$pastOrCurrent = "";
			$trainingPeriod = "";
			foreach ($trainingData as $row) {
				if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "custom_grant")) {
					$startTs = FALSE;
					if ($row['custom_start']) {
						$startTs = strtotime($row['custom_start']);
					}
					$endTs = FALSE;
					if ($row['custom_end']) {
						$endTs = strtotime($row['custom_end']);
					}
					if ($startTs) {
						if ($endTs) {
							$pastOrCurrent = "Past";
							$trainingPeriod = date("Y", $startTs)."-".date("Y", $endTs);
						} else {
							$pastOrCurrent = "Current";
							$trainingPeriod = date("Y", $startTs)."-Present";
						}
					}
				}
			}

			$redcapData = Download::fieldsForRecords($this->token, $this->server, Application::$citationFields, array($recordId));
			$pubs = new Publications($this->token, $this->server, $this->metadata);
			$pubs->setRows($redcapData);

			if ($pubs->getCitationCount() == 0) {
				$dataRow = array(
							"Faculty Member" => $facultyMember,
							"Trainee Name" => $traineeName,
							"Past or Current Trainee" => $pastOrCurrent,
							"Training Period" => $trainingPeriod,
							"Publication" => "No Publications",
						);
				array_push($data, $dataRow);
			} else {
				$citations = $pubs->getCitations("Included");
				foreach ($citations as $citation) {
					if ($citation->hasAuthor($facultyMember) && $citation->hasAuthor($traineeName)) {
						$dataRow = array(
									"Faculty Member" => $facultyMember,
									"Trainee Name" => $traineeName,
									"Past or Current Trainee" => $pastOrCurrent,
									"Training Period" => $trainingPeriod,
									"Publication" => $citation->getNIHFormat($lastNames[$recordId], $firstNames[$recordId]),
								);
						array_push($data, $dataRow);
					}
				}
			}
		}
		return $data;
	}

	private $token;
	private $server;
	private $pid;
	private $metadata;
	private static $NA = "N/A";
}
