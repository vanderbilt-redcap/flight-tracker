<?php

namespace Vanderbilt\CareerDevLibrary\MeshTermsLib\CollectionClasses;

use Vanderbilt\CareerDevLibrary\MeshTermsLib\DataClasses\Scholar;
use Traversable;

class ScholarCollection implements \IteratorAggregate
{
	private array $scholars = [];

	public function __construct(Scholar ...$scholars) {
		$this->scholars = $scholars;
	}

	/**
	 * @inheritDoc
	 */
	public function getIterator(): Traversable {
		return new \ArrayIterator($this->scholars);
	}

	public function addScholar(Scholar $scholar): void {
		$this->scholars[] = $scholar;
	}
}
