<?php

namespace Vanderbilt\CareerDevLibrary;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use function Amp\Promise\wait;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

function pullORCIDs(string $token, string $server, $pid, array $recordIds): void {
    $orcids = Download::ORCIDs($token, $server);
    $firstnames = Download::firstnames($token, $server);
    $lastnames = Download::lastnames($token, $server);
    $middlenames = Download::middlenames($token, $server);
    $institutions = Download::institutions($token, $server);
    $metadataFields = Download::metadataFields($token, $server);
    $switches = new FeatureSwitches($token, $server, $pid);
    $blockOrcids = in_array("identifer_block_orcid", $metadataFields) ? Download::oneField($token, $server, "identifier_block_orcid") : [];

    if (in_array("identifier_orcid", $metadataFields)) {
        $excludeList = Download::excludeList($token, $server, "exclude_orcid", $metadataFields);
    } else {
        $excludeList = [];
    }

    $newOrcids = [];
    $messages = [];
    $noMatches = [];
    $multiples = [];
    foreach ($recordIds as $recordId) {
        $blockThisOrcid = (isset($blockOrcids[$recordId]) && ($blockOrcids[$recordId] == "1"));
        if (
            (
                !$orcids[$recordId]
                || !preg_match("/^\d\d\d\d-\d\d\d\d-\d\d\d\d-\d\d\d.$/", $orcids[$recordId])
            )
            && (
                $firstnames[$recordId]
                && $lastnames[$recordId]
            )
            && !$blockThisOrcid
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

        if (!$blockThisOrcid && ($switches->isOnForProject("Full ORCID Profiles"))) {
            updateORCIDProfileData($pid, $recordId, $orcids[$recordId] ?? "", $excludeList);
        }
    }

    $upload = [];
    if (!in_array("identifier_orcid", $metadataFields)) {
        foreach ($newOrcids as $recordId => $orcid) {
            if (!in_array($orcid, $excludeList[$recordId] ?? [])) {
                $upload[] = [
                    "record_id" => $recordId, 
                    "identifier_orcid" => $orcid
                ];
            }
        }
    }

    if (!empty($upload)) {
        Application::log("ORCID Upload: ".count($upload)." new rows");
        // Upload::rows($upload, $token, $server);
    }
    CareerDev::saveCurrentDate("Last ORCID Download", $pid);
    if (!empty($noMatches)) {
        Application::log("Could not find matches for records: ".REDCapManagement::json_encode_with_spaces($noMatches));
    }
    if (!empty($messages)) {
        throw new \Exception(count($messages)." messages: ".implode("; ", $messages));
    }
}

function updateORCIDProfileData($pid, string $recordId, string $recordORCIDs, array $excludeList): void {
    $usedORCIDs = preg_split("/\s*[,;]\s*/", $recordORCIDs, -1, PREG_SPLIT_NO_EMPTY);
    $orcidData = [];
    foreach ($usedORCIDs as $orcid) {
        if (!in_array($orcid, $excludeList)) {
            $addtlOrcidDetails = ORCID::downloadORCIDProfile($orcid, $pid);
            foreach ($addtlOrcidDetails as $endpoint => $data) {
                $orcidData[$endpoint] = array_merge($orcidData[$endpoint] ?? [], $data);
            }
        }
    }
    # PREG_SPLIT_NO_EMPTY prevents an empty string in the array
    $upload = flattenORCIDArray($orcidData, ORCID::PROFILE_PREFIX, $pid, $recordId, $usedORCIDs);
    $metadataFields = Download::metadataFieldsByPid($pid);
    foreach ($upload as $i => $row) {
        $upload[$i] = REDCapManagement::filterForREDCap($row, $metadataFields);
    }
    if(!empty($upload)) {
        foreach ($upload as $row) {
            echo "To upload for $recordId: ".REDCapManagement::json_encode_with_spaces($row)."<br/>";   // TODO
        }
        // TODO Upload::rowsByPid($upload, $pid);
    }
}

function flattenORCIDArray(array $data, string $prefix, $pid, string $recordId, array $usedORCIDs): array {
    $combinedResults = [];
    foreach ($data as $endpoint => $keyData) {
        $endpoint = ORCID::encodeKey($endpoint);
        $newInstrument = $prefix . $endpoint;
        if (array_keys($keyData) === range(0, count($keyData) - 1)) {
            $testField = FALSE;
            if (!empty($keyData)) {
                $firstIndex = array_keys($keyData)[0];
                foreach ($keyData[$firstIndex] as $key => $value) {
                    if ($key != "id") {
                        $testField = $key;
                        break;
                    }
                }
            }
            $priorTestValues = Download::oneFieldForRecordByPid($pid, $newInstrument."_".$testField, $recordId);
            if ($priorTestValues === "") {
                $priorTestValues = [];
            } else if (!is_array($priorTestValues)) {
                $priorTestValues = [1 => $priorTestValues];
            }
            if (empty($priorTestValues)) {
                $instance = 0;
            } else {
                $instance = max(array_keys($priorTestValues));
            }
            foreach ($keyData as $values) {
                if (
                    $testField
                    && !in_array($values[$testField], array_values($priorTestValues))
                    && isset($values["id"])
                    && in_array($values["id"], $usedORCIDs)
                ) {
                    $instance++;
                    $instrumentStarterRow = [
                        "record_id" => $recordId,
                        "redcap_repeat_instrument" => $newInstrument,
                        "redcap_repeat_instance" => $instance,
                        $newInstrument."_id" => $values['id'],
                        $newInstrument."_last_update" => date("Y-m-d"),
                        $newInstrument."_complete" => "2",
                    ];
                    $currRow = $instrumentStarterRow;
                    foreach ($values as $key => $value) {
                        if ($key != "id") {
                        // if (isset($value) && ($key != "id")) {
                            $key = ORCID::encodeKey($key);
                            $currRow[$newInstrument."_".$key] = $value;
                        }
                    }
                    if (count($currRow) > count($instrumentStarterRow)) {
                        $combinedResults[] = $currRow;
                    }
                }
            }
        }
    }
    return $combinedResults;
}