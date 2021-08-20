<?php

namespace Vanderbilt\CareerDevLibrary;


# This class handles publication data from PubMed, the VICTR fetch routine, and surveys.
# It also provides HTML for data-wrangling the publication data

require_once(__DIR__ . '/ClassLoader.php');

class Publications {
	public function __construct($token, $server, $metadata = array()) {
		$this->token = $token;
		$this->server = $server;
		if (empty($metadata)) {
			$metadata = Download::metadata($token, $server);
		}
		$this->metadata = $metadata;
		$this->choices = Scholar::getChoices($metadata);
		$this->pid = Application::getPID($token);
        $this->names = Download::names($token, $server);
        $this->lastNames = Download::lastnames($token, $server);
        $this->firstNames = Download::firstnames($token, $server);
	}

	public function deduplicateCitations($recordId) {
	    $pmids = [];
	    $duplicateInstances = [];
	    foreach ($this->rows as $row) {
	        $pmid = $row['citation_pmid'];
	        if (!in_array($pmid, $pmids)) {
	            $pmids[] = $pmid;
            } else {
	            $duplicateInstances[] = $row['redcap_repeat_instance'];
            }
        }
	    if (!empty($duplicateInstances)) {
            Upload::deleteFormInstances($this->token, $this->server, $this->pid, "citation_", $recordId, $duplicateInstances);
        }
    }

    public static function filterExcludeList($rows, $recordExcludeList) {
	    if (empty($recordExcludeList)) {
	        return $rows;
        }
	    $newRows = [];
	    foreach ($rows as $row) {
	        $excludeThisRow = FALSE;
	        if ($row['citation_authors']) {
                $authors = preg_split("/\s*[,;]\s*/", $row['citation_authors']);
                foreach ($authors as $author) {
                    $author = trim($author);
                    foreach ($recordExcludeList as $excludeName) {
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
	        if (!$excludeThisRow) {
	            $newRows[] = $row;
            }
        }
	    return $newRows;
    }

    public static function searchPubMedForName($first, $last, $pid, $institution = "") {
        $first = preg_replace("/\s+/", "+", $first);
        $last = preg_replace("/\s+/", "+", $last);
        $institution = preg_replace("/\s+/", "+", $institution);
        $term = $first."+".$last."%5Bau%5D";
        if ($institution) {
            $term .= "+AND+" . strtolower($institution) . "%5Bad%5D";
        }
        $pmids = self::queryPubMed($term, $pid);

        $maximumThreshold = 2000;
        if (count($pmids) > $maximumThreshold) {
            $adminEmail = Application::getSetting("admin_email", $pid);
            $defaultFrom = Application::getSetting("default_from", $pid);
            $mssg = "Searching PubMed for $first $last returned ".count($pmids)." PMIDs in pid $pid! This is likely an error as it is above the threshold of $maximumThreshold. Data not uploaded.";
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

    public static function queryPubMed($term, $pid) {
        $url = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&retmax=100000&retmode=json&term=".$term;
        Application::log($url);
        list($resp, $output) = REDCapManagement::downloadURL($url, $pid);
        Application::log("$resp: Downloaded ".strlen($output)." bytes");

        $pmids = array();
        $pmData = json_decode($output, true);
        if ($pmData['esearchresult'] && $pmData['esearchresult']['idlist']) {
            # if the errorlist is not blank, it might search for simplified
            # it might search for simplified names and produce bad results
            $pmidCount = count($pmData['esearchresult']['idlist']);
            if (!isset($pmData['esearchresult']['errorlist'])
                || !$pmData['esearchresult']['errorlist']
                || !$pmData['esearchresult']['errorlist']['phrasesnotfound']
                || empty($pmData['esearchresult']['errorlist']['phrasesnotfound'])) {
                foreach ($pmData['esearchresult']['idlist'] as $pmid) {
                    array_push($pmids, $pmid);
                }
            }
        }
        Publications::throttleDown();
        Application::log("$url returned PMIDs: ".REDCapManagement::json_encode_with_spaces($pmids));
        return $pmids;
    }

    public function getAltmetricRange($type = "included") {
	    $scores = [];
	    foreach ($this->getCitations($type) as $citation) {
	        $scores[] = $citation->getVariable("altmetric_score");
        }
	    if (!empty($scores)) {
            $maxRoundedUp = ceil(max($scores));
            $minRoundedDown = floor(min($scores));
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

	public function getNumberFirstAuthors($startTs = NULL, $endTs = NULL, $asFraction = TRUE) {
        $type = "Included";
        if ($startTs) {
            if ($endTs) {
                $citations = $this->getSortedCitationsInTimespan($startTs, $endTs, $type);
            }  else {
                $citations = $this->getSortedCitationsInTimespan($startTs, FALSE, $type);
            }
        } else {
            $citations = $this->getCitations($type);
        }
        return self::getNumberAuthorsHelper("first", $citations, $this->getName(), $asFraction);
    }

    private static function getNumberAuthorsHelper($pos, $citations, $name, $asFraction = TRUE) {
        if ($pos == "first") {
            $method = "isFirstAuthor";
        } else if ($pos == "last") {
            $method = "isLastAuthor";
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
        $type = "Included";
        if ($startTs) {
            if ($endTs) {
                $citations = $this->getSortedCitationsInTimespan($startTs, $endTs, $type);
            }  else {
                $citations = $this->getSortedCitationsInTimespan($startTs, FALSE, $type);
            }
        } else {
            $citations = $this->getCitations($type);
        }
        return self::getNumberAuthorsHelper("last", $citations, $this->getName(), $asFraction);
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
		if ($filterOutCopiedRecords && method_exists("Application", "filterOutCopiedRecords")) {
			$records = Application::filterOutCopiedRecords($records);
		}
		$names = Download::names($token, $server);
		$page = basename($_SERVER['PHP_SELF']);

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
		$this->input = new CitationCollection($this->recordId, $this->token, $this->server, "New", $this->rows, $this->metadata, $this->lastNames, $this->firstNames);
		$this->omissions = new CitationCollection($this->recordId, $this->token, $this->server, "Omit", $this->rows, $this->metadata, $this->lastNames, $this->firstNames);
		foreach ($this->omissions->getCitations() as $citation) {
			$pmid = $citation->getPMID();
			if ($this->input->has($pmid)) {
				$this->omissions->removePMID($pmid);
			}
			if ($this->goodCitations->has($pmid)) {
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
                $almetricFields = [
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
                    "citation_altmetric_last_update" => date("Y-m-d"),
                ];
                foreach ($almetricFields as $redcapField => $variable) {
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
				if (!in_array($currItems, $currItem)) {
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

	public static function pullFromEFetch($pmids) {
		if (!is_array($pmids)) {
			$pmids = array($pmids);
		}
		$limit = self::getPMIDLimit();
		if (count($pmids) > $limit) {
			throw new \Exception("Cannot pull more than $limit PMIDs at once!");
		}
		$url = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pubmed&retmode=xml&id=".implode(",", $pmids);
		Publications::throttleDown();
		list($resp, $output) = REDCapManagement::downloadURL($url);
		return $output;
	}

    public static function downloadPMID($pmid) {
	    $ary = self::downloadPMIDs([$pmid]);
	    if (count($ary) > 0) {
            return $ary[0];
        }
	    return NULL;
    }

	public static function downloadPMIDs($pmids) {
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
            $output = self::pullFromEFetch($pmidGroup);
            $xml = simplexml_load_string(utf8_encode($output));
            $numRetries = 5;
            $i = 0;
            while (!$xml && ($numRetries > $i)) {
                sleep(5);
                $output = self::pullFromEFetch($pmidGroup);
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
                                    array_push($pubTypes, $pubType);
                                }
                            }

                            if ($medlineCitation->MedlineCitation->KeywordList) {
                                foreach ($medlineCitation->MedlineCitation->KeywordList->Keyword as $keyword) {
                                    array_push($keywords, $keyword);
                                }
                            }

                            if ($medlineCitation->MedlineCitation->MeshHeadingList) {
                                foreach ($medlineCitation->MedlineCitation->MeshHeadingList->children() as $node) {
                                    if ($node->DescriptorName) {
                                        array_push($meshTerms, $node->DescriptorName);
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

	public static function deleteMismatchedRows($token, $server, $pid, $recordId, $allFirstNames, $allLastNames) {
        # download citation_authors
	    $redcapData = Download::fieldsForRecords($token, $server, ["record_id", "citation_authors"], [$recordId]);

	    # find items that don't match current record AND match some other record
        $currFirstName = $allFirstNames[$recordId];
        $currLastName = $allLastNames[$recordId];
        $instances = [];
        foreach ($redcapData as $row) {
            if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "citation")) {
                $instance = $row['redcap_repeat_instance'];
                $authors = preg_split("/\s*[,;]\s*/", $row['citation_authors']);
                for ($i = 0; $i < count($authors); $i++) {
                    $author = trim($authors[$i]);
                    list($first, $last) = NameMatcher::splitName($author, 2);
                    $authors[$i] = ["first" => $first, "last" => $last];
                }
                $foundCurrInAuthorList = FALSE;
                $foundAnotherInAuthorList = FALSE;
                foreach ($authors as $author) {
                    if (NameMatcher::matchByInitials($currLastName, $currFirstName, $author['last'], $author['first'])) {
                        $foundCurrInAuthorList = TRUE;
                        Application::log("Found current $currFirstName $currLastName {$author['last']}, {$author['first']} in author list in $recordId:$instance", $pid);
                        break;
                    }
                }
                if (!$foundCurrInAuthorList) {
                    Application::log("Did not find current $currFirstName $currLastName in author list in $recordId:$instance", $pid);
                    foreach ($authors as $author) {
                        foreach ($allLastNames as $recordId2 => $otherLastName) {
                            $otherFirstName = $allFirstNames[$recordId2];
                            if (NameMatcher::matchByInitials($author['last'], $author['first'], $otherLastName, $otherFirstName)) {
                                Application::log("Found other $otherFirstName $otherLastName {$author['last']}, {$author['first']} in author list in $recordId:$instance", $pid);
                                $foundAnotherInAuthorList = TRUE;
                                break;
                            }
                        }
                    }
                }
                if ($foundAnotherInAuthorList) {
                    $instances[] = $instance;
                } else {
                    Application::log("Did not find other in author list in $recordId:$instance", $pid);
                }
            }
        }
        if (!empty($instances)) {
            Application::log("Deleting instances ".json_encode($instances)." for citations for Record $recordId", $pid);
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

    private static function xml2REDCap($xml, $recordId, &$instance, $src, $confirmedPMIDs, $metadataFields) {
        $hasAbstract = in_array("citation_abstract", $metadataFields);
        $pmidsPulled = [];
        $upload = [];
        foreach ($xml->PubmedArticle as $medlineCitation) {
            $article = $medlineCitation->MedlineCitation->Article;
            $abstract = "";
            if ($article->Abstract && $article->Abstract->AbstractText) {
                $abstract = strval($article->Abstract->AbstractText);
            }
            $authors = [];
            if ($article->AuthorList->Author) {
                foreach ($article->AuthorList->Author as $authorXML) {
                    $author = $authorXML->LastName . " " . $authorXML->Initials;
                    if ($author != " ") {
                        $authors[] = $author;
                    } else {
                        $authors[] = strval($authorXML->CollectiveName);
                    }
                }
            }
            $title = strval($article->ArticleTitle);
            $title = preg_replace("/\.$/", "", $title);

            $pubTypes = array();
            if ($article->PublicationTypeList) {
                foreach ($article->PublicationTypeList->PublicationType as $pubType) {
                    array_push($pubTypes, strval($pubType));
                }
            }

            $assocGrants = array();
            if ($article->GrantList) {
                foreach ($article->GrantList->Grant as $grant) {
                    array_push($assocGrants, strval($grant->GrantID));
                }
            }

            $meshTerms = array();
            if ($medlineCitation->MedlineCitation->MeshHeadingList) {
                foreach ($medlineCitation->MedlineCitation->MeshHeadingList->MeshHeading as $mesh) {
                    array_push($meshTerms, strval($mesh->DescriptorName));
                }
            }

            $journal = strval($article->Journal->ISOAbbreviation);
            $journal = preg_replace("/\.$/", "", $journal);

            $issue = $article->Journal->JournalIssue;    // not a strval but node!!!
            $year = "";
            $month = "";
            $day = "";

            $date = $issue->PubDate->Year . " " . $issue->PubDate->Month;
            if ($issue->PubDate->Year) {
                $year = strval($issue->PubDate->Year);
            }
            if ($issue->PubDate->Month) {
                $month = strval($issue->PubDate->Month);
            }
            if ($issue->PubDate->Day) {
                $day = "{$issue->PubDate->Day}";
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

            $row = [
                "record_id" => "$recordId",
                "redcap_repeat_instrument" => "citation",
                "redcap_repeat_instance" => "$instance",
                "citation_pmid" => $pmid,
                "citation_include" => "",
                "citation_source" => $src,
                "citation_authors" => implode(", ", $authors),
                "citation_title" => $title,
                "citation_pub_types" => implode("; ", $pubTypes),
                "citation_mesh_terms" => implode("; ", $meshTerms),
                "citation_journal" => $journal,
                "citation_issue" => $iss,
                "citation_volume" => $vol,
                "citation_year" => $year,
                "citation_month" => $month,
                "citation_day" => $day,
                "citation_pages" => $pages,
                "citation_grants" => implode("; ", $assocGrants),
                "citation_complete" => "2",
            ];
            if ($hasAbstract) {
                $row['citation_abstract'] = $abstract;
            }

            if (in_array($pmid, $confirmedPMIDs)) {
                $row['citation_include'] = '1';
            }
            $row = REDCapManagement::filterForREDCap($row, $metadataFields);
            array_push($upload, $row);
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
                            list($authorFirst, $authorLast) = NameMatcher::splitName($author, 2);
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

	public static function getCitationsFromPubMed($pmids, $metadata, $src = "", $recordId = 0, $startInstance = 1, $confirmedPMIDs = array(), $pid = NULL) {
        $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
		$upload = [];
		$instance = $startInstance;
		$pullSize = self::getPMIDLimit();
		for ($i0 = 0; $i0 < count($pmids); $i0 += $pullSize) {
			$pmidsToPull = [];
			for ($j = $i0; ($j < count($pmids)) && ($j < $i0 + $pullSize); $j++) {
				$pmidsToPull[] = $pmids[$j];
			}
			$output = self::pullFromEFetch($pmidsToPull);
            $xml = simplexml_load_string(utf8_encode($output));
			$tries = 0;
			$maxTries = 10;
			$numSecs = 300; // five minutes? 60;
			while (!$xml && ($tries < $maxTries)) {
				$tries++;
				Publications::throttleDown($numSecs);
				$output = self::pullFromEFetch($pmidsToPull);
                $xml = simplexml_load_string(utf8_encode($output));
			}
			if (!$xml) {
			    if ($tries >= $maxTries) {
                    Application::log("Warning: Cannot pull from eFetch! Attempted $tries times. " . implode(", ", $pmidsToPull) . " " . $output);
                }
			} else {
                list($parsedRows, $pmidsPulled) = self::xml2REDCap($xml, $recordId, $instance, $src, $confirmedPMIDs, $metadataFields);
                $upload = array_merge($upload, $parsedRows);
                $translateFromPMIDToPMC = self::PMIDsToPMCs($pmidsPulled, $pid);
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
                if (!$recordId) {
                    throw new \Exception("Please specify a record id!");
                } else if (empty($pmidsPulled)) {     // $pmidsPulled is empty
                    Application::log("ERROR: No PMIDs pulled from ".json_encode($pmidsToPull), $pid);
                }
            }
            Publications::throttleDown();
		}
		self::addTimesCited($upload, $pid, $pmids, $metadataFields);
		Application::log("$recordId: Returning ".count($upload)." lines from getCitationsFromPubMed", $pid);
		return $upload;
	}

	private function updateAssocGrantsAndBibliometrics(&$upload, $pmids) {
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
                        foreach ($fieldsToCopy as $field) {
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
            if ($row['citation_doi']) {
                $altmetricRow = self::getAltmetricRow($row['citation_doi'], $metadataFields, $this->pid);
                $upload[$i] = array_merge($upload[$i], $altmetricRow);
            }
            $i++;
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
		sleep($secs);
	}

	private function leftColumnText() {
		$html = "";
		$notDone = $this->getCitationCollection("Not Done");
		$notDoneCount = $notDone->getCount();
		$html .= "<h4 class='newHeader'>";
		if ($notDoneCount == 0) {
			$html .= "No New Citations";
			$html .= "</h4>\n";
			$html .= "<div id='newCitations'>\n";
		} else {
			if ($notDoneCount == 1) {
				$html .= $notDoneCount." New Citation";
			} else if ($notDoneCount == 0) {
				$html .= "No New Citations";
			} else {
				$html .= $notDoneCount." New Citations";
			}
			$html .= "</h4>\n";
			$html .= "<div id='newCitations'>\n";
			if ($notDoneCount > 1) {
                $html .= "<p class='centered'><a href='javascript:;' onclick='selectAllCitations(\"#newCitations\");'>Select All New Citations</a> | <a href='javascript:;' onclick='unselectAllCitations(\"#newCitations\");'>Deselect All New Citations</a></p>";
            }
			$html .= $notDone->toHTML("notDone");
		}
		$html .= "</div>\n";
		$html .= "<hr>\n";

		$included = $this->getCitationCollection("Included");
		$html .= "<h4>Existing Citations</h4>\n";
		$html .= "<div id='finalCitations'>\n";
		$html .= $included->toHTML("included");
		$html .= "</div>\n";
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
	public function getEditText() {
        $notDone = $this->getCitationCollection("Not Done");
        $notDoneCount = $notDone->getCount();
        $included = $this->getCitationCollection("Included");
        $includedCount = $included->getCount();
        $wrangler = new Wrangler("Publications");
		$html = $wrangler->getEditText($notDoneCount, $includedCount, $this->recordId, $this->name, $this->lastName);

		$html .= self::manualLookup();
		$html .= "<table style='width: 100%;' id='main'><tr>\n";
		$html .= "<td class='twoColumn yellow' id='left'>".$this->leftColumnText()."</td>\n";
		$html .= "<td id='right'>".$wrangler->rightColumnText()."</td>\n";
		$html .= "</tr></table>\n";

		return $html;
	}

	private function manualLookup() {
		$html = "";
		$html .= "<table id='lookupTable' style='margin-left: auto; margin-right: auto; border-radius: 10px;' class='bin'><tr>\n";
		$html .= "<td style='width: 250px; height: 200px; text-align: left; vertical-align: top;'>\n";
		$html .= "<h4 style='margin-bottom: 0px;'>Lookup PMID</h4>\n";
        $html .= "<p class='oneAtATime'><input type='text' id='pmid'> <button onclick='submitPMID($(\"#pmid\").val(), \"#manualCitation\", \"\"); return false;' class='biggerButton' readonly>Go!</button><br><a class='smaller' href='javascript:;' onclick='$(\".list\").show(); $(\".oneAtATime\").hide(); checkSubmitButton(\"#manualCitation\", \".list\");'>Switch to Bulk</a></p>\n";
        $html .= "<p class='list' style='display: none;'><textarea id='pmidList'></textarea> <button onclick='submitPMIDs($(\"#pmidList\").val(), \"#manualCitation\", \"\"); return false;' class='biggerButton' readonly>Go!</button><br><a class='smaller' href='javascript:;' onclick='$(\".list\").hide(); $(\".oneAtATime\").show(); checkSubmitButton(\"#manualCitation\", \".oneAtATime\");'>Switch to Single</a></p>\n";
		$html .= "<h4 style='margin-bottom: 0px;'>Lookup PMC</h4>\n";
        $html .= "<p class='oneAtATime'><input type='text' id='pmc'> <button onclick='submitPMC($(\"#pmc\").val(), \"#manualCitation\", \"\"); return false;' class='biggerButton'>Go!</button><br><a class='smaller' href='javascript:;' onclick='$(\".list\").show(); $(\".oneAtATime\").hide(); checkSubmitButton(\"#manualCitation\", \".list\");'>Switch to Bulk</a></p>\n";
        $html .= "<p class='list' style='display: none;'><textarea id='pmcList'></textarea> <button onclick='submitPMCs($(\"#pmcList\").val(), \"#manualCitation\", \"\"); return false;' class='biggerButton'>Go!</button><br><a class='smaller' href='javascript:;' onclick='$(\".list\").hide(); $(\".oneAtATime\").show(); checkSubmitButton(\"#manualCitation\", \".oneAtATime\");'>Switch to Single</a></p>\n";
		$html .= "</td><td style='width: 500px;'>\n";
		$html .= "<div id='lookupResult'>\n";
		$html .= "<p><textarea style='width: 100%; height: 150px; font-size: 16px;' id='manualCitation'></textarea></p>\n";
        $html .= "<p class='oneAtATime'><button class='biggerButton green includeButton' style='display: none;' onclick='includeCitation($(\"#manualCitation\").val()); return false;'>Include This Citation</button></p>\n";
        $html .= "<p class='list' style='display: none;'><button class='biggerButton green includeButton' style='display: none;' onclick='includeCitations($(\"#manualCitation\").val()); return false;'>Include These Citations</button></p>\n";
		$html .= "</div>\n";
		$html .= "</td>\n";
		$html .= "</tr></table>\n";
		return $html;
	}

	public static function getCurrentPMC($citation) {
		if (preg_match("/PMC\d+/", $citation, $matches)) {
			$match = preg_replace("/PMC/", "", $matches[0]);
			return $match;
		}
		return "";
	}

	public static function getCurrentPMID($citation) {
		if (preg_match("/PMID:\s*\d+/", $citation, $matches)) {
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
                $pmcid = $translator[$pmid];
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
            return self::runIDConverter($pmids, $pid);
        }
        return [];
    }

	public static function PMIDToPMC($pmid, $pid) {
	    if ($pmid) {
            $pmcs = self::PMIDsToPMCs([$pmid], $pid);
            if (count($pmcs) > 0) {
                return $pmcs[0];
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
                return self::runIDConverter($pmcids, $pid);
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

        $translator = [];
	    foreach ($idsBatched as $batch) {
	        $id = implode(",", $batch);
			$query = "ids=".$id."&format=json";
			$url = "https://www.ncbi.nlm.nih.gov/pmc/utils/idconv/v1.0/?".$query;
			list($resp, $output) = REDCapManagement::downloadURL($url, $pid);

			Publications::throttleDown();

			$results = json_decode($output, true);
			if ($results) {
			    foreach ($results['records'] as $record) {
			        if ($record['pmcid'] && $record['pmid']) {
                        $translator[$record['pmcid']] = $record['pmid'];
                        $translator[$record['pmid']] = $record['pmcid'];
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
			$cit = Citation::createCitationFromText($citation, $recordId);
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
					array_push($pubsInRange, $citation->getCitation());
				}
			}
		}
		return $pubsInRange;
	}

	public function getCitationCollection($type = "Included") {
		if (($type == "Included") || ($type == "Final")) {
			return $this->goodCitations;
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

	# returns array of class Citation
	public function getCitations($type = "Included") {
		if ($type == "Included") {
			return $this->goodCitations->getCitations();
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
			$this->hasChanged = FALSE;
		}
	}

	private function setupTests() {
		$records = Download::recordIds($this->token, $this->server);
		$n = rand(0, count($records) - 1);
		$record = $records[$n];

		$redcapData = Download::records($this->token, $this->server, array($record));
		$this->setRows($redcapData);
	}

	# unit test: getEditText
	public function getEditText_test($tester) {
		$html = $this->getEditText();
		$tester->assertMatch("/<form/", $html);
	}

	# unit test: default variables
	public function defaultVariables_test($tester) {
		$this->setupTests();
		$this->process();

		$tester->assertNotBlank($this->name);
		$tester->assertTrue(!$this->hasChanged);
	}

	# unit test: get number of citations for a random record
	public function process_test($tester) {
		$this->setupTests();
		$this->process();
	}

	private $rows;
	private $input;
	private $name;
	private $token;
    private $metadata;
	private $server;
	private $recordId;
	private $goodCitations;
	private $omissions;
	private $choices;
	private $pid;
    private $names;
    private $lastNames;
    private $lastName;
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
