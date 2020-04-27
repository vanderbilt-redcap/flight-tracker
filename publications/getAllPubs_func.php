<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\iCite;
use \Vanderbilt\CareerDevLibrary\VICTRPubMedConnection;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");
require_once(dirname(__FILE__)."/../classes/iCite.php");
require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/../classes/OracleConnection.php");

function getPubs($token, $server, $pid) {
	$cleanOldData = FALSE;

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
			$redcapData = Download::fieldsForRecords($token, $server, CareerDev::$citationFields, $pullRecords);
			foreach ($redcapData as $row) {
				if ($row['redcap_repeat_instrument'] == "citation") {
					$recordId = $row['record_id'];
					clearAllCitations($pid, array($recordId));
					break;
				}
			}
		}

		$redcapData = Download::fieldsForRecords($token, $server, CareerDev::$citationFields, $pullRecords);
		foreach ($redcapData as $row) {
			if ($row['redcap_repeat_instrument'] == "citation") {
				$recordId = $row['record_id'];
				$instance = $row['redcap_repeat_instance'];
				if (!isset($maxInstances[$recordId]) || ($instance > $maxInstances[$recordId])) {
					$maxInstances[$recordId] = $instance;
				}
			}
		}
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
		processVICTR($citationIds, $maxInstances, $token, $server);
	}
	processPubMed($citationIds, $maxInstances, $token, $server);
	postprocess($token, $server);
	CareerDev::saveCurrentDate("Last PubMed Download");
}

function postprocess($token, $server) {
	$records = Download::recordIds($token, $server);
	$pullSize = 3;
	for ($i = 0; $i < count($records); $i += $pullSize) {
		$pullRecords = array();
		for ($j = $i; ($j < count($records)) && ($j < $i + $pullSize); $j++) {
			array_push($pullRecords, $records[$j]);
		}
		$redcapData = Download::fieldsForRecords($token, $server, CareerDev::$citationFields, $pullRecords);
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
		if ($iCite->getVariable("is_research_article")) {
			$uploadRow = array(
						"record_id" => $row['record_id'],
						"redcap_repeat_instrument" => "citation",
						"redcap_repeat_instance" => $row['redcap_repeat_instance'],
						"citation_doi" => $iCite->getVariable("doi"),
						"citation_is_research" => $iCite->getVariable("is_research_article"),
						"citation_num_citations" => $iCite->getVariable("citation_count"),
						"citation_citations_per_year" => $iCite->getVariable("citations_per_year"),
						"citation_expected_per_year" => $iCite->getVariable("expected_citations_per_year"),
						"citation_field_citation_rate" => $iCite->getVariable("field_citation_rate"),
						"citation_nih_percentile" => $iCite->getVariable("nih_percentile"),
						"citation_rcr" => $iCite->getVariable("relative_citation_ratio"),
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

function processVICTR(&$citationIds, &$maxInstances, $token, $server) {
	$vunets = reverseArray(Download::vunets($token, $server));

	if (preg_match("/redcap.vanderbilt.edu/", $server)) {
		# pulls from PubMed via a helper file

		define("NOAUTH", true);

		$pubmedConnect = new VICTRPubMedConnection();
		$pubmedConnect->connect();
		$pubmedData = $pubmedConnect->getData();
		$pubmedConnect->close();

		CareerDev::log(count($pubmedData['outcomepubs'])." Pubs entries downloaded");
		CareerDev::log(count($pubmedData['outcomepubmatches'])." PubMatches entries downloaded");
		CareerDev::log(count($pubmedData['pubmed_publications'])." PubMedPubs entries downloaded");
		echo count($pubmedData['outcomepubs'])." Pubs entries downloaded\n";
		echo count($pubmedData['outcomepubmatches'])." PubMatches entries downloaded\n";
		echo count($pubmedData['pubmed_publications'])." PubMedPubs entries downloaded\n";
	} else {
		$pubmedData = array();
		$types = array("outcomepubs", "outcomepubmatches", "pubmed_publications");
		foreach ($types as $type) {
			$pubmedData[$type] = array();
			$fp = fopen($type.".format.json", "r");
			while ($jsonRow = fgets($fp)) {
				$jsonData = json_decode($jsonRow, true);
				$pubmedData[$type][] = $jsonData;
			}
			fclose($fp);
		}
	}

	$months = array(	"JAN" => 1,
				"FEB" => 2,
				"MAR" => 3,
				"APR" => 4,
				"MAY" => 5,
				"JUN" => 6,
				"JUL" => 7,
				"AUG" => 8,
				"SEP" => 9,
				"OCT" => 10,
				"NOV" => 11,
				"DEC" => 12,
			);

	$numFound = 0;
	$numNew = 0;

	$upload = array();
	# outcomepubs contains automatic matches; if user self-identifies with the citation, OUTP_ISAUTHOR is 1
	# outcompubmatches only contains those who use VICTR resources
	$i = 0;
	$iTotal = count($pubmedData['outcomepubs']);
	$batchSize = 200;
	foreach ($pubmedData['outcomepubs'] as $row) {
		if ($i % $batchSize === 0) {
			CareerDev::log("Looking at row $i of $iTotal: ".json_encode($row));
			echo "Looking at row $i of $iTotal: ".json_encode($row)."\n";
			if (!empty($upload)) {
				upload($upload, $token, $server);
				$upload = array();
			}
		}
		if (isset($vunets[$row['USR_VUNET']]) && ($row['OUTP_ISAUTHOR'] == "1")) {
			$newCitationId = $row['SRI_PUBPUB_ID'];
			$recordId = $vunets[$row['USR_VUNET']];
			if ($recordId) {
				$foundType = inCitationIds($citationIds, $newCitationId, $recordId);
				if (!$foundType) {
					echo "$i/$iTotal. vunet: ".$row['USR_VUNET']." PMID: ".$newCitationId." recordId: ".$recordId."\n";
					if (!isset($maxInstances[$recordId])) {
						$maxInstances[$recordId] = 0;
					}
					$maxInstances[$recordId]++;
					$uploadRows = Publications::getCitationsFromPubMed(array($newCitationId), "victr", $recordId, $maxInstances[$recordId], array($newCitationId));
					if (!isset($citationIds['Final'][$recordId])) {
						$citationIds['Final'][$recordId] = array();
					}
					array_push($citationIds['Final'][$recordId], $newCitationId);
				} else {
					CareerDev::log("$i/$iTotal. Skipping because matched: ".$row['USR_VUNET']." PMID: ".$newCitationId);
					echo "$i/$iTotal. Skipping because matched: ".$row['USR_VUNET']." PMID: ".$newCitationId."\n";
				}
			} else {
				CareerDev::log("Count not find record for vunet ".$row['USR_VUNET']);
				echo "Count not find record for vunet ".$row['USR_VUNET']."\n";
			}
		}
		$i++;
	}
	CareerDev::saveCurrentDate("Last VICTR PubMed Fetch Download");
	if (!empty($upload)) {
		upload($upload, $token, $server);
	}
}

function processPubMed(&$citationIds, &$maxInstances, $token, $server) { 
	$allLastNames = Download::lastnames($token, $server);
	$allFirstNames = Download::firstnames($token, $server);
    $allInstitutions = Download::institutions($token, $server);
    $orcids = Download::ORCIDs($token, $server);
    $metadata = Download::metadata($token, $server);
    $choices = REDCapManagement::getChoices($metadata);

	foreach ($allLastNames as $recordId => $recLastName) {
		$firstName = $allFirstNames[$recordId];
		$lastNames = preg_split("/\s*[\s\-]\s*/", strtolower($recLastName));
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
            $pubmedRows = Publications::getCitationsFromPubMed($nonOrcidPMIDs, "pubmed", $recordId, $max, $orcidPMIDs);
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
            $orcidRows = Publications::getCitationsFromPubMed($orcidPMIDs, $src, $recordId, $max, $orcidPMIDs);
        }
        $uploadRows = array_merge($pubmedRows, $orcidRows);
		if (!empty($uploadRows)) {
			upload($uploadRows, $token, $server);
		}
	}
	CareerDev::saveCurrentDate("Last PubMed Download");
}

function addPMIDsIfNotFound(&$pmids, &$citationIds, $currPMIDs, $recordId) {
    $pmidNum = 1;
    $pmidCount = count($currPMIDs);
    foreach ($currPMIDs as $pmid) {
        $foundType = inCitationIds($citationIds, $pmid, $recordId);
        if (!$foundType) {
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
