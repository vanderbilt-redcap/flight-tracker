<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class REDCapLookup
{
	public function __construct($firstName, $lastName) {
		$this->firstName = NameMatcher::clearOfHonorifics(NameMatcher::clearOfDegrees($firstName));
		$this->lastName = NameMatcher::clearOfHonorifics(NameMatcher::clearOfDegrees($lastName));
	}

	public static function getUserInfo($uid) {
		if ($uid) {
			$module = Application::getModule();
			$sql = "SELECT * FROM redcap_user_information WHERE username = ?";
			$results = $module->query($sql, [$uid]);
			if ($row = $results->fetch_assoc()) {
				return $row;
			}
		}
		return [];
	}

	public static function getCurrentUserIDAndName() {
		$username = Application::getUsername();
		$row = REDCapLookup::getUserInfo($username);
		$firstName = $row['user_firstname'];
		$lastName = $row['user_lastname'];
		return [$username, $firstName, $lastName];
	}

	public static function getCurrentUserIDNameAndEmails() {
		$username = Application::getUsername();
		$row = REDCapLookup::getUserInfo($username);
		$firstName = $row['user_firstname'];
		$lastName = $row['user_lastname'];
		return [$username, $firstName, $lastName, self::getEmailsFromRow($row)];
	}

	public static function getEmailsFromRow($row) {
		$emails = [];
		$emailFields = ["user_email", "user_email2", "user_email3"];
		foreach ($emailFields as $field) {
			if ($row[$field]) {
				$emails[] = $row[$field];
			}
		}
		return $emails;
	}

	public function getName() {
		return $this->firstName." ".$this->lastName;
	}

	public function getUidsAndNames($showEmails = false) {
		$uids = [];
		$sqlField = $showEmails ? "user_email" : "";
		$clause = $showEmails ? ", ".$sqlField : "";

		$module = Application::getModule();
		if (!$this->firstName || !$this->lastName) {
			if (!$this->firstName) {
				$name = $this->lastName;
			} else {
				$name = $this->firstName;
			}
			$params = [strtolower($name), strtolower($name)];
			$sql = "SELECT username, user_firstname, user_lastname$clause FROM redcap_user_information WHERE lower(user_firstname) = ? OR lower(user_lastname) = ?";
		} else {
			$firstNames = NameMatcher::explodeFirstName($this->firstName);
			if (count($firstNames) > 1) {
				foreach ($firstNames as $firstName) {
					if ($firstName && !NameMatcher::isInitial($firstName)) {
						$params2 = [strtolower($this->firstName), strtolower($this->lastName)];
						$sql2 = "SELECT username, user_firstname, user_lastname$clause FROM redcap_user_information WHERE lower(user_firstname) = ? AND lower(user_lastname) = ?";
						$results2 = $module->query($sql2, $params2);
						while ($row2 = $results2->fetch_assoc()) {
							if ($row2['username']) {
								$uids[$row2['username']] = self::formatName($row2['user_firstname'], $row2['user_lastname']);
								if ($showEmails) {
									$uids[$row2['username']] .= " ".$row2[$sqlField];
								}
							}
						}
					}
				}
				ksort($uids);
				return $uids;
			} else {
				$params = [strtolower($this->firstName), strtolower($this->lastName)];
				$sql = "SELECT username, user_firstname, user_lastname$clause FROM redcap_user_information WHERE lower(user_firstname) = ? AND lower(user_lastname) = ?";
			}
		}
		$results = $module->query($sql, $params);
		while ($row = $results->fetch_assoc()) {
			if ($row['username']) {
				$uids[$row['username']] = self::formatName($row['user_firstname'], $row['user_lastname']);
				if ($showEmails) {
					$uids[$row['username']] .= " ".$row[$sqlField];
				}
			}
		}
		foreach ($uids as $uid => $name) {
			$uids[$uid] = trim($name);
		}
		ksort($uids);
		return $uids;
	}

	public static function getAllEmails($username) {
		$info = self::getUserInfo($username);
		return self::getEmailsFromRow($info);
	}

	private static function formatName($firstName, $lastName) {
		if ($firstName && $lastName) {
			return $firstName." ".$lastName;
		} elseif (!$firstName) {
			return $lastName;
		} else {
			return $firstName;
		}
	}

	private $firstName = "";
	private $lastName = "";
}
