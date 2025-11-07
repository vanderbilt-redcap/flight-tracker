<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class LDAP {
	public static function getLDAPByMultiple($types, $values)
	{
		return LdapLookup::lookupUserDetailsByKeys($values, $types, true, false);
	}

	public static function getLDAP($type, $value) {
		return self::getLDAPByMultiple(array($type), array($value));
	}

    public static function getREDCapRowsFromName($first, $last, $metadata, $recordId, $repeatingForms) {
	    $firstNames = NameMatcher::explodeFirstName($first);
	    $lastNames = NameMatcher::explodeLastName($last);
	    $instrument = "ldapds";
	    $rows = [];
	    foreach ($firstNames as $firstName) {
	        $firstName = strtolower($firstName);
	        foreach ($lastNames as $lastName) {
	            $lastName = strtolower($lastName);
                $key = self::getNameAssociations($firstName, $lastName);
                $maxInstance = REDCapManagement::getMaxInstance($rows, $instrument, $recordId);
                $rows = array_merge($rows, self::getREDCapRows(array_keys($key), array_values($key), $metadata, $recordId, $maxInstance + 1, $repeatingForms));
                $rows = REDCapManagement::deDupREDCapRows($rows, $instrument, $recordId);
            }
        }
        return $rows;
    }

    public static function getREDCapRowsFromUid($uid, $metadata, $recordId, $repeatingForms) {
        $key = ["uid" => $uid];
        return self::getREDCapRows(array_keys($key), array_values($key), $metadata, $recordId, 0, $repeatingForms);
    }

    public static function getREDCapRows($types, $values, $metadata, $recordId, $previousMaxInstance = 0, $repeatingForms = []) {
	    $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
	    $debug = FALSE;
	    $hasLDAP = FALSE;
	    $prefix = "ldapds_";
	    foreach ($metadataFields as $redcapField) {
	        if (preg_match("/^$prefix/", $redcapField)) {
	            $hasLDAP = TRUE;
	            break;
            }
        }
	    if (!$hasLDAP) {
	        return [];
        }

	    $info = self::getLDAPByMultiple($types, $values);
	    $ldapFields = self::getFields();
	    $rows = [];
	    $defaultRow = ["record_id" => $recordId, "ldapds_complete" => "2"];
	    foreach ($ldapFields as $ldapField) {
	        $redcapField = $prefix.$ldapField;
	        if (in_array($redcapField, $metadataFields)) {
                $values = self::findField($info, $ldapField);
                if (count($values) == 0) {
                    if ($debug) {
                        Application::log("Could not find $ldapField for Record $recordId in LDAP");
                    }
                } else if (in_array("ldapds", $repeatingForms)) {
                    # multiple
                    if ($debug) {
                        Application::log("Could have values for Record $recordId in LDAP: " . implode(", ", $values));
                    }
                    $i = 0;
                    foreach ($values as $value) {
                        if (!isset($rows[$i])) {
                            $rows[$i] = $defaultRow;
                            $rows[$i]["redcap_repeat_instrument"] = "ldapds";
                            $rows[$i]["redcap_repeat_instance"] = $previousMaxInstance + 1;
                            $previousMaxInstance++;
                        }
                        $rows[$i][$redcapField] = $value;
                        $i++;
                    }
                } else {
                    if (!isset($rows[0])) {
                        $rows[0] = $defaultRow;
                        $rows[0]["redcap_repeat_instrument"] = "ldapds";
                        $rows[0]["redcap_repeat_instance"] = $previousMaxInstance + 1;
                        $previousMaxInstance++;
                    }
                    $rows[0][$redcapField] = $values[0];
                }
            } else {
	            if ($debug) {
                    Application::log("Could not find $redcapField");
                }
            }
        }
	    if ($debug) {
	        Application::log("getREDCapRows Returning ".REDCapManagement::json_encode_with_spaces($rows));
        }
	    return $rows;
    }

    public static function getNameAssociations($first, $last) {
        return ["givenname" => $first, "sn" => $last];
    }

	public static function getVUNetsAndDepartments($first, $last) {
		$key = self::getNameAssociations($first, $last);
		$info = self::getLDAPByMultiple(array_keys($key), array_values($key));
		$vunets = self::findField($info, "cn");
		$departments = self::findField($info, "department");
		return array($vunets, $departments);
	}

	public static function getName($uid) {
        $info = self::getLDAP("cn", $uid);
        if ($info['count'] > 0) {
            return self::findField($info, "givenname", 0)." ".self::findField($info, "sn", 0);
        }
        return "";
    }

    public static function getNameFromEmail($email) {
        $info = self::getLDAP("mail", $email);
        if ($info['count'] > 0) {
            return self::findField($info, "givenname", 0)." ".self::findField($info, "sn", 0);
        }
        return "";
    }

    public static function getDepartmentAndRank($uid) {
        $info = self::getLDAP("cn", $uid);
        $department = "";
        $rank = "";
        if ($info['count'] > 0) {
            $departments = self::findField($info, "department");
            $ranks = self::findField($info, "title");
            foreach ($departments as $dept) {
                if ($dept) {
                    $department = $dept;
                    break;
                }
            }
            foreach ($ranks as $r) {
                if ($r) {
                    $rank = $r;
                    break;
                }
            }
        }
        return [$department, $rank];
    }

    public static function getVUNet($first, $last) {
        $key = self::getNameAssociations($first, $last);
        $info = self::getLDAPByMultiple(array_keys($key), array_values($key));
        if ($info['count'] > 0) {
            return self::findField($info, "cn", 0);
        }
        return "";
    }

    public static function getAllVUNets($first, $last) {
        $key = self::getNameAssociations($first, $last);
        $info = self::getLDAPByMultiple(array_keys($key), array_values($key));
        return self::findField($info, "cn");
    }

    # $info is line from getLDAP
	# returns array from $info with the field $field
	public static function findField($info, $field, $idx = "all") {
	    if (!isset($info['count'])) {
	        return [];
        }
		$separator = ";";
		$values = [];
		for ($i = 0; $i < $info['count']; $i++) {
			$line = $info[$i];
			$value = "";
			foreach ($line as $var => $results) {
				if ($var == $field) {
					if (isset($results['count'])) {
						$r = array();
						for ($j = 0; $j < $results['count']; $j++) {
							$r[] = $results[$j];
						}
						$value = implode($separator, $r);
					}
				}
			}
			$values[] = Sanitizer::sanitizeWithoutChangingQuotes($value);
		}
		if (isset($_GET['test'])) {
		    echo "findField values: ".json_encode($values)."<br>\n";
		    echo "findField idx: $idx<br>\n";
        }
		if ($idx === "all") {
            if (isset($_GET['test'])) {
                echo "findField return all $idx<br>\n";
            }
            return $values;
        } else if (isset($values[$idx])) {
            if (isset($_GET['test'])) {
                echo "findField return index $idx: ".json_encode($values[$idx])."<br>\n";
            }
            return $values[$idx];
        } else {
		    throw new \Exception("Could not find index $idx for uid $field");
        }
	}

    public static function getEmailFromName($first, $last) {
        $key = self::getNameAssociations($first, $last);
        $info = self::getLDAPByMultiple(array_keys($key), array_values($key));
        if ($info['count'] > 0) {
            return self::findField($info, "mail", 0);
        }
        return "";
    }

    public static function getEmailFromUid($uid) {
        $info = self::getLDAP("cn", $uid);
        if ($info['count'] > 0) {
            return self::findField($info, "mail", 0);
        }
        return "";
    }

    public static function getLimitedFields() {
        return [
            "cn",
            "sn",
            "o",
            "title",
            "telephonenumber",
            "givenname",
            "displayname",
            "department",
            "company",
            "employeetype",
            "name",
            "division",
            "mail",
            "departmentnumber",
            "middlename",
        ];
    }

    public static function getFields() {
        return [
            "cn",
            "sn",
            "o",
            "title",
            "telephonenumber",
            "givenname",
            "distinguishedname",
            "instancetype",
            "whencreated",
            "displayname",
            "usncreated",
            "memberof",
            "usnchanged",
            "department",
            "company",
            "proxyaddresses",
            "extensionattribute1",
            "extensionattribute2",
            "employeetype",
            "name",
            "useraccountcontrol",
            "codepage",
            "countrycode",
            "primarygroupid",
            "accountexpires",
            "samaccountname",
            "division",
            "samaccounttype",
            "legacyexchangedn",
            "userprincipalname",
            "objectcategory",
            "dscorepropagationdata",
            "lastlogontimestamp",
            "mail",
            "departmentnumber",
            "middlename",
            "msexchalobjectversion",
            "msexchremoterecipienttype",
            "msexchumdtmfmap",
            "uidnumber",
            "msexchprovisioningflags",
            "msexchrecipientsoftdeletedstatus",
            "msds-externaldirectoryobjectid",
            "loginshell",
            "msexchwhenmailboxcreated",
            "gidnumber",
        ];
    }

    public static function getFieldsOldLDAP() {
		return [
            "modifytimestamp",
            "modifiersname",
            "departmentnumber",
            "edupersonaffiliation",
            "vanderbiltpersonhrdeptname",
            "vanderbiltpersonhrdeptnumber",
            "vanderbiltpersonlastepwchgdate",
            "o",
            "vanderbiltpersoncommonid",
            "displayname",
            "uid",
            "edupersonprincipalname",
            "creatorsname",
            "createtimestamp",
            "vanderbiltpersonsecurity",
            "givenname",
            "sn",
            "objectclass",
            "uidnumber",
            "gidnumber",
            "homedirectory",
            "mail",
            "vanderbiltpersonepinumber",
            "vanderbiltpersonstudentid",
            "vanderbiltpersonemployeeid",
            "cn",
            "vanderbiltpersonjobstatus",
            "vanderbiltpersonhrdepttype",
            "vanderbiltpersonactiveemployee",
            "vanderbiltpersonactivestudent",
            "vanderbiltpersonemployeeclass",
            "edupersonprimaryaffiliation",
            "telephonenumber",
            "loginshell",
            "vanderbiltpersonjobcode",
            "vanderbiltpersonjobname",
        ];
	}
}


