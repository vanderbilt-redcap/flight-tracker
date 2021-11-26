<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/ClassLoader.php");

class FTStats {
    public static function getItemsToBeTotaled() {
        return [
            "Number of Scholars Currently Tracked (Newman)",
            "Number of Scholars Currently Tracked (Other Vanderbilt)",
            "Number of Scholars Currently Tracked (Outside)",
        ];
    }

    public static function getRecordIds($token, $server) {
        $data = array(
            'token' => $token,
            'content' => 'record',
            'format' => 'json',
            'type' => 'flat',
            'csvDelimiter' => '',
            'fields' => ['record_id'],
            'rawOrLabel' => 'raw',
            'rawOrLabelHeaders' => 'raw',
            'exportCheckboxLabel' => 'false',
            'exportSurveyFields' => 'false',
            'exportDataAccessGroups' => 'false',
            'returnFormat' => 'json'
        );
        return self::sendToServer($server, $data);
    }

    public static function sendToServer($server, $data) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $server);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_VERBOSE, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
        $output = curl_exec($ch);
        curl_close($ch);
        $results = (is_string($output) && ($output !== "")) ? json_decode($output, TRUE) : [];
        if (isset($results['error'])) {
            throw new \Exception($results['error']);
        }
        return $results;
    }

    public static function getAllData($token, $server) {
        $data = array(
            'token' => $token,
            'content' => 'record',
            'format' => 'json',
            'type' => 'flat',
            'csvDelimiter' => '',
            'rawOrLabel' => 'raw',
            'rawOrLabelHeaders' => 'raw',
            'exportCheckboxLabel' => 'false',
            'exportSurveyFields' => 'false',
            'exportDataAccessGroups' => 'false',
            'returnFormat' => 'json'
        );
        return self::sendToServer($server, $data);
    }

    public static function gatherStats($redcapData, $server, $ts, $includeNewman = TRUE) {
        $uniqueCounts = [
            "Total Number of Reports" => ["record_id"],
            "Total Number of Projects Ever" => ["pid", "server"],
            "Total Number of Servers Ever" => ["server"],
            "Total Number of Weeks of Reporting" => ["date"],
        ];
        $mostRecentCounts = [];
        if ($includeNewman) {
            $mostRecentCounts["Number of Scholars Currently Tracked (Newman)"] = ["newman"];
        }
        $mostRecentCounts["Number of Scholars Currently Tracked (Other Vanderbilt)"] = ["num_scholars", "server=vanderbilt.edu"];
        $mostRecentCounts["Number of Scholars Currently Tracked (Outside)"] = ["num_scholars"];
        $mostRecentCounts["Number of Projects Currently Active"] = ["pid", "server"];
        $mostRecentCounts["Number of Servers Currently Active"] = ["server"];
//    $mostRecentCounts["Number of Institutions Currently Active"] = ["institution"];
        $totalLabels = self::getItemsToBeTotaled();

        $separator = "|";
        $lastRunYMD = self::getLastSaturdayDate($ts);
        $gatherHistoricalData = ($lastRunYMD != self::getLastSaturdayDate(time()));
        $values = array();
        foreach ($uniqueCounts as $label => $fields) {
            $values[$label] = array();
            foreach ($redcapData as $row) {
                if (in_array($label, $totalLabels)) {
                    $value = self::processValueForFields($fields, $row);
                    if ($value) {
                        $values[$label][] = $value;
                    }
                } else {
                    $entryValues = array();
                    foreach ($fields as $field) {
                        if ($field)
                            array_push($entryValues, $row[$field]);
                    }
                    $entryValue = implode($separator, $entryValues);
                    if (!in_array($entryValue, $values[$label])) {
                        array_push($values[$label], $entryValue);
                    }
                }
            }
        }
        foreach ($mostRecentCounts as $label => $fields) {
            $seen = [];
            $values[$label] = [];
            if ($fields[0] == "newman") {
                $newmanRecordIds = self::getRecordIds(NEWMAN_TOKEN, $server);
                $values[$label][] = count($newmanRecordIds);
            } else {
                foreach ($redcapData as $row) {
                    $id = self::makeId($row);    // de-duplicate
                    if (($row['date'] == $lastRunYMD) && !in_array($id, $seen)) {
                        $seen[] = $id;
                        if ($gatherHistoricalData || self::isLatestRowForProject($row['record_id'], $row['pid'], $row['server'], $redcapData)) {
                            if (in_array($label, $totalLabels)) {
                                $value = self::processValueForFields($fields, $row);
                                if ($value) {
                                    $values[$label][] = $value;
                                }
                            } else {
                                $entryValues = [];
                                foreach ($fields as $field) {
                                    $entryValues[] = $row[$field];
                                }
                                $entryValue = implode($separator, $entryValues);
                                if (!in_array($entryValue, $values[$label])) {
                                    $values[$label][] = $entryValue;
                                }
                            }
                        }
                    }
                }
            }
        }
        return $values;
    }

    public static function makeId($row) {
        $sep = ":";
        return $row['pid'].$sep.$row['server'].$sep.$row['date'];
    }

    public static function getLastSaturdayDate($ts = NULL) {
        if (!$ts) {
            $ts = time();
        }
        while (date("w", $ts) != 6) {
            $ts -= 24 * 3600;
        }
        return date("Y-m-d", $ts);
    }

    public static function processValueForFields($fields, $row) {
        $primaryField = $fields[0];
        $stipulationsValid = TRUE;
        for ($i = 1; $i < count($fields); $i++) {
            if (preg_match("/=/", $fields[$i])) {
                list($stipulationField, $stipulationValue) = explode("=", $fields[$i]);
                if (!preg_match("/$stipulationValue/", $row[$stipulationField]))  {
                    $stipulationsValid = FALSE;
                }
            }
        }
        if ($stipulationsValid) {
            return $row[$primaryField];
        }
        return FALSE;
    }

    public static function isLatestRowForProject($recordId, $pid, $server, $allREDCapData) {
        $latestRecordId = 0;
        foreach ($allREDCapData as $row) {
            if (($row['pid'] == $pid) &&
                ($row['server'] == $server) &&
                ($row['record_id'] > $latestRecordId))
            {
                $latestRecordId = $row['record_id'];
            }
        }
        return ($recordId == $latestRecordId);
    }
}