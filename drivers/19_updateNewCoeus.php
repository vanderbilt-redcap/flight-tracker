<?php

namespace Vanderbilt\CareerDevLibrary;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

function updateAllCoeus($token, $server, $pid, $records) {
    updateCoeusGrants($token, $server, $pid, $records);
    updateCoeusSubmissions($token, $server, $pid, $records);
}

function updateCoeusGrants($token, $server, $pid, $records) {
    updateCoeusGeneric($token, $server, $pid, $records, "coeus", "awards");
    CareerDev::saveCurrentDate("Last COEUS Download", $pid);
}

function updateCoeusSubmissions($token, $server, $pid, $records) {
    updateCoeusGeneric($token, $server, $pid, $records, "coeus_submission", "proposals");
    CareerDev::saveCurrentDate("Last COEUS Submission Download", $pid);
}

function updateCoeusGeneric($token, $server, $pid, $records, $instrument, $awardDataField) {
    $metadata = Download::metadata($token, $server);
    $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
    $submissionFields = REDCapManagement::getFieldsFromMetadata($metadata, $instrument);
    if (Application::isVanderbilt() && !empty($submissionFields)) {
        Application::log("Downloading $instrument fields", $pid);
        if ($instrument == "coeus_submission") {
            $prefix = "coeussubmission_";
        } else {
            $prefix = $instrument."_";
        }
        $userids = Download::userids($token, $server);
        $currFirstNames = Download::firstnames($token, $server);
        $currLastNames = Download::lastnames($token, $server);

        $conn = new COEUSConnection();
        $conn->connect();
        $data = $conn->pullAllRecords();
        $conn->close();

        $translate = [];
        foreach ($records as $recordId) {
            $currUserid = strtolower($userids[$recordId] ?? "");
            $matchedData = [];
            $i = 0;
            foreach ($data[$awardDataField] as $row) {
                if (isset($translate[$row['PERSON_ID']])) {
                    $uid = $translate[$row['PERSON_ID']];
                } else {
                    $uid = LDAP::getUIDFromEmployeeID($row['PERSON_ID']);
                    $translate[$row['PERSON_ID']] = $uid;
                    # TODO could cache somewhere
                }
                if ($uid) {
                    if ($uid == $currUserid) {
                        $matchedData[] = $row;
                    }
                } else {
                    # not in LDAP => use name matching
                    list($firstName, $lastName) = NameMatcher::splitName($row['PERSON_NAME']);
                    if (NameMatcher::matchName($firstName, $lastName, $currFirstNames[$recordId], $currLastNames[$recordId])) {
                        $matchedData[] = $row;
                    }
                }
                $i++;
            }
            if (!empty($matchedData)) {
                Application::log("Record $recordId has ".count($matchedData)." matches for $instrument", $pid);
                $uniqueFields = [];
                $timestampField = "";
                if ($instrument == "coeus") {
                    $uniqueFields[] = "coeus_award_no";
                    $uniqueFields[] = "coeus_award_seq";
                    $timestampField = "coeus_update_timestamp";
                } else if ($instrument == "coeus_submission") {
                    $uniqueFields[] = "coeussubmission_ip_number";
                    $uniqueFields[] = "coeussubmission_ip_seq";
                    $timestampField = "coeussubmission_update_timestamp";
                } else {
                    Application::log("No unique fields set => automatically including.", $pid);
                }
                $timestampFields = $timestampField ? [$timestampField] : [];
                $redcapData = Download::fieldsForRecords($token, $server, array_merge(["record_id"], $uniqueFields, $timestampFields), [$recordId]);
                $maxInstance = REDCapManagement::getMaxInstance($redcapData, $instrument, $recordId);
                $uniqueValues = [];
                foreach ($uniqueFields as $uniqueField) {
                    $uniqueValues[$uniqueField] = REDCapManagement::findAllFields($redcapData, $recordId, $uniqueField, TRUE);
                }

                $foundInstanceList = [];
                $upload = [];
                foreach ($matchedData as $dataRow) {
                    $foundInstances = [];
                    foreach ($dataRow as $dataField => $value) {
                        $field = $prefix.strtolower($dataField);
                        foreach ($uniqueFields as $uniqueField) {
                            if ($field == $uniqueField) {
                                $foundField = FALSE;
                                if (!empty($foundInstances)) {
                                    $newInstances = [];
                                    foreach ($foundInstances as $foundInstance) {
                                        if ($uniqueValues[$uniqueField][$foundInstance] == $value) {
                                            $foundField = TRUE;
                                            $newInstances[] = $foundInstance;
                                        }
                                    }
                                    $foundInstances = $newInstances;
                                } else {
                                    foreach ($uniqueValues[$uniqueField] as $instance => $existingValue) {
                                        if ($existingValue == $value) {
                                            $foundField = TRUE;
                                            $foundInstances[] = $instance;
                                        }
                                    }
                                }
                                if (!$foundField) {
                                    $foundInstances = [];
                                }
                            }
                            if (empty($foundInstances)) {
                                break;
                            }
                        }
                    }
                    if (!empty($foundInstances)) {
                        $foundInstanceList = array_unique(array_merge($foundInstanceList, $foundInstances));
                        Application::log("Existing instances at ".implode(", ", $foundInstanceList), $pid);
                        if ($timestampField) {
                            foreach ($foundInstanceList as $instance) {
                                $lastUpdateInREDCap = REDCapManagement::findField($redcapData, $recordId, $timestampField, $instrument, $instance);
                                $lastUpdateInCOEUS = "";
                                foreach ($dataRow as $dataField => $dataValue) {
                                    if ($prefix.strtolower($dataField) == $timestampField) {
                                        if (DateManagement::isOracleDate($dataValue)) {
                                            $lastUpdateInCOEUS = DateManagement::oracleDate2YMD($dataValue);
                                        } else if (DateManagement::isDate($dataValue)) {
                                            $lastUpdateInCOEUS = $dataValue;
                                        }
                                        break;
                                    }
                                }
                                if (
                                    $lastUpdateInCOEUS
                                    && (
                                        !$lastUpdateInREDCap
                                        || DateManagement::dateCompare($lastUpdateInCOEUS, ">", $lastUpdateInREDCap)
                                    )
                                ) {
                                    $upload[] = makeUploadRowForCOEUS($dataRow, $recordId, $instrument, $instance, $prefix, $metadataFields);
                                }
                            }
                        }
                    } else {
                        $maxInstance++;
                        $upload[] = makeUploadRowForCOEUS($dataRow, $recordId, $instrument, $maxInstance, $prefix, $metadataFields);
                    }
                }
                if (!empty($upload)) {
                    Application::log("Uploading ".count($upload)." rows for Record $recordId", $pid);
                    Upload::rows($upload, $token, $server);
                } else {
                    Application::log("Nothing to upload for Record $recordId! Existing instances at ".implode(", ", $foundInstanceList), $pid);
                }
            } else {
                Application::log("Record $recordId has no matches for $instrument", $pid);
            }
        }
    } else {
        Application::log("Skipping $instrument fields", $pid);
    }
}

function makeUploadRowForCOEUS($dataRow, $recordId, $instrument, $instance, $prefix, $metadataFields) {
    $uploadRow = [
        "record_id" => $recordId,
        "redcap_repeat_instrument" => $instrument,
        "redcap_repeat_instance" => $instance,
        $prefix."last_update" => date("Y-m-d"),
        $instrument."_complete" => "2",
    ];
    foreach ($dataRow as $dataField => $value) {
        if ($value) {
            $field = $prefix.strtolower($dataField);
            if (!in_array($field, $metadataFields)) {
                throw new \Exception("Invalid field $field");
            }
            if (DateManagement::isOracleDate($value)) {
                $value = DateManagement::oracleDate2YMD($value);
            } else if ($value == "Y") {
                $value = "1";
            } else if ($value == "N") {
                $value = "0";
            }
            $uploadRow[$field] = utf8_decode($value);
        }
    }
    return $uploadRow;
}

function sendUseridsToCOEUS($token, $server, $pid, $records) {
    if (Application::isVanderbilt()) {
        $redcapUserids = Download::userids($token, $server);
        putUseridsIntoArray($redcapUserids);
        $errors = filterUseridsForSize($redcapUserids);
        $conn = new COEUSConnection();
        $conn->connect();
        $conn->sendUseridsToCOEUS($redcapUserids, $records, $pid);
        $conn->close();
        if (!empty($errors)) {
            $adminEmail = Application::getSetting("admin_email", $pid);
            $defaultFrom = Application::getSetting("default_from", $pid);
            if ($adminEmail && $defaultFrom) {
                $projectTitle = Application::getProjectTitle();
                $preamble = "<strong>Flight Tracker Project $pid - $projectTitle</strong><br>$server";
                \REDCap::email($adminEmail, $defaultFrom, "Flight Tracker - Invalid userid", $preamble."<br><br>".implode("<br>", $errors));
            }
        }
    }
}

function putUseridsIntoArray(&$userids) {
    foreach (array_keys($userids) as $recordId) {
        if (preg_match("/[,;]/", $userids[$recordId])) {
            $userids[$recordId] = preg_split("/\s*[,;]\s*/", $userids[$recordId]);
        } else if (!is_array($userids[$recordId])) {
            $userids[$recordId] = [$userids[$recordId]];
        }
    }
}

function filterUseridsForSize(&$userids) {
    $maxChars = 10;   // SRIADM.SRI_CAREER's CAREER_VUNET is VARCHAR2(10)
    $errors = [];
    foreach ($userids as $recordId => $useridAry) {
        foreach ($useridAry as $userid) {
            if (strlen($userid) > $maxChars) {
                $errors[] = "Record $recordId has userid $userid, which is greater than $maxChars characters.";
                unset($userids[$recordId]);
            }
        }
    }
    return $errors;
}