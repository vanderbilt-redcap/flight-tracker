<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class Sanitizer
{
	/**
	 * @psalm-taint-specialize
	 */
	public static function sanitizeJSON($str) {
		$data = json_decode($str, true);
		if ($data) {
			$data = self::sanitizeRecursive($data, false, false);
			return json_encode($data);
		}
		return "";
	}

	/**
	 * @psalm-taint-specialize
	 */
	public static function sanitizeREDCapData($data) {
		$data = self::sanitizeArray($data, false, false);
		for ($i = 0; $i < count($data); $i++) {
			if (isset($data[$i]['record_id'])) {
				$data[$i]['record_id'] = self::sanitizeInteger($data[$i]['record_id']);
			}
		}
		return $data;
	}

	public static function decodeSpecialHTML(&$a) {
		if (is_array($a)) {
			foreach (array_keys($a) as $i) {
				# handle HTML escaping; include curly quotes
				# Many early projects accidentally stored escaped quotes in their database and need to be decoded
				# This should not have to be heavily used with projects after 11/2023
				$a[$i] = str_replace("&amp;", "&", $a[$i]);
				$a[$i] = str_replace("&quot;", "\"", $a[$i]);
				$a[$i] = str_replace("&apos;", "'", $a[$i]);
				$singleQuoteItems = ["#039", "#39", "#8216", "#8217"];
				$doubleQuoteItems = ["#8220", "#8221"];
				$replacements = [
					"%27" => $singleQuoteItems,
					"%22" => $doubleQuoteItems,
				];
				foreach ($replacements as $replacement => $items) {
					foreach ($items as $escapedQuote) {
						if (preg_match("/&$escapedQuote;/", $a[$i])) {
							$a[$i] = str_replace("&$escapedQuote;", $replacement, $a[$i]);
						} elseif (preg_match("/&$escapedQuote\D/", $a[$i])) {
							$a[$i] = str_replace("&$escapedQuote", $replacement, $a[$i]);
						} elseif (preg_match("/$escapedQuote;/", $a[$i])) {
							$a[$i] = str_replace("$escapedQuote;", $replacement, $a[$i]);
						} elseif (preg_match("/$escapedQuote\D/", $a[$i])) {
							$a[$i] = str_replace($escapedQuote, $replacement, $a[$i]);
						}
					}
				}
			}
		} else {
			$ary = [$a];
			self::decodeSpecialHTML($ary);
			if (!empty($ary)) {
				$a = $ary[0];
			}
		}
	}

	public static function repetitivelyDecodeHTML($entity, $depth = 1) {
		if (is_array($entity)) {
			foreach ($entity as $key => $value) {
				$entity[$key] = self::repetitivelyDecodeHTML($value);
			}
			return $entity;
		} else {
			$original = $entity;
			if (preg_match("/&[A-Za-z\d#]+,/", $entity)) {
				$semicolonEntity = preg_replace("/(&[A-Za-z\d#]+),/", '$1;', $entity);
				$decoded = self::decodeHTML($semicolonEntity);
			} else {
				$decoded = self::decodeHTML($entity);
			}
			$maxDepth = 50;
			if (($original == $decoded) || ($depth > $maxDepth)) {
				return $decoded;
			} else {
				return self::repetitivelyDecodeHTML($decoded, $depth + 1);
			}
		}
	}

	public static function sanitizeToken($token) {
		if (REDCapManagement::isValidToken($token)) {
			return self::sanitize($token);
		}
		return "";
	}

	public static function sanitizeNumber($num) {
		if (is_integer($num)) {
			return self::sanitizeInteger($num);
		} elseif (is_numeric($num)) {
			return (float) self::sanitize($num);
		} else {
			return "";
		}
	}

	public static function sanitizeInteger($int) {
		if (filter_var($int, FILTER_VALIDATE_INT) !== false) {
			$stringVersion = self::sanitize($int);
			return (int) $stringVersion;
		} else {
			return "";
		}
	}

	/**
	 * @psalm-taint-specialize
	 */
	public static function sanitizeURL($url) {
		$url = filter_var($url, FILTER_SANITIZE_URL);
		if (!$url) {
			throw new \Exception("Invalid URL!");
		} else {
			return $url;
		}
	}

	/**
	 * @psalm-taint-specialize
	 */
	public static function sanitizePid($pid) {
		$pid = filter_var($pid, FILTER_VALIDATE_INT);
		$pid = self::sanitize($pid);
		return $pid;
	}

	/**
	 * @psalm-taint-specialize
	 */
	private static function sanitizeRecursive($datum, $encodeQuotes = true, $stripHTML = true) {
		if (is_array($datum)) {
			$newData = [];
			foreach ($datum as $key => $value) {
				if ($encodeQuotes && $stripHTML) {
					$key = self::sanitize($key);
				} elseif (!$stripHTML) {
					$key = self::sanitizeWithoutStrippingHTML($key, $encodeQuotes);
				} else {
					$key = self::sanitizeWithoutChangingQuotes($key);
				}
				$newData[$key] = self::sanitizeRecursive($value, $encodeQuotes, $stripHTML);
			}
			return $newData;
		} elseif ($encodeQuotes && $stripHTML) {
			return self::sanitize($datum);
		} elseif (!$stripHTML) {
			return self::sanitizeWithoutStrippingHTML($datum, $encodeQuotes);
		} else {
			return self::sanitizeWithoutChangingQuotes($datum);
		}
	}

	/**
	 * @psalm-taint-specialize
	 */
	public static function sanitizeDate($date) {
		$date = self::sanitize($date);
		if (!$date) {
			return "";
		}
		if (DateManagement::isDate($date)) {
			return $date;
		} else {
			throw new \Exception("Invalid date $date");
		}
	}

	/**
	 * @psalm-taint-specialize
	 */
	# Used in cases where $str must be escaped so that it can be used in APIs or searching API data
	# This should NOT be used in cases where $str is only displayed to the screen to avoid spurious alerts
	# In most cases, Santizer::sanitize() should be used instead
	public static function sanitizeWithoutChangingQuotes($str) {
		if (is_numeric($str)) {
			$str = (string) $str;
		}
		if (!is_string($str)) {
			return "";
		}

		$quotesInHTML = [
			"&lsquo;" => "'",
			"&rsquo;" => "'",
			"&#8216;" => "'",
			"&#8217;" => "'",
			"&#39;" => "'",
			"&#039;" => "'",
			"&apos;" => "'",
			"&quot;" => '"',
			"&ldquo;" => '"',
			"&rdquo;" => '"',
			"&#8220;" => '"',
			"&#8221;" => '"',
			"&#34;" => '"',
			"&#034;" => '"',
			"&amp;" => "&",
		];

		$str = htmlspecialchars($str, ENT_QUOTES);
		foreach ($quotesInHTML as $encoded => $decoded) {
			$str = str_replace($encoded, $decoded, $str);
		}
		return htmlentities($str, ENT_NOQUOTES);
	}

	public static function decodeHTML($entity) {
		$module = Application::getModule();
		return $module->escape($entity);
	}

	/**
	 * @psalm-taint-specialize
	 */
	public static function sanitizeArray($ary, $stripHTML = true, $encodeQuotes = true) {
		if (is_array($ary)) {
			$newAry = [];
			if (REDCapManagement::isAssoc($ary)) {
				foreach ($ary as $key => $value) {
					if ($stripHTML && $encodeQuotes) {
						$key = self::sanitize($key);
					} elseif ($stripHTML && !$encodeQuotes) {
						$key = self::sanitizeWithoutChangingQuotes($key);
					} else {
						$key = self::sanitizeWithoutStrippingHTML($key, $encodeQuotes);
					}
					if (is_array($value)) {
						$value = self::sanitizeArray($value, $stripHTML, $encodeQuotes);
					} elseif ($stripHTML && $encodeQuotes) {
						$value = self::sanitize($value);
					} elseif ($stripHTML && !$encodeQuotes) {
						$value = self::sanitizeWithoutChangingQuotes($value);
					} else {
						$value = self::sanitizeWithoutStrippingHTML($value, $encodeQuotes);
					}
					$newAry[$key] = $value;
				}
			} else {
				foreach ($ary as $value) {
					if (is_array($value)) {
						$value = self::sanitizeArray($value, $stripHTML, $encodeQuotes);
					} elseif ($stripHTML && $encodeQuotes) {
						$value = self::sanitize($value);
					} elseif ($stripHTML && !$encodeQuotes) {
						$value = self::sanitizeWithoutChangingQuotes($value);
					} else {
						$value = self::sanitizeWithoutStrippingHTML($value, $encodeQuotes);
					}
					$newAry[] = $value;
				}
			}
			return $newAry;
		} elseif ($stripHTML && $encodeQuotes) {
			return self::sanitize($ary);
		} elseif ($stripHTML && !$encodeQuotes) {
			return self::sanitizeWithoutChangingQuotes($ary);
		} else {
			return self::sanitizeWithoutStrippingHTML($ary, $encodeQuotes);
		}
	}

	/**
	 * @psalm-taint-specialize
	 */
	public static function sanitizeWithoutStrippingHTML($str, $encodeQuotes = true) {
		$final = filter_tags($str, true, true, true, true);
		# filter_tags() puts spaces before a closing tag that is escaped; this undoes that effect
		# this primarily affects HTML within JSONs
		if ((strpos($final, "< \\/") !== false) && (strpos($str, "< \\/") === false)) {
			$final = str_replace("< \\/", "<\\/", $final);
		}
		return $final;
	}

	/**
	 * @psalm-taint-specialize
	 */
	# This function sanitizes output that is printed to the screen
	public static function sanitizeOutput($str) {
		# for now
		return self::sanitizeArray($str);
	}

	/**
	 * @psalm-taint-specialize
	 */
	public static function sanitizeCohort($cohortName, $pid = null) {
		if (!$pid) {
			$pid = self::sanitizePid($_GET['pid'] ?? "");
		}
		if ($pid) {
			return Cohorts::sanitize($cohortName, $pid);
		} else {
			return "";
		}
	}

	public static function sanitize($origStr) {
		if (REDCapManagement::isValidToken($origStr)) {
			$module = Application::getModule();
			if (method_exists($module, "sanitizeAPIToken")) {
				return $module->sanitizeAPIToken($origStr);
			}
		}
		$module = Application::getModule();
		return $module->escape($origStr);
	}

	# requestedRecord is from GET/POST
	/**
	 * @psalm-taint-specialize
	 */
	public static function getSanitizedRecord($requestedRecord, $records) {
		foreach ($records as $r) {
			if ($r == $requestedRecord) {
				return self::sanitizeInteger($r);
			}
		}
		return "";
	}

	public static function sanitizeLDAP($str) {
		// ldap_escape()
		return self::sanitize($str);
	}


}
