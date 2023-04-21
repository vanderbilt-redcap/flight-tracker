<?php

namespace Vanderbilt\CareerDevLibrary;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

function updateAllCoeus($token, $server, $pid, $records) {
    $data = getCOEUSData();
    updateCoeusGrants($token, $server, $pid, $records, $data);
    updateCoeusSubmissions($token, $server, $pid, $records, $data);
}

function updateCoeusGrants($token, $server, $pid, $records, $data = []) {
    if (empty($data)) {
        $data = getCOEUSData();
    }
    updateCoeusGeneric($token, $server, $pid, $records, "coeus", "awards", $data);
    Application::saveCurrentDate("Last COEUS Download", $pid);
}

function updateCoeusSubmissions($token, $server, $pid, $records, $data = []) {
    if (empty($data)) {
        $data = getCOEUSData();
    }
    updateCoeusGeneric($token, $server, $pid, $records, "coeus_submission", "proposals", $data);
    Application::saveCurrentDate("Last COEUS Submission Download", $pid);
}

function updateAllCOEUSMulti($pids) {
    Application::log("updateAllCOEUSMulti with ".count($pids)." pids");
    $data = getCOEUSData();
    foreach ($pids as $currPid) {
        $currToken = Application::getSetting("token", $currPid);
        $currServer = Application::getSetting("server", $currPid);
        if (REDCapManagement::isActiveProject($currPid) && $currToken && $currServer) {
            Application::setPid($currPid);
            $forms = Download::metadataForms($currToken, $currServer);
            if (in_array("coeus", $forms)) {
                $records = Download::records($currToken, $currServer);
                Application::log("updateAllCOEUSMulti updating COEUS ".count($records)." records", $currPid);
                updateCoeusGrants($currToken, $currServer, $currPid, $records, $data);
                updateCoeusSubmissions($currToken, $currServer, $currPid, $records, $data);
            }
        }
    }
}

function getCOEUSData() {
    $conn = new COEUSConnection();
    $conn->connect();
    $data = $conn->pullAllRecords();
    $conn->close();
    return $data;
}

function updateCoeusGeneric($token, $server, $pid, $records, $instrument, $awardDataField, $coeusData) {
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

        foreach ($records as $recordId) {
            $recordUserids = (isset($userids[$recordId]) && $userids[$recordId]) ? preg_split("/\s*,\s*/", strtolower($userids[$recordId])) : [];
            $matchedData = [];
            $i = 0;
            foreach ($coeusData[$awardDataField] as $row) {
                $uid = strtolower($row['VUNETID'] ?? "");
                if ($uid) {
                    if (in_array($uid, $recordUserids)) {
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
                                $uploadInstances = array_column($upload, "redcap_repeat_instance");
                                if (
                                    $lastUpdateInCOEUS
                                    && (
                                        !$lastUpdateInREDCap
                                        || DateManagement::dateCompare($lastUpdateInCOEUS, ">", $lastUpdateInREDCap)
                                    )
                                    && !in_array($instance, $uploadInstances)
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
                    Application::log("Uploading ".count($upload)." rows (".implode(", ", Upload::makeIds($upload)).")for Record $recordId", $pid);
                    Upload::rows($upload, $token, $server);
                } else {
                    Application::log("Nothing to upload for Record $recordId! Existing instances at ".implode(", ", $foundInstanceList), $pid);
                }
            } else {
                Application::log("Record $recordId has no matches for $instrument", $pid);
            }
            dedupCOEUS($token, $server, $pid, $recordId, $instrument, $prefix, $uniqueFields, $timestampField);
        }
    } else {
        Application::log("Skipping $instrument fields", $pid);
    }
}

function dedupCOEUS($token, $server, $pid, $recordId, $instrument, $prefix, $uniqueFields, $timestampField) {
    $fieldsToDownload = $uniqueFields;
    $fieldsToDownload[] = "record_id";
    $fieldsToDownload[] = $timestampField;
    $fieldsToDownload[] = REDCapManagement::prefix2CompleteField($prefix);
    $redcapData = Download::fieldsForRecords($token, $server, $fieldsToDownload, [$recordId]);
    $latestTimestampForItems = [];
    $instanceToUseForItems = [];
    $sep = "____";
    foreach ($redcapData as $row) {
        if ($row['redcap_repeat_instrument'] == $instrument) {
            $uniqueIDValues = [];
            $allBlank = TRUE;
            foreach ($uniqueFields as $field) {
                if ($row[$field]) {
                    $allBlank = FALSE;
                }
                $uniqueIDValues[] = $row[$field];
            }
            $uniqueID = implode($sep, $uniqueIDValues);
            if (!$allBlank) {
                $date = $row[$timestampField] ?? "";
                if (isset($latestTimestampForItems[$uniqueID])) {
                    if (DateManagement::isDate($date)) {
                        $rowTs = strtotime($date);
                        if ($rowTs > $latestTimestampForItems[$uniqueID]) {
                            $instanceToUseForItems[$uniqueID] = $row['redcap_repeat_instance'];
                            $latestTimestampForItems[$uniqueID] = $rowTs;
                        }
                    }
                } else {
                    if (DateManagement::isDate($date)) {
                        $latestTimestampForItems[$uniqueID] = strtotime($date);
                    } else {
                        $latestTimestampForItems[$uniqueID] = time();
                    }
                    $instanceToUseForItems[$uniqueID] = $row['redcap_repeat_instance'];
                }
            }
        }
    }
    $instancesToDelete = [];
    $instancesToKeep = array_values($instanceToUseForItems);
    foreach ($redcapData as $row) {
        if (($row['redcap_repeat_instrument'] == $instrument) && !in_array($row['redcap_repeat_instance'], $instancesToKeep)) {
            $instancesToDelete[] = $row['redcap_repeat_instance'];
        }
    }
    if (!empty($instancesToDelete)) {
        Application::log("Deleting instances of $instrument for $recordId: ".implode(", ", $instancesToDelete), $pid);
        Upload::deleteFormInstances($token, $server, $pid, $prefix, $recordId, $instancesToDelete);
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
            if (in_array($field, $metadataFields)) {
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