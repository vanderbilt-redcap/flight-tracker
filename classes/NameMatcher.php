<?php

namespace Vanderbilt\CareerDevLibrary;


# This class handles commonly occuring downloads from the REDCap API.

// require_once(dirname(__FILE__)."/../../../redcap_connect.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/Download.php");

class NameMatcher {
    public static function matchLastName($lastName, $token, $server) {
        if (!$lastName) {
            return [];
        }
        if (!self::$namesForMatch || empty(self::$namesForMatch) || ($token != self::$currToken)) {
            self::downloadNamesForMatch($token, $server);
        }

        $names = [];
        $lastName = strtolower($lastName);
        foreach (self::$namesForMatch as $row) {
            $sLast = strtolower($row['identifier_last_name']);
            if (preg_match("/".$sLast."/", $lastName) || preg_match("/".$lastName."/", $sLast)) {
                $names[$row['record_id']] = $row['identifier_first_name']." ".$row['identifier_last_name'];
            }
        }
        return $names;
    }

	public static function matchNames($firsts, $lasts, $token, $server) {
		if (!$firsts || !$lasts) {
			return "";
		}
		if (!self::$namesForMatch || empty(self::$namesForMatch) || ($token != self::$currToken)) {
			self::downloadNamesForMatch($token, $server);
		}
		$recordIds = array();
		for ($i = 0; $i < count($firsts) && $i < count($lasts); $i++) {
			$myFirst = self::prepareName($firsts[$i]);
			$myLast = self::prepareName($lasts[$i]);
			$found = false;
			if ($myFirst && $myLast) {
                foreach (self::$namesForMatch as $row) {
                    $sFirst = self::prepareName($row['identifier_first_name']);
                    $sLast = self::prepareName($row['identifier_last_name']);
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
            }
			if (!$found) {
				$recordIds[] = "";
			}
		}
		return $recordIds;
	}

	private static function prepareName($name) {
        $name = strtolower($name);
        $name = preg_replace("/\s+/", "-", $name);
        return $name;
    }

	public static function explodeFirstName($first) {
	    $nodes = preg_split("/\s*\(\s*/", $first);
	    $newNodes = array();
	    foreach ($nodes as $node) {
	        $node = preg_replace("/\)/", "", $node);
	        if ($node && !in_array($node, $newNodes)) {
                array_push($newNodes, $node);
            }
        }
	    return $newNodes;
    }

    public static function explodeLastName($last) {
	    $nodes = preg_split("/[\s\-]+/", $last);
	    $newNodes = array($last);
	    foreach ($nodes as $node) {
	        if ($node && !in_array($node, $newNodes)) {
	            array_push($newNodes, $node);
            }
        }
	    return $newNodes;
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
        if ($first && $last) {
            $ary = self::matchNames(array($first), array($last), $token, $server);
            if (count($ary) > 0) {
                return $ary[0];
            }
        }
		return "";
	}

	# case insensitive match based on last name and first initial only
	# returns TRUE/FALSE
	public static function matchByInitials($lastName1, $firstName1, $lastName2, $firstName2) {
		$lastName1 = strtolower($lastName1);
		$firstName1 = strtolower($firstName1);
		$lastName2 = strtolower($lastName2);
		$firstName2 = strtolower($firstName2);

		$firstInitial1 = self::turnIntoOneInitial($firstName1);
		$firstInitial2 = self::turnIntoOneInitial($firstName2);

		return (($lastName1 == $lastName2) && ($firstInitial1 == $firstInitial2));
	}

	public static function turnIntoOneInitial($name) {
		if (strlen($name) >= 1) {
			return substr($name, 0, 1);
		}
		return "";
	}

	public static function pretty($name) {
		list($first, $last) = self::splitName($name);
		return "$first $last";
	}

	# returns list($firstName, $lastName)
	public static function splitName($name) {
		if ($name == "") {
			return array("", "");
		}
		$nodes = preg_split("/\s*,\s*/", $name);
		if (count($nodes) == 1) {
            $nodes = preg_split("/\s*\band\b\s*/", $name);
        }
		if (count($nodes) >= 2) {
			# last-name, first-name
			return array($nodes[1], $nodes[0]);
		} else if (count($nodes) == 1) {
			$nodes = preg_split("/\s+/", $name);
			if (count($nodes) > 2) {
			    if (strlen($nodes[1]) <= 2) {
                    return array($nodes[0], $nodes[2]);
                } else {
                    return array($nodes[0], $nodes[1]);
                }
            } else if (count($nodes) == 2) {
                return array($nodes[0], $nodes[1]);
			} else if (count($nodes) == 1) {
				return array($nodes[0], "");
			} else {
				throw new \Exception("Name-splitter could not read $name!");
			}
		}
		return array("", "");
	}

	private static $namesForMatch = array();
	private static $currToken = "";
}
