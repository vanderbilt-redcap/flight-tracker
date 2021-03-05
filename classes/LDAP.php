<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/REDCapManagement.php");

class LDAP {
	public static function getLDAPByMultiple($types, $values)
	{
		return LdapLookup::lookupUserDetailsByKeys($values, $types, true, false, true);
	}

	public static function getLDAP($type, $value) {
		return self::getLDAPByMultiple(array($type), array($value));
	}

    public static function getREDCapRowsFromName($first, $last, $metadata, $recordId, $repeatingForms) {
	    $firstNames = NameMatcher::explodeFirstName($first);
	    $lastNames = NameMatcher::explodeLastName($last);
	    $instrument = "ldap";
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
	    $prefix = "ldap_";
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
	    $defaultRow = ["record_id" => $recordId, "ldap_complete" => "2"];
	    foreach ($ldapFields as $ldapField) {
	        $redcapField = $prefix.$ldapField;
	        if (in_array($redcapField, $metadataFields)) {
                $values = self::findField($info, $ldapField);
                if (count($values) == 0) {
                    if ($debug) {
                        Application::log("Could not find $ldapField for Record $recordId in LDAP");
                    }
                } else if (in_array("ldap", $repeatingForms)) {
                    # multiple
                    if ($debug) {
                        Application::log("Could have values for Record $recordId in LDAP: " . implode(", ", $values));
                    }
                    $i = 0;
                    foreach ($values as $value) {
                        if (!isset($rows[$i])) {
                            $rows[$i] = $defaultRow;
                            $rows[$i]["redcap_repeat_instrument"] = "ldap";
                            $rows[$i]["redcap_repeat_instance"] = $previousMaxInstance + 1;
                            $previousMaxInstance++;
                        }
                        $rows[$i][$redcapField] = $value;
                        $i++;
                    }
                } else {
                    if (!isset($rows[0])) {
                        $rows[0] = $defaultRow;
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
	        Application::log("getREDCapRows Returning ".json_encode_with_spaces($rows));
        }
	    return $rows;
    }

    public static function getNameAssociations($first, $last) {
        return ["givenname" => $first, "sn" => $last];
    }

	public static function getVUNetsAndDepartments($first, $last) {
		$key = self::getNameAssociations($first, $last);
		$info = self::getLDAPByMultiple(array_keys($key), array_values($key));
		$vunets = self::findField($info, "uid");
		$departments = self::findField($info, "vanderbiltpersonhrdeptname");
		return array($vunets, $departments);
	}

	public static function getName($uid) {
        $info = self::getLDAP("uid", $uid);
        if ($info['count'] > 0) {
            return self::findField($info, "givenname", 0)." ".self::findField($info, "sn", 0);
        }
        return "";
    }

    public static function getVUNet($first, $last) {
        $key = self::getNameAssociations($first, $last);
        $info = self::getLDAPByMultiple(array_keys($key), array_values($key));
        if ($info['count'] > 0) {
            return self::findField($info, "uid", 0);
        }
        return "";
    }

    public static function getAllVUNets($first, $last) {
        $key = self::getNameAssociations($first, $last);
        $info = self::getLDAPByMultiple(array_keys($key), array_values($key));
        return self::findField($info, "uid");
    }

    # $info is line from getLDAP
	# returns array from $info with the field $field
	public static function findField($info, $field, $idx = "all") {
		$separator = ";";
		$values = array();
		for ($i = 0; $i < $info['count']; $i++) {
			$line = $info[$i];
			$value = "";
			foreach ($line as $var => $results) {
				if ($var == $field) {
					if (isset($results['count'])) {
						$r = array();
						for ($j = 0; $j < $results['count']; $j++) {
							array_push($r, $results[$j]);
						}
						$value = implode($separator, $r);
					}
				}
			}
			array_push($values, $value);
		}
		if (isset($_GET['test'])) {
		    echo "findField values: ".json_encode($values)."<br>";
		    echo "findField idx: $idx<br>";
        }
		if ($idx === "all") {
            if (isset($_GET['test'])) {
                echo "findField return all $idx<br>";
            }
            return $values;
        } else if (isset($values[$idx])) {
            if (isset($_GET['test'])) {
                echo "findField return index $idx: ".json_encode($values[$idx])."<br>";
            }
            return $values[$idx];
        } else {
		    throw new \Exception("Could not find index $idx for uid $field");
        }
	}

	public static function getFields() {
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


# adapted from Core/LdapLookup.php
# originally authored by Kyle McGuffin

class LdapLookup {
	const VUNET_KEY = "uid";
	const EMAIL_KEY = "mail";
	const FIRST_NAME_KEY = "givenname";
	const LAST_NAME_KEY = "sn";
	const FULL_NAME_KEY = "cn";
	const PERSON_ID_KEY = "vanderbiltpersonemployeeid";
	const PHONE_NUMBER_KEY = "telephonenumber";
	const DEPT_NUMBER_KEY = "vanderbiltpersonhrdeptnumber";
	const DEPT_NAME_KEY = "vanderbiltpersonhrdeptname";

	private static $ldapConns;
	private static $ldapBinds;

	private function resetConnections($includeVU) {
	    self::initialize($includeVU, TRUE);
    }

	/**
	 * @param $values array of strings
	 * @param $keys array of strings
	 * @param $and bool - if true ANDs the search filters; otherwise ORs them
	 * @param $oneLine bool - return one line
	 * @return array|bool
	 * @throws Exception
	 */
	public static function lookupUserDetailsByKeys($values,$keys,$and,$oneLine = true, $includeVU = false) {
		self::initialize($includeVU);

		$cnt = count($keys);
		if ($cnt > count($values)) {
			$cnt = count($values);
		}

		$searchTerms = array();
		if ($cnt > 0) {
			for ($i = 0; $i < $cnt; $i++) {
				$searchTerms[] = "(".$keys[$i]."=".$values[$i].")";
			}
		}

		$sr = NULL;
		$allData = array();
		if (!empty($searchTerms)) {
			## Search LDAP for any user matching the vunet ID
			$char = "|";
			if ($and) {
				$char = "&";
			}
			$searchFilter = "($char".implode("", $searchTerms).")";
			$resetTries = 0;
			do {
                $hasReset = FALSE;
                foreach (self::$ldapConns as $ldapConn) {
                    $currTry = 0;
                    $sr = NULL;
                    while (($currTry < self::MAX_RETRIES) && !$sr) {
                        $currTry++;
                        $sr = ldap_search($ldapConn, "ou=people,dc=vanderbilt,dc=edu", $searchFilter);
                        if ($sr) {
                            $data = ldap_get_entries($ldapConn, $sr);
                            if ($oneLine) {
                                for($i = 0; $i < count($data); $i++) {
                                    return $data[$i];
                                }
                            } else {
                                $allData = self::mergeAndDiscardDups($allData, $data);
                            }
                        } else if (ldap_error($ldapConn) != "") {
                            if (ldap_error($ldapConn) == "Can't contact LDAP server") {
                                self::resetConnections($includeVU);
                                $hasReset = TRUE;
                                $resetTries++;
                                break;
                            }
                            if ($currTry == self::MAX_RETRIES) {
                                echo "<pre>";var_dump(ldap_error($ldapConn));echo "</pre><br /><Br />";
                                throw new \Exception(ldap_error($ldapConn)." ".$searchFilter);
                            }
                        }
                    }
                }
            } while($hasReset && ($resetTries < self::MAX_RETRIES));
		}
		return $allData;
	}

	/**
	  * @param $data1 array
	  * @param $data2 array
	  * @return array
	  */
	private static function mergeAndDiscardDups($data1, $data2) {
		$sources = array($data1, $data2);

		$deDuped = array();
		foreach ($sources as $source) {
			if (!empty($source) && isset($source['count'])) {
				for ($i = 0; $i < $source['count']; $i++) {
					$entry = $source[$i];
					$currUID = self::getUID($entry);
					$add = TRUE;
					foreach ($deDuped as $existingEntry) {
						if ($currUID && (self::getUID($existingEntry) == $currUID)) {
							$add = FALSE;
							break;
						}
					}
					if ($add) {
						array_push($deDuped, $entry);
					}
				}
			}
		}
		$deDuped['count'] = count($deDuped);

		return $deDuped;
	}

	/**
	  * @param $entry array
	  * returns string
	  */
	public static function getUID($entry) {
		foreach ($entry as $var => $results) {
			if ($var == "uid") {
				if (isset($results['count'])) {
					for ($i = 0; $i < $results['count']; $i++) {
						return $results[$i];
					}
				}
			}
		}
		return "";
	}


	/**
	 * @param $value string
	 * @param $key string
	 * @param $oneLine bool
	 * @return array|bool
	 * @throws Exception
	 */
	public static function lookupUserDetailsByKey($value,$key,$oneLine=true,$includeVU=false) {
		self::initialize($includeVU);

		## Search LDAP for any user matching the vunet ID
		$allData = array();
		foreach (self::$ldapConns as $ldapConn) {
			$sr = ldap_search($ldapConn, "ou=people,dc=vanderbilt,dc=edu", "(".$key."=".$value.")");

			if ($sr) {
				$data = ldap_get_entries($ldapConn, $sr);
				if ($oneLine) {
					for ($i=0; $i < count($data); $i++) {
						return $data[$i];
					}
				} else {
					$allData = self::mergeAndDiscardDups($allData, $data);
				}
			} else {
				if(ldap_error($ldapConn) != "") {
					echo "<pre>";var_dump(ldap_error($ldapConn));echo "</pre><br /><Br />";
					throw new \Exception(ldap_error($ldapConn));
				}
			}
		}
		return $allData;
	}

	public static function lookupUserDetailsByVunet($vunet) {
		return self::lookupUserDetailsByKey($vunet,self::VUNET_KEY);
	}

	public static function lookupUserDetailsByPersonId($personId) {
		if(is_numeric($personId) < 7) {
			$personId = str_pad($personId,7,"0",STR_PAD_LEFT);
		}
		return self::lookupUserDetailsByKey($personId,self::PERSON_ID_KEY);
	}

	public static function lookupUsersByNameFragment($nameFragment, $includeVU = false) {
		self::initialize($includeVU);

		## Search LDAP for any user matching the $nameFragment on vunet, surname or givenname
		$allData = array();
		foreach (self::$ldapConns as $ldapConn) {
			$sr = ldap_search($ldapConn, "ou=people,dc=vanderbilt,dc=edu", "(|(uid=$nameFragment*)(sn=$nameFragment*)(givenname=$nameFragment*))");

			if ($sr) {
				$data = ldap_get_entries($ldapConn, $sr);
				$allData = self::mergeAndDiscardDups($allData, $data);
			} else {
				echo "<pre>";var_dump(ldap_error($ldapConn));echo "</pre><br /><Br />";
			}
		}
		return $allData;
	}

	public static function initialize($includeVU = FALSE, $force = FALSE) {
		if(!self::$ldapBinds || $force) {
			include "/app001/credentials/con_redcap_ldap_user.php";

			self::$ldapConns = array();
			array_push(self::$ldapConns, ldap_connect("ldaps://ldap.vunetid.mc.vanderbilt.edu"));
			if ($includeVU) {
				array_push(self::$ldapConns, ldap_connect("ldaps://ldap.vunetid.vanderbilt.edu"));
			}

			# assume same userid/password
			# Bind to LDAP server
			self::$ldapBinds = array();
			for ($i = 0; $i < count(self::$ldapConns); $i++) {
			    # $ldapuser and $ldappass defined in the credentials file, included above
				self::$ldapBinds[$i] = ldap_bind(self::$ldapConns[$i], "uid=".$ldapuser.",ou=special users,dc=vanderbilt,dc=edu", $ldappass);
			}


			unset($ldapuser);
			unset($ldappass);
		}
	}

	// Returns true if the vunetid and password are valid (and false otherwise).
	public static function authenticate($vunetid, $password){
		if(empty($vunetid) || empty($password)){
			return false;
		}

		// This DC specific url is required to authenticate users other than the redcap01 user.
		// This url does not seem to work with ldap_search() though, or I would have used it in the initialize() function as well.
		$connection = @ldap_connect("ldap://DC-M1.ds.vanderbilt.edu");
		return @ldap_bind($connection, "$vunetid@vanderbilt.edu", $password);
	}

	const MAX_RETRIES = 5;
}

