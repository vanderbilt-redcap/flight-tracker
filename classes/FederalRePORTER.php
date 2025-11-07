<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class FederalRePORTER {
    public static function searchAward($awardNo, $pid, $recordId) {
        $query = "/v1/projects/search?query=projectNumber:*".urlencode($awardNo)."*";
        return self::getData($query, $pid, $recordId);
    }

    public static function searchPI($piName, $pid, $recordId, $institutions) {
        $query = "/v1/projects/search?query=PiName:".urlencode($piName);
        $currData = self::getData($query, $pid, $recordId);
        if ($currData) {
            return self::filterForInstitutionsAndName($currData, $institutions, $pid, $recordId, $piName);
        }
        return [];
    }

    public static function getAssociatedAwardNumbers($awardNo, $pid, $recordId) {
        $data = self::searchAward($awardNo, $pid, $recordId);
        $awardNumbers = [];
        foreach ($data as $item) {
            $awardNumbers[] = $item['projectNumber'];
        }
        return $awardNumbers;
    }

    private static function filterForInstitutionsAndName($currData, $institutions, $pid, $recordId, $name) {
        if (method_exists("\Vanderbilt\CareerDevLibrary\Application", "getHelperInstitutions")) {
            $helperInstitutions = Application::getHelperInstitutions($pid);
        } else {
            $helperInstitutions = [];
        }
        list($firstName, $lastName) = NameMatcher::splitName($name, 2);
        # dissect current data; must have first name to include
        $pis = [];
        $included = [];
        foreach ($currData as $item) {
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
                                Application::log("Possible match $itemFirstName and $institution vs. '{$item['orgName']}'", $pid);
                            }
                            if (in_array($institution, $helperInstitutions)) {
                                $proceed = FALSE;
                                if (method_exists("\Vanderbilt\CareerDevLibrary\Application", "getCities")) {
                                    foreach (Application::getCities() as $city) {
                                        if (preg_match("/".$city."/i", $item['orgCity'])) {
                                            $proceed = TRUE;
                                        }
                                    }
                                }
                            } else {
                                $proceed = TRUE;
                                $isVanderbilt = method_exists("\Vanderbilt\CareerDevLibrary\Application", "isVanderbilt") && Application::isVanderbilt();
                                if ($isVanderbilt && ((strtoupper($myFirstName) != "HAROLD") && (strtoupper($lastName) == "MOSES") && preg_match("/HAROLD L/i", $myFirstName))) {
                                    # Hack: exclude HAROLD L MOSES since HAROLD MOSES JR is valid
                                    $proceed = FALSE;
                                }
                            }
                            if ($proceed) {
                                if (isset($_GET['test'])) {
                                    Application::log("Including $itemFirstName {$item['orgName']}", $pid);
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
            Application::log("$recordId: $firstName $lastName included ".count($included), $pid);
        }
        // echo "itemNames: ".json_encode($pis)."\n";
        return $included;
    }

    private static function getData($query, $pid, $recordId) {
        $currData = array();
        $try = 0;
        $max = 0;   // reset with every new name
        $myData = NULL;
        do {
            if (isset($myData) && $myData && isset($myData['offset']) && $myData['offset'] == 0) {
                $try++;
            } else {
                $try = 0;
            }
            $url = "https://api.federalreporter.nih.gov".$query."&offset=".($max + 1);
            list($resp, $output) = REDCapManagement::downloadURL($url, $pid);
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
                usleep(400000);     // up to 3 per second
            } else {
                $myData = FALSE;
                $try++;
            }
            if (isset($_GET['test']) && $myData) {
                Application::log("$query $try: Checking {$myData['totalCount']} and {$myData['offset']} and {$myData['limit']}");
            }
        } while (!$myData || (count($currData) < $myData['totalCount']) && ($try <= 5));
        if (isset($_GET['test'])) {
            Application::log("$recordId: currData ".count($currData));
        }
        return $currData;
    }
}