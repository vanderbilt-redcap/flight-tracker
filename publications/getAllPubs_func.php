<?php

namespace Vanderbilt\CareerDevLibrary;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

function cleanEmptySources($token, $server, $pid, $records) {
    foreach ($records as $recordId) {
        Publications::deleteEmptySources($token, $server, $pid, $recordId);
    }
}

function dedupPubs($token, $server, $pid, $records) {
    if (empty($records)) {
        $records = Download::recordIds($token, $server);
    }
    $metadata = Download::metadata($token, $server);
    foreach ($records as $recordId) {
        $fields = ["record_id", "citation_pmid", "eric_id"];
        $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);

        $pubs = new Publications($token, $server, $metadata);
        $pubs->setRows($redcapData);
        $pubs->deduplicateCitations($recordId);
    }
}

function getPubs($token, $server, $pid, $records) {
	$cleanOldData = FALSE;
    $metadata = Download::metadata($token, $server);
    $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
    $hasAbstract = in_array("citation_abstract", $metadataFields);

    if (empty($records)) {
        $records = Download::recordIds($token, $server);
    }
    $firstNames = Download::firstnames($token, $server);
    $lastNames = Download::lastnames($token, $server);

	$citationIds = [];
	$pullSize = 1;
	$maxInstances = [];
	for ($i0 = 0; $i0 < count($records); $i0 += $pullSize) {
		$pullRecords = [];
		for ($j = $i0; $j < count($records) && $j < $i0 + $pullSize; $j++) {
			$pullRecords[] = $records[$j];
		}

		if ($cleanOldData && $pid) {
			$redcapData = Download::fieldsForRecords($token, $server, Application::getCitationFields($metadata), $pullRecords);
			foreach ($redcapData as $row) {
				if ($row['redcap_repeat_instrument'] == "citation") {
					$recordId = $row['record_id'];
					clearAllCitations($pid, array($recordId));
					break;
				}
			}
		}

		$redcapData = Download::fieldsForRecords($token, $server, Application::getCitationFields($metadata), $pullRecords);
		foreach ($pullRecords as $recordId) {
		    $maxInstances[$recordId] = REDCapManagement::getMaxInstance($redcapData, "citation", $recordId);
        }
        $pmidsByRecordForAbstracts = [];
        $pmidsByRecordForDate = [];
		foreach ($redcapData as $row) {
            if ($row['redcap_repeat_instrument'] == "citation") {
                $recordId = $row['record_id'];
                $instance = $row['redcap_repeat_instance'];
                if (!$row['citation_abstract'] && $hasAbstract && $row['citation_pmid']) {
                    if (!isset($pmidsByRecordForAbstracts[$recordId])) {
                        $pmidsByRecordForAbstracts[$recordId] = [];
                    }
                    $pmidsByRecordForAbstracts[$recordId][$instance] = $row['citation_pmid'];
                }
                if (!$row['citation_year'] && !$row['citation_month'] && !$row['citation_day']) {
                    if (!isset($pmidsByRecordForDate[$recordId])) {
                        $pmidsByRecordForDate[$recordId] = [];
                    }
                    $pmidsByRecordForDate[$recordId][$instance] = $row['citation_pmid'];
                }
            }
        }
        foreach ($pmidsByRecordForDate as $recordId => $pmidsByInstance) {
            $pmids = array_values($pmidsByInstance);
            if (!empty($pmids)) {
                $rows = Publications::getCitationsFromPubMed($pmids, $metadata, "pubmed", $recordId, 1, [], $pid);
                foreach ($pmidsByInstance as $instance => $pmid) {
                    foreach ($rows as $row) {
                        if (
                            isset($row['pmid'])
                            && ($row['pmid'] == $pmid)
                            && (
                                $row['citation_year']
                                || $row['citation_month']
                                || $row['citation_day']
                            )
                        ) {
                            $uploadRow = [
                                "record_id" => $recordId,
                                "redcap_repeat_instrument" => "citation",
                                "redcap_repeat_instance" => $instance,
                                "citation_year" => $row['citation_year'],
                                "citation_month" => $row['citation_month'],
                                "citation_day" => $row['citation_day'],
                            ];
                            Upload::oneRow($uploadRow, $token, $server);
                        }
                    }
                }
            }
        }
        foreach ($pmidsByRecordForAbstracts as $recordId => $pmidsByInstance) {
            $pubmedMatches = Publications::downloadPMIDs(array_values($pmidsByInstance));
            $i = 0;
            foreach ($pmidsByInstance as $instance => $pmid) {
                if (isset($pubmedMatches[$i]) && $pubmedMatches[$i]) {
                    $pubmedMatch = $pubmedMatches[$i];
                    $abstract = $pubmedMatch->getVariable("Abstract");
                    if ($abstract) {
                        $uploadRow = [
                            "record_id" => $recordId,
                            "redcap_repeat_instrument" => "citation",
                            "redcap_repeat_instance" => $instance,
                            "citation_abstract" => $abstract,
                        ];
                        Upload::oneRow($uploadRow, $token, $server);
                    }
                }
                $i++;
            }
		}
        foreach ($pullRecords as $recordId) {
            $cnt = Publications::uploadBlankPMCsAndPMIDs($token, $server, $recordId, $metadata, $redcapData);
            if ($cnt > 0) {
                Application::log("Uploaded $cnt blank rows for $recordId", $pid);
            }
            Publications::deleteEmptySources($token, $server, $pid, $recordId);
            Publications::deleteMismatchedRows($token, $server, $pid, $recordId, $firstNames, $lastNames);
            Publications::updateNewPMCs($token, $server, $pid, $recordId, $redcapData);
        }
		binREDCapRows($redcapData, $citationIds);
	}
	foreach ($citationIds as $type => $typeCitationIds) {
		foreach ($typeCitationIds as $recordId => $recordCitationIds) {
			CareerDev::log("citationIds[$type][$recordId] has ".count($recordCitationIds));
		}
	}
	unset($redcapData);

	if (CareerDev::isVanderbilt()) {
		processVICTR($citationIds, $maxInstances, $token, $server, $pid, $records);
	}
	processPubMed($citationIds, $maxInstances, $token, $server, $pid, $records);
	postprocess($token, $server, $records);
    cleanUpMiddleNamesSeepage($token, $server, $pid, $records);
    dedupPubs($token, $server, $pid, $records);
	CareerDev::saveCurrentDate("Last PubMed Download", $pid);
}

function postprocess($token, $server, $records) {
	$metadata = Download::metadata($token, $server);
	$pullSize = 3;
	for ($i = 0; $i < count($records); $i += $pullSize) {
		$pullRecords = [];
		for ($j = $i; ($j < count($records)) && ($j < $i + $pullSize); $j++) {
			array_push($pullRecords, $records[$j]);
		}
		$redcapData = Download::fieldsForRecords($token, $server, Application::getCitationFields($metadata), $pullRecords);
		$indexedData = [];
		foreach ($redcapData as $row) {
			$recordId = $row['record_id'];
			if (($row['redcap_repeat_instrument'] == "citation") && ($row['citation_is_research'] === "") && ($row['citation_pmid'])) {
				// processUncategorizedRow($token, $server, $row);
			}
			if (!isset($indexedData[$recordId])) {
				$indexedData[$recordId] = [];
			}
			$indexedData[$recordId][] = $row;
		}
		foreach ($indexedData as $recordId => $rows) {
			removeDuplicates($token, $server, $rows, $recordId);
		}
	}
}

function removeDuplicates($token, $server, $rows, $recordId) {
	$alreadySeen = array();
	$pid = Application::getPID($token);
	foreach ($rows as $row) {
		if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "citation")) {
			$pmid = $row['citation_pmid'];
			if (in_array($pmid, $alreadySeen)) {
				$instance = $row['redcap_repeat_instance'];
				CareerDev::log("Duplicate in record $recordId (instance $instance)!");
				echo "Duplicate in record $recordId (instance $instance)!\n";
                Upload::deleteFormInstances($token, $server, $pid, "citation", $recordId, [$instance]);
			} else if (($row['citation_include'] === '1') || ($row['citation_include'] === '')) {
				array_push($alreadySeen, $pmid);
			}
		}
	}
}

function processUncategorizedRow($token, $server, $row) {
	$pmid = $row['citation_pmid'];
	if ($pmid) {
		CareerDev::log("Uncategorized row: {$row['record_id']}:{$row['redcap_repeat_instance']} $pmid");
		echo "Uncategorized row: {$row['record_id']}:{$row['redcap_repeat_instance']} $pmid\n";
		$iCite = new iCite($pmid, Application::getPID($token));
		if ($iCite->getVariable($pmid, "is_research_article")) {
			$uploadRow = array(
						"record_id" => $row['record_id'],
						"redcap_repeat_instrument" => "citation",
						"redcap_repeat_instance" => $row['redcap_repeat_instance'],
						"citation_doi" => $iCite->getVariable($pmid, "doi"),
						"citation_is_research" => $iCite->getVariable($pmid, "is_research_article"),
						"citation_num_citations" => $iCite->getVariable($pmid, "citation_count"),
						"citation_citations_per_year" => $iCite->getVariable($pmid, "citations_per_year"),
						"citation_expected_per_year" => $iCite->getVariable($pmid, "expected_citations_per_year"),
						"citation_field_citation_rate" => $iCite->getVariable($pmid, "field_citation_rate"),
						"citation_nih_percentile" => $iCite->getVariable($pmid, "nih_percentile"),
						"citation_rcr" => $iCite->getVariable($pmid, "relative_citation_ratio"),
						);
			upload(array($uploadRow), $token, $server);
		}
	}
}

function reverseArray($ary) {
	$newAry = array();
	foreach ($ary as $idx => $val) {
		$newAry[$val] = $idx;
	}
	return $newAry;
}

function processVICTR(&$citationIds, &$maxInstances, $token, $server, $pid, $records) {
    $metadata = Download::metadata($token, $server);
    $vunets = Download::vunets($token, $server);
    $file = Application::getCredentialsDir()."/con_redcap_ldap_user.php";
    if (!file_exists($file)) {
        return;
    }
    include $file;

    $upload = [];
    foreach ($records as $recordId) {
        $vunet = $vunets[$recordId] ?? "";
        if ($vunet) {
            $data = StarBRITE::accessSRI("pub-match/vunet/", [$vunet], $pid);
            $pmids = fetchPMIDs($data);
            foreach ($pmids as $newCitationId) {
                if ($recordId) {
                    $foundType = inCitationIds($citationIds, $newCitationId, $recordId);
                    if (!$foundType) {
                        Application::log("vunet: " . $vunet . " PMID: " . $newCitationId . " recordId: " . $recordId);
                        if (!isset($maxInstances[$recordId])) {
                            $maxInstances[$recordId] = 0;
                        }
                        $maxInstances[$recordId]++;
                        $uploadRows = Publications::getCitationsFromPubMed(array($newCitationId), $metadata, "victr", $recordId, $maxInstances[$recordId], [$newCitationId], $pid);
                        foreach ($uploadRows as $uploadRow) {
                            // mark to include only for VICTR
                            $uploadRow['citation_include'] = '1';
                            array_push($upload, $uploadRow);
                        }
                        if (!isset($citationIds['Final'][$recordId])) {
                            $citationIds['Final'][$recordId] = array();
                        }
                        array_push($citationIds['Final'][$recordId], $newCitationId);
                    } else {
                        $citationIdsForRecord = [];
                        foreach ($citationIds as $type => $recordsWithData) {
                            $citationIdsForRecord[$type] = $recordsWithData[$recordId] ?? [];
                        }
                        Application::log("$recordId: Skipping because matched: " . $vunet . " PMID: " . $newCitationId." citationIds: ".REDCapManagement::json_encode_with_spaces($citationIdsForRecord));
                    }
                }
            }
        }
    }
    CareerDev::saveCurrentDate("Last VICTR PubMed Fetch Download", $pid);
    if (!empty($upload)) {
        upload($upload, $token, $server);
    }
}

function processPubMed(&$citationIds, &$maxInstances, $token, $server, $pid, $records) {
	$allLastNames = Download::lastnames($token, $server);
	$allFirstNames = Download::firstnames($token, $server);
	$allMiddleNames = Download::middlenames($token, $server);
    $allInstitutions = Download::institutions($token, $server);
    $metadata = Download::metadata($token, $server);
    $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
    if (in_array("identifier_orcid", $metadataFields)) {
        $orcids = Download::ORCIDs($token, $server);
    } else {
        $orcids = [];
    }
    $choices = REDCapManagement::getChoices($metadata);
    $defaultInstitutions = REDCapManagement::excludeAcronyms(array_unique(array_merge(Application::getInstitutions(), Application::getHelperInstitutions())));
    $excludeList = Download::excludeList($token, $server, "exclude_publications", $metadata);

	foreach ($records as $recordId) {
        $recLastName = $allLastNames[$recordId];
		$firstName = $allFirstNames[$recordId];
		$middleName = $allMiddleNames[$recordId];
        $lastNames = NameMatcher::explodeLastName(strtolower($recLastName));
        $firstNames = NameMatcher::explodeFirstName(strtolower($firstName), strtolower($middleName));

        if (isset($allInstitutions[$recordId])) {
            $institutions = Scholar::explodeInstitutions($allInstitutions[$recordId]);
        } else {
            $institutions = [];
        }
        foreach ($defaultInstitutions as $defaultInstitution) {
            if (!in_array($defaultInstitution, $institutions)) {
                $institutions[] = $defaultInstitution;
            }
        }

        $firstNames = REDCapManagement::removeBlanksFromAry($firstNames);
        $lastNames = REDCapManagement::removeBlanksFromAry($lastNames);
        $institutions = REDCapManagement::removeBlanksFromAry($institutions);

		$pmids = array();
		$orcidPMIDs = array();
        if ($orcids[$recordId]) {
            $orcidPMIDs = Publications::searchPubMedForORCID($orcids[$recordId], $pid);
            addPMIDsIfNotFound($pmids, $citationIds, $orcidPMIDs, $recordId);
        }

        $uniquePMIDs = [];
        $middle = "";     // can set at some future point as this is searchable
        foreach ($lastNames as $lastName) {
			foreach ($firstNames as $firstName) {
				foreach ($institutions as $institution) {
					if ($institution) {
						if ($middle) {
							$firstName .= " ".$middle;
						}
						CareerDev::log("Searching $lastName $firstName at $institution");
						echo "Searching $lastName $firstName at $institution\n";
                        $currPMIDs = Publications::searchPubMedForName($firstName, $lastName, $pid, $institution);
                        $uniquePMIDs = array_unique(array_merge($uniquePMIDs, $currPMIDs));
					}
				}

				// $naturalFirstName = REDCapManagement::stripNickname($allFirstNames[$recordId]);
                // $currPMIDs = Publications::searchPubMedForName($naturalFirstName." ".$allLastNames[$recordId]);
                // addPMIDsIfNotFound($pmids, $citationIds, $currPMIDs, $recordId);
			}
		}
        addPMIDsIfNotFound($pmids, $citationIds, $uniquePMIDs, $recordId);
		CareerDev::log("$recordId at ".count($pmids)." new PMIDs ".json_encode($pmids));

		if (!isset($maxInstances[$recordId])) {
			$maxInstances[$recordId] = 0;
		}
        $nonOrcidPMIDs = [];
        foreach ($pmids as $pmid) {
            if (!in_array($pmid, $orcidPMIDs)) {
                $nonOrcidPMIDs[] = $pmid;
            }
        }
        $pubmedRows = [];
        $orcidRows = [];
        $max = $maxInstances[$recordId];
        $max++;
        if (!empty($nonOrcidPMIDs)) {
            $pubmedRows = Publications::getCitationsFromPubMed($nonOrcidPMIDs, $metadata, "pubmed", $recordId, $max, $orcidPMIDs, $pid);
        }
        if (!empty($orcidPMIDs)) {
            if (!empty($pubmedRows)) {
                $max = REDCapManagement::getMaxInstance($pubmedRows, "citation", $recordId);
                $max++;
            }
            $src = "orcid";
            if (!isset($choices["citation_source"][$src])) {
                $src = "pubmed";
            }
            $orcidRows = Publications::getCitationsFromPubMed($orcidPMIDs, $metadata, $src, $recordId, $max, $orcidPMIDs, $pid);
        }
        Application::log("$recordId: Combining ".count($pubmedRows)." PubMed rows with ".count($orcidRows)." ORCID rows", $pid);
        $uploadRows = array_merge($pubmedRows, $orcidRows);
		if (!empty($uploadRows)) {
		    $uploadRows = Publications::filterExcludeList($uploadRows, $excludeList[$recordId]);
		    $instances = [];
		    foreach ($uploadRows as $row) {
		        $instances[] = $row['redcap_repeat_instance'];
            }
		    Application::log("$recordId: Uploading ".count($uploadRows)." rows: ".json_encode($instances), $pid);
			upload($uploadRows, $token, $server);
		}
	}
	CareerDev::saveCurrentDate("Last PubMed Download", $pid);
}

function addPMIDsIfNotFound(&$pmids, &$citationIds, $currPMIDs, $recordId) {
    $pmidNum = 1;
    $pmidCount = count($currPMIDs);
    foreach ($currPMIDs as $pmid) {
        $foundType = inCitationIds($citationIds, $pmid, $recordId);
        $alreadyInPMIDs = in_array($pmid, $pmids);
        if (!$foundType && !$alreadyInPMIDs) {
            $pmids[] = $pmid;
            if (!isset($citationIds['New'][$recordId])) {
                $citationIds['New'][$recordId] = [];
            }
            $citationIds['New'][$recordId][] = $pmid;
        } else if ($foundType) {
            Application::log("Record $recordId: Skipping $pmid because in citation ids ($pmidNum/$pmidCount)");
        } else if ($alreadyInPMIDs) {
            Application::log("Record $recordId: Skipping $pmid because in pmids ($pmidNum/$pmidCount)");
        } else {
            Application::log("Record $recordId: Skipping $pmid - this should never happen");
        }
        $pmidNum++;
    }
}

function upload($upload, $token, $server) {
    $pmids = [];
    for ($i = 0; $i < count($upload); $i++) {
        if ($upload[$i]['redcap_repeat_instrument'] == "citation") {
            $upload[$i]['citation_complete'] = '2';
            $pmids[$upload[$i]['citation_pmid']] = $upload[$i]['redcap_repeat_instance'];
        }
    }
    Upload::rows($upload, $token, $server);
    Application::log("Uploaded PMIDS ".REDCapManagement::json_encode_with_spaces($pmids));
}

function binREDCapRows($redcapData, &$citationIds) {
	if (!$citationIds) {
		$citationIds = array();
	}
	if (empty($citationIds)) {
		$citationIds['New'] = array();
		$citationIds['Omit'] = array();
		$citationIds['Final'] = array();
	}
	foreach ($redcapData as $row) {
		if ($row['redcap_repeat_instrument'] == "citation") {
			$recordId = $row['record_id'];
			
			if (!isset($citationIds['Final'][$recordId])) {
				$citationIds['New'][$recordId] = array();
				$citationIds['Omit'][$recordId] = array();
				$citationIds['Final'][$recordId] = array();
			}

			$type = "";
			if ($row['citation_include'] === "0") {
				$type = "Omit";
			} else if ($row['citation_include'] === "") {
				$type = "New";
			} else if ($row['citation_include'] == "1") {
				$type = "Final";
			} else {
				throw new \Exception("Cannot find include category for record {$recordId} with value {$row['citation_include']}.");
			}

			if ($type) {
				// CareerDev::log("Pushing {$row['citation_pmid']} to $type:$recordId");
				array_push($citationIds[$type][$recordId], $row['citation_pmid']);
			} else {
				throw new \Exception("Could not find type for record {$recordId}.");
			}
		}
	}
}

function inCitationIds($citationIds, $pmid, $recordId) {
	foreach ($citationIds as $type => $typeCitationIds) {
		if (in_array($pmid, $typeCitationIds[$recordId] ?? [])) {
			return $type;
		}
	}
	return "";
}

function clearAllCitations($pid, $records) {
    $module = Application::getModule();
	foreach ($records as $record) {
		CareerDev::log("Clearing record $record in $pid");
		echo "Clearing record $record in $pid\n";
		$sql = "DELETE FROM redcap_data WHERE field_name LIKE 'citation_%' AND project_id = ? AND record = ?";
        $module->query($sql, [$pid, $record]);
	}
}

function fetchPMIDs($pubMedData) {
    $pmids = [];
    if (isset($pubMedData["data"])) {
        foreach ($pubMedData["data"] as $sourceData) {
            if ($sourceData["publications"]) {
                foreach ($sourceData["publications"] as $pub) {
                    $pmids[] = $pub["pubMedId"];
                }
            }
        }
    }
    return $pmids;
}

function cleanUpMiddleNamesSeepage($token, $server, $pid, $records) {
    if (empty($records)) {
        $records = Download::recordIds($token, $server);
    }

    $cleaningField = "cleaned_by_middle_name";
    $recordsCleanedByMiddleName = Application::getSetting($cleaningField, $pid);
    if (!is_array($recordsCleanedByMiddleName)) {
        $recordsCleanedByMiddleName = [];
    }
    if (count(array_unique(array_merge($records, $recordsCleanedByMiddleName))) == count($recordsCleanedByMiddleName)) {
        # nothing new
        return;
    }

    $firstNames = Download::firstnames($token, $server);
    $lastNames = Download::lastnames($token, $server);
    $middleNames = Download::middlenames($token, $server);
    $allInstitutions = Download::institutions($token, $server);
    $defaultInstitutions = array_unique(array_merge(Application::getInstitutions(), Application::getHelperInstitutions()));
    $metadata = Download::metadata($token, $server);

    foreach ($records as $recordId) {
        if (
            $middleNames[$recordId]
            && NameMatcher::isInitial($middleNames[$recordId])
            && !in_array($recordId, $recordsCleanedByMiddleName)
        ) {
            $redcapData = Download::fieldsForRecords($token, $server, Application::getCitationFields($metadata), [$recordId]);
            $institutions = isset($allInstitutions[$recordId]) ? Scholar::explodeInstitutions($allInstitutions[$recordId]) : [];
            $institutions = array_unique(array_merge($institutions, $defaultInstitutions));
            Publications::deleteMiddleNameOnlyMatches($token, $server, $pid, $recordId, $redcapData, $firstNames[$recordId], $lastNames[$recordId], $middleNames[$recordId], $institutions);
        }
    }
    $recordsCleanedByMiddleName = array_unique(array_merge($recordsCleanedByMiddleName, $records));
    Application::saveSetting($cleaningField, $recordsCleanedByMiddleName, $pid);
}

