<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class REDCapLookupByUserid {
    public function __construct($userid) {
        $this->userid = $userid;
    }

    public function getEmail() {
        if ($this->userid) {
            $module = Application::getModule();
            $sql = "SELECT user_email FROM redcap_user_information WHERE lower(username) = ?";
            $q = $module->query($sql, [strtolower($this->userid)]);
            if ($row = $q->fetch_assoc($q)) {
                return $row['user_email'];
            }
        }
        return "";
    }

    public function getName() {
        if ($this->userid) {
            $module = Application::getModule();
            $sql = "SELECT user_firstname, user_lastname FROM redcap_user_information WHERE lower(username) = ?";
            $q = $module->query($sql, [strtolower($this->userid)]);
            if ($row = $q->fetch_assoc()) {
                return $row['user_firstname']." ".$row['user_lastname'];
            }
        }
        return "";
    }

    public function getLastName() {
        if ($this->userid) {
            $module = Application::getModule();
            $sql = "SELECT user_lastname FROM redcap_user_information WHERE lower(username) = ?";
            $q = $module->query($sql, [strtolower($this->userid)]);
            if ($row = $q->fetch_assoc()) {
                return $row['user_lastname'];
            }
        }
        return "";
    }

    private $userid = "";
}

