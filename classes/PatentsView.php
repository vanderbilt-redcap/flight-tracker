<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/REDCapManagement.php");

class PatentsView {
    public function __construct($recordId, $pid, $startDate = "none") {
        if (!$recordId) {
            throw new \Exception("recordId is required to access a patent");
        }

        $this->recordId = $recordId;
        $this->pid = $pid;
        if ($startDate == "none") {
            $this->startDate = "";
        } else if (REDCapManagement::isDate($startDate)) {
            $this->startDate = $startDate;
        } else {
            throw new \Exception("Invalid Patents View start date for Record $recordId: $startDate");
        }
    }

    public function setName($lastName, $firstName) {
        if (!$lastName) {
            throw new \Exception("Blank last name for record ".$this->recordId);
        }
        $this->lastName = $lastName;
        $this->firstName = $firstName;
    }

    private function formQuery() {
        if (!$this->lastName) {
            throw new \Exception("Does not have last name in Record ".$this->recordId);
        } else {
            $vars = [];
            $vars[] = ["inventor_last_name" => $this->lastName];
            if ($this->firstName) {
                $vars[] = ["inventor_first_name" => $this->firstName];
            }
            if ($this->startDate) {
                $vars[] = ["_gte" => ["patent_date" => $this->startDate]];
            }

            if (count($vars) == 1) {
                return $vars;
            } else {
                return ["_and" => $vars];
            }
        }
    }

    public function getFilteredPatentsAsREDCap($institutions, $maxInstance = 0, $previousPatentNumbers) {
        if (empty($institutions)) {
            Application::log("Warning! Institutions is empty in getFilteredPatents for record ".$this->recordId.". Will match against all organizations.");
        }
        $allPatents = $this->getPatents();
        $filteredPatents = [];
        foreach ($allPatents as $patent) {
            foreach ($patent["assignee"] as $orgAry) {
                $org = strtolower($orgAry["assignee_organization"]);
                $hasInstitution = FALSE;
                foreach ($institutions as $institution) {
                    if (preg_match("/".$institution."/i", $org)) {
                        $hasInstitution = TRUE;
                        break;
                    }
                }
                if ($hasInstitution || empty($institutions)) {
                    if (!in_array($patent['patent_number'], $previousPatentNumbers)) {
                        $filteredPatents[] = $patent;
                        $previousPatentNumbers[] = $patent['patent_number'];
                    }
                    break;
                }
            }
        }
        return $this->patents2REDCap($filteredPatents, $maxInstance);
    }

    public function patents2REDCap($patents, $maxInstance) {
        $rows = [];
        $instance = $maxInstance;
        foreach ($patents as $patent) {
            $instance++;
            $row = ["record_id" => $this->recordId, "redcap_repeat_instrument" => "patent", "redcap_repeat_instance" => $instance];
            $inventors = ["lastNames" => [], "ids" => []];

            foreach ($patent['inventors'] as $inventor) {
                $inventors["lastNames"][] = $inventor['inventor_last_name'];
                $inventors["ids"][] = $inventor['inventor_key_id'];
            }
            $assignees = ["names" => [], "ids" => []];
            foreach ($patent['assignees'] as $assignee) {
                $assignees["names"][] = $assignee['assignee_organization'];
                $assignees["ids"][] = $assignee['assignee_key_id'];
            }

            $row['patent_number'] = $patent["patent_number"] ? $patent["patent_number"] : "";
            $row['patent_date'] = $patent["patent_date"] ? $patent["patent_date"] : "";
            $row['patent_inventors'] = implode(", ", $inventors["lastNames"]);
            $row['patent_inventor_ids'] = implode(", ", $inventors["ids"]);
            $row['patent_assignees'] = implode(", ", $assignees["names"]);
            $row['patent_assignee_ids'] = implode(", ", $assignees["ids"]);
            $row['patent_last_update'] = date("Y-m-d");
            $rows[] = $row;
        }
        return $rows;
    }

    public static function getPatentNumbers($redcapData) {
        $numbers = [];
        foreach ($redcapData as $row) {
            if ($row['patent_number'] && ($row['redcap_repeat_instrument'] == "patent")) {
                $numbers[] = $row['patent_number'];
            }
        }
        return $numbers;
    }

    private function getPatents() {
        $query = $this->formQuery();
        $patents = [];
        if (!empty($query)) {
            $fields = ["patent_number","patent_date","inventor_last_name","assignee_organization"];
            $numPerPage = 50;
            $page = 0;
            do {
                $hasMore = FALSE;
                $page++;
                $o = ["page" => $page, "per_page" => $numPerPage];
                $url = "https://api.patentsview.org/patents/query?q=".json_encode($query)."&f=".json_encode($fields)."&o=".json_encode($o);
                $json = REDCapManagement::downloadURL($url, $this->pid);
                if (REDCapManagement::isJSON($json)) {
                    $data = json_decode($json, TRUE);
                    if (($data["patents"] === NULL) || empty($data["patents"])) {
                        return [];
                    } else if (isset($data["patents"])) {
                        $patents = array_merge($patents, $data["patents"]);
                        $hasMore = isset($data['count'])
                            && isset($data['total_patent_count'])
                            && ($data['count'] == $numPerPage)
                            && ($data['count'] < $data['total_patent_count']);
                    } else {
                        throw new \Exception("Could not find 'patents' in data: ".json_encode($data));
                    }
                    usleep(500);
                } else {
                    Application::log("Could not decode JSON for Record {$this->recordId} ".$json);
                }
            } while ($hasMore);
        } else {
            Application::log("Empty query for Record {$this->recordId}");
        }
        return $patents;
    }

    protected $lastName = "";
    protected $firstName = "";
    protected $startDate = "";
    protected $recordId = "";
    protected $pid;
}