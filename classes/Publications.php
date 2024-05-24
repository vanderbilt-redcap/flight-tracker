<?php

namespace Vanderbilt\CareerDevLibrary;


# This class handles publication data from PubMed, the VICTR fetch routine, and surveys.
# It also provides HTML for data-wrangling the publication data

use phpDocumentor\Reflection\DocBlock\Tags\Link;

require_once(__DIR__ . '/ClassLoader.php');

class Publications {
    const DEFAULT_LIMIT_YEAR = 2014;
    const DEFAULT_PUBMED_THROTTLE = 0.35;   // rate limit: 3 per minute
    const API_KEY_PUBMED_THROTTLE = 0.10;   // rate limit: 10 per minute
    const WAIT_SECS_UPON_FAILURE = 60;

	public function __construct($token, $server, $metadata = "download") {
		$this->token = $token;
		$this->server = $server;
		if ($metadata == "download") {
			$metadata = Download::metadata($token, $server);
		}
		$this->metadata = $metadata;
		$this->pid = Application::getPID($token);
        $this->names = Download::names($token, $server);
        $this->lastNames = Download::lastnames($token, $server);
        $this->firstNames = Download::firstnames($token, $server);
        $this->wranglerType = Sanitizer::sanitize($_GET['wranglerType'] ?? "");
    }

    public static function areFlagsOn($pid) {
        return Grants::areFlagsOn($pid);
    }

    # alias to Grants class
    # turns on additional menu item
    public function getFlagStatus() {
        return self::areFlagsOn(Application::getPID($this->token));
    }

    public static function adjudicateStartDate($limitYear, $startDate) {
        if ($limitYear && $startDate) {
            $limitYear = Sanitizer::sanitizeInteger($limitYear);
            $startDate = Sanitizer::sanitizeDate($startDate);
            if ($limitYear) {
                $limitDate = "$limitYear-01-01";
                if ($startDate != $limitDate) {
                    $startDate = $limitDate;
                }
            }
        } else if ($limitYear) {
            $limitYear = Sanitizer::sanitizeInteger($limitYear);
            if ($limitYear) {
                $startDate = "$limitYear-01-01";
            } else {
                $startDate = "";
            }
        } else {
            $startDate = Sanitizer::sanitizeDate($startDate);
        }
        return $startDate;
    }

    public static function makeFullCitations($token, $server, $pid, $recordId, $metadata, $addTimestamp = FALSE) {
        $redcapData = Download::fieldsForRecords($token, $server, Application::getCitationFields($metadata), [$recordId]);
        $upload = [];
        $instancesToDelete = [];
        foreach ($redcapData as $row) {
            if (
                ($row['record_id'] == $recordId)
                && ($row['redcap_repeat_instrument'] == "citation")
                && $row['citation_pmid']
            ) {
                $citation = new Citation($token, $server, $recordId, $row['redcap_repeat_instance'], $row);
                $pubmedCitation = $citation->getPubMedCitation();
                if ($pubmedCitation) {
                    $uploadRow = [
                        "record_id" => $recordId,
                        "redcap_repeat_instrument" => "citation",
                        "redcap_repeat_instance" => $row['redcap_repeat_instance'],
                        "citation_full_citation" => $pubmedCitation,
                    ];
                    if ($addTimestamp) {
                        $uploadRow["citation_ts"] = date("Y-m-d", $citation->getTimestamp());
                    }
                    $upload[] = $uploadRow;
                }
            } else if (!$row['citation_pmid']) {
                $instancesToDelete[] = $row['redcap_repeat_instance'];
            }
        }

        if (!empty($upload)) {
            Application::log("Adding ".count($upload)." full citations for Record $recordId", $pid);
            Upload::rows($upload, $token, $server);
        }
        if (!empty($instancesToDelete)) {
            Upload::deleteFormInstances($token, $server, $pid, "citation_", $recordId, $instancesToDelete);
        }
    }

    public static function makeLimitButton($elementTag = "p", $buttonClass = "") {
        $server = $_SERVER['HTTP_HOST'] ?? "";
        $uri = $_SERVER['REQUEST_URI'] ?? "";
        $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://".$server.$uri;
        if (isset($_GET['limitPubs'])) {
            $limitYear = Sanitizer::sanitizeInteger($_GET['limitPubs']);
            $status = "<span class='smaller bolded'>Currently limiting pubs to after $limitYear</span>";
            if (preg_match("/&limitPubs=\d+/", $url)) {
                $newUrl = preg_replace("/&limitPubs=\d+/", "", $url);
            } else {
                $newUrl = preg_replace("/limitPubs=\d+&/", "", $url);
            }
            $buttonText = "Show All Pubs";
            $nextLine = "";
        } else {
            $limitYear = self::DEFAULT_LIMIT_YEAR;
            $status = "<span class='smaller bolded'>Currently showing all pubs</span>";
            $newUrl = $url."&limitPubs=$limitYear";
            $buttonText = "Limit Pubs to After $limitYear";
            $nextLine = "<br/><span class='smallest'>PubMed's match-quality increased after $limitYear</span>";
        }
        $buttonClassText = "";
        if ($buttonClass) {
            $buttonClassText = "class='$buttonClass'";
        }
        return "<$elementTag class='centered'>$status<br/><button $buttonClassText onclick='location.href=\"$newUrl\"; return false;'>$buttonText</button>$nextLine</$elementTag>";
    }

	public function deduplicateCitations($recordId) {
	    $pmids = [];
        $ericIDs = [];
	    $duplicateInstances = ["citation_" => [], "eric_" => []];
	    foreach ($this->rows as $row) {
            if ($row['redcap_repeat_instrument'] == "citation") {
                $pmid = $row['citation_pmid'];
                if (!in_array($pmid, $pmids)) {
                    $pmids[] = $pmid;
                } else {
                    $duplicateInstances["citation_"][] = $row['redcap_repeat_instance'];
                }
            } else if ($row['redcap_repeat_instrument'] == "eric") {
                $id = $row['eric_id'];
                if (!in_array($id, $ericIDs)) {
                    $ericIDs[] = $id;
                } else {
                    $duplicateInstances["eric_"][] = $row['redcap_repeat_instance'];
                }
            }
        }
        foreach ($duplicateInstances as $prefix => $instances) {
            if (!empty($instances)) {
                Upload::deleteFormInstances($this->token, $this->server, $this->pid, $prefix, $recordId, $instances);
            }
        }
    }

    public static function filterExcludeList($rows, $recordExcludeLists, $recordId) {
	    if (empty($recordExcludeLists)) {
	        return $rows;
        }
	    $newRows = [];
	    foreach ($rows as $row) {
	        $excludeThisRow = FALSE;
	        if ($row['citation_authors']) {
                $authors = preg_split("/\s*[,;]\s*/", $row['citation_authors']);
                foreach ($authors as $author) {
                    $author = trim($author);
                    foreach ($recordExcludeLists['author'][$recordId] as $excludeName) {
                        if (strtolower($author) == strtolower($excludeName)) {
                            $excludeThisRow = TRUE;
                            break;
                        }
                    }
                    if ($excludeThisRow) {
                        break;
                    }
                }
            }
            if (!$excludeThisRow && $row['citation_title'] && isset($recordExcludeLists['title'])) {
                $title = strtolower($row['citation_title']);
                foreach ($recordExcludeLists['title'][$recordId] as $excludeWord) {
                    $excludeWord = strtolower($excludeWord);
                    if (strpos($title, $excludeWord) !== FALSE) {
                        $excludeThisRow = TRUE;
                        break;
                    }
                }
            }
	        if (!$excludeThisRow) {
	            $newRows[] = $row;
            }
        }
	    return $newRows;
    }

    private static function makePubMedNameClause($unexplodedFirst, $unexplodedLast, $middle = "") {
        $suffix = "%5Bau%5D";
        $quote = "%22";
        $nameClauses = [];
        foreach (NameMatcher::explodeFirstName($unexplodedFirst) as $first) {
            foreach (NameMatcher::explodeLastName($unexplodedLast) as $last) {
                if ($first && $last) {
                    $first = preg_replace("/\s+/", "+", $first);
                    $last = preg_replace("/\s+/", "+", $last);
                    if ($middle) {
                        $nameClauses[] = $quote . $last . ",+" . $first . "+" . $middle . $quote . $suffix;
                    }
                }
                $nameClauses[] = $quote . $last . ",+" . $first . $quote . $suffix;
            }
        }
        if (!empty($nameClauses)) {
            return "(".implode("+OR+", $nameClauses).")";
        } else {
            return "";
        }
    }

    public static function searchPubMedForNameAndDate($unexplodedFirst, $middle, $unexplodedLast, $pid, $institutions, $startDate, $endDate = "3000")
    {
        if (!is_array($institutions)) {
            $institutions = [$institutions];
        }

        $term = self::makePubMedNameClause($unexplodedFirst, $unexplodedLast, $middle);
        if (!$term) {
            return [];
        }

        $institutionClause = self::makePubMedInstitutionClause($institutions);
        $term .= $institutionClause ? "+AND+".$institutionClause : "";

        if ($startDate) {
            $encodedStartDate = str_replace("-", "%2F", $startDate);
            $encodedEndDate = str_replace("-", "%2F", $endDate);
            $term .= "+AND+(\"$encodedStartDate\"%5BDate+-+Publication%5D+%3A+\"$encodedEndDate\"%5BDate+-+Publication%5D)";
        }

        return self::queryPubMed($term, $pid);
    }

    private static function makePubMedInstitutionClause($institutions) {
        $institutionSearchNodes = [];
        foreach ($institutions as $institution) {
            # handle HTML escaping; include curly quotes
            # Many early projects accidentally stored escaped quotes in their database and need to be decoded
            # This should not have to be heavily used with projects after 11/2023
            $institution = str_replace("&amp;", "&", $institution);
            $singleQuoteItems = ["#039", "#39", "#8216", "#8217"];
            $doubleQuoteItems = ["#8220", "#8221"];
            $replacements = [
                "%27" => $singleQuoteItems,
                "%22" => $doubleQuoteItems,
            ];
            foreach ($replacements as $replacement => $items) {
                foreach ($items as $escapedQuote) {
                    if (preg_match("/&$escapedQuote;/", $institution)) {
                        $institution = str_replace("&$escapedQuote;", $replacement, $institution);
                    } else if (preg_match("/&$escapedQuote\D/", $institution)) {
                        $institution = str_replace("&$escapedQuote", $replacement, $institution);
                    } else if (preg_match("/$escapedQuote;/", $institution)) {
                        $institution = str_replace("$escapedQuote;", $replacement, $institution);
                    } else if (preg_match("/$escapedQuote\D/", $institution)) {
                        $institution = str_replace($escapedQuote, $replacement, $institution);
                    }
                }
            }
            $institution = preg_replace("/\s*&\s*/", " ", $institution);
            $institution = preg_replace("/\s*&\s*/", " ", $institution);
            $institution = preg_replace("/\s+/", "+", $institution);
            $institution = Sanitizer::repetitivelyDecodeHTML(strtolower($institution));
            $institution = str_replace("(", "", $institution);
            $institution = str_replace(")", "", $institution);
            # Derivations of the word "children" as an institution are interpreted as a MeSH term (topic)
            # by PubMed; thus, they will explode into thousands of incorrect publications
            if (!in_array($institution, ["children", "children'", "children's"])) {
                $institutionSearchNodes[] = Sanitizer::repetitivelyDecodeHTML(strtolower($institution)) . "+%5Bad%5D";
            }
        }

        if (!empty($institutionSearchNodes)) {
            return "(" . implode("+OR+", $institutionSearchNodes) . ")";
        } else {
            return "";
        }
    }


    public static function searchPubMedForName($first, $middle, $last, $pid, $institutions = [], $recordId = FALSE) {
        if (!is_array($institutions)) {
            $institutions = [$institutions];
        }
        $term = self::makePubMedNameClause($first, $last, $middle);
        if (!$term) {
            return [];
        }
        if (
            (count($institutions) > 1)
            || (
                !empty($institutions)
                && ($institutions[0] != "all")
            )
        ) {
            $institutionClause = self::makePubMedInstitutionClause($institutions);
            $term .= $institutionClause ? "+AND+".$institutionClause : "";
        }
        $pmids = self::queryPubMed($term, $pid);

        $maximumThreshold = 2000;
        if (count($pmids) > $maximumThreshold) {
            $adminEmail = Application::getSetting("admin_email", $pid);
            $defaultFrom = Application::getSetting("default_from", $pid);
            if ($recordId) {
                $name = Links::makeRecordHomeLink($pid, $recordId, "Record $recordId: $first $last");
            } else {
                $name = "$first $last";
            }
            $mssg = "Searching PubMed for $name returned ".count($pmids)." PMIDs in pid $pid! This is likely an error as it is above the threshold of $maximumThreshold. Data not uploaded.";
            if ($adminEmail) {
                \REDCap::email($adminEmail, $defaultFrom, Application::getProgramName()." Error", $mssg);
                return [];
            } else {
                throw new \Exception($mssg);
            }
        } else {
            return $pmids;
        }
    }

    public static function searchPubMedForORCID($orcid, $pid) {
        $term = $orcid . "%5Bauid%5D";
        return self::queryPubMed($term, $pid);
    }

    public static function searchPubMedForTitleAndJournal($title, $journal, $pid) {
        $term = "%28".urlencode($title)."%5Btitle%5D%29+AND+%28\"".urlencode($journal)."\"%5Bjournal%5D%29";
        return self::queryPubMed($term, $pid);
    }

    public static function searchPubMedForAuthorAndJournal($firstName, $lastName, $journal, $pid) {
        $term = "%28".urlencode('"'.$lastName.", ".$firstName.'"')."%5BAuthor%5D%29+AND+%28\"".urlencode($journal)."\"%5Bjournal%5D%29";
        return self::queryPubMed($term, $pid);
    }

    public static function queryPubMed($term, $pid) {
        $apiKey = Application::getSetting("pubmed_api_key", $pid);
        $url = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&retmax=100000&retmode=json&term=".$term;
        if ($apiKey) {
            $url .= "&api_key=".urlencode($apiKey);
        }
        Application::log($url);
        list($resp, $output) = REDCapManagement::downloadURL($url, $pid);
        Application::log("$resp: Downloaded ".strlen($output)." bytes");

        $pmids = [];
        $pmData = json_decode($output, true);
        if (isset($pmData['esearchresult']) && isset($pmData['esearchresult']['idlist'])) {
            # if the errorlist is not blank, it might search for simplified
            # it might search for simplified names and produce bad results
            if (
                !isset($pmData['esearchresult']['errorlist'])
                || !$pmData['esearchresult']['errorlist']
                || !isset($pmData['esearchresult']['errorlist']['phrasesnotfound'])
                || (
                    is_array($pmData['esearchresult']['errorlist']['phrasesnotfound'])
                    && empty($pmData['esearchresult']['errorlist']['phrasesnotfound'])
                )
            ) {
                foreach ($pmData['esearchresult']['idlist'] as $pmid) {
                    $pmids[] = $pmid;
                }
            }
        }
        if ($apiKey) {
            Publications::throttleDown(self::API_KEY_PUBMED_THROTTLE);
        } else {
            Publications::throttleDown(self::DEFAULT_PUBMED_THROTTLE);
        }
        Application::log("$url returned PMIDs: ".REDCapManagement::json_encode_with_spaces($pmids));
        return $pmids;
    }

    public function getAltmetricRange($type = "included") {
	    $scores = [];
	    foreach ($this->getCitations($type) as $citation) {
	        $score = $citation->getVariable("altmetric_score");
	        if (is_numeric($score) && ($score > 0)) {
                $scores[] = $score;
            }
        }
	    if (!empty($scores)) {
            $maxRoundedUp = ceil((float) max($scores));
            $minRoundedDown = floor((float) min($scores));
            if ($minRoundedDown == $maxRoundedUp) {
                return $minRoundedDown;
            }
            return $minRoundedDown."-".$maxRoundedUp;
        } else {
            return "";
        }
    }

	public static function getSearch() {
		return "Last/Full Name:<br><input id='search' type='text' style='width: 100%;'><br><div style='width: 100%; color: #ff0000;' id='searchDiv'></div>";
	}

    private function getCitationsForTimespanHelper($startTs, $endTs) {
        $type = "Included";
        if ($startTs) {
            if ($endTs) {
                return $this->getSortedCitationsInTimespan($startTs, $endTs, $type);
            }  else {
                return $this->getSortedCitationsInTimespan($startTs, FALSE, $type);
            }
        } else {
            return $this->getCitations($type);
        }
    }

    public function getFirstAuthors($startTs = NULL, $endTs = NULL) {
        $citations = $this->getCitationsForTimespanHelper($startTs, $endTs);
        return self::getAuthorsHelper("first", $citations, $this->getName());
    }

    public function getLastAuthors($startTs = NULL, $endTs = NULL) {
        $citations = $this->getCitationsForTimespanHelper($startTs, $endTs);
        return self::getAuthorsHelper("last", $citations, $this->getName());
    }

    public function getMiddleAuthors($startTs = NULL, $endTs = NULL) {
        $citations = $this->getCitationsForTimespanHelper($startTs, $endTs);
        return self::getAuthorsHelper("middle", $citations, $this->getName());
    }

    public function getNumberFirstAuthors($startTs = NULL, $endTs = NULL, $asFraction = TRUE) {
        $citations = $this->getCitationsForTimespanHelper($startTs, $endTs);
        return self::getNumberAuthorsHelper("first", $citations, $this->getName(), $asFraction);
    }

    private static function getAuthorsHelper($pos, $citations, $name) {
        if ($pos == "first") {
            $method = "isFirstAuthor";
        } else if ($pos == "last") {
            $method = "isLastAuthor";
        } else if ($pos == "middle") {
            $method = "isMiddleAuthor";
        } else {
            throw new \Exception("Invalid position $pos");
        }
        $filteredCitations = [];
        foreach ($citations as $citation) {
            if ($citation->$method($name)) {
                $filteredCitations[] = $citation;
            }
        }
        return $filteredCitations;
    }

    private static function getNumberAuthorsHelper($pos, $citations, $name, $asFraction = TRUE) {
        if ($pos == "first") {
            $method = "isFirstAuthor";
        } else if ($pos == "last") {
            $method = "isLastAuthor";
        } else if ($pos == "middle") {
            $method = "isMiddleAuthor";
        } else {
            throw new \Exception("Invalid position $pos");
        }
        $num = 0;
        $total = count($citations);
        foreach ($citations as $citation) {
            if ($citation->$method($name)) {
                $num++;
            }
        }
        if ($asFraction) {
            return $num."/".$total;
        } else {
            return $num;
        }
    }

    public function getNumberLastAuthors($startTs = NULL, $endTs = NULL, $asFraction = TRUE) {
        $citations = $this->getCitationsForTimespanHelper($startTs, $endTs);
        return self::getNumberAuthorsHelper("last", $citations, $this->getName(), $asFraction);
    }

    public function getNumberMiddleAuthors($startTs = NULL, $endTs = NULL, $asFraction = TRUE) {
        $citations = $this->getCitationsForTimespanHelper($startTs, $endTs);
        return self::getNumberAuthorsHelper("middle", $citations, $this->getName(), $asFraction);
    }

    public static function getNumberFirstAuthor($citations, $name) {
        return self::getNumberAuthorsHelper("first", $citations, $name);
    }

    public static function getNumberLastAuthor($citations, $name) {
        return self::getNumberAuthorsHelper("last", $citations, $name);
    }

    public function getAllGrantCounts($type = "Included") {
	    $citations = $this->getCitations($type);
	    $awards = [];
	    foreach ($citations as $citation) {
            $awardNumbers = $citation->getGrants();
            foreach ($awardNumbers as $awardNo) {
                if (!isset($awards[$awardNo])) {
                    $awards[$awardNo] = 0;
                }
                $awards[$awardNo]++;
            }
        }
	    arsort($awards);
	    return $awards;
    }

    public function getCitationsForGrants($awardNumbers, $type = "Included") {
        if (!is_array($awardNumbers)) {
            $awardNumbers = [$awardNumbers];
        }
        $citations = $this->getCitations($type);
        $filteredCitations = [];
        foreach ($citations as $citation) {
            $grantAwardNumbers = $citation->getGrants();
            if (isset($_GET['test'])) {
                Application::log($citation->getPMID().": Looking for ".REDCapManagement::json_encode_with_spaces($awardNumbers)." in ".REDCapManagement::json_encode_with_spaces($grantAwardNumbers));
            }
            foreach ($awardNumbers as $awardNo) {
                if (in_array($awardNo, $grantAwardNumbers)) {
                    if (isset($_GET['test'])) {
                        Application::log($citation->getPMID().": Matched $awardNo in ".REDCapManagement::json_encode_with_spaces($grantAwardNumbers));
                    }
                    $filteredCitations[] = $citation;
                    if (isset($_GET['test'])) {
                        Application::log("filteredCitations now has ".count($filteredCitations)." elements");
                    }
                    break;
                }
            }
        }
        return $filteredCitations;
    }

    public static function getSelectRecord($filterOutCopiedRecords = FALSE) {
		global $token, $server;

		$records = Download::recordIds($token, $server);
		if ($filterOutCopiedRecords && method_exists("\Vanderbilt\CareerDevLibrary\Application", "filterOutCopiedRecords")) {
			$records = Application::filterOutCopiedRecords($records);
		}
		$names = Download::names($token, $server);
		$page = basename($_SERVER['PHP_SELF'] ?? "");

		$html = "Record: <select style='width: 100%;' id='refreshRecord' onchange='refreshForRecord(\"$page\");'><option value=''>---SELECT---</option>";
		foreach ($records as $record) {
			$name = $names[$record];
			$selected = "";
			if (isset($_GET['record']) && ($_GET['record'] == $record)) {
			    $selected = " SELECTED";
            }
			$html .= "<option value='$record'$selected>$record: $name</option>";
		}
		$html .= "</select>";
		return $html;
	}

	# input: All REDCap data rows associated with a recordId
	# calls private helper method process
	public function setRows($rows) {
		$this->rows = $rows;

        $this->name = "";
        $this->lastName = "";
		$this->recordId = 0;
		foreach ($rows as $row) {
			if ($row['record_id']) {
				$this->recordId = $row['record_id'];
                $this->name = $this->names[$this->recordId];
                $this->lastName = $this->lastNames[$this->recordId];
			}
		}

		$this->process();
        $this->goodCitations = new CitationCollection($this->recordId, $this->token, $this->server, "Final", $this->rows, $this->metadata, $this->lastNames, $this->firstNames);
        $this->flaggedCitations = new CitationCollection($this->recordId, $this->token, $this->server, "Flagged", $this->rows, $this->metadata, $this->lastNames, $this->firstNames);
        $this->unflaggedCitations = new CitationCollection($this->recordId, $this->token, $this->server, "Unflagged", $this->rows, $this->metadata, $this->lastNames, $this->firstNames);
		$this->input = new CitationCollection($this->recordId, $this->token, $this->server, "New", $this->rows, $this->metadata, $this->lastNames, $this->firstNames);
		$this->omissions = new CitationCollection($this->recordId, $this->token, $this->server, "Omit", $this->rows, $this->metadata, $this->lastNames, $this->firstNames);
		foreach ($this->omissions->getCitations() as $citation) {
			$pmid = $citation->getPMID();
			if ($this->input->has($pmid)) {
				$this->omissions->removePMID($pmid);
			} else if ($this->goodCitations->has($pmid)) {
				$this->omissions->removePMID($pmid);
			}
		}
	}

	public function updateMetrics() {
	    $upload = [];
	    $metadataFields = REDCapManagement::getFieldsFromMetadata($this->metadata);
	    $pmids = [];
	    foreach($this->rows as $row) {
	        if (
	            ($row['record_id'] == $this->getRecordId())
                && ($row['redcap_repeat_instrument'] == "citation")
                && $row["citation_pmid"]
            ) {
                $pmid = $row['citation_pmid'];
	            if ($pmid) {
                    $pmids[] = $pmid;
                }
	            $setupFields = [
	                "record_id" => $this->recordId,
                    "redcap_repeat_instrument" => "citation",
                    "redcap_repeat_instance" => $row['redcap_repeat_instance'],
                    "citation_pmid" => $pmid,
                    "citation_rcr" => $row['citation_rcr'] ?? "",
                    "citation_altmetric_score" => $row['citation_altmetric_score'] ?? "",
                    "citation_altmetric_last_update" => $row['citation_altmetric_last_update'],
                ];
	            $upload[] = $setupFields;
            }
        }
        self::addTimesCited($upload, $this->pid, $pmids, $metadataFields);
        $this->updateAssocGrantsAndBibliometrics($upload, $pmids);
	    if (!empty($upload)) {
	        Upload::rows($upload, $this->token, $this->server);
        }
    }

    private static function getAltmetricRow($doi, $metadataFields, $pid) {
        $uploadRow = [];
        if ($doi) {
            $altmetric = new Altmetric($doi, $pid);
            if ($altmetric->hasData()) {
                $altmetricFields = [
                    "citation_altmetric_score" => "score",
                    "citation_altmetric_image" => "images",
                    "citation_altmetric_details_url" => "details_url",
                    "citation_altmetric_id" => "altmetric_id",
                    "citation_altmetric_fbwalls_count" => "cited_by_fbwalls_count",
                    "citation_altmetric_feeds_count" => "cited_by_feeds_count",
                    "citation_altmetric_gplus_count" => "cited_by_gplus_count",
                    "citation_altmetric_posts_count" => "cited_by_posts_count",
                    "citation_altmetric_tweeters_count" => "cited_by_tweeters_count",
                    "citation_altmetric_accounts_count" => "cited_by_accounts_count",
                    "citation_altmetric_msm_count" => "cited_by_msm_count",
                    "citation_altmetric_rdts_count" => "cited_by_rdts_count",
                    "citation_altmetric_videos_count" => "cited_by_videos_count",
                    "citation_altmetric_patents_count" => "cited_by_patents_count",
                    "citation_altmetric_wikipedia_count" => "cited_by_wikipedia_count",
                    "citation_altmetric_qna_count" => "cited_by_qna_count",
                    "citation_altmetric_policies_count" => "cited_by_policies_count",
                    "citation_altmetric_last_update" => date("Y-m-d"),
                ];
                $rankTypes = ["all", "journal", "similarage3m", "similaragejournal3m"];
                foreach ($rankTypes as $type) {
                    $altmetricFields["citation_altmetric_context_$type"."_count"] = "context_$type"."_count";
                    $altmetricFields["citation_altmetric_context_$type"."_mean"] = "context_$type"."_count";
                    $altmetricFields["citation_altmetric_context_$type"."_rank"] = "context_$type"."_rank";
                    $altmetricFields["citation_altmetric_context_$type"."_percentage"] = "context_$type"."_pct";
                    $altmetricFields["citation_altmetric_context_$type"."_higher_than"] = "context_$type"."_higher_than";
                }
                foreach ($altmetricFields as $redcapField => $variable) {
                    if (in_array($redcapField, $metadataFields)) {
                        if ($redcapField == "citation_altmetric_last_update") {
                            $value = $variable;
                            $uploadRow[$redcapField] = $value;
                        } else {
                            $uploadRow[$redcapField] = $altmetric->getVariable($variable);
                        }
                    }
                }
            }
            usleep(1150000);   // altmetric has a 1 second rate-limit
        }
        return $uploadRow;
    }

	public function getPubsInLastYear() {
		return $this->getPubsInRange(time() - 365 * 24 * 3600);
	}

	public function getNumberInLastYear() {
		return count($this->getPubsInLastYear());
	}

	public function getCount($type = "Included") {
		return $this->getNumber($type);
	}

	public function getCitationCount($type = "Included") {
	    return $this->getCount($type);
    }

	public function getNumber($type = "Included") {
		return count($this->getCitations($type));
	}

	public function getName() {
		return $this->name;
	}

	public function getRecordId() {
		return $this->recordId;
	}

	public static function getAllPublicationTypes($token, $server) {
		return self::explodeListsForField($token, $server, "citation_pub_types");
	}

	private static function explodeListsForField($token, $server, $field) {
		$redcapData = Download::fields($token, $server, array($field));
		$list = array();
		foreach ($redcapData as $row) {
			$currItems = Citation::explodeList($row[$field]);
			foreach ($currItems as $currItem) {
				if (!in_array($currItem, $currItems)) {
					array_push($list, $currItem);
				}
			}
		}
		return $list;
	}

	public static function getAllMESHTerms($token, $server) {
		return self::explodeListsForField($token, $server, "citation_mesh_terms");
	}

	private function isDuplicatedCitation($pmid) {
		if ($this->goodCitations->has($pmid)) {
			return TRUE;
		}
		if ($this->omissions->has($pmid)) {
			return TRUE;
		}
		if ($this->input->has($pmid)) {
			return TRUE;
		}
		return FALSE;
	}

	private static function getPMIDLimit() {
		return 10;
	}

	public static function pullFromEFetch($pmids, $pid = NULL) {
		if (!is_array($pmids)) {
			$pmids = array($pmids);
		}
		$limit = self::getPMIDLimit();
		if (count($pmids) > $limit) {
			throw new \Exception("Cannot pull more than $limit PMIDs at once!");
		}
		$url = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pubmed&retmode=xml&id=".implode(",", $pmids);
        $apiKey = Application::getSetting("pubmed_api_key", $pid);
        if ($apiKey) {
            Publications::throttleDown(self::API_KEY_PUBMED_THROTTLE);
        } else {
            Publications::throttleDown(self::DEFAULT_PUBMED_THROTTLE);
        }
		list($resp, $output) = URLManagement::downloadURL($url);
		return $output;
	}

    public static function downloadPMID($pmid, $pid = NULL) {
	    $ary = self::downloadPMIDs([$pmid], $pid);
	    if (count($ary) > 0) {
            return $ary[0];
        }
	    return NULL;
    }

    private static function isEmptyArticleSet($output) {
        return preg_match("/<PubmedArticleSet><\/PubmedArticleSet>/", $output);
    }

	public static function downloadPMIDs($pmids, $pid = NULL) {
	    $limit = self::getPMIDLimit();
	    $pmidsInGroups = [];
	    for ($i = 0; $i < count($pmids); $i += $limit) {
	        $pmidGroup = [];
	        for ($j = $i; ($j < $i + $limit) && ($j < count($pmids)); $j++) {
	            $pmidGroup[] = $pmids[$j];
            }
	        if (!empty($pmidGroup)) {
	            $pmidsInGroups[] = $pmidGroup;
            }
        }
        $pubmedMatches = [];
        foreach ($pmidsInGroups as $pmidGroup) {
            $output = self::pullFromEFetch($pmidGroup, $pid);
            $xml = simplexml_load_string(utf8_encode($output));
            $numRetries = 5;
            $i = 0;
            while (!$xml && ($numRetries > $i) && !self::isEmptyArticleSet($output)) {
                sleep(5);
                $output = self::pullFromEFetch($pmidGroup, $pid);
                $xml = simplexml_load_string(utf8_encode($output));
                $i++;
            }
            if (!$xml) {
                Application::log("Warning: Cannot create object after $i iterations (xml = '".$output."') for PMIDS ".implode(", ", $pmidGroup));
            } else {
                foreach ($pmidGroup as $pmid) {
                    $pubmedMatch = NULL;
                    $pubTypes = [];
                    $keywords = [];
                    $abstract = "";
                    $meshTerms = [];
                    $title = "";

                    foreach ($xml->PubmedArticle as $medlineCitation) {
                        $article = $medlineCitation->MedlineCitation->Article;
                        $currPmid = "{$medlineCitation->MedlineCitation->PMID}";

                        if ($currPmid == $pmid) {
                            if ($article->ArticleTitle) {
                                $title = "{$article->ArticleTitle}";
                            }
                            if ($article->Abstract) {
                                $text = "";
                                foreach ($article->Abstract->children() as $node) {
                                    $attrs = $node->attributes();
                                    if ($attrs['Label']) {
                                        $text .= $attrs['Label']."\n";
                                    }
                                    $text .= "$node\n";
                                }
                                $abstract = $text;
                            }

                            if ($article->PublicationTypeList) {
                                foreach ($article->PublicationTypeList->PublicationType as $pubType) {
                                    $pubTypes[] = $pubType;
                                }
                            }

                            if ($medlineCitation->MedlineCitation->KeywordList) {
                                foreach ($medlineCitation->MedlineCitation->KeywordList->Keyword as $keyword) {
                                    $keywords[] = $keyword;
                                }
                            }

                            if ($medlineCitation->MedlineCitation->MeshHeadingList) {
                                foreach ($medlineCitation->MedlineCitation->MeshHeadingList->children() as $node) {
                                    if ($node->DescriptorName) {
                                        $meshTerms[] = $node->DescriptorName;
                                    }
                                }
                            }
                            $pubmedMatch = new PubmedMatch($pmid);
                            if ($abstract) {
                                $pubmedMatch->setVariable("Abstract", $abstract);
                            }
                            if ($keywords) {
                                $pubmedMatch->setVariable("Keywords", $keywords);
                            }
                            if ($title) {
                                $pubmedMatch->setVariable("Title", $title);
                            }
                            if ($pubTypes) {
                                $pubmedMatch->setVariable("Publication Types", $pubTypes);
                            }
                            if ($meshTerms) {
                                $pubmedMatch->setVariable("MESH Terms", $meshTerms);
                            }
                            $pubmedMatch->fillInCategoryAndScore();
                        }
                    }
                    $pubmedMatches[] = $pubmedMatch;
                }
            }
        }
		return $pubmedMatches;
	}

	public function uploadSummary() {
		$recordId = $this->recordId;
		if ($recordId) {
		    $metadataFields = REDCapManagement::getFieldsFromMetadata($this->metadata);
		    if (in_array("summary_publication_count", $metadataFields)) {
                $row = [
                    "record_id" => $recordId,
                    "redcap_repeat_instrument" => "",
                    "redcap_repeat_instance" => "",
                    "summary_publication_count" => $this->getCount("Original Included"),
                ];
                return Upload::oneRow($row, $this->token, $this->server);
            }
		}
		return [];
	}

	public static function deleteEmptySources($token, $server, $pid, $recordId) {
        $redcapData = Download::fieldsForRecords($token, $server, ["record_id", "citation_source", "citation_pmid"], [$recordId]);
        $instances = [];
        foreach ($redcapData as $row) {
            if (($row['redcap_repeat_instrument'] == "citation") && ($row['citation_source'] == "")) {
                $instances[] = $row['redcap_repeat_instance'];
            }
        }
        if (!empty($instances)) {
            Application::log("Deleting instances due to empty source ".json_encode($instances)." for citations for Record $recordId", $pid);
            Upload::deleteFormInstances($token, $server, $pid, "citation", $recordId, $instances);
        }
    }

	public static function deleteMismatchedRows($token, $server, $pid, $recordId, $allFirstNames, $allLastNames) {
        # download citation_authors
	    $redcapData = Download::fieldsForRecords($token, $server, ["record_id", "citation_pmid", "citation_authors"], [$recordId]);

	    # find items that don't match current record AND match some other record
        $currFirstName = $allFirstNames[$recordId];
        $currLastName = $allLastNames[$recordId];
        $instances = [];
        foreach ($redcapData as $row) {
            if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "citation")) {
                $instance = $row['redcap_repeat_instance'];
                $authorList = preg_split("/\s*[,;]\s*/", $row['citation_authors']);
                $pmid = $row['citation_pmid'];
                $authors = [];
                foreach ($authorList as $authorName) {
                    $authorName = trim($authorName);
                    list($first, $last) = NameMatcher::splitName($authorName, 2, FALSE, FALSE);
                    $author = ["first" => $first, "last" => $last];
                    $authors[] = $author;
                }
                $foundCurrInAuthorList = FALSE;
                $foundAnotherInAuthorList = FALSE;
                foreach ($authors as $author) {
                    foreach (NameMatcher::explodeLastName($currLastName) as $currLN) {
                        foreach (NameMatcher::explodeFirstName($currFirstName) as $currFN) {
                            if (
                                NameMatcher::matchByInitials($currLN, $currFN, $author['last'], $author['first'])
                                || NameMatcher::matchByInitials($currFN, $currLN, $author['last'], $author['first'])  // reverse for Asian names which sometimes put family name first
                            ) {
                                $foundCurrInAuthorList = TRUE;
                                // Application::log("Found current $currFirstName $currLastName {$author['last']}, {$author['first']} in author list in $recordId:$instance $pmid", $pid);
                                break;
                            }
                        }
                        if ($foundCurrInAuthorList) {
                            break;
                        }
                    }
                    if ($foundCurrInAuthorList) {
                        break;
                    }
                }
                if (!$foundCurrInAuthorList) {
                    // Application::log("Did not find current $currFirstName $currLastName in author list in $recordId:$instance $pmid ".$row['citation_authors'], $pid);
                    foreach ($authors as $author) {
                        foreach ($allLastNames as $recordId2 => $otherLastName) {
                            $otherFirstName = $allFirstNames[$recordId2];
                            if (
                                NameMatcher::matchByInitials($author['last'], $author['first'], $otherLastName, $otherFirstName)
                            ) {
                                // Application::log("Found other $otherFirstName $otherLastName {$author['last']}, {$author['first']} in author list in $recordId:$instance", $pid);
                                $foundAnotherInAuthorList = TRUE;
                                break;
                            }
                        }
                    }
                }
                if ($foundAnotherInAuthorList) {
                    $instances[] = $instance;
                } else {
                    // Application::log("Did not find other in author list in $recordId:$instance", $pid);
                }
            }
        }
        if (!empty($instances)) {
            Application::log("Deleting instances due to mismatched name ".json_encode($instances)." for citations for Record $recordId", $pid);
            Upload::deleteFormInstances($token, $server, $pid, "citation", $recordId, $instances);
        }
	}

	# returns number of citations filled in
	public static function uploadBlankPMCsAndPMIDs($token, $server, $recordId, $metadata, $redcapData) {
	    $blankPMIDs = [];
	    $blankPMCs = [];
	    $skip = ["record_id", "redcap_repeat_instrument", "redcap_repeat_instance", "citation_pmid", "citation_pmcid"];
	    foreach ($redcapData as $row) {
            if (($recordId == $row['record_id']) && ($row['redcap_repeat_instrument'] == "citation")) {
                $numFilled = 0;
                foreach ($row as $field => $value) {
                    if (!in_array($field, $skip)) {
                        if ($value) {
                            $numFilled++;
                        }
                    }
                }
                if ($numFilled === 0) {
                    $instance = $row['redcap_repeat_instance'];
                    if ($row['citation_pmid']) {
                        $blankPMIDs[$instance] = $row['citation_pmid'];
                    } else if ($row['citation_pmcid']) {
                        $blankPMCs[$instance] = $row['citation_pmcid'];
                    } else {
                        Application::log("ERROR: Citation missing PMID and PMC: Record " . $row['record_id'] . " Instance $instance");
                    }
                }
            }
        }
	    foreach ($blankPMCs as $instance => $pmcid) {
            $pmid = self::PMCToPMID($pmcid, Application::getPID($token));
            $blankPMIDs[$instance] = $pmid;
        }

	    if (!empty($blankPMIDs)) {
            $uploadRows = self::getCitationsFromPubMed(array_values($blankPMIDs), $metadata, "", $recordId);
            Application::log("Uploading ".count($uploadRows)." for $recordId");
            $i = 0;
            foreach ($uploadRows as $row) {
                $pmid = $row["citation_pmid"];
                foreach ($blankPMIDs as $instance => $pmid2) {
                    if ($pmid == $pmid2) {
                        $uploadRows[$i]["redcap_repeat_instance"] = $instance;
                        break; // inner
                    }
                }
                $i++;
            }
            if (!empty($uploadRows)) {
                $feedback = Upload::rows($uploadRows, $token, $server);
            }
            return count($uploadRows);
        }
	    return 0;
    }

    public function getIndividualCollaborations($names, $cat = "Included") {
        $numPubsMatchedHash = [];
        foreach ($names as $name) {
            $numPubsMatchedHash[$name] = 0;
        }
        foreach ($this->getCitations($cat) as $citation) {
            foreach ($names as $name) {
                if ($name && $citation->hasAuthor($name)) {
                    $numPubsMatchedHash[$name]++;
                }
            }
        }
        return $numPubsMatchedHash;
    }

    public function getNumberWithPeople($names, $cat = "Included") {
	    $numPubsMatched = 0;
	    foreach ($this->getCitations($cat) as $citation) {
	        foreach ($names as $name) {
	            if ($name && $citation->hasAuthor($name)) {
                    $numPubsMatched++;
	                break;
                }
            }
        }
	    return $numPubsMatched."/".$this->getCount($cat);
    }

    public static function getAllAffiliationsFromAuthorArray($pubmedAffiliationAry) {
        $ary = [];
        foreach ($pubmedAffiliationAry as $author => $institutions) {
            foreach ($institutions as $inst) {
                $inst = preg_replace("/^1\]/", "", $inst);
                $inst = preg_replace("/\[\d+\]/", ";", $inst);
                if (preg_match("/^a/", $inst)) {
                    # institutions listed with preface of a, b, c, d, ... before word
                    # e.g., aVanderbilt Institute..., bDivision of ...
                    # assume next word is a title => starts in a capital letter
                    $inst = preg_replace("/[a-z]([A-Z])/", "; $1", $inst);
                }
                foreach (self::getCountryNames() as $country) {
                    if (!in_array($country, ["Jersey", "Georgia"])) {
                        $inst = str_replace("$country,", "$country;", $inst);
                        $inst = str_replace("$country.", "$country;", $inst);
                        $inst = str_replace(", $country and ", ", $country; ", $inst);
                    }
                }
                if (strpos($inst, ";") !== FALSE) {
                    $explodedAry = preg_split("/\s*;\s*/", $inst);
                    foreach ($explodedAry as $explodedInst) {
                        if (!in_array($explodedInst, $ary)) {
                            $ary[] = $explodedInst;
                        }
                    }
                } else if (!in_array($inst, $ary)) {
                    $ary[] = $inst;
                }
            }
        }
        return $ary;
    }

    public static function getAffiliationsAndDatesForPMIDs($pmids, $metadataFields, $pid) {
        $itemsByPMID = [];
        $pullSize = 10;
        for ($i0 = 0; $i0 < count($pmids); $i0 += $pullSize) {
            $pmidsToPull = [];
            for ($j = $i0; ($j < count($pmids)) && ($j < $i0 + $pullSize); $j++) {
                $pmidsToPull[] = $pmids[$j];
            }
            $xml = self::repetitivelyPullFromEFetch($pmidsToPull, $pid);
            if ($xml) {
                list($parsedRows, $pmidsPulled) = self::xml2REDCap($xml, 1, $instance, "pubmed", $pmidsToPull, $metadataFields, $pid);
                foreach ($parsedRows as $row) {
                    $itemsByPMID[$row['citation_pmid']] = [
                        "affiliations" => json_decode($row['citation_affiliations'] ?? "[]", TRUE),
                        "date" => $row['citation_ts'],
                        "citation" => $row['citation_full_citation'] ?? "",
                    ];
                }
            }
        }
        return $itemsByPMID;
    }

    public static function getAffiliationJSONsForPMIDs($pmids, $metadataFields, $pid) {
        $affiliations = [];
        $pullSize = 10;
        for ($i0 = 0; $i0 < count($pmids); $i0 += $pullSize) {
            $pmidsToPull = [];
            for ($j = $i0; ($j < count($pmids)) && ($j < $i0 + $pullSize); $j++) {
                $pmidsToPull[] = $pmids[$j];
            }
            $xml = self::repetitivelyPullFromEFetch($pmidsToPull, $pid);
            if ($xml) {
                list($parsedRows, $pmidsPulled) = self::xml2REDCap($xml, 1, $instance, "pubmed", $pmidsToPull, $metadataFields, $pid);
                foreach ($parsedRows as $row) {
                    $affiliations[$row['citation_pmid']] = $row['citation_affiliations'] ?? "";
                }
            }
        }
        return $affiliations;
    }

    # An example XML document is at https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pubmed&retmode=xml&id=37635263
    private static function xml2REDCap($xml, $recordId, &$instance, $src, $confirmedPMIDs, $metadataFields, $pid) {
        $hasAbstract = in_array("citation_abstract", $metadataFields);
        $pmidsPulled = [];
        $upload = [];
        Application::log(count($xml->PubmedArticle)." articles in XML", $pid);
        foreach ($xml->PubmedArticle as $medlineCitation) {
            $article = $medlineCitation->MedlineCitation->Article;
            $abstract = "";
            if ($article->Abstract && $article->Abstract->AbstractText) {
                $abstract = strval($article->Abstract->AbstractText);
            }
            $authors = [];
            $affiliations = [];
            if ($article->AuthorList->Author) {
                foreach ($article->AuthorList->Author as $authorXML) {
                    $author = $authorXML->LastName . " " . $authorXML->Initials;
                    if ($author == " ") {
                        $author = strval($authorXML->CollectiveName);
                    }
                    $authors[] = $author;

                    $authorAffiliations = [];
                    foreach ($authorXML->AffiliationInfo as $affiliationXML) {
                        $authorAffiliations[] = strval($affiliationXML->Affiliation);
                    }
                    $affiliations[$author] = $authorAffiliations;
                }
            }
            $title = strval($article->ArticleTitle);
            $title = preg_replace("/\.$/", "", $title);

            $pubTypes = array();
            if ($article->PublicationTypeList) {
                foreach ($article->PublicationTypeList->PublicationType as $pubType) {
                    $pubTypes[] = strval($pubType);
                }
            }

            $assocGrants = array();
            if ($article->GrantList) {
                foreach ($article->GrantList->Grant as $grant) {
                    $assocGrants[] = strval($grant->GrantID);
                }
            }

            $meshTerms = array();
            if ($medlineCitation->MedlineCitation->MeshHeadingList) {
                foreach ($medlineCitation->MedlineCitation->MeshHeadingList->MeshHeading as $mesh) {
                    $meshTerms[] = strval($mesh->DescriptorName);
                }
            }

            $journal = strval($article->Journal->ISOAbbreviation);
            $journal = preg_replace("/\.$/", "", $journal);

            $issue = $article->Journal->JournalIssue;    // not a strval but node!!!
            $year = "";
            $month = "";
            $day = "";

            if ($issue->PubDate->Year) {
                $year = strval($issue->PubDate->Year);
            }
            if ($issue->PubDate->Month) {
                $month = strval($issue->PubDate->Month);
            }
            if ($issue->PubDate->Day) {
                $day = "{$issue->PubDate->Day}";
            }
            if (!$day && !$month && !$year && $article->ArticleDate) {
                if ($article->ArticleDate->Year) {
                    $year = strval($article->ArticleDate->Year);
                }
                if ($article->ArticleDate->Month) {
                    $month = strval($article->ArticleDate->Month);
                }
                if ($article->ArticleDate->Day) {
                    $day = "{$article->ArticleDate->Day}";
                }
            }
            $numericMonth = DateManagement::getMonthNumber($month);
            if ($year && $numericMonth && $day) {
                $date = "$year-$numericMonth-$day";
            } else if ($year && $numericMonth) {
                $date = "$year-$numericMonth-01";
            } else if ($year) {
                $date = "$year-01-01";
            } else {
                $date = "";
            }

            $vol = "";
            if ($issue->Volume) {
                $vol = strval($issue->Volume);
            }
            $iss = "";
            if ($issue->Issue) {
                $iss = strval($issue->Issue);
            }
            $pages = "";
            if ($article->Pagination->MedlinePgn) {
                $pages = strval($article->Pagination->MedlinePgn);
            }
            $pmid = strval($medlineCitation->MedlineCitation->PMID);
            $pmidsPulled[] = $pmid;

            $preprintVersions = [];
            if ($medlineCitation->MedlineCitation->CommentsCorrectionsList) {
                foreach ($medlineCitation->MedlineCitation->CommentsCorrectionsList as $commentsCorrections) {
                    $attrs = $commentsCorrections->attributes();
                    if (($attrs['RefType'] ?? "" == "UpdateOf") && $commentsCorrections->PMID) {
                        $preprintVersions[] = strval($commentsCorrections->PMID);
                    }
                }
            }

            $matchedPreprint = FALSE;
            foreach ($preprintVersions as $preprintPMID) {
                if (in_array($preprintPMID, $confirmedPMIDs)) {
                    $matchedPreprint = TRUE;
                }
            }
            $newPMIDIncludeStatus = "";
            if ($matchedPreprint) {
                # logic: make accepted if old one is accepted; turn old one into no if already accepted; leave both blank if old one blank
                $smallFields = ["record_id", "citation_include", "citation_pmid"];
                $token = Application::getSetting("token", $pid);
                $server = Application::getSetting("server", $pid);
                $redcapData = Download::fieldsForRecords($token, $server, $smallFields, [$recordId]);

                $includes = [];
                $pmidsWithInstances = [];
                foreach ($redcapData as $row) {
                    $includes[$row['citation_pmid']] = $row['citation_include'];
                    $pmidsWithInstances[$row['citation_pmid']] = $row['redcap_repeat_instance'];
                }
                $includeChangeRows = [];
                foreach ($preprintVersions as $preprintPMID) {
                    if (isset($includes[$preprintPMID])) {
                        if ($includes[$preprintPMID] == "1") {
                            $newPMIDIncludeStatus = "1";
                            $includeChangeRows[] = [
                                "record_id" => $recordId,
                                "redcap_repeat_instance" => $pmidsWithInstances[$preprintPMID],
                                "redcap_repeat_instrument" => "citation",
                                "citation_include" => "0",
                            ];
                        }
                    }
                }
                if (!empty($includeChangeRows)) {
                    Upload::rows($includeChangeRows, $token, $server);
                }
            }

            $row = [
                "record_id" => "$recordId",
                "redcap_repeat_instrument" => "citation",
                "redcap_repeat_instance" => "$instance",
                "citation_pmid" => $pmid,
                "citation_include" => $newPMIDIncludeStatus,
                "citation_source" => $src,
                "citation_authors" => NameMatcher::getRidOfAccentMarks(implode(", ", $authors)),
                "citation_title" => $title,
                "citation_pub_types" => implode("; ", $pubTypes),
                "citation_mesh_terms" => implode("; ", $meshTerms),
                "citation_journal" => $journal,
                "citation_issue" => $iss,
                "citation_volume" => $vol,
                "citation_year" => $year,
                "citation_month" => $month,
                "citation_day" => $day,
                "citation_date" => $date,
                "citation_affiliations" => json_encode($affiliations),
                "citation_pages" => $pages,
                "citation_grants" => implode("; ", $assocGrants),
                "citation_complete" => "2",
            ];
            $token = Application::getSetting("token", $pid);
            $server = Application::getSetting("server", $pid);
            $citation = new Citation($token, $server, $recordId, $instance, $row);
            if (in_array("citation_full_citation", $metadataFields)) {
                $pubmedCitation = $citation->getPubMedCitation();
                $row["citation_full_citation"] = $pubmedCitation;
            }
            if (in_array("citation_ts", $metadataFields)) {
                $row["citation_ts"] = date("Y-m-d", $citation->getTimestamp());
            }
            if (in_array("citation_last_update", $metadataFields)) {
                $row["citation_last_update"] = date("Y-m-d");
            }
            if (in_array("citation_created", $metadataFields)) {
                $row["citation_created"] = date("Y-m-d");
            }
            if (in_array("citation_flagged", $metadataFields)) {
                $row["citation_flagged"] = "";
            }
            if ($hasAbstract) {
                $row['citation_abstract'] = $abstract;
            }

            if (in_array($pmid, $confirmedPMIDs)) {
                $row['citation_include'] = '1';
            }
            $row = REDCapManagement::filterForREDCap($row, $metadataFields);
            $upload[] = $row;
            $instance++;
        }
        return [$upload, $pmidsPulled];
    }

    public static function filterOutAuthorMismatchesFromNewData($rows, $firstNames, $lastNames) {
	    $newRows = [];
	    foreach ($rows as $row) {
	        $found = FALSE;
	        foreach ($firstNames as $firstName) {
	            foreach ($lastNames as $lastName) {
	                $authors = preg_split("/\s*[,;]\s*/", $row['citation_authors']);
	                foreach ($authors as $author) {
	                    if ($author) {
                            list($authorFirst, $authorLast) = NameMatcher::splitName($author, 2, FALSE, FALSE);
                            if (NameMatcher::matchByInitials($lastName, $firstName, $authorLast, $authorFirst)) {
                                $found = TRUE;
                                break;
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
	        if ($found) {
	            $newRows[] = $row;
            } else {
	            Application::log("Excluding row ".json_encode($row)." for names ".json_encode($firstNames)." ".json_encode($lastNames));
            }
        }

	    $firstInstance = 0;
	    if (count($rows) > 0) {
	        $firstInstance = $rows[0]['redcap_repeat_instance'];
        }
	    for ($i=0; $i < count($newRows); $i++) {
	        $newRows[$i]['redcap_repeat_instance'] = $firstInstance + $i;
        }
	    return $newRows;
    }

    public static function getCitationsFromERIC($ids, $metadata, $src = "", $recordId = 0, $startInstance = 1, $confirmedIDs = [], $confirmedTitles = [], $pid = NULL) {
        $upload = [];
        $instance = $startInstance;
        foreach ($ids as $id) {
            $url = ERIC::makeURL($metadata, "id:$id", 2000, 0);
            list($resp, $json) = URLManagement::downloadURL($url);
            $data = json_decode($json, TRUE);
            if (($resp == 200) && $data && isset($data["response"])) {
                $docs = $data["response"]["docs"] ?? [];
                $newRows = ERIC::process($docs, $metadata, $recordId, $confirmedIDs, $confirmedTitles, $instance);
                $upload = array_merge($upload, $newRows);
            }
        }
        return $upload;
    }

    private static function repetitivelyPullFromEFetch($pmids, $pid) {
        $output = self::pullFromEFetch($pmids, $pid);
        $xml = simplexml_load_string(utf8_encode($output));
        $tries = 0;
        $maxTries = 10;
        $oldOutput = "BAD OUTPUT";
        while (!$xml && ($tries < $maxTries) && (!$output || ($oldOutput != $output)) && !self::isEmptyArticleSet($output)) {
            if ($output) {
                $oldOutput = $output;
            }
            $tries++;
            self::throttleDown(self::WAIT_SECS_UPON_FAILURE);
            $output = self::pullFromEFetch($pmids, $pid);
            $xml = simplexml_load_string(utf8_encode($output));
        }
        if (!$xml) {
            if ($tries >= $maxTries) {
                Application::log("Warning: Cannot pull from eFetch! Attempted $tries times. " . implode(", ", $pmids) . " " . $output, $pid);
            } else if (!$output || ($oldOutput != $output)) {
                Application::log("Warning: Empty pull from eFetch! ".$output, $pid);
            }
            return "";
        } else {
            return $xml;
        }
    }

    # https://www.ncbi.nlm.nih.gov/genbank/collab/country/
    public static function getCountryNames() {
        return [
            "Afghanistan",
            "Albania",
            "Algeria",
            "American Samoa",
            "Andorra",
            "Angola",
            "Anguilla",
            "Antarctica",
            "Antigua and Barbuda",
            "Arctic Ocean",
            "Argentina",
            "Armenia",
            "Aruba",
            "Ashmore and Cartier Islands",
            "Atlantic Ocean",
            "Australia",
            "Austria",
            "Azerbaijan",
            "Bahamas",
            "Bahrain",
            "Baltic Sea",
            "Baker Island",
            "Bangladesh",
            "Barbados",
            "Bassas da India",
            "Belarus",
            "Belgium",
            "Belize",
            "Benin",
            "Bermuda",
            "Bhutan",
            "Bolivia",
            "Borneo",
            "Bosnia and Herzegovina",
            "Botswana",
            "Bouvet Island",
            "Brazil",
            "British Virgin Islands",
            "Brunei",
            "Bulgaria",
            "Burkina Faso",
            "Burundi",
            "Cambodia",
            "Cameroon",
            "Canada",
            "Cape Verde",
            "Cayman Islands",
            "Central African Republic",
            "Chad",
            "Chile",
            "China",
            "Christmas Island",
            "Clipperton Island",
            "Cocos Islands",
            "Colombia",
            "Comoros",
            "Cook Islands",
            "Coral Sea Islands",
            "Costa Rica",
            "Cote d'Ivoire",
            "Croatia",
            "Cuba",
            "Curacao",
            "Cyprus",
            "Czech Republic",
            "Democratic Republic of the Congo",
            "Denmark",
            "Djibouti",
            "Dominica",
            "Dominican Republic",
            "Ecuador",
            "Egypt",
            "El Salvador",
            "Equatorial Guinea",
            "Eritrea",
            "Estonia",
            "Eswatini",
            "Ethiopia",
            "Europa Island",
            "Falkland Islands (Islas Malvinas)",
            "Faroe Islands",
            "Fiji",
            "Finland",
            "France",
            "French Guiana",
            "French Polynesia",
            "French Southern and Antarctic Lands",
            "Gabon",
            "Gambia",
            "Gaza Strip",
            "Georgia",
            "Germany",
            "Ghana",
            "Gibraltar",
            "Glorioso Islands",
            "Greece",
            "Greenland",
            "Grenada",
            "Guadeloupe",
            "Guam",
            "Guatemala",
            "Guernsey",
            "Guinea",
            "Guinea-Bissau",
            "Guyana",
            "Haiti",
            "Heard Island and McDonald Islands",
            "Honduras",
            "Hong Kong",
            "Howland Island",
            "Hungary",
            "Iceland",
            "India",
            "Indian Ocean",
            "Indonesia",
            "Iran",
            "Iraq",
            "Ireland",
            "Isle of Man",
            "Israel",
            "Italy",
            "Jamaica",
            "Jan Mayen",
            "Japan",
            "Jarvis Island",
            "Jersey",
            "Johnston Atoll",
            "Jordan",
            "Juan de Nova Island",
            "Kazakhstan",
            "Kenya",
            "Kerguelen Archipelago",
            "Kingman Reef",
            "Kiribati",
            "Kosovo",
            "Kuwait",
            "Kyrgyzstan",
            "Laos",
            "Latvia",
            "Lebanon",
            "Lesotho",
            "Liberia",
            "Libya",
            "Liechtenstein",
            "Line Islands",
            "Lithuania",
            "Luxembourg",
            "Macau",
            "Madagascar",
            "Malawi",
            "Malaysia",
            "Maldives",
            "Mali",
            "Malta",
            "Marshall Islands",
            "Martinique",
            "Mauritania",
            "Mauritius",
            "Mayotte",
            "Mediterranean Sea",
            "Mexico",
            "Micronesia, Federated States of",
            "Midway Islands",
            "Moldova",
            "Monaco",
            "Mongolia",
            "Montenegro",
            "Montserrat",
            "Morocco",
            "Mozambique",
            "Myanmar",
            "Namibia",
            "Nauru",
            "Navassa Island",
            "Nepal",
            "Netherlands",
            "New Caledonia",
            "New Zealand",
            "Nicaragua",
            "Niger",
            "Nigeria",
            "Niue",
            "Norfolk Island",
            "North Korea",
            "North Macedonia",
            "North Sea",
            "Northern Mariana Islands",
            "Norway",
            "Oman",
            "Pacific Ocean",
            "Pakistan",
            "Palau",
            "Palmyra Atoll",
            "Panama",
            "Papua New Guinea",
            "Paracel Islands",
            "Paraguay",
            "Peru",
            "Philippines",
            "Pitcairn Islands",
            "Poland",
            "Portugal",
            "Puerto Rico",
            "Qatar",
            "Republic of the Congo",
            "Reunion",
            "Romania",
            "Ross Sea",
            "Russia",
            "Rwanda",
            "Saint Barthelemy",
            "Saint Helena",
            "Saint Kitts and Nevis",
            "Saint Lucia",
            "Saint Martin",
            "Saint Pierre and Miquelon",
            "Saint Vincent and the Grenadines",
            "Samoa",
            "San Marino",
            "Sao Tome and Principe",
            "Saudi Arabia",
            "Senegal",
            "Serbia",
            "Seychelles",
            "Sierra Leone",
            "Singapore",
            "Sint Maarten",
            "Slovakia",
            "Slovenia",
            "Solomon Islands",
            "Somalia",
            "South Africa",
            "South Georgia and the South Sandwich Islands",
            "South Korea",
            "South Sudan",
            "Southern Ocean",
            "Spain",
            "Spratly Islands",
            "Sri Lanka",
            "State of Palestine",
            "Sudan",
            "Suriname",
            "Svalbard",
            "Sweden",
            "Switzerland",
            "Syria",
            "Taiwan",
            "Tajikistan",
            "Tanzania",
            "Tasman Sea",
            "Thailand",
            "Timor-Leste",
            "Togo",
            "Tokelau",
            "Tonga",
            "Trinidad and Tobago",
            "Tromelin Island",
            "Tunisia",
            "Turkey",
            "Turkmenistan",
            "Turks and Caicos Islands",
            "Tuvalu",
            "Uganda",
            "Ukraine",
            "United Arab Emirates",
            "United Kingdom",
            "Uruguay",
            "USA",
            "Uzbekistan",
            "Vanuatu",
            "Venezuela",
            "Viet Nam",
            "Virgin Islands",
            "Wake Island",
            "Wallis and Futuna",
            "West Bank",
            "Western Sahara",
            "Yemen",
            "Zambia",
            "Zimbabwe",
            "Belgian Congo",
            "British Guiana",
            "Burma",
            "Czechoslovakia",
            "East Timor",
            "Korea",
            "Macedonia",
            "Micronesia",
            "Netherlands Antilles",
            "Serbia and Montenegro",
            "Siam",
            "Swaziland",
            "The former Yugoslav Republic of Macedonia",
            "USSR",
            "Yugoslavia",
            "Zaire",
        ];
    }

    # https://www.iban.com/country-codes
    public static function getCountryCode($country) {
        $codes = [
            "Afghanistan" => "AF",
            "Albania" => "AL",
            "Algeria" => "DZ",
            "American Samoa" => "AS",
            "Andorra" => "AD",
            "Angola" => "AO",
            "Anguilla" => "AI",
            "Antarctica" => "AQ",
            "Antigua and Barbuda" => "AG",
            "Argentina" => "AR",
            "Armenia" => "AM",
            "Aruba" => "AW",
            "Australia" => "AU",
            "Austria" => "AT",
            "Azerbaijan" => "AZ",
            "Bahamas" => "BS",
            "Bahrain" => "BH",
            "Bangladesh" => "BD",
            "Barbados" => "BB",
            "Belarus" => "BY",
            "Belgium" => "BE",
            "Belize" => "BZ",
            "Benin" => "BJ",
            "Bermuda" => "BM",
            "Bhutan" => "BT",
            "Plurinational State of Bolivia" => "BO",
            "Bolivia" => "BO",
            "Bonaire, Sint Eustatius and Saba" => "BQ",
            "Bosnia and Herzegovina" => "BA",
            "Botswana" => "BW",
            "Bouvet Island" => "BV",
            "Brazil" => "BR",
            "British Indian Ocean Territory" => "IO",
            "Brunei Darussalam" => "BN",
            "Brunei" => "BN",
            "Bulgaria" => "BG",
            "Burkina Faso" => "BF",
            "Burundi" => "BI",
            "Cabo Verde" => "CV",
            "Cape Verde" => "CV",
            "Cambodia" => "KH",
            "Cameroon" => "CM",
            "Canada" => "CA",
            "Cayman Islands" => "KY",
            "Central African Republic" => "CF",
            "Chad" => "TD",
            "Chile" => "CL",
            "China" => "CN",
            "Christmas Island" => "CX",
            "Cocos (Keeling) Islands" => "CC",
            "Colombia" => "CO",
            "Comoros" => "KM",
            "Democratic Republic of the Congo" => "CD",
            "Belgian Congo" => "CD",
            "Zaire" => "CD",
            "Congo" => "CG",
            "Republic of the Congo" => "CG",
            "Cook Islands" => "CK",
            "Costa Rica" => "CR",
            "Croatia" => "HR",
            "Cuba" => "CU",
            "Curaçao" => "CW",
            "Cyprus" => "CY",
            "Czechia" => "CZ",
            "Czech Republic" => "CZ",
            "Czechoslovakia" => "CZ",
            "Côte d'Ivoire" => "CI",
            "Cote d'Ivoire" => "CI",
            "Denmark" => "DK",
            "Djibouti" => "DJ",
            "Dominica" => "DM",
            "Dominican Republic" => "DO",
            "Ecuador" => "EC",
            "Egypt" => "EG",
            "El Salvador" => "SV",
            "Equatorial Guinea" => "GQ",
            "Eritrea" => "ER",
            "Estonia" => "EE",
            "Eswatini" => "SZ",
            "Ethiopia" => "ET",
            "Falkland Islands [Malvinas]" => "FK",
            "Falkland Islands (Islas Malvinas)" => "FK",
            "Faroe Islands" => "FO",
            "Fiji" => "FJ",
            "Finland" => "FI",
            "France" => "FR",
            "French Guiana" => "GF",
            "French Polynesia" => "PF",
            "French Southern Territories" => "TF",
            "Gabon" => "GA",
            "Gambia" => "GM",
            "Georgia" => "GE",
            "Germany" => "DE",
            "Ghana" => "GH",
            "Gibraltar" => "GI",
            "Greece" => "GR",
            "Greenland" => "GL",
            "Grenada" => "GD",
            "Guadeloupe" => "GP",
            "Guam" => "GU",
            "Guatemala" => "GT",
            "Guernsey" => "GG",
            "Guinea" => "GN",
            "Guinea-Bissau" => "GW",
            "Guyana" => "GY",
            "Haiti" => "HT",
            "Heard Island and McDonald Islands" => "HM",
            "Holy See" => "VA",
            "Honduras" => "HN",
            "Hong Kong" => "HK",
            "Hungary" => "HU",
            "Iceland" => "IS",
            "India" => "IN",
            "Indonesia" => "ID",
            "Islamic Republic of Iran" => "IR",
            "Iran" => "IR",
            "Iraq" => "IQ",
            "Ireland" => "IE",
            "Isle of Man" => "IM",
            "Israel" => "IL",
            "Italy" => "IT",
            "Jamaica" => "JM",
            "Japan" => "JP",
            "Jersey" => "JE",
            "Jordan" => "JO",
            "Kazakhstan" => "KZ",
            "Kenya" => "KE",
            "Kiribati" => "KI",
            "Democratic People's Republic of Korea" => "KP",
            "North Korea" => "KP",
            "Republic of Korea" => "KR",
            "South Korea" => "KR",
            "Korea" => "KR",
            "Kosovo" => "XK",
            "Kuwait" => "KW",
            "Kyrgyzstan" => "KG",
            "Lao People's Democratic Republic" => "LA",
            "Laos" => "LA",
            "Latvia" => "LV",
            "Lebanon" => "LB",
            "Lesotho" => "LS",
            "Liberia" => "LR",
            "Libya" => "LY",
            "Liechtenstein" => "LI",
            "Lithuania" => "LT",
            "Luxembourg" => "LU",
            "Macao" => "MO",
            "Madagascar" => "MG",
            "Malawi" => "MW",
            "Malaysia" => "MY",
            "Maldives" => "MV",
            "Mali" => "ML",
            "Malta" => "MT",
            "Marshall Islands" => "MH",
            "Martinique" => "MQ",
            "Mauritania" => "MR",
            "Mauritius" => "MU",
            "Mayotte" => "YT",
            "Mexico" => "MX",
            "Federated States of Micronesia" => "FM",
            "Micronesia, Federated States of" => "FM",
            "Micronesia" => "FM",
            "Moldova" => "MD",
            "Monaco" => "MC",
            "Mongolia" => "MN",
            "Montenegro" => "ME",
            "Montserrat" => "MS",
            "Morocco" => "MA",
            "Mozambique" => "MZ",
            "Myanmar" => "MM",
            "Burma" => "MM",
            "Namibia" => "NA",
            "Nauru" => "NR",
            "Nepal" => "NP",
            "Netherlands" => "NL",
            "New Caledonia" => "NC",
            "New Zealand" => "NZ",
            "Nicaragua" => "NI",
            "Niger" => "NE",
            "Nigeria" => "NG",
            "Niue" => "NU",
            "Norfolk Island" => "NF",
            "Northern Mariana Islands" => "MP",
            "Norway" => "NO",
            "Oman" => "OM",
            "Pakistan" => "PK",
            "Palau" => "PW",
            "State of Palestine" => "PS",
            "West Bank" => "PS",
            "Gaza Strip" => "PS",
            "Panama" => "PA",
            "Papua New Guinea" => "PG",
            "Paraguay" => "PY",
            "Peru" => "PE",
            "Philippines" => "PH",
            "Pitcairn" => "PN",
            "Poland" => "PL",
            "Portugal" => "PT",
            "Puerto Rico" => "PR",
            "Qatar" => "QA",
            "North Macedonia" => "MK",
            "The former Yugoslav Republic of Macedonia" => "MK",
            "Macedonia" => "MK",
            "Romania" => "RO",
            "Russian Federation" => "RU",
            "Russia" => "RU",
            "USSR" => "RU",
            "Rwanda" => "RW",
            "Réunion" => "RE",
            "Reunion" => "RE",
            "Saint Barthélemy" => "BL",
            "Saint Barthelemy" => "BL",
            "Saint Helena" => "SH",
            "Saint Kitts and Nevis" => "KN",
            "Saint Lucia" => "LC",
            "Saint Martin" => "MF",
            "Saint Pierre and Miquelon" => "PM",
            "Saint Vincent and the Grenadines" => "VC",
            "Samoa" => "WS",
            "San Marino" => "SM",
            "Sao Tome and Principe" => "ST",
            "Saudi Arabia" => "SA",
            "Senegal" => "SN",
            "Serbia" => "RS",
            "Serbia and Montenegro" => "RS",
            "Yugoslavia" => "RS",
            "Seychelles" => "SC",
            "Sierra Leone" => "SL",
            "Singapore" => "SG",
            "Sint Maarten" => "SX",
            "Slovakia" => "SK",
            "Slovenia" => "SI",
            "Solomon Islands" => "SB",
            "Somalia" => "SO",
            "South Africa" => "ZA",
            "South Georgia and the South Sandwich Islands" => "GS",
            "South Sudan" => "SS",
            "Spain" => "ES",
            "Sri Lanka" => "LK",
            "Sudan" => "SD",
            "Suriname" => "SR",
            "Svalbard and Jan Mayen" => "SJ",
            "Svalbard" => "SJ",
            "Jan Mayen" => "SJ",
            "Sweden" => "SE",
            "Switzerland" => "CH",
            "Syrian Arab Republic" => "SY",
            "Syria" => "SY",
            "Taiwan (Province of China)" => "TW",
            "Taiwan" => "TW",
            "Tajikistan" => "TJ",
            "United Republic of Tanzania" => "TZ",
            "Tanzania" => "TZ",
            "Thailand" => "TH",
            "Siam" => "TH",
            "Timor-Leste" => "TL",
            "East Timor" => "TL",
            "Togo" => "TG",
            "Tokelau" => "TK",
            "Tonga" => "TO",
            "Trinidad and Tobago" => "TT",
            "Tunisia" => "TN",
            "Turkey" => "TR",
            "Turkmenistan" => "TM",
            "Turks and Caicos Islands" => "TC",
            "Tuvalu" => "TV",
            "Uganda" => "UG",
            "Ukraine" => "UA",
            "United Arab Emirates" => "AE",
            "United Kingdom of Great Britain and Northern Ireland" => "GB",
            "United Kingdom" => "GB",
            "United States Minor Outlying Islands" => "UM",
            "United States of America" => "US",
            "USA" => "US",
            "Uruguay" => "UY",
            "Uzbekistan" => "UZ",
            "Vanuatu" => "VU",
            "Bolivarian Republic of Venezuela" => "VE",
            "Venezuela" => "VE",
            "Viet Nam" => "VN",
            "Virgin Islands (British)" => "VG",
            "British Virgin Islands" => "VG",
            "Virgin Islands (U.S.)" => "VI",
            "Virgin Islands" => "VI",
            "Wallis and Futuna" => "WF",
            "Western Sahara" => "EH",
            "Yemen" => "YE",
            "Zambia" => "ZM",
            "Zimbabwe" => "ZW",
            "Åland Islands" => "AX",
        ];
        return $codes[$country] ?? "";
    }

    # [latitude, longitude]
    # Expanded from https://developers.google.com/public-data/docs/canonical/countries_csv
    public static function getCountryCoordinate($country) {
        $coords = [
            "Andorra" => [42.546245, 1.601554],
            "United Arab Emirates" => [23.424076, 53.847818],
            "Afghanistan" => [33.93911, 67.709953],
            "Antigua and Barbuda" => [17.060816, -61.796428],
            "Anguilla" => [18.220554, -63.068615],
            "Albania" => [41.153332, 20.168331],
            "Armenia" => [40.069099, 45.038189],
            "Netherlands Antilles" => [12.226079, -69.060087],
            "Angola" => [-11.202692, 17.873887],
            "Antarctica" => [-75.250973, -0.071389],
            "Argentina" => [-38.416097, -63.616672],
            "American Samoa" => [-14.270972, -170.132217],
            "Austria" => [47.516231, 14.550072],
            "Australia" => [-25.274398, 133.775136],
            "Aruba" => [12.52111, -69.968338],
            "Azerbaijan" => [40.143105, 47.576927],
            "Bosnia and Herzegovina" => [43.915886, 17.679076],
            "Barbados" => [13.193887, -59.543198],
            "Bangladesh" => [23.684994, 90.356331],
            "Belgium" => [50.503887, 4.469936],
            "Burkina Faso" => [12.238333, -1.561593],
            "Bulgaria" => [42.733883, 25.48583],
            "Bahrain" => [25.930414, 50.637772],
            "Burundi" => [-3.373056, 29.918886],
            "Benin" => [9.30769, 2.315834],
            "Bermuda" => [32.321384, -64.75737],
            "Brunei" => [4.535277, 114.727669],
            "Bolivia" => [-16.290154, -63.588653],
            "Brazil" => [-14.235004, -51.92528],
            "Bahamas" => [25.03428, -77.39628],
            "Bhutan" => [27.514162, 90.433601],
            "Bouvet Island" => [-54.423199, 3.413194],
            "Botswana" => [-22.328474, 24.684866],
            "Belarus" => [53.709807, 27.953389],
            "Belize" => [17.189877, -88.49765],
            "Canada" => [56.130366, -106.346771],
            "Cocos [Keeling] Islands" => [-12.164165, 96.870956],
            "Democratic Republic of the Congo" => [-4.038333, 21.758664],
            "Zaire" => [-4.038333, 21.758664],
            "Central African Republic" => [6.611111, 20.939444],
            "Republic of the Congo" => [-0.228021, 15.827659],
            "Switzerland" => [46.818188, 8.227512],
            "Côte d'Ivoire" => [7.539989, -5.54708],
            "Cote d'Ivoire" => [7.539989, -5.54708],
            "Cook Islands" => [-21.236736, -159.777671],
            "Chile" => [-35.675147, -71.542969],
            "Cameroon" => [7.369722, 12.354722],
            "China" => [35.86166, 104.195397],
            "Colombia" => [4.570868, -74.297333],
            "Costa Rica" => [9.748917, -83.753428],
            "Cuba" => [21.521757, -77.781167],
            "Cape Verde" => [16.002082, -24.013197],
            "Christmas Island" => [-10.447525, 105.690449],
            "Cyprus" => [35.126413, 33.429859],
            "Czech Republic" => [49.817492, 15.472962],
            "Czechoslovakia" => [49.817492, 15.472962],
            "Germany" => [51.165691, 10.451526],
            "Djibouti" => [11.825138, 42.590275],
            "Denmark" => [56.26392, 9.501785],
            "Dominica" => [15.414999, -61.370976],
            "Dominican Republic" => [18.735693, -70.162651],
            "Algeria" => [28.033886, 1.659626],
            "Ecuador" => [-1.831239, -78.183406],
            "Estonia" => [58.595272, 25.013607],
            "Egypt" => [26.820553, 30.802498],
            "Western Sahara" => [24.215527, -12.885834],
            "Eritrea" => [15.179384, 39.782334],
            "Spain" => [40.463667, -3.74922],
            "Ethiopia" => [9.145, 40.489673],
            "Finland" => [61.92411, 25.748151],
            "Fiji" => [-16.578193, 179.414413],
            "Falkland Islands (Islas Malvinas)" => [-51.796253, -59.523613],
            "Micronesia" => [7.425554, 150.550812],
            "Micronesia, Federated States of" => [7.425554, 150.550812],
            "Faroe Islands" => [61.892635, -6.911806],
            "France" => [46.227638, 2.213749],
            "Gabon" => [-0.803689, 11.609444],
            "United Kingdom" => [55.378051, -3.435973],
            "Grenada" => [12.262776, -61.604171],
            "Georgia" => [42.315407, 43.356892],
            "French Guiana" => [3.933889, -53.125782],
            "Guernsey" => [49.465691, -2.585278],
            "Ghana" => [7.946527, -1.023194],
            "Gibraltar" => [36.137741, -5.345374],
            "Greenland" => [71.706936, -42.604303],
            "Gambia" => [13.443182, -15.310139],
            "Guinea" => [9.945587, -9.696645],
            "Guadeloupe" => [16.995971, -62.067641],
            "Equatorial Guinea" => [1.650801, 10.267895],
            "Greece" => [39.074208, 21.824312],
            "South Georgia and the South Sandwich Islands" => [-54.429579, -36.587909],
            "Guatemala" => [15.783471, -90.230759],
            "Guam" => [13.444304, 144.793731],
            "Guinea-Bissau" => [11.803749, -15.180413],
            "Guyana" => [4.860416, -58.93018],
            "Gaza Strip" => [31.354676, 34.308825],
            "Hong Kong" => [22.396428, 114.109497],
            "Heard Island and McDonald Islands" => [-53.08181, 73.504158],
            "Honduras" => [15.199999, -86.241905],
            "Croatia" => [45.1, 15.2],
            "Haiti" => [18.971187, -72.285215],
            "Hungary" => [47.162494, 19.503304],
            "Indonesia" => [-0.789275, 113.921327],
            "Ireland" => [53.41291, -8.24389],
            "Israel" => [31.046051, 34.851612],
            "Isle of Man" => [54.236107, -4.548056],
            "India" => [20.593684, 78.96288],
            "British Indian Ocean Territory" => [-6.343194, 71.876519],
            "Iraq" => [33.223191, 43.679291],
            "Iran" => [32.427908, 53.688046],
            "Iceland" => [64.963051, -19.020835],
            "Italy" => [41.87194, 12.56738],
            "Jersey" => [49.214439, -2.13125],
            "Jamaica" => [18.109581, -77.297508],
            "Jordan" => [30.585164, 36.238414],
            "Japan" => [36.204824, 138.252924],
            "Kenya" => [-0.023559, 37.906193],
            "Kyrgyzstan" => [41.20438, 74.766098],
            "Cambodia" => [12.565679, 104.990963],
            "Kiribati" => [-3.370417, -168.734039],
            "Comoros" => [-11.875001, 43.872219],
            "Saint Kitts and Nevis" => [17.357822, -62.782998],
            "North Korea" => [40.339852, 127.510093],
            "South Korea" => [35.907757, 127.766922],
            "Korea" => [35.907757, 127.766922],
            "Kuwait" => [29.31166, 47.481766],
            "Cayman Islands" => [19.513469, -80.566956],
            "Kazakhstan" => [48.019573, 66.923684],
            "Laos" => [19.85627, 102.495496],
            "Lebanon" => [33.854721, 35.862285],
            "Saint Lucia" => [13.909444, -60.978893],
            "Liechtenstein" => [47.166, 9.555373],
            "Sri Lanka" => [7.873054, 80.771797],
            "Liberia" => [6.428055, -9.429499],
            "Lesotho" => [-29.609988, 28.233608],
            "Lithuania" => [55.169438, 23.881275],
            "Luxembourg" => [49.815273, 6.129583],
            "Latvia" => [56.879635, 24.603189],
            "Libya" => [26.3351, 17.228331],
            "Morocco" => [31.791702, -7.09262],
            "Monaco" => [43.750298, 7.412841],
            "Moldova" => [47.411631, 28.369885],
            "Montenegro" => [42.708678, 19.37439],
            "Madagascar" => [-18.766947, 46.869107],
            "Marshall Islands" => [7.131474, 171.184478],
            "Macedonia" => [41.608635, 21.745275],
            "The former Yugoslav Republic of Macedonia" => [41.608635, 21.745275],
            "Mali" => [17.570692, -3.996166],
            "Myanmar" => [21.913965, 95.956223],
            "Burma" => [21.913965, 95.956223],
            "Mongolia" => [46.862496, 103.846656],
            "Macau" => [22.198745, 113.543873],
            "Northern Mariana Islands" => [17.33083, 145.38469],
            "Martinique" => [14.641528, -61.024174],
            "Mauritania" => [21.00789, -10.940835],
            "Montserrat" => [16.742498, -62.187366],
            "Malta" => [35.937496, 14.375416],
            "Mauritius" => [-20.348404, 57.552152],
            "Maldives" => [3.202778, 73.22068],
            "Malawi" => [-13.254308, 34.301525],
            "Mexico" => [23.634501, -102.552784],
            "Malaysia" => [4.210484, 101.975766],
            "Mozambique" => [-18.665695, 35.529562],
            "Namibia" => [-22.95764, 18.49041],
            "New Caledonia" => [-20.904305, 165.618042],
            "Niger" => [17.607789, 8.081666],
            "Norfolk Island" => [-29.040835, 167.954712],
            "Nigeria" => [9.081999, 8.675277],
            "Nicaragua" => [12.865416, -85.207229],
            "Netherlands" => [52.132633, 5.291266],
            "Norway" => [60.472024, 8.468946],
            "Nepal" => [28.394857, 84.124008],
            "Nauru" => [-0.522778, 166.931503],
            "Niue" => [-19.054445, -169.867233],
            "New Zealand" => [-40.900557, 174.885971],
            "Oman" => [21.512583, 55.923255],
            "Panama" => [8.537981, -80.782127],
            "Peru" => [-9.189967, -75.015152],
            "French Polynesia" => [-17.679742, -149.406843],
            "Papua New Guinea" => [-6.314993, 143.95555],
            "Philippines" => [12.879721, 121.774017],
            "Pakistan" => [30.375321, 69.345116],
            "Poland" => [51.919438, 19.145136],
            "Saint Pierre and Miquelon" => [46.941936, -56.27111],
            "Pitcairn Islands" => [-24.703615, -127.439308],
            "Puerto Rico" => [18.220833, -66.590149],
            "State of Palestine" => [31.952162, 35.233154],
            "West Bank" => [31.952162, 35.233154],
            "Portugal" => [39.399872, -8.224454],
            "Palau" => [7.51498, 134.58252],
            "Paraguay" => [-23.442503, -58.443832],
            "Qatar" => [25.354826, 51.183884],
            "Réunion" => [-21.115141, 55.536384],
            "Romania" => [45.943161, 24.96676],
            "Serbia" => [44.016521, 21.005859],
            "Serbia and Montenegro" => [44.016521, 21.005859],
            "Yugoslavia" => [44.016521, 21.005859],
            "Russia" => [61.52401, 105.318756],
            "USSR" => [61.52401, 105.318756],
            "Rwanda" => [-1.940278, 29.873888],
            "Saudi Arabia" => [23.885942, 45.079162],
            "Solomon Islands" => [-9.64571, 160.156194],
            "Seychelles" => [-4.679574, 55.491977],
            "Sudan" => [12.862807, 30.217636],
            "Sweden" => [60.128161, 18.643501],
            "Singapore" => [1.352083, 103.819836],
            "Saint Helena" => [-24.143474, -10.030696],
            "Slovenia" => [46.151241, 14.995463],
            "Svalbard" => [77.553604, 23.670272],
            "Jan Mayen" => [77.553604, 23.670272],
            "Slovakia" => [48.669026, 19.699024],
            "Sierra Leone" => [8.460555, -11.779889],
            "San Marino" => [43.94236, 12.457777],
            "Senegal" => [14.497401, -14.452362],
            "Somalia" => [5.152149, 46.199616],
            "Suriname" => [3.919305, -56.027783],
            "São Tomé and Príncipe" => [0.18636, 6.613081],
            "El Salvador" => [13.794185, -88.89653],
            "Syria" => [34.802075, 38.996815],
            "Swaziland" => [-26.522503, 31.465866],
            "Turks and Caicos Islands" => [21.694025, -71.797928],
            "Chad" => [15.454166, 18.732207],
            "French Southern Territories" => [-49.280366, 69.348557],
            "Togo" => [8.619543, 0.824782],
            "Thailand" => [15.870032, 100.992541],
            "Siam" => [15.870032, 100.992541],
            "Tajikistan" => [38.861034, 71.276093],
            "Tokelau" => [-8.967363, -171.855881],
            "Timor-Leste" => [-8.874217, 125.727539],
            "East Timor" => [-8.874217, 125.727539],
            "Turkmenistan" => [38.969719, 59.556278],
            "Tunisia" => [33.886917, 9.537499],
            "Tonga" => [-21.178986, -175.198242],
            "Turkey" => [38.963745, 35.243322],
            "Trinidad and Tobago" => [10.691803, -61.222503],
            "Tuvalu" => [-7.109535, 177.64933],
            "Taiwan" => [23.69781, 120.960515],
            "Tanzania" => [-6.369028, 34.888822],
            "Ukraine" => [48.379433, 31.16558],
            "Uganda" => [1.373333, 32.290275],
            "USA" => [37.09024, -95.712891],
            "Uruguay" => [-32.522779, -55.765835],
            "Uzbekistan" => [41.377491, 64.585262],
            "Vatican City" => [41.902916, 12.453389],
            "Saint Vincent and the Grenadines" => [12.984305, -61.287228],
            "Venezuela" => [6.42375, -66.58973],
            "British Virgin Islands" => [18.420695, -64.639968],
            "Virgin Islands" => [18.335765, -64.896335],
            "Viet Nam" => [14.058324, 108.277199],
            "Vanuatu" => [-15.376706, 166.959158],
            "Wallis and Futuna" => [-13.768752, -177.156097],
            "Samoa" => [-13.759029, -172.104629],
            "Kosovo" => [42.602636, 20.902977],
            "Yemen" => [15.552727, 48.516388],
            "Mayotte" => [-12.8275, 45.166244],
            "South Africa" => [-30.559482, 22.937506],
            "Zambia" => [-13.133897, 27.849332],
            "Zimbabwe" => [-19.015438, 29.154857],
            "Tasman Sea" => [-40.8581, 160.4313],
            "Tromelin Island" => [-15.8917, 54.5235],
            "Wake Island" => [19.2796, 166.6499],
            "Belgian Congo" => [-4.038333, 21.758664],
            "British Guiana" => [4.8604, -58.9302],
            "Arctic Ocean" => [65.2482, -60.4621],
            "Ashmore and Cartier Islands" => [-12.2583, 123.0416],
            "Atlantic Ocean" => [-14.5994, -28.631],
            "Baltic Sea" => [19.8633, 58,4880],
            "Baker Island" => [0.1936, -176.4769],
            "Bassas da India" => [-21.483, 39.683],
            "Borneo" => [0.9619, 114.5548],
            "Clipperton Island" => [10.3021, -109.2177],
            "Cocos Islands" => [-12.1642, 96.8710],
            "Coral Sea Islands" => [-19.3920, 155.8561],
            "Curacao" => [12.169570, -68.990021],
            "Eswatini" => [-26.5225, 31.4659],
            "Europa Island" => [-22.3667, 40.3529],
            "French Southern and Antarctic Lands" => [-49.2804, 69.3486],
            "Glorioso Islands" => [-11.5532, 47.3231],
            "Howland Island" => [0.8113, -176.6183],
            "Indian Ocean" => [-33.1376, 81.8262],
            "Jarvis Island" => [-0.3744, -159.9967],
            "Johnston Atoll" => [16.7295, -169.5336],
            "Juan de Nova Island" => [-17.0548, 42.7245],
            "Kerguelen Archipelago" => [-49.3500, 70.2167],
            "Kingman Reef" => [6.3833, -162.4167],
            "Line Islands" => [1.9420, -157.4750],
            "Mediterranean Sea" => [34.5531, 18.0480],
            "Midway Islands" => [28.2072, -177.3735],
            "Navassa Island" => [18.4101, -75.0115],
            "North Macedonia" => [41.6086, 21.7453],
            "North Sea" => [56.5110, 3.5156],
            "Pacific Ocean" => [-8.7832, -124.5085],
            "Palmyra Atoll" => [5.8885, -162.0787],
            "Paracel Islands" => [16.24, -111.85],
            "Reunion" => [-21.1151, 55.5364],
            "Ross Sea" => [-74.5487, -166.3074],
            "Saint Barthelemy" => [17.9000, -62.8333],
            "Saint Martin" => [18.0708, -63.0501],
            "Sao Tome and Principe" => [0.1864, 6.6131],
            "Sint Maarten" => [18.0425, -63.0548],
            "South Sudan" => [6.8770, 31.3070],
            "Southern Ocean" => [-68.4380, -160.2340],
            "Spratly Islands" => [10.7233, 115.8265],
        ];
        return $coords[$country] ?? [];
    }

	public static function getCitationsFromPubMed($pmids, $metadata, $src = "", $recordId = 0, $startInstance = 1, $confirmedPMIDs = [], $pid = NULL, $getBibliometricInfo = TRUE) {
        $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
        $upload = [];
		$instance = $startInstance;
		$pullSize = self::getPMIDLimit();
		for ($i0 = 0; $i0 < count($pmids); $i0 += $pullSize) {
			$pmidsToPull = [];
			for ($j = $i0; ($j < count($pmids)) && ($j < $i0 + $pullSize); $j++) {
				$pmidsToPull[] = $pmids[$j];
			}
            Application::log("Downloading PMIDs: ".implode(", ", $pmidsToPull), $pid);
            $xml = self::repetitivelyPullFromEFetch($pmidsToPull, $pid);
            if ($xml) {
                list($parsedRows, $pmidsPulled) = self::xml2REDCap($xml, $recordId, $instance, $src, $confirmedPMIDs, $metadataFields, $pid);
                $upload = array_merge($upload, $parsedRows);
                $translateFromPMIDToPMC = self::PMIDsToPMCs($pmidsPulled, $pid);
                if ($getBibliometricInfo) {
                    $iCite = new iCite($pmidsPulled, $pid);
                    foreach ($pmidsPulled as $pmid) {
                        for ($j = 0; $j < count($upload); $j++) {
                            if ($upload[$j]['citation_pmid'] == $pmid) {
                                if (isset($translateFromPMIDToPMC[$pmid])) {
                                    $pmcid = $translateFromPMIDToPMC[$pmid];
                                    if ($pmcid) {
                                        if (!preg_match("/PMC/", $pmcid)) {
                                            $pmcid = "PMC" . $pmcid;
                                        }
                                        $upload[$j]['citation_pmcid'] = $pmcid;
                                    }
                                }
                                $upload[$j]["citation_doi"] = $iCite->getVariable($pmid, "doi");
                                $upload[$j]["citation_is_research"] = $iCite->getVariable($pmid, "is_research_article");
                                $upload[$j]["citation_num_citations"] = $iCite->getVariable($pmid, "citation_count");
                                $upload[$j]["citation_citations_per_year"] = $iCite->getVariable($pmid, "citations_per_year");
                                $upload[$j]["citation_expected_per_year"] = $iCite->getVariable($pmid, "expected_citations_per_year");
                                $upload[$j]["citation_field_citation_rate"] = $iCite->getVariable($pmid, "field_citation_rate");
                                $upload[$j]["citation_nih_percentile"] = $iCite->getVariable($pmid, "nih_percentile");
                                $upload[$j]["citation_rcr"] = $iCite->getVariable($pmid, "relative_citation_ratio");
                                if (in_array("citation_icite_last_update", $metadataFields)) {
                                    $upload[$j]["citation_icite_last_update"] = date("Y-m-d");
                                }

                                $altmetricRow = self::getAltmetricRow($iCite->getVariable($pmid, "doi"), $metadataFields, $pid);
                                $upload[$j] = array_merge($upload[$j], $altmetricRow);
                            }
                        }
                    }
                }
                if (!$recordId) {
                    throw new \Exception("Please specify a record id!");
                } else if (empty($pmidsPulled)) {     // $pmidsPulled is empty
                    Application::log("ERROR: No PMIDs pulled from ".json_encode($pmidsToPull), $pid);
                }
            }
		}
        if ($getBibliometricInfo) {
            self::addTimesCited($upload, $pid, $pmids, $metadataFields);
        }

		$uploadPMIDsAndInstances = [];
		foreach ($upload as $row) {
		    $uploadPMIDsAndInstances[$row['redcap_repeat_instance']] = $row['citation_pmid'];
        }
		Application::log("$recordId: Returning ".count($upload)." lines from getCitationsFromPubMed: ".REDCapManagement::json_encode_with_spaces($uploadPMIDsAndInstances), $pid);
		return $upload;
	}

	private function updateAssocGrantsAndBibliometrics(&$upload, $pmids) {
        $todaysHighPerformingPMIDs = [];
        $metadataFields = REDCapManagement::getFieldsFromMetadata($this->metadata);
        $rows = self::getCitationsFromPubMed($pmids, $this->metadata, "", $this->recordId, 1, [], $this->pid);
        $fieldsToCopy = [
            "citation_grants",      // associated grants
            "citation_doi",
            "citation_is_research",
            "citation_num_citations",
            "citation_citations_per_year",
            "citation_expected_per_year",
            "citation_field_citation_rate",
            "citation_nih_percentile",
            "citation_rcr",
            "citation_icite_last_update",
        ];

        $i = 0;
        foreach ($upload as $row) {
            if ($row['record_id'] == $this->recordId) {
                $pmid = $row['citation_pmid'];
                foreach ($rows as $row2) {
                    if (($pmid == $row2['citation_pmid']) && ($row2['record_id'] == $row['record_id'])) {
                        $prevRCR = $upload[$i]["citation_rcr"] ?: 0;
                        foreach ($fieldsToCopy as $field) {
                            if (
                                ($field == "citation_rcr")
                                && ($row2[$field] >= iCite::THRESHOLD_SCORE)
                                && ($prevRCR <= iCite::THRESHOLD_SCORE)
                                && !in_array($pmid, $todaysHighPerformingPMIDs)
                            ) {
                                $todaysHighPerformingPMIDs[] = $pmid;
                            }
                            $upload[$i][$field] = $row2[$field];
                        }
                        $upload[$i] = REDCapManagement::filterForREDCap($upload[$i], $metadataFields);
                    }
                }
            }
            $i++;
        }

        $i = 0;
        foreach ($upload as $row) {
            $prevScore = $row["citation_altmetric_score"] ?: 0;
            $pmid = $row['citation_pmid'];
            if (
                $row['citation_doi']
                && ($row['citation_altmetric_last_update'] != date("Y-m-d"))
            ) {
                $altmetricRow = self::getAltmetricRow($row['citation_doi'], $metadataFields, $this->pid);
                foreach ($altmetricRow as $field => $value) {
                    if (
                        ($field == "citation_altmetric_score")
                        && ($value >= Altmetric::THRESHOLD_SCORE)
                        && ($prevScore <= Altmetric::THRESHOLD_SCORE)
                        && !in_array($pmid, $todaysHighPerformingPMIDs)
                    ) {
                        $todaysHighPerformingPMIDs[] = $pmid;
                    }
                    // overwrite
                    $upload[$i][$field] = $value;
                }
            }
            $i++;
        }
        if (!empty($todaysHighPerformingPMIDs)) {
            $today = date("Y-m-d");
            $allHighPerformingPMIDs = Application::getSetting("high_performing_pmids", $this->pid) ?: [];
            $allHighPerformingPMIDs[$today] = $todaysHighPerformingPMIDs;
            Application::saveSetting("high_performing_pmids", $allHighPerformingPMIDs, $this->pid);
        }
	}

	private static function addTimesCited(&$upload, $pid, $pmids, $metadataFields) {
        $timesCitedField = "citation_wos_times_cited";
        $lastUpdateField = "citation_wos_last_update";
        if (in_array($timesCitedField, $metadataFields)) {
            $wos = new WebOfScience($pid);
            $timesCitedData = $wos->getData($pmids);
            foreach ($timesCitedData as $pmid => $timesCited) {
                for ($i = 0; $i < count($upload); $i++) {
                    if ($upload[$i]['citation_pmid'] == $pmid) {
                        $upload[$i][$timesCitedField] = $timesCited;
                        if (in_array($lastUpdateField, $metadataFields)) {
                            $upload[$i][$lastUpdateField] = date("Y-m-d");
                        }
                    }
                }
            }
        }
    }

	public static function throttleDown($secs = 1) {
        $microseconds = (int) round($secs * 1000000);
        if ($microseconds > 0) {
            usleep($microseconds);
        }
	}

    private function makeFlaggedPublicationsHTML() {
        $flagged = $this->getCitationCollection("Flagged");
        $unflagged = $this->getCitationCollection("Unflagged");

        $html = "<h4>Flag Publications to Use</h4>";
        $html .= $flagged->toHTML("Flagged", FALSE);
        $html .= $unflagged->toHTML("Unflagged", FALSE, $flagged->getCount() + 1);

        return $html;
    }

	public function leftColumnText($newLabel = "New", $existingLabel = "Existing", $omittedLabel = "Omitted", $displayREDCapLink = TRUE) {
        if ($this->wranglerType == "FlagPublications") {
            return $this->makeFlaggedPublicationsHTML();
        }

		$html = "";
		$notDone = $this->getCitationCollection("Not Done");
		$notDoneCount = $notDone->getCount();
		$html .= "<h4 class='newHeader'>";
		if ($notDoneCount == 0) {
			$html .= "No $newLabel Citations";
			$html .= "</h4>";
			$html .= "<div id='newCitations'>";
		} else {
			if ($notDoneCount == 1) {
				$html .= $notDoneCount." $newLabel Citation";
			} else {
				$html .= $notDoneCount." $newLabel Citations";
			}
			$html .= "</h4>";
			$html .= "<div id='newCitations'>";
			if ($notDoneCount > 1) {
                $html .= "<p class='centered'><a href='javascript:;' onclick='selectAllCitations(\"#newCitations\");'>Select All $newLabel Citations</a> | <a href='javascript:;' onclick='unselectAllCitations(\"#newCitations\");'>Deselect All $newLabel Citations</a></p>";
            }
			$html .= $notDone->toHTML("notDone", TRUE, 1, $displayREDCapLink);
		}
		$html .= "</div>";
		$html .= "<hr/>";

		$included = $this->getCitationCollection("Included");
		$html .= "<h4>$existingLabel Citations</h4>";
		$html .= "<div id='finalCitations'>";
		$html .= $included->toHTML("included", TRUE, 1, $displayREDCapLink);
		$html .= "</div>";
        $html .= "<hr/>";

        $omitted = $this->getCitationCollection("Omitted");
        $html .= "<h4>$omittedLabel Citations</h4>";
        $html .= "<div id='omittedCitations'>";
        $html .= $omitted->toHTML("excluded", TRUE, 1, $displayREDCapLink);
        $html .= "</div>";

		return $html;
	}

	private static function makeHiddenValue($id, $value) {
		$idQuote = "'";
		$valueQuote = "'";
		if (preg_match("/'/", $id)) {
			$idQuote = "\"";
		}
		if (preg_match("/'/", $value)) {
			$valueQuote = "\"";
		}
		return "<input type='hidden' id=$idQuote$id$idQuote value=$valueQuote$value$valueQuote>";
	}

	public static function makeUncommonDefinition() {
	    return NameMatcher::makeUncommonDefinition();
    }

    public static function makeLongDefinition() {
	    return NameMatcher::makeLongDefinition();
    }

    # returns HTML to edit the publication; used in data wrangling
	public function getEditText($thisUrl) {
        $wrangler = new Wrangler($this->wranglerType, $this->pid);
        $fullName = Download::fullName($this->token, $this->server, $this->recordId) ?: $this->name;
        if ($this->wranglerType == "FlagPublications") {
            $unflagged = $this->getCitationCollection("Unflagged");
            $flagged = $this->getCitationCollection("Flagged");

            $html = $wrangler->getEditText($unflagged->getCount(), $flagged->getCount(), $this->recordId, $fullName, $this->lastName);
        } else {

            $notDone = $this->getCitationCollection("Not Done");
            $notDoneCount = $notDone->getCount();
            $included = $this->getCitationCollection("Included");
            $includedCount = $included->getCount();

            $html = $wrangler->getEditText($notDoneCount, $includedCount, $this->recordId, $fullName, $this->lastName);
            $html .= self::manualLookup($thisUrl);
            $numPids = count(Application::getPids());
            if ($numPids > 1) {
                $html .= self::lookForMatches($thisUrl, $numPids);
            }
            if ($notDoneCount > 0) {
                $html .= $this->getNameChooser($notDone, $fullName);
            }
        }
        $html .= "<table style='width: 100%;' id='main'><tr>";
        $html .= "<td class='twoColumn yellow' id='left'>".$this->leftColumnText()."</td>";
        $html .= "<td id='right'>".$wrangler->rightColumnText($this->recordId)."</td>";
        $html .= "</tr></table>";

		return $html;
	}

    private static function lookForMatches($thisUrl, $numPids) {
        $html = "<div class='centered' id='nameMatches'><img src='".Application::link("img/loading.gif")."' alt='loading' style='width: 128px; height: 128px;' /><br/>Searching for Matches from $numPids Other Projects...</div>";
        $html .= "<script>
$(document).ready(() => {
    lookForMatches('$thisUrl', '#nameMatches');
});
</script>";
        return $html;
    }

    private function getNameChooser($citationCollection, $name) {
        $pubmedNames = $citationCollection->getBoldedNames(TRUE);
        if (count($pubmedNames) <= 1) {
            return "";
        }
        $wranglerType = Sanitizer::sanitize($_GET['wranglerType'] ?? "");

        $html = "";
        $html .= "<h4>Matches to $name</h4>";
        $html .= "<p class='centered max-width' style='line-height: 2.2em;'>";
        $nameSpans = [];
        $checkedImgLoc = Wrangler::getImageLocation("checked", $this->pid, $wranglerType);
        $uncheckedImgLoc = Wrangler::getImageLocation("unchecked", $this->pid, $wranglerType);
        foreach ($pubmedNames as $i => $pubmedName) {
            $nameSpans[] .= "<span class='clickableOn' title='Click to Toggle' onclick='togglePubMedName(\".name$i\", this, \"$checkedImgLoc\", \"$uncheckedImgLoc\");'>$pubmedName</span>";
        }
        $html .= implode(" ", $nameSpans);
        $html .= "</p>";
        return $html;
    }

	public static function manualLookup($refreshPageUrl, $submitButtonClass = "green") {
		$html = "";
		$html .= "<table id='lookupTable' style='margin-left: auto; margin-right: auto; border-radius: 10px;' class='bin'><tr>\n";
		$html .= "<td style='width: 250px; height: 200px; text-align: left; vertical-align: top;'>\n";
		$html .= "<h4 style='margin-bottom: 0;'>Lookup PMID</h4>\n";
        $html .= "<p class='oneAtATime'><input type='text' id='pmid'> <button onclick='submitPMID($(\"#pmid\").val(), \"#manualCitation\", \"\"); return false;' class='biggerButton' readonly>Go!</button><br><a class='smaller' href='javascript:;' onclick='$(\".list\").show(); $(\".oneAtATime\").hide(); checkSubmitButton(\"#manualCitation\", \".list\");'>Switch to Bulk</a></p>\n";
        $html .= "<p class='list' style='display: none;'><textarea id='pmidList'></textarea> <button onclick='submitPMIDs($(\"#pmidList\").val(), \"#manualCitation\", \"\"); return false;' class='biggerButton' readonly>Go!</button><br><a class='smaller' href='javascript:;' onclick='$(\".list\").hide(); $(\".oneAtATime\").show(); checkSubmitButton(\"#manualCitation\", \".oneAtATime\");'>Switch to Single</a></p>\n";
		$html .= "<h4 style='margin-bottom: 0;'>Lookup PMC</h4>\n";
        $html .= "<p class='oneAtATime'><input type='text' id='pmc'> <button onclick='submitPMC($(\"#pmc\").val(), \"#manualCitation\", \"\"); return false;' class='biggerButton'>Go!</button><br><a class='smaller' href='javascript:;' onclick='$(\".list\").show(); $(\".oneAtATime\").hide(); checkSubmitButton(\"#manualCitation\", \".list\");'>Switch to Bulk</a></p>\n";
        $html .= "<p class='list' style='display: none;'><textarea id='pmcList'></textarea> <button onclick='submitPMCs($(\"#pmcList\").val(), \"#manualCitation\", \"\"); return false;' class='biggerButton'>Go!</button><br><a class='smaller' href='javascript:;' onclick='$(\".list\").hide(); $(\".oneAtATime\").show(); checkSubmitButton(\"#manualCitation\", \".oneAtATime\");'>Switch to Single</a></p>\n";
		$html .= "</td><td style='width: 500px;'>\n";
		$html .= "<div id='lookupResult'>\n";
		$html .= "<p><textarea style='width: 100%; height: 150px; font-size: 16px;' id='manualCitation' readonly></textarea></p>\n";
        $html .= "<p class='oneAtATime'><button class='biggerButton $submitButtonClass includeButton' style='display: none;' onclick='includeCitation($(\"#manualCitation\").val(), \"$refreshPageUrl\"); return false;'>Include &amp; Accept This Citation</button></p>\n";
        $html .= "<p class='list' style='display: none;'><button class='biggerButton $submitButtonClass includeButton' style='display: none;' onclick='includeCitations($(\"#manualCitation\").val(), \"$refreshPageUrl\"); return false;'>Include &amp; Accept These Citations</button></p>\n";
		$html .= "</div>\n";
		$html .= "</td>\n";
		$html .= "</tr></table>\n";
		return $html;
	}

	public static function getCurrentPMC($citation) {
		if (preg_match("/PMC:?\s*\d+/", $citation, $matches)) {
            $match = preg_replace("/PMC:?\s*/", "", $matches[0]);
            return $match;
        }
		return "";
	}

	public static function getCurrentPMID($citation) {
		if (preg_match("/PMID:?\s*\d+/", $citation, $matches)) {
			$match = preg_replace("/PMID:\s*/", "", $matches[0]);
			return $match;
		}
		return "";
	}

	public function getUpdatedBlankPMCs($recordId) {
	    $upload = [];
	    $affectedPMIDs = [];
        foreach ($this->rows as $row) {
            if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "citation")) {
                $instance = $row['redcap_repeat_instance'];
                if (!$row['citation_pmcid']) {
                    $affectedPMIDs[$instance] = $row['citation_pmid'];
                }
            }
        }
        if (!empty($affectedPMIDs)) {
            $translator = self::PMIDsToPMCs(array_values($affectedPMIDs), $this->pid);
            foreach ($affectedPMIDs as $instance => $pmid) {
                $pmcid = $translator[$pmid] ?? "";
                if ($pmcid) {
                    $uploadRow = [
                        "record_id" => $recordId,
                        "redcap_repeat_instrument" => "citation",
                        "redcap_repeat_instance" => $instance,
                        "citation_pmcid" => $pmcid,
                    ];
                    $upload[] = $uploadRow;
                }
            }
        }
        return $upload;
    }

    public static function PMIDsToPMCs($pmids, $pid) {
        if (is_array($pmids) && !empty($pmids)) {
            $translator = self::runIDConverter($pmids, $pid);
            $pmcTranslator = [];
            foreach ($translator as $pmid => $ary) {
                if (in_array($pmid, $pmids)) {
                    $pmcTranslator[$pmid] = $ary['pmcid'];
                }
            }
            return $pmcTranslator;
        }
        return [];
    }

    public static function PMIDsToNIHMS($pmids, $pid) {
        if (!empty($pmids)) {
            $translator = self::runIDConverter($pmids, $pid);
            $nihmsTranslator = [];
            foreach ($translator as $pmid => $ary) {
                if (in_array($pmid, $pmids) && isset($ary['nihms'])) {
                    $nihmsTranslator[$pmid] = $ary['nihms'];
                }
            }
            return $nihmsTranslator;
        }
        return [];
    }

    public static function PMIDToNIHMS($pmid, $pid) {
        $translator = self::PMIDsToPMCs([$pmid], $pid);
        if (isset($translator[$pmid])) {
            return $translator[$pmid];
        }
        return "";
    }

	public static function PMIDToPMC($pmid, $pid) {
	    if ($pmid) {
            $pmcs = self::PMIDsToPMCs([$pmid], $pid);
            if (count($pmcs) > 0) {
                return $pmcs[$pmid];
            }
        }
		return "";
	}

	public static function PMCsToPMIDs($pmcids, $pid) {
	    if (is_array($pmcids)) {
            foreach ($pmcids as &$pmcid) {
                if (!preg_match("/PMC/", $pmcid)) {
                    $pmcid = "PMC".$pmcid;
                }
            }
            if (!empty($pmcids)) {
                $translator = self::runIDConverter($pmcids, $pid);
                $pmidTranslator = [];
                foreach ($translator as $transPmcid => $ary) {
                    if (in_array($transPmcid, $pmcids)) {
                        $pmidTranslator[$transPmcid] = $ary['pmid'];
                    }
                }
                return $pmidTranslator;
            }
        }
	    return [];
    }

	public static function PMCToPMID($pmcid, $pid) {
	    $translator = self::PMCsToPMIDs([$pmcid], $pid);
	    if (isset($translator[$pmcid])) {
	        return $translator[$pmcid];
        }
		return "";
	}

	private static function runIDConverter($id, $pid) {
	    if (is_array($id)) {
	        $ids = $id;
	        $newIds = [];
	        foreach ($ids as $i => $item) {
	            if ($item != "") {
	                $newIds[] = $item;
                }
            }
        } else {
	        $newIds = [$id];
        }
        $idsBatched = [];
	    $pullSize = 20;
	    for ($i = 0; $i < count($newIds); $i += $pullSize) {
	        $batch = [];
	        for ($j = $i; ($j < count($newIds)) && ($j < $i + $pullSize); $j++) {
	            $batch[] = $newIds[$j];
            }
	        if (!empty($batch)) {
                $idsBatched[] = $batch;
            }
        }
        $apiKey = Application::getSetting("pubmed_api_key", $pid);

        $translator = [];
	    foreach ($idsBatched as $batch) {
	        $id = implode(",", $batch);
			$query = "ids=".$id."&format=json";
            if ($apiKey) {
                $query .= "&api_key=".urlencode($apiKey);
            }
			$url = "https://www.ncbi.nlm.nih.gov/pmc/utils/idconv/v1.0/?".$query;
			list($resp, $output) = REDCapManagement::downloadURL($url, $pid);

            if ($apiKey) {
                Publications::throttleDown(self::API_KEY_PUBMED_THROTTLE);
            } else {
                Publications::throttleDown(self::DEFAULT_PUBMED_THROTTLE);
            }

			$results = json_decode($output, true);
			if ($results) {
			    foreach ($results['records'] as $record) {
			        if (isset($record['pmcid']) && isset($record['pmid'])) {
                        $translator[$record['pmcid']] = ["pmid" => $record['pmid']];
                        $translator[$record['pmid']] = ["pmcid" => $record['pmcid']];
                        if (isset($record['versions'])) {
                            foreach ($record['versions'] as $version) {
                                if (isset($version['mid'])) {
                                    $translator[$record['pmcid']]['nihms'] = $version['mid'];
                                    $translator[$record['pmid']]['nihms'] = $version['mid'];
                                }
                            }
                        }
                    }
                }
            }
		}
        return $translator;
	}

	public function getNumberOfCitationsByOthers($type = "Original Included") {
		$citations = $this->getCitations($type);
		$citedByOthers = array();
		foreach ($citations as $citation) {
			$numCits = $citation->getVariable("num_citations");
			if ($numCits) {
				array_push($citedByOthers, $numCits);
			}
		}
		return array_sum($citedByOthers);
	}

	public function getAverageRCR($type = "Original Included") {
		$citations = $this->getCitations($type);
		$rcrs = array();
		foreach ($citations as $citation) {
			$rcr = $citation->getVariable("rcr");
			if ($rcr) {
				array_push($rcrs, $rcr);
			}
		}
		if (count($rcrs) > 0) {
		    $accuracy = 1000;

			return round(array_sum($rcrs) / count($rcrs) * $accuracy) / $accuracy;
		}
		return "N/A";
	}

	public static function getPubTimestamp($citation, $recordId) {
		if (!$citation) {
			return 0;
		}
		if (get_class($citation) == "Citation") {
			$cit = $citation;
		} else {
			return Citation::getTimestampFromText($citation, $recordId);
		}
		return $cit->getTimestamp();
	}

	# if endTs not specified, will go into future
	public function getPubsInRange($beginTs, $endTs = FALSE, $type = "Included") {
		$citations = $this->getCitations($type);
		$pubsInRange = array();
		foreach ($citations as $citation) {
			$currTs = self::getPubTimestamp($citation, $this->getRecordId());
			if ($beginTs <= $currTs) {
				if (($endTs === FALSE) || ($endTs >= $currTs)) {
					$pubsInRange[] = $citation->getCitation();
				}
			}
		}
		return $pubsInRange;
	}

	public function getCitationCollection($type = "Included") {
        if (self::areFlagsOn($this->pid)) {
            if (($type == "Included") || ($type == "Final")) {
                return $this->flaggedCitations;
            } else if (($type == "Omitted") || ($type == "Omit")) {
                return $this->unflaggedCitations;
            }
        }
		if (($type == "Included") || ($type == "Final")) {
            return $this->goodCitations;
        } else if ($type == "Flagged") {
            return $this->flaggedCitations;
        } else if ($type == "Unflagged") {
            return $this->unflaggedCitations;
		} else if (($type == "Not done") || ($type == "Not Done") || ($type == "New")) {
			return $this->input;
		} else if (($type == "Omitted") || ($type == "Omit")) {
			return $this->omissions;
		}
		return NULL;
	}

	public function getSortedCitationsInTimespan($startTs, $endTs = FALSE, $type = "Included", $asc = TRUE) {
	    $citations = $this->getSortedCitations($type, $asc);
	    $includedCits = [];
	    $dates = [];
	    foreach ($citations as $citation) {
	        $ts = $citation->getTimestamp();
	        $dates[] = date("Y-m-d", $ts);
	        if (($ts >= $startTs) && (($ts <= $endTs) || !$endTs)) {
	            $includedCits[] = $citation;
            }
        }
	    return $includedCits;
    }

	public function getSortedCitations($type = "Included", $asc = TRUE) {
	    $citations = $this->getCitations($type);
	    $keyedByTimestamp = array();
	    foreach ($citations as $citation) {
	        $ts = $citation->getTimestamp();
	        while (isset($keyedByTimestamp[$ts])) {
	            $ts++;
            }
            $keyedByTimestamp[$ts] = $citation;
        }
	    if ($asc) {
            ksort($keyedByTimestamp);
        } else {
	        krsort($keyedByTimestamp);
        }
	    return array_values($keyedByTimestamp);
    }

    public static function updateNewPMCs($token, $server, $pid, $recordId, $redcapData) {
	    $pmidsWithoutPMC = [];
	    foreach ($redcapData as $row) {
	        if (
	            ($row['record_id'] == $recordId)
                && ($row['redcap_repeat_instrument'] == "citation")
                && $row['citation_pmid']
                && ($row['citation_pmcid'] === "")
            ) {
	            $pmidsWithoutPMC[$row['redcap_repeat_instance']] = $row['citation_pmid'];
            }
        }
	    if (!empty($pmidsWithoutPMC)) {
	        $pmcids = self::PMIDsToPMCs(array_values($pmidsWithoutPMC), $pid);
	        $i = 0;
	        $upload = [];
	        foreach ($pmidsWithoutPMC as $instance => $pmid) {
	            $pmcid = $pmcids[$i] ?? "";
	            if ($pmcid) {
                    $upload[] = [
                        "record_id" => $recordId,
                        "redcap_repeat_instrument" => "citation",
                        "redcap_repeat_instance" => $instance,
                        "citation_pmcid" => $pmcid,
                    ];
                }
	            $i++;
            }
	        if (!empty($upload)) {
	            Application::log("Uploading ".count($upload)." rows of new PMCIDs", $pid);
	            Upload::rows($upload, $token, $server);
            }
        }
    }

	# returns array of class Citation
	public function getCitations($type = "Included") {
        if ($type == "Flagged") {
            return $this->flaggedCitations->getCitations();
        } else if ($type == "Unflagged") {
            return $this->unflaggedCitations->getCitations();
        } else if ($type == "Included") {
            return $this->goodCitations->getCitations();
        } else if (in_array($type, ["PubMed", "pubmed"])) {
            $allCitations = $this->getCitations("Included");
            $pubmedCitations = [];
            foreach ($allCitations as $cit) {
                if ($cit->getVariable("data_source") == "citation") {
                    $pubmedCitations[] = $cit;
                }
            }
            return $pubmedCitations;
		} else if (($type == "Original Included") || ($type == "Included Original")) {
			$cits = $this->goodCitations->getCitations();
			$filtered = array();
			foreach ($cits as $cit) {
				if ($cit->getCategory() == "Original Research") {
					array_push($filtered, $cit);
				}
			}
			return $filtered;
		} else if (($type == "Not done") || ($type == "Not Done")) {
			return $this->input->getCitations();
		} else if ($type == "Omitted") {
			return $this->omissions->getCitations();
		} else if ($type == "All") {
			return array_merge($this->goodCitations->getCitations(), $this->input->getCitations());
		}
		return array();
	}

	private static function mergeCitationsWithoutDuplicates($aryOfCitations) {
		$combined = array();
		$pmidsDone = array();
		foreach ($aryOfCitations as $citations) {
			foreach ($citations as $citation) {
				if (preg_match("/PMID:\s*\d+/", $citation, $matches)) {
					$pmid = preg_replace("/^PMID:\s*/", "", $matches[0]);
					if (!in_array($pmid, $pmidsDone)) {
						array_push($pmidsDone, $pmid);
						array_push($combined, $citation);
					}
				} else {
					array_push($combined, $citation);
				}
			}
		}
		return $combined;
	}

	public function getCitationIds($type = "Included") {
		if ($type == "Included") {
			return $this->goodCitations->getIds();
		} else if (($type == "Not done") || ($type == "Not Done")) {
			return $this->input->getIds();
		} else if ($type == "Omitted") {
			return $this->omissions->getIds();
		}
		return array();
	}

	private function process() {
		if ($this->rows) {
			foreach ($this->rows as $row) {
				if ($row['record_id']) {
					$this->recordId = $row['record_id'];
					break;
				}
			}
		}
	}

	private function setupTests() {
		$records = Download::recordIds($this->token, $this->server);
		$n = rand(0, count($records) - 1);
		$record = $records[$n];

		$redcapData = Download::records($this->token, $this->server, array($record));
		$this->setRows($redcapData);
	}

	private $rows;
	private $input;
	private $name;
	private $token;
    private $metadata;
	private $server;
	private $recordId;
    private $goodCitations;
    private $flaggedCitations;
    private $unflaggedCitations;
	private $omissions;
	private $pid;
    private $names;
    private $lastNames;
    private $firstNames;
    private $lastName;
    private $wranglerType;
}

class PubmedMatch {
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
		$ary = array();
		$skip = array("PMID", "Category", "Score");
		foreach ($this->toArray() as $var => $val) {
			if (!in_array($var, $skip)) {
				$ary[$var] = $val;
			}
		}
		return empty($ary);
	}

	private function toArray() {
		$ary = array();

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
		return array("", 0);
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
					return array("research", 100);
				} else if ($numResearchMatches >= 2) {
					return array("research", 70);
				} else {
					# only one match for research
					return array("research", 20 + self::scoreAbstractForMinorPhrases($abstract));
				}
			} else if ($isErrata) {
				return array("errata", 100);
			} else if ($isLetter) {
				return array("letter-to-the-editor", 100);
			}
		} else if ($isResearch + $isErrata + $isLetter > 1) {
			# in more than one category
			if ($isResearch) {
				if ($numResearchMatches >= 4) {
					return array("research", 90);
				} else if ($numResearchMatches >= 3) {
					return array("research", 80);
				} else if ($numResearchMatches >= 2) {
					return array("research", 50);
				} else {
					# only one match for research and in another category
					return array("research", 10 + self::scoreAbstractForMinorPhrases($abstract));
				}
			} else {
				# both errata and letter => cannot classify
				return array("", 0);
			}
		}

		# abstract yields no insights
		return array("", 0);
	}

	private static function scoreAbstractForMinorPhrases($abstract) {
		$phrases = array( "/compared/i", "/studied/i", "/examined/i" );
		$numMatches = 0;
		$scorePerMatch = 10;

		foreach ($phrases as $regex) {
			if (preg_match($regex, $abstract)) {
				$numMatches++;
			}
		}
		return $scorePerMatch * $numMatches;
	}

	private $ary = array();
	private $pmid = "";
}
