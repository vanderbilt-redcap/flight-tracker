<?php

namespace Vanderbilt\CareerDevLibrary;

# used in Grants.php

require_once(__DIR__ . '/ClassLoader.php');


class Name
{
	public function __construct($first, $middle, $last) {
		$this->first = strtolower($first);
		$this->middle = strtolower($middle);
		$this->last = strtolower($last);
	}

	public function isMatch($fullName) {
		$names = preg_split("/[\s,\.]+/", $fullName);
		$matchedFirst = false;
		$matchedLast = false;
		foreach ($names as $name) {
			$name = strtolower($name);
			if (($name != $this->first) && ($name != $this->middle) && ($name != $this->last)) {
				return false;
			}
			if ($name == $this->first) {
				$matchedFirst = true;
			}
			if ($name == $this->last) {
				$matchedLast = true;
			}
		}
		if (!$matchedFirst || !$matchedLast) {
			return false;
		}
		return true;
	}

	private $first;
	private $middle;
	private $last;
}
