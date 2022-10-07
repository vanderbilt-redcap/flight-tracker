<?php

namespace Vanderbilt\CareerDevLibrary;
use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

function getPatents($token, $server, $pid, $records) {
    $metadata = Download::metadata($token, $server);
    $forms = REDCapManagement::getFormsFromMetadata($metadata);
    $patentFields = REDCapManagement::getFieldsFromMetadata($metadata, "patent");
    if (in_array("patent", $forms)) {
        $lastNames = Download::lastnames($token, $server);
        $firstNames = Download::firstnames($token, $server);
        $institutions = Download::institutionsAsArray($token, $server);
        $metadataFields = REDCapManagement::getFieldsFromMetadata($metadata);
        if (in_array("summary_training_start", $metadataFields)) {
            $startDates = Download::oneField($token, $server, "summary_training_start");
        } else {
            $startDates = [];
        }
        $upload = [];
        foreach ($records as $recordId) {
            $redcapData = Download::fieldsForRecords($token, $server, array_unique(array_merge(["record_id"], $patentFields)), [$recordId]);
            $previousNumbers = REDCapManagement::findAllFields($redcapData, $recordId, "patent_number");
            $maxInstance = REDCapManagement::getMaxInstance($redcapData, "patent", $recordId);
            $myInstitutions = array_unique(array_merge($institutions[$recordId], Application::getInstitutions($pid), Application::getHelperInstitutions()));

            Application::log("Searching for {$firstNames[$recordId]} {$lastNames[$recordId]} at ".json_encode($myInstitutions), $pid);
            $p = new PatentsView($recordId, $pid, $startDates[$recordId] ?: "none", $metadata);
            $p->setName($lastNames[$recordId], $firstNames[$recordId]);
            $uploadRows = $p->getFilteredPatentsAsREDCap($myInstitutions, $maxInstance, $previousNumbers);
            if (count($uploadRows) > 0) {
                Application::log("Got ".count($uploadRows)." new patents for Record $recordId", $pid);
                $upload = array_merge($upload, $uploadRows);
            }
        }
        if (!empty($upload)) {
            Upload::rows($upload, $token, $server);
        }
    }
    REDCapManagement::deduplicateByKey($token, $server, $pid, $records, "patent_number", "patent_", "patent");
    CareerDev::saveCurrentDate("Patents View Download", $pid);
}