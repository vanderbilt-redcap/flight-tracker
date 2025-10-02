<?php

namespace Vanderbilt\CareerDevLibrary;

# This class handles publication data from PubMed, the VICTR fetch routine, and surveys.
# It also provides HTML for data-wrangling the publication data

require_once(__DIR__ . '/ClassLoader.php');

class PubmedMatch
{
	public function __construct($pmid) {
		$this->pmid = $pmid;
	}

	public function formDisplay() {
		$str = "";

		$str .= "<div>\n";
		if ($this->isEmpty()) {
			$str .= "<p>PMID {$this->pmid} not found.</p>\n";
		} else {
			foreach ($this->toArray() as $var => $val) {
				if ($var == "Category") {
					if ($val) {
						$cats = Citation::getCategories();
						$val = $cats[$val];
					} else {
						$val = "[UNDETERMINABLE]";
					}
				}
				if ($var == "Score") {
					if (is_numeric($val) && ($val < 100)) {
						$val = "<span class='red'>$val</span>";
					}
				}
				if (is_array($val)) {
					$str .= "<p><b>$var</b>: ".implode("; ", $val)."</p>\n";
				} else {
					$str .= "<p><b>$var</b>: $val</p>\n";
				}
			}
		}
		$str .= "</div>\n";

		return $str;
	}

	public function fillInCategoryAndScore() {
		list($cat, $score) = $this->autoSuggestCategoryAndScore();
		$this->setVariable("Category", $cat);
		$this->setVariable("Score", $score);
	}

	private function isEmpty() {
		$ary = [];
		$skip = ["PMID", "Category", "Score"];
		foreach ($this->toArray() as $var => $val) {
			if (!in_array($var, $skip)) {
				$ary[$var] = $val;
			}
		}
		return empty($ary);
	}

	private function toArray() {
		$ary = [];

		# put important variables at front
		$ary['PMID'] = $this->pmid;
		if (isset($this->ary['Category'])) {
			$ary['Category'] = $this->ary['Category'];
		}
		if (isset($this->ary['Score'])) {
			$ary['Score'] = $this->ary['Score'];
		}

		foreach ($this->ary as $var => $val) {
			if (!isset($ary[$var])) {
				$ary[$var] = $val;
			}
		}
		return $ary;
	}

	public function setVariable($var, $value) {
		if (is_array($value)) {
			$this->ary[$var] = $value;
		} else {
			$this->ary[$var] = (string) $value;
		}
	}

	public function getPMID(): string {
		return strval($this->pmid);
	}
	public function getVariable($var) {
		if (isset($this->ary[$var])) {
			return $this->ary[$var];
		}
		return "";
	}

	# returns list($category, $score)
	private function autoSuggestCategoryAndScore() {
		$pubAry = $this->toArray();
		if (is_array($pubAry['Publication Types']) && !empty($pubAry['Publication Types'])) {
			// $cat = Citation::suggestCategoryFromPubTypes($pubAry['Publication Types'], $pubAry['Title']);
			// if ($cat) {
			// return array($cat, 100);
			// }
		}
		if (isset($pubAry['Abstract'])) {
			return self::analyzeAbstractAndTitle($pubAry['Abstract'], $pubAry['Title']);
		}
		# no abstract and no pub types => minimal paper
		return ["", 0];
	}

	# returns list($category, $score)
	private static function analyzeAbstractAndTitle($abstract, $title) {
		# look in title and in abstract for replies and errata
		$fullAbstract = $title."\n".$abstract;
		$isLetter = 0;
		if (preg_match("/The Author'?s Reply/i", $fullAbstract)) {
			$isLetter++;
		}

		$isErrata = 0;
		if (preg_match("/Erratum/i", $fullAbstract)) {
			$isErrata++;
		}
		if (preg_match("/Errata/i", $fullAbstract)) {
			$isErrata++;
		}

		# only look for research topics in abstract
		$isResearch = 0;
		$numResearchMatches = 0;
		if (preg_match("/Problem/i", $abstract)) {
			$isResearch = 1;
			$numResearchMatches++;
		}
		if (preg_match("/Background/i", $abstract)) {
			$isResearch = 1;
			$numResearchMatches++;
		}
		if (preg_match("/Methods/i", $abstract)) {
			$isResearch = 1;
			$numResearchMatches++;
		}
		if (preg_match("/Results/i", $abstract)) {
			$isResearch = 1;
			$numResearchMatches++;
		}
		if (preg_match("/Discussion/i", $abstract)) {
			$isResearch = 1;
			$numResearchMatches++;
		}
		if (preg_match("/Conclusion/i", $abstract)) {
			$isResearch = 1;
			$numResearchMatches++;
		}

		if ($isResearch + $isErrata + $isLetter == 1) {
			# only in one category
			if ($isResearch) {
				if ($numResearchMatches >= 3) {
					return ["research", 100];
				} elseif ($numResearchMatches >= 2) {
					return ["research", 70];
				} else {
					# only one match for research
					return ["research", 20 + self::scoreAbstractForMinorPhrases($abstract)];
				}
			} elseif ($isErrata) {
				return ["errata", 100];
			} elseif ($isLetter) {
				return ["letter-to-the-editor", 100];
			}
		} elseif ($isResearch + $isErrata + $isLetter > 1) {
			# in more than one category
			if ($isResearch) {
				if ($numResearchMatches >= 4) {
					return ["research", 90];
				} elseif ($numResearchMatches >= 3) {
					return ["research", 80];
				} elseif ($numResearchMatches >= 2) {
					return ["research", 50];
				} else {
					# only one match for research and in another category
					return ["research", 10 + self::scoreAbstractForMinorPhrases($abstract)];
				}
			} else {
				# both errata and letter => cannot classify
				return ["", 0];
			}
		}

		# abstract yields no insights
		return ["", 0];
	}

	private static function scoreAbstractForMinorPhrases($abstract) {
		$phrases = [ "/compared/i", "/studied/i", "/examined/i" ];
		$numMatches = 0;
		$scorePerMatch = 10;

		foreach ($phrases as $regex) {
			if (preg_match($regex, $abstract)) {
				$numMatches++;
			}
		}
		return $scorePerMatch * $numMatches;
	}

	private $ary = [];
	private $pmid = "";
}
