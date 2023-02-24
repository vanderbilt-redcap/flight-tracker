<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class ConnectionStatus {
    public function __construct($server, $pid) {
        $this->server = $server;
        $method = "https";
        $this->url = $method."://".$server;
        $this->pid = $pid;
    }

    private function testDownloadURL(&$tests) {
        list($returnCode, $data) = REDCapManagement::downloadURL($this->url, $this->pid);
        $bytes = strlen($data);
        if ($bytes > 0) {
            $tests['downloadURL'] = REDCapManagement::pretty($bytes)." bytes returned with response code of $returnCode.";
        } else {
            $tests['downloadURL'] = "ERROR: No data returned with response code of $returnCode!";
        }
    }

    public function testFileGetContents(&$tests) {

        /*
         * Disabled due to security hole using file_get_contents
         *
        $data = file_get_contents($this->url);
        $bytes = strlen($data);
        if ($bytes > 0) {
            $tests['file_get_contents'] = REDCapManagement::pretty($bytes)." bytes returned.";
        } else {
            $tests['file_get_contents'] = "ERROR: No data returned!";
        }
        */
    }

    public function testSocket(&$tests) {
        $timeout = 15;
        $fp = fsockopen($this->server, 80, $errno, $errstr, $timeout);
        if ($fp) {
            $tests['socket'] = "Socked successfully opened.";
            fclose($fp);
        } else {
            $tests['socket'] = "ERROR: Socket not opened ($errno: $errstr)";
        }
    }

    public function test() {
        $tests = [];

        $this->testDownloadURL($tests);

        # disabled because extraneous tests - downloadURL is the only method used to access the web
        // $this->testFileGetContents($tests);
        // $this->testSocket($tests);

        return $tests;
    }

    public function getURL() {
        return $this->url;
    }

    # coordinated with encodeName in drivers/14_connectivity.php
    public static function encodeName($str) {
        $str = strtolower($str);
        $str = preg_replace("/\s+/", "_", $str);
        $str = preg_replace("/^[^a-z]+|[^\w:\.\-]+/", "", $str);
        return $str;
    }

    public static function formatResultsInHTML($title, $results) {
        $html = "";
        $html .= "<h2>$title</h2>\n";
        $html .= "<div class='centered bordered shadow' style='max-width: 500px; margin 0 auto;'>\n";
        foreach ($results as $key => $result) {
            if (preg_match("/error/i", $result)) {
                $currClass = "red";
            } else {
                $currClass = "green";
            }
            $html .= "<p class='$currClass centered' style='max-width: 500px; margin: 0 auto;'><b>$key</b>: $result</p>\n";
        }
        $html .= "</div>\n";
        return $html;
    }

    private $url;
    private $server;
    private $pid;
}
