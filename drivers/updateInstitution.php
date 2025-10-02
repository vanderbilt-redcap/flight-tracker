<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__."/../classes/Autoload.php");

function updateInstitution($token, $server, $pid, $records) {
	$compileInstitutionFields = array_keys(Scholar::getDefaultOrder("identifier_institution"));
	$fields = Scholar::getInstitutionFields(array_unique(array_merge(["record_id"], $compileInstitutionFields)));
	$metadata = Download::metadata($token, $server);
	$upload = [];
	foreach ($records as $recordId) {
		$redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
		$oldInstitutionText = REDCapManagement::findField($redcapData, $recordId, "identifier_institution");
		$scholar = new Scholar($token, $server, $metadata, $pid);
		$scholar->setRows($redcapData);
		$newInstitutionText = $scholar->getInstitutionText();
		if ($oldInstitutionText != $newInstitutionText) {
			$upload[] = [
				"record_id" => $recordId,
				"identifier_institution" => $newInstitutionText,
			];
		}
	}

	if (!empty($upload)) {
		Upload::rows($upload, $token, $server);
	}
}
