<?php


namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

# See https://info.orcid.org/documentation/features/public-api/
class ORCID {
    public const NO_MATCHES = "NO_MATCHES";
    public const MORE_THAN_ONE = "MORE_THAN_ONE";
    public const ORCID_DELIM = "|";

    # returns list($orcid, $message)
    public static function downloadORCID($recordId, $first, $middle, $last, $institutionList, $pid) {
        $delim = self::ORCID_DELIM;
        $identifier = "$recordId: $first $last $institutionList";
        if (!$last) {
            return array(FALSE, "No last name for $identifier!");
        }
        $params = array();
        $params["family-name"] = NameMatcher::explodeLastName($last);
        if ($first) {
            $params["given-names"] = NameMatcher::explodeFirstName($first, $middle);
        }
        $params["affiliation-org-name"] = self::getInstitutionArray(preg_split("/\s*[,\/]\s*/", $institutionList));
        // Test server $baseUrl = "https://pub.sandbox.orcid.org/v3.0/search/";
        $baseUrl = "https://pub.orcid.org/v3.0/search/";
        $q = self::makeQueryFromParams($params);
        if ($q) {
            $url = $baseUrl . "?q=" . $q;
            $options = self::fetchAndParseURL($url);
            if (is_string($options)) {
                $mssg = $options;
                Application::log($mssg, $pid);
                return array(FALSE, $mssg);
            }

            if (count($options) == 0) {
                unset($params["affiliation-org-name"]);
                Application::log("Searching name '$first $last' without institution");
                $q = self::makeQueryFromParams($params);
                if ($q) {
                    $url = $baseUrl . "?q=" . $q;
                    $options = self::fetchAndParseURL($url);
                    if (is_string($options)) {
                        $mssg = $options;
                        Application::log($mssg, $pid);
                        return array(FALSE, $mssg);
                    }
                }
            }

            $numOptions = count($options);
            if ($numOptions == 0) {
                $mssg = self::NO_MATCHES."$delim$recordId";
                Application::log($mssg, $pid);
                return array(FALSE, $mssg);
            } else if ($numOptions > 1) {
                $orcids = array();
                foreach ($options as $result) {
                    if ($result["path"]) {
                        $orcids[] = $result["path"];
                    }
                }
                $mssg = self::MORE_THAN_ONE."$delim$recordId$delim".json_encode($orcids);
                Application::log($mssg, $pid);
                return array(FALSE, $mssg);
            } else {
                $orcid = $options[0]["path"];
                Application::log("************** Returning $orcid", $pid);
                return array($orcid, "");
            }
        } else {
            return array(FALSE, "No parameters available for $identifier");
        }
    }

    # returns array or FALSE
    public static function isCodedMessage($mssg) {
        $delim = self::ORCID_DELIM;
        $valid = array(self::MORE_THAN_ONE, self::NO_MATCHES);
        foreach ($valid as $beginning) {
            $regex = "/^".$beginning."/";
            if (preg_match($regex, $mssg)) {
                $nodes = explode($delim, $mssg);
                if (count($nodes) == 1) {
                    return FALSE;
                } else if (count($nodes) == 2) {
                    return array($nodes[1] => $nodes[1]);
                } else if (count($nodes) == 3) {
                    return array($nodes[1] => $nodes[2]);
                } else {
                    throw new \Exception("Could not decode $mssg! This should never happen.");
                }
            }
        }
        return FALSE;
    }

    # returns string if an error; else returns an array
    private static function fetchAndParseURL($url) {
        $i = 0;
        $numRetries = 3;
        $xml = NULL;
        do {
            usleep(300000);

            list($resp, $output) = URLManagement::downloadURL($url);
            if ($resp == 200) {
                $xml = simplexml_load_string(utf8_encode($output));
            }
            $i++;
        } while ((!$xml || ($resp != 200)) && ($numRetries > $i));
        if ($resp != 200) {
            $mssg = "Could not contact $url; response $resp";
            return $mssg;
        } else if (!$xml) {
            $mssg = "Could not contact $url; could not parse $output";
            return $mssg;
        }

        $searchNs = "http://www.orcid.org/ns/search";
        $commonNs = "http://www.orcid.org/ns/common";
        $options = array();
        foreach ($xml->children($searchNs) as $result) {
            foreach ($result->children($commonNs) as $child) {
                $result = array();
                foreach ($child->children($commonNs) as $grandChild) {
                    $result[$grandChild->getName()] = strval($grandChild);
                }
                $options[] = $result;
            }
        }
        return $options;

    }

    private static function makeQueryFromParams($params) {
        $queryStrings = array();
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $values = array();
                foreach ($value as $v) {
                    if ($v) {
                        $values[] = $v;
                    }
                }
                if (count($values) > 1) {
                    $queryStrings[] = $key . ":" . "(" . implode(" OR ", $values) . ")";
                } else {
                    $value = $values[0];
                    $queryStrings[] = $key . ":" . $value;
                }
            } else if ($value) {
                $queryStrings[] = $key . ":" . $value;
            }
        }
        return urlencode(implode(" AND ", $queryStrings));
    }

    private static function getInstitutionArray($additionalInstitutions) {
        $institutions = Application::getInstitutions();
        foreach ($additionalInstitutions as $institution) {
            if ($institution && !in_array($institution, $institutions)) {
                $institutions[] = self::formatInstitutionName($institution);
            }
        }
        return $institutions;
    }

    private static function formatInstitutionName($inst) {
        $inst = str_replace("&", "", $inst);
        $inst = preg_replace("/\s\s+/", " ", $inst);
        return $inst;
    }
}
