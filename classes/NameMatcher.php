<?php

namespace Vanderbilt\CareerDevLibrary;


# This class handles commonly occuring downloads from the REDCap API.

// require_once(dirname(__FILE__)."/../../../redcap_connect.php");
require_once(__DIR__ . '/ClassLoader.php');

class NameMatcher {
    public static function matchInstitution($institution, $allInstitutions) {
        foreach ($allInstitutions as $inst) {
            if (self::doInstitutionsMatch($inst, $institution)) {
                Application::log("$inst and $institution match");
                return TRUE;
            }
        }
        return FALSE;
    }

    public static function formatName($first, $middle, $last) {
        if ($middle) {
            return $first." ".$middle." ".$last;
        } else {
            return $first." ".$last;
        }
    }

    public static function doInstitutionsMatch($i1, $i2) {
        if (!$i1 || !$i2) {
            return FALSE;
        }
        $i1 = strtolower($i1);
        $i2 = strtolower($i2);
        if ($i1 == $i2) {
            return TRUE;
        }
        $delim = "|";
        return (
            preg_match($delim . preg_quote($i1, $delim) . $delim, $i2)
            || preg_match($delim . preg_quote($i2, $delim) . $delim, $i1)
        );
    }

    public static function matchLastName($lastName, $tokenOrArrayOfFirsts, $serverOrArrayOfLasts) {
        if (!$lastName) {
            return [];
        }
        $names = [];
        if (REDCapManagement::isValidToken($tokenOrArrayOfFirsts)) {
            $token = $tokenOrArrayOfFirsts;
            $server = $serverOrArrayOfLasts;
            self::refreshNamesForMatch($token, $server);

            $lastName = strtolower($lastName);
            foreach (self::$namesForMatch as $row) {
                $sLast = strtolower($row['identifier_last_name']);
                if (self::testLastName($lastName, $sLast)) {
                    $names[$row['record_id']] = $row['identifier_first_name']." ".$row['identifier_last_name'];
                }
            }
        } else if (is_array($tokenOrArrayOfFirsts) && is_array($serverOrArrayOfLasts)) {
            $firstNames = $tokenOrArrayOfFirsts;
            $lastNames = $serverOrArrayOfLasts;
            foreach ($lastNames as $recordId => $lastName2) {
                if (self::testLastName($lastName, strtolower($lastName2))) {
                    $firstName2 = $firstNames[$recordId] ?? "";
                    $names[$recordId] = $firstName2." ".$lastName2;
                }
            }
        }
        return $names;
    }

    private static function testLastName($lastName1, $lastName2) {
        return preg_match("/".preg_quote($lastName2, "/")."/", $lastName1) || preg_match("/".preg_quote($lastName1, "/")."/", $lastName2);
    }

    public static function eliminateInitials($name) {
        $name = preg_replace("/\b[A-Z]\b\.?/", "", $name);
        $name = trim($name);
        return $name;
    }

    private static function doNamesMatch($name1, $name2) {
        if (isset($_GET['test'])) {
            Application::log("doNamesMatch $name1 $name2");
        }
        $name1WithoutApostrophes = str_replace("'", "", $name1);
        $name2WithoutApostrophes = str_replace("'", "", $name2);
        if (strtolower($name1WithoutApostrophes) == strtolower($name2WithoutApostrophes)) {
            return TRUE;
        }
        $nodes1 = preg_split("/[\s\-]+/", $name1);
        $nodes2 = preg_split("/[\s\-]+/", $name2);
        if ((count($nodes1) > 1) || (count($nodes2) > 1)) {
            foreach ($nodes1 as $node1) {
                foreach ($nodes2 as $node2) {
                    if (isset($_GET['test'])) {
                        Application::log("doNamesMatch comparison of '$node1' and '$node2'");
                    }
                    if (!self::isInitial($node1) && !self::isInitial($node2) && (strtolower($node1) == strtolower($node2))) {
                        if (isset($_GET['test'])) {
                            Application::log("doNamesMatch comparison of '$node1' and '$node2' - returning TRUE");
                        }
                        return TRUE;
                    } else {
                        if (isset($_GET['test'])) {
                            Application::log("doNamesMatch comparison of '$node1' and '$node2' - continuing through FALSE");
                        }
                        if (self::isInitial($node1)) {
                            if (isset($_GET['test'])) {
                                Application::log("doNamesMatch comparison of 1 '$node1' is initial");
                            }
                        }
                        if (self::isInitial($node2)) {
                            if (isset($_GET['test'])) {
                                Application::log("doNamesMatch comparison of 2 '$node2' is initial");
                            }
                        }
                        if (strtolower($node1) != strtolower($node2)) {
                            if (isset($_GET['test'])) {
                                Application::log("doNamesMatch comparison of '$node1' and '$node2' not equal");
                            }
                        }
                    }
                }
            }
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
        $delim = "|";
        foreach ($pairs as $pair) {
            $n1 = $pair[0];
            $n2 = $pair[1];
            if (!self::isShortName($n1)) {
                if (preg_match($delim.preg_quote($n1, $delim).$delim."i", $n2)) {
                    return TRUE;
                }
            }
            if (!self::isShortName($n2)) {
                if (preg_match($delim.preg_quote($n2, $delim).$delim."i", $n1)) {
                    return TRUE;
                }
            }
            if (strtolower($n1) == strtolower($n2)) {
                return TRUE;
            }
        }
        return FALSE;
    }

    public static function isShortName($name) {
        return (strlen($name) <= 4);
    }

    public static function isShortLastName($lastName) {
        return self::isShortName($lastName);
    }

    public static function makeUncommonDefinition() {
        return "<a class='tooltip'>uncommon<span class='tooltiptext' style='font-weight: normal;'>".Application::getProgramName()." defines uncommon as less than 200,000 people in the 2010 US Census.</span></a>";
    }

    public static function makeLongDefinition() {
        return "<a class='tooltip'>long<span class='tooltiptext' style='font-weight: normal;'>".Application::getProgramName()." defines long as five or more characters.</span></a>";
    }

    public static function explodeAlternates($name) {
        $list = [$name];
        if (preg_match("/'/", $name)) {
            $list[] = preg_replace("/'/", "", $name);
        }
        return $list;
    }

	public static function matchNames($firsts, $lasts, $tokenOrFirst, $serverOrLast, $secondPairRecordId = -1) {
		if (!$firsts || !$lasts) {
			return "";
		}
		# Assumption: A first name is not 32 characters long and tokens never become more than 32 characters in REDCap
		if ((strlen($tokenOrFirst)) == 32 && preg_match("/^[\da-zA-Z]+$/", $tokenOrFirst)) {
		    $token = $tokenOrFirst;
            $server = $serverOrLast;
            self::refreshNamesForMatch($token, $server);
            $namesToSearch = self::$namesForMatch;
        } else {
		    $nameRow = [
		        "record_id" => $secondPairRecordId,
                "identifier_first_name" => $tokenOrFirst,
                "identifier_last_name" => $serverOrLast,
            ];
		    $namesToSearch = [$nameRow];
        }
		$recordIds = array();
		for ($i = 0; $i < count($firsts) && $i < count($lasts); $i++) {
			$myFirst = self::prepareName($firsts[$i]);
			$myLast = self::prepareName($lasts[$i]);
            $recordIds[$i] = FALSE;
			if ($myFirst && $myLast) {
                foreach ($namesToSearch as $row) {
                    $sFirst = self::prepareName($row['identifier_first_name']);
                    $sLast = self::prepareName($row['identifier_last_name']);
                    $found = FALSE;
                    foreach (self::explodeFirstName($sFirst) as $sFirst) {
                        foreach (self::explodeLastName($sLast) as $sLast) {
                            if (self::doNamesMatch($sLast, $myLast)) {
                                if (isset($_GET['test'])) {
                                    Application::log("Matched $sLast $myLast; comparing $sFirst $myFirst");
                                }
                                if (self::isInitialsOnly($myFirst)) {
                                    if (isset($_GET['test'])) {
                                        Application::log("isInitialsOnly $myFirst");
                                    }
                                    if (self::matchByInitials($sLast, $sFirst, $myLast, $myFirst)) {
                                        $recordIds[$i] = $row['record_id'];
                                        $found = TRUE;
                                        break;  // inner
                                    }
                                }
                                if (self::isFirstInitalMiddleName($myFirst) || self::isFirstInitalMiddleName($sFirst)) {
                                    if (isset($_GET['test'])) {
                                        Application::log("isFirstInitalMiddleName $myFirst and $sFirst");
                                    }
                                    $sFirstInitial = self::turnIntoOneInitial($sFirst);
                                    $myFirstInitial = self::turnIntoOneInitial($myFirst);
                                    if (self::matchByInitials($sLast, $sFirstInitial, $myLast, $myFirstInitial)) {
                                        $recordIds[$i] = $row['record_id'];
                                        $found = TRUE;
                                        break;  // inner
                                    }
                                    $myNodes = preg_split("/\s+/", $myFirst);
                                    $sNodes = preg_split("/\s+/", $sFirst);
                                    if ((count($myNodes) > 1) && (count($sNodes) > 1)) {
                                        $matchFound = FALSE;
                                        foreach ($myNodes as $myNode) {
                                            foreach ($sNodes as $sNode) {
                                                if (self::doNamesMatch($myNode, $sNode)) {
                                                    $matchFound = TRUE;
                                                }
                                            }
                                        }
                                        if ($matchFound) {
                                            $recordIds[$i] = $row['record_id'];
                                            $found = TRUE;
                                            break;  // inner
                                        }
                                    } else if (count($myNodes) > 1) {
                                        $myMiddleName = $myNodes[1];
                                        if (self::doNamesMatch($sFirst, $myMiddleName)) {
                                            $recordIds[$i] = $row['record_id'];
                                            $found = TRUE;
                                            break;  // inner
                                        }
                                    } else if (count($sNodes) > 1) {
                                        $sMiddleName = $sNodes[1];
                                        if (self::doNamesMatch($sMiddleName, $myFirst)) {
                                            $recordIds[$i] = $row['record_id'];
                                            $found = TRUE;
                                            break;  // inner
                                        }
                                    }
                                }
                                if (self::doNamesMatch($sFirst, $myFirst)) {
                                    $recordIds[$i] = $row['record_id'];
                                    $found = TRUE;
                                    break;  // inner
                                }
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

    public static function removeParentheses($name) {
        $name = preg_replace("/[\(\)]/", " ", $name);
        $name = trim($name);
        $name = preg_replace("/\s\s+/", " ", $name);
        return $name;
    }

	public static function explodeFirstName($first, $middle = "") {
        $first = self::removeParentheses($first);
        $splitRegex = "/[\s\-]+/";
	    $firstNodes = preg_split($splitRegex, $first);
	    $middleNodes = [];
	    if ($middle) {
	        $middleNodes = preg_split($splitRegex, $middle);
        }
	    $nodes = array_unique(array_merge($firstNodes, $middleNodes));

	    $newNodes = [$first];
	    foreach ($nodes as $node) {
	        $isMiddleInitial = FALSE;
	        if (self::isInitial($node) && in_array($node, $middleNodes)) {
	            $isMiddleInitial = TRUE;
            }
	        if ($node && !in_array($node, $newNodes) && !$isMiddleInitial) {
                $newNodes[] = $node;
            }
        }
        $newNodes = Sanitizer::decodeHTML($newNodes);
	    return self::trimArray($newNodes);
    }

    private static function trimArray($ary) {
        $newAry = [];
        foreach ($ary as $elem) {
            $newAry[] = trim($elem);
        }
        return $newAry;
    }

    private static function getSuffixes() {
        return ["I", "II", "III", "IV", "JR", "JR.", "SR", "SR."];
    }

    public static function explodeLastName($last) {
        $last = self::removeParentheses($last);
	    $nodes = preg_split("/[\s\-]+/", $last);
	    $newNodes = [$last];
	    $i = 0;
	    $suffixesInUpper = self::getSuffixes();
	    $prefixesInLower = ["van", "von", "de", "der"];
	    foreach ($nodes as $node) {
	        if (in_array(strtoupper($node), $suffixesInUpper) && ($i == 1)) {
	            return [$nodes[0], $nodes[0]." ".$nodes[1]];
            } else if (in_array(strtolower($node), $prefixesInLower) && ($i == 0) && (count($nodes) >= 2)) {
                return self::collapseNames($nodes, 1);
            } else if ($node && !in_array($node, $newNodes)) {
	            $newNodes[] =  $node;
            }
	        $i++;
        }
        if (preg_match("/\s/", $last)) {
            $newNodes[] = preg_replace("/\s/", "-", $last);
        }
        if (preg_match("/\-/", $last)) {
            $newNodes[] = preg_replace("/\-/", " ", $last);
        }
        $newNodes = Sanitizer::decodeHTML($newNodes);
        return self::trimArray($newNodes);
    }

    public static function refreshNamesForMatch($token, $server) {
        if ((self::$namesForMatch === NULL) || ($token != self::$currToken)) {
            self::downloadNamesForMatch($token, $server);
        }
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
    # $secondPairRecordId is for second pair if they are names
	public static function matchName($first, $last, $tokenOrFirst, $serverOrLast, $secondPairRecordId = -1) {
        if ($first && $last) {
            $ary = self::matchNames(array($first), array($last), $tokenOrFirst, $serverOrLast, $secondPairRecordId);
            if (count($ary) > 0) {
                return $ary[0];
            }
        }
		return "";
	}

    public static function matchByLastName($lastName1, $lastName2) {
        $lastName1 = strtolower($lastName1);
        $lastName2 = strtolower($lastName2);
        return ($lastName1 == $lastName2);
    }

    public static function matchByFirstName($firstName1, $firstName2) {
        return self::matchByLastName($firstName1, $firstName2);
    }

    public static function dashes2Spaces($name) {
        return preg_replace("/\-/", " ", $name);
    }

    # case insensitive match based on last name and first initial only
	# returns TRUE/FALSE
	public static function matchByInitials($lastName1, $firstName1, $lastName2, $firstName2) {
        $firstNames1 = is_array($firstName1) ? $firstName1 : [$firstName1];
        $firstNames2 = is_array($firstName2) ? $firstName2 : [$firstName2];

        // Application::log("matchByInitials: Comparing firstNames: ".json_encode($firstNames1)." vs. ".json_encode($firstNames2));
        $firstInitialMatch = FALSE;
        foreach ($firstNames1 as $fn1) {
            $fn1 = strtolower($fn1);
            foreach ($firstNames2 as $fn2) {
                $fn2 = strtolower($fn2);
                $fi1 = self::turnIntoOneInitial($fn1);
                $fi2 = self::turnIntoOneInitial($fn2);
                // Application::log("matchByInitials: Comparing first $fi1 vs $fi2 from $fn1 and $fn2");
                if ($fi1 == $fi2) {
                    $firstInitialMatch = TRUE;
                }
            }
        }
        if (!$firstInitialMatch) {
            return FALSE;
        }

		$lastNames1 = is_array($lastName1) ? $lastName1 : self::explodeLastName(self::dashes2Spaces($lastName1));
		$lastNames2 = is_array($lastName2) ? $lastName2 : self::explodeLastName(self::dashes2Spaces($lastName2));
        // Application::log("matchByInitials: Comparing lastNames: ".json_encode($lastNames1)." vs. ".json_encode($lastNames2));
		foreach ($lastNames1 as $ln1) {
		    $ln1 = strtolower($ln1);
		    foreach ($lastNames2 as $ln2) {
		        $ln2 = strtolower($ln2);
                if (isset($_GET['test'])) {
                    // Application::log("matchByInitials: Comparing last $ln1 vs $ln2");
                }
                if ($ln1 == $ln2) {
                    return TRUE;
                }
            }
        }
		return FALSE;
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
	public static function splitName($name, $parts = 2, $loggingOn = FALSE, $clearOfExtraTitles = TRUE) {
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
		if ($loggingOn) { echo "Initial split into: ".json_encode($nodes); }
		if ($clearOfExtraTitles) {
            $nodes = self::clearOfDegrees($nodes);
        }
        if ($loggingOn) { echo "Cleared into: ".json_encode($nodes); }
		if (count($nodes) == 1) {
		    if (preg_match("/\band\b/", $name)) {
                $nodes = preg_split("/\s*\band\b\s*/", $name);
            }
        }
		if (count($nodes) >= 2) {
		    if ($loggingOn) { echo "Comma delimited<br>"; }
			# last-name, first-name
            if ($parts == 2) {
                return [$nodes[1], $nodes[0]];
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
                $prevName = $nodes[0];
                $nodes = preg_split("/\s+/", $prevName);
                if ($clearOfExtraTitles) {
                    $nodes = self::clearOfDegrees($nodes);
                    $nodes = self::clearOfHonorifics($nodes);
                }
                $lastNodeIdx = count($nodes) - 1;

                if ($loggingOn) { echo "Split ".REDCapManagement::sanitize($prevName)." into ".count($nodes)." nodes<br>"; }
                do {
                    $changed = FALSE;
                    if ($loggingOn) { echo "In do-while with ".count($nodes)." nodes<br>"; }
                    if (count($nodes) == $parts) {
                        if (strlen($nodes[1]) <= 2) {
                            # Initials
                            if ($loggingOn) { echo "Do-while A Initials<br>"; }
                            return [$nodes[1], $nodes[0]];
                        } else {
                            if ($loggingOn) { echo "Do-while A<br>"; }
                            return $nodes;
                        }
                    } else if ((count($nodes) == 3) && (self::isInitial($nodes[0]) || self::isInitial($nodes[1]))) {
                        if ($loggingOn) { echo "Do-while B<br>"; }
                        if ($parts == 2) {
                            if (self::isInitial($nodes[1])) {
                                if ($loggingOn) {
                                    echo "Do-while B: Getting rid of initial {$nodes[1]}<br>";
                                }
                                return [$nodes[0], $nodes[2]];
                            } else if (self::isInitial($nodes[0])) {
                                if ($loggingOn) {
                                    echo "Do-while B: Getting rid of initial ".REDCapManagement::sanitize($nodes[0])."<br>";
                                }
                                return [$nodes[1], $nodes[2]];
                            } else {
                                return [$nodes[0] . " " . $nodes[1], $nodes[2]];
                            }
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
                        } else if (preg_match("/^\((.+)\)$/", $nodes[$lastNodeIdx] ?? "", $matches)) {
                            if ($loggingOn) {
                                echo "Do-while E: ".json_encode($nodes)."<br>";
                            }
                            $newNodes = [];
                            for ($i = 0; $i < $lastNodeIdx - 1; $i++) {
                                $newNodes[] = $nodes[$i];
                            }
                            $newNodes[] = $nodes[$lastNodeIdx - 1] . " " . $matches[1];   # remove parentheses
                            $changed = TRUE;
                            $nodes = $newNodes;
                        } else {
                            if ($loggingOn) {
                                echo "Do-while F: ".json_encode($nodes)."<br>";
                            }
                            return self::collapseNames($nodes, $parts);
                        }
                    } else if (count($nodes) < $parts) {
                        if ($loggingOn) {
                            echo "Do-while G<br>";
                        }
                        return self::padWithSpaces($nodes, $parts);
                    } else {
                        if ($loggingOn) {
                            echo "Do-while H<br>";
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

	public static function clearOfHonorifics($nodes) {
        $returnString = FALSE;
        if (is_string($nodes)) {
            $returnString = TRUE;
            $nodes = preg_split("/\s+/", $nodes);
        }
        $honorifics = [
            "Mr.",
            "Ms.",
            "Mx.",
            "Mrs.",
            "Miss",
            "Dr.",
            "Rev.",
            "Mr",
            "Ms",
            "Mx",
            "Mrs",
            "Dr",
            "Rev",
        ];
        $suffixes = self::getSuffixes();

        $newNodes = [];
        $i = 0;
        foreach ($nodes as $node) {
            if (($i > 0) || !in_array($node, $honorifics)) {
                if (
                    !in_array(strtoupper($node), $suffixes)
                    || (
                        (count($nodes) <= 2)
                        && self::isInitialsOnly($node)
                    )
                ) {
                    $newNodes[] = $node;
                }
            }
            $i++;
        }
        if ($returnString) {
            return implode(" ", $newNodes);
        } else {
            return $newNodes;
        }
    }

	public static function clearOfDegrees($nodes) {
        $returnString = FALSE;
        if (is_string($nodes)) {
            $returnString = TRUE;
            $nodes = preg_split("/\s+/", $nodes);
        }
        $degreesInUpperCase = [
            "MD,",
            "PHD,",
            "MD",
            "PHD",
            "MSC",
            "DSC",
            "DRPH",
            "MSCI",
            "MPH",
            "MD PHD",
            "MD, PHD",
            "MD,PHD",
            "MD/PHD",
            "MS",
            "MHS",
            "DPHIL",
            "PSYD",
            "PHARMD",
            "RN",
            "FACP",
            "FRCP",
            "MBCHB",
        ];
        $newNodes = [];
        if (count($nodes) >= 1) {
            $newNodes[] = $nodes[0];
        }
        $i = 0;
        foreach ($nodes as $node) {
            if ($i > 0) {
                $node = trim($node);
                $nodeToTest = preg_replace("/[\,\;\.]+$/", "", strtoupper($node));
                if ($node && !in_array($nodeToTest, $degreesInUpperCase)) {
                    $newNodes[] = $node;
                }
            }
            $i++;
        }
        if ($returnString) {
            return preg_replace("/[\,\;\.]+$/", "", implode(" ", $newNodes));
        } else {
            return $newNodes;
        }
    }

	private static function collapseNames($nodes, $parts) {
        $suffixes = self::getSuffixes();
        $hasSuffix = FALSE;
        for ($i = 1; $i < count($nodes); $i++) {
            if (in_array(strtoupper($nodes[$i]), $suffixes)) {
                $hasSuffix = TRUE;
                break;
            }
        }
        if ($hasSuffix) {
            $newNodes = [];
            foreach ($nodes as $node) {
                if (in_array(strtoupper($node), $suffixes)) {
                    if (count($newNodes) > 0) {
                        $newNodes[count($newNodes) - 1] .=" ".$node;
                    } else {
                        throw new \Exception("This should never happen, a suffix at the beginning of a name: ".json_encode($nodes)." ".json_encode($newNodes)." on $node");
                    }
                } else {
                    $newNodes[] = $node;
                }
            }
            if (count($newNodes) <= $parts) {
                return $newNodes;
            } else {
                return self::collapseNames($newNodes, $parts);
            }
        }
        $first = $nodes[0];
        for ($i = 1; $i <= count($nodes) - $parts; $i++) {
            $first .= " ".$nodes[$i];
        }
        # collapse initials
        $first = preg_replace("/\b(\w)\b\s\b(\w)\b/", "$1$2", $first);

        $returnValues = [];
        $returnValues[] = $first;
        for ($i = count($nodes) - $parts + 1; $i < count($nodes); $i++) {
            $returnValues[] = $nodes[$i];
        }
        return $returnValues;
    }

    private static function isFirstInitalMiddleName($name) {
        if (preg_match("/^\w\.[\s\-]\w+/", $name)) {
            return TRUE;
        }
        if (preg_match("/^\w[\s\-]\w+/", $name)) {
            return TRUE;
        }
        return FALSE;
    }

    // TRUE for E or E.
    // TRUE for E M, EM, E.M., or E. M.
    // TRUE for W E B or W. E. B.
    // FALSE for WEB or W.E.B.
    // FALSE for Ryan
    public static function isInitialsOnly($name) {
        if (preg_match("/^[A-Z]\.?$/", $name)) {
            return TRUE;
        }
        if (preg_match("/^[A-Z]\.?[A-Z]\.?$/", $name)) {
            return TRUE;
        }
        $nodes = preg_split("/[\s\-]+/", $name);
        foreach ($nodes as $node) {
            if (!self::isInitial($node)) {
                return FALSE;
            }
        }
        return TRUE;
    }

	public static function isInitial($name) {
        return preg_match("/^\w\.?$/", $name);
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
