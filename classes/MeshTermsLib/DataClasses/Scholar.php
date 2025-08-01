<?php

namespace Vanderbilt\CareerDevLibrary\MeshTermsLib\DataClasses;

use DateTime;
use Vanderbilt\CareerDevLibrary\MeshTermsLib\CollectionClasses\PosterCollection;

class Scholar
{
	public string $name = "";
	public string $institution = "";
	public array $publications = [];
	private array $firstLastAuthorPublications = [];
	private array $middleAuthorPublications = [];
	public array $primaryMeshTerms = [];
	public array $secondaryMeshTerms = [];
	private int $maxPublications = 10;
	public string $recordId = "";
	public ?PosterCollection $recommendedPoster = null;

	public function __construct(string $name, string $institution, string $recordId) {
		$this->name = $this->cleanName($name);
		$this->institution = $institution;
		$this->recordId = $recordId;
	}

	public function savePublicationList(array $publications): void {
		$this->publications = array_merge($this->publications, $publications);
	}

	public function getPublicationList(): array {
		return $this->publications;
	}

	private function generatePublicationList() {
		$client = new \GuzzleHttp\Client();
		$response = $client->request('GET', 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi', [
			'query' => [
				'db' => 'pubmed',
				'retmax' => '100000',
				'retmode' => 'json',
				'term' => "($this->name [au]) AND ($this->institution [ad])",
			]
		]);
		usleep(500000);
		$data = json_decode($response->getBody(), true);
		$pmids = $data['esearchresult']['idlist'];
		if (!is_null($pmids) && count($pmids) > 0) {
			$this->savePublicationList($pmids);
		}
	}

	private function processPublications() {
		$pmids = $this->getPublicationList();
		$pmidChunks = array_chunk($pmids, 20);
		$client = new \GuzzleHttp\Client();
		foreach ($pmidChunks as $chunk) {
			$pmidList = implode(',', $chunk);
			$response = $client->request('GET', 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi', [
				'query' => [
					'db' => 'pubmed',
					'retmode' => 'xml',
					'id' => $pmidList
				]
			]);
			$data = $response->getBody()->getContents();
			$xml = simplexml_load_string($data);
			foreach ($xml->PubmedArticle as $article) {
				$this->processPublication($article);
			}
			usleep(500000);
		}
	}

	public function initializeScholar(): bool {
		$serializer = new \Zumba\JsonSerializer\JsonSerializer();
		$nameFile = str_replace(" ", "_", $this->name);
		$primaryDir = __DIR__ . "/../../../temp/$nameFile-primary.json";
		$secondaryDir = __DIR__ . "/../../../temp/$nameFile-secondary.json";
		if ($this->cachedDataExits()) {
			$this->loadCachedData();
			return true;
		} else {
			$this->generatePublicationList();
			$this->processPublications();
			$this->generateMeshTermsForScholar();
			file_put_contents($primaryDir, $serializer->serialize($this->primaryMeshTerms));
			file_put_contents($secondaryDir, $serializer->serialize($this->secondaryMeshTerms));
			return false;
		}
	}

	public function cachedDataExits(): bool {
		$nameFile = str_replace(" ", "_", $this->name);
		$primaryDir = __DIR__ . "/../../../temp/$nameFile-primary.json";
		$secondaryDir = __DIR__ . "/../../../temp/$nameFile-secondary.json";
		return file_exists($primaryDir) && file_exists($secondaryDir);
	}

	public function loadCachedData(): void {
		$serializer = new \Zumba\JsonSerializer\JsonSerializer();
		$nameFile = str_replace(" ", "_", $this->name);
		$primaryDir = __DIR__ . "/../../../temp/$nameFile-primary.json";
		$secondaryDir = __DIR__ . "/../../../temp/$nameFile-secondary.json";
		$this->primaryMeshTerms = $serializer->unserialize(file_get_contents($primaryDir));
		$this->secondaryMeshTerms = $serializer->unserialize(file_get_contents($secondaryDir));
	}

	public function processPublication(\SimpleXMLElement $publication): void {
		$pmid = (string)$publication->MedlineCitation->PMID;
		$publicationDate = $this->getPublicationDate($publication);
		$isFirstOrLastAuthor = $this->isFirstOrLastAuthor($publication->MedlineCitation->Article->AuthorList);
		$isAuthor = $this->isAuthor($publication->MedlineCitation->Article->AuthorList);
		if (!$isAuthor) {
			return;
		}
		if ($isFirstOrLastAuthor) {
			if (count($this->firstLastAuthorPublications) < $this->maxPublications) {
				$this->firstLastAuthorPublications[$pmid]['publication'] = $publication;
				$this->firstLastAuthorPublications[$pmid]['date'] = $publicationDate;
			} else {
				if ($publicationDate > $this->firstLastAuthorPublications[$this->getOldestPublicationPmid($this->firstLastAuthorPublications)]['date']) {
					unset($this->firstLastAuthorPublications[$this->getOldestPublicationPmid($this->firstLastAuthorPublications)]);
					$this->firstLastAuthorPublications[$pmid]['publication'] = $publication;
					$this->firstLastAuthorPublications[$pmid]['date'] = $publicationDate;
				}
			}
		} else {
			if (count($this->middleAuthorPublications) < $this->maxPublications) {
				$this->middleAuthorPublications[$pmid]['publication'] = $publication;
				$this->middleAuthorPublications[$pmid]['date'] = $publicationDate;
			} else {
				if ($publicationDate > $this->middleAuthorPublications[$this->getOldestPublicationPmid($this->middleAuthorPublications)]['date']) {
					unset($this->middleAuthorPublications[$this->getOldestPublicationPmid($this->middleAuthorPublications)]);
					$this->middleAuthorPublications[$pmid]['publication'] = $publication;
					$this->middleAuthorPublications[$pmid]['date'] = $publicationDate;
				}
			}
		}
	}

	private function getOldestPublicationPmid($pubList): string {
		$oldestDate = new DateTime();
		$oldestPmid = "";
		foreach ($pubList as $pmid => $publication) {
			if ($publication['date'] < $oldestDate) {
				$oldestDate = $publication['date'];
				$oldestPmid = $pmid;
			}
		}
		return $oldestPmid;
	}

	private function getPublicationDate(\SimpleXMLElement $publication): DateTime {
		$pubDate = $publication->MedlineCitation->Article->Journal->JournalIssue->PubDate;
		$articleDate = $publication->MedlineCitation->Article->ArticleDate;
		$pubScore = 0;
		$articleScore = 0;
		if ($pubDate->Year) {
			$pubScore += 1;
		}
		if ($pubDate->Month) {
			$pubScore += 1;
		}
		if ($pubDate->Day) {
			$pubScore += 1;
		}
		if ($articleDate->Year) {
			$articleScore += 1;
		}
		if ($articleDate->Month) {
			$articleScore += 1;
		}
		if ($articleDate->Day) {
			$articleScore += 1;
		}
		if ($articleScore > $pubScore) {
			$year = (string)$articleDate->Year;
			$month = (string)$articleDate->Month;
			$day = (string)$articleDate->Day;
		} else {
			$year = (string)$pubDate->Year;
			$month = $this->getMonthNumber((string)$pubDate->Month);
			$day = $pubDate->Day ? (string)$pubDate->Day : "01";
		}
		return new DateTime("$year-$month-$day");
	}

	private function getMonthNumber($monthStr) {
		if ($monthStr === "") {
			return "01";
		}
		if (is_numeric($monthStr)) {
			return $monthStr;
		}
		$monthStr = strtoupper($monthStr);
		$months = [
			"JAN" => "01",
			"FEB" => "02",
			"MAR" => "03",
			"APR" => "04",
			"MAY" => "05",
			"JUN" => "06",
			"JUL" => "07",
			"AUG" => "08",
			"SEP" => "09",
			"OCT" => "10",
			"NOV" => "11",
			"DEC" => "12",
			"SPRING" => "03",
			"SUMMER" => "06",
			"FALL" => "09",
			"AUTUMN" => "09",
			"WINTER" => "12",
		];
		for ($i = 1; $i <= 12; $i++) {
			$month = str_pad($i, 2, "0", STR_PAD_LEFT);
			$ts = strtotime("2020-$month-01");
			$months[strtoupper(date("F", $ts))] = $month;
		}

		if (isset($months[$monthStr])) {
			return $months[$monthStr];
		}
		$date = date_parse($monthStr);
		if (!empty($date['errors'])) {
			throw new \Exception(implode("<br/>\n", $date['errors']));
		}
		if ($date['month']) {
			return REDCapManagement::padInteger($date['month'], 2);
		}
		throw new \Exception("Invalid month $monthStr");
	}

	private function isFirstOrLastAuthor(\SimpleXMLElement $authors): bool {
		$authorCount = count($authors->Author);
		if ($authorCount == 0) {
			return false;
		}
		$firstAuthor = (string)$authors->Author[0]->LastName;
		$lastAuthor = (string)$authors->Author[$authorCount - 1]->LastName;
		if ($firstAuthor == "" || $lastAuthor == "") {
			return false;
		}
		if (count(explode(" ", $this->name)) == 1) {
			return false;
		}
		return ($firstAuthor == explode(" ", $this->name)[1] || $lastAuthor == explode(" ", $this->name)[1]);
	}

	public function generateMeshTermsForScholar() {
		foreach ($this->firstLastAuthorPublications as $pmid => $publication) {
			$meshTerms = $this->getMeshTerms($publication['publication']);
			$this->primaryMeshTerms = array_merge($this->primaryMeshTerms, $meshTerms);
			$this->primaryMeshTerms = array_unique($this->primaryMeshTerms);
		}
		foreach ($this->middleAuthorPublications as $pmid => $publication) {
			$meshTerms = $this->getMeshTerms($publication['publication']);
			$this->secondaryMeshTerms = array_merge($this->secondaryMeshTerms, $meshTerms);
			$this->secondaryMeshTerms = array_unique($this->secondaryMeshTerms);
		}
		$this->secondaryMeshTerms = array_diff($this->secondaryMeshTerms, $this->primaryMeshTerms);
	}

	private function getMeshTerms(\SimpleXMLElement $publication): array {
		$meshTerms = [];
		foreach ($publication->MedlineCitation->MeshHeadingList->MeshHeading as $meshHeading) {
			$meshTerms[] = (string)$meshHeading->DescriptorName;
		}
		return $meshTerms;
	}

	public function getRecommendedPoster(): PosterCollection {
		return $this->recommendedPoster;
	}

	public function setRecommendedPoster(PosterCollection $recommendedPoster): void {
		$this->recommendedPoster = $recommendedPoster;
	}

	public function isAuthor(\SimpleXMLElement $authors): bool {
		foreach ($authors->Author as $author) {
			$lastNamePubMed = (string)$author->LastName;
			$firstNamePubMed = (string)$author->ForeName;
			$nameExplode = explode(" ", $this->name);
			$firstName = $nameExplode[0];
			$lastName = $nameExplode[1];
			if (str_contains($lastNamePubMed, $lastName) || str_contains($firstNamePubMed, $firstName)) {
				return true;
			}
		}
		return false;
	}

	public function getNumberOfFirstLastAuthorPublications(): int {
		return count($this->firstLastAuthorPublications);
	}

	public function getNumberOfMiddleAuthorPublications(): int {
		return count($this->middleAuthorPublications);
	}

	public function getNumberOfPublications(): int {
		return count($this->firstLastAuthorPublications) + count($this->middleAuthorPublications);
	}

	private function cleanName($name): string {
		$name = str_replace(["PHD", "PhD", "MD", "CRNA", "MPH", "RN", ",", "CCC-SLP", "(she\/her)", "DO", "CCRP", "MS", "MSCI", "MPP", 'CI', "CPRS", "MBBS"], "", $name);
		$name = trim($name, '., ');
		$nameSplit = explode(" ", $name);
		//Hard coded fixes for certain attendees. Should be removed when moving to flighttracker module.
		if ($name == "Markie Sneed") {
			return "Nadia Sneed";
		}
		if ($name == "Don Arnold") {
			return "Donald Arnold";
		}
		if ($name == "xiuqi.zhang") {
			return "Xiuqi Zhang";
		}
		if ($name == "Irina De la Huerta") {
			return "Irina De la Huerta";
		}
		if ($name == "Celly Wanjala") {
			return "Celestine Wanjalla";
		}
		if ($name == "Bill Mitchell") {
			return "William Mitchell";
		}
		if ($name == "Milene Fontes") {
			return "Milene Tavares";
		}
		if ($name == "Julia Donato") {
			return "Julia Landivar";
		}
		if ($name == "Dan Larach") {
			return "Daniel Larach";
		}
		if ($name == "Bennie Damul") {
			return "Benmun Damul";
		}
		if (count($nameSplit) > 1) {
			$name = $nameSplit[0] . " " . $nameSplit[count($nameSplit) - 1];
		} else {
			$name = $nameSplit[0];
		}
		return $name;
	}
}
