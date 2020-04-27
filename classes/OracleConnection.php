<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../Application.php");

abstract class OracleConnection {
	public function connect() {
		$this->connection = oci_connect($this->getUserId(), $this->getPassword(), $this->getServer());
		if (!$this->connection) {
			throw new \Exception("Unable to connect: ".$this->getUserId()." ".$this->getServer()." ".json_encode(oci_error()));
		}
		Application::log("Has connection to ".$this->getUserId()." ".$this->getPassword()." ".$this->getServer());
		Application::log("oci_error: ".json_encode(oci_error()));
	}

	# returns the data in an array
	# problematic for very large queries
	protected function query($sql) {
		if (!$this->connection) {
			throw new \Exception("Unable to connect! ".json_encode(oci_error()));
		}
		Application::log("Has connection to ".$this->getServer());
		Application::log("oci_error: ".json_encode(oci_error()));

		$stmt = oci_parse($this->connection, $sql);
		if (!$stmt) {
			throw new \Exception("Unable to parse ".$sql.": ".json_encode(oci_error()));
		}

		$result = oci_execute($stmt);
		Application::log("Statement returned ".oci_num_rows($stmt)." rows");
		if (!$result) {
			throw new \Exception("Unable to execute statement. ".json_encode(oci_error($stmt)));
		}

		$data = array();
		while ($row = oci_fetch_assoc($stmt)) {
			array_push($data, $row);
		}

		oci_free_statement($stmt);

		return $data;
	}

	public function close() {
		$result = oci_close($this->connection);
		if (!$result) {
			throw new \Exception("Unable to disconnect");
		}
		return $result;
	}

	abstract public function getUserId();
	abstract public function getPassword();
	abstract public function getServer();

	private $connection = NULL;
}

class VICTRPubMedConnection extends OracleConnection {
	public function __construct() {
		$file = dirname(__FILE__)."/../victrPubMedDB.php";
		if (file_exists($file)) {
			Application::log("Using $file");
			require($file);
		} else {
			$file = dirname(__FILE__)."/../../../plugins/career_dev/victrPubMedDB.php";
			if (file_exists($file)) {
				Application::log("Using $file");
				require($file);
			} else {
				Application::log("Could not find files!");
			}
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

	public function getData() {
		$data = array('outcomepubs' => array(), 'outcomepubmatches' => array(), 'pubmed_publications' => array());

		$sql = "SELECT * FROM STARBRITEADM.OUTCOMEPUBS";
		$data['outcomepubs'] = $this->query($sql);

		$sql = "SELECT * FROM STARBRITEADM.OUTCOMEPUBMATCHES";
		$data['outcomepubmatches'] = $this->query($sql);

		$sql = "SELECT PUBPUB_ID, PUBPUB_TITLE, PUBPUB_PUBDATE, PUBPUB_PUBDATECONV, PUBPUB_EPUBDATE, PUBPUB_EPUBDATECONV, PUBPUB_SOURCE, PUBPUB_FULLJOURNALNAME, PUBPUB_AUTHORLIST, PUBPUB_VOLUME, PUBPUB_ISSUE, PUBPUB_PAGES, PUBPUB_PUBTYPE, PUBPUB_SO, PUBPUB_HISTORYPUBMEDDATE, PUBPUB_CREATED_DATE, PUBPUB_MODIFIED_DATE, PUBPUB_FLAGS, PUBPUB_PMCID FROM SRIADM.PUBMED_PUBLICATIONS";
		$data['pubmed_publications'] = $this->query($sql);

		return $data;
	}

	private $userid;
	private $passwd;
	private $server;
}

class COEUSConnection extends OracleConnection {
	public function __construct() {
		$usedFile = "None";
		$file = dirname(__FILE__)."/../coeusDB.php";
		if (file_exists($file)) {
			require($file);
			$usedFile = $file;
		} else {
			$file = dirname(__FILE__)."/../../../plugins/career_dev/coeusDB.php";
			if (file_exists($file)) {
				require($file);
				$usedFile = $file;
			}
		}
		if (!$userid || !$passwd || !$serverAddress) {
			throw new \Exception("Cannot find userid: $userid; passwd ".strlen($passwd)." characters; server: $serverAddress from file $usedFile in directory ".dirname(__FILE__));
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

	public function insertNewIds($ids) {
		foreach ($ids as $id) {
			$sql = "INSERT INTO SRIADM.SRI_CAREER (CAREER_VUNET, CAREER_ACTIVE) VALUES ('$id', '1')";
			$this->query($sql);
		}
	}

	public function getCurrentIds() {
		$sql = "SELECT * FROM SRIADM.SRI_CAREER";
		return $this->query($sql);
	}

	public function pullAwards() {
		$sql = "SELECT * FROM SRIADM.RC_AWARDS_VW";
		return $this->query($sql);
	}

	public function pullInvestigators() {
		$sql = "SELECT * FROM SRIADM.RC_AWARD_INVESTIGATORS_VW";
		return $this->query($sql);
	}

	public function pullPis() {
		$sql = "SELECT * FROM SRIADM.RC_AWARD_INVESTIGATORS_VW WHERE PI_FLAG = 'Y' OR PERCENT_EFFORT >= 75";
		return $this->query($sql);
	}

	public function describeDepartments() {
		$sql = "DESCRIBE SRIADM.COEUS_VUMC_IMPH_AWARDS_VW";
		return $this->query($sql);
	}

	public function pullByDepartments() {
		$sql = "SELECT * FROM SRIADM.COEUS_VUMC_IMPH_AWARDS_VW";
		return $this->query($sql);
	}

	public function pullAllRecords() {
		$data = array();

		$data["awards"] = $this->pullAwards();

		$sql = "SELECT * FROM SRIADM.SRI_CAREER WHERE CAREER_ACTIVE = 1";
		$data["membership"] = $this->query($sql);

		$data["investigators"] = $this->pullPis();

		return $data;
	}

	private $userid;
	private $passwd;
	private $server;
}
