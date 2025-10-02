<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class REDCapLookupByEmail
{
	public function __construct($email) {
		$this->email = $email;
	}

	public function getUserid() {
		if ($this->email) {
			$module = Application::getModule();
			$sql = "SELECT username FROM redcap_user_information WHERE lower(user_email) = ?";
			$q = $module->query($sql, [strtolower($this->email)]);
			if ($row = $q->fetch_assoc($q)) {
				return $row['username'];
			}
		}
		return "";
	}

	public function getName() {
		if ($this->email) {
			$module = Application::getModule();
			$sql = "SELECT user_firstname, user_lastname FROM redcap_user_information WHERE lower(user_email) = ?";
			$q = $module->query($sql, [strtolower($this->email)]);
			if ($row = $q->fetch_assoc()) {
				return $row['user_firstname']." ".$row['user_lastname'];
			}
		}
		return "";
	}

	public function getLastName() {
		if ($this->email) {
			$module = Application::getModule();
			$sql = "SELECT user_lastname FROM redcap_user_information WHERE lower(user_email) = ?";
			$q = $module->query($sql, [strtolower($this->email)]);
			if ($row = $q->fetch_assoc()) {
				return $row['user_lastname'];
			}
		}
		return "";
	}

	private $email = "";
}
