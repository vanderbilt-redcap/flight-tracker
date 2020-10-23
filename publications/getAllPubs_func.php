<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\iCite;
use \Vanderbilt\CareerDevLibrary\VICTRPubMedConnection;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\StarBRITE;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");
require_once(dirname(__FILE__)."/../classes/iCite.php");
require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/../classes/OracleConnection.php");
require_once(dirname(__FILE__)."/../classes/StarBRITE.php");

function getPubs($token, $server, $pid) {
	$cleanOldData = FALSE;
	$metadata = Download::metadata($token, $server);

	$records = Download::recordIds($token, $server);

	$citationIds = array();
	$pullSize = 1;
	$maxInstances = array();
	for ($i = 0; $i < count($records); $i += $pullSize) {
		$pullRecords = array();
		for ($j = $i; $j < count($records) && $j < $i + $pullSize; $j++) {
			array_push($pullRecords, $records[$j]);
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
		foreach ($redcapData as $row) {
			if ($row['redcap_repeat_instrument'] == "citation") {
				$recordId = $row['record_id'];
				$instance = $row['redcap_repeat_instance'];
				if (!isset($maxInstances[$recordId]) || ($instance > $maxInstances[$recordId])) {
					$maxInstances[$recordId] = $instance;
				}
			}
		}
        Publications::uploadBlankPMCsAndPMIDs($token, $server, $recordId, $metadata, $redcapData);
		binREDCapRows($redcapData, $citationIds);
	}
	foreach ($citationIds as $type => $typeCitationIds) {
		foreach ($typeCitationIds as $recordId => $recordCitationIds) {
			CareerDev::log("citationIds[$type][$recordId] has ".count($recordCitationIds));
			echo "citationIds[$type][$recordId] has ".count($recordCitationIds)."\n";
		}
	}
	unset($redcapData);

	if (CareerDev::getShortInstitution() == "Vanderbilt") {
		processVICTR($citationIds, $maxInstances, $token, $server, $pid);
	}
	processPubMed($citationIds, $maxInstances, $token, $server, $pid);
	postprocess($token, $server);
	CareerDev::saveCurrentDate("Last PubMed Download", $pid);
}

function postprocess($token, $server) {
	$records = Download::recordIds($token, $server);
	$metadata = Download::metadata($token, $server);
	$pullSize = 3;
	for ($i = 0; $i < count($records); $i += $pullSize) {
		$pullRecords = array();
		for ($j = $i; ($j < count($records)) && ($j < $i + $pullSize); $j++) {
			array_push($pullRecords, $records[$j]);
		}
		$redcapData = Download::fieldsForRecords($token, $server, Application::getCitationFields($metadata), $pullRecords);
		$indexedData = array();
		foreach ($redcapData as $row) {
			$recordId = $row['record_id'];
			if (($row['redcap_repeat_instrument'] == "citation") && ($row['citation_is_research'] === "") && ($row['citation_pmid'])) {
				// processUncategorizedRow($token, $server, $row);
			}
			if (!isset($indexedData[$recordId])) {
				$indexedData[$recordId] = array();
			}
			array_push($indexedData[$recordId], $row);
		}
		foreach ($indexedData as $recordId => $rows) {
			removeDuplicates($token, $server, $rows, $recordId);
		}
	}
}

function removeDuplicates($token, $server, $rows, $recordId) {
	$alreadySeen = array();
	foreach ($rows as $row) {
		if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "citation")) {
			$pmid = $row['citation_pmid'];
			if (in_array($pmid, $alreadySeen)) {
				$instance = $row['redcap_repeat_instance'];
				CareerDev::log("Duplicate in record $recordId (instance $instance)!");
				echo "Duplicate in record $recordId (instance $instance)!\n";
				$uploadRow = array(
							"record_id" => $row['record_id'],
							"redcap_repeat_instrument" => $row['redcap_repeat_instrument'],
							"redcap_repeat_instance" => $row['redcap_repeat_instance'],
							"citation_include" => "0",
							);
				upload(array($uploadRow), $token, $server);
			} else {
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
		$iCite = new iCite($pmid);
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

function processVICTR(&$citationIds, &$maxInstances, $token, $server, $pid) {
    $metadata = Download::metadata($token, $server);
    $vunets = reverseArray(Download::vunets($token, $server));
    include "/app001/credentials/con_redcap_ldap_user.php";
    $starBriteServer = StarBRITE::getServer();

    foreach ($vunets as $vunet => $recordId) {
        $url = "https://$starBriteServer/s/sri/api/pub-match/vunet";
        $getParams = [$vunet];
        $url .= '/' . implode('/', array_map('urlencode', $getParams));
        $opts = [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_HTTPHEADER => [ 'Content-Type: application/json' ],
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $ldapuser . ':' . $ldappass,
            CURLOPT_CUSTOMREQUEST => "GET",
        ];
        list($resp, $output) = REDCapManagement::downloadURL($url, $opts);
        $pmids = fetchPMIDs(json_decode($output, TRUE));
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
                    Application::log("Skipping because matched: " . $vunet . " PMID: " . $newCitationId);
                }
            }
        }
    }
    CareerDev::saveCurrentDate("Last VICTR PubMed Fetch Download", $pid);
    if (!empty($upload)) {
        upload($upload, $token, $server);
    }
}

function processPubMed(&$citationIds, &$maxInstances, $token, $server, $pid) {
	$allLastNames = Download::lastnames($token, $server);
	$allFirstNames = Download::firstnames($token, $server);
    $allInstitutions = Download::institutions($token, $server);
    $metadata = Download::metadata($token, $server);
    $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
    if (in_array("identifier_orcid", $metadataFields)) {
        $orcids = Download::ORCIDs($token, $server);
    } else {
        $orcids = [];
    }
    $choices = REDCapManagement::getChoices($metadata);

	foreach ($allLastNames as $recordId => $recLastName) {
		$firstName = $allFirstNames[$recordId];
		$lastNames = preg_split("/\s*[\s\-]\s*/", strtolower($recLastName));
        for ($i = 0; $i < count($lastNames); $i++) {
            $lastNames[$i] = preg_replace("/^\(/", "", $lastNames[$i]);
            $lastNames[$i] = preg_replace("/\)$/", "", $lastNames[$i]);
        }
		if (count($lastNames) > 1) {
			array_push($lastNames, strtolower($recLastName));
		}
		if (preg_match("/\s\(/", strtolower($firstName))) {
			# nickname in parentheses
			$namesWithFormatting = preg_split("/\s\(/", strtolower($firstName));
			$firstNames = array();
			foreach ($namesWithFormatting as $formattedFirstName) {
				$firstName = preg_replace("/\)$/", "", $formattedFirstName);
				$firstName = preg_replace("/\s+/", "+", $firstName);
				array_push($firstNames, $firstName);
			}
		} else {
			# specified full name => search as group
			$firstNames = array(preg_replace("/\s+/", "+", $firstName));
		}

		$personalInstitutions = array();
		if (isset($allInstitutions[$recordId])) {
			$personalInstitutions = preg_split("/\s*,\s*/", $allInstitutions[$recordId]);
		}
		$institutions = array_unique(array_merge(CareerDev::getInstitutions(), $personalInstitutions));

        $firstNames = REDCapManagement::removeBlanksFromAry($firstNames);
        $lastNames = REDCapManagement::removeBlanksFromAry($lastNames);
        $institutions = REDCapManagement::removeBlanksFromAry($institutions);

		$pmids = array();
		$orcidPMIDs = array();
        if ($orcids[$recordId]) {
            $orcidPMIDs = Publications::searchPubMedForORCID($orcids[$recordId]);
            addPMIDsIfNotFound($pmids, $citationIds, $orcidPMIDs, $recordId);
        }

        foreach ($lastNames as $lastName) {
			foreach ($firstNames as $firstName) {
				foreach ($institutions as $institution) {
					if ($institution) {
						if (isset($middle) && $middle) {
							$firstName .= " ".$middle;
						}
						CareerDev::log("Searching $lastName $firstName at $institution");
						echo "Searching $lastName $firstName at $institution\n";
                        $currPMIDs = Publications::searchPubMedForName($firstName, $lastName, $institution);
						addPMIDsIfNotFound($pmids, $citationIds, $currPMIDs, $recordId);
					}
				}

				// $naturalFirstName = REDCapManagement::stripNickname($allFirstNames[$recordId]);
                // $currPMIDs = Publications::searchPubMedForName($naturalFirstName." ".$allLastNames[$recordId]);
                // addPMIDsIfNotFound($pmids, $citationIds, $currPMIDs, $recordId);
			}
		}
		CareerDev::log("$recordId at ".count($pmids)." PMIDs");
		echo "$recordId at ".count($pmids)." PMIDs\n";

		if (!isset($maxInstances[$recordId])) {
			$maxInstances[$recordId] = 0;
		}
        $nonOrcidPMIDs = array();
        foreach ($pmids as $pmid) {
            if (!in_array($pmid, $orcidPMIDs)) {
                array_push($nonOrcidPMIDs, $pmid);
            }
        }
        $pubmedRows = array();
        $orcidRows = array();
        $max = $maxInstances[$recordId];
        $max++;
        if (!empty($nonOrcidPMIDs)) {
            $pubmedRows = Publications::getCitationsFromPubMed($nonOrcidPMIDs, $metadata, "pubmed", $recordId, $max, $orcidPMIDs, $pid);
        }
        if (!empty($orcidRows)) {
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
        $uploadRows = array_merge($pubmedRows, $orcidRows);
		if (!empty($uploadRows)) {
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
            array_push($pmids, $pmid);
            array_push($citationIds['New'][$recordId], $pmid);
        } else {
            Application::log("Record $recordId: Skipping $pmid ($pmidNum/$pmidCount)");
        }
        $pmidNum++;
    }
}

function upload($upload, $token, $server) {
	CareerDev::log("In function upload with ".count($upload)." rows");
	echo "In function upload with ".count($upload)." rows\n";
	$uploadSize = 1;
	$j = 0;
	while ($j < count($upload)) {
		$k = 0;
		$uploadSegments = array();
		while (($k < $uploadSize) && ($j + k < count($upload))) {
			$row = $upload[$j + $k];
			$row['citation_complete'] = '2';
			array_push($uploadSegments, $row);
			$k++;
		}
		Upload::rows($uploadSegments, $token, $server);
		$j += $uploadSize;
	}
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
		if (in_array($pmid, $typeCitationIds[$recordId])) {
			return $type;
		}
	}
	return "";
}

function clearAllCitations($pid, $records) {
	foreach ($records as $record) {
		CareerDev::log("Clearing record $record in $pid");
		echo "Clearing record $record in $pid\n";
		$sql = "DELETE FROM redcap_data WHERE field_name LIKE 'citation_%' AND project_id = '".db_real_escape_string($pid)."' AND record = '".db_real_escape_string($record)."'";
		db_query($sql);
	}
}

function fetchPMIDs($pubMedData) {
    $pmids = [];
    if ($pubMedData["data"]) {
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