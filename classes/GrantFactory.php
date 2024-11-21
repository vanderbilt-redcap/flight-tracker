<?php

namespace Vanderbilt\CareerDevLibrary;


# This file compiles all of the grants from various data sources and compiles them into an ordered list of grants.
# It should remove duplicate grants as well.
# Unit-testable.

require_once(__DIR__ . '/ClassLoader.php');

abstract class GrantFactory {
    const ROOT = APP_PATH_WEBROOT;
    
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

    protected function getPIs($row) {
        $fields = $this->getPIFields();
        if (empty($fields)) {
            return [];
        }
        $pis = [];
        foreach ($fields as $field) {
            if ($row[$field]) {
                if (preg_match("/;/", $row[$field])) {
                    $pis = array_unique(array_merge($pis, preg_split("/\s*;\s*/", $row[$field])));
                } else if (preg_match("/,/", $row[$field])) {
                    list($myFirst, $myLast) = NameMatcher::splitName($this->getName(), 2);
                    list($fieldFirst, $fieldLast) = NameMatcher::splitName($row[$field], 2);
                    if (NameMatcher::matchName($myFirst, $myLast, $fieldFirst, $fieldLast)) {
                        list($myFirst, $myMiddle, $myLast) = NameMatcher::splitName($this->getName(), 3);
                        $formattedName = NameMatcher::formatName($myFirst, $myMiddle, $myLast);
                        $pis = array_unique(array_merge($pis, [$formattedName]));
                    } else {
                        $pis = array_unique(array_merge($pis, preg_split("/\s*,\s*/", $row[$field])));
                    }
                }
            }
        }
        return $pis;
    }

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

