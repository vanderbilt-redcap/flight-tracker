<?php

namespace Vanderbilt\CareerDevLibrary;

# used in Grants.php

require_once(__DIR__ . '/ClassLoader.php');

class ImportedChange {
    public function __construct($awardno) {
        $this->awardNo = $awardno;
    }

    public function setChange($type, $value) {
        $this->changeType = $type;
        $this->changeValue = $value;
    }

    public function getNumber() {
        return $this->awardNo;
    }

    public function getBaseAwardNumber() {
        return $this->getBaseNumber();
    }

    public function getBaseNumber() {
        return Grant::translateToBaseAwardNumber($this->awardNo);
    }

    public function setRemove($bool) {
        $this->remove = $bool;
    }

    public function isRemove() {
        return $this->remove;
    }

    public function setTakeOverDate($date) {
        $this->takeOverDate = $date;
    }

    public function getTakeOverDate() {
        return $this->takeOverDate;
    }

    public function getChangeType() {
        return $this->changeType;
    }

    public function getChangeValue() {
        return $this->changeValue;
    }

    public function toString() {
        $remove = "";
        if ($this->isRemove()) {
            $remove = " REMOVE";
        }
        return $this->changeType." ".$this->changeValue.": ".$this->awardNo.$remove;
    }

    # unit test - award number is populated
    # unit test - change outputs values

    private $changeType;
    private $changeValue;
    private $awardNo;
    private $remove = FALSE;
    private $takeOverDate = "";
}

