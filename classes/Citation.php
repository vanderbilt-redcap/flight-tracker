<?php

namespace Vanderbilt\CareerDevLibrary;

# This class handles publication data from PubMed, the VICTR fetch routine, and surveys.
# It also provides HTML for data-wrangling the publication data

use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(__DIR__ . '/ClassLoader.php');

class CitationCollection {
	# type = [ Filtered, Final, New, Omit, Flagged, Unflagged ]
	public function __construct($recordId, $token, $server, $type = 'Final', $redcapData = array(), $metadata = "download", $lastNames = [], $firstNames = []) {
		$this->token = $token;
		$this->server = $server;
		$this->citations = array();
		if ($metadata == "download") {
		    $this->metadata = Download::metadata($token, $server);
        } else {
            $this->metadata = $metadata;
        }
		if (empty($redcapData) &&  ($type != "Filtered") && !empty($this->metadata)) {
            $redcapData = Download::fieldsForRecords($token, $server, Application::getCitationFields($this->metadata), [$recordId]);
		}
		if (empty($lastNames)) {
		    $lastNames = Download::lastnames($token, $server);
        }
        if (empty($firstNames)) {
            $firstNames = Download::firstnames($token, $server);
        }
        if ($type != "Filtered") {
            foreach ($redcapData as $row) {
                if (in_array($row['redcap_repeat_instrument'], ["citation", "eric"]) && ($row['record_id'] == $recordId)) {
                    $c = new Citation($token, $server, $recordId, $row['redcap_repeat_instance'], $row, $this->metadata, $lastNames[$recordId], $firstNames[$recordId]);
                    if ($c->getType() == $type) {
                        $this->citations[] = $c;
                    }
                }
            }
        } else {
            # Filtered ==> Manually add
        }
	}

    public function getBoldedNames($withTotals = FALSE) {
        $names = [];
        foreach ($this->getCitations() as $citation) {
            $boldedNames = $citation->getBoldedNames();
            foreach ($boldedNames as $name) {
                if (!isset($names[$name])) {
                    $names[$name] = 1;
                } else {
                    $names[$name]++;
                }
            }
        }
        if ($withTotals) {
            $totalNames = [];
            foreach ($names as $name => $total) {
                $totalNames[] = "$name ($total)";
            }
            return $totalNames;
        } else {
            return array_keys($names);
        }
    }

	# citationClass is notDone, included, or excluded/omitted
	public function toHTML($citationClass, $displayOnEmpty = TRUE, $startI = 1) {
		$html = "";
		if ($displayOnEmpty && (count($this->getCitations()) == 0)) {
			$html .= "<p class='centered'>None to date.</p>";
		} else {
            $allBoldedNames = $this->getBoldedNames();
            $i = $startI - 1;
            foreach ($this->getCitations() as $citation) {
                $boldedNames = $citation->getBoldedNames();
                $nameClasses = [];
                foreach ($allBoldedNames as $j => $name) {
                    if (in_array($name, $boldedNames)) {
                        $nameClasses[] = "name$j";
                    }
                }

				$html .= $citation->toHTML($citationClass, $nameClasses, $i+1);
				$i++;
			}
		}
		return $html;
	}

	# for bookkeeping purposes only; does not write to DB
	public function removePMID($pmid) {
		if ($this->has($pmid)) {
			$newCitations = array();
			foreach ($this->getCitations() as $citation) {
				if ($citation->getPMID() != $pmid) {
					$newCitations[] = $citation;
				}
			}
			$this->citations = $newCitations;
		}
	}

	public function has($pmid) {
		$allPMIDs = $this->getIds();
		return in_array($pmid, $allPMIDs);
	}

	public function getIds() {
		$ids = array();
		$citations = $this->getCitations();
		foreach ($citations as $citation) {
			$pmid = $citation->getPMID();
			if (!in_array($pmid, $ids)) {
				$ids[] = $pmid;
			}
		}
		return $ids;
	}

	public function getCitations($sortBy = "date") {
		$this->sortCitations($sortBy);
        $limitYear = Sanitizer::sanitizeInteger($_GET['limitPubs'] ?? "");
        if (isset($_GET['limitPubs']) && $limitYear) {
            $thresholdTs = strtotime($limitYear."-01-01");
            $filteredCitations = [];
            foreach ($this->citations as $citation) {
                $ts = $citation->getTimestamp();
                if ($ts && $ts >= $thresholdTs) {
                    $filteredCitations[] = $citation;
                }
            }
            return $filteredCitations;
        } else {
            return $this->citations;
        }
	}

	private static function sortArrays($unorderedArys, $field, $descending) {
		$keys = array();
		foreach ($unorderedArys as $ary) {
			$keys[] = $ary[$field];
		}
        if ($descending) {
            rsort($keys);
        } else {
            sort($keys);
        }

		if (count($keys) != count($unorderedArys)) {
			throw new \Exception("keys (".count($keys).") != unorderedArys (".count($unorderedArys).")");
		}

		$ordered = array();
		foreach ($keys as $key) {
			$found = FALSE;
			foreach ($unorderedArys as $i => $ary) {
				if ($ary[$field] == $key) {
					$ordered[] = $ary;
					unset($unorderedArys[$i]);
					$found = TRUE;
					break;
				}
			}
			if (!$found) {
				throw new \Exception($key." not found in ".json_encode($unorderedArys));
			}
		}

		if (count($keys) != count($ordered)) {
			throw new \Exception("keys (".count($keys).") != ordered (".count($ordered).")");
		}

		return $ordered;
	}

	public function sortCitations($how = "date") {
        $unsorted = [];
        $myCitations = $this->citations;
        $sorted = [];
        if ($how == "date") {
            foreach ($myCitations as $citation) {
                $unsorted[] = [
                    "citation" => $citation,
                    "timestamp" => $citation->getTimestamp(),
                ];
            }
            $sorted = self::sortArrays($unsorted, "timestamp", TRUE);
        } else if (in_array($how, ["altmetric_score", "rcr"])) {
            foreach ($myCitations as $citation) {
                $unsorted[] = [
                    "citation" => $citation,
                    "impact_factor" => $citation->getVariable($how),
                ];
            }
            $sorted = self::sortArrays($unsorted, "impact_factor", TRUE);
        }
		if (count($unsorted) != count($sorted)) {
			throw new \Exception("Unsorted (".count($unsorted).") != sorted (".count($sorted).")");
		}

		$this->citations = [];
		foreach ($sorted as $ary) {
			$this->citations[] = $ary['citation'];
		}
	}

	public function addCitation($citation) {
	    $validClasses = ["Citation", "Vanderbilt\CareerDevLibrary\Citation"];
		if (in_array(get_class($citation), $validClasses)) {
			$this->citations[] = $citation;
		} else {
			throw new \Exception("addCitation tries to add a citation of class ".get_class($citation).", instead of valid classes ".implode(", ", $validClasses)."!");
		}
	}

	public function getCitationsAsString($hasLink = FALSE) {
		$str = "";
		foreach ($this->getCitations() as $citation) {
			if ($str != "") {
				$str .= "\n";
			}
			if ($hasLink) {
				$str .= $citation->getCitationWithLink();
			} else {
				$str .= $citation->getCitation();
			}
		}
		return $str;
	}

    public function filterForAuthorPositions($positions, $name) {
        $methods = [];
        if (in_array("first", $positions)) {
            $methods[] = "isFirstAuthor";
        }
        if (in_array("middle", $positions)) {
            $methods[] = "isMiddleAuthor";
        }
        if (in_array("last", $positions)) {
            $methods[] = "isLastAuthor";
        }

        $newCitations = [];
        foreach ($this->getCitations() as $citation) {
            $match = FALSE;
            foreach ($methods as $method) {
                if ($citation->$method($name)) {
                    $match = TRUE;
                }
            }
            if ($match) {
                $newCitations[] = $citation;
            }
        }
        $this->citations = $newCitations;
    }

	public function filterForTimespan($startTs, $endTs) {
	    $newCitations = [];
	    foreach ($this->getCitations() as $citation) {
	        $ts = $citation->getTimestamp();
	        if (($ts >= $startTs) && ($ts <= $endTs)) {
	            $newCitations[] = $citation;
            }
        }
	    $this->citations = $newCitations;
    }

	public function getCount() {
		return count($this->getCitations());
	}

	private $citations = array();
	private $token = "";
	private $server = "";
	private $metadata = [];
}

class Citation {
    # Cf. https://pubmed.ncbi.nlm.nih.gov/help/#journal-lists
    const PUBMED_JOURNAL_URL = "https://ftp.ncbi.nih.gov/pubmed/J_Medline.txt";

    public function __construct($token, $server, $recordId, $instance, $row = array(), $metadata = array(), $lastName = "", $firstName = "") {
		$this->recordId = $recordId;
		$this->instance = $instance;
		$this->token = $token;
		$this->server = $server;
		$this->origRow = $row;
		$this->metadata = $metadata;
		$this->lastName = $lastName;
		$this->firstName = $firstName;
		$choices = REDCapManagement::getChoices($metadata);

		if (isset($choices["citation_source"])) {
			$this->sourceChoices = $choices["citation_source"];
		} else {
			$this->sourceChoices = [];
		}

		$this->readData();
	}

	public static function getImageSize() {
		return Wrangler::getImageSize();
	}
	
	public function getGrantBaseAwardNumbers() {
        $grantStr = $this->getVariable("grants");
        $initialGrants = $grantStr ? preg_split("/\s*[\n\r;,]\s*/", $grantStr) : [];
        $grants = [];
        $seen = [];
        for ($i = 0; $i < count($initialGrants); $i++) {
            if ($initialGrants[$i]) {
                if (preg_match("/[a-z]/", $initialGrants[$i])) {
                    continue;
                }
                if (preg_match("/^\d[A-Z][A-Z\d]\d[A-Z][A-Z]/", $initialGrants[$i])) {
                    $initialGrants[$i] = preg_replace("/^\d/", "", $initialGrants[$i]);
                }
                $initialGrants[$i] = preg_replace("/-\d\d$/", "", $initialGrants[$i]);
                $initialGrants[$i] = preg_replace("/-\d\d[A-Z\d]\d$/", "", $initialGrants[$i]);
                $initialGrants[$i] = preg_replace("/[\s\-_]+/", "", $initialGrants[$i]);
                if (
                    !preg_match("/[A-Z][A-Z]\d{6}/", $initialGrants[$i])
                    && preg_match("/[A-Z][A-Z]\d{5}/", $initialGrants[$i])
                ) {
                    $insert = "0";
                    $initialGrants[$i] = preg_replace("/([A-Z][A-Z])(\d{5})/", "\${1}$insert\${2}", $initialGrants[$i]);
                }
                if (!in_array($initialGrants[$i], $seen)) {
                    $grants[] = $initialGrants[$i];
                    $seen[] = $initialGrants[$i];
                }
            }
        }
        return $grants;
    }

	# citationClass is notDone, included, excluded/omitted, flagged, or unflagged
	public function toHTML($citationClass, $otherClasses = [], $number = 1) {
        $citationClass = strtolower($citationClass);
		if (in_array($citationClass, ["notDone", "notdone"])) {
			$checkboxClass = "checked";
		} else if ($citationClass == "included") {
			$checkboxClass = "readonly";
        } else if (in_array($citationClass, ["omitted", "excluded"])) {
            $checkboxClass = "unchecked";
        } else if ($citationClass == "flagged") {
            $checkboxClass = "checked";
        } else if ($citationClass == "unflagged") {
            $checkboxClass = "unchecked";
		} else {
			throw new \Exception("Unknown citationClass $citationClass");
		}


        $wranglerType = Sanitizer::sanitize($_GET['wranglerType'] ?? "");
		$ableToReset = ($wranglerType == "FlagPublications") ? [] : ["included", "omitted", "excluded"];
        $pid = Application::getPID($this->token);

		$html = "";
		$source = $this->getSource();
		if ($source) {
			$source = "<span class='sourceInCitation'>" . $source . "</span>: ";
		}
		$id = $this->getUniqueID();
        $divClasses = "citation $citationClass ".implode(" ", $otherClasses);
		$html .= "<div class='$divClasses' id='citation_$citationClass$id'>";
		$html .= "<div class='citationCategories'><span class='tooltiptext'>".$this->makeTooltip()."</span>".$this->getCategory()."</div>";
		$html .= Wrangler::makeCheckbox($id, $checkboxClass, $pid, $wranglerType)."&nbsp;<strong>$number</strong>.&nbsp;".$source.$this->getCitationWithLink(TRUE, TRUE);
		if (in_array($citationClass, $ableToReset)) {
            $html .= "<div style='text-align: right;' class='smallest'><span onclick='resetCitation(\"$id\");' class='finger'>reset</span></div>";
        }
		$html .= "</div>\n";
		return $html;
	}

	public function hasAuthor($name) {
		list($firstName, $lastName) = NameMatcher::splitName($name, 2, isset($_GET['test']));
		if ($lastName) {
			$authorList = $this->getAuthorList();
			foreach ($authorList as $author) {
				list($currFirstName, $currLastName) = NameMatcher::splitName($author, 2, isset($_GET['test']), FALSE);
                if (isset($_GET['test'])) {
                    Application::log("Comparing $firstName $lastName against $currFirstName $currLastName");
                }
                if (NameMatcher::matchByInitials($currLastName, $currFirstName, $lastName, $firstName)) {
                    return TRUE;
                }
			}
		}
		return FALSE;
	}

    public function isMiddleAuthor($name) {
        return !$this->isFirstAuthor($name) && !$this->isLastAuthor($name);
    }

	public function isFirstAuthor($name) {
        return $this->isAuthorIdx("first", $name);
    }

    public function isLastAuthor($name) {
        return $this->isAuthorIdx("last", $name);
    }

    private function isAuthorIdx($pos, $name) {
        list($firstName, $lastName) = NameMatcher::splitName($name);
        $authorList = $this->getAuthorList();
        if (count($authorList) == 0) {
            throw new \Exception("Citation did not have any authors!");
        }
	    if ($pos == "first") {
	        $idx = 0;
	    } else if ($pos == "last") {
	        $idx = count($authorList) - 1;
	    } else {
	        throw new \Exception("You must specify the first or the last author!");
        }
        $author = $authorList[$idx];
        list($authorFirstName, $authorLastName) = NameMatcher::splitName($author, 2, FALSE, FALSE);
        if (NameMatcher::matchByInitials($authorLastName, $authorFirstName, $lastName, $firstName)) {
            return TRUE;
        }
        return FALSE;
    }

	private function changeTextColorOfLink($str, $color) {
		if (preg_match("/<a /", $str)) {
			if (preg_match("/style\s*=\s*['\"]/", $str, $matches)) {
				$match = $matches[0];
				$str = str_replace($match, $match."color: $color; ", $str);
			} else {
				$str = preg_replace("/<a /", "<a style='color: $color;' ", $str);
			}
		}
		return $str;
	}

	private function makeTooltip() {
		$html = "";

		$pubTypes = $this->getPubTypes();
		$meshTerms = $this->getMESHTerms();

		if (count($pubTypes) > 0) {
			$html .= "<b>Publication Types (".self::changeTextColorOfLink(Links::makeLink("https://www.pubmed.gov", "PubMed"), "white")." or ".self::changeTextColorOfLink(Links::makeLink("https://eric.ed.gov/", "ERIC"), "white").")</b><br>".implode("<br>", $this->getPubTypes())."<br><br>";
		}
		if (count($meshTerms) > 0) {
			$html .= "<b>".self::changeTextColorOfLink(Links::makeLink("https://www.ncbi.nlm.nih.gov/mesh", "MESH Terms"), "white")."</b><br>".implode("<br>", $this->getMESHTerms())."<br><br>";
		}
        $cat = $this->getCategory();
        if ($this->getVariable("data_source") == "citation") {
            if ($cat == "Uncategorized") {
                $cat = "Currently $cat; may be automatically updated in the future";
            }
            $html .= "<b>".self::changeTextColorOfLink(Links::makeLink("https://icite.od.nih.gov", "iCite"), "white")." Category</b><br>$cat<br><br>";
        } else if (($this->getVariable("data_source") == "eric") && ($cat == "Peer Reviewed")) {
            $html .= "<b>Category</b><br>$cat<br><br>";
        }

		return $html;
	}

	public function hasChanged() {
		if (empty($this->origRow) && !empty($this->data)) {
			return TRUE;
		}
		foreach ($this->origRow as $field => $value) {
			$shortField = self::shortenField($field);
			if ($this->getVariable($shortField) != $value) {
				return TRUE;
			}
		}
		return FALSE;
	}

	private function resetOrigRow() {
		$this->origRow = array();
		foreach ($this->data as $field => $value) {
			$this->origRow["citation_".$field] = $value;
		}
	}

	private function resetData() {
		$this->data = array();
	}

	private static function shortenField($field) {
        $field = preg_replace("/^citation_/", "", $field);
        return preg_replace("/^eric_/", "", $field);
	}

	public function getGrants() {
	    $grantStr = $this->getVariable("grants");
        $grantStr = str_replace(" ", "", $grantStr);
	    if (!$grantStr) {
            return [];
        }
	    $grants = preg_split("/;/", $grantStr);
        $filteredGrants = [];
	    foreach ($grants as $grantNo) {
	        $grantNo = strtoupper(Grant::translateToBaseAwardNumber($grantNo));
	        if ($grantNo && !in_array($grantNo, $filteredGrants)) {
	            $filteredGrants[] = $grantNo;
            }
        }
	    return $filteredGrants;
    }

    public static function getFullMonth($mon) {
	    $num = self::getNumericMonth($mon);
	    $translate = [
            "01" => "January",
            "02" => "February",
            "03" => "March",
            "04" => "April",
            "05" => "May",
            "06" => "June",
            "07" => "July",
            "08" => "August",
            "09" => "September",
            "1" => "January",
            "2" => "February",
            "3" => "March",
            "4" => "April",
            "5" => "May",
            "6" => "June",
            "7" => "July",
            "8" => "August",
            "9" => "September",
            "10" => "October",
            "11" => "November",
            "12" => "December",
        ];
	    if (isset($translate[$num])) {
	        return $translate[$num];
        }
	    return $mon;
    }

	public static function getNumericMonth($mon) {
		$month = "";
		if (!$mon) {
			$month = "01";
		}
		if (is_numeric($mon)) {
			$month = $mon;
			if ($month < 10) {
				$month = "0".intval($month);
			}
		} else {
            if (preg_match("/-/", $mon)) {
                $nodes = preg_split("/-/", $mon);
                if ($nodes[0]) {
                    $mon = $nodes[0];
                }
            }
			$months = [
                "Jan" => "01",
                "Feb" => "02",
                "Mar" => "03",
                "Apr" => "04",
                "May" => "05",
                "Jun" => "06",
                "Jul" => "07",
                "Aug" => "08",
                "Sep" => "09",
                "Sept" => "09",
                "Oct" => "10",
                "Nov" => "11",
                "Dec" => "12",
                "Win" => "01",
                "Winter" => "01",
                "Spr" => "04",
                "Spring" => "04",
                "Sum" => "07",
                "Summer" => "07",
                "Fal" => "10",
                "Fall" => "10",
                "January" => "01",
                "February" => "02",
                "March" => "03",
                "April" => "04",
                "June" => "06",
                "July" => "07",
                "August" => "08",
                "September" => "09",
                "October" => "10",
                "November" => "11",
                "December" => "12",
            ];
			if (isset($months[$mon])) {
				$month = $months[$mon];
			}
		}
		if (!$month) {
			return $mon;
		}
		return $month;
	}

	public static function getTimestampFromText($text, $recordId) {
		if (!$text) {
			return 0;
		}
		$nodes = preg_split("/[\.\?]\s+/", $text);
		$date = "";
		$i = 0;
		$issue = "";
		while (!$date && $i < count($nodes)) {
			if (preg_match("/;/", $nodes[$i]) && preg_match("/\d\d\d\d.*;/", $nodes[$i])) {
				$a = preg_split("/;/", $nodes[$i]);
				$date = $a[0];
				$issue = $a[1];
			}
			$i++;
		}
		if ($date) {
			$dateNodes = preg_split("/\s+/", $date);
	
			$year = $dateNodes[0];
			$month = "";
			$day = "";

			if (count($dateNodes) == 1) {
				$month = "01";
			} else {
				$month = Citation::getNumericMonth($dateNodes[1]);
			}
	
			if (count($dateNodes) <= 2) {
				$day = "01";
			} else {
				$day = $dateNodes[2];
				if ($day < 10) {
					$day = "0".intval($day);
				}
			}
			return strtotime($year."-".$month."-".$day);
		} else {
			return 0;
		}
	}

	public function readData() {
		if (empty($this->origRow)) {
			$this->downloadData();
		} else {
			$this->resetData();
            $this->setVariable("data_source", $this->origRow['redcap_repeat_instrument']);
			foreach ($this->origRow as $field => $value) {
				$shortField = self::shortenField($field);
                if ($value !== "") {
                    $this->setVariable($shortField, $value);
                }
			}
		}
	}

	private function downloadData() { 
		$redcapData = Download::fieldsForRecords($this->token, $this->server, Application::getCitationFields($this->metadata), array($this->getRecordId()));
		foreach ($redcapData as $row) {
			if (($row['record_id'] == $this->getRecordId()) && ($row['redcap_repeat_instrument'] == "citation") && ($row['redcap_repeat_instance'] == $this->getInstance())) {
				foreach ($row as $field => $value) {
					$shortField = self::shortenField($field);
					$this->setVariable($shortField, $value);
				}
				$this->origRow = $row;
				break;
			}
		}
	}

    public function isFlagged() {
        $pid = Application::getPID($this->token);
        return (Publications::areFlagsOn($pid)) && ($this->getVariable("flagged") === "1");
    }

	public function getVariable($var) {
		$var = strtolower(preg_replace("/^citation_/", "", $var));
		if (isset($this->data[$var])) {
            $value = $this->data[$var];
		    $value = REDCapManagement::stripHTML($value);
		    $value = REDCapManagement::formatMangledText($value);     // REDCap and PubMed save in incompatible formats
		    return $value;
		}
		return "";
	}

	private function setVariable($var, $val) {
		$this->data[$var] = $val;
	}

	public function getID() {
        if ($this->getVariable("data_source") == "eric") {
            return $this->getERICID();
        } else if ($this->getVariable("data_source") == "citation") {
            return $this->getPMID();
        }
        return "";
	}

	public function getUniqueID() {
        if ($this->getVariable("data_source") == "eric") {
            return $this->getERICID();
        } else if ($this->getVariable("data_source") == "citation") {
            return "PMID".$this->getPMID();
        }
        return "";
	}

    public function getERICID() {
        return $this->getVariable("id");
    }

	public function getPMID() {
		return $this->getVariable("pmid");
	}

	public function getPMCWithPrefix() {
		$pmc = $this->getPMC();
		if ($pmc && !preg_match("/PMC/", $pmc)) {
			$pmc = "PMC".$pmc;
		}
		return $pmc;
	}

	public function getPMCWithoutPrefix() {
		$pmc = $this->getVariable("pmcid");
		return preg_replace("/PMC/", "", $pmc);
	}

	public function getPMC() {
		return $this->getVariable("pmcid");
	}

	public function getVariables() {
		return json_encode($this->data);
	}

	public static function explodeList($str) {
		if ($str) {
			return explode("; ", $str);
		} else {
			return [];
		}
	}

	public function getPubTypes() {
        $possibleVariables = [ "pub_types", "publicationtype"];
        foreach ($possibleVariables as $var) {
            $str = $this->getVariable($var);
            if ($str) {
                return self::explodeList($str);
            }
        }
        return [];
	}

	public function getMESHTerms() {
		$str = $this->getVariable("mesh_terms");
		return self::explodeList($str);
	}

    public function getSubjects() {
        $str = $this->getVariable("subject");
        return self::explodeList($str);
    }

	public function getType() {
        $pid = Application::getPID($this->token);
        if (Publications::areFlagsOn($pid)) {
            $flagged = $this->isFlagged();
            return $flagged ? "Flagged" : "Unflagged";
        }
		$include = $this->getVariable("include");
		if ($include === "") {
			return "New";
		} else if ($include === "0") {
			return "Omit";
		} else {
			return "Final";
		}
	}

	public static function transformYear($year) {
        if ($year) {
            if (is_numeric($year) && ($year < 100)) {
                $year += 2000;
            }
        }
        return $year;
    }

	private function getYear() {
        if ($this->getVariable("data_source") == "citation") {
            $year = $this->getVariable("year");
        } else if ($this->getVariable("data_source") == "eric") {
            $year = $this->getVariable("publicationdateyear");
        } else {
            $year = "";
        }
        return self::transformYear($year);
	}

	public static function transformIntoDate($year, $month, $day) {
        $translateMonth = array(
            1 => "January",
            2 => "February",
            3 => "March",
            4 => "April",
            5 => "May",
            6 => "June",
            7 => "July",
            8 => "August",
            9 => "Septemeber",
            10 => "October",
            11 => "November",
            12 => "December",
        );
        $date = "";

        if ($year) {
            $date .= $year;
        }

        if ($month) {
            if (is_numeric($month)) {
                $month = $translateMonth[intval($month)];
            }
            if ($date) {
                $date .= " ";
            }
            $date .= $month;
        }

        if ($day) {
            if ($date) {
                $date .= " ";
            }
            $date .= $day;
        }

        return $date;
    }

	public function getDate($dateAsNumber = FALSE) {
        if ($this->getVariable("data_source") == "eric") {
            $year = $this->getYear();
            if ($sourceID = $this->getVariable("sourceid")) {
                return self::getDateFromSourceID($sourceID, $year);
            }
            return $year."-01-01";
        } else if ($this->getVariable("data_source") == "citation") {
            $year = $this->getYear();
            $sep = "-";
            if ($dateAsNumber) {
                $month = self::getNumericMonth($this->getVariable("month"));
            } else {
                $month = self::getFullMonth($this->getVariable("month"));
            }
            $day = $this->getVariable("day");
            if ($dateAsNumber) {
                if ($month && $day && $year) {
                    return $month.$sep.$day.$sep.$year;
                } else if ($month && $day) {
                    return self::getFullMonth($this->getVariable("month"))." ".$day;
                } else if ($day && $year) {
                    return $year;   // deliberate
                } else if ($month && $year) {
                    return $month.$sep.$year;
                } else {
                    if ($year) {
                        return $year;
                    } else if ($month) {
                        return self::getFullMonth($this->getVariable("month"));
                    } else if ($day) {
                        return "Day ".$day;
                    } else {
                        return "";
                    }
                }
            } else {
                return self::transformIntoDate($year, $month, $day);
            }
        }
	}

	private function getIssueAndPages() {
		$vol = $this->getVariable("volume");
		$issue = $this->getVariable("issue");
		$pages = $this->getVariable("pages");

		$str = "";
		if ($vol) {
			$str .= $vol;
		}
		if ($issue) {
			$str .= "(".$issue.")";
		}
		if ($pages) {
			$str .= ":".$pages;
		}

		return $str;
	}

	private function getVolumeAndPages() {
		$vol = $this->getVariable("volume");
		$pages = $this->getVariable("pages");

		$str = "";
		if ($vol) {
			$str .= $vol;
		}
		if ($pages) {
			$str .= ":".$pages;
		}

		return $str;
	}

	private static function addPeriodIfExtant($str) {
	    if ($str) {
	        $str .= ". ";
        }
	    return $str;
    }

    public function getEtAlCitation() {
        $allAuthors = $this->getAuthorList();
        if (count($allAuthors) > 1) {
            $authorArray = [$allAuthors[0], "et al."];
        } else {
            $authorArray = $allAuthors;
        }
        $authors = self::addPeriodIfExtant(implode(", ", $authorArray));
        if ($this->getVariable("data_source") == "eric") {
            return $this->getERICCitation($authors);
        } else if ($this->getVariable("data_source") == "citation") {
            return $this->makePubMedCitation($authors);
        }
        return "";
    }

	public function getCitation($multipleNamesToBold = []) {
        $html = "";
        if (!empty($multipleNamesToBold)) {
            // Application::log("Has multiple names to bold: ".REDCapManagement::json_encode_with_spaces($multipleNamesToBold));
            $authorList = $this->getAuthorList();
            foreach ($multipleNamesToBold as $nameAry) {
                if (is_array($nameAry)) {
                    $firstName = $nameAry["firstName"];
                    $lastName = $nameAry["lastName"];
                } else if (is_string($nameAry)) {
                    list($firstName, $lastName) = NameMatcher::splitName($nameAry);
                } else {
                    $firstName = "";
                    $lastName = "";
                }
                $authorList = self::boldName($lastName, $firstName, $authorList);
                // Application::log("Bolding $lastName $firstName: ".REDCapManagement::json_encode_with_spaces($authorList));
            }
            $authors = self::addPeriodIfExtant(implode(", ", $authorList));
        } else {
            $authors = self::addPeriodIfExtant(implode(", ", self::boldName($this->lastName, $this->firstName, $this->getAuthorList())));
        }
        if ($this->getVariable("data_source") == "eric") {
            $html = $this->getERICCitation($authors);
        } else if ($this->getVariable("data_source") == "citation") {
            $html = $this->makePubMedCitation($authors);
        }
        return utf8_encode($html);
    }

    private function getERICCitation($authorText) {
        $title = self::addPeriodIfExtant($this->getVariable("title"));
        $journal = self::addPeriodIfExtant($this->getVariable("source"));
        $dateAndIssue = self::addPeriodIfExtant($this->getVariable("sourceid"));

        $citation = $authorText.$title.$journal.$dateAndIssue;
        if ($id = $this->getERICID()) {
            $citation .= self::addPeriodIfExtant($id);
        }
        return $citation;
    }

    public static function isCitation($str) {
        list($title, $journal) = self::getPublicationTitleAndJournalFromText($str);
        return ($title && $journal);
    }

    public static function getPublicationTitleAndJournalFromText($citationText) {
        foreach (["\\.", ","] as $sep) {
            if (preg_match("/$sep \d{4}$sep ([^$sep]+)$sep ([^;$sep]+)[$sep;] [\(\)\d\w\-:]+/", $citationText, $matches)) {
                return [$matches[1], $matches[2]];
            } else if (preg_match("/$sep ([^$sep]+)$sep ([^$sep]+)[$sep] \d{4}/", $citationText, $matches)) {
                return [$matches[1], $matches[2]];
            }
        }
        $nodes = preg_split("/\s*\.\s+/", $citationText);
        if ($nodes >= 4) {
            $authors = $nodes[0];
            $date = "";
            $journal = "";
            $title = "";
            for ($i = 1; $i < count($nodes); $i++) {
                if ($nodes[$i] === "") {
                } else if (DateManagement::isDate($nodes[$i]) || DateManagement::isYear($nodes[$i])) {
                    $date = $nodes[$i];
                } else if (preg_match("/^(.+);(\d+\(\d+\)):([\d-]+)$/", $nodes[$i], $matches)) {
                    $preNode = $matches[1];
                    if ($title !== "") {
                        $journal = $preNode;
                    } else {
                        $title = $preNode;
                    }
                    $volumeAndIssue = $matches[2];
                    $pages = $matches[3];
                } else if ($title !== "") {
                    $journal = $nodes[$i];
                } else {
                    $title = $nodes[$i];
                }
            }
            if ($journal && $title) {
                return [$title, $journal];
            }
        }
        return ["", ""];
    }

    public static function getJournalFromText($citationText) {
        foreach (["\.", ","] as $sep) {
            if (preg_match("/$sep \d{4}$sep [^$sep]+$sep ([^$sep]+)$sep [\d\-:]+/", $citationText, $matches)) {
                return $matches[1];
            } else if (preg_match("/$sep [^$sep]+$sep ([^$sep]+)$sep \d{4}/", $citationText, $matches)) {
                return $matches[1];
            }
        }
        return ["", ""];
    }

    public function getPubMedCitation($addDOI = TRUE) {
        $authors = self::addPeriodIfExtant(implode(", ", $this->getAuthorList()));
        return $this->makePubMedCitation($authors, $addDOI);
    }

    private function makePubMedCitation($authorText, $addDOI = TRUE) {
        $title = self::addPeriodIfExtant($this->getVariable("title"));
        $journal = self::addPeriodIfExtant($this->getVariable("journal"));

        $date = $this->getDate();
        $issue = $this->getIssueAndPages();
        $dateAndIssue = $date;
        if ($dateAndIssue && $issue) {
            $dateAndIssue .= "; ".$issue;
        } else if ($this->getIssueAndPages()) {
            $dateAndIssue = $issue;
        }
        $dateAndIssue = self::addPeriodIfExtant($dateAndIssue);

	    $citation = $authorText.$title.$journal.$dateAndIssue;
		$doi = $this->getVariable("doi");
		if ($doi && $addDOI) {
			$citation .= self::addPeriodIfExtant("doi:".$doi);
		}
		return $citation;
	}

    public function getBoldedNames() {
        $authors = $this->getAuthorList();
        $matchedAuthors = [];
        foreach ($authors as $author) {
            list($authorFirstName, $authorLastName) = NameMatcher::splitName($author, 2, FALSE, FALSE);
            if (NameMatcher::matchByLastName($authorLastName, $this->lastName)) {
                $matchedAuthors[] = $author;
            }
        }
        return $matchedAuthors;
    }

    public static function splitAuthorList($authorList) {
        if (preg_match("/;/", $authorList)) {
            return preg_split("/\s*;\s*/", $authorList);
        } else {
            return preg_split("/\s*,\s*/", $authorList);
        }
    }

	public function getAuthorList() {
        if ($this->getVariable("data_source") == "eric") {
            return self::splitAuthorList($this->getVariable("author"));
        } else {
            return self::splitAuthorList($this->getVariable("authors"));
        }
	}

    private static function updateISSNs(&$issns, $medAbbr, $isoAbbr, $issnPrint, $issnOnline) {
        $abbreviations = [];
        if ($medAbbr) {
            $abbreviations[] = $medAbbr;
        }
        if ($isoAbbr) {
            $abbreviations[] = $isoAbbr;
        }
        if (empty($abbreviations)) {
            return;
        }
        if ($issnPrint) {
            $issns[$issnPrint] = $abbreviations;
        }
        if ($issnOnline) {
            $issns[$issnOnline] = $abbreviations;
        }
    }

    public static function getISSNsForAbbreviations() {
        list($resp, $output) = URLManagement::downloadURL(self::PUBMED_JOURNAL_URL);
        $issns = [];
        if ($resp === 200) {
            $lines = preg_split("/[\n\r]+/", $output);
            $medAbbr = "";
            $isoAbbr = "";
            $issnPrint = "";
            $issnOnline = "";
            foreach ($lines as $line) {
                if (preg_match("/^----------/", $line)) {
                    self::updateISSNs($issns, $medAbbr, $isoAbbr, $issnPrint, $issnOnline);
                    $issnPrint = "";
                    $issnOnline = "";
                    $medAbbr = "";
                    $isoAbbr = "";
                } else if (preg_match("/^MedAbbr: /", $line)) {
                    $medAbbr = trim(preg_replace("/^MedAbbr: /", "", $line));
                } else if (preg_match("/^IsoAbbr: /", $line)) {
                    $isoAbbr = trim(preg_replace("/^IsoAbbr: /", "", $line));
                } else if (preg_match("/^ISSN \(Print\): /", $line)) {
                    $issnPrint = trim(preg_replace("/^ISSN \(Print\): /", "", $line));
                } else if (preg_match("/^ISSN \(Online\): /", $line)) {
                    $issnOnline = trim(preg_replace("/^ISSN \(Online\): /", "", $line));
                }
            }
            self::updateISSNs($issns, $medAbbr, $isoAbbr, $issnPrint, $issnOnline);
        }
        return $issns;
    }

    public static function getJournalTranslations() {
        list($resp, $output) = URLManagement::downloadURL(self::PUBMED_JOURNAL_URL);
        $journals = [];
        if ($resp === 200) {
            $lines = preg_split("/[\n\r]+/", $output);
            $title = "";
            $medAbbr = "";
            $isoAbbr = "";
            foreach ($lines as $line) {
                if (preg_match("/^----------/", $line)) {
                    if ($medAbbr && $title) {
                        $journals[$medAbbr] = $title;
                    }
                    if ($isoAbbr && $title) {
                        $journals[$isoAbbr] = $title;
                    }
                    $title = "";
                    $medAbbr = "";
                    $isoAbbr = "";
                } else if (preg_match("/^JournalTitle: /", $line)) {
                    $title = trim(preg_replace("/^JournalTitle: /", "", $line));
                } else if (preg_match("/^MedAbbr: /", $line)) {
                    $medAbbr = trim(preg_replace("/^MedAbbr: /", "", $line));
                } else if (preg_match("/^IsoAbbr: /", $line)) {
                    $isoAbbr = trim(preg_replace("/^IsoAbbr: /", "", $line));
                }
            }
            if ($medAbbr && $title) {
                $journals[$medAbbr] = $title;
            }
            if ($isoAbbr && $title) {
                $journals[$isoAbbr] = $title;
            }
        }
        return $journals;
    }

	public static function getNamesFromNodes($nameNodes) {
        $currLastNames = [];
        if (count($nameNodes) > 2) {
            for ($i = 0; $i < count($nameNodes) - 1; $i++) {
                $currLastNames[] = $nameNodes[$i];
            }
            $currLastName = implode(" ", $currLastNames);
            $currFirstInitial = $nameNodes[count($nameNodes) - 1];
        } else {
            $currLastName = $nameNodes[0];
            $currFirstInitial = $nameNodes[1];
        }
        return [$currLastNames, $currFirstInitial, $currLastName];
    }

    private static function isBolded($name) {
	    return preg_match("/<b>/i", $name) || preg_match("/<strong>/i", $name);
    }

	public static function boldName($lastName, $firstName, $authorList) {
        $lastName = trim($lastName);
        $firstName = trim($firstName);
		$newAuthorList = [];
		$boldedName = FALSE;
		foreach ($authorList as $name) {
            if (NameMatcher::matchPubMedName($name, $firstName, $lastName)) {

            }
            $newAuthorList[] = $name;
        }

		if (!$boldedName) {
		    $authorList = $newAuthorList;
		    $newAuthorList = [];
            foreach ($authorList as $name) {
                $nameNodes = preg_split("/\s+/", $name);
                if (count($nameNodes) >= 2) {
                    list($currLastNames, $currFirstInitial, $currLastName) = self::getNamesFromNodes($nameNodes);
                    if ($lastName) {
                        if (NameMatcher::matchByLastName($lastName, $currLastName)) {
                            if (!self::isBolded($name)) {
                                $name = "<strong>" . $name . "</strong>";
                            }
                        } else {
                            foreach (NameMatcher::explodeLastName($lastName) as $ln) {
                                $matched = FALSE;
                                if (NameMatcher::matchByLastName($ln, $currLastName)) {
                                    $matched = TRUE;
                                }
                                foreach (NameMatcher::explodeLastName($currLastName) as $ln2) {
                                    if (NameMatcher::matchByLastName($ln, $ln2)) {
                                        $matched = TRUE;
                                    }
                                }
                                if ($matched) {
                                    if (!self::isBolded($name)) {
                                        $name = "<strong>" . $name . "</strong>";
                                    }
                                    break;    // inner
                                }
                            }
                        }
                    }
                }
                $newAuthorList[] = $name;
            }
        }
		return $newAuthorList;
	}

	public function getNIHFormat($traineeLastName, $traineeFirstName, $includeIDs = FALSE, $includeDOI = FALSE) {
        if ($this->getVariable("data_source") == "eric") {
            return "";
        }
        $authors = self::addPeriodIfExtant(implode(", ", self::boldName($traineeLastName, $traineeFirstName, $this->getAuthorList())));
        $citation = $this->makePubMedCitation($authors, $includeDOI);
		if ($includeIDs) {
		    if ($pmid = $this->getPMID()) {
                $citation .= self::addPeriodIfExtant("PMID ".$pmid);
            }
		    if ($pmc = $this->getPMCWithoutPrefix()) {
		        $citation .= self::addPeriodIfExtant("PMC".$pmc);
            }
        }
		return $citation;
	}

	public function getImage($alignment = "left") {
	    if ($this->origRow["citation_altmetric_image"]) {
	        $img = "<img src='".$this->origRow["citation_altmetric_image"]."' align='$alignment'  style='width: 48px; height: 48px;' alt='Altmetrics'>";
	        if ($this->origRow["citation_altmetric_details_url"]) {
	            return "<a href='".$this->origRow["citation_altmetric_details_url"]."'>$img</a>";
            }
	        return $img;
        }
	    return "";
    }

    public function getEtAlCitationWithLink($includeREDCapLink = TRUE, $newTarget = FALSE) {
        $base = $this->getEtAlCitation();
        return $this->makeAddOns($base, $includeREDCapLink, $newTarget);
    }

	public function getCitationWithLink($includeREDCapLink = TRUE, $newTarget = FALSE, $namesToBold = []) {
        $base = $this->getCitation($namesToBold);
        return $this->makeAddOns($base, $includeREDCapLink, $newTarget);
    }

    private function makeAddOns($base, $includeREDCapLink, $newTarget) {
        global $event_id;
        $pid = Application::getPID($this->token);

        if ($this->getVariable("data_source") == "eric") {
            $fullTextURL = $this->getVariable("e_fulltext");
            $locationText = $fullTextURL ? " ".Links::makeLink($fullTextURL, "Full Text", $newTarget) : "";

            if (!$locationText) {
                $journalURL = $this->getVariable("url");
                $locationText = $journalURL ? " ".Links::makeLink($journalURL, "Full Text", $newTarget) : "";
            }

            $ericURL = $this->getURL();
            $ericText = $ericURL ? " ".Links::makeLink($ericURL, "ERIC", $newTarget) : "";

            if ($includeREDCapLink && $this->getRecordId() && $this->getInstance()) {
                $redcap = " ".Links::makeERICLink($pid, $this->getRecordId(), $event_id, "Citation in REDCap", $this->getInstance(), TRUE);
            } else {
                $redcap = "";
            }

            return $base.$locationText.$redcap.$ericText;
        } else {
            $doi = $this->getVariable("doi");
            if (preg_match("/doi:/", $base)) {
                $base = str_replace("doi:$doi", Links::makeLink("https://www.doi.org/".$doi, "doi:".$doi, $newTarget), $base);
                $doiLink = "";
            } else if ($doi) {
                $doiLink = Links::makeLink("https://www.doi.org/".$doi, "doi:".$doi, $newTarget);
            } else {
                $doiLink = "";
            }

            if ($this->getPMID() && !preg_match("/PMID\s*\d/", $base)) {
                $pmidText = " PubMed PMID: ".$this->getPMID();
            } else {
                $pmidText = "";
            }

            if ($includeREDCapLink && $this->getInstance() && $this->getRecordId()) {
                $redcap = " ".Links::makePublicationsLink($pid, $this->getRecordId(), $event_id, "Citation in REDCap", $this->getInstance(), TRUE);
            } else {
                $redcap = "";
            }

            $pmcWithPrefix = $this->getPMCWithPrefix();
            if ($pmcWithPrefix && !preg_match("/PMC\d/", $base)) {
                $pmcText = " ".$pmcWithPrefix.".";
            } else {
                $pmcText = "";
            }

            $baseWithPMC = $base.$doiLink.$pmidText.$redcap.$pmcText;
            if ($pmcWithPrefix) {
                $baseWithPMCLink = preg_replace("/".$pmcWithPrefix."/", Links::makeLink($this->getPMCURL(), $pmcWithPrefix, $newTarget), $baseWithPMC);
            } else {
                $baseWithPMCLink = $baseWithPMC;
            }
            return preg_replace("/PubMed PMID:\s*".$this->getPMID()."/", Links::makeLink($this->getURL(), "PubMed PMID: ".$this->getPMID(), $newTarget), $baseWithPMCLink);
        }
	}

	public function getPMCURL() {
	    return self::getURLForPMC($this->getPMCWithPrefix());
    }

    public static function getURLForPMC($pmcid) {
		return "https://www.ncbi.nlm.nih.gov/pmc/articles/".$pmcid;
	}

	public function getURL() {
        if ($this->getVariable("data_source") == "eric") {
            return self::getURLForERIC($this->getERICID());
        } else if ($this->getVariable("data_source") == "citation") {
            return self::getURLForPMID($this->getPMID());
        }
        return "";
	}

    public static function getURLForERIC($id) {
        return "https://eric.ed.gov/?id=".$id;
    }

	public static function getURLForPMID($pmid) {
        return "https://www.ncbi.nlm.nih.gov/pubmed/?term=".$pmid;
    }

	public function isResearch() {
		return $this->isResearchArticle();
	}

	public function isResearchArticle() {
        if ($this->getVariable("data_source") == "citation") {
            return ($this->getCategory() == "Original Research");
        } else if ($this->getVariable("data_source") == "eric") {
            return ($this->getVariable("peerreviewed") == "1");
        }
        return FALSE;
	}

	public function isIncluded() {
		return $this->getVariable("include");
	}

    public static function getInstrumentFromId($id) {
        if (preg_match("/^E[DJ]/i", $id)) {
            return "eric";
        } else {
            return "citation";
        }
    }

	private function writeToDB() {
		$row = [
				"record_id" => $this->getRecordId(),
				"redcap_repeat_instance" => $this->getInstance(),
				];
        if ($this->getVariable("data_source") == "eric") {
            $row['redcap_repeat_instrument'] = "eric";
            foreach ($this->data as $field => $value) {
                $row['eric_'.$field] = $value;
            }
            $row['eric_complete'] = '2';
        } else {
            $row['redcap_repeat_instrument'] = "citation";
            foreach ($this->data as $field => $value) {
                $row['citation_'.$field] = $value;
            }
            $row['citation_complete'] = '2';
        }
        Upload::oneRow($row, $this->token, $this->server);
	}

	public function stageForReview() {
		$this->setVariable("include", "");
		$this->writeToDB();
	}

	public function includePub() {
		$this->setVariable("include", "1");
		$this->writeToDB();
	}

	public function omit() {
		$this->setVariable("include", "0");
		$this->writeToDB();
	}

	public function getCategory() {
        if ($this->getVariable("data_source") == "eric") {
            $isPeerReviewed = $this->getVariable("peerreviewed");
            if ($isPeerReviewed) {
                return "Peer Reviewed";
            } else {
                return "Not Peer Reviewed";
            }
        } else if ($this->getVariable("data_source") == "citation") {
            $override = $this->getVariable("is_research_override");
            if ($override !== "") {
                if ($override == "1") {
                    return "Original Research";
                } else if ($override == "2") {
                    return "Not Original Research";
                }
            }
            $val = $this->getVariable("is_research");
            if ($val == "1") {
                return "Original Research";
            } else if ($val === "0") {
                return "Not Original Research";
            } else {
                return "Uncategorized";
            }
        }
	}

	public static function getCategories() {
		return ["Original Research", "Not Original Research", "Peer Reviewed", "Not Peer Reviewed", "Uncategorized"];
	}

	public function inTimespan($startTs, $endTs) {
	    $ts = $this->getTimestamp();
	    return (($startTs <= $ts) && ($endTs >= $ts));
    }

    public static function getDateFromSourceID($sourceID, $pubYear) {
        $sep = "-";
        $sourceID = preg_replace("/v\d+-\d+\s+/", "", $sourceID);
        $sourceID = preg_replace("/v\d+\s+/", "", $sourceID);
        $sourceID = preg_replace("/n\d+-\d+\s+/", "", $sourceID);
        $sourceID = preg_replace("/n\d+\s+/", "", $sourceID);
        $sourceID = preg_replace("/p\d+-\d+\s+/", "", $sourceID);
        $sourceID = preg_replace("/p\d+\s+/", "", $sourceID);
        $sourceID = preg_replace("/\d+, \d+, [A-Z]?\d+-[A-Z]?\d+, /", "", $sourceID);
        $sourceID = preg_replace("/\d+, \d+, [A-Z]?\d+, /", "", $sourceID);
        $sourceID = preg_replace("/^[A-Z]?\d+-[A-Z]?\d+, /", "", $sourceID);
        $sourceDate = $sourceID;
        $dateNodes = preg_split("/\s+/", $sourceDate);
        # year may be two-digits or four-digits; if one dateNode, then year
        if (count($dateNodes) == 2) {
            $month = self::getNumericMonth($dateNodes[0]);
            return $pubYear.$sep.$month.$sep."01";
        } else if (count($dateNodes) == 3) {
            $month = self::getNumericMonth($dateNodes[0]);
            $day = "01";
            if (is_numeric($dateNodes[1])) {
                $day = $dateNodes[1];
            }
            return $pubYear.$sep.$month.$sep.$day;
        } else {
            return $pubYear.$sep."01".$sep."01";
        }
    }

    public static function transformDateToTimestamp($date) {
        if ($date) {
            $dateNodes = preg_split("/\s+/", $date);
            $year = $dateNodes[0];
            $months = [
                "Jan" => "01",
                "Feb" => "02",
                "Mar" => "03",
                "Apr" => "04",
                "May" => "05",
                "Jun" => "06",
                "Jul" => "07",
                "Aug" => "08",
                "Sep" => "09",
                "Oct" => "10",
                "Nov" => "11",
                "Dec" => "12",
                "January" => "01",
                "February" => "02",
                "March" => "03",
                "April" => "04",
                "June" => "06",
                "July" => "07",
                "August" => "08",
                "September" => "09",
                "October" => "10",
                "November" => "11",
                "December" => "12",
            ];

            $month = "01";
            if (count($dateNodes) == 1) {
                $month = "01";
            } else if (is_numeric($dateNodes[1])) {
                $month = $dateNodes[1];
                if ($month < 10) {
                    $month = "0".intval($month);
                }
            } else if (isset($months[$dateNodes[1]])) {
                $month = $months[$dateNodes[1]];
            } else if (preg_match("/[\/\-]/", $dateNodes[1])) {
                $monthNodes = preg_split("/[\/\-]/", $dateNodes[1]);
                $month = "01";
                foreach ($monthNodes as $monthNode) {
                    if (isset($months[$monthNode])) {
                        $month = $months[$monthNode];
                        break;
                    }
                }
            }

            $day = "01";
            if (count($dateNodes) > 2) {
                $day = $dateNodes[2];
                if ($day < 10) {
                    $day = "0".intval($day);
                }
            }
            return strtotime($year."-".$month."-".$day);
        } else {
            return 0;
        }
    }

	public function getTimestamp() {
		return self::transformDateToTimestamp($this->getDate());
	}

	public function getSource() {
        if ($this->getVariable("data_source") == "eric") {
            return "ERIC";
        }
		$src = $this->getVariable("source");
		if (isset($this->sourceChoices[$src])) {
			return $this->sourceChoices[$src];
		} else {
			if (!$this->sourceChoices && empty($this->sourceChoices)) {
                $pid = Application::getPID($this->token);
                if (empty($this->metadata) && $pid) {
                    $this->sourceChoices = DataDictionaryManagement::getChoicesForField($pid, "citation_source");
                } else {
                    $choices = Scholar::getChoices($this->metadata);
                    if (isset($choices["citation_source"])) {
                        $this->sourceChoices = $choices["citation_source"];
                    } else {
                        $this->sourceChoices = [];
                    }
                }
			}
			if (isset($this->sourceChoices[$src])) {
				return $this->sourceChoices[$src];
			} else {
				return $src;
			}
		}
	}

	public function getRecordId() {
		return $this->recordId;
	}

	public function getInstance() {
		return $this->instance;
	}

	private $data = array();
	private $origRow = array();
	private $recordId = 0;
	private $instance = 0;
	private $token = "";
	private $server = "";
	private $sourceChoices = array();
	private $metadata = [];
    private $firstName = "";
    private $lastName = "";
}

