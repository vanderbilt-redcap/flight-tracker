<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

abstract class OracleConnection {
	public function connect() {
		$this->connection = oci_connect($this->getUserId(), $this->getPassword(), $this->getServer());
		if (!$this->connection) {
			throw new \Exception("Unable to connect: ".$this->getUserId()." ".$this->getServer()." ".json_encode(oci_error()));
		}
		Application::log("Has connection to ".$this->getUserId()." ".strlen($this->getPassword())." ".$this->getServer());
		Application::log("oci_error: ".json_encode(oci_error()));
	}

	# returns the data in an array
	# problematic for very large queries
	protected function query($sql) {
		if (!$this->connection) {
			throw new \Exception("Unable to connect! ".json_encode(oci_error()));
		}
		// Application::log("Has connection to ".$this->getServer());
		// Application::log("oci_error: ".json_encode(oci_error()));

		$stmt = oci_parse($this->connection, $sql);
		if (!$stmt) {
			throw new \Exception("Unable to parse ".$sql.": ".json_encode(oci_error()));
		}

        Application::log($sql);
		oci_execute($stmt);
		// Application::log("Statement returned ".oci_num_rows($stmt)." rows");
		if ($error = oci_error($stmt)) {
			throw new \Exception("Unable to execute statement. ".json_encode($error));
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
		$file = "/app001/credentials/career_dev/coeusDB.php";
		if (file_exists($file)) {
			include($file);
			$usedFile = $file;
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

	public function sendUseridsToCOEUS($redcapUserids, $records, $pid) {
        $data = $this->pullAllRecords();
        $coeusIds = $data['ids'];
        Application::log("COEUS is pulling ".count($coeusIds)." ids", $pid);
        $idsToAdd = [];
        foreach ($records as $recordId) {
            $userid = $redcapUserids[$recordId];
            if ($userid && !in_array($userid, $coeusIds)) {
                $idsToAdd[] = $userid;
            }
        }
        if (!empty($idsToAdd)) {
            Application::log("Inserting ".count($idsToAdd)." ids", $pid);
            $this->insertNewIds($idsToAdd);
            $data = $this->pullAllRecords();
            $coeusIds = $data['ids'];
            Application::log("COEUS is now pulling ".count($coeusIds)." ids", $pid);
        } else {
            Application::log("No new ids to upload", $pid);
        }
    }

	public function insertNewIds($ids) {
	    $rowsOfRows = [];
	    $row = [];
	    $limit = 1;
	    foreach ($ids as $id) {
	        $row[] = $id;
	        if (count($row) == $limit) {
	            $rowsOfRows[] = $row;
	            $row = [];
            }
        }
	    if (!empty($row)) {
	        $rowsOfRows[] = $row;
        }

	    foreach ($rowsOfRows as $row) {
	        if (!empty($row)) {
                $sql = "INSERT INTO SRIADM.SRI_CAREER (CAREER_VUNET, CAREER_ACTIVE) VALUES ('".implode("', '1'),('", $row)."', '1')";
                $this->query($sql);
            }
        }
	}

    public function pullAwards() {
		$sql = "SELECT * FROM SRIADM.RC_AWARDS_VW INNER JOIN SRIADM.RC_AWARD_INVESTIGATORS_VW ON (RC_AWARD_INVESTIGATORS_VW.AWARD_NO = RC_AWARDS_VW.AWARD_NO) and (RC_AWARD_INVESTIGATORS_VW.AWARD_SEQ = RC_AWARDS_VW.AWARD_SEQ) WHERE PI_FLAG = 'Y'";
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

	public function pullProposals() {
	    $sql = "SELECT * FROM SRIADM.RC_IP_VW INNER JOIN SRIADM.RC_IP_INVESTIGATORS_VW ON RC_IP_INVESTIGATORS_VW.IP_NUMBER = RC_IP_VW.IP_NUMBER";
	    return $this->query($sql);
    }

	public function pullAllRecords() {
		$data = [];

		$data["awards"] = $this->pullAwards();
		$sql = "SELECT * FROM SRIADM.SRI_CAREER WHERE CAREER_ACTIVE = 1";
		$data["membership"] = $this->query($sql);
		$data["ids"] = [];
		foreach ($data["membership"] as $row) {
		    $data["ids"][] = $row['CAREER_VUNET'];
        }
		$data["proposals"] = $this->pullProposals();

		return $data;
	}

	private $userid;
	private $passwd;
	private $server;
}
