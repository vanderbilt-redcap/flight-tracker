<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/REDCapManagement.php");

class iCite {
	public function __construct($pmid) {
		$this->pmid = $pmid;
		$this->data = self::getData($pmid);
	}

	private static function getData($pmid) {
		$url = "https://icite.od.nih.gov/api/pubs?pmids=".$pmid."&format=json";
		list($resp, $json) = REDCapManagement::downloadURL($url);
		Application::log("iCite ".$url.": $resp");

		$data = json_decode($json, true);
		if (!$data || !$data['data'] || count($data['data']) == 0) {
			return array();
		}
		// Application::log(json_encode($data['data'][0]));
		return $data['data'][0];
	}

	public function getPMID() {
		return $this->pmid;
	}

	public function getVariable($var) {
		if (isset($this->data[$var])) {
			if ($var == "is_research_article") {
				if ($this->data["is_research_article"]) {
					return "1";
				} else {
					return "0";
				}
			}
			return strval($this->data[$var]);
		}
		return "";
	}

	private $pmid;
	private $data;
}
