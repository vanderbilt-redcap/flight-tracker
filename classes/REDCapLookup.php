<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class REDCapLookup {
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

    public function getName() {
        return $this->firstName." ".$this->lastName;
    }

    public function getUidsAndNames($showEmails = FALSE) {
        $uids = [];
        if ($showEmails) {
            $sqlField = ", user_email";
        } else {
            $sqlField = "";
        }

        $module = Application::getModule();
        $params = [];
        if (!$this->firstName || !$this->lastName) {
            if (!$this->firstName) {
                $name = $this->lastName;
            } else {
                $name = $this->firstName;
            }
            $params = [strtolower($name), strtolower($name)];
            $sql = "SELECT username, user_firstname, user_lastname $sqlField FROM redcap_user_information WHERE lower(user_firstname) = ? OR lower(user_lastname) = ?";
        } else {
            $firstNames = NameMatcher::explodeFirstName($this->firstName);
            if (count($firstNames) > 1) {
                foreach ($firstNames as $firstName) {
                    if ($firstName && !NameMatcher::isInitial($firstName)) {
                        $params2 = [strtolower($this->firstName), strtolower($this->lastName)];
                        $sql2 = "SELECT username, user_firstname, user_lastname $sqlField FROM redcap_user_information WHERE lower(user_firstname) = ? AND lower(user_lastname) = ?";
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
                $sql = "SELECT username, user_firstname, user_lastname $sqlField FROM redcap_user_information WHERE lower(user_firstname) = ? AND lower(user_lastname) = ?";
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
        ksort($uids);
        return $uids;
    }

    private static function formatName($firstName, $lastName) {
        if ($firstName && $lastName) {
            return $firstName." ".$lastName;
        } else if (!$firstName) {
            return $lastName;
        } else {
            return $firstName;
        }
    }

    private $firstName = "";
    private $lastName = "";
}

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

