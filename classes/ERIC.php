<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class ERIC {
    public static function isRecordEnabled($recordId, $token, $server, $pid) {
        $switches = new FeatureSwitches($token, $server, $pid);
        if ($switches->isOnForProject("ERIC (Education Publications)")) {
            return TRUE;
        }
        $recordsToRun = $switches->getRecordsTurnedOn("ERIC (Education Publications)");
        return in_array($recordId, $recordsToRun);
    }

    public static function getFields($metadata) {
        $instrument = "eric";
        $prefix = "eric_";
        $ericREDCapFields = DataDictionaryManagement::getFieldsFromMetadata($metadata, $instrument);
        if (empty($ericREDCapFields)) {
            return [];
        }
        return DataDictionaryManagement::removePrefix($ericREDCapFields, $prefix);
    }

    public static function makeURL($metadata, $search, $maxRowCount, $start) {
        $ericFields = self::getFields($metadata);
        $url = "https://api.ies.ed.gov/eric?search=$search&format=json&rows=$maxRowCount&fields=".implode(",", $ericFields)."&start=$start";
        return Sanitizer::sanitizeURL($url);
    }

    public static function process($docs, $metadata, $recordId, $listOfPriorIds, $listOfPriorTitles, &$startInstance) {
        $ericFields = self::getFields($metadata);
        $prefix = "eric_";
        $instrument = "eric";
        $upload = [];
        foreach ($docs as $entry) {
            $id = $entry["id"];
            $title = $entry["title"];
            if (!in_array($id, $listOfPriorIds) && !in_array($title, $listOfPriorTitles)) {
                $listOfPriorIds[] = $id;
                $listOfPriorTitles[] = $title;
                $startInstance++;
                $uploadRow = [
                    "record_id" => $recordId,
                    "redcap_repeat_instrument" => $instrument,
                    "redcap_repeat_instance" => $startInstance,
                    $instrument."_complete" => "2",
                    $prefix."include" => "",
                    $prefix."last_update" => date("Y-m-d"),
                    $prefix."link" => "https://eric.ed.gov/?id=$id",
                ];
                if (in_array($prefix."created", $ericFields)) {
                    $uploadRow[$prefix."created"] = date("Y-m-d");
                }
                $hasData = FALSE;
                foreach ($ericFields as $ericField) {
                    $value = $entry[$ericField] ?? "";
                    if ($ericField == "e_fulltextauth") {
                        if ($value) {
                            $link = "https://files.eric.ed.gov/fulltext/$id.pdf";
                            $uploadRow[$prefix."e_fulltext"] = $link;
                            $hasData = TRUE;
                        }
                    } else {
                        $redcapField = $prefix.$ericField;
                        if ($value == "T") {
                            $value = "1";
                        } else if ($value == "F") {
                            $value = "0";
                        } else if (is_array($value)) {
                            $value = implode("; ", $value);
                        } else {
                            $value = html_entity_decode((string) $value);
                        }
                        if ($value) {
                            $uploadRow[$redcapField] = $value;
                            $hasData = TRUE;
                        }
                    }
                }
                if ($hasData) {
                    $upload[] = $uploadRow;
                }
            }
        }
        return $upload;
    }
}