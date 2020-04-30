<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/REDCapManagement.php");

class ConnectionStatus {
    public function __construct($name, $server) {
        $this->server = $server;
        $method = "https";
        $this->url = $method."://".$server;
        $this->name = $name;
    }

    public function test() {
        $tests = [];

        $data = file_get_contents($this->url);
        $bytes = strlen($data);
        if ($bytes > 0) {
            $tests['file_get_contents'] = REDCapManagement::pretty($bytes)." bytes returned.";
        } else {
            $tests['file_get_contents'] = "ERROR: No data returned!";
        }

        list($returnCode, $data) = REDCapManagement::downloadURL($this->url);
        $bytes = strlen($data);
        if ($bytes > 0) {
            $tests['downloadURL'] = REDCapManagement::pretty($bytes)." bytes returned with response code of $returnCode.";
        } else {
            $tests['downloadURL'] = "ERROR: No data returned with response code of $returnCode!";
        }

        $timeout = 15;
        $fp = fsockopen($this->server, 80, $errno, $errstr, $timeout);
        if ($fp) {
            $tests['socket'] = "Socked successfully opened.";
            fclose($fp);
        } else {
            $tests['socket'] = "ERROR: Socket not opened ($errno: $errstr)";
        }

        return $tests;
    }

    public function getURL() {
        return $this->url;
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
    private $name;
}
