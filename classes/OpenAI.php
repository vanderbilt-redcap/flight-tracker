<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class OpenAI
{
	public const ENDPOINT = "https://vumc-openai-24.openai.azure.com/";
	public const NO_DATA = "No data";
	public const NUM_KEYWORDS = 5;
	public const CITATION_KEYWORD_FIELD = "citation_ai_keywords";
	public const SEPARATOR = ";";
	public const NUM_TOPIC_KEYWORDS = 7;

	public function __construct(int $pid) {
		$this->pid = $pid;
		$this->apiKey = self::getAPIKey();
		$this->setDeployment("OpenAI-24");
	}

	public function setDeployment(string $deploymentId): void {
		$this->deploymentId = $deploymentId;
	}

	public static function implodeKeywords(array $keywords): string {
		if (empty($keywords)) {
			return "";
		}
		$separator = self::SEPARATOR." ";
		return implode($separator, $keywords);
	}

	public static function explodeKeywords(string $keywordString): array {
		if ($keywordString === "") {
			return [];
		}
		$separator = self::SEPARATOR." ";
		return explode($separator, $keywordString);
	}

	private static function getMessage(array $response, int $index = 0): string {
		return $response["choices"][$index]["message"]["content"] ?? $response["error"]["message"] ?? self::NO_DATA;
	}

	public function searchForAlternateTopics(array $topics): array {
		$results = [];
		foreach ($topics as $topic) {
			$prompt = "As a scientist, list the ".self::NUM_TOPIC_KEYWORDS." scientific keywords without descriptions most closely related to $topic";
			$response = $this->submitPrompt($prompt);
			$text = self::getMessage($response);
			if (isset($_GET['test'])) {
				echo "<p>$topic<br/>$text</p>";
			}
			if ($text != self::NO_DATA) {
				foreach (self::makeIntoArray($text) as $keyword) {
					if (!in_array($keyword, $results)) {
						$results[] = $keyword;
					}
				}
			}
		}
		return $results;
	}

	public function getPublicationKeywords(array $titles, array $abstracts, int $numKeywords): array {
		if (empty($titles) || empty($abstracts) || (count($titles) != count($abstracts))) {
			return [];
		}
		$prompts = [];
		foreach ($titles as $i => $title) {
			$abstract = $abstracts[$i];
			$prompts[] = "As a scientist, list this publications main $numKeywords scientific keywords without descriptions.\nPublication title: $title\nPublication abstract: $abstract";
		}
		$results = [];
		foreach ($prompts as $i => $prompt) {
			if ($i !== 0) {
				usleep(500000);  // rate limiter
			}
			$response = $this->submitPrompt($prompt);
			$text = self::getMessage($response);
			if (isset($_GET['test'])) {
				$abstract = $abstracts[$i];
				$title = $titles[$i];
				echo "<p>$title<br/>$abstract<br/>$text</p>";
			}
			if ($text != self::NO_DATA) {
				$results[] = self::makeIntoArray($text);
			} else {
				$results[] = [$text];
			}
		}
		return $results;
	}

	private static function makeIntoArray(string $text): array {
		$lines = preg_split("/[\r\n]+/", $text, -1, PREG_SPLIT_NO_EMPTY);
		foreach ($lines as $i => $line) {
			$lines[$i] = preg_replace("/^\d+\.\s+/", "", $line);
		}
		return $lines;
	}

	private function submitPrompt(string $message): array {
		$postdata = ["messages" => [["role" => "system", "content" => $message]]];
		$opts = [
			CURLOPT_HTTPHEADER => [
				"api-key: ".$this->apiKey,
			],
		];

		$url = self::ENDPOINT."openai/deployments/".$this->deploymentId."/chat/completions?api-version=2024-02-01";
		list($resp, $json) = self::downloadURLWithPOST($url, $postdata, $this->pid, $opts, 3, 2, "json");
		$response = json_decode($json, true);
		if ($resp == 200) {
			return $response;
		} elseif (isset($response['error']['message'])) {
			return ["code" => $resp, "error" => $response['error'] ];
		} else {
			return ["code" => $resp, "error" => ["message" => $json]];
		}
	}

	public static function getAPIKey(): string {
		$apiKey = "";
		$credentialsDir = Application::getCredentialsDir();
		if (!$credentialsDir) {
			die("Invalid credentials");
		}
		include($credentialsDir."/career_dev/openai.php");
		if (!$apiKey) {
			throw new \Exception("No API Key!");
		}
		return $apiKey;
	}

	private static function getDefaultCURLOpts(int $pid): array {
		return [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_VERBOSE => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_AUTOREFERER => true,
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_FRESH_CONNECT => 1,
			CURLOPT_TIMEOUT => 120,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_SSL_VERIFYPEER => true,
		];
	}

	public static function downloadURLWithPOST(string $url, array $postdata = [], int $pid = -1, array $addlOpts = [], int $autoRetriesLeft = 3, int $longRetriesLeft = 2, string $defaultFormat = "json"): array {
		if (!Application::isLocalhost()) {
			Application::log("Contacting $url", $pid);
		}
		if (!empty($postdata)) {
			Application::log("Posting to $url", $pid);
		}
		$url = Sanitizer::sanitizeURL($url);
		$url = REDCapManagement::changeSlantedQuotes($url);
		if (!$url) {
			throw new \Exception("Invalid URL!");
		}
		if ($pid < 0) {
			if (isset($_GET['pid']) && is_numeric($_GET['pid'])) {
				$pid = (int) $_GET['pid'];
			} else {
				$pids = Application::getActivePids();
				if (empty($pids)) {
					throw new \Exception("No active PIDs!");
				} else {
					$pid = (int) $pids[0];
				}
			}
		}
		$defaultOpts = self::getDefaultCURLOpts($pid);
		$time1 = microtime();
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		foreach ($defaultOpts as $opt => $value) {
			if (!isset($addlOpts[$opt])) {
				curl_setopt($ch, $opt, $value);
			}
		}
		foreach ($addlOpts as $opt => $value) {
			if ($opt != CURLOPT_HTTPHEADER) {
				curl_setopt($ch, $opt, $value);
			}
		}
		if (!empty($postdata)) {
			$postdata = REDCapManagement::changeSlantedQuotesInArray($postdata);
			if ($defaultFormat == "json") {
				if (is_string($postdata)) {
					$json = $postdata;
				} else {
					$json = json_encode($postdata);
				}
				$json = Sanitizer::sanitizeJSON($json);
				if (!$json) {
					throw new \Exception("Invalid POST parameters!");
				}
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
				curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
				curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge([
					'Content-Type: application/json',
				], $addlOpts[CURLOPT_HTTPHEADER] ?? []));
			} else {
				throw new \Exception("Unknown format $defaultFormat");
			}
		}

		$data = (string) curl_exec($ch);
		$resp = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		if (curl_errno($ch)) {
			Application::log("Error number ".curl_errno($ch)." cURL Error: ".curl_error($ch), $pid);
			if ($autoRetriesLeft > 0) {
				sleep(30);
				Application::log("Retrying ($autoRetriesLeft left)...", $pid);
				list($resp, $data) = self::downloadURLWithPOST($url, $postdata, $pid, $addlOpts, $autoRetriesLeft - 1, $longRetriesLeft, $defaultFormat);
			} elseif ($longRetriesLeft > 0) {
				sleep(300);
				Application::log("Retrying ($longRetriesLeft long retries left)...", $pid);
				list($resp, $data) = self::downloadURLWithPOST($url, $postdata, $pid, $addlOpts, 0, $longRetriesLeft - 1, $defaultFormat);
			} else {
				Application::log("Error: ".curl_error($ch), $pid);
				throw new \Exception(curl_error($ch));
			}
		}
		curl_close($ch);
		$time2 = microtime();
		$timeStmt = "";
		if (is_numeric($time1) && is_numeric($time2)) {
			$timeStmt = " in ".(($time2 - $time1) / 1000)." seconds";
		}
		Application::log("$url Response code $resp; ".strlen($data)." bytes".$timeStmt, $pid);
		if (Application::isVanderbilt()) {
			if (strlen($data) < 1000) {
				Application::log("Result: ".$data, $pid);
			}
		}
		return [$resp, $data];
	}

	protected $apiKey;
	protected $pid;
	protected $deploymentId;
}
