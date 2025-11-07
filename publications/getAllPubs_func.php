<?php

namespace Vanderbilt\CareerDevLibrary;

use Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

function cleanEmptySources($token, $server, $pid, $records) {
	foreach ($records as $recordId) {
		Publications::deleteEmptySources($token, $server, $pid, $recordId);
	}
}

function getPubs($token, $server, $pid, $records) {
	$backfilledRecords = Application::getSetting("backfill_pubmed_keywords", $pid) ?: [];
	$intersect = array_intersect($records, $backfilledRecords);
	if (count($intersect) === 0) {
		$metadataFields = Download::metadataFieldsByPid($pid);
		if (in_array("citation_pubmed_keywords", $metadataFields)) {
			# this just needs to be called once to backfill the citation_pubmed_keywords field
			updatePubMedKeywords($pid, $records);
			Application::saveSetting("backfill_pubmed_keywords", array_merge($backfilledRecords, $records), $pid);
		}
	}

	getPubsGeneric($token, $server, $pid, $records, true);
}

function getNamePubs($token, $server, $pid, $records) {
	getPubsGeneric($token, $server, $pid, $records, false);
}

function getPubsGeneric(string $token, string $server, string $pid, array $records, bool $searchWithInstitutions): void {
	$metadata = Download::metadataByPid($pid);
	$metadataFields = DataDictionaryManagement::getFieldsFromMetadata($metadata);
	$allRecords = Download::recordIdsByPid($pid);
	if (empty($records)) {
		$records = $allRecords;
	}

	$redoDatesSetting = "backfillDatesSetting";
	$redoBlankDates = (Application::getSetting($redoDatesSetting, $pid) === "");
	if ($redoBlankDates && (count($records) == count($allRecords))) {
		Application::saveSetting($redoDatesSetting, "1", $pid);
	}

	if (Application::isVanderbilt() && !Application::isLocalhost()) {
		processVICTR($token, $server, $pid, $records, $metadata);
	}
	foreach ($records as $recordId) {
		$redcapData = Download::fieldsForRecordsByPid($pid, Application::getCitationFields($metadata), [$recordId]);
		if (
			in_array("citation_date", $metadataFields)
			&& hasBlankCitationField($redcapData, "citation_date")
			&& in_array("citation_affiliations", $metadataFields)
			&& hasBlankCitationField($redcapData, "citation_affiliations")
		) {
			backfillDatesAndAffiliations($redcapData, $metadataFields, $pid);
		}
		if (in_array("citation_abstract", $metadataFields) && hasBlankCitationField($redcapData, "citation_abstract")) {
			backfillAbstracts($redcapData, $pid);
		}
		if ($redoBlankDates) {
			redoBlankDates($redcapData, $pid, $metadata, $recordId);
		}
		if (hasBlankCitationField($redcapData, "citation_pmid")) {
			$cnt = Publications::uploadBlankPMCsAndPMIDs($token, $server, $recordId, $metadata, $redcapData);
			if ($cnt > 0) {
				Application::log("Uploaded $cnt blank rows for $recordId", $pid);
			}
		}
		# 2024-04-17 - I'm removing this line because it sometimes acts incorrectly due to data irregularities in PubMed
		# It also frustrates users who try to add back items that have such irregularities, only to have them deleted automatically
		# The matching algorithm is better now than in the past, so I don't think this is needed.
		# I'm leaving the code in here for now in case we want to turn it on.
		// Publications::deleteMismatchedRows($token, $server, $pid, $recordId, $firstNames, $lastNames);
		if (hasBlankCitationField($redcapData, "citation_pmcid")) {
			Publications::updateNewPMCs($token, $server, $pid, $recordId, $redcapData);
		}
		if (
			DateManagement::dateCompare(date("Y-m-d"), "<=", "2024-06-01")  // remove because assuming everyone has upgraded
			&& in_array("citation_full_citation", $metadataFields)
			&& hasBlankCitationField($redcapData, "citation_full_citation")
		) {
			$hasTimestamp = in_array("citation_ts", $metadataFields);
			Publications::makeFullCitations($token, $server, $pid, $recordId, $metadata, $hasTimestamp);
		}
	}
	processPubMed($token, $server, $pid, $records, $metadata, $searchWithInstitutions);
	foreach ($records as $recordId) {
		$redcapData = Download::fieldsForRecordsByPid($pid, ["record_id", "citation_pmid", "eric_id"], [$recordId]);
		$pubs = new Publications($token, $server, $metadata);
		$pubs->setRows($redcapData);
		$pubs->deduplicateCitations($recordId);
	}
	CareerDev::saveCurrentDate("Last PubMed Download", $pid);
}

function hasBlankCitationField($redcapData, $field) {
	foreach ($redcapData as $row) {
		if (($row['redcap_repeat_instrument'] == "citation") && isset($row[$field]) && ($row[$field] === "")) {
			return true;
		}
	}
	return false;
}

function processVICTR($token, $server, $pid, $records, $metadata) {
	$file = Application::getCredentialsDir()."/con_redcap_ldap_user.php";
	if (!file_exists($file)) {
		return;
	}
	include $file;

	$vunets = Download::userids($token, $server);
	$allIncludes = Download::oneFieldWithInstances($token, $server, "citation_include");
	$allPMIDs = Download::oneFieldWithInstances($token, $server, "citation_pmid");
	$allSources = Download::oneFieldWithInstances($token, $server, "citation_source");

	$upload = [];
	foreach ($records as $recordId) {
		$vunet = $vunets[$recordId] ?? "";
		if ($vunet) {
			$data = StarBRITE::accessSRI("pub-match/vunet/", [$vunet], $pid);
			$pmids = StarBRITE::fetchPMIDs($data);
			$recordIncludes = $allIncludes[$recordId] ?? [];
			$recordPMIDsWithInstances = $allPMIDs[$recordId] ?? [];
			$recordSources = $allSources[$recordId] ?? [];
			$maxInstance = max(array_keys($recordPMIDsWithInstances) ?: [0]);
			foreach ($pmids as $newPMID) {
				if (!in_array($newPMID, array_values($recordPMIDsWithInstances))) {
					Application::log("$recordId: New PMID for userid: " . $vunet . " PMID: " . $newPMID, $pid);
					$maxInstance++;
					$uploadRows = Publications::getCitationsFromPubMed([$newPMID], $metadata, "victr", $recordId, $maxInstance, [$newPMID], $pid);
					foreach ($uploadRows as $uploadRow) {
						// mark to include only for VICTR
						$uploadRow['citation_include'] = '1';
						$upload[] = $uploadRow;
					}
				} else {
					# already exist --> update source to VICTR and mark as included automatically
					foreach ($recordPMIDsWithInstances as $instance => $pmid) {
						if (
							($pmid == $newPMID)
							&& (($recordIncludes[$instance] ?? "") !== "0")
							&& in_array($recordSources[$instance] ?? "", ["", "pubmed", "manual"])
						) {
							$uploadRow = [
								"record_id" => $recordId,
								"redcap_repeat_instrument" => "citation",
								"redcap_repeat_instance" => $instance,
								"citation_pmid" => $pmid,
								"citation_include" => "1",
								"citation_source" => "victr",
							];
							$upload[] = $uploadRow;
							break;
						}
					}
					Application::log("$recordId: Skipping because matched: " . $vunet . " PMID: " . $newPMID, $pid);
				}
			}
		}
	}
	CareerDev::saveCurrentDate("Last VICTR PubMed Fetch Download", $pid);
	if (!empty($upload)) {
		uploadPublications($upload, $pid);
	}
}

function processPubMed($token, $server, $pid, $records, $metadata, $searchWithInstitutions) {
	$allIncludes = Download::oneFieldWithInstances($token, $server, "citation_include");
	$allPMIDs = Download::oneFieldWithInstances($token, $server, "citation_pmid");
	$allSources = Download::oneFieldWithInstances($token, $server, "citation_source");
	$allLastNames = Download::lastnames($token, $server);
	$allFirstNames = Download::firstnames($token, $server);
	$allMiddleNames = Download::middlenames($token, $server);
	$allInstitutions = Download::institutions($token, $server);
	$metadataFields = DataDictionaryManagement::getFieldsFromMetadata($metadata);
	$choices = REDCapManagement::getChoices($metadata);
	$defaultInstitutions = REDCapManagement::excludeAcronyms(array_unique(array_merge(Application::getInstitutions($pid), Application::getHelperInstitutions($pid))));
	$excludeList = [
		'author' => Download::excludeList($token, $server, "exclude_publications", $metadataFields),
	];
	if (in_array("exclude_publication_topics", $metadataFields)) {
		$excludeList["title"] = Download::excludeList($token, $server, "exclude_publication_topics", $metadataFields);
	}
	if (in_array("identifier_orcid", $metadataFields)) {
		$orcids = Download::ORCIDs($token, $server);
	} else {
		$orcids = [];
	}
	if (in_array("identifier_corporate_author", $metadataFields)) {
		$corporateAuthors = Download::oneFieldByPid($pid, "identifier_corporate_author");
	} else {
		$corporateAuthors = [];
	}

	foreach ($records as $recordId) {
		$uploadRows = [];
		$recordPMIDsWithInstances = $allPMIDs[$recordId] ?? [];
		$maxInstance = (int) max(array_keys($recordPMIDsWithInstances) ?: [0]);
		$recLastName = $allLastNames[$recordId] ?? "";
		$firstName = $allFirstNames[$recordId] ?? "";
		$middleName = $allMiddleNames[$recordId] ?? "";

		if ($searchWithInstitutions) {
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
		} else {
			$institutions = ["all"];
		}
		$institutions = REDCapManagement::removeBlanksFromAry($institutions);

		$orcidPMIDsToDownload = [];
		$orcidPMIDs = [];
		if ($orcids[$recordId]) {
			$src = "orcid";
			if (!isset($choices["citation_source"][$src])) {
				$src = "pubmed";
			}
			foreach (preg_split("/\s*[,;]\s*/", $orcids[$recordId], -1, PREG_SPLIT_NO_EMPTY) as $orcid) {
				$orcidPMIDs = array_unique(array_merge($orcidPMIDs, Publications::searchPubMedForORCID($orcid, $pid)));
			}
			foreach ($orcidPMIDs as $pmid) {
				$matchedInstance = false;
				foreach ($recordPMIDsWithInstances as $instance => $recordPMID) {
					if ($pmid == $recordPMID) {
						$matchedInstance = $instance;
						break;
					}
				}
				if ($matchedInstance === false) {
					$orcidPMIDsToDownload[] = $pmid;
				} elseif (
					(($allIncludes[$recordId][$matchedInstance] ?? "") !== "0")
					&& (($allSources[$recordId][$matchedInstance] ?? "") != $src)
				) {
					$uploadRow = [
						"record_id" => $recordId,
						"redcap_repeat_instrument" => "citation",
						"redcap_repeat_instance" => $matchedInstance,
						"citation_pmid" => $pmid,
						"citation_include" => "1",
						"citation_source" => $src,
					];
					$uploadRows[] = $uploadRow;
				}
			}
		}

		$corporateAuthorPMIDS = [];
		if ($corporateAuthors[$recordId]) {
			$corporateAuthorNames = explode(";", $corporateAuthors[$recordId]);
			foreach ($corporateAuthorNames as $corporateAuthorName) {
				$corporateAuthorPMIDS = array_unique(array_merge($corporateAuthorPMIDS, Publications::searchPubMedForCorporateAuthor($corporateAuthorName, $pid)));
			}
		}

		Application::log("Searching $recLastName $firstName at ".implode(", ", $institutions), $pid);
		Application::log("Prior Record PMIDs ".count($recordPMIDsWithInstances).": ".json_encode($recordPMIDsWithInstances), $pid);
		$pubmedPMIDs = Publications::searchPubMedForName($firstName, $middleName, $recLastName, $pid, $institutions);
		$nonOrcidPMIDsToDownload = [];
		foreach ($pubmedPMIDs as $pmid) {
			if (!in_array($pmid, array_values($recordPMIDsWithInstances)) && !in_array($pmid, $orcidPMIDsToDownload)) {
				$nonOrcidPMIDsToDownload[] = $pmid;
			}
		}
		if (!empty($orcidPMIDsToDownload)) {
			Application::log("$recordId at ".count($orcidPMIDsToDownload)." new ORCID PMIDs ".json_encode($orcidPMIDsToDownload), $pid);
		}
		if (!empty($nonOrcidPMIDsToDownload)) {
			Application::log("$recordId at ".count($nonOrcidPMIDsToDownload)." new PubMed PMIDs ".json_encode($nonOrcidPMIDsToDownload), $pid);
		}
		if (!empty($corporateAuthorPMIDS)) {
			Application::log("$recordId at ".count($corporateAuthorPMIDS)." new Corporate Author PMIDs ".json_encode($corporateAuthorPMIDS), $pid);
		}

		$pubmedRows = [];
		$orcidRows = [];
		$corporateAuthorRows = [];
		$maxInstance++;
		if (!empty($nonOrcidPMIDsToDownload)) {
			$pubmedRows = Publications::getCitationsFromPubMed($nonOrcidPMIDsToDownload, $metadata, "pubmed", $recordId, $maxInstance, $orcidPMIDsToDownload, $pid);
			$maxInstance = REDCapManagement::getMaxInstance($pubmedRows, "citation", $recordId);
			$maxInstance++;
		}
		if (!empty($orcidPMIDsToDownload)) {
			$src = "orcid";
			if (!isset($choices["citation_source"][$src])) {
				$src = "pubmed";
			}
			$orcidRows = Publications::getCitationsFromPubMed($orcidPMIDs, $metadata, $src, $recordId, $maxInstance, $orcidPMIDsToDownload, $pid);
			$maxInstance = REDCapManagement::getMaxInstance($orcidRows, "citation", $recordId) + 1;
		}
		if (!empty($corporateAuthorPMIDS)) {
			$src = "pubmed";
			$corporateAuthorRows = Publications::getCitationsFromPubMed($corporateAuthorPMIDS, $metadata, $src, $recordId, $maxInstance, [], $pid);
		}
		Application::log("$recordId: Combining ".count($pubmedRows)." PubMed rows with ".count($orcidRows)." ORCID rows and Coporate Author Rows " . count($corporateAuthorRows) . " And ".count($uploadRows)." prior rows", $pid);
		$uploadRows = array_merge($pubmedRows, $orcidRows, $uploadRows, $corporateAuthorRows);
		if (!empty($uploadRows)) {
			$uploadRows = Publications::filterExcludeList($uploadRows, $excludeList, $recordId);
			$instances = [];
			foreach ($uploadRows as $row) {
				$instances[] = $row['redcap_repeat_instance'];
			}
			Application::log("$recordId: Uploading ".count($uploadRows)." rows: ".json_encode($instances), $pid);
			uploadPublications($uploadRows, $pid);
		}
	}
	CareerDev::saveCurrentDate("Last PubMed Download", $pid);
}

function uploadPublications($upload, $pid) {
	if (!empty($upload)) {
		$pmids = [];
		for ($i = 0; $i < count($upload); $i++) {
			if ($upload[$i]['redcap_repeat_instrument'] == "citation") {
				$upload[$i]['citation_complete'] = Publications::getPublicationCompleteStatusFromInclude($upload[$i]["citation_include"] ?? "");
				$pmids[$upload[$i]['citation_pmid']] = $upload[$i]['record_id'].":".$upload[$i]['redcap_repeat_instance'];
			}
		}
		Upload::rowsByPid($upload, $pid);
		Application::log("Uploaded PMIDs ".REDCapManagement::json_encode_with_spaces($pmids), $pid);
	}
}

# not used, but here in case needed
function clearAllCitations($pid, $records) {
	$module = Application::getModule();
	foreach ($records as $record) {
		Application::log("Clearing record $record", $pid);
		$dataTable = Application::getDataTable($pid);
		$sql = "DELETE FROM $dataTable WHERE field_name LIKE 'citation_%' AND project_id = ? AND record = ?";
		$module->query($sql, [$pid, $record]);
	}
}

# a publication may have a month or a day field missing; this fills in the rest to make it a REDCap-compatible date
function makeCitationDate($row) {
	$monthNo = $row['citation_month'] ? DateManagement::getMonthNumber($row['citation_month']) : "01";
	$dayNo = $row['citation_day'] ?: "01";
	if ($row['citation_year']) {
		return $row['citation_year']."-".$monthNo."-".$dayNo;
	} else {
		return "";
	}
}

# Dates were incorrectly calculated; because I accidentally added two date fields, both need to be corrected
# The date algorithm in Publications::getCitationsFromPubMed() became more sophisticated in early 2024.
# Therefore, all dates need to be re-uploaded.
# This function should only be called once for each project.
function redoBlankDates(array $redcapData, string $pid, array $metadata, string $recordId): void {
	$allPMIDs = [];
	$includeValues = [];
	foreach ($redcapData as $row) {
		$pmid = $row['citation_pmid'];
		$includeValues[$pmid] = $row['citation_include'];
		$allPMIDs[$pmid] = $row['redcap_repeat_instance'];
	}
	if (!empty($allPMIDs)) {
		$pubmedRows = Publications::getCitationsFromPubMed(array_keys($allPMIDs), $metadata, "pubmed", $recordId, 1, [], $pid, false);
		$uploadRows = [];
		foreach ($pubmedRows as $row) {
			$pmid = $row['citation_pmid'];
			$instance = $allPMIDs[$pmid];
			$uploadRow = [
				"record_id" => $recordId,
				"redcap_repeat_instance" => $instance,
				"redcap_repeat_instrument" => "citation",
				"citation_day" => $row['citation_day'],
				"citation_month" => $row['citation_month'],
				"citation_year" => $row['citation_year'],
				"citation_ts" => $row['citation_ts'],
				"citation_date" => $row['citation_date'],
				"citation_complete" => Publications::getPublicationCompleteStatusFromInclude($includeValues[$pmid] ?? ""),
			];
			$uploadRows[] = $uploadRow;
		}
		if (!empty($uploadRows)) {
			Upload::rowsByPid($uploadRows, $pid);
		}
	}
}

function backfillAbstracts($redcapData, $pid) {
	$pmidsByRecordForAbstracts = [];
	foreach ($redcapData as $row) {
		if ($row['redcap_repeat_instrument'] == "citation") {
			$recordId = $row['record_id'];
			$instance = $row['redcap_repeat_instance'];
			if (!$row['citation_abstract'] && $row['citation_pmid']) {
				if (!isset($pmidsByRecordForAbstracts[$recordId])) {
					$pmidsByRecordForAbstracts[$recordId] = [];
				}
				$pmidsByRecordForAbstracts[$recordId][$instance] = $row['citation_pmid'];
			}
		}
	}

	$upload = [];
	foreach ($pmidsByRecordForAbstracts as $recordId => $pmidsByInstance) {
		$pubmedMatches = Publications::downloadPMIDs(array_values($pmidsByInstance), $pid);
		$i = 0;
		foreach ($pmidsByInstance as $instance => $pmid) {
			if (isset($pubmedMatches[$i]) && $pubmedMatches[$i]) {
				$pubmedMatch = $pubmedMatches[$i];
				$abstract = $pubmedMatch->getVariable("Abstract");
				if ($abstract) {
					$upload[] = [
						"record_id" => $recordId,
						"redcap_repeat_instrument" => "citation",
						"redcap_repeat_instance" => $instance,
						"citation_abstract" => $abstract,
					];
				}
			}
			$i++;
		}
	}
	if (!empty($upload)) {
		Upload::rowsByPid($upload, $pid);
	}
}

function backfillDatesAndAffiliations($redcapData, $metadataFields, $pid) {
	$pmidsToUpdateDates = [];
	$pmidsToUpdateAffiliations = [];
	foreach ($redcapData as $row) {
		if ($row['redcap_repeat_instrument'] == "citation") {
			$recordId = $row['record_id'];
			$instance = $row['redcap_repeat_instance'];
			$fullDate = makeCitationDate($row);
			if (!$row['citation_date'] && in_array("citation_date", $metadataFields) && $fullDate) {
				$pmidsToUpdateDates["$recordId:$instance"] = $fullDate;
			}
			if (!$row['citation_affiliations'] && in_array("citation_affiliations", $metadataFields)) {
				$pmidsToUpdateAffiliations["$recordId:$instance"] = $row['citation_pmid'];
			}
		}
	}
	# back-fill rows
	$uploadUpdates = [];
	if (!empty($pmidsToUpdateAffiliations)) {
		$affiliations = Publications::getAffiliationJSONsForPMIDs(array_unique(array_values($pmidsToUpdateAffiliations)), $metadataFields, $pid);
		foreach ($pmidsToUpdateAffiliations as $licensePlate => $pmid) {
			list($recordId, $instance) = explode(":", $licensePlate);
			$uploadRow = [
				"record_id" => $recordId,
				"redcap_repeat_instrument" => "citation",
				"redcap_repeat_instance" => $instance,
				"citation_affiliations" => $affiliations[$pmid],
			];
			if (isset($pmidsToUpdateDates[$licensePlate])) {
				# avoid duplicating upload rows
				$uploadRow["citation_date"] = $pmidsToUpdateDates[$licensePlate];
			}
			$uploadUpdates[] = $uploadRow;
		}
	}
	foreach ($pmidsToUpdateDates as $licensePlate => $newDate) {
		# avoid duplicating upload rows
		if (!isset($pmidsToUpdateAffiliations[$licensePlate])) {
			list($recordId, $instance) = explode(":", $licensePlate);
			$uploadUpdates[] = [
				"record_id" => $recordId,
				"redcap_repeat_instrument" => "citation",
				"redcap_repeat_instance" => $instance,
				"citation_date" => $newDate,
			];
		}
	}
	if (!empty($uploadUpdates)) {
		Upload::rowsByPid($uploadUpdates, $pid);
	}
}

# one-time backfill script for citation_pubmed_keywords
function updatePubMedKeywords($pid, array $records): void {
	$pmidsToUpdate = Download::oneFieldWithInstancesByPid($pid, "citation_pmid");
	$upload = [];
	foreach ($records as $recordId) {
		if (!empty($pmidsToUpdate[$recordId] ?? [])) {
			$pmidsToDownload = array_values($pmidsToUpdate[$recordId]);
			$pubmedMatches = Publications::downloadPMIDs($pmidsToDownload, $pid);  // an array of PubmedMatch objects
			foreach ($pubmedMatches as $pubmedMatch) {
				# an element of pubmedMatches returns NULL if a PMID is not found - rare, but it happens
				$keywords = ($pubmedMatch === null) ? [] : ($pubmedMatch->getVariable("Keywords") ?: []);
				if (!empty($keywords)) {
					# not every PMID has keywords
					$pmid = $pubmedMatch->getPMID();
					$instance = array_search($pmid, $pmidsToUpdate[$recordId]);
					$upload[] = [
						"record_id" => $recordId,
						"redcap_repeat_instrument" => "citation",
						"redcap_repeat_instance" => $instance,
						"citation_pubmed_keywords" => implode(Publications::LIST_SEPARATOR." ", $keywords),
					];
				}
			}
		}
	}
	if (!empty($upload)) {
		Upload::rowsByPid($upload, $pid);
	}
}
