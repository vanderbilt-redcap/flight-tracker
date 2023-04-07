<?php

namespace Vanderbilt\CareerDevLibrary;


# This file compiles all of the grants from various data sources and compiles them into an ordered list of grants.
# It should remove duplicate grants as well.
# Gelper classes as well.
# Unit-testable.

require_once(__DIR__ . '/ClassLoader.php');

abstract class GrantFactory {
	public function __construct($name, $lexicalTranslator, $metadata, $token = "", $server = "") {
		$this->name = $name;
		$this->lexicalTranslator = $lexicalTranslator;
		$this->metadata = $metadata;
		$this->choices = REDCapManagement::getChoices($this->metadata);
		$this->token = $token;
		$this->server = $server;
	}

    public function getName() {
        return $this->name;
    }

	public function getGrants() {
		return $this->grants;
	}

    public static function getGrantFactoryForRow($row, $name, $lexicalTranslator, $metadata, $token, $server) {
        if ($row['redcap_repeat_instrument'] == "coeus") {
            return new CoeusGrantFactory($name, $lexicalTranslator, $metadata, $token, $server);
        } else if ($row['redcap_repeat_instrument'] == "coeus2") {
            return new Coeus2GrantFactory($name, $lexicalTranslator, $metadata, "Grants", $token, $server);
        } else if ($row['redcap_repeat_instrument'] == "reporter") {
            return new RePORTERGrantFactory($name, $lexicalTranslator, $metadata, $token, $server);
        } else if ($row['redcap_repeat_instrument'] == "exporter") {
            return new ExPORTERGrantFactory($name, $lexicalTranslator, $metadata, $token, $server);
        } else if ($row['redcap_repeat_instrument'] == "nih_reporter") {
            return new NIHRePORTERGrantFactory($name, $lexicalTranslator, $metadata, $token, $server);
        } else if ($row['redcap_repeat_instrument'] == "vera") {
            return new VERAGrantFactory($name, $lexicalTranslator, $metadata, $token, $server);
        } else if ($row['redcap_repeat_instrument'] == "custom_grant") {
            return new CustomGrantFactory($name, $lexicalTranslator, $metadata, "Grants", $token, $server);
        } else if ($row['redcap_repeat_instrument'] == "followup") {
            return new FollowupGrantFactory($name, $lexicalTranslator, $metadata, $token, $server);
        } else if ($row['redcap_repeat_instrument'] == "nsf") {
            return new NSFGrantFactory($name, $lexicalTranslator, $metadata, $token, $server);
        } else if ($row['redcap_repeat_instrument'] == "ies_grant") {
            return new IESGrantFactory($name, $lexicalTranslator, $metadata, $token, $server);
        } else if ($row['redcap_repeat_instrument'] === "") {
            $checkGf = new InitialGrantFactory($name, $lexicalTranslator, $metadata, $token, $server);
            $checkGf->setPrefix("check");
            $initImportGf = new InitialGrantFactory($name, $lexicalTranslator, $metadata, $token, $server);
            $initImportGf->setPrefix("init_import");
            return [$checkGf, $initImportGf];
        } else {
            return NULL;
        }
    }


    public static function createFactoriesForRow($row, $name, $lexicalTranslator, $metadata, $token, $server, $allRows, $type = "Awarded", $includeSummaries = TRUE) {
        $gfs = [];

        if ($type == "Submissions") {
            if ($row['redcap_repeat_instrument'] == "coeus2") {
                $gfs[] = new Coeus2GrantFactory($name, $lexicalTranslator, $metadata, "Submissions", $token, $server);
            } else if ($row['redcap_repeat_instrument'] == "coeus_submission") {
                $gfs[] = new CoeusSubmissionGrantFactory($name, $lexicalTranslator, $metadata, $token, $server);
            } else if ($row['redcap_repeat_instrument'] == "vera_submission") {
                $gfs[] = new VERASubmissionGrantFactory($name, $lexicalTranslator, $metadata, $token, $server);
            } else if ($row['redcap_repeat_instrument'] == "custom_grant") {
                $gfs[] = new CustomGrantFactory($name, $lexicalTranslator, $metadata, "Submissions", $token, $server);
            }
        } else if ($type == "Awarded") {
            if ($row['redcap_repeat_instrument'] == "") {
                if (Application::isVanderbilt()) {
                    foreach ($row as $field => $value) {
                        if (preg_match("/^newman_/", $field)) {
                            $gfs[] = new NewmanGrantFactory($name, $lexicalTranslator, $metadata, $token, $server);
                            break;
                        }
                    }
                }
                if ($includeSummaries) {
                    $gfs[] = new PriorGrantFactory($name, $lexicalTranslator, $metadata, $token, $server);
                }
            }
            $gf = self::getGrantFactoryForRow($row, $name, $lexicalTranslator, $metadata, $token, $server);
            if (is_array($gf)) {
                $currentGfs = $gf;
                foreach ($currentGfs as $gf) {
                    $gfs[] = $gf;
                }
            } else if ($gf) {
                $gfs[] = $gf;
            }   // else NULL
        } else {
            throw new \Exception("Invalid type $type");
        }

        return $gfs;
    }

	public static function cleanAwardNo($awardNo) {
        if (!$awardNo) {
            return "";
        }
		$awardNo = preg_replace("/-\d\d[A-Za-z]\d$/", "", $awardNo);
		$awardNo = preg_replace("/-\d[A-Za-z]\d\d$/", "", $awardNo);
		$awardNo = preg_replace("/-\d\d\d\d$/", "", $awardNo);
		return $awardNo;
	}

	public static function numNodes($regex, $str) {
	    $allNodes = preg_split($regex, $str);
	    $newNodes = array();
	    foreach ($allNodes as $node) {
	        if ($n = trim($node)) {
	            $newNodes[] = $n;
            }
        }
	    return count($newNodes);
    }

    protected function getProjectIdentifiers($token) {
        if ($token) {
            $pid = Application::getPid($token);
            global $event_id;
        } else {
            global $pid, $event_id;
        }
        return [$pid, $event_id];
    }

	abstract public function processRow($row, $otherRows, $token = "");
    abstract public function getAwardFields();
    abstract public function getPIFields();

    private static function getAllClassNames() {
        $children = [];
        foreach(get_declared_classes() as $class){
            if (is_subclass_of($class, \Vanderbilt\CareerDevLibrary\GrantFactory::class)) {
                $children[] = $class;
            }
        }
        return $children;
    }

    public static function getAllPIFields($token, $server)
    {
        return self::getFieldsHelper($token, $server, "PI");
    }

    public static function getAllAwardFields($token, $server)
    {
        return self::getFieldsHelper($token, $server, "Award");
    }

    public static function getFieldsHelper($token, $server, $type) {
        $lexicalTranslator = new GrantLexicalTranslator($token, $server, Application::getModule());
        $fields = [];
        $metadataFields = Download::metadataFields($token, $server);
        foreach (self::getAllClassNames() as $class) {
            $gf = new $class("", $lexicalTranslator, []);
            if ($type == "PI") {
                $fields = array_unique(array_merge($fields, $gf->getPIFields()));
            } else if ($type == "Award") {
                $fields = array_unique(array_merge($fields, $gf->getAwardFields()));
            }
        }
        return DataDictionaryManagement::filterOutInvalidFieldsFromFieldlist($metadataFields, $fields);
    }

	protected function extractFromOtherSources($rows, $excludeSources, $variable, $awardNo) {
        $sourceOrder = Grants::getSourceOrder();
        $lowerAwardNo = strtolower($awardNo);
        foreach ($sourceOrder as $source) {
            if (in_array($source, $excludeSources)) {
                continue;
            }
            $sourceRows = [];
            foreach ($rows as $row) {
                if ($row['redcap_repeat_instrument'] == $source) {
                    $sourceRows[] = $row;
                }
            }
            if (!empty($sourceRows)) {
                $grants = new Grants($this->token, $this->server, $this->metadata);
                $grants->setRows($sourceRows);
                $grantAry = $grants->getGrants("native");
                foreach ($grantAry as $grant) {
                    if (strtolower($grant->getNumber()) == $lowerAwardNo) {
                        $value = $grant->getVariable($variable);
                        if ($variable == "role") {
                            if ($value != self::$defaultRole) {
                                return $value;
                            }
                        } else if ($value !== "") {
                            return $value;
                        }
                    }
                }
            }
        }
        return "";
    }

	protected $name = "";
	protected $grants = array();
	protected $lexicalTranslator;
	protected $metadata;
	protected $choices;
	protected $token;
	protected $server;
	protected static $defaultRole = "PI/Co-PI";
}

class InitialGrantFactory extends GrantFactory {
    public function setPrefix($prefix) {
        $prefix = preg_replace("/_$/", "", $prefix);
        $this->prefix = $prefix;
    }

    public function getAwardFields() {
        $prefix = $this->prefix;
        $fields = [];
        for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
            $fields[] = $prefix.'_grant'.$i.'_number';
        }
        return $fields;
    }

    public function getPIFields() {
        return [];
    }

    # get the Scholars' Survey (always nicknamed check) default spec array
	public function processRow($row, $otherRows, $token = "") {
        $prefix = $this->prefix;
        list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
		for ($i=1; $i <= Grants::$MAX_GRANTS; $i++) {
			if (($row[$prefix."_grant$i"."_start"] != "")
                && (
                    !isset($row[$prefix."_grant$i"."_notmine"])
                    || ($row[$prefix."_grant$i"."_notmine"] != '1')
                )
                && in_array($row[$prefix."_grant".$i."_role"], [1, 2])) {

			    $url = APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=initial_survey";
			    $awardno = $row[$prefix.'_grant'.$i.'_number'];
				$grant = new Grant($this->lexicalTranslator);
				$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
                $grant->setVariable('start', $row[$prefix.'_grant'.$i.'_start']);
                $grant->setVariable('end', $row[$prefix.'_grant'.$i.'_end']);
                $grant->setVariable('project_start', $row[$prefix.'_grant'.$i.'_start']);
                $grant->setVariable('project_end', $row[$prefix.'_grant'.$i.'_end']);
				if ($prefix == "check") {
                    $grant->setVariable('source', "scholars");
                } else if ($prefix == "init_import") {
                    $grant->setVariable('source', "manual");
                }
				$costs = Grant::removeCommas($row[$prefix.'_grant'.$i.'_costs']);
				$grant->setVariable('budget', $costs);
				$grant->setVariable('direct_budget', $costs);
				// $grant->setVariable('fAndA', Grants::getFAndA($awardno, $row['check_grant'.$i.'_start']));
				$grant->setVariable('finance_type', Grants::getFinanceType($awardno));
                $grant->setVariable('sponsor', $row[$prefix.'_grant'.$i.'_org']);
                $grant->setVariable('flagged', $row[$prefix.'_grant'.$i.'_flagged'] ?? "");
				$grant->setVariable('url', $url);
				$grant->setVariable('link', Links::makeLink($url, "See Grant"));
				# Co-PI or PI, not Co-I or Other
				if (in_array($row[$prefix.'_grant'.$i.'_role'], [1, 2, ''])) {
					$grant->setVariable('pi_flag', 'Y');
				} else {
					$grant->setVariable('pi_flag', 'N');
				}
				if (empty($this->choices)) {
				    $field = $prefix."_grant".$i."_role";
				    $fieldChoices = DataDictionaryManagement::getChoicesForField($pid, $field);
                    $grant->setVariable("role", $fieldChoices[$row[$field]]);
                } else {
                    $grant->setVariable("role", $this->choices[$prefix."_grant".$i."_role"][$row[$prefix."_grant".$i."_role"]]);
                }
				$grant->setNumber($awardno);
				$grant->setVariable("original_award_number", $awardno);
				if (preg_match("/^\d?[A-Z]\d\d/", $awardno, $matches)) {
					$match = preg_replace("/^\d/", "", $matches[0]);
					$grant->setVariable('nih_mechanism', $match);
				}
				$grant->putInBins();
				$this->grants[] = $grant;
			}
		}
	}

    private $prefix = "";
}

class FollowupGrantFactory extends GrantFactory {
    public function getAwardFields() {
        $prefix = "followup";
        $fields = [];
        for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
            $fields[] = $prefix.'_grant'.$i.'_number';
        }
        return $fields;
    }

    public function getPIFields() {
        return [];
    }

    public function processRow($row, $otherRows, $token = "") {
        list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
		for ($i=1; $i <= Grants::$MAX_GRANTS; $i++) {
			if (($row["followup_grant$i"."_start"] != "")
                && ($row["followup_grant$i"."_notmine"] != '1')
                && in_array($row["followup_grant$i"."_role"], [1, 2])) {

			    $url = APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=followup&instance={$row['redcap_repeat_instance']}";
				$awardno = $row['followup_grant'.$i.'_number'];

				$grant = new Grant($this->lexicalTranslator);
				$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
                $grant->setVariable('start', $row['followup_grant'.$i.'_start']);
                $grant->setVariable('end', $row['followup_grant'.$i.'_end']);
                $grant->setVariable('project_start', $row['followup_grant'.$i.'_start']);
                $grant->setVariable('project_end', $row['followup_grant'.$i.'_end']);
				$grant->setVariable('source', "followup");
				$costs = Grant::removeCommas($row['followup_grant'.$i.'_costs']);
				$grant->setVariable('budget', $costs);
				// $grant->setVariable('fAndA', Grants::getFAndA($awardno, $row['followup_grant'.$i.'_start']));
				$grant->setVariable('finance_type', Grants::getFinanceType($awardno));
				$grant->setVariable('direct_budget', $costs);
                $grant->setVariable('sponsor', $row['followup_grant'.$i.'_org']);
                $grant->setVariable('flagged', $row['followup_grant'.$i.'_flagged'] ?? "");
				$grant->setVariable('url', $url);
				$grant->setVariable('link', Links::makeLink($url, "See Grant"));
				# Co-PI or PI, not Co-I or Other
				if (in_array($row['followup_grant'.$i.'_role'], [1, 2, ''])) {
					$grant->setVariable('pi_flag', 'Y');
				} else {
					$grant->setVariable('pi_flag', 'N');
				}
				if (empty($this->choices)) {
                    $field = "followup_grant".$i."_role";
                    $fieldChoices = DataDictionaryManagement::getChoicesForField($pid, $field);
                    $grant->setVariable("role", $fieldChoices[$row[$field]]);
                } else {
                    $grant->setVariable("role", $this->choices["followup_grant".$i."_role"][$row["followup_grant".$i."_role"]]);
                }
				$grant->setNumber($awardno);
				$grant->setVariable("original_award_number", $awardno);
				if (preg_match("/^\d?[A-Z]\d\d/", $awardno, $matches)) {
					$match = preg_replace("/^\d/", "", $matches[0]);
					$grant->setVariable('nih_mechanism', $match);
				}
                $grant->putInBins();
                $this->grants[] = $grant;
			}
		}
	}
}

class NewmanGrantFactory extends GrantFactory {
    public function getAwardFields() {
        return [];
    }

    public function getPIFields() {
        return [];
    }

    public function processRow($row, $otherRows, $token = "") {
		$this->processNewmanData($row);
		$this->processSheet2($row);
		$this->processNew2017($row);
	}

	private static function addYearsToDate($date, $years) {
		$ts = strtotime($date);
		$yearOfTs = date("Y", $ts);
		$yearOfNewTs = $yearOfTs + $years;
		$restOfTs = date("-m-d", $ts);
		return $yearOfNewTs.$restOfTs;
	}

	private function processNewmanData($row) {
		global $pid, $event_id;
		$internalKAwardLength = Application::getInternalKLength();
		$externalKAwardLength = Application::getIndividualKLength();
		$k12kl2AwardLength = Application::getK12KL2Length();

		$date1 = "";
		if (!preg_match("/none/", $row['newman_data_date_first_institutional_k_award_newman'])) {
			$date1 = $row['newman_data_date_first_institutional_k_award_newman'];
		}
		if ($date1) {
			foreach (self::getNewmanFirstType($row, "data_internal") as $type) {
			    $url = APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=data";
				$grant = new Grant($this->lexicalTranslator);
				$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
				$grant->setVariable('pi_flag', "Y");
				$grant->setVariable("role", self::$defaultRole);
                $grant->setVariable('start', $date1);
                $grant->setVariable('project_start', $date1);
				$grant->setVariable('budget', 0);
				$grant->setVariable('direct_budget', 0);
				$grant->setVariable('source', "data");
				$grant->setVariable('sponsor_type', $type);
				$grant->setVariable('url', $url);
				$grant->setVariable('link', Links::makeLink($url, "See Grant"));

				$isk12kl2 = FALSE;
				if (preg_match("/K12/", $type) || preg_match("/KL2/", $type)) {
					$grant->setNumber($type);
					$include = TRUE;
					$isk12kl2 = TRUE;
				} else {
					if ($type) {
						$grant->setNumber($type);
						$include = TRUE;
					} else {
						$grant->setNumber("K12/KL2 - Rec. {$row['record_id']}");
						$include = FALSE;
						$isk12kl2 = TRUE;
					}
				}
				if ($isk12kl2) {
					$endDate = self::addYearsToDate($date1, $k12kl2AwardLength);
				} else {
					$endDate = self::addYearsToDate($date1, $internalKAwardLength);
				}
                $grant->setVariable("end", $endDate);
                $grant->setVariable("project_end", $endDate);
				if ($include) {
					$grant->putInBins();
					$this->grants[] = $grant;
				}
			}
		}
	
		$date2 = "";
		if (!preg_match("/none/", $row['newman_data_individual_k_start'])) {
			$date2 = $row['newman_data_individual_k_start'];
		}
		if ($date2) {
			foreach (self::getNewmanFirstType($row, "data_individual") as $type) {
			    $url = APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=data";
				$grant = new Grant($this->lexicalTranslator);
				$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
				$grant->setVariable('pi_flag', "Y");
                $grant->setVariable("role", self::$defaultRole);
                $grant->setVariable('source', "data");
                $grant->setVariable('url', $url);
				$grant->setVariable('link', Links::makeLink($url, "See Grant"));
                $grant->setVariable('start', $date2);
                $grant->setVariable('project_start', $date2);
                $endDate = self::addYearsToDate($date2, $externalKAwardLength);
                $grant->setVariable('end', $endDate);
                $grant->setVariable('project_end', $endDate);
				$grant->setVariable('budget', 0);
				$grant->setVariable('direct_budget', 0);
				$grant->setVariable('sponsor_type', $type);
				if ($type) {
					$grant->setNumber($type);
				} else {
					$grant->setNumber("Individual K - Rec. {$row['record_id']}");
				}
				$grant->putInBins();
				$this->grants[] = $grant;
			}
		}
	
		$date3 = "";
		if (!preg_match("/none/", $row['newman_data_r01_start'])) {
			$date3 = $row['newman_data_r01_start'];
		}
		if ($date3) {
		    $url = APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=data";
			$grant = new Grant($this->lexicalTranslator);
			$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
			$grant->setVariable('pi_flag', "Y");
            $grant->setVariable("role", self::$defaultRole);
            $grant->setVariable('start', $date3);
            $grant->setVariable('project_start', $date3);
            $grant->setVariable('end', "");
            $grant->setVariable('project_end', "");
			$grant->setVariable('budget', 0);
			$grant->setVariable('direct_budget', 0);
			$grant->setVariable('source', "data");
			$grant->setVariable('url', $url);
			$grant->setVariable('link', Links::makeLink($url, "See Grant"));
			$grant->setVariable('sponsor_type', "R01");
			$grant->setNumber("R01");
			$grant->putInBins();
			$this->grants[] = $grant;
		}
	}

	# get the first type of a Newman data entry
	private static function getNewmanFirstType($row, $dataSource) {
		$current = "";
		$previous = "";
		if ($dataSource == "data_individual") {
			$previous = $row['newman_data_previous_nih_grant_funding_newman'];
			$current = $row['newman_data_nih_current'];
		} else if ($dataSource == "data_internal") {
			$previous = $row['newman_data_previous_program_funding_newman'];
			$current = $row['newman_data_current_program_funding_newman'];
		} else if ($dataSource == "sheet2_internal") {
			$previous = $row['newman_sheet2_previous_program_funding_2'];
			$current = $row['newman_sheet2_current_program_funding_2'];
		} else if ($dataSource == "sheet2_noninst") {
			$previous = $row['newman_sheet2_previous_funding'];
			$current = $row['newman_sheet2_current_funding'];
		}
		if ((preg_match("/none/", $current) || ($current == "")) && (preg_match("/none/", $previous) || ($previous == ""))) {
			return array();
		} else {
			$previous = preg_replace("/none/", "", $previous);
			$current = preg_replace("/none/", "", $current);
			if ($previous && $current) {
				return self::splitAwards($previous);
			} else if ($previous) {
				return self::splitAwards($previous);
			} else if ($current) {
				return self::splitAwards($current);
			} else {
				// individual K
				return array();
			}
		}
	}

	# splits multiple awards into an array for one Newman data entry
	private static function splitAwards($en) {
		$a = preg_split("/\s*[\|;,]\s*/", $en);
		return $a;
	}


	# sheet 2 into specs
	# sheet 2 is of questionable origin and is the least reliable of the data sources
	# we do not know the origin or author of sheet 2
	private function processSheet2($row) {
		global $pid, $event_id;

		$internalKAwardLength = Application::getInternalKLength();
		$k12kl2AwardLength = Application::getK12KL2Length();
		$externalKAwardLength = Application::getIndividualKLength();

		$internalKDate = "";
		if (!preg_match("/none/", $row['newman_sheet2_institutional_k_start'])) {
			$internalKDate = $row['newman_sheet2_institutional_k_start'];
		}
		if ($internalKDate) {
			foreach (self::getNewmanFirstType($row, "sheet2_internal") as $type) {
			    $url = APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=sheet2";
				$grant = new Grant($this->lexicalTranslator);
				$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
                $grant->setVariable('start', $internalKDate);
                $grant->setVariable('project_start', $internalKDate);
				$grant->setVariable('source', "sheet2");
				$grant->setVariable('url', $url);
				$grant->setVariable('link', Links::makeLink($url, "See Grant"));
				$grant->setVariable('budget', 0);
				$grant->setVariable('direct_budget', 0);
				$grant->setVariable('sponsor_type', $type);

				$include = FALSE;
				$isk12kl2 = FALSE;
				if (preg_match("/K12/", $type) || preg_match("/KL2/", $type)) {
					$grant->setNumber($type);
					$include = TRUE;
					$isk12kl2 = TRUE;
				} else {
					if ($type) {
						$grant->setNumber($type);
						$include = TRUE;
						$isk12kl2 = FALSE;
					} else {
						$grant->setNumber("K12/KL2 - Rec. {$row['record_id']}");
						$include = FALSE;
						$isk12kl2 = TRUE;
					}
				}
				if ($isk12kl2) {
					$endDate = self::addYearsToDate($internalKDate, $k12kl2AwardLength);
				} else {
					$endDate = self::addYearsToDate($internalKDate, $internalKAwardLength);
				}
                $grant->setVariable("end", $endDate);
                $grant->setVariable("project_end", $endDate);
				if ($include) {
					$grant->setVariable('pi_flag', "Y");
                    $grant->setVariable("role", self::$defaultRole);
					$grant->putInBins();
					$this->grants[] = $grant;
				}
			}
		}

		$noninstDate = "";
		if (!preg_match("/none/", $row['newman_sheet2_noninstitutional_start'])) {
			$noninstDate = $row['newman_sheet2_noninstitutional_start'];
		}
		if ($noninstDate) {
			foreach (self::getNewmanFirstType($row, "sheet2_noninst") as $awardno) {
				$url = APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=sheet2";
			    $grant = new Grant($this->lexicalTranslator);
				$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
                $grant->setVariable('start', $noninstDate);
                $grant->setVariable('project_start', $noninstDate);
                $endDate = self::addYearsToDate($noninstDate, $externalKAwardLength);
                $grant->setVariable('end', $endDate);
                $grant->setVariable('project_end', $endDate);
				$grant->setVariable('source', "sheet2");
				$grant->setVariable('url', $url);
				$grant->setVariable('link', Links::makeLink($url, "See Grant"));
				$grant->setVariable('budget', 0);
				$grant->setVariable('direct_budget', 0);
				$grant->setVariable('pi_flag', "Y");
				# for this, the type = the award no
				if (!$awardno) {
					$grant->setNumber("Unknown individual - Rec. {$row['record_id']}");
				} else {
					$grant->setNumber($awardno);
				}
				if (!$row['newman_sheet2_first_r01_date'] || preg_match("/none/", $row['newman_sheet2_first_r01_date']) || !preg_match("/[Rr]01/", $awardno)) {
					$grant->putInBins();
					$this->grants[] = $grant;
				}
			}
		}
	
		$r01Date = "";
		if (!preg_match("/none/", $row['newman_sheet2_first_r01_date'])) {
			$r01Date = $row['newman_sheet2_first_r01_date'];
		}
		if ($r01Date) {
		    $url = APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=sheet2";
			$grant = new Grant($this->lexicalTranslator);
			$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
            $grant->setVariable('start', $r01Date);
            $grant->setVariable('project_start', $r01Date);
            $grant->setVariable('end', "");
            $grant->setVariable('project_end', "");
            $grant->setVariable('pi_flag', "Y");
            $grant->setVariable("role", self::$defaultRole);
			$grant->setVariable('source', "sheet2");
			$grant->setVariable('url', $url);
			$grant->setVariable('link', Links::makeLink($url, "See Grant"));
			$grant->setVariable('budget', 0);
			$grant->setVariable('direct_budget', 0);

			$previous = $row['newman_sheet2_previous_funding'];
			$current = $row['newman_sheet2_current_funding'];

			if (preg_match("/[Rr]01/", $previous)) {
				$grant->setNumber(self::findR01($previous));
			} else if (preg_match("/[Rr]01/", $current)) {
				$grant->setNumber(self::findR01($current));
			} else {
				$grant->setNumber("R01");
			}
			$grant->putInBins();
			$this->grants[] = $grant;
		}
	}

	# This puts the new2017 folks into grants
	private function processNew2017($row) {
		global $pid, $event_id;
		$internalKDate = "";

		$internalKAwardLength = Application::getInternalKLength();
		$externalKAwardLength = Application::getIndividualKLength();
		$k12kl2AwardLength = Application::getK12KL2Length();

		if (!preg_match("/none/", $row['newman_new_first_institutional_k_award'])) {
			$internalKDate = $row['newman_new_first_institutional_k_award'];
		}
		if ($internalKDate) {
			$grant = new Grant($this->lexicalTranslator);
			$url = APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=new_2017";
			$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
            $grant->setVariable('start', $internalKDate);
            $grant->setVariable('project_start', $internalKDate);
			$grant->setVariable('source', "new2017");
			$grant->setVariable('url', $url);
			$grant->setVariable('link', Links::makeLink($url, "See Grant"));
			$grant->setVariable('budget', 0);
			$grant->setVariable('direct_budget', 0);
			$sponsorType = $row["newman_new_current_program_funding"];
			$grant->setVariable('sponsor_type', $sponsorType);

			$include = FALSE;
			$isk12kl2 = FALSE;
			if ($sponsorType) {
				$grant->setNumber($sponsorType);
				$include = TRUE;
				if (preg_match("/K12/", $sponsorType) || preg_match("/KL2/", $sponsorType)) {
					$isk12kl2 = TRUE;
				} else {
					$isk12kl2 = FALSE;
				}
			} else {
				$grant->setNumber("K12/KL2 - Rec. {$row['record_id']}");
				$include = FALSE;
				$isk12kl2 = TRUE;
			}

            $endDate = "";
			if ($isk12kl2) {
				$endDate = self::addYearsToDate($internalKDate, $k12kl2AwardLength);
			} else {
				$endDate = self::addYearsToDate($internalKDate, $internalKAwardLength);
			}
            $grant->setVariable("end", $endDate);
            $grant->setVariable("project_end", $endDate);
			if ($include) {
				$grant->setVariable('pi_flag', "Y");
                $grant->setVariable("role", self::$defaultRole);
				$grant->putInBins();
				$this->grants[] = $grant;
			}
		}
	
		$noninstDate = "";
		if (!preg_match("/none/", $row['newman_new_first_individual_k_award'])) {
			$noninstDate = $row['newman_new_first_individual_k_award'];
		}
		if ($noninstDate) {
		    $url = APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=new_2017";
			$grant = new Grant($this->lexicalTranslator);
			$grant->setVariable('person_name', $row['identifier_first_name']." ".$row['identifier_last_name']);
            $grant->setVariable('start', $noninstDate);
            $grant->setVariable('project_start', $noninstDate);
            $endDate = self::addYearsToDate($noninstDate, $externalKAwardLength);
            $grant->setVariable('end', $endDate);
            $grant->setVariable('project_end', $endDate);
			$grant->setVariable('source', "new2017");
			$grant->setVariable('url', $url);
			$grant->setVariable('link', Links::makeLink($url, "See Grant"));
			$grant->setVariable('budget', 0);
			$grant->setVariable('direct_budget', 0);
			$grant->setVariable('pi_flag', "Y");
			# for this, the type = the award no
			$awardno = $row['newman_new_current_nih_funding'];
			if (!$awardno) {
				$grant->setNumber("Unknown individual - Rec. {$row['record_id']}");
			} else {
				$grant->setNumber($awardno);
			}
			$grant->putInBins();
			$this->grants[] = $grant;
		}
	}

	# finds the R01 out of a compound grant list
	private static function findR01($sn) {
		if (preg_match("/\d[Rr]01\S+/", $sn, $matches)) {
			return $matches[0];
		}
		if (preg_match("/[Rr]01\S+/", $sn, $matches)) {
			return $matches[0];
		}
		return $sn;
	}
}
class CoeusSubmissionGrantFactory extends GrantFactory {
    public function getAwardFields() {
        return ['coeussubmission_sponsor_proposal_number'];
    }

    public function getPIFields() {
        return [];
    }

    public function processRow($row, $otherRows, $token = "") {
        list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
        $url = APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=coeus_submission&instance={$row['redcap_repeat_instance']}";
        $grant = new Grant($this->lexicalTranslator);
        $awardNo = self::cleanAwardNo($row['coeussubmission_sponsor_proposal_number']);
        $grant->setVariable('original_award_number', $row['coeussubmission_sponsor_proposal_number']);
        $grant->setNumber($awardNo);
        $grant->setVariable('person_name', $row['coeussubmission_person_name']);
        $grant->setVariable('project_start', $row['coeussubmission_project_start_date']);
        $grant->setVariable('project_end', $row['coeussubmission_project_end_date']);
        $grant->setVariable('start', $row['coeussubmission_budget_start_date']);
        $grant->setVariable('end', $row['coeussubmission_budget_end_date']);

        $status = $row['coeussubmission_proposal_status'];
        if (preg_match("/Pending/i", $status)) {
            $status = "Pending";
        }
        $grant->setVariable('status', $status);
        $proposalType = in_array($row['coeussubmission_proposal_type'], ["Resubmission", "Revision"]) ? "Resubmission" : "New";
        $grant->setVariable('proposal_type', $proposalType);
        $grant->setVariable("submission_date", $row['coeussubmission_proposal_create_date']);
        $grant->setVariable("submission_id", $row['coeussubmission_ip_number']);

        $grant->setVariable('sponsor', $row['coeussubmission_direct_sponsor_name']);
        $grant->setVariable('sponsor_type', $row['coeussubmission_direct_sponsor_type']);
        $grant->setVariable('prime_sponsor_type', $row['coeussubmission_prime_sponsor_type']);
        $grant->setVariable('prime_sponsor_name', $row['coeussubmission_prime_sponsor_name']);
        $grant->setVariable('direct_sponsor_type', $row['coeussubmission_direct_sponsor_type']);
        $grant->setVariable('direct_sponsor_name', $row['coeussubmission_direct_sponsor_name']);

        $directBudget = (int) $row['coeussubmission_direct_cost_budget_period'];
        $indirectBudget = (int) $row['coeussubmission_indirect_cost_budget_period'];
        $totalBudget = $directBudget + $indirectBudget;
        $grant->setVariable('title', $row['coeussubmission_title']);
        $grant->setVariable('budget', $totalBudget);
        $grant->setVariable('direct_budget', $directBudget);

        $grant->setVariable('source', "coeus");
        $grant->setVariable('url', $url);
        $grant->setVariable('link', Links::makeLink($url, "See Grant"));
        $grant->setVariable('percent_effort', $row['coeussubmission_percent_effort']);
        $grant->setVariable('last_update', $row['coeussubmission_last_update']);
        $grant->setVariable('pi_flag', $row['coeussubmission_pi_flag']);
        $grant->setVariable('flagged', $row['coeussubmission_flagged'] ?? "");

        $grant->putInBins();
        $this->grants[] = $grant;
    }
}

class CoeusGrantFactory extends GrantFactory {
    public function getAwardFields() {
        return ['coeus_award_no', 'coeus_sponsor_award_number'];
    }

    public function getPIFields() {
        return [];
    }

    public static function cleanAwardNo($awardNo) {
		$awardNo = preg_replace("/^\d\d\d\d\d\d-\d\d\d\s-\s\d\s/", "", $awardNo);
		$awardNo = preg_replace("/^[A-Z][A-Z]\d\d\d\d\d\d\d\d\s-\s\d\s/", "", $awardNo);
		$awardNo = preg_replace("/^[A-Z]\d\d\d\d\d\s-\s\d\s/", "", $awardNo);
		return parent::cleanAwardNo($awardNo);
	}

	public function processRow($row, $otherRows, $token = "") {
        list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
        $url = APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=coeus&instance={$row['redcap_repeat_instance']}";
		$grant = new Grant($this->lexicalTranslator);
		$awardNo = self::cleanAwardNo($row['coeus_sponsor_award_number']);
		$grant->setVariable('original_award_number', $row['coeus_sponsor_award_number']);
        $grant->setVariable("submission_date", $row['coeus_award_create_date']);

		$isSubproject = preg_match("/VUMC\d/", $awardNo) ? TRUE : FALSE;
		if (isset($row['coeus_person_name'])) {
			$grant->setVariable('person_name', $row['coeus_person_name']);
		} else if (isset($row['coeus_principal_investigator'])) {
			$grant->setVariable('person_name', $row['coeus_principal_investigator']);
			$grant->setVariable('vunet', $row['coeus_pi_vunetid']);
		}
		$grant->setVariable('project_start', $row['coeus_project_start_date']);
		$grant->setVariable('project_end', $row['coeus_project_end_date']);
		$grant->setVariable('award_date', $row['coeus_award_create_date']);
		$grant->setVariable('start', $row['coeus_budget_start_date']);
		$grant->setVariable('end', $row['coeus_budget_end_date']);
		$grant->setVariable('finance_type', Grants::getFinanceType($awardNo));
        $grant->setVariable('subproject', $isSubproject);
		if (preg_match("/[Kk]12/", $awardNo) && !in_array($row['coeus_pi_flag'], ["N", "0"])) {
			$grant->setVariable('budget', '0');
			$grant->setVariable('direct_budget', '0');
            $grant->setVariable("role", "");
		} else {
            # TODO takes about 1 second per record to complete
		    // if ($role = $this->extractFromOtherSources($otherRows, ["coeus"], "role", $awardNo)) {
                // $grant->setVariable("role", $role);
            // }
            if ($row['coeus_multi_pi_flag'] === "0") {
                $grant->setVariable("role", "PI");
            } else if ($row['coeus_multi_pi_flag'] === "1") {
                $grant->setVariable("role", "Co-PI");
            } else {
                $grant->setVariable("role", self::$defaultRole);
            }
			$grant->setVariable('budget', $row['coeus_total_cost_budget_period']);
            $grant->setVariable('direct_budget', $row['coeus_direct_cost_budget_period']);
		}
		$grant->setVariable('title', $row['coeus_title']);
		$grant->setVariable('sponsor', $row['coeus_direct_sponsor_name']);
		$grant->setVariable('sponsor_type', $row['coeus_direct_sponsor_type']);

		# used in budgetary calculations
		$grant->setVariable('prime_sponsor_type', $row['coeus_prime_sponsor_type']);
		$grant->setVariable('prime_sponsor_name', $row['coeus_prime_sponsor_name']);
		$grant->setVariable('direct_sponsor_type', $row['coeus_direct_sponsor_type']);
		$grant->setVariable('direct_sponsor_name', $row['coeus_direct_sponsor_name']);

		$grant->setNumber($awardNo);
		$grant->setVariable("original_award_number", $row['coeus_sponsor_award_number']);
		$grant->setVariable('source', "coeus");
        $grant->setVariable('url', $url);
		$grant->setVariable('link', Links::makeLink($url, "See Grant"));
		$grant->setVariable('percent_effort', $row['coeus_percent_effort']);
		if ($row['coeus_nih_mechanism']) {
			$grant->setVariable('nih_mechanism', $row['coeus_nih_mechanism']);
		} else {
			$grant->setVariable('nih_mechanism', Grant::getActivityCode($awardNo));
		}
        $grant->setVariable('last_update', $row['coeus_last_update']);
        $grant->setVariable('flagged', $row['coeus_flagged'] ?? "");
		$grant->setVariable('pi_flag', $row['coeus_pi_flag']);

		$grant->putInBins();
		$this->grants[] = $grant;
	}
}

class VERAGrantFactory extends  GrantFactory {
    public function getAwardFields() {
        return ['vera_direct_sponsor_award_id', 'vera_award_id'];
    }

    public function getPIFields() {
        return [];
    }

    public function processRow($row, $otherRows, $token = "")
    {
        list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
        $url = APP_PATH_WEBROOT . "DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=vera&instance={$row['redcap_repeat_instance']}";
        $awardNo = self::cleanAwardNo($row['vera_direct_sponsor_award_id']);
        if ($awardNo == "") {
            $awardNo = Grant::$noNameAssigned;
        }

        $role = "";
        if ($row['vera_personnel_role'] == "Co-Investigator") {
            return;
        } else if ($row['vera_personnel_role'] == "PD/PI") {
            $role = "PI";
        } else if ($row['vera_personnel_role'] == "Co-PD/PI") {
            $role = "Co-PI";
        }

        $grant = new Grant($this->lexicalTranslator);
        $grant->setVariable("person_name", $row['vera_pi_full_name']);
        $grant->setVariable("role", $role);
        # Their PI-flag field excludes Co-PIs
        $grant->setVariable('pi_flag', in_array($role, ["PI", "Co-PI"]) ? "Y" : "N");

        $grant->setVariable("project_start", $row['vera_project_start_date']);
        $grant->setVariable("project_end", $row['vera_project_end_date']);
        $grant->setVariable("start", $row['vera_budget_allocation_startdate']);
        $grant->setVariable("end", $row['vera_budget_allocation_enddate']);
        $grant->setVariable("title", $row['vera_title']);
        $grant->setVariable("budget", $row['vera_budget_allocation_total']);
        $grant->setVariable("total_budget", $row['vera_budget_allocation_total']);
        $grant->setVariable("direct_budget", $row['vera_budget_allocation_direct_total']);

        $grant->setVariable('sponsor', $row['vera_direct_sponsor_name']);
        $grant->setVariable('sponsor_type', $row['vera_direct_sponsor_type']);

        # blank if same
        $primeSponsorType = $row['vera_prime_sponsor_type'] ?: $row['vera_direct_sponsor_type'];
        $primeSponsorName = $row['vera_prime_sponsor_name'] ?: $row['vera_direct_sponsor_name'];
        $grant->setVariable('prime_sponsor_type', $primeSponsorType);
        $grant->setVariable('prime_sponsor_name', $primeSponsorName);
        $grant->setVariable('direct_sponsor_type', $row['vera_direct_sponsor_type']);
        $grant->setVariable('direct_sponsor_name', $row['vera_direct_sponsor_name']);

        $grant->setNumber($awardNo);
        $grant->setVariable("original_award_number", $row['vera_direct_sponsor_award_id']);
        $grant->setVariable('source', "vera");
        $grant->setVariable('url', $url);
        $grant->setVariable('link', Links::makeLink($url, "See Grant"));
        $grant->setVariable("submission_date", $row['vera_datecreated']);

        if ($row['vera_reporting_award_type_mechanism']) {
            $grant->setVariable('nih_mechanism', $row['vera_reporting_award_type_mechanism']);
        } else {
            $grant->setVariable('nih_mechanism', Grant::getActivityCode($awardNo));
        }
        $grant->setVariable('last_update', $row['vera_last_update']);
        $grant->setVariable('flagged', $row['vera_flagged'] ?? "");

        $grant->putInBins();
        $this->grants[] = $grant;
    }
}

class VERASubmissionGrantFactory extends  GrantFactory {
    public function getAwardFields() {
        return [];
    }

    public function getPIFields() {
        return [];
    }

    public function processRow($row, $otherRows, $token = "")
    {
        list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
        $url = APP_PATH_WEBROOT . "DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=vera_submission&instance={$row['redcap_repeat_instance']}";
        $awardNo = Grant::$noNameAssigned;

        $role = "";
        if ($row['verasubmission_project_role'] == "Other Professional") {
            return;
        } else if ($row['verasubmission_project_role'] == "PD/PI") {
            $role = "PI";
        } else if ($row['verasubmission_project_role'] == "Co-PD/PI") {
            $role = "Co-PI";
        }
        $directBudget = (int) $row['verasubmission_budget_period_direct'];
        $indirectBudget = (int) $row['verasubmission_budget_period_indirect'];
        $totalBudget = $directBudget + $indirectBudget;

        $grant = new Grant($this->lexicalTranslator);
        $grant->setVariable("person_name", $row['verasubmission_person_name']);
        $grant->setVariable("role", $role);
        # Their PI-flag field excludes Co-PIs
        $grant->setVariable('pi_flag', in_array($role, ["PI", "Co-PI"]) ? "Y" : "N");

        $grant->setVariable("project_start", $row['verasubmission_project_start_date']);
        $grant->setVariable("project_end", $row['verasubmission_project_end_date']);
        $grant->setVariable("start", $row['verasubmission_project_end_date']);
        $grant->setVariable("end", $row['verasubmission_project_end_date']);
        $grant->setVariable("title", $row['verasubmission_title']);
        $grant->setVariable("budget", $totalBudget);
        $grant->setVariable("total_budget", $totalBudget);
        $grant->setVariable("direct_budget", $row['verasubmission_budget_period_direct']);

        $grant->setVariable('status', $row['verasubmission_status']);
        $proposalType = in_array($row['verasubmission_proposal_type'], ["Resubmission", "Revision"]) ? "Resubmission" : "New";
        $grant->setVariable("proposal_type", $proposalType);
        $grant->setVariable("submission_date", $row['verasubmission_date_created']);
        $grant->setVariable("submission_id", $row['verasubmission_fp_id']);

        $grant->setVariable('sponsor', $row['verasubmission_direct_sponsor_name']);
        $grant->setVariable('sponsor_type', $row['verasubmission_direct_sponsor_type']);

        # blank if same
        $primeSponsorType = $row['verasubmission_prime_sponsor_type'] ?: $row['verasubmission_direct_sponsor_type'];
        $primeSponsorName = $row['verasubmission_prime_sponsor_name'] ?: $row['verasubmission_direct_sponsor_name'];
        $grant->setVariable('prime_sponsor_type', $primeSponsorType);
        $grant->setVariable('prime_sponsor_name', $primeSponsorName);
        $grant->setVariable('direct_sponsor_type', $row['verasubmission_direct_sponsor_type']);
        $grant->setVariable('direct_sponsor_name', $row['verasubmission_direct_sponsor_name']);

        $grant->setNumber($awardNo);
        $grant->setVariable('source', "vera");
        $grant->setVariable('url', $url);
        $grant->setVariable('link', Links::makeLink($url, "See Grant"));

        $grant->setVariable('last_update', $row['verasubmission_last_update']);

        $grant->putInBins();
        $this->grants[] = $grant;
    }
}

class Coeus2GrantFactory extends CoeusGrantFactory {
    public function __construct($name, $lexicalTranslator, $metadata, $type = "Grant", $token = "", $server = "") {
        parent::__construct($name, $lexicalTranslator, $metadata, $token, $server);
        $this->type = $type;
    }

    public function getAwardFields() {
        return ['coeus2_agency_grant_number', 'coeus2_award_status'];
    }

    public function getPIFields() {
        return ['coeus2_collaborators'];
    }

    public function processRow($row, $otherRows, $token = "") {
        $addGrant = FALSE;
        if (in_array($this->type, ["Grant", "Grants"])) {
            $addGrant = ($row['coeus2_award_status'] == "Awarded");
        } else if (in_array($this->type, ["Submissions", "Submission"])) {
            $addGrant = ($row['coeus2_award_status'] != "Awarded");
        } else {
            throw new \Exception("Improper type ".$this->type);
        }
        if ($addGrant) {
            list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
            $url = APP_PATH_WEBROOT . "DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=coeus2&instance={$row['redcap_repeat_instance']}";
            $awardNo = self::cleanAwardNo($row['coeus2_agency_grant_number']);
            $choices = REDCapManagement::getChoices($this->metadata);
            $grant = new Grant($this->lexicalTranslator);
            $roleText = $choices['coeus2_role'][$row['coeus2_role']];
            if ($awardNo == '000') {
                $awardNo = Grant::$noNameAssigned;
            }

            $grant->setNumber($awardNo);
            $grant->setVariable('source', "coeus2");
            $grant->setVariable('original_award_number', $row['coeus2_agency_grant_number']);
            $grant->setVariable('sponsor_type', $row['coeus2_agency_name']);
            $grant->setVariable('person_name', $this->name);
            $grant->setVariable('start', REDCapManagement::datetime2Date($row['coeus2_current_period_start']));
            $grant->setVariable('end', REDCapManagement::datetime2Date($row['coeus2_current_period_end']));
            $grant->setVariable('title', $row['coeus2_title']);
            $grant->setVariable('budget', $row['coeus2_current_period_total_funding']);
            $grant->setVariable('direct_budget', $row['coeus2_current_period_direct_funding']);
            $grant->setVariable('last_update', $row['coeus2_last_update']);
            $grant->setVariable('flagged', $row['coeus2_flagged'] ?? "");
            $grant->setVariable('pi_flag', ($roleText == "Principal Investigator") ? "Y" : "N");
            $grant->setVariable('finance_type', Grants::getFinanceType($awardNo));
            $grant->setVariable('nih_mechanism', Grant::getActivityCode($awardNo));
            $grant->setVariable("status", $row['coeus2_award_status']);
            $grant->setVariable("submission_date", $row['coeus2_in_progress']);
            $grant->setVariable("submission_id",  $row['coeus2_id']);
            $grant->setVariable('url', $url);
            $grant->setVariable('link', Links::makeLink($url, "See Grant"));
            if ($roleText == "Principal Investigator") {
                $grant->setVariable("role", "PI");
            } else if ($roleText == "Investigator") {
                $grant->setVariable("role", "Co-I");
            } else {
                $grant->setVariable("role", $roleText);
            }

            $grant->putInBins();
            // Application::log("Coeus2GrantFactory adding ".json_encode($grant->toArray()));
            $this->grants[] = $grant;
        }
    }

    protected $type = "Grant";
}

class RePORTERGrantFactory extends GrantFactory {
    public function getAwardFields() {
        return ['reporter_projectnumber'];
    }

    public function getPIFields() {
        return ['reporter_contactpi', 'reporter_otherpis'];
    }

    public function processRow($row, $otherRows, $token = "") {
        list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
        $url = APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=reporter&instance={$row['redcap_repeat_instance']}";
		$awardNo = self::cleanAwardNo($row['reporter_projectnumber']);
		$grant = new Grant($this->lexicalTranslator);
		$grant->setVariable('original_award_number', $row['reporter_projectnumber']);
		$grant->setVariable('person_name', $row['reporter_contactpi']);
		$grant->setVariable('start', self::getReporterDate($row['reporter_budgetstartdate']));
		$grant->setVariable('end', self::getReporterDate($row['reporter_budgetenddate']));
		$grant->setVariable('project_start', self::getReporterDate($row['reporter_projectstartdate']));
		$grant->setVariable('project_end', self::getReporterDate($row['reporter_projectenddate']));
		$grant->setVariable('title', $row['reporter_title']);
		$grant->setVariable('budget', $row['reporter_totalcostamount']);
		$grant->setVariable('direct_budget', Grants::directCostsFromTotal($row['reporter_totalcostamount'], $awardNo, self::getReporterDate($row['reporter_budgetstartdate'])));
		// $grant->setVariable('fAndA', Grants::getFAndA($awardNo, self::getReporterDate($row['reporter_budgetstartdate'])));
		$grant->setVariable('finance_type', Grants::getFinanceType($awardNo));
		$grant->setVariable('sponsor', $row['reporter_agency']);
		$grant->setVariable('sponsor_type', $row['reporter_agency']);
        $grant->setVariable('last_update', $row['reporter_last_update']);
        $grant->setVariable('flagged', $row['reporter_flagged'] ?? "");
		$grant->setNumber($awardNo);
		$grant->setVariable('nih_mechanism', Grant::getActivityCode($awardNo));
		$grant->setVariable('source', "reporter");
		$grant->setVariable('url', $url);
		$grant->setVariable('link', Links::makeLink($url, "See Grant"));
		$grant->setVariable('pi_flag', "Y");

        if ($row["reporter_otherpis"]) {
            $grant->setVariable("role", "Co-PI");
        } else  {
            $grant->setVariable("role", "PI");
        }


		$grant->putInBins();
		$this->grants[] = $grant;
	}

	# gets the date from a RePORTER formatting (YYYY-MM-DDThh:mm:ss);
	# returns YYYY-MM-DD
	public static function getReporterDate($dt) {
		if (!$dt) {
			return "";
		}
		if (preg_match("/T/", $dt)) {
            $nodes = preg_split("/T/", $dt);
            return $nodes[0];
        }
		return $dt;
	}
}

class NIHRePORTERGrantFactory extends  GrantFactory {
    public function getAwardFields() {
        return ['nih_project_num'];
    }

    public function getPIFields()
    {
        return ["nih_principal_investigators"];
    }

    public function processRow($row, $otherRows, $token = "")
    {
        list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
        $url = APP_PATH_WEBROOT . "DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=nih_reporter&instance={$row['redcap_repeat_instance']}";
        $awardNo = self::cleanAwardNo($row['nih_project_num']);
        $isSubproject = ($row['nih_subproject_id'] !== "");
        $grant = new Grant($this->lexicalTranslator);
        $grant->setVariable('person_name', $row['nih_principal_investigators'] ?? $row['nih_contact_pi_name']);
        $grant->setVariable('project_start', $row['nih_project_start_date']);
        $grant->setVariable('project_end', $row['nih_project_end_date']);
        list ($budgetStartDate, $budgetEndDate) = self::calculateBudgetDates($row['nih_project_start_date'], $row['nih_project_end_date'], $row['nih_award_notice_date']);
        if ($budgetStartDate && $budgetEndDate) {
            $grant->setVariable('start', $budgetStartDate);
            $grant->setVariable('end', $budgetEndDate);
        }
        $grant->setVariable('title', $row['nih_project_title']);
        $grant->setVariable('budget', $row['nih_award_amount']);
        $grant->setVariable('total_budget', $row['nih_award_amount']);
        $grant->setVariable('sponsor', $row['nih_agency_ic_admin']);
        $grant->setVariable('sponsor_type', $row['nih_agency_ic_admin']);
        $grant->setVariable('original_award_number', $row['nih_project_num']);
        $grant->setVariable('finance_type', Grants::getFinanceType($awardNo));
        $grant->setVariable('subproject', $isSubproject);
        $grant->setNumber($awardNo);
        $grant->setVariable('source', "nih_reporter");
        $grant->setVariable('nih_mechanism', Grant::getActivityCode($awardNo));
        $grant->setVariable('url', $url);
        $grant->setVariable('link', Links::makeLink($url, "See Grant"));
        $grant->setVariable('pi_flag', "Y");
        $grant->setVariable('last_update', $row['nih_last_update']);
        $grant->setVariable('flagged', $row['nih_flagged'] ?? "");

        $numNodes = self::numNodes("/\s*;\s*/", $row['nih_principal_investigators']);
        if ($numNodes === 1) {
            $grant->setVariable("role", "PI");
        } else if ($numNodes > 1) {
            $grant->setVariable("role", "Co-PI");
        } else {    // 0
            $grant->setVariable("role", "");
        }

        $grant->putInBins();
        $this->grants[] = $grant;
    }

    private static function calculateBudgetDates($projectStartDate, $projectEndDate, $awardNoticeDate) {
        $budgetStartTs = FALSE;
        $budgetEndTs = FALSE;
        if ($projectStartDate && $projectEndDate && $awardNoticeDate) {
            $projectStartTs = strtotime($projectStartDate);
            $projectEndTs = strtotime($projectEndDate);
            $awardNoticeTs = strtotime($awardNoticeDate);

            $startBudgetYear = date("Y", $awardNoticeTs);
            if (
                in_array(date("m", $awardNoticeTs), ["10", "11", "12"])
                && in_array(date("m", $projectStartTs), ["01", "02", "03"])
            ) {
                $startBudgetYear++;
            }
            $isStartLeapYear = ($startBudgetYear % 4 === 0);
            $startBudgetRemainingDate = date("-m-d", $projectStartTs);
            if ($startBudgetRemainingDate == "-02-29" && !$isStartLeapYear) {
                $startBudgetRemainingDate = "-02-28";
            }
            $budgetStartTs = strtotime($startBudgetYear.$startBudgetRemainingDate);

            $endBudgetYear = ((int) $startBudgetYear) + 1;
            $isEndLeapYear = ($endBudgetYear % 4 === 0);
            $endBudgetRemainingDate = date("-m-d", $projectStartTs);
            if ($endBudgetRemainingDate == "-02-29" && !$isEndLeapYear) {
                $endBudgetRemainingDate = "-02-28";
            }
            $oneDay = 24 * 3600;
            $budgetEndTs = strtotime($endBudgetYear.$endBudgetRemainingDate) - $oneDay;
            if ($budgetEndTs > $projectEndTs) {
                $budgetEndTs = $projectEndTs;
            }
        }

        $format = "Y-m-d";
        $budgetStartDate = $budgetStartTs ? date($format, $budgetStartTs) : "";
        $budgetEndDate = $budgetEndTs ? date($format, $budgetEndTs) : "";
        return [$budgetStartDate, $budgetEndDate];
    }
}

class ExPORTERGrantFactory extends GrantFactory {
    public function getAwardFields() {
        return ['exporter_full_project_num'];
    }

    public function getPIFields()
    {
        return ["exporter_pi_names"];
    }

    public function processRow($row, $otherRows, $token = "")
    {
        $totalCosts = $row['exporter_total_cost'];
        if (!$totalCosts) {
            $totalCosts = $row['exporter_total_cost_sub_project'];
        }
        $isSubproject = ($row['exporter_subproject_id'] != "");
        list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
        $url = APP_PATH_WEBROOT . "DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=exporter&instance={$row['redcap_repeat_instance']}";
        $awardNo = self::cleanAwardNo($row['exporter_full_project_num']);
        $grant = new Grant($this->lexicalTranslator);
        $grant->setVariable('person_name', $row['exporter_pi_names']);
        $grant->setVariable('start', RePORTERGrantFactory::getReporterDate($row['exporter_budget_start']));
        $grant->setVariable('end', RePORTERGrantFactory::getReporterDate($row['exporter_budget_end']));
        $grant->setVariable('project_start', RePORTERGrantFactory::getReporterDate($row['exporter_project_start']));
        $grant->setVariable('project_end', RePORTERGrantFactory::getReporterDate($row['exporter_project_end']));
        $grant->setVariable('title', $row['exporter_project_title']);
        $grant->setVariable('budget', $totalCosts);
        $grant->setVariable('direct_budget', $row['exporter_direct_cost_amt']);
        $grant->setVariable('sponsor', $row['exporter_ic_name']);
        $grant->setVariable('sponsor_type', $row['exporter_ic_name']);
        $grant->setVariable('original_award_number', $row['exporter_full_project_num']);
        $grant->setVariable('finance_type', Grants::getFinanceType($awardNo));
        $grant->setNumber($awardNo);
        $grant->setVariable('source', "exporter");
        $grant->setVariable('nih_mechanism', Grant::getActivityCode($awardNo));
        $grant->setVariable('url', $url);
        $grant->setVariable('link', Links::makeLink($url, "See Grant"));
        $grant->setVariable('pi_flag', "Y");
        $grant->setVariable('subproject', $isSubproject);
        $grant->setVariable('last_update', $row['exporter_last_update']);
        $grant->setVariable('flagged', $row['exporter_flagged'] ?? "");

        $numNodes = self::numNodes("/\s*;\s*/", $row['exporter_pi_names']);
        if ($numNodes == 1) {
            $grant->setVariable("role", "PI");
        } else if ($numNodes > 1) {
            $grant->setVariable("role", "Co-PI");
        } else {    // 0
            $grant->setVariable("role", "");
        }

        $grant->putInBins();
		$this->grants[] = $grant;
	}
}

class CustomGrantFactory extends GrantFactory {
    public function getAwardFields() {
        return ['custom_number'];
    }

    public function getPIFields() {
        return [];
    }

    public function __construct($name, $lexicalTranslator, $metadata, $type = "Grant", $token = "", $server = "") {
        parent::__construct($name, $lexicalTranslator, $metadata, $token, $server);
        $this->type = $type;
    }

    public function processRow($row, $otherRows, $token = "") {
        list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
        $url = APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=custom_grant&instance={$row['redcap_repeat_instance']}";
		$awardNo = self::cleanAwardNo($row['custom_number']);
		$directCosts = $row['custom_costs'];
		if (isset($row['custom_costs_total']) && $row['custom_costs_total']) {
		    $totalCosts = $row['custom_costs_total'];
		    if (!$directCosts) {
		        $directCosts = Grants::directCostsFromTotal($totalCosts, $awardNo, $row['custom_start']);
            }
        } else {
		    if (REDCapManagement::hasValue($directCosts)) {
                $totalCosts = Grants::totalCostsFromDirect($directCosts, $awardNo, $row['custom_start']);
            } else {
		        $totalCosts = '';
            }
        }

		$grant = new Grant($this->lexicalTranslator);
        $grant->setVariable('start', $row['custom_start']);
        $grant->setVariable('end', $row['custom_end']);
        $grant->setVariable('project_start', $row['custom_start']);
        $grant->setVariable('project_end', $row['custom_end']);
		$grant->setVariable('title', $row['custom_title']);
		$grant->setVariable('budget', $totalCosts);
		// $grant->setVariable('fAndA', Grants::getFAndA($awardNo, $row['custom_start']));
		$grant->setVariable('finance_type', Grants::getFinanceType($awardNo));
		$grant->setVariable('direct_budget', $directCosts);
		$grant->setVariable('sponsor', $row['custom_org']);
        $grant->setVariable("submission_date", $row['custom_submission_date'] ?? "");
		$grant->setVariable('original_award_number', $row['custom_number']);
		$grant->setNumber($awardNo);
		$grant->setVariable('source', "custom");
		if (in_array($row['custom_role'], [1, 2, ''])) {
			$grant->setVariable('pi_flag', 'Y');
            $type = $row['custom_type'];
            $reverseAwardTypes = Grant::getReverseAwardTypes();
            if ($type && isset($reverseAwardTypes[$type]) && $reverseAwardTypes[$type]) {
                $grant->setVariable("type", $reverseAwardTypes[$type]);
            } else {
                $grant->putInBins();
            }
		} else {
			$grant->setVariable('pi_flag', 'N');
            $grant->putInBins();
		}
		if (empty($this->choices)) {
            $field = "custom_role";
            $fieldChoices = DataDictionaryManagement::getChoicesForField($pid, $field);
            $grant->setVariable("role", $fieldChoices[$row[$field]]);
        } else if ($row['custom_role'] !== "") {
            $grant->setVariable("role", $this->choices["custom_role"][$row["custom_role"]]);
        }
		$grant->setVariable('nih_mechanism', Grant::getActivityCode($awardNo));
		$grant->setVariable('url', $url);
		$grant->setVariable('link', Links::makeLink($url, "See Grant"));
        $grant->setVariable('last_update', $row['custom_last_update']);
        $grant->setVariable('flagged', $row['custom_flagged'] ?? "");

        if (in_array($this->type, ["Grant", "Grants"]) && ($row['custom_is_submission'] != "1")) {
            $this->grants[] = $grant;
        } else if (in_array($this->type, ["Submission", "Submissions"]) && ($row['custom_is_submission'] == "1")) {
            $statusIdx = $row['custom_submission_status'];
            $status = $this->choices['custom_submission_status'][$statusIdx] ?? "";
            $proposalType = $row['custom_resubmission_date'] ? "Resubmission" : "New";

            $grant->setVariable("status", $status);
            $grant->setVariable("proposal_type", $proposalType);
            $grant->setVariable("submission_id", $awardNo);

            $this->grants[] = $grant;
        }
	}

    protected $type = "";
}

class PriorGrantFactory extends GrantFactory {
    public function getAwardFields() {
        $fields = [];
        for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
            $fields[] = 'summary_award_sponsorno_'.$i;
        }
        return $fields;
    }

    public function getPIFields() {
        return [];
    }

    public function processRow($row, $otherRows, $token = "") {
        list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
		for ($i = 1; $i <= Grants::$MAX_GRANTS; $i++) {
			if (isset($row['summary_award_date_'.$i]) && $row['summary_award_date_'.$i]) {
				$grant = new Grant($this->lexicalTranslator);
				$url = APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=summary#summary_award_date_".$i;
                $grant->setVariable('start', $row['summary_award_date_'.$i]);
                $grant->setVariable('end', $row['summary_award_end_date_'.$i]);
                $grant->setVariable('project_start', $row['summary_award_date_'.$i]);
                $grant->setVariable('project_end', $row['summary_award_end_date_'.$i]);
				$grant->setVariable('last_update', $row['summary_award_last_update_'.$i]);
				$grant->setVariable('title', $row['summary_award_title_'.$i]);
				$grant->setVariable('budget', $row['summary_award_total_budget_'.$i]);
				$grant->setVariable('direct_budget', $row['summary_award_direct_budget_'.$i]);
				$grant->setNumber($row['summary_award_sponsorno_'.$i]);
				$grant->setVariable('source', $row['summary_award_source_'.$i]);
				$grant->setVariable('age', $row['summary_award_age_'.$i]);
                $grant->setVariable('pi_flag', 'Y');
                $grant->setVariable('role', $row['summary_award_role_'.$i]);
				$grant->setVariable('nih_mechanism', $row['summary_award_nih_mechanism_'.$i]);
				$grant->setVariable('percent_effort', $row['summary_award_percent_effort_'.$i]);
				$grant->setVariable('url', $url);
				$grant->setVariable('link', Links::makeLink($url, "See Grant"));
				$grant->setVariable('last_update', $row['summary_last_calculated']);
		
				$type = $row['summary_award_type_'.$i];
				$reverseAwardTypes = Grant::getReverseAwardTypes();
				if ($reverseAwardTypes[$type]) {
					$grant->setVariable("type", $reverseAwardTypes[$type]);
				} else {
					$grant->putInBins();
				}
				$this->grants[] = $grant;
			}
		}
	}
}

class NSFGrantFactory extends GrantFactory {
    public function getAwardFields() {
        return ['nsf_id'];
    }

    public function getPIFields()
    {
        return ["nsf_copdpi", "nsf_pdpiname"];
    }

    public function processRow($row, $otherRows, $token = "")
    {
        list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
        $url = APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=nsf&instance={$row['redcap_repeat_instance']}";
        $awardNo = $row['nsf_id'];
        $dollars = $row['nsf_estimatedtotalamt'];
        $title = $row['nsf_title'];

        $grant = new Grant($this->lexicalTranslator);
        $grant->setVariable('start', $row['nsf_startdate']);
        $grant->setVariable('project_start', $row['nsf_startdate']);
        $grant->setVariable('end', $row['nsf_expdate']);
        $grant->setVariable('project_end', $row['nsf_expdate']);
        $grant->setVariable('title', $title);
        $grant->setVariable('budget', $dollars);
        $grant->setVariable('direct_budget', $dollars);
        $grant->setVariable('sponsor', $row['nsf_agency']);
        $grant->setVariable('original_award_number', $awardNo);
        $grant->setNumber($awardNo);
        $grant->setVariable('source', "nsf");
        $grant->setVariable('pi_flag', 'Y');

        $grant->putInBins();

        list ($firstName, $lastName) = NameMatcher::splitName($this->name, 2);
        $role = "";
        if (NameMatcher::matchName($firstName, $lastName, $row['nsf_pifirstname'], $row['nsf_pilastname'])) {
            $role = "PI";
        } else {
            $coPIs = preg_split("/\s*[,;]\s*/", $row['nsf_copdpi']);
            foreach ($coPIs as $coPI) {
                $coPI = trim($coPI);
                list($coPIFirst, $coPILast) = NameMatcher::splitName($coPI, 2);
                if (NameMatcher::matchName($firstName, $lastName, $coPIFirst, $coPILast)) {
                    $role = "Co-PI";
                    break;
                }
            }
        }
        $grant->setVariable("role", $role);
        $grant->setVariable('nih_mechanism', $row['nsf_agency']);
        $grant->setVariable('url', $url);
        $grant->setVariable('link', Links::makeLink($url, "See Grant"));
        $grant->setVariable('last_update', $row['nsf_last_update']);
        $grant->setVariable('flagged', $row['nsf_flagged'] ?? "");

        $this->grants[] = $grant;
    }
}

class IESGrantFactory extends GrantFactory {
    public function getAwardFields() {
        return ['ies_awardnum'];
    }

    public function getPIFields() {
        return [];
    }

    public function processRow($row, $otherRows, $token = "")
    {
        list($pid, $event_id) = self::getProjectIdentifiers($token ?: $this->token);
        $url = APP_PATH_WEBROOT."DataEntry/index.php?pid=$pid&id={$row['record_id']}&event_id=$event_id&page=nsf&instance={$row['redcap_repeat_instance']}";
        $awardNo = $row['ies_awardnum'];
        $dollars = $row['ies_awardamt'];

        $grant = new Grant($this->lexicalTranslator);
        $grant->setVariable('start', $row['ies_start']);
        $grant->setVariable('project_start', $row['ies_start']);
        $grant->setVariable('end', $row['ies_end']);
        $grant->setVariable('project_end', $row['ies_end']);
        $grant->setVariable('title', $row['ies_title']);
        $grant->setVariable('budget', $dollars);
        $grant->setVariable('direct_budget', $dollars);
        $grant->setVariable('sponsor', $row['ies_centername']);
        $grant->setVariable('original_award_number', $awardNo);
        $grant->setNumber($awardNo);
        $grant->setVariable('source', "ies");
        $grant->setVariable('pi_flag', 'Y');

        $grant->putInBins();

        $grant->setVariable("role", "PI");    // Co-PIs are not currently listed
        $grant->setVariable('url', $url);
        $grant->setVariable('link', Links::makeLink($url, "See Grant"));
        $grant->setVariable('last_update', $row['ies_last_update']);
        $grant->setVariable('flagged', $row['ies_flagged'] ?? "");

        $this->grants[] = $grant;
    }
}