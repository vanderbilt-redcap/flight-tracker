<?php

namespace Vanderbilt\CareerDevLibrary;


# This class handles publication data from PubMed, the VICTR fetch routine, and surveys.
# It also provides HTML for data-wrangling the publication data

require_once(dirname(__FILE__)."/Upload.php");
require_once(dirname(__FILE__)."/Download.php");
require_once(dirname(__FILE__)."/Links.php");
require_once(dirname(__FILE__)."/iCite.php");
require_once(dirname(__FILE__)."/Citation.php");
require_once(dirname(__FILE__)."/Scholar.php");

class Publications {
	public function __construct($token, $server, $metadata = array()) {
		$this->token = $token;
		$this->server = $server;
		if (empty($metadata)) {
			$metadata = Download::metadata($token, $server);
		}
		$this->choices = Scholar::getChoices($metadata);
	}

	public static function getSearch() {
		return "Last/Full Name:<br><input id='search' type='text' style='width: 100%;'><br><div style='width: 100%; color: red;' id='searchDiv'></div>";
	}

	public static function getSelectRecord() {
		global $token, $server;

		$names = Download::names($token, $server);
		$page = basename($_SERVER['PHP_SELF']);

		$html = "Record: <select style='width: 100%;' id='refreshRecord' onchange='refreshForRecord(\"$page\");'><option value=''>---SELECT---</option>";
		foreach ($names as $record => $name) {
			$html .= "<option value='$record'>$record: $name</option>";
		}
		$html .= "</select>";
		return $html;
	}

	# input: All REDCap data rows associated with a recordId
	# calls private helper method process
	public function setRows($rows) {
		$this->rows = $rows;

		$this->name = "";
		$this->recordId = 0;
		foreach ($rows as $row) {
			if ($row['record_id']) {
				$this->recordId = $row['record_id'];
			}
			if ($row['redcap_repeat_instrument'] == "") {
				if ($row['identifier_first_name'] && $row['identifier_last_name']) {
					$this->name = $row['identifier_first_name']." ".$row['identifier_last_name'];
				} else if ($row['identifier_last_name']) {
					$this->name = $row['identifier_last_name'];
				} else if ($row['identifier_first_name']) {
					$this->name = $row['identifier_first_name'];
				}
			}
		}

		$this->process();
		$this->goodCitations = new CitationCollection($this->recordId, $this->token, $this->server, "Final", $this->rows, $this->choices);
		$this->omissions = new CitationCollection($this->recordId, $this->token, $this->server, "Omit", $this->rows, $this->choices);
		$this->input = new CitationCollection($this->recordId, $this->token, $this->server, "New", $this->rows, $this->choices);
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
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		$output = curl_exec($ch);
		curl_close($ch);
		return $output;
	}

	public static function downloadPMID($pmid) {
		$output = self::pullFromEFetch($pmid);
		$xml = simplexml_load_string(utf8_encode($output));
		if (!$xml) {
			throw new Exception("Error: Cannot create object ".$output);
		}

		$pubTypes = array();
		$keywords = array();
		$abstract = "";
		$meshTerms = array();
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

		return $pubmedMatch;
	}

	public function uploadSummary() {
		$recordId = $this->recordId;
		if ($recordId) {
			$row = array(
					"record_id" => $recordId,
					"redcap_repeat_instrument" => "",
					"redcap_repeat_instance" => "",
					"summary_publication_count" => $this->getCount("Original Included"),
					);
			return Upload::oneRow($row, $this->token, $this->server);
		}
		return array();
	}

	public static function getCitationsFromPubMed($pmids, $src = "", $recordId = 0, $startInstance = 1) {
		$citations = array();
		$upload = array();
		$instance = $startInstance;
		$pullSize = self::getPMIDLimit();
		for ($i = 0; $i < count($pmids); $i += $pullSize) {
			$pmidsToPull = array();
			for ($j = $i; ($j < count($pmids)) && ($j < $i + $pullSize); $j++) {
				array_push($pmidsToPull, $pmids[$j]);
			}
			$output = self::pullFromEFetch($pmidsToPull);
			$mssg = json_decode($output, true);
			$tries = 1;
			$maxTries = 10;
			$numSecs = 60;
			while ($mssg && $mssg['error'] && ($tries < $maxTries)) {
				Publications::throttleDown($numSecs);
				$output = self::pullFromEFetch($pmidsToPull);
				$mssg = json_decode($output, true);
				$tries++;
			}
			if ($tries >= $maxTries) {
				throw new \Exception("Cannot pull from eFetch! Attempted $tries times. ".$output);
			}

			$xml = simplexml_load_string(utf8_encode($output));
			if (!$xml) {
				throw new \Exception("Error: Cannot create object ".$output);
			}
			foreach ($xml->PubmedArticle as $medlineCitation) {
				$article = $medlineCitation->MedlineCitation->Article;
				$authors = array();
				if ($article->AuthorList->Author) {
					foreach ($article->AuthorList->Author as $authorXML) {
						$author = $authorXML->LastName." ".$authorXML->Initials;
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

				$issue = $article->Journal->JournalIssue;	// not a strval but node!!!
				$year = "";
				$month = "";
				$day = "";

				$date = $issue->PubDate->Year." ".$issue->PubDate->Month;
				if ($issue->PubDate->Year) {
					$year = strval($issue->PubDate->Year);
				}
				if ($issue->PubDate->Month) {
					$month = strval($issue->PubDate->Month);
				}
				if ($issue->PubDate->Day) {
					$date = $date." ".$issue->PubDate->Day;
					$day = "{$issue->PubDate->Day}";
				}
				$journalIssue = strval($issue->Volume);
				$vol = "";
				if ($issue->Volume) {
					$vol = strval($issue->Volume);
				}
				$iss = "";
				if ($issue->Issue) {
					$journalIssue .= "(".strval($issue->Issue).")";
					$iss = strval($issue->Issue);
				}
				$pages = "";
				if ($article->Pagination->MedlinePgn) {
					$journalIssue .= ":".$article->Pagination->MedlinePgn;
					$pages = strval($article->Pagination->MedlinePgn);
				}
				$pmid = strval($medlineCitation->MedlineCitation->PMID);
				$pubmed = "PubMed PMID: ".$pmid;
				$pmcid = self::PMIDToPMC($pmid);
				$pmcPhrase = "";
				if ($pmcid) {
					if (!preg_match("/PMC/", $pmcid)) {
						$pmcid = "PMC".$pmcid;
					}
					$pmcPhrase = " ".$pmcid.".";
				}

				if ($recordId) {
					$iCite = new iCite($pmid);
					$row = array(
							"record_id" => "$recordId",
							"redcap_repeat_instrument" => "citation",
							"redcap_repeat_instance" => "$instance",
							"citation_pmid" => $pmid,
							"citation_pmcid" => $pmcid,
							"citation_doi" => $iCite->getVariable("doi"),
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
							"citation_is_research" => $iCite->getVariable("is_research_article"),
							"citation_num_citations" => $iCite->getVariable("citation_count"),
							"citation_citations_per_year" => $iCite->getVariable("citations_per_year"),
							"citation_expected_per_year" => $iCite->getVariable("expected_citations_per_year"),
							"citation_field_citation_rate" => $iCite->getVariable("field_citation_rate"),
							"citation_nih_percentile" => $iCite->getVariable("nih_percentile"),
							"citation_rcr" => $iCite->getVariable("relative_citation_ratio"),
							"citation_complete" => "2",
							);
					array_push($upload, $row);
					$instance++;
				} else {
					throw new \Exception("Please specify a record id!");
				}
			}

			Publications::throttleDown();
		}
		return $upload;
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

	private function rightColumnText() {
		$html = "<button class='sticky biggerButton green' id='finalize' style='display: none; font-weight: bold;' onclick='submitChanges($(\"#nextRecord\").val()); return false;'>Finalize Citations</button><br>\n";
		$html .= "<div class='sticky red shadow' style='height: 120px; padding: 5px; vertical-align: middle; text-align: center; display: none;' id='uploading'>\n";
		$html .= "<p>Uploading Changes...</p>\n";
		$html .= "<p style='font-size: 12px;'>Redownloading citations from PubMed to ensure accuracy. May take up to one minute.</p>\n";
		$html .= "</div>\n";

		# make button show/hide at various pixelations
		$html .= "<script>\n";

		$html .= "\tfunction adjustFinalizeButton() {\n";
		$html .= "\t\tvar mainTable = $('#main').position();\n";
		$html .= "\t\tvar scrollTop = $(window).scrollTop();\n";
		$html .= "\t\tvar finalizeTop = mainTable.top - scrollTop;\n";
		# 100px is fixed position of the sticky class
		$html .= "\t\tvar finalLoc = 100;\n";
		$html .= "\t\tvar spacing = 20;\n";
		$html .= "\t\tvar buttonSize = 40;\n";
		$html .= "\t\tif (finalizeTop > finalLoc) { $('#finalize').css({ top: (finalizeTop+spacing)+'px' }); $('#uploading').css({ top: (finalizeTop+spacing+buttonSize)+'px' }); }\n";
		$html .= "\t\telse { $('#finalize').css({ top: finalLoc+'px' }); $('#uploading').css({ top: (finalLoc+buttonSize)+'px' }); }\n";
		$html .= "\t}\n";

		$html .= "$(document).ready(function() {\n";
		$html .= "\tadjustFinalizeButton();\n";
		# timeout to overcome API rate limit; 1.5 seconds seems adeqate; 1.0 seconds fails with immediate click
		$html .= "\tsetTimeout(function() {\n";
		$html .= "\t\t$('#finalize').show();\n";
		$html .= "\t}, 1500)\n";
		$html .= "\t$(document).scroll(function() { adjustFinalizeButton(); });\n";
		$html .= "});\n";
		$html .= "</script>\n";
		return $html;
	}

	# returns HTML to edit the publication; used in data wrangling
	public function getEditText() {
		$html = "";
		$html .= "<h1>Publication Wrangler</h1>\n";
		$html .= "<p class='centered'>This page is meant to confirm the association of papers with authors. Please confirm all authors regardless of how the article is categorized.</p>\n";
		if (!isset($_GET['headers']) || ($_GET['headers'] != "false")) {
			$html .= "<h2>".$this->recordId.": ".$this->name."</h2>\n";
		}

		$included = $this->getCitationCollection("Included");
		$includedCount = $included->getCount();
		if ($includedCount == 1) {
			$html .= "<h3 class='newHeader'>$includedCount Existing Citation | ";
		} else if ($includedCount == 0) {
			$html .= "<h3 class='newHeader'>No Existing Citations | ";
		} else {
			$html .= "<h3 class='newHeader'>$includedCount Existing Citations | ";
		}

		$notDone = $this->getCitationCollection("Not Done");
		$notDoneCount = $notDone->getCount();
		if ($notDoneCount == 1) {
			$html .= "$notDoneCount New Citation</h3>\n";
		} else if ($notDoneCount == 0) {
			$html .= "No New Citations</h3>\n";
		} else {
			$html .= "$notDoneCount New Citations</h3>\n";
		}

		$html .= self::manualLookup();
		$html .= "<table style='width: 100%;' id='main'><tr>\n";
		$html .= "<td class='twoColumn yellow' id='left'>".$this->leftColumnText()."</td>\n";
		$html .= "<td id='right'>".$this->rightColumnText()."</td>\n";
		$html .= "</tr></table>\n";

		return $html;
	}

	private function manualLookup() {
		$html = "";
		$html .= "<table style='margin-left: auto; margin-right: auto; border-radius: 10px;' class='bin'><tr>\n";
		$html .= "<td style='width: 250px; height: 200px; text-align: left; vertical-align: top;'>\n";
		$html .= "<h4 style='margin-bottom: 0px;'>Lookup PMID</h4>\n";
		$html .= "<p><input type='text' id='pmid' style='font-size: 20px; width: 150px;'> <button onclick='submitPMID($(\"#pmid\").val(), \"#manualCitation\", \"#lookupResult\"); return false;' class='biggerButton' readonly>Go!</button><p>\n";
		$html .= "<h4 style='margin-bottom: 0px;'>Lookup PMC</h4>\n";
		$html .= "<p><input type='text' id='pmc' style='font-size: 20px; width: 150px;'> <button onclick='submitPMC($(\"#pmc\").val(), \"#manualCitation\", \"#lookupResult\"); return false;' class='biggerButton'>Go!</button><p>\n";
		$html .= "</td><td style='width: 500px;'>\n";
		$html .= "<div id='lookupResult'>\n";
		$html .= "<p><textarea style='width: 100%; height: 150px; font-size: 16px;' id='manualCitation'></textarea></p>\n";
		$html .= "<p><button class='biggerButton green' onclick='includeCitation($(\"#manualCitation\").val()); return false;'>Include This Citation</button></p>\n";
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

	public static function PMIDToPMC($pmid) {
		if ($pmid) {
			$recData = self::runIDConverter($pmid);
			if ($recData) {
				foreach ($recData['records'] as $record) {
					if (isset($record['pmcid'])) {
						return $record['pmcid'];
					}
				}
			}
		}
		return "";
	}

	public static function PMCToPMID($pmcid) {
		if ($pmcid) {
			if (!preg_match("/PMC/", $pmcid)) {
				$pmcid = "PMC".$pmcid;
			}
			$recData = self::runIDConverter($pmcid);
			if ($recData) {
				foreach ($recData['records'] as $record) {
					if (isset($record['pmid'])) {
						return $record['pmid'];
					}
				}
			}
		}
		return "";
	}

	private static function runIDConverter($id) {
		if ($id) {
			$query = "ids=".$id."&format=json";
			$url = "https://www.ncbi.nlm.nih.gov/pmc/utils/idconv/v1.0/?".$query;
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_VERBOSE, 0);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_AUTOREFERER, true);
			curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
			curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			$output = curl_exec($ch);
			curl_close($ch);

			Publications::throttleDown();

			return json_decode($output, true);
		}
		return "";
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
		return array_sum($rcrs) / count($rcrs);
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

	public function writeToREDCap($token, $server) {
		if (!$token || !$server) {
			return "";
		}
		if (($this->recordId != 0) && $this->hasChanged) {
			$upload = array(
					"record_id" => $this->recordId,
					"redcap_repeat_instrument" => "",
					"redcap_repeat_instance" => "",
					"summary_final_citation_id" => json_encode($this->getCitationIds("Included")),
					"summary_final_citations" => implode("\n", $this->getCitations("Included")),
					"summary_number_citations" => $this->getNumber(),
					);
			return Upload::oneRow($token, $server, $upload);
		}
		return "";
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
	private $server;
	private $recordId;
	private $goodCitations;
	private $omissions;
	private $notDone;
	private $choices;
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
			$cat = Citation::suggestCategoryFromPubTypes($pubAry['Publication Types'], $pubAry['Title']);
			if ($cat) {
				return array($cat, 100);
			}
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
