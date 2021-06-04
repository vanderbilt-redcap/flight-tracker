<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\Links;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

define("NO_MATCHES", "NO_MATCHES");
define("MORE_THAN_ONE", "MORE_THAN_ONE");
define("ORCID_DELIM", "|");

function pullORCIDs($token, $server, $pid, $recordIds) {
    $orcids = Download::ORCIDs($token, $server);
    $firstnames = Download::firstnames($token, $server);
    $lastnames = Download::lastnames($token, $server);
    $institutions = Download::institutions($token, $server);
    $metadata = Download::metadata($token, $server);
    $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);

    $newOrcids = array();
    $messages = array();
    $noMatches = array();
    $multiples = array();
    foreach ($recordIds as $recordId) {
        if ((!$orcids[$recordId]
            || !preg_match("/^\d\d\d\d-\d\d\d\d-\d\d\d\d-\d\d\d.$/", $orcids[$recordId])
        ) && ($firstnames[$recordId] && $lastnames[$recordId])) {
            list($orcid, $mssg) = downloadORCID($recordId, $firstnames[$recordId], $lastnames[$recordId], $institutions[$recordId]);
            if ($ary = isCodedMessage($mssg)) {
                foreach ($ary as $recordId => $value) {
                    if ($value == $recordId) {
                        # no match
                        array_push($noMatches, $recordId);
                    } else if ($orcidAry = json_decode($value, TRUE)) {
                        # multi-match
                        $multiples[$recordId] = $orcidAry;
                    } else {
                        array_push($messages, "Could not decipher $recordId: $value! This should never happen.");
                    }
                }
            } else if ($mssg) {
                array_push($messages, $mssg);
            } else if ($orcid) {
                $newOrcids[$recordId] = $orcid;
            }
        }
    }

    if (in_array("identifier_orcid", $metadataFields)) {
        $excludeList = Download::excludeList($token, $server, "exclude_orcid", $metadata);
        $upload = [];
        foreach ($newOrcids as $recordId => $orcid) {
            if (!in_array($orcid, $excludeList[$recordId])) {
                $upload[] = ["record_id" => $recordId, "identifier_orcid" => $orcid];
            }
        }
    }

    if (!empty($upload)) {
        Application::log("ORCID Upload: ".count($upload)." new rows");
        $feedback = Upload::rows($upload, $token, $server);
        Application::log("ORCID Upload: ".json_encode($feedback));
    }
    if (function_exists("saveCurrentDate")) {
        saveCurrentDate("Last ORCID Download", $pid);
    } else {
        CareerDev::saveCurrentDate("Last ORCID Download", $pid);
    }
    if (!empty($noMatches)) {
        Application::log("Could not find matches for records: ".REDCapManagement::json_encode_with_spaces($noMatches));
    }
    if (countNewMultiples($multiples, $pid) > 0) {
        # send email
        $adminEmail = Application::getSetting("admin_email", $pid);
        $html = makeORCIDsEmail($multiples, $firstnames, $lastnames, $pid, $metadata);

        require_once(dirname(__FILE__)."/../../../redcap_connect.php");
        \REDCap::email($adminEmail, Application::getSetting("default_from", $pid), CareerDev::getProgramName().": Multiple ORCIDs Found", $html);
    }
    if (!empty($messages)) {
        throw new \Exception(count($messages)." messages: ".implode("; ", $messages));
    }
}

function countNewMultiples($multiples, $pid) {
    $priorMultiples = Application::getSetting("prior_orcids", $pid);
    if (!$priorMultiples) {
        $priorMultiples = array();
    }
    $newMultiples = 0;
    foreach ($multiples as $recordId => $recordORCIDs) {
        if (!isset($priorMultiples[$recordId])) {
            $priorMultiples[$recordId] = array();
        }
        if (count($recordORCIDs) > count($priorMultiples[$recordId])) {
            $newMultiples++;
        }
    }
    return $newMultiples;
}

function makeORCIDsEmail($multiples, $firstnames, $lastnames, $pid, $metadata) {
    $orcidThreshold = 6;
    $orcidSearchLink = "https://orcid.org/orcid-search/search";
    $priorMultiples = Application::getSetting("prior_orcids", $pid);
    if (!$priorMultiples) {
        $priorMultiples = array();
    }

    $html = "";
    $html .= "<h1>Multiple ORCIDs Found</h1>\n";
    $html .= "<h3>".Links::makeProjectHomeLink($pid, "REDCap Project")."</h3>";
    $html .= "<h3>".Links::makeLink($orcidSearchLink, $orcidSearchLink)."</h3>";
    $html .= "<h4>Please insert the proper ORCID on the identifiers form. Click on the name to take you to the REDCap record. Links are available for all new ORCIDs.</h4>\n";
    foreach ($multiples as $recordId => $recordORCIDs) {
        if (!isset($priorMultiples[$recordId])) {
            $priorMultiples[$recordId] = array();
        }
        if (count($recordORCIDs) > count($priorMultiples[$recordId])) {
            $name = $firstnames[$recordId] . " " . $lastnames[$recordId];
            $name = Links::makeIdentifiersLink($pid, $recordId, Application::getSetting("event_id", $pid), $name);
            $orcidLinks = array();
            foreach ($recordORCIDs as $orcid) {
                $url = "https://orcid.org/" . $orcid;
                if (in_array($orcid, $priorMultiples[$recordId])) {
                    $tag = "";
                } else {
                    $tag = " (new)";
                }
                array_push($orcidLinks, Links::makeLink($url, $orcid).$tag);
            }
            $priorMultiples[$recordId] = $recordORCIDs;
            if (count($orcidLinks) <= $orcidThreshold) {
                $html .= "<p>$name has " . count($recordORCIDs) . " possible ORCIDs: " . implode(", ", $orcidLinks) . "</p>\n";
            } else {
                $html .= "<p>$name has " . count($recordORCIDs) . " possible ORCIDs: " . Links::makeLink($orcidSearchLink, $orcidSearchLink) . "</p>\n";
            }
        }
    }
    CareerDev::setSetting("prior_orcids", $priorMultiples, $pid);
    $html .= "<h3>".Links::makeLink($orcidSearchLink, $orcidSearchLink)."</h3>";
    return $html;
}

# returns list($orcid, $message)
function downloadORCID($recordId, $first, $last, $institutionList) {
    $delim = ORCID_DELIM;
    $identifier = "$recordId: $first $last $institutionList";
    if (!$last) {
        return array(FALSE, "No last name for $identifier!");
    }
    $params = array();
    $params["family-name"] = NameMatcher::explodeLastName($last);
    if ($first) {
        $params["given-names"] = NameMatcher::explodeFirstName($first);
    }
    $params["affiliation-org-name"] = getInstitutionArray(preg_split("/\s*[,\/]\s*/", $institutionList));
    // Test server $baseUrl = "https://pub.sandbox.orcid.org/v3.0/search/";
    $baseUrl = "https://pub.orcid.org/v3.0/search/";
    $q = makeQueryFromParams($params);
    if ($q) {
        $url = $baseUrl . "?q=" . $q;
        $options = fetchAndParseURL($url);
        if (is_string($options)) {
            $mssg = $options;
            Application::log($mssg);
            return array(FALSE, $mssg);
        }

        if (count($options) == 0) {
            unset($params["affiliation-org-name"]);
            Application::log("Searching name '$first $last' without institution");
            $q = makeQueryFromParams($params);
            if ($q) {
                $url = $baseUrl . "?q=" . $q;
                $options = fetchAndParseURL($url);
                if (is_string($options)) {
                    $mssg = $options;
                    Application::log($mssg);
                    return array(FALSE, $mssg);
                }
            }
        }

        $numOptions = count($options);
        if ($numOptions == 0) {
            $mssg = NO_MATCHES."$delim$recordId";
            Application::log($mssg);
            return array(FALSE, $mssg);
        } else if ($numOptions > 1) {
            $orcids = array();
            foreach ($options as $result) {
                if ($result["path"]) {
                    array_push($orcids, $result["path"]);
                }
            }
            $mssg = MORE_THAN_ONE."$delim$recordId$delim".json_encode($orcids);
            Application::log($mssg);
            return array(FALSE, $mssg);
        } else {
            $orcid = $options[0]["path"];
            Application::log("************** Returning $orcid");
            return array($orcid, "");
        }
    } else {
        return array(FALSE, "No parameters available for $identifier");
    }
}

# returns array or FALSE
function isCodedMessage($mssg) {
    $delim = ORCID_DELIM;
    $valid = array(MORE_THAN_ONE, NO_MATCHES);
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

function getInstitutionArray($additionalInstitutions) {
    $institutions = Application::getInstitutions();
    foreach ($additionalInstitutions as $institution) {
        if ($institution && !in_array($institution, $institutions)) {
            array_push($institutions, formatInstitutionName($institution));
        }
    }
    return $institutions;
}

function formatInstitutionName($inst) {
    $inst = str_replace("&", "", $inst);
    $inst = preg_replace("/\s\s+/", " ", $inst);
    return $inst;
}

# returns string if an error; else returns an array
function fetchAndParseURL($url) {
    $i = 0;
    $numRetries = 3;
    $xml = NULL;
    do {
        usleep(300000);
        if (function_exists("downloadURL")) {
            list($resp, $output) = downloadURL($url);
        } else {
            list($resp, $output) = \Vanderbilt\FlightTrackerExternalModule\downloadURL($url);
        }
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
            array_push($options, $result);
        }
    }
    return $options;

}

function replaceWhitespaceWithPlus($str) {
    return preg_replace("/\s+/", "+", $str);
}

function makeQueryFromParams($params) {
    $queryStrings = array();
    foreach ($params as $key => $value) {
        if (is_array($value)) {
            $values = array();
            foreach ($value as $v) {
                array_push($values, $v);
            }
            if (count($values) > 1) {
                array_push($queryStrings, $key . ":" . "(" . implode(" OR ", $values) . ")");
            } else {
                $value = $values[0];
                array_push($queryStrings, $key . ":" . $value);
            }
        } else if ($value) {
            array_push($queryStrings, $key . ":" . $value);
        }
    }
    return urlencode(implode(" AND ", $queryStrings));
}

