<?php

namespace Vanderbilt\CareerDevLibrary;

# This class handles publication data from PubMed, the VICTR fetch routine, and surveys.
# It also provides HTML for data-wrangling the publication data

require_once(dirname(__FILE__)."/Upload.php");
require_once(dirname(__FILE__)."/Download.php");
require_once(dirname(__FILE__)."/Scholar.php");
require_once(dirname(__FILE__)."/Links.php");
require_once(dirname(__FILE__)."/iCite.php");
require_once(dirname(__FILE__)."/NameMatcher.php");
require_once(dirname(__FILE__)."/../Application.php");

class CitationCollection {
	# type = [ Final, New, Omit ]
	public function __construct($recordId, $token, $server, $type = 'Final', $redcapData = array(), $metadata = array(), $lastNames = [], $firstNames = []) {
		$this->token = $token;
		$this->server = $server;
		$this->citations = array();
		if (empty($metadata)) {
		    $this->metadata = Download::metadata($token, $server);
        } else {
            $this->metadata = $metadata;
        }
		if (empty($redcapData)) {
			$redcapData = Download::fieldsForRecords($token, $server, Application::getCitationFields($this->metadata), array($recordId));
		}
		if (empty($lastNames)) {
		    $lastNames = Download::lastnames($token, $server);
        }
        if (empty($firstNames)) {
            $firstNames = Download::firstnames($token, $server);
        }
		foreach ($redcapData as $row) {
			if (($row['redcap_repeat_instrument'] == "citation") && ($row['record_id'] == $recordId)) {
				$c = new Citation($token, $server, $recordId, $row['redcap_repeat_instance'], $row, $this->metadata, $lastNames[$recordId], $firstNames[$recordId]);
				if ($c->getType() == $type) {
					array_push($this->citations, $c);
				}
			}
		}
	}

	# citationClass is notDone, included, or omitted
	public function toHTML($citationClass) {
		$html = "";
		$i = 0;
		if (count($this->getCitations()) == 0) {
			$html .= "<p class='centered'>None to date.</p>\n";
		} else {
			foreach ($this->getCitations() as $citation) {
				$html .= $citation->toHTML($citationClass);
				$i++;
			}
		}
		return $html;
	}

	# for book-keeping purposes only; does not write to DB
	public function removePMID($pmid) {
		if ($this->has($pmid)) {
			$newCitations = array();
			foreach ($this->getCitations() as $citation) {
				if ($citation->getPMID() != $pmid) {
					array_push($newCitations, $citation);
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
				array_push($ids, $pmid);
			}
		}
		return $ids;
	}

	public function getCitations() {
		$this->sortCitations();
		return $this->citations;
	}

	private static function sortArrays($unorderedArys, $field) {
		$keys = array();
		foreach ($unorderedArys as $ary) {
			array_push($keys, $ary[$field]);
		}
		rsort($keys);

		if (count($keys) != count($unorderedArys)) {
			throw new \Exception("keys (".count($keys).") != unorderedArys (".count($unorderedArys).")");
		}

		$ordered = array();
		foreach ($keys as $key) {
			$found = FALSE;
			foreach ($unorderedArys as $i => $ary) {
				if ($ary[$field] == $key) {
					array_push($ordered, $ary);
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

	public function sortCitations() {
		$unsorted = array();
		foreach ($this->citations as $citation) {
			array_push($unsorted, array(
							"citation" => $citation,
							"timestamp" => $citation->getTimestamp(),
							));
		}
		$sorted = self::sortArrays($unsorted, "timestamp");
		if (count($unsorted) != count($sorted)) {
			throw new \Exception("Unsorted (".count($unsorted).") != sorted (".count($sorted).")");
		}

		$this->citations = array();
		foreach ($sorted as $ary) {
			array_push($this->citations, $ary['citation']);
		}
	}

	public function addCitation($citation) {
		if (get_class($citation) == "Citation") {
			array_push($this->citations, $citation);
		} else {
			throw new \Exception("addCitation tries to add a citation of class ".get_class($citation).", instead of class Citation!");
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
		return count($this->citations);
	}

	private $citations = array();
	private $token = "";
	private $server = "";
	private $metadata = [];
}

class Citation {
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
			$this->sourceChoices = array();
		}

		$this->readData();
	}

	public static function getImageSize() {
		return 26;
	}

	# citationClass is notDone, included, or omitted
	public function toHTML($citationClass) {
		if ($citationClass == "notDone") {
			$checkboxClass = "checked";
		} else if ($citationClass == "included") {
			$checkboxClass = "readonly";
		} else if ($citationClass == "omitted") {
			$checkboxClass = "unchecked";
		} else {
			throw new \Exception("Unknown citationClass $citationClass");
		}

		$ableToReset = ["included", "omitted"];

		$html = "";
		$source = $this->getSource();
		if ($source) {
			$source = "<span class='sourceInCitation'>" . $source . "</span>: ";
		}
		$id = $this->getUniqueID();
		$pmid = $this->getPMID();
		$html .= "<div class='citation $citationClass' id='citation_".$citationClass."$id'>";
		$html .= "<div class='citationCategories'><span class='tooltiptext'>".$this->makeTooltip()."</span>".$this->getCategory()."</div>";
		$html .= self::makeCheckbox($id, $checkboxClass)." ".$source.$this->getCitationWithLink();
		if (in_array($citationClass, $ableToReset)) {
            $html .= "<div style='text-align: right;' class='smallest'><span onclick='resetCitation(\"$id\");' class='finger'>reset</span></div>";
        }
		$html .= "</div>\n";
		return $html;
	}

	public function hasAuthor($name) {
		list($firstName, $lastName) = NameMatcher::splitName($name);
		if ($lastName) {
			$authorList = $this->getAuthorList();
			foreach ($authorList as $author) {
				list($currFirstName, $currLastName) = NameMatcher::splitName($author);
                if (NameMatcher::matchByInitials($currLastName, $currFirstName, $lastName, $firstName)) {
                    return TRUE;
                }
			}
		}
		return FALSE;
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
        list($authorFirstName, $authorLastName) = NameMatcher::splitName($author);
        if (NameMatcher::matchByInitials($authorFirstName, $authorLastName, $lastName, $firstName)) {
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
			$html .= "<b>".self::changeTextColorOfLink(Links::makeLink("https://www.pubmed.gov", "PubMed"), "white")." Publication Types</b><br>".implode("<br>", $this->getPubTypes())."<br><br>";
		}
		if (count($meshTerms) > 0) {
			$html .= "<b>".self::changeTextColorOfLink(Links::makeLink("https://www.ncbi.nlm.nih.gov/mesh", "MESH Terms"), "white")."</b><br>".implode("<br>", $this->getMESHTerms())."<br><br>";
		}
		$cat = $this->getCategory();
		if ($cat == "Uncategorized") {
			$cat = "Currently $cat; may be automatically updated in the future";
		}
		$html .= "<b>".self::changeTextColorOfLink(Links::makeLink("https://icite.od.nih.gov", "iCite"), "white")." Category</b><br>$cat<br><br>";

		return $html;
	}

	# img is unchecked, checked, or readonly
	private static function makeCheckbox($id, $img) {
	    $validImages = ["unchecked", "checked", "readonly"];
	    if (!in_array($img, $validImages)) {
	        throw new \Exception("Image ($img) must be in: ".implode(", ", $validImages));
        }
		$imgFile = "wrangler/".$img.".png";
		$size = self::getImageSize()."px";
		$js = "if ($(this).attr(\"src\").match(/unchecked/)) { $(\"#$id\").val(\"include\"); $(this).attr(\"src\", \"".Application::link("wrangler/checked.png")."\"); } else { $(\"#$id\").val(\"exclude\"); $(this).attr(\"src\", \"".Application::link("wrangler/unchecked.png")."\"); }";
		if ($img == "unchecked") {
			$value = "exclude";
		} else if ($img == "checked") {
			$value = "include";
		} else {
			$value = "";
		}
		$input = "<input type='hidden' id='$id' value='$value'>";
		if (($img == "unchecked") || ($img == "checked")) {
			return "<img src='".Application::link($imgFile)."' id='image_$id' onclick='$js' style='width: $size; height: $size;' align='left'>".$input;
		}
		if ($img == "readonly") {
			return "<img src='".Application::link($imgFile)."' id='image_$id' style='width: $size; height: $size;' align='left'>".$input;
		}
		return "";
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
		return preg_replace("/^citation_/", "", $field);
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
			$months = array("Jan" => "01", "Feb" => "02", "Mar" => "03", "Apr" => "04", "May" => "05", "Jun" => "06", "Jul" => "07", "Aug" => "08", "Sep" => "09", "Oct" => "10", "Nov" => "11", "Dec" => "12");
			if (isset($months[$mon])) {
				$month = $months[$mon];
			}
		}
		if (!$month) {
			throw new \Exception("Could not convert $mon into month!");
		}
		return $month;
	}

	public static function createCitationFromText($text, $recordId) {
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
			foreach ($this->origRow as $field => $value) {
				$shortField = self::shortenField($field);
				$this->setVariable($shortField, $value);
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
		return $this->getPMID();
	}

	public function getUniqueID() {
		return "PMID".$this->getPMID();
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
			return array();
		}
	}

	public function getPubTypes() {
		$str = $this->getVariable("pub_types");
		return self::explodeList($str);
	}

	public function getMESHTerms() {
		$str = $this->getVariable("mesh_terms");
		return self::explodeList($str);
	}

	public function getType() {
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
		$year = $this->getVariable("year");
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

	private function getDate() {
		$year = $this->getYear();
		$month = $this->getVariable("month");
		$day = $this->getVariable("day");
		return self::transformIntoDate($year, $month, $day);
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

	public function getCitation($multipleNamesToBold = []) {
	    if (!empty($multipleNamesToBold)) {
	        $authorList = $this->getAuthorList();
	        foreach ($multipleNamesToBold as $nameAry) {
	            $firstName = $nameAry["firstName"];
	            $lastName = $nameAry["lastName"];
	            $authorList = self::boldName($lastName, $firstName, $authorList);
            }
	        $authors = self::addPeriodIfExtant(implode(", ", $authorList));
        } else {
            $authors = self::addPeriodIfExtant(implode(", ", self::boldName($this->lastName, $this->firstName, $this->getAuthorList())));
        }
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

	    $citation = $authors.$title.$journal.$dateAndIssue;
		$doi = $this->getVariable("doi");
		if ($doi) {
			$citation .= self::addPeriodIfExtant("doi:".$doi);
		}
		return $citation;
	}

	public function getAuthorList() {
		$authorList = preg_split("/\s*,\s*/", $this->getVariable("authors"));
		return $authorList;
	}

	private static function getNamesFromNodes($nameNodes) {
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
	    return preg_match("/<b>/", $name);
    }

	public static function boldName($lastName, $firstName, $authorList) {
        $lastName = trim($lastName);
        $firstName = trim($firstName);
		$newAuthorList = [];
		$boldedName = FALSE;
		foreach ($authorList as $name) {
            $nameNodes = preg_split("/\s+/", $name);
            if (count($nameNodes) >= 2) {
                list($currLastNames, $currFirstInitial, $currLastName) = self::getNamesFromNodes($nameNodes);
                if ($firstName && $lastName) {
                    if (NameMatcher::matchByInitials($lastName, $firstName, $currLastName, $currFirstInitial)) {
                        if (!self::isBolded($name)) {
                            $name = "<b>" . $name . "</b>";
                        }
                        $boldedName = TRUE;
                    } else {
                        // for double last names - must loop through both last names
                        foreach (NameMatcher::explodeLastName($lastName) as $ln) {
                            $matched = FALSE;
                            if (NameMatcher::matchByInitials($ln, $firstName, $currLastName, $currFirstInitial)) {
                                $matched = TRUE;
                            }
                            foreach ($currLastNames as $ln2) {
                                if (NameMatcher::matchByInitials($ln, $firstName, $ln2, $currFirstInitial)) {
                                    $matched = TRUE;
                                }
                            }
                            if ($matched) {
                                if (!self::isBolded($name)) {
                                    $name = "<b>" . $name . "</b>";
                                }
                                $boldedName = TRUE;
                                break;    // inner
                            }
                        }
                    }
                }
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
                                $name = "<b>" . $name . "</b>";
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
                                        $name = "<b>" . $name . "</b>";
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

	public function getNIHFormat($traineeLastName, $traineeFirstName, $includeIDs = FALSE) {
        $authors = self::addPeriodIfExtant(implode(", ", self::boldName($traineeLastName, $traineeFirstName, $this->getAuthorList())));
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

        $citation = $authors.$title.$journal.$dateAndIssue;
        $doi = $this->getVariable("doi");
        if ($doi) {
            $citation .= self::addPeriodIfExtant("doi:".$doi);
        }
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

	public function getCitationWithLink($includeREDCapLink = TRUE, $newTarget = FALSE) {
		global $pid, $event_id;

		$base = $this->getCitation();

		$doi = $this->getVariable("doi");
		if ($doi) {
			$baseWithDOILink = str_replace("doi:".$doi, Links::makeLink("https://www.doi.org/".$doi, "doi:".$doi, TRUE), $base);
		} else {
			$baseWithDOILink = $base;
		}

		if ($this->getPMID() && !preg_match("/PMID\s*\d/", $baseWithDOILink)) {
			$baseWithPMID = $baseWithDOILink." PubMed PMID: ".$this->getPMID();
		} else {
			$baseWithPMID = $baseWithDOILink;
		}

		if ($includeREDCapLink && $this->getInstance() && $this->getRecordId()) {
			$baseWithREDCap = $baseWithPMID." ".Links::makePublicationsLink($pid, $this->getRecordId(), $event_id, "REDCap", $this->getInstance(), TRUE);
		} else {
			$baseWithREDCap = $baseWithPMID;
		}

		$pmcWithPrefix = $this->getPMCWithPrefix();
		if ($pmcWithPrefix && !preg_match("/PMC\d/", $baseWithREDCap)) {
			$baseWithPMC = $baseWithREDCap." ".$pmcWithPrefix.".";
		} else {
			$baseWithPMC = $baseWithREDCap;
		}

		if ($pmcWithPrefix) {
            $baseWithPMCLink = preg_replace("/".$pmcWithPrefix."/", Links::makeLink($this->getPMCURL(), $pmcWithPrefix), $baseWithPMC);
        } else {
		    $baseWithPMCLink = $baseWithPMC;
        }

		$baseWithLinks = preg_replace("/PubMed PMID:\s*".$this->getPMID()."/", Links::makeLink($this->getURL(), "PubMed PMID: ".$this->getPMID(), $newTarget), $baseWithPMCLink);

		return $baseWithLinks;
	}

	public function getPMCURL() {
	    return self::getURLForPMC($this->getPMCWithPrefix());
    }

    public static function getURLForPMC($pmcid) {
		return "https://www.ncbi.nlm.nih.gov/pmc/articles/".$pmcid;
	}

	public function getURL() {
	    return self::getURLForPMID($this->getPMID());
	}

	public static function getURLForPMID($pmid) {
        return "https://www.ncbi.nlm.nih.gov/pubmed/?term=".$pmid;
    }

	public function isResearch() {
		return $this->isResearchArticle();
	}

	public function isResearchArticle() {
		return ($this->getCategory() == "Original Research");
	}

	public function isIncluded() {
		return $this->getVariable("include");
	}

	private function writeToDB() {
		$row = array(
				"record_id" => $this->getRecordId(),
				"redcap_repeat_instrument" => "citation",
				"redcap_repeat_instance" => $this->getInstance(),
				);
		foreach ($this->data as $field => $value) {
			$row['citation_'.$field] = $value;
		}
		$row['citation_complete'] = '2';
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

	public static function getCategories() {
		return array("Original Research", "Not Original Research", "Uncategorized");
	}

	public function inTimespan($startTs, $endTs) {
	    $ts = $this->getTimestamp();
	    return (($startTs <= $ts) && ($endTs >= $ts));
    }

    public static function transformDateToTimestamp($date) {
        if ($date) {
            $dateNodes = preg_split("/\s+/", $date);
            $year = $dateNodes[0];
            $months = array("Jan" => "01", "Feb" => "02", "Mar" => "03", "Apr" => "04", "May" => "05", "Jun" => "06", "Jul" => "07", "Aug" => "08", "Sep" => "09", "Oct" => "10", "Nov" => "11", "Dec" => "12");

            if (count($dateNodes) == 1) {
                $month = "01";
            } else if (is_numeric($dateNodes[1])) {
                $month = $dateNodes[1];
                if ($month < 10) {
                    $month = "0".intval($month);
                }
            } else if (isset($months[$dateNodes[1]])) {
                $month = $months[$dateNodes[1]];
            } else {
                $month = "01";
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

	public function getTimestamp() {
		return self::transformDateToTimestamp($this->getDate());
	}

	public function getSource() {
		$src = $this->getVariable("source");
		if (isset($this->sourceChoices[$src])) {
			return $this->sourceChoices[$src];
		} else {
			if (!$this->sourceChoices && empty($this->sourceChoices)) {
				$choices = Scholar::getChoices($this->metadata);
				if (isset($choices["citation_source"])) {
					$this->sourceChoices = $choices["citation_source"];
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

