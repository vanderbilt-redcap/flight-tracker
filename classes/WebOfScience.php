<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class WebOfScience
{
	public function __construct($pid) {
		$this->pid = $pid;
		list($this->userid, $this->passwd) = self::getCredentials($pid);
	}

	private static function batchPMIDs($pmids, $maxSize) {
		$batched = [];
		while (count($pmids) > $maxSize) {
			$i = 0;
			$currentGroup = [];
			$newPmids = [];
			foreach ($pmids as $pmid) {
				if ($i < $maxSize) {
					$currentGroup[] = $pmid;
				} else {
					$newPmids[] = $pmid;
				}
				$i++;
			}
			$pmids = $newPmids;
			$batched[] = $currentGroup;
		}
		$batched[] = $pmids;
		return $batched;
	}

	public function getData($pmids) {
		if ($this->userid && $this->passwd) {
			$batchedPmids = self::batchPMIDs($pmids, 50);

			$data = [];
			foreach ($batchedPmids as $pmids) {
				if (empty($pmids)) {
					Application::log("No PMIDs", $this->pid);
				} else {
					$maxTries = 2;
					$tryNum = 0;
					$done = false;
					$values = [];
					while (($tryNum <= $maxTries) && !$done) {
						$tryNum++;
						Application::log("Web of Science Downloading for ".count($pmids)." PMIDs", $this->pid);
						$xml = $this->makeXML($pmids);
						// Application::log("Uploading ".$xml, $this->pid);
						$url = 'https://ws.isiknowledge.com/cps/xrpc';

						$curl = curl_init($url);
						curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type: text/xml"]);
						curl_setopt($curl, CURLOPT_POST, true);
						curl_setopt($curl, CURLOPT_POSTFIELDS, $xml);
						curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
						URLManagement::applyProxyIfExists($ch, $this->pid);
						$result = (string) curl_exec($curl);
						if (curl_errno($curl)) {
							throw new \Exception(curl_error($curl));
						}
						curl_close($curl);
						Application::log("Got ".strlen($result)." bytes from ".$url, $this->pid);
						// Application::log($result, $this->pid);

						try {
							$values = $this->parseXML($result);
							Application::log("On try $tryNum, got ".count($values)." values from XML", $this->pid);
							$done = true;
						} catch (\Exception $e) {
							Application::log("parseXML try $tryNum: ".$e->getMessage(), $this->pid);
							sleep(120);    // "wait a couple of minutes in case of error"
						}
					}
					if ($tryNum > $maxTries) {
						throw new \Exception("Exceeded maximum of $maxTries tries");
					}
					foreach ($values as $key => $value) {
						$data[$key] = $value;
					}
					sleep(1);    // rate-limiter
				}
			}
			return $data;
		} else {
			return [];
		}
	}

	private function makeXML($pmids) {
		# http://help.incites.clarivate.com/LAMRService/WebServiceOperationsGroup/requestAPIWoS.html
		$xml = '';
		$xml .= '<?xml version="1.0" encoding="UTF-8" ?>
<request xmlns="http://www.isinet.com/xrpc42" src="app.id=API Demo">
  <fn name="LinksAMR.retrieve">
    <list>
      <map>
        <val name="username">'.$this->userid.'</val>
        <val name="password">'.$this->passwd.'</val>
      </map>
      <map>
        <list name="WOS">
          <val>timesCited</val>
          <val>ut</val>
          <val>doi</val>
          <val>pmid</val>
          <val>sourceURL</val>
          <val>citingArticlesURL</val>
          <val>relatedRecordsURL</val>
        </list>
      </map>
      <map>';
		$i = 1;
		foreach ($pmids as $pmid) {
			$xml .= '
                <map name="cite_' . $i . '">
                  <val name="pmid">' . $pmid . '</val>
                </map>
            ';
			$i++;
		}
		$xml .= '
      </map>
    </list>
  </fn>
</request>';
		return $xml;
	}

	private function parseXML($xmlStr) {
		# http://help.incites.clarivate.com/LAMRService/WebServiceOperationsGroup/responseAPIWoS.html
		$results = [];
		$xml = simplexml_load_string(mb_convert_encoding($xmlStr, 'UTF-8'));
		if (!$xml) {
			throw new \Exception("Error: Cannot create object " . $xmlStr);
		}
		if ($xml->fn && $xml->fn->map) {
			foreach ($xml->fn->map->children() as $map) {
				if ($map->map) {
					$node = [];
					foreach ($map->map->children() as $val) {
						foreach ($val->attributes() as $key => $attributeName) {
							if ((string) $key == "name") {
								$node[(string) $attributeName] = (string) $val;
							}
						}
					}
					if (isset($node['pmid']) && isset($node['timesCited'])) {
						$results[$node['pmid']] = $node['timesCited'];
					}
				}
			}
		} elseif ($xml->fn && $xml->fn->error) {
			$error = (string) $xml->fn->error;
			Application::log("WebOfScience Error: ".$error, $this->pid);
			return [];
		} else {
			throw new \Exception("Could not parse XML!");
		}
		Application::log("Returning results: ".REDCapManagement::json_encode_with_spaces($results), $this->pid);
		return $results;
	}

	public static function getCredentials($pid) {
		# Vanderbilt seems to have cancelled its Web of Science account
		// $userid = "";
		// $passwd = "";
		// $file = Application::getCredentialsDir()."/career_dev/wos.php";
		// if (file_exists($file)) {
		// require($file);
		// return [$userid, $passwd];
		// }
		$userid = Application::getSetting("wos_userid", $pid);
		$passwd = Application::getSetting("wos_password", $pid);
		return [$userid, $passwd];
	}

	public function getTimesCited() {
		return $this->data;
	}

	public function hasData() {
		if ($this->data && !empty($this->data)) {
			foreach ($this->data as $pmid => $timesCited) {
				if ($timesCited) {
					return true;
				}
			}
		}
		return false;
	}

	protected $data;
	protected $userid;
	protected $passwd;
	protected $pid = null;
}
