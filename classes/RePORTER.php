<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/REDCapManagement.php");
require_once(dirname(__FILE__)."/NameMatcher.php");
require_once(dirname(__FILE__)."/../Application.php");

class RePORTER {
    public function __construct($pid, $recordId, $category) {
        $this->pid = $pid;
        $this->recordId = $recordId;
        $this->category = $category;
        $this->includeFields = [
            "ApplId","SubprojectId","FiscalYear","OrgName","OrgCity",
            "OrgState","OrgStateName","DeptType", "ProjectNum","OrgCountry",
            "ProjectNumSplit","ContactPiName","AllText","FullStudySection",
            "ProjectStartDate","ProjectEndDate",
        ];
        if ($this->category == "NIH") {
            $this->server = "https://api.reporter.nih.gov";
        } else if ($this->category == "Federal") {
            $this->server = "https://api.federalreporter.nih.gov";
        } else {
            throw new \Exception("Wrong category!");
        }
    }

    public function searchAward($baseAwardNo) {
        if ($this->isFederal()) {
            $query = $this->server."/v1/projects/search?query=projectNumber:*".urlencode($baseAwardNo)."*";
            $this->runGETQuery($query);
        } else if ($this->isNIH()) {
            $payload = [
                "criteria" => ["project_nums" => "?$baseAwardNo*"],
                "include_fields" => $this->includeFields,
            ];
            $location = $this->server."/v1/projects/search";
            $this->runPOSTQuery($location, $payload);
        }
        return $this->getData();
    }

    public function runPOSTQuery($url, $postdata, $limit = 25, $offset = 0) {
        $postdata['limit'] = $limit;
        $postdata['offset'] = $offset;
        $this->currData = [];
        do {
            list($resp, $output) = $this->downloadPOST($url, $postdata);
            $this->sleep();
            $runAgain = FALSE;
            if ($resp == 200) {
                $fullResults = json_decode($output, TRUE);
                $this->currData = array_merge($this->currData, $fullResults['results']);
                if (count($fullResults['results']) == $limit) {
                    $offset += $limit;
                    $postdata['offset'] = $offset;
                    $runAgain = TRUE;
                }
            }
        } while ($runAgain);
        return $this->getData();
    }

    public function downloadPOST($url, $postdata) {
        return REDCapManagement::downloadURLWithPOST($url, $postdata, $this->pid);
    }

    public function searchPI($piName, $institutions) {
        if ($this->isFederal()) {
            $query = "/v1/projects/search?query=PiName:".urlencode($piName);
            $this->runGETQuery($query);
            return $this->filterForInstitutionsAndName($piName, $institutions);
        } else if ($this->isNIH()) {
            list($firstName, $lastName) = NameMatcher::splitName($piName, 2);
            $payload = [
                "criteria" => [
                    "pi_names" => [["any_name" => $firstName], ["first_name" => ""], ["any_name" => $lastName], ["last_name" => ""]],
                    "org_names" => $institutions,
                ],
                "include_fields" => $this->includeFields,
            ];
            $location = $this->server."/v1/projects/search";
            $this->runPOSTQuery($location, $payload);
        }
        return $this->getData();
    }

    public function getAssociatedAwardNumbers($awardNo) {
        $this->searchAward($awardNo);
        $awardNumbers = [];
        foreach ($this->getData() as $item) {
            if (isset($item['projectNumber'])) {
                $awardNumbers[] = $item['projectNumber'];
            } else if (isset($item['project_num'])) {
                $awardNumbers[] = $item['project_num'];
            }
        }
        return $awardNumbers;
    }

    private function filterForInstitutionsAndName($name, $institutions) {
        if (method_exists("Application", "getHelperInstitutions")) {
            $helperInstitutions = Application::getHelperInstitutions();
        } else {
            $helperInstitutions = [];
        }
        list($firstName, $lastName) = NameMatcher::splitName($name, 2);
        # dissect current data; must have first name to include
        $pis = [];
        $included = [];
        foreach ($this->getData() as $item) {
            $itemName = $item['contactPi'];
            if (!in_array($itemName, $pis)) {
                $pis[] = $itemName;
            }
            if ($item['otherPis']) {
                $otherPis = preg_split("/\s*;\s*/", $item['otherPis']);
                foreach ($otherPis as $otherPi) {
                    $otherPi = trim($otherPi);
                    if ($otherPi && !in_array($otherPi, $pis)) {
                        $pis[] = $otherPi;
                    }
                }
            }
            $found = false;
            foreach ($pis as $itemName) {
                $itemNames = preg_split("/\s*,\s*/", $itemName);
                // $itemLastName = $itemNames[0];
                if (count($itemNames) > 1) {
                    $itemFirstName = $itemNames[1];
                } else {
                    $itemFirstName = $itemNames[0];
                }
                $listOfFirstNames = preg_split("/\s/", strtoupper($firstName));
                foreach ($institutions as $institution) {
                    foreach ($listOfFirstNames as $myFirstName) {
                        $myFirstName = preg_replace("/^\(/", "", $myFirstName);
                        $myFirstName = preg_replace("/\)$/", "", $myFirstName);
                        if (preg_match("/".strtoupper($myFirstName)."/", $itemFirstName) && (preg_match("/$institution/i", $item['orgName']))) {
                            if (isset($_GET['test'])) {
                                Application::log("Possible match $itemFirstName and $institution vs. '{$item['orgName']}'", $this->pid);
                            }
                            if (in_array($institution, $helperInstitutions)) {
                                $proceed = FALSE;
                                if (method_exists("Application", "getCities")) {
                                    foreach (Application::getCities() as $city) {
                                        if (preg_match("/".$city."/i", $item['orgCity'])) {
                                            $proceed = TRUE;
                                        }
                                    }
                                }
                            } else {
                                $proceed = TRUE;
                                $isVanderbilt = method_exists("Application", "isVanderbilt") && Application::isVanderbilt();
                                if ($isVanderbilt && ((strtoupper($myFirstName) != "HAROLD") && (strtoupper($lastName) == "MOSES") && preg_match("/HAROLD L/i", $myFirstName))) {
                                    # Hack: exclude HAROLD L MOSES since HAROLD MOSES JR is valid
                                    $proceed = FALSE;
                                }
                            }
                            if ($proceed) {
                                if (isset($_GET['test'])) {
                                    Application::log("Including $itemFirstName {$item['orgName']}", $this->pid);
                                }
                                $included[] = $item;
                                $found = true;
                                break;
                            }
                        } else {
                            // echo "Not including $itemFirstName {$item['orgName']}\n";
                        }
                    }
                    if ($found) {
                        break;
                    }
                }
                if ($found) {
                    break;
                }
            }
        }
        if (isset($_GET['test'])) {
            Application::log($this->recordId.": $firstName $lastName included ".count($included), $this->pid);
        }
        // echo "itemNames: ".json_encode($pis)."\n";
        $this->currData = $included;
        return $included;
    }

    private function runGETQuery($location) {
        $currData = [];
        $try = 0;
        $max = 0;   // reset with every new name
        do {
            $try++;
            $url = $location."&offset=".($max + 1);
            list($resp, $output) = REDCapManagement::downloadURL($url, $this->pid);
            if ($resp == 200) {
                $myData = json_decode($output, true);
                $currDataChanged = FALSE;
                if ($myData) {
                    if (isset($myData['items'])) {
                        foreach ($myData['items'] as $item) {
                            $currData[] = $item;
                            $currDataChanged = TRUE;
                        }
                        // echo "Checking {$myData['totalCount']} (".count($myData['items'])." here) and ".($myData['offset'] - 1 + $myData['limit'])."\n";
                    }
                    if (isset($myData['offset']) && isset($myData['limit'])) {
                        $max = $myData['offset'] + $myData['limit'] - 1;
                    } else {
                        $myData = FALSE;
                    }
                    if (!$currDataChanged) {
                        # protect from infinite loops
                        $try++;
                    }
                    $this->sleep();
                } else {
                    $myData = FALSE;
                    $try++;
                }
                if (isset($_GET['test'])) {
                    Application::log("$query $try: Checking {$myData['totalCount']} and {$myData['offset']} and {$myData['limit']}");
                }
            }
        } while (!$myData || (count($currData) < $myData['totalCount']) && ($try <= 5));
        if (isset($_GET['test'])) {
            Application::log($this->recordId.": currData ".count($currData));
        }
        return $currData;
    }

    private function sleep() {
        if ($this->isNIH()) {
            sleep(1);
        } else if ($this->isFederal()) {
            usleep(400000);     // up to 3 per second
        }
    }

    public function getData() {
        return $this->currData;
    }

    private function isNIH() {
        return ($this->category == "NIH");
    }

    private function isFederal() {
        return ($this->category == "Federal");
    }

    private $recordId;
    private $pid;
    private $server;
    private $currData;
    private $category;
    private $includeFields;
}