<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class COEUSConnection extends OracleConnection {
    public function __construct() {
        $userid = "";
        $passwd = "";
        $serverAddress = "";

        $usedFile = "None";
        $file = Application::getCredentialsDir()."/career_dev/coeusDB.php";
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
            if (isset($redcapUserids[$recordId])) {
                if (is_array($redcapUserids[$recordId])) {
                    $userids = $redcapUserids[$recordId];
                    foreach ($userids as $userid) {
                        $userid = strtolower($userid);
                        if ($userid && !in_array($userid, $coeusIds) && !in_array($userid, $idsToAdd)) {
                            $idsToAdd[] = $userid;
                        }
                    }
                } else if (is_string($redcapUserids[$recordId])) {
                    $userid = strtolower($redcapUserids[$recordId]);
                    if ($userid && !in_array($userid, $coeusIds) && !in_array($userid, $idsToAdd)) {
                        $idsToAdd[] = $userid;
                    }
                } else {
                    throw new \Exception("Wrong data type: ".json_encode($redcapUserids[$recordId]));
                }
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
