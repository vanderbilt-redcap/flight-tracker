<?php

namespace Vanderbilt\CareerDevLibrary;

use Vanderbilt\CareerDevLibrary\Download;
use Vanderbilt\CareerDevLibrary\Upload;
use Vanderbilt\CareerDevLibrary\Publications;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

function updatePMCs($token, $server, $pid, $records) {
	foreach ($records as $recordId) {
		$redcapData = Download::fieldsForRecords($token, $server, ["record_id", "citation_pmid"], [$recordId]);
		$pmids = [];
		$upload = [];
		foreach ($redcapData as $row) {
			if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "citation") && $row['citation_pmid']) {
				$pmids[$row['redcap_repeat_instance']] = $row['citation_pmid'];
			}
		}

		if (!empty($pmids)) {
			$translator = Publications::PMIDsToPMCs(array_values($pmids), $pid);
			foreach ($pmids as $instance => $pmid) {
				if ($translator[$pmid]) {
					$pmcid = $translator[$pmid];
				} else {
					$pmcid = "";
				}
				$upload[] = [
					"record_id" => $recordId,
					"redcap_repeat_instrument" => "citation",
					"redcap_repeat_instance" => $instance,
					"citation_pmcid" => $pmcid,
				];
			}
		}
		if (!empty($upload)) {
			Upload::rows($upload, $token, $server);
		}
	}
}

function cleanUpBlankInstances($token, $server, $pid, $records) {
	foreach ($records as $recordId) {
		$redcapData = Download::fieldsForRecords($token, $server, ["record_id", "citation_pmid", "citation_pmcid"], [$recordId]);
		$instancesToDelete = [];
		foreach ($redcapData as $row) {
			if (($row['redcap_repeat_instrument'] == "citation") && $row['citation_pmcid'] && !$row['citation_pmid']) {
				$instancesToDelete[] = $row['redcap_repeat_instance'];
			}
		}
		if (!empty($instancesToDelete)) {
			Upload::deleteFormInstances($token, $server, $pid, "citation", $recordId, $instancesToDelete);
		}
	}
}
