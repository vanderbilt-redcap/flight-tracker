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
        if ((self::$namesForMatch === NULL) || ($token != self::$currToken)) {
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

    public static function eliminateInitials($name) {
        $name = preg_replace("/\b[A-Z]\b\.?/", "", $name);
        trim($name);
        return $name;
    }

    private static function doNamesMatch($name1, $name2) {
        if (strtolower($name1) == strtolower($name2)) {
            return TRUE;
        }
        $pairs = [
            [$name1, $name2],
            [$name2, $name1],
        ];
        $compoundRegex = "/[\-\s]/";
        if (preg_match($compoundRegex, $name1)) {
            $pairs[] = [
                preg_replace($compoundRegex, "", $name1),
                $name2,
            ];
        }
        if (preg_match($compoundRegex, $name2)) {
            $pairs[] = [
                $name1,
                preg_replace($compoundRegex, "", $name2),
            ];
        }
        if (preg_match($compoundRegex, $name1) && preg_match($compoundRegex, $name2)) {
            $pairs[] = [
                preg_replace($compoundRegex, "", $name1),
                preg_replace($compoundRegex, "", $name2),
            ];
        }
        foreach ($pairs as $pair) {
            $n1 = $pair[0];
            $n2 = $pair[1];
            if (strlen($n1) >= 4) {
                if (preg_match("/".$n1."/i", $n2)) {
                    return TRUE;
                }
            } else if (strtolower($n1) == strtolower($n2)) {
                return TRUE;
            }
        }
        return FALSE;
    }

    public static function explodeAlternates($name) {
        $list = [$name];
        if (preg_match("/'/", $name)) {
            $list[] = preg_replace("/'/", "", $name);
        }
        return $list;
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
            $recordIds[$i] = FALSE;
			if ($myFirst && $myLast) {
                foreach (self::$namesForMatch as $row) {
                    $sFirst = self::prepareName($row['identifier_first_name']);
                    $sLast = self::prepareName($row['identifier_last_name']);
                    $found = FALSE;
                    foreach (self::explodeAlternates($sFirst) as $sFirst) {
                        foreach (self::explodeAlternates($sLast) as $sLast) {
                            if (self::doNamesMatch($sFirst, $myFirst) && self::doNamesMatch($sLast, $myLast)) {
                                $recordIds[$i] = $row['record_id'];
                                $found = TRUE;
                                break;  // inner
                            }
                        }
                        if ($found) {
                            break;
                        }
                    }
                    if ($found) {
                        break;
                    }
                }
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
        $first = preg_replace("/[\(\)]/", "", $first);
	    $nodes = preg_split("/\s+/", $first);
	    $newNodes = array();
	    foreach ($nodes as $node) {
	        if ($node && !in_array($node, $newNodes)) {
                $newNodes[] = $node;
            }
        }
	    return $newNodes;
    }

    public static function explodeLastName($last) {
	    $nodes = preg_split("/[\s\-]+/", $last);
	    $newNodes = array($last);
	    $i = 0;
	    $suffixesInUpper = ["I", "II", "III", "IV", "JR", "JR.", "SR", "SR."];
	    $prefixesInLower = ["van", "von", "de"];
	    foreach ($nodes as $node) {
	        if (in_array(strtoupper($node), $suffixesInUpper) && ($i == 1)) {
	            return [$nodes[0]." ".$nodes[1]];
            } else if (in_array(strtolower($node), $prefixesInLower) && ($i == 0) && (count($nodes) >= 2)) {
                return self::collapseNames($nodes, 1);
            } else if ($node && !in_array($node, $newNodes)) {
	            $newNodes[] =  $node;
            }
	        $i++;
        }
	    return $newNodes;
    }

	public static function downloadNamesForMatch($token, $server) {
		Application::log("Downloading new names for pid ".Application::getPID($token));
		self::$namesForMatch = Download::fields($token, $server, array("record_id", "identifier_first_name", "identifier_last_name"));
        Application::log("Downloaded ".count(self::$namesForMatch)." rows");
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

	# from 2010 census
    # https://www.census.gov/topics/population/genealogy/data/2010_surnames.html
	public static function isCommonLastName($lastName) {
        # criteria 200,000+ names in US
        $listOfCommonLastNames = [
            "SMITH",
            "JOHNSON",
            "WILLIAMS",
            "BROWN",
            "JONES",
            "GARCIA",
            "MILLER",
            "DAVIS",
            "RODRIGUEZ",
            "MARTINEZ",
            "HERNANDEZ",
            "LOPEZ",
            "GONZALEZ",
            "WILSON",
            "ANDERSON",
            "THOMAS",
            "TAYLOR",
            "MOORE",
            "JACKSON",
            "MARTIN",
            "LEE",
            "PEREZ",
            "THOMPSON",
            "WHITE",
            "HARRIS",
            "SANCHEZ",
            "CLARK",
            "RAMIREZ",
            "LEWIS",
            "ROBINSON",
            "WALKER",
            "YOUNG",
            "ALLEN",
            "KING",
            "WRIGHT",
            "SCOTT",
            "TORRES",
            "NGUYEN",
            "HILL",
            "FLORES",
            "GREEN",
            "ADAMS",
            "NELSON",
            "BAKER",
            "HALL",
            "RIVERA",
            "CAMPBELL",
            "MITCHELL",
            "CARTER",
            "ROBERTS",
            "GOMEZ",
            "PHILLIPS",
            "EVANS",
            "TURNER",
            "DIAZ",
            "PARKER",
            "CRUZ",
            "EDWARDS",
            "COLLINS",
            "REYES",
            "STEWART",
            "MORRIS",
            "MORALES",
            "MURPHY",
            "COOK",
            "ROGERS",
            "GUTIERREZ",
            "ORTIZ",
            "MORGAN",
            "COOPER",
            "PETERSON",
            "BAILEY",
            "REED",
            "KELLY",
            "HOWARD",
            "RAMOS",
            "KIM",
            "COX",
            "WARD",
            "RICHARDSON",
            "WATSON",
            "BROOKS",
            "CHAVEZ",
            "WOOD",
            "JAMES",
            "BENNETT",
            "GRAY",
            "MENDOZA",
            "RUIZ",
            "HUGHES",
            "PRICE",
            "ALVAREZ",
            "CASTILLO",
            "SANDERS",
            "PATEL",
            "MYERS",
            "LONG",
            "ROSS",
            "FOSTER",
            "JIMENEZ",
            "POWELL",
            "JENKINS",
            "PERRY",
            "RUSSELL",
            "SULLIVAN",
            "BELL",
            "COLEMAN",
            "BUTLER",
            "HENDERSON",
            "BARNES",
            "GONZALES",
            "FISHER",
            "VASQUEZ",
            "SIMMONS",
            "ROMERO",
            "JORDAN",
            "PATTERSON",
            "ALEXANDER",
            "HAMILTON",
            "GRAHAM",
            "REYNOLDS",
        ];
        $lastName = trim(strtoupper($lastName));
        return in_array($lastName, $listOfCommonLastNames);
    }

	# returns list($firstName, $lastName)
	public static function splitName($name, $parts = 2, $loggingOn = FALSE) {
        $simpleLastNamePrefixes = ["von", "van", "de"];
        $complexLastNamePrefixes = [["von", "van", "de"], ["der", "la"]];

        if ($parts <= 0) {
            throw new \Exception("Parts must be positive! ($parts)");
        }
		if ($name == "") {
		    $returnValues = [];
		    for ($i = 0; $i < $parts; $i++) {
		        $returnValues[] = "";
            }
			return $returnValues;
		}
		if ($parts == 1) {
		    return [$name];
        }

		$nodes = preg_split("/\s*,\s*/", $name);
		if (count($nodes) == 1) {
            $nodes = preg_split("/\s*\band\b\s*/", $name);
        }
		if (count($nodes) >= 2) {
		    if ($loggingOn) { echo "Comma delimited<br>"; }
			# last-name, first-name
            if ($parts == 2) {
                return [$nodes[1], $nodes[0]];
            } else if ($parts == 1) {
                return [$nodes[0]];
            } else if (count($nodes) >= $parts) {
                $returnValues = [$nodes[1], $nodes[0]];
                for ($i = 2; $i < $parts; $i++) {
                    $returnValues[] = $nodes[$i];
                }
                return $returnValues;
            } else {     // $parts > count($nodes)
                $nodesInRightOrder = [$nodes[1]];   // first name
                for ($i = 2; $i < count($nodes); $i++) {
                    $nodesInRightOrder[] = $nodes[$i];
                }
                $nodesInRightOrder[] = $nodes[0];    // last name
                return self::padWithSpaces($nodesInRightOrder, $parts);
            }
		} else if (count($nodes) == 1) {
            if ($parts >= 2) {
                $nodes = preg_split("/\s+/", $nodes[0]);
                $lastNodeIdx = count($nodes) - 1;
                if ($loggingOn) { echo "Split into ".count($nodes)." nodes<br>"; }
                do {
                    $changed = FALSE;
                    if ($loggingOn) { echo "In do-while with ".count($nodes)." nodes<br>"; }
                    if (count($nodes) == $parts) {
                        if ($loggingOn) { echo "Do-while A<br>"; }
                        return $nodes;
                    } else if ((count($nodes) == 3) && (self::isInitial($nodes[0]) || self::isInitial($nodes[1]))) {
                        if ($loggingOn) { echo "Do-while B<br>"; }
                        if ($parts == 2) {
                            return [$nodes[0] . " " . $nodes[1], $nodes[2]];
                        } else if ($parts > 3) {
                            return self::padWithSpaces($nodes, $parts);
                        } else if ($parts == 3) {
                            return $nodes;
                        }
                    } else if (count($nodes) > $parts) {
                        if (in_array(strtolower($nodes[$lastNodeIdx - 1]), $simpleLastNamePrefixes)) {
                            if ($loggingOn) {
                                echo "Do-while C<br>";
                            }
                            $newNodes = [];
                            for ($i = 0; $i < $lastNodeIdx - 1; $i++) {
                                $newNodes[] = $nodes[$i];
                            }
                            $newNodes[] = $nodes[$lastNodeIdx - 1] . " " . $nodes[$lastNodeIdx];
                            $changed = TRUE;
                            $nodes = $newNodes;
                        } else if (($lastNodeIdx > 2) && in_array(strtolower($nodes[$lastNodeIdx - 2]), $complexLastNamePrefixes[0]) && in_array(strtolower($nodes[$lastNodeIdx - 1]), $complexLastNamePrefixes[1])) {
                            if ($loggingOn) {
                                echo "Do-while D<br>";
                            }
                            $newNodes = [];
                            for ($i = 0; $i < $lastNodeIdx - 2; $i++) {
                                $newNodes[] = $nodes[$i];
                            }
                            $newNodes[] = $nodes[$lastNodeIdx - 2] . " " . $nodes[$lastNodeIdx - 1] . " " . $nodes[$lastNodeIdx];
                            $changed = TRUE;
                            $nodes = $newNodes;
                        } else {
                            if ($loggingOn) {
                                echo "Do-while E: ".json_encode($nodes)."<br>";
                            }
                            return self::collapseNames($nodes, $parts);
                        }
                    } else if (count($nodes) < $parts) {
                        if ($loggingOn) {
                            echo "Do-while F<br>";
                        }
                        return self::padWithSpaces($nodes, $parts);
                    } else {
                        if ($loggingOn) {
                            echo "Do-while G<br>";
                        }
                    }
                } while($changed);

                if (count($nodes) > $parts) {
                    return self::collapseNames($nodes, $parts);
                } else if (count($nodes) < $parts) {
                    return self::padWithSpaces($nodes, $parts);
                } else {
                    throw new \Exception("This should never happen!");
                }
            }
		}
		return array("", "");
	}

	private static function collapseNames($nodes, $parts) {
        $first = $nodes[0];
        for ($i = 1; $i <= count($nodes) - $parts; $i++) {
            $first .= " ".$nodes[$i];
        }
        # collapse initials
        $first = preg_replace("/\b(\w)\b\s\b(\w)\b/", "$1$2", $first);

        $returnValues[] = $first;
        for ($i = count($nodes) - $parts + 1; $i < count($nodes); $i++) {
            $returnValues[] = $nodes[$i];
        }
        return $returnValues;
    }

	private static function isInitial($name) {
        return preg_match("/^\w\.$/", $name);
    }

    private static function padWithSpaces($nodes, $parts) {
        $returnValues = [$nodes[0]];
        for ($i = 0; $i < $parts - count($nodes); $i++) {
            $returnValues[] = "";
        }
        for ($i = 1; $i < count($nodes); $i++) {
            $returnValues[] = $nodes[$i];
        }
        return $returnValues;
    }

	private static $namesForMatch = NULL;
	private static $currToken = "";
}
