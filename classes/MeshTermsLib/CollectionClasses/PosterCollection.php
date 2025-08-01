<?php

namespace Vanderbilt\CareerDevLibrary\MeshTermsLib\CollectionClasses;

use Vanderbilt\CareerDevLibrary\MeshTermsLib\DataClasses\Poster;
use Traversable;

class PosterCollection implements \IteratorAggregate
{
	public array $posters = [];

	public function __construct(Poster ...$posters) {
		$this->posters = $posters;
	}
	/**
	 * @inheritDoc
	 */
	public function getIterator(): Traversable {
		return new \ArrayIterator($this->posters);
	}

	public function addPoster(Poster $poster): void {
		$this->posters[] = $poster;
	}

	public function getPosterByRecordId(string $recordId): ?Poster {
		foreach ($this->posters as $poster) {
			if ($poster->getRecordId() === $recordId) {
				return $poster;
			}
		}
		return null;
	}
}
