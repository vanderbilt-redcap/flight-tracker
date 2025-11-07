<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class VICTRPubMedConnection extends OracleConnection {
    public function __construct() {
        $userid = "";
        $passwd = "";
        $serverAddress = "";
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

