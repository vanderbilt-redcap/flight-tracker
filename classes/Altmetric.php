<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class Altmetric {
    const THRESHOLD_SCORE = 100;

    public function __construct($doi, $pid) {
        $this->doi = $doi;
        $this->data = self::getData($doi, $pid);
    }

    private static function getData($doi, $pid) {
        $url = "https://api.altmetric.com/v1/doi/".$doi;
        list($resp, $json) = REDCapManagement::downloadURL($url, $pid);

        $data = json_decode($json, true);
        if (!$data || ($resp != 200)) {
            return [];
        }
        return $data;
    }

    public static function makeClickText($thisLink) {
        if (isset($_GET['altmetrics'])) {
            $url = str_replace("&altmetrics", "", $thisLink);
            $clickStatus = "off";
        } else {
            $url = $thisLink."&altmetrics";
            $clickStatus = "on";
        }
        $title = 'Sourced from the Web, altmetrics can tell you a lot about how often journal articles and other scholarly outputs like datasets are discussed and used around the world.';
        return "<h4><a href='$url' title='$title'>Turn $clickStatus Altmetrics</a></h4><p class='centered max-width'>$title</p>";
    }

    public function getVariable($var) {
        if (isset($this->data[$var])) {
            if ($var == "images") {
                return strval($this->data[$var]["small"]);
            } else if (preg_match("/^context_/", $var)) {
                $var = str_replace("higher_than", "higherthan", $var);
                $nodes = explode("_", $var);
                if ((count($nodes) == 3) && isset($this->data[$nodes[0]][$nodes[1]])) {
                    $nodes[1] = str_replace("similarage3m", "similar_age_3m", $nodes[1]);
                    $nodes[1] = str_replace("similaragejournal3m", "similar_age_journal_3m", $nodes[1]);
                    $nodes[2] = str_replace("higherthan", "higher_than", $nodes[2]);
                    return $this->data[$nodes[0]][$nodes[1]][$nodes[2]] ?? "";
                }
            } else {
                return strval($this->data[$var]);
            }
        }
        return "";
    }

    public function hasData() {
        return $this->data && !empty($this->data);
    }

    protected $doi;
    protected $data;
}

