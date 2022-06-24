<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class Patents {
    public function __construct($recordId, $pid, $firstName = "", $lastName = "", $institutions = []) {
        $this->pid = $pid;
        $this->token = Application::getSetting("token", $this->pid);
        $this->server = Application::getSetting("server", $this->pid);
        $this->rows = [];
        $this->patentNumbers = [];
        $this->excludedNumbers = [];
        $this->newNumbers = [];
        $this->recordId = $recordId;
        $this->eventId = Application::getSetting("event_id", $this->pid);
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->name = "$firstName $lastName";
        $this->institutions = array_unique(array_merge(Application::getInstitutions($pid), Application::getHelperInstitutions(), $institutions));
    }

    public static function getURLForPatent($patentNumber) {
        return "https://datatool.patentsview.org/#search&pat=2|".$patentNumber;
    }

    public function setRows($rows) {
        $this->rows = $rows;
        $this->patentNumbers = [];
        foreach ($this->rows as $row) {
            if (($row['record_id'] == $this->recordId) && ($row['redcap_repeat_instrument'] == "patent")) {
                if ($row['patent_include'] == "1") {
                    $this->patentNumbers[$row['redcap_repeat_instance']] = $row['patent_number'];
                } else if ($row['patent_include'] === "0") {
                    $this->excludedNumbers[$row['redcap_repeat_instance']] = $row['patent_number'];
                } else if ($row['patent_include'] === "") {
                    $this->newNumbers[$row['redcap_repeat_instance']] = $row['patent_number'];
                }
            }
        }
    }

    public function getHTML($useDivs = FALSE) {
        $html = "";
        foreach ($this->rows as $row) {
            if (($row['redcap_repeat_instrument'] == "patent") && ($row['patent_include'] == "1")) {
                if ($useDivs) {
                    $html .= "<div>".$this->redcapRowIntoHTML($row)."</div>";
                } else {
                    $html .= "<p>".$this->redcapRowIntoHTML($row)."</p>";
                }
            }
        }
        return $html;
    }

    private function redcapRowIntoHTML($row) {
        $patentNumber = Links::makeFormLink($this->pid, $this->recordId, $this->eventId, "Patent ".$row['patent_number'], "patent", $row['redcap_repeat_instance'], "", TRUE);
        $html = "";
        $html .= $patentNumber;
        if ($row['patent_date']) {
            $html .= " on ".REDCapManagement::YMD2MDY($row['patent_date']);
        }
        if ($row['patent_inventors']) {
            $listOfInventors = preg_split("/\s*,\s*/", $row['patent_inventors']);
            if ($this->firstName && $this->lastName) {
                for ($i = 0; $i < count($listOfInventors); $i++) {
                    list($currFirstName, $currLastName) = NameMatcher::splitName($listOfInventors[$i]);
                    if (NameMatcher::matchName($this->firstName, $this->lastName, $currFirstName, $currLastName)) {
                        $listOfInventors[$i] = "<b>".$listOfInventors[$i]."</b>";
                    }
                }
            }
            $html .= " by ".REDCapManagement::makeConjunction($listOfInventors);
        }
        if ($row['patent_assignees']) {
            $listOfAssignees = preg_split("/\s*,\s*/", $row['patent_assignees']);
            if (!empty($this->institutions)) {
                for ($i = 0; $i < count($listOfAssignees); $i++) {
                    if (NameMatcher::matchInstitution($listOfAssignees[$i], $this->institutions)) {
                        $listOfAssignees[$i] = "<b>".$listOfAssignees[$i]."</b>";
                    }
                }
            }
            $html .= " assigned to ".REDCapManagement::makeConjunction($listOfAssignees);
        }
        $html .= ".";
        return $html;
    }

    public function getCount($type = "Included") {
        return count($this->getData($type));
    }

    public function dataToHTML($type = "Included") {
        if ($type == "Included") {
            $checkboxClass = "checked";
        } else if ($type == "New") {
            $checkboxClass = "checked";
        } else if ($type == "Excluded") {
            $checkboxClass = "unchecked";
        } else {
            throw new \Exception("Unknown type $type");
        }

        $numbers = $this->getData($type);
        if (count($numbers) == 0) {
            return "<p class='centered'>None to date.</p>";
        }
        $html = "";
        foreach ($this->rows as $row) {
            if (in_array($row['patent_number'], $numbers)) {
                $description = $this->redcapRowIntoHTML($row);
                $ableToReset = ["Included", "New"];

                $number = $row['patent_number'];
                $id = "USPO$number";
                $html .= "<div class='patent $type' id='patent_$type$id'>";
                $html .= Wrangler::makeCheckbox($id, $checkboxClass)." ".$description;
                if (in_array($type, $ableToReset)) {
                    $html .= "<div style='text-align: right;' class='smallest'><span onclick='resetPatent(\"$id\");' class='finger'>reset</span></div>";
                }
                $html .= "</div>\n";
            }
        }
        return $html;
    }

    private function getData($type = "Included") {
        if ($type == "Included") {
            return $this->patentNumbers;
        } else if ($type == "Excluded") {
            return $this->excludedNumbers;
        } else if ($type == "New") {
            return $this->newNumbers;
        } else {
            throw new \Exception("Invalid patent category $type");
        }
    }

    public function getTitles() {
        return $this->getFields("patent_title");
    }

    public function getAbstracts() {
        return $this->getFields("patent_abstract");
    }

    private function getFields($field) {
        $values = [];
        foreach ($this->patentNumbers as $instance => $patentNumber) {
            $value = REDCapManagement::findField($this->rows, $this->recordId, $field, TRUE, $instance);
            if (!$value) {
                Application::log("Warning! In Record ".$this->recordId.", no value for $field on instance $instance", $this->pid);
            }
            $values[$instance] = $value;
        }
        return $values;
    }

    public function includePatents($listOfPatentNumbers) {
        $this->setPatentStatus($listOfPatentNumbers, "1");
    }

    public function excludePatents($listOfPatentNumbers) {
        $this->setPatentStatus($listOfPatentNumbers, "0");
    }

    private function setPatentStatus($listOfPatentNumbers, $valueToSet) {
        if (empty($listOfPatentNumbers)) {
            return;
        }
        $instancesToInclude = [];
        foreach ($listOfPatentNumbers as $patentNumber) {
            if (in_array($patentNumber, array_values($this->patentNumbers))) {
                foreach ($this->patentNumbers as $myInstance => $myPatentNumber) {
                    if ($patentNumber == $myPatentNumber) {
                        $instancesToInclude[] = $myInstance;
                    }
                }
            } else {
                Application::log("Warning! Cannot find $patentNumber for inclusion.", $this->pid);
            }
        }
        if (!empty($instancesToInclude)) {
            $upload = [];
            foreach ($instancesToInclude as $instance) {
                $upload[] = [
                    "record_id" => $this->recordId,
                    "redcap_repeat_instrument" => "patent",
                    "redcap_repeat_instance" => $instance,
                    "patent_include" => $valueToSet,
                    ];
            }
            Upload::rowsByPid($upload, $this->pid);
        } else {
            Application::log("Warning! No patents to include from ".implode(", ", $listOfPatentNumbers));
        }
    }

    public function getEditText($thisUrl) {
        $wrangler = new Wrangler("Patents", $this->pid);
        $html = $wrangler->getEditText($this->getCount("New"), $this->getCount("Included"), $this->recordId, Download::fullName($this->token, $this->server, $this->recordId) ?: $this->name, $this->lastName);

        $html .= self::manualLookup($thisUrl);
        $html .= "<table style='width: 100%;' id='main'><tr>\n";
        $html .= "<td class='twoColumn blue' id='left'>".$this->leftColumnText()."</td>\n";
        $html .= "<td id='right'>".$wrangler->rightColumnText()."</td>\n";
        $html .= "</tr></table>\n";

        return $html;
    }

    private function leftColumnText() {
        $html = "";
        $notDoneCount = $this->getCount("New");
        $html .= "<h4 class='newHeader'>";
        if ($notDoneCount == 0) {
            $html .= "No New Patents";
            $html .= "</h4>\n";
            $html .= "<div id='newPatents'>\n";
        } else {
            if ($notDoneCount == 1) {
                $html .= $notDoneCount." New Patent";
            } else {
                $html .= $notDoneCount." New Patents";
            }
            $html .= "</h4>\n";
            $html .= "<div id='newPatents'>\n";
            if ($notDoneCount > 1) {
                $html .= "<p class='centered'><a href='javascript:;' onclick='selectAllPatents(\"#newPatents\");'>Select All New Patents</a> | <a href='javascript:;' onclick='unselectAllPatents(\"#newPatents\");'>Deselect All New Patents</a></p>";
            }
            $html .= $this->dataToHTML("New");
        }
        $html .= "</div>\n";
        $html .= "<hr>\n";

        $html .= "<h4>Existing Patents</h4>\n";
        $html .= "<div id='finalPatents'>\n";
        $html .= $this->dataToHTML("Included");
        $html .= "</div>\n";
        return $html;
    }

    private function rightColumnText() {

    }

    private function manualLookup($thisUrl) {
        $html = "";
        $html .= "<table id='lookupTable' style='margin-left: auto; margin-right: auto; border-radius: 10px;' class='bin'><tr>\n";
        $html .= "<td style='width: 250px; height: 200px; text-align: left; vertical-align: top;'>\n";
        $html .= "<h4 style='margin-bottom: 0px;'>Lookup Patent</h4>\n";
        $html .= "<p class='oneAtATime'><input type='text' id='patent'> <button onclick='submitPatent($(\"#patent\").val(), \"#manualPatent\", \"\"); return false;' class='biggerButton' readonly>Go!</button><br><a class='smaller' href='javascript:;' onclick='$(\".list\").show(); $(\".oneAtATime\").hide(); checkSubmitButton(\"#manualPatent\", \".list\");'>Switch to Bulk</a></p>\n";
        $html .= "<p class='list' style='display: none;'><textarea id='patentList'></textarea> <button onclick='submitPatents($(\"#patentList\").val(), \"#manualPatent\", \"\"); return false;' class='biggerButton' readonly>Go!</button><br><a class='smaller' href='javascript:;' onclick='$(\".list\").hide(); $(\".oneAtATime\").show(); checkSubmitButton(\"#manualPatent\", \".oneAtATime\");'>Switch to Single</a></p>\n";
        $html .= "</td><td style='width: 500px;'>\n";
        $html .= "<div id='lookupResult'>\n";
        $html .= "<p><textarea style='width: 100%; height: 150px; font-size: 16px;' id='manualPatent'></textarea></p>\n";
        $html .= "<p class='oneAtATime'><button class='biggerButton green includeButton' style='display: none;' onclick='includePatent($(\"#manualPatent\").val(), \"$thisUrl\"); return false;'>Include This Patent</button></p>\n";
        $html .= "<p class='list' style='display: none;'><button class='biggerButton green includeButton' style='display: none;' onclick='includePatents($(\"#manualPatent\").val(), \"$thisUrl\"); return false;'>Include These Patents</button></p>\n";
        $html .= "</div>\n";
        $html .= "</td>\n";
        $html .= "</tr></table>\n";
        return $html;
    }

    private $pid;
    private $token;
    private $server;
    private $rows;
    private $recordId;
    private $patentNumbers;
    private $excludedNumbers;
    private $newNumbers;
    private $eventId;
    private $lastName;
    private $firstName;
    private $institutions;
    private $name;
}