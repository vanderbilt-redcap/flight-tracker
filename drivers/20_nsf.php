<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

function getNSFGrants($token, $server, $pid, $records) {
    if (empty($records)) {
        $records = Download::recordIds($token, $server);
    }

    $generalInstitutions = array_unique(array_merge(Application::getInstitutions($pid), Application::getHelperInstitutions()));
    $printFields = getNSFFields();
    $recordMatches = [];
    $firstNames = Download::firstnames($token, $server);
    // $middleNames = Download::middlenames($token, $server);
    $lastNames = Download::lastnames($token, $server);
    $urlBase = "https://api.nsf.gov/services/v1/awards.json";
    $errors = [];
    $maxTries = 4;
    $maxConsecutiveFailures = 50;

    $numIndividualAwards = 0;
    $institutions = Download::institutionsAsArray($token, $server);
    $numConsecutiveFailures = 0;
    foreach ($records as $recordId) {
        $customInstitutions = $institutions[$recordId] ?? [];
        $recordInstitutions = array_unique(array_merge($generalInstitutions, $customInstitutions));
        foreach ($recordInstitutions as $i => $inst) {
            $recordInstitutions[$i] = trim(str_replace(" & ", " and ", $inst));
        }
        foreach (NameMatcher::explodeFirstName($firstNames[$recordId]) as $firstName) {
            foreach (NameMatcher::explodeLastName($lastNames[$recordId]) as $lastName) {
                $piName = '"'.$firstName." ".$lastName.'"';
                $piName = formatForNSFURL($piName);
                $url = $urlBase."?pdPIName=$piName&printFields=".implode(",", $printFields);
                Application::log("Record $recordId: $url", $pid);
                $tryNum = 0;
                $haveData = FALSE;
                do {
                    $tryNum++;
                    list($respCode, $json) = URLManagement::downloadURL($url, $pid);
                    if ($respCode == 200) {
                        $haveData = TRUE;
                        $numConsecutiveFailures = 0;
                        $data = json_decode($json, TRUE);
                        $resp = $data['response'];
                        if (isset($resp['serviceNotification'])) {
                            try {
                                processErrors($resp['serviceNotification'], $url);
                            } catch (\Exception $e) {
                                $errors[] = $e->getMessage();
                            }
                        } else if (isset($resp['award'])) {
                            $numIndividualAwards += count($resp['award']);
                            foreach ($resp['award'] as $award) {
                                checkForMatches(
                                    $recordMatches,
                                    $firstNames[$recordId] ?? "",
                                    $lastNames[$recordId] ?? "",
                                    $recordInstitutions,
                                    $recordId,
                                    $award
                                );
                            }
                        }
                    } else {
                        sleep(30);
                        $numConsecutiveFailures++;
                        if ($numConsecutiveFailures > $maxConsecutiveFailures) {
                            throw new \Exception("Over $maxConsecutiveFailures consecutive, unsuccessful iterations were detected. Quitting.");
                        }
                    }
                } while (!$haveData && ($tryNum < $maxTries));
            }
        }
    }
    Application::log("After general match, inspected $numIndividualAwards awards and now have ".getRecordMatchCount($recordMatches)." Record Matches across ".count($recordMatches)." records", $pid);

    try {
        $upload = transformIntoREDCap($recordMatches, $token, $server);
        if (!empty($upload)) {
            Upload::rows($upload, $token, $server);
        }
    } catch (\Exception $e) {
        $errors[] = $e->getMessage();
    }

    if (!empty($errors)) {
        throw new \Exception(implode("<br/><br/>", $errors));
    }
    Application::saveCurrentDate("Last NSF Download", $pid);
}

function ridOfExtraSpaces($aryOfStrings) {
    for ($i = 0; $i < count($aryOfStrings); $i++) {
        $aryOfStrings[$i] = preg_replace("/\s\s+/", " ", $aryOfStrings[$i]);
    }
    return $aryOfStrings;
}

function processErrors($notifications, $url) {
    $messages = [];
    foreach ($notifications as $notification) {
        if ($notification['notificationType'] == "ERROR") {
            $message = $notification['notificationMessage'];
            if (isset($notification['notificationCode'])) {
                $message .= " (" . $notification['notificationCode'] . ")";
            }
            $messages[] = $message;
        }
    }
    if (!empty($messages)) {
        throw new \Exception(count($messages)." error messages encountered from $url:<br/>".implode("<br/>", $messages));
    }
}

function checkForMatches(&$recordMatches, $firstName, $lastName, $institutions, $recordId, $award) {
    $pi = $award['pdPIName'] ?? "";
    $thisInstitution = $award['awardeeName'] ?? "";
    $coPIs = $award['coPDPI'] ?? [];
    $allPIs = ridOfExtraSpaces(array_merge([$pi], $coPIs));
    $awardId = $award['id'];
    if (!$awardId) {
        Application::log("No award-id: ".REDCapManagement::json_encode_with_spaces($award));
        return;
    }
    if (!$thisInstitution) {
        return;
    }
    if (!isset($recordMatches[$recordId][$awardId])) {
        $recordFirstNames = NameMatcher::explodeFirstName($firstName ?? "");
        $recordLastNames = NameMatcher::explodeLastName($lastName ?? "");
        foreach ($allPIs as $pi) {
            list($piFirst, $piLast) = NameMatcher::splitName($pi, 2);
            foreach ($recordFirstNames as $recFirst) {
                foreach ($recordLastNames as $recLast) {
                    if (
                        !isset($recordMatches[$recordId][$awardId])
                        && NameMatcher::matchName($recFirst, $recLast, $piFirst, $piLast)
                        && NameMatcher::matchInstitution($thisInstitution, $institutions)
                    ) {
                        if (!isset($recordMatches[$recordId])) {
                            $recordMatches[$recordId] = [];
                        }
                        Application::log("Matched $recordId to $awardId: $thisInstitution in ".json_encode($institutions));
                        $recordMatches[$recordId][$awardId] = $award;
                    }
                }
            }
        }
    }
}

function getNSFFields() {
    return [
        "id",
        "agency",
        "awardeeCity",
        "awardeeName",
        "awardeeStateCode",
        "coPDPI",
        "date",
        "startDate",
        "expDate",
        "estimatedTotalAmt",
        "fundsObligatedAmt",
        "pdPIName",
        "poName",
        "title",
        "piFirstName",
        "piMiddeInitial",
        "piLastName",
    ];
}

function transformIntoREDCap($recordMatches, $token, $server) {
    $upload = [];
    $fields = getNSFFields();
    $prefix = "nsf_";
    $instrument = "nsf";
    $redcapFields = ["record_id", "nsf_id"];

    $errors = [];
    $totalAwards = 0;
    $metadataFields = Download::metadataFields($token, $server);
    foreach ($recordMatches as $recordId => $awards) {
        if (!empty($awards)) {
            $totalAwards += count($awards);
            $redcapData = Download::fieldsForRecords($token, $server, $redcapFields, [$recordId]);
            $maxInstance = REDCapManagement::getMaxInstance($redcapData, $instrument, $recordId);
            $existingIds = REDCapManagement::findAllFields($redcapData, $recordId, "nsf_id");
            foreach ($awards as $awardId => $award) {
                if (!in_array($awardId, $existingIds)) {
                    $uploadRow = [
                        "record_id" => $recordId,
                        "redcap_repeat_instrument" => $instrument,
                        "redcap_repeat_instance" => $maxInstance + 1,
                        "nsf_last_update" => date("Y-m-d"),
                        $instrument."_complete" => "2",
                    ];
                    if (in_array("nsf_created", $metadataFields)) {
                        $uploadRow["nsf_created"] = date("Y-m-d");
                    }
                    foreach ($fields as $field) {
                        if (!isset($award[$field])) {
                            continue;
                        }
                        $value = is_array($award[$field]) ? implode(", ", ridOfExtraSpaces($award[$field])) : $award[$field] ?? "";
                        if (DateManagement::isDate($value) && DateManagement::isMDY($value)) {
                            $value = DateManagement::MDY2YMD($value);
                        }
                        if ($value && DateManagement::isDate($value) && !DateManagement::isYMD($value)) {
                            $errors[] = "$recordId: Invalid date $value for $field";
                        }
                        $uploadRow[strtolower($prefix.$field)] = $value;
                    }
                    if (count($uploadRow) > 5) {
                        $maxInstance++;
                        $upload[] = $uploadRow;
                    }
                }
            }
        }
    }
    if (!empty($errors)) {
        throw new \Exception("Upload errors!<br/>".implode("<br/>", $errors));
    }
    Application::log("Transformed $totalAwards awards into ".count($upload)." rows of REDCap");
    return $upload;
}

function getRecordMatchCount($recordMatches) {
    $total = 0;
    foreach ($recordMatches as $recordId => $awards) {
        $total += count($awards);
    }
    return $total;
}

function formatForNSFURL($str) {
    $str = str_replace(" & ", " and ", $str);
    $str = strtolower($str);
    $str = trim($str);
    $str = urlencode($str);
    $str = preg_replace("/\s+/", "+", $str);
    return $str;
}