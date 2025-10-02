<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

# adapted from Core/LdapLookup.php
# originally authored by Kyle McGuffin
# modifications by Scott J. Pearson

class LdapLookup
{
	public const VUNET_KEY = "cn";
	public const EMAIL_KEY = "mail";
	public const FIRST_NAME_KEY = "givenname";
	public const LAST_NAME_KEY = "sn";
	public const FULL_NAME_KEY = "displayname";
	public const PHONE_NUMBER_KEY = "telephonenumber";
	public const DEPT_NUMBER_KEY = "departmentnumber";
	public const DEPT_NAME_KEY = "department";

	private static $ldapConns;
	private static $ldapBinds;

	private static function resetConnections() {
		self::initialize(true);
	}

	/**
	 * @param $values array of strings
	 * @param $keys array of strings
	 * @param $and bool - if true ANDs the search filters; otherwise ORs them
	 * @param $oneLine bool - return one line
	 * @return array|bool
	 * @throws \Exception
	 */
	public static function lookupUserDetailsByKeys($values, $keys, $and, $oneLine = true) {
		self::initialize();

		$cnt = count($keys);
		if ($cnt > count($values)) {
			$cnt = count($values);
		}

		$searchTerms = [];
		if ($cnt > 0) {
			for ($i = 0; $i < $cnt; $i++) {
				if ($keys[$i] === "uid") {
					$keys[$i] = "cn";
				}
				$searchTerms[] = "(".$keys[$i]."=".$values[$i].")";
			}
		}

		$sr = null;
		$allData = [];
		if (!empty($searchTerms)) {
			## Search LDAP for any user matching the vunet ID
			$char = "|";
			if ($and) {
				$char = "&";
			}
			$searchFilter = "($char".implode("", $searchTerms).")";
			$resetTries = 0;
			do {
				$hasReset = false;
				foreach (self::$ldapConns as $ldapConn) {
					$currTry = 0;
					$sr = null;
					while (($currTry < self::MAX_RETRIES) && !$sr) {
						$currTry++;
						$sr = ldap_search($ldapConn, "cn=users,dc=ds,dc=vanderbilt,dc=edu", $searchFilter);
						if ($sr) {
							$data = ldap_get_entries($ldapConn, $sr);
							if ($oneLine) {
								for ($i = 0; $i < count($data); $i++) {
									return $data[$i];
								}
							} else {
								$allData = self::mergeAndDiscardDups($allData, $data);
							}
						} elseif (ldap_error($ldapConn) != "") {
							if (ldap_error($ldapConn) == "Can't contact LDAP server") {
								self::resetConnections();
								$hasReset = true;
								$resetTries++;
								break;
							}
							if ($currTry == self::MAX_RETRIES) {
								throw new \Exception(ldap_error($ldapConn)." ".$searchFilter);
							}
						}
					}
				}
			} while ($hasReset && ($resetTries < self::MAX_RETRIES));
		}
		return $allData;
	}

	/**
	 * @param $data1 array
	 * @param $data2 array
	 * @return array
	 */
	private static function mergeAndDiscardDups($data1, $data2) {
		$sources = [$data1, $data2];

		$deDuped = [];
		foreach ($sources as $source) {
			if (!empty($source) && isset($source['count'])) {
				for ($i = 0; $i < $source['count']; $i++) {
					$entry = $source[$i];
					$currUID = self::getUID($entry);
					$add = true;
					foreach ($deDuped as $existingEntry) {
						if ($currUID && (self::getUID($existingEntry) == $currUID)) {
							$add = false;
							break;
						}
					}
					if ($add) {
						$deDuped[] = $entry;
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
			if ($var == "cn") {
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
	 * @throws \Exception
	 */
	public static function lookupUserDetailsByKey($value, $key, $oneLine = true) {
		self::initialize();

		## Search LDAP for any user matching the vunet ID
		$allData = [];
		foreach (self::$ldapConns as $ldapConn) {
			$sr = ldap_search($ldapConn, "cn=users,dc=ds,dc=vanderbilt,dc=edu", "(".$key."=".$value.")");

			if ($sr) {
				$data = ldap_get_entries($ldapConn, $sr);
				$data = Sanitizer::sanitizeArray($data, true, false);
				if ($oneLine) {
					for ($i = 0; $i < count($data); $i++) {
						return $data[$i];
					}
				} else {
					$allData = self::mergeAndDiscardDups($allData, $data);
				}
			} else {
				if (ldap_error($ldapConn) != "") {
					throw new \Exception(ldap_error($ldapConn));
				}
			}
		}
		return $allData;
	}

	public static function lookupUserDetailsByVunet($vunet) {
		return self::lookupUserDetailsByKey($vunet, self::VUNET_KEY);
	}

	public static function lookupUsersByNameFragment($nameFragment) {
		self::initialize();

		## Search LDAP for any user matching the $nameFragment on vunet, surname or givenname
		$allData = [];
		foreach (self::$ldapConns as $ldapConn) {
			$sr = ldap_search($ldapConn, "cn=users,dc=ds,dc=vanderbilt,dc=edu", "(|(cn=$nameFragment*)(sn=$nameFragment*)(givenname=$nameFragment*))");

			if ($sr) {
				$data = ldap_get_entries($ldapConn, $sr);
				$allData = self::mergeAndDiscardDups($allData, $data);
			} else {
				throw new \Exception(ldap_error($ldapConn));
			}
		}
		return $allData;
	}

	public static function initialize($force = false) {
		$ldappass = "";
		$ldapuser = "";
		$includeFile = Application::getCredentialsDir()."/con_redcap_ldap_user.php";
		if (!file_exists($includeFile)) {
			throw new \Exception("Could not find credentials file!");
		}
		self::$ldapConns = [];
		self::$ldapBinds = [];
		if (!self::$ldapBinds || $force) {
			include $includeFile;

			if (!$ldapuser || !$ldappass) {
				return;
			}

			self::$ldapConns[] = ldap_connect("ldaps://ds.vanderbilt.edu");

			# assume same userid/password
			# Bind to LDAP server
			for ($i = 0; $i < count(self::$ldapConns); $i++) {
				self::$ldapBinds[$i] = ldap_bind(self::$ldapConns[$i], "cn=".$ldapuser.",cn=users,dc=ds,dc=vanderbilt,dc=edu", $ldappass);
			}


			unset($ldapuser);
			unset($ldappass);
		}
	}

	// Returns true if the vunetid and password are valid (and false otherwise).
	public static function authenticate($vunetid, $password) {
		if (empty($vunetid) || empty($password)) {
			return false;
		}

		foreach (self::getDSNs() as $dsn) {
			$connection = @ldap_connect($dsn['url']);
			if (@ldap_bind($connection, "cn=$vunetid,cn=users,dc=ds,dc=vanderbilt,dc=edu", $password)) {
				return true;
			}
		}
		return false;
	}

	# from ori1007lt:/app001/www/redcap/webtools2/ldap/ldap_config.php
	private static function getDSNs() {
		$ldapuser = Sanitizer::sanitize($_POST['username'] ?? "");
		$ldappass = Sanitizer::sanitize($_POST['password'] ?? "");
		if ($ldappass && $ldapuser) {
			return [
				[
					'url'       => 'ldaps://ds.vanderbilt.edu',
					'port'      => '636',
					'version'   => '3',
					'userattr' => 'cn',
					'binddn'    => 'cn='.$ldapuser.',cn=users,dc=ds,dc=vanderbilt,dc=edu',
					'basedn'    => 'dc=ds,dc=vanderbilt,dc=edu',    # if have issues, try 'cn=users,dc=ds,dc=vanderbilt,dc=edu'
					'bindpw'    => $ldappass,
				],
			];
		} else {
			return [];
		}
	}

	public const MAX_RETRIES = 5;
}
