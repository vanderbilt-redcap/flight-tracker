<?php

namespace Vanderbilt\CareerDevLibrary;


class LDAP {
	public static function getLDAPByMultiple($types, $values)
	{
		return LdapLookup::lookupUserDetailsByKeys($values, $types, true, false);
	}

	public static function getLDAP($type, $value)
	{
		return LdapLookup::lookupUserDetailsByKeys(array($value), array($type), true, false);
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

	private static $ldapConn;
	private static $ldapBind;

	/**
	 * @param $values array of strings
	 * @param $keys array of strings
	 * @param $and bool - if true ANDs the search filters; otherwise ORs them
	 * @param $oneLine bool - return one line
	 * @return array|bool
	 * @throws Exception
	 */
	public static function lookupUserDetailsByKeys($values,$keys,$and,$oneLine = true) {
		self::initialize();

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
		if (!empty($searchTerms)) {
			## Search LDAP for any user matching the vunet ID
			$char = "|";
			if ($and) {
				$char = "&";
			}
			$searchFilter = "($char".implode("", $searchTerms).")";
			$sr = ldap_search(self::$ldapConn, "ou=people,dc=vanderbilt,dc=edu", $searchFilter);
		}

		if ($sr) {
			$data = ldap_get_entries(self::$ldapConn, $sr);
			if ($oneLine) {
				for($i = 0; $i < count($data); $i++) {
					return $data[$i];
				}
			} else {
				return $data;
			}
		}
		else {
			if(ldap_error(self::$ldapConn) != "") {
				echo "<pre>";var_dump(ldap_error(self::$ldapConn));echo "</pre><br /><Br />";
				throw new Exception(ldap_error(self::$ldapConn)." ".$searchFilter);
			}
		}
		return false;
	}

	/**
	 * @param $value string
	 * @param $key string
	 * @param $oneLine bool
	 * @return array|bool
	 * @throws Exception
	 */
	public static function lookupUserDetailsByKey($value,$key,$oneLine=true) {
		self::initialize();

		## Search LDAP for any user matching the vunet ID
		$sr = ldap_search(self::$ldapConn, "ou=people,dc=vanderbilt,dc=edu", "(".$key."=".$value.")");

		if ($sr) {
			$data = ldap_get_entries(self::$ldapConn, $sr);
			if ($oneLine) {
				for ($i=0; $i < count($data); $i++) {
					return $data[$i];
				}
			} else {
				return $data;
			}
		}
		else {
			if(ldap_error(self::$ldapConn) != "") {
				echo "<pre>";var_dump(ldap_error(self::$ldapConn));echo "</pre><br /><Br />";
				throw new Exception(ldap_error(self::$ldapConn));
			}
		}
		return false;
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

	public static function lookupUsersByNameFragment($nameFragment) {
		self::initialize();

		## Search LDAP for any user matching the $nameFragment on vunet, surname or givenname
		$sr = ldap_search(self::$ldapConn, "ou=people,dc=vanderbilt,dc=edu", "(|(uid=$nameFragment*)(sn=$nameFragment*)(givenname=$nameFragment*))");

		if ($sr) {
			$data = ldap_get_entries(self::$ldapConn, $sr);

			return $data;
		}
		else {
			echo "<pre>";var_dump(ldap_error(self::$ldapConn));echo "</pre><br /><Br />";
		}
		return false;
	}


	public static function initialize() {
		if(!self::$ldapBind) {
			include "/app001/credentials/con_redcap_ldap_user.php";

			self::$ldapConn = ldap_connect("ldaps://ldap.vunetid.vanderbilt.edu");

			// Bind to LDAP server
			self::$ldapBind = ldap_bind(self::$ldapConn, "uid=".$ldapuser.",ou=special users,dc=vanderbilt,dc=edu", $ldappass);

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
}

