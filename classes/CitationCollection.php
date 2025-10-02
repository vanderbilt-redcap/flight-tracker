<?php

namespace Vanderbilt\CareerDevLibrary;

# This class handles publication data from PubMed, the VICTR fetch routine, and surveys.
# It also provides HTML for data-wrangling the publication data

require_once(__DIR__ . '/ClassLoader.php');

class CitationCollection
{
	# type = [ Filtered, Final, New, Omit, Flagged, Unflagged ]
	public function __construct($recordId, $token, $server, $type = 'Final', $redcapData = [], $metadata = "download", $lastNames = [], $firstNames = []) {
		$this->type = $type;
		$this->pid = Application::getPID($token);
		$this->citations = [];
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
				if (in_array($row['redcap_repeat_instrument'], ["citation", "eric"]) && ($row['record_id'] == $recordId) && ($row['citation_pmid'] ?? false)) {
					$c = new Citation($token, $server, $recordId, $row['redcap_repeat_instance'], $row, $this->metadata, $lastNames[$recordId], $firstNames[$recordId], $this->pid);
					if ($c->getType() == $type) {
						$this->citations[] = $c;
					}
				}
			}
		} else {
			# Filtered ==> Manually add
		}
	}

	public function getInstances() {
		$instances = [];
		foreach ($this->citations as $citation) {
			$instances[] = $citation->getInstance();
		}
		return $instances;
	}

	public function getType() {
		return $this->type;
	}

	public function getBoldedNames($withTotals = false) {
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
	public function toHTML($citationClass, $displayOnEmpty = true, $startI = 1, $displayREDCapLink = true) {
		$html = "";
		if ($displayOnEmpty && (count($this->getCitations()) == 0)) {
			$html .= "<p class='centered'>None to date.</p>";
		} else {
			$allBoldedNames = $this->getBoldedNames();
			$i = $startI - 1;
			$arePilotGrantsOn = Wrangler::arePilotGrantsOn($this->pid);
			$options = Wrangler::getPilotGrantOptions($this->pid);
			$isWranglingPage = in_array($_GET['page'] ?? "", ["portal/index", "portal/driver", "wrangler/include", "portal%2Findex", "portal%2Fdriver", "wrangler%2Finclude"]);

			foreach ($this->getCitations() as $citation) {
				$boldedNames = $citation->getBoldedNames();
				$nameClasses = [];
				foreach ($allBoldedNames as $j => $name) {
					if (in_array($name, $boldedNames)) {
						$nameClasses[] = "name$j";
					}
				}

				$pilotGrantHTML = "";
				if (
					$arePilotGrantsOn
					&& in_array($citationClass, ["notDone", "notdone", "included", "flagged", "unflagged"])
					&& $isWranglingPage
				) {
					$pilotGrantHTML = $this->makePilotGrantHTML($citation, $options);
				}
				$html .= $citation->toHTML($citationClass, $nameClasses, $i + 1, $pilotGrantHTML, $displayREDCapLink);
				$i++;
			}
		}
		return $html;
	}

	private function makePilotGrantHTML($citation, $options) {
		if (empty($options)) {
			return "";
		}
		$citationID = $citation->getUniqueID();
		$checkedPilotGrants = $citation->getVariable("pilot_grants");
		$html = "<div class='pilotGrant'><strong>Is the above publication related to a pilot grant?</strong>";
		foreach ($options as $optionID => $label) {
			$combinedID = $citationID."___".$optionID;
			$checked = in_array($optionID, $checkedPilotGrants) ? "checked" : "";
			$html .= "<br/><input type='checkbox' id='$combinedID' value='1' $checked /><label for='$combinedID'> $label</label>";
		}
		$html .= "</div>";
		return $html;
	}

	# for bookkeeping purposes only; does not write to DB
	public function removePMID($pmid) {
		if ($this->has($pmid)) {
			$newCitations = [];
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
		$ids = [];
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
		$keys = [];
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

		$ordered = [];
		foreach ($keys as $key) {
			$found = false;
			foreach ($unorderedArys as $i => $ary) {
				if ($ary[$field] == $key) {
					$ordered[] = $ary;
					unset($unorderedArys[$i]);
					$found = true;
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
			$sorted = self::sortArrays($unsorted, "timestamp", true);
		} elseif (in_array($how, ["altmetric_score", "rcr"])) {
			foreach ($myCitations as $citation) {
				$unsorted[] = [
					"citation" => $citation,
					"impact_factor" => $citation->getVariable($how),
				];
			}
			$sorted = self::sortArrays($unsorted, "impact_factor", true);
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

	public function getCitationsAsString($hasLink = false) {
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

	public function filterForMeSHTerms(array $terms, string $combiner): void {
		$combiner = strtolower($combiner);
		if (!in_array($combiner, ["and", "or"]) && (count($terms) >= 2)) {
			throw new \Exception("Invalid combine term '$combiner'!");
		}
		if (count($terms) == 0) {
			return;
		}
		$newCitations = [];
		foreach ($this->getCitations() as $citation) {
			if (count($terms) == 1) {
				$term = $terms[0];
				if ($citation->hasMESHTerm($term)) {
					$newCitations[] = $citation;
				}
			} elseif ($combiner == "and") {
				$hasAll = true;
				foreach ($terms as $term) {
					if (!$citation->hasMESHTerm($term)) {
						$hasAll = false;
					}
				}
				if ($hasAll) {
					$newCitations[] = $citation;
				}
			} elseif ($combiner == "or") {
				foreach ($terms as $term) {
					if ($citation->hasMeSHTerm($term)) {
						$newCitations[] = $citation;
						break;
					}
				}
			} else {
				throw new \Exception("Invalid citation state! This should never happen.");
			}
		}
		$this->citations = $newCitations;
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
			$match = false;
			foreach ($methods as $method) {
				if ($citation->$method($name)) {
					$match = true;
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

	public function filterByTitleWords($words) {
		if (!empty($words)) {
			$quotedWords = [];
			foreach ($words as $word) {
				$quotedWords[] = preg_quote($word, "/");
			}

			$filteredCitations = [];
			foreach ($this->getCitations() as $citation) {
				$title = $citation->getVariable("title");
				$hasWord = false;
				foreach ($quotedWords as $word) {
					if (preg_match("/\b$word\b/i", $title)) {
						$hasWord = true;
						break;
					}
				}
				if ($hasWord) {
					$filteredCitations[] = $citation;
				}
			}
			$this->citations = $filteredCitations;
		}
	}

	public function getCount() {
		return count($this->getCitations());
	}

	protected $citations = [];
	protected $metadata = [];
	protected $type = "";
	protected $pid;
}
