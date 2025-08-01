<?php

namespace Vanderbilt\CareerDevLibrary\MeshTermsLib\DataClasses;

class Publication
{
	public function __construct(
		public string $recordId = "",
		public string $title = "",
		public string $authors = "",
		public string $publicationDate = "",
		public string $journal = "",
		public string $meshTerms = "",
		public int $publicationScore = 0
	) {

	}


}
