<?php

namespace Vanderbilt\CareerDevLibrary;


# This class handles commonly occuring downloads from the REDCap API.

// require_once(dirname(__FILE__)."/../../../redcap_connect.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/Download.php");

class NameMatcher {
	# returns an array of recordId's that matches respective last, first
	# returns "" if no match
	public static function matchNames($firsts, $lasts, $token, $server) {
		if (!$firsts || !$lasts) {
			return "";
		}
		if (!self::$namesForMatch || empty(self::$namesForMatch) || ($token != self::$currToken)) {
			self::downloadNamesForMatch($token, $server);
		}
		$recordIds = array();
		for ($i = 0; $i < count($firsts) && $i < count($lasts); $i++) {
			$myFirst = strtolower($firsts[$i]);
			$myLast = strtolower($lasts[$i]);
			$found = false;
			foreach (self::$namesForMatch as $row) {
				$sFirst = strtolower($row['identifier_first_name']);
				$sLast = strtolower($row['identifier_last_name']);
				$matchFirst = false;
				if (preg_match("/".$sFirst."/", $myFirst) || preg_match("/".$myFirst."/", $sFirst)) {
					$matchFirst = true;
				}
				$matchLast = false;
				if (preg_match("/".$sLast."/", $myLast) || preg_match("/".$myLast."/", $sLast)) {
					$matchLast = true;
				}
				if ($matchFirst && $matchLast) {
					$recordIds[] = $row['record_id'];
					$found = true;
					break;
				}
			}
			if (!$found) {
				$recordIds[] = "";
			}
		}
		return $recordIds;
	}

	public static function downloadNamesForMatch($token, $server) {
		Application::log("Downloading new names for pid ".Application::getPID($token));
		self::$namesForMatch = Download::fields($token, $server, array("record_id", "identifier_first_name", "identifier_last_name"));
		self::$currToken = $token;
		return self::$namesForMatch;
	}

	# returns recordId that matches
	# returns "" if no match
	public static function matchName($first, $last, $token, $server) {
		$ary = self::matchNames(array($first), array($last), $token, $server);
		if (count($ary) > 0) {
			return $ary[0];
		}
		return "";
	}

	private static $namesForMatch = array();
	private static $currToken = "";
}
