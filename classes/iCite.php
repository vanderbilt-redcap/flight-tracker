<?php

namespace Vanderbilt\CareerDevLibrary;


class iCite {
	public function __construct($pmid) {
		$this->pmid = $pmid;
		$this->data = self::getData($pmid);
	}

	private static function getData($pmid) {
		$url = "https://icite.od.nih.gov/api/pubs?pmids=".$pmid."&format=json";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		$json = curl_exec($ch);
		curl_close($ch);
		error_log("iCite ".$url);

		$data = json_decode($json, true);
		if (!$data || !$data['data'] || count($data['data']) == 0) {
			return array();
		}
		// error_log(json_encode($data['data'][0]));
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
