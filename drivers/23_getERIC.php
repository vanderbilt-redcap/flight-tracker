<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

function getERIC($token, $server, $pid, $records) {
	$switches = new FeatureSwitches($token, $server, $pid);
	if (empty($records)) {
		$records = Download::recordIds($token, $server);
	}
	if ($switches->isOnForProject("ERIC (Education Publications)")) {
		Application::log("Is On For Project", $pid);
		$recordsToRun = Download::recordIds($token, $server);
	} else {
		$recordsToRun = $switches->getRecordsTurnedOn("ERIC (Education Publications)");
	}
	$intersectedRecords = array_intersect($recordsToRun, $records);
	if (empty($intersectedRecords)) {
		return;
	}
	Application::log("Getting ERIC for records: ".implode(", ", $intersectedRecords), $pid);
	$prefix = "eric_";
	$instrument = "eric";

	$firstNames = Download::firstnames($token, $server);
	$lastNames = Download::lastnames($token, $server);
	$metadata = Download::metadata($token, $server);
	$ericREDCapFields = ERIC::getFields($metadata);
	if (empty($ericREDCapFields)) {
		return;
	}
	$ericFields = DataDictionaryManagement::removePrefix($ericREDCapFields, $prefix);
	$maxRowCount = 2000;     // limit set by ERIC at 2000
	$maxIterations = 20;
	$priorIds = Download::oneFieldWithInstances($token, $server, "eric_id");
	$priorERICTitles = Download::oneFieldWithInstances($token, $server, "eric_title");
	$priorPubMedTitles = Download::oneFieldWithInstances($token, $server, "citation_title");
	foreach ($intersectedRecords as $recordId) {
		$firstName = $firstNames[$recordId] ?: "";
		$lastName = $lastNames[$recordId] ?: "";
		if (!$firstName || !$lastName) {
			continue;
		}
		$upload = [];
		$priorIdsForRecord = $priorIds[$recordId] ?? [];
		$listOfPriorTitles = array_merge(array_values($priorERICTitles[$recordId] ?? []), array_values($priorPubMedTitles[$recordId] ?? []));
		$maxInstance = empty($priorIdsForRecord) ? 0 : max(array_keys($priorIdsForRecord));
		$listOfPriorIds = array_values($priorIdsForRecord);

		foreach (NameMatcher::explodeFirstName($firstName) as $fn) {
			foreach (NameMatcher::explodeLastName($lastName) as $ln) {
				$startNum = 0;
				do {
					usleep(300000);
					# if spaces are present, a 502 Bad Gateway error is returned
					$fn = preg_replace("/\s+/", "%20", $fn);
					$ln = preg_replace("/\s+/", "%20", $ln);
					$url = ERIC::makeURL($metadata, "author:%22$ln,%20$fn%22", $maxRowCount, $startNum);
					list($resp, $output) = URLManagement::downloadURL($url, $pid);
					$returnData = json_decode($output, true);
					if (URLManagement::isGoodResponse($resp) && $returnData && isset($returnData["response"])) {
						$numFound = $returnData["response"]["numFound"] ?? 0;
						$runAgain = ($numFound == $maxRowCount);
						$docs = $returnData["response"]["docs"] ?? [];
						$newRows = ERIC::process($docs, $metadata, $recordId, $listOfPriorIds, $listOfPriorTitles, $maxInstance);
						$upload = array_merge($upload, $newRows);
					} else {
						$runAgain = false;
					}
					$startNum += $maxRowCount;
				} while ($runAgain && ($startNum / $maxRowCount < $maxIterations));
			}
		}

		if (!empty($upload)) {
			Application::log("Uploading ".count($upload)." rows of $instrument data for Record $recordId", $pid);
			Upload::rows($upload, $token, $server);
		}
	}
	Application::saveCurrentDate("Last ERIC Download", $pid);
}
