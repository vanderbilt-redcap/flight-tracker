<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class Workday {
    public function __construct($pid) {
        $this->pid = $pid;
        $this->file = self::getLatestFile();
    }

    public function searchForFirstName($firstName) {
        return $this->searchForOneItem($firstName, "FIRST_NAME");
    }

    public function searchForLastName($lastName) {
        return $this->searchForOneItem($lastName, "LAST_NAME");
    }

    private function searchForOneItem($name, $headerLabel) {
        $name = strtolower(trim($name));
        if ($name === "") {
            return [];
        }
        $matchedLines = [];
        $headers = [];
        if (file_exists($this->file)) {
            $fp = fopen($this->file, "r");
            $headers = fgetcsv($fp);
            $index = self::getIndexInArray($headerLabel, $headers);
            if ($index >= 0) {
                while ($line = fgetcsv($fp)) {
                    $lineName = strtolower($line[$index] ?? "");
                    if ($lineName === $name) {
                        $matchedLines[] = $line;
                    }
                }
            }
        }
        Application::log("Search for $name in $headerLabel yielded ".count($matchedLines), $this->pid);
        return self::shareData($matchedLines, $headers);
    }

    public function searchForName($firstName, $lastName) {
        $matchedLines = [];
        $headers = [];
        if (file_exists($this->file)) {
            $fp = fopen($this->file, "r");
            $headers = fgetcsv($fp);
            $lastNameIndex = self::getIndexInArray("LAST_NAME", $headers);
            $firstNameIndex = self::getIndexInArray("FIRST_NAME", $headers);
            if (($firstNameIndex >= 0) && ($lastNameIndex >= 0)) {
                while ($line = fgetcsv($fp)) {
                    $lineFN = $line[$firstNameIndex] ?? "";
                    $lineLN = $line[$lastNameIndex] ?? "";
                    if (NameMatcher::matchName($lineFN, $lineLN, $firstName, $lastName)) {
                        $matchedLines[] = $line;
                    }
                }
            }
        }
        return self::shareData($matchedLines, $headers);
    }

    public static function getViewableFields() {
        return [
            "Employee ID" => "EMPLID",
            "User ID" => "VUNETID",
            "Email Address" => "EMAIL_ADDRESS",
            "First Name" => "FIRST_NAME",
            "Last Name" => "LAST_NAME",
            "Address Line 1" => "ADDRESS_LINE_1",
            "Address Line 2" => "ADDRESS_LINE_2",
            "Vanderbilt Phone" => "VU_PHONE",
            "Zip" => "ZIP",
            "Business Phone" => "BUSINESS_PHONE",
            "Hire Date/Time" => "HIRE_DT",
            "Full-Time Employee" => "FTE",
            "Region" => "REG_REGION",
            "Department ID" => "DEPTID",
            "Department Description" => "DEPT_DESCR",
            "Job Description" => "job_description",
            "Job Function" => "JOB_FUNCTION",
            "Job Family" => "JOB_FAMILY",
            "Adjusted Manager Level" => "ADJUSTED_MANAGER_LEVEL",
            "Department Manager Name" => "HD_DEPT_MANAGER_NAME",
            "Department Manager ID" => "HD_DEPT_MANAGER_ID",
        ];
    }

    private static function shareData($matchedLines, $headers) {
        if (empty($headers) || empty($matchedLines)) {
            return [];
        }

        # for security, fields have been cleared with Kyle McGuffin in 1:1 conversation
        $viewableColumns = self::getViewableFields();
        $assocArys = [];
        foreach ($matchedLines as $line) {
            $row = [];
            foreach ($viewableColumns as $descr => $id) {
                $index = self::getIndexInArray($id, $headers);
                if (isset($line[$index])) {
                    $row[$descr] = $line[$index];
                }
            }
            if (!empty($row)) {
                $assocArys[] = $row;
            }
        }
        return $assocArys;
    }

    public function searchForUserid($userid) {
        return $this->searchForOneItem($userid, "VUNETID");
    }

    public static function getIndexInArray($col, $ary) {
        foreach ($ary as $i => $val) {
            if ($val == $col) {
                return $i;
            }
        }
        return -1;
    }

    public static function getLatestFile() {
        $location = NULL;
        $prefix = NULL;
        $credentialsFile = Application::getCredentialsDir()."/career_dev/workday.php";
        if (file_exists($credentialsFile)) {
            include($credentialsFile);
            if (isset($location) && isset($prefix)) {
                $ts = strtotime("-1 day");
                return "$location/$prefix".date("Ymd", $ts).".csv";
            }
        }
        return "";
    }

    protected $pid;
    protected $file;
}