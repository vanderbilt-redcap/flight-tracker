<?php

namespace Vanderbilt\CareerDevLibrary\MeshTermsLib\DataClasses;

class Poster
{
	public string $recordId = "";
	public int $posterScore = 0;

	public function __construct(string $recordId, int $posterScore) {
		$this->recordId = $recordId;
		$this->posterScore = $posterScore;
	}

	public function setRecordId(string $recordId): void {
		$this->recordId = $recordId;
	}

	public function getRecordId(): string {
		return $this->recordId;
	}

	public function setPosterScore(int $posterScore): void {
		$this->posterScore = $posterScore;
	}

	public function getPosterScore(): int {
		return $this->posterScore;
	}
}
