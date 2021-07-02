<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class iCite {
	public function __construct($pmids, $pid) {
		$this->pmids = $pmids;
		$this->pid = $pid;
		$this->data = self::getData($pmids, $pid);
	}

	private static function getData($pmids, $pid) {
	    if (!is_array($pmids)) {
	        $pmids = [$pmids];
        }
	    $maxSize = 10;
	    for ($i = 0; $i < count($pmids); $i += $maxSize) {
	        $queue = [];
	        for ($j = $i; ($j < count($pmids)) && ($j < $i + $maxSize); $j++) {
	            $queue[] = $pmids[$j];
            }
        }
		$url = "https://icite.od.nih.gov/api/pubs?pmids=".implode(",", $queue)."&format=json";
		list($resp, $json) = REDCapManagement::downloadURL($url, $pid);
		Application::log("iCite ".$url.": $resp", $pid);

		$data = json_decode($json, true);
		if (!$data || !$data['data'] || count($data['data']) == 0) {
			return array();
		}
		// Application::log(json_encode($data['data'][0]));
		return $data['data'];
	}

	public function getPMIDs() {
		return $this->pmids;
	}

	public function getVariable($pmid, $var) {
	    foreach ($this->data as $datum) {
	        if ($datum['pmid'] == $pmid) {
                if (isset($datum[$var])) {
                    if ($var == "is_research_article") {
                        if ($datum["is_research_article"]) {
                            return "1";
                        } else {
                            return "0";
                        }
                    }
                    return strval($datum[$var]);
                }
            }
        }
		return "";
	}

	public function hasData($pmid = NULL) {
	    if ($pmid) {
	        if (!$this->data) {
	            return FALSE;
            }
	        foreach ($this->data as $datum) {
	            if ($datum['pmid'] == $pmid) {
	                return TRUE;
                }
            }
	        return FALSE;
        } else {
            return $this->data && !empty($this->data);
        }
    }

	private $pmids;
	private $data;
	private $pid;
}
