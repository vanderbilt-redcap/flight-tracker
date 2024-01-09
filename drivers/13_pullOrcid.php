<?php

namespace Vanderbilt\CareerDevLibrary;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

function pullORCIDs($token, $server, $pid, $recordIds) {
    $orcids = Download::ORCIDs($token, $server);
    $firstnames = Download::firstnames($token, $server);
    $lastnames = Download::lastnames($token, $server);
    $middlenames = Download::middlenames($token, $server);
    $institutions = Download::institutions($token, $server);
    $metadataFields = Download::metadataFields($token, $server);
    $blockOrcids = in_array("identifer_block_orcid", $metadataFields) ? Download::oneField($token, $server, "identifier_block_orcid") : [];

    $newOrcids = [];
    $messages = [];
    $noMatches = [];
    $multiples = [];
    foreach ($recordIds as $recordId) {
        $blockThisOrcid = (isset($blockOrcids[$recordId]) && ($blockOrcids[$recordId] == "1"));
        if (
            (!$orcids[$recordId]
            || !preg_match("/^\d\d\d\d-\d\d\d\d-\d\d\d\d-\d\d\d.$/", $orcids[$recordId])
        ) && ($firstnames[$recordId] && $lastnames[$recordId])
            && (!$blockThisOrcid)
        ) {
            list($orcid, $mssg) = ORCID::downloadORCID($recordId, $firstnames[$recordId], $middlenames[$recordId], $lastnames[$recordId], $institutions[$recordId], $pid);
            if ($ary = ORCID::isCodedMessage($mssg)) {
                foreach ($ary as $recordId => $value) {
                    if ($value == $recordId) {
                        # no match
                        $noMatches[] = $recordId;
                    } else if ($orcidAry = json_decode($value, TRUE)) {
                        # multi-match
                        $multiples[$recordId] = $orcidAry;
                    } else {
                        $messages[] = "Could not decipher $recordId: $value! This should never happen.";
                    }
                }
            } else if ($mssg) {
                $messages[] = $mssg;
            } else if ($orcid) {
                $newOrcids[$recordId] = $orcid;
            }
        }
    }

    if (in_array("identifier_orcid", $metadataFields)) {
        $excludeList = Download::excludeList($token, $server, "exclude_orcid", $metadataFields);
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
    CareerDev::saveCurrentDate("Last ORCID Download", $pid);
    if (!empty($noMatches)) {
        Application::log("Could not find matches for records: ".REDCapManagement::json_encode_with_spaces($noMatches));
    }
    # 2023-12-26: Disabling for now and replacing with the ORCID Wrangler.
    # I don't think this was being used much. I'm preserving the code in case it's helpful in the future.
    # It may be helpful to remove after 3-6 months for readability.
    // if (countNewMultiples($multiples, $pid) > 0) {
        # send email
        // $adminEmail = Application::getSetting("admin_email", $pid);
        // $html = makeORCIDsEmail($multiples, $firstnames, $lastnames, $pid);

        // if (preg_match("/possible ORCIDs/", $html)) {
            // require_once(dirname(__FILE__) . "/../../../redcap_connect.php");
            // \REDCap::email($adminEmail, Application::getSetting("default_from", $pid), CareerDev::getProgramName() . ": Multiple ORCIDs Found", $html);
        // }
    // }
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

function makeORCIDsEmail($multiples, $firstnames, $lastnames, $pid) {
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
    $html .= "<p>ORCIDs (<a href='https://www.orcid.org'>www.orcid.org</a>) are unique identifiers that can be used to match a scholar with a publication. They avoid the name-matching problem, and they allow Flight Tracker to skip the Publication Wrangling process. Flight Tracker tries to pull an ORCID identifier from the ORCID website, but some scholars match more than one ID. Below is a list of these scholars. When you have time, do you mind seeing if you can identify which, if any, ORCID is for your scholar and fill that in on the Identifiers form on their REDCap record? That should help you avoid the step of Publication Wrangling as often.</p>";
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
                $orcidLinks[] = Links::makeLink($url, $orcid) . $tag;
            }
            $priorMultiples[$recordId] = $recordORCIDs;
            if (count($orcidLinks) <= $orcidThreshold) {
                $html .= "<p>$name has " . count($recordORCIDs) . " possible ORCIDs: " . implode(", ", $orcidLinks) . "</p>\n";
            } else {
                $html .= "<p>$name has " . count($recordORCIDs) . " possible ORCIDs: " . Links::makeLink($orcidSearchLink, $orcidSearchLink) . "</p>\n";
            }
        }
    }
    CareerDev::saveSetting("prior_orcids", $priorMultiples, $pid);
    $html .= "<h3>".Links::makeLink($orcidSearchLink, $orcidSearchLink)."</h3>";
    return $html;
}