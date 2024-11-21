<?php

namespace Vanderbilt\CareerDevLibrary;

# used in Grants.php

require_once(__DIR__ . '/ClassLoader.php');


class Name {
    public function __construct($first, $middle, $last) {
        $this->first = strtolower($first);
        $this->middle = strtolower($middle);
        $this->last = strtolower($last);
    }

    public function isMatch($fullName) {
        $names = preg_split("/[\s,\.]+/", $fullName);
        $matchedFirst = FALSE;
        $matchedLast = FALSE;
        foreach ($names as $name) {
            $name = strtolower($name);
            if (($name != $this->first) && ($name != $this->middle) && ($name != $this->last)) {
                return FALSE;
            }
            if ($name == $this->first) {
                $matchedFirst = TRUE;
            }
            if ($name == $this->last) {
                $matchedLast = TRUE;
            }
        }
        if (!$matchedFirst || !$matchedLast) {
            return FALSE;
        }
        return TRUE;
    }

    private $first;
    private $middle;
    private $last;
}
