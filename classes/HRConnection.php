<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class HRConnection extends OracleConnection
{
	public function __construct() {
		$userid = "";
		$passwd = "";
		$serverAddress = "";
		putenv("TNS_ADMIN=".Application::getCredentialsDir()."/../tnsnames/");
		$file = Application::getCredentialsDir()."/career_dev/biprodDB.php";
		if (file_exists($file)) {
			Application::log("Using $file");
			require($file);
		} else {
			throw new \Exception("Could not find file!");
		}

		$this->userid = $userid;
		$this->passwd = $passwd;
		$this->server = $serverAddress;
	}

	public function getUserId() {
		return $this->userid;
	}

	public function getPassword() {
		return $this->passwd;
	}

	public function getServer() {
		return $this->server;
	}

	public function getEmployeeID($vunet) {
		if ($vunet) {
			$this->query("ALTER SESSION SET CURRENT_SCHEMA=HR_MART");

			$regex = '%' . $vunet . '%';
			$sql = "select VUNETID, EMPLID from HR_PERSON_D where VUNETID like '$regex' order by PERSON_KEY desc";
			$rows = $this->query($sql);
			if (count($rows) > 0) {
				$row = $rows[0];
				if ($row['EMPLID'] && ($row['VUNETID'] == $vunet)) {
					return $row['EMPLID'];
				}
			}
		}
		return "";
	}

	private $userid;
	private $passwd;
	private $server;
}
