<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\COEUSConnection;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\LDAP;
use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\Upload;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

function updateCoeusGrants($token, $server, $pid, $records) {
    updateCoeusGeneric($token, $server, $pid, $records, "coeus", "awards");
}

function updateCoeusSubmissions($token, $server, $pid, $records) {
    updateCoeusGeneric($token, $server, $pid, $records, "coeus_submission", "proposals");
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

        $ldapUIDs = [];
        for ($i = 0; $i < count($data[$awardDataField]); $i++) {
            if ($i % 1000 == 0) {
                Application::log("Pulling LDAP on $i", $pid);
            }
            $uid = LDAP::getUIDFromEmployeeID($data[$awardDataField][$i]['PERSON_ID']);
            usleep(10);
            $ldapUIDs[] = strtolower($uid);
        }

        foreach ($records as $recordId) {
            $currUserid = strtolower($userids[$recordId]);
            $matchedData = [];
            $i = 0;
            foreach ($data[$awardDataField] as $row) {
                $uid = $ldapUIDs[$i];
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
                if ($instrument == "coeus") {
                    $uniqueFields[] = "coeus_award_no";
                    $uniqueFields[] = "coeus_award_seq";
                } else if ($instrument == "coeus_submission") {
                    $uniqueFields[] = "coeussubmission_ip_number";
                    $uniqueFields[] = "coeussubmission_ip_seq";
                } else {
                    Application::log("No unique fields set => automatically including.", $pid);
                }
                $redcapData = Download::fieldsForRecords($token, $server, array_merge(["record_id"], $uniqueFields), [$recordId]);
                $maxInstance = REDCapManagement::getMaxInstance($redcapData, $instrument, $recordId);
                $uniqueValues = [];
                foreach ($uniqueFields as $uniqueField) {
                    $uniqueValues[$uniqueField] = REDCapManagement::findAllFields($redcapData, $recordId, $uniqueField);
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
                    } else {
                        $maxInstance++;
                        $uploadRow = [
                            "record_id" => $recordId,
                            "redcap_repeat_instrument" => $instrument,
                            "redcap_repeat_instance" => $maxInstance,
                            $prefix."last_update" => date("Y-m-d"),
                            $instrument."_complete" => "2",
                        ];
                        foreach ($dataRow as $dataField => $value) {
                            if ($value) {
                                $field = $prefix.strtolower($dataField);
                                if (!in_array($field, $metadataFields)) {
                                    throw new \Exception("Invalid field $field");
                                }
                                if (REDCapManagement::isOracleDate($value)) {
                                    $value = REDCapManagement::oracleDate2YMD($value);
                                } else if ($value == "Y") {
                                    $value = "1";
                                } else if ($value == "N") {
                                    $value = "0";
                                }
                                $uploadRow[$field] = utf8_decode($value);
                            }
                        }
                        $upload[] = $uploadRow;
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

function sendUseridsToCOEUS($token, $server, $pid, $records) {
    if (Application::isVanderbilt()) {
        $redcapUserids = Download::userids($token, $server);
        $conn = new COEUSConnection();
        $conn->connect();
        $data = $conn->pullAllRecords();
        $coeusIds = $data['ids'];
        Application::log("COEUS is pulling ".count($coeusIds)." ids", $pid);
        $idsToAdd = [];
        foreach ($records as $recordId) {
            $userid = $redcapUserids[$recordId];
            if ($userid && !in_array($userid, $coeusIds)) {
                $idsToAdd[] = $userid;
            }
        }
        if (!empty($idsToAdd)) {
            Application::log("Inserting ".count($idsToAdd)." ids", $pid);
            $conn->insertNewIds($idsToAdd);
            $data = $conn->pullAllRecords();
            $coeusIds = $data['ids'];
            Application::log("COEUS is now pulling ".count($coeusIds)." ids", $pid);
        } else {
            Application::log("No new ids to upload", $pid);
        }
        $conn->close();
    }
}

