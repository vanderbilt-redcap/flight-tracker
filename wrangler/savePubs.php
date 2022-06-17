<?php

use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\CitationCollection;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/../small_base.php");

require_once(dirname(__FILE__)."/../classes/Autoload.php");

$metadata = Download::metadata($token, $server);
$pmids = [];
if (isset($_POST['finalized'])) {
    # Do not sanitize jsons because they contain quotes
    $newFinalized = json_decode($_POST['finalized']);
    $newOmissions = json_decode($_POST['omissions']);
    $newResets = json_decode($_POST['resets']);
    $recordId = Sanitizer::sanitize($_POST['record_id']);

    $citationFields = Application::getCitationFields($metadata);
    $redcapData = Download::fieldsForRecords($token, $server, $citationFields, [$recordId]);
    $maxInstance = [
        "citation" => REDCapManagement::getMaxInstance($redcapData, "citation", $recordId),
        "eric" => REDCapManagement::getMaxInstance($redcapData, "eric", $recordId),
    ];

    $priorPMIDs = [];
    $upload = [];
    $toProcess = ["1" => $newFinalized, "0" => $newOmissions, "" => $newResets];
    $instruments = [
        "citation" => "citation_pmid",
        "eric" => "eric_id",
    ];
    foreach ($toProcess as $val => $aryOfPMIDs) {
        foreach ($aryOfIDs as $id) {
            $matched = FALSE;
            foreach ($redcapData as $row) {
                foreach ($instruments as $instrument => $idField) {
                    if (
                        ($row['record_id'] == $recordId)
                        && ($row['redcap_repeat_instrument'] == $instrument)
                        && ($id == $row[$idField])
                    ) {
                        $uploadRow = array(
                            "record_id" => $recordId,
                            "redcap_repeat_instrument" => $instrument,
                            "redcap_repeat_instance" => $row['redcap_repeat_instance'],
                            $instrument."_include" => $val,
                        );
                        $priorIDs[] = $id;
                        $upload[] = $uploadRow;
                        $matched = TRUE;
                        break;
                    }
                }
            }
            if (!$matched) {
                # new citation
                $instrument = Citation::getInstrumentFromId($id);
                $maxInstance[$instrument]++;
                if ($instrument == "citation") {
                    $uploadRows = Publications::getCitationsFromPubMed([$id], $metadata, "manual", $recordId, $maxInstance[$instrument], [], $pid);
                } else if ($instrument == "eric") {
                    $uploadRows = Publications::getCitationsFromERIC([$id], $metadata, "manual", $recordId, $maxInstance[$instrument], [], $pid);
                } else {
                    $uploadRows = [];
                }
                foreach ($uploadRows as $uploadRow) {
                    $upload[] = $uploadRow;
                }
                $priorIDs[] = $id;
            }
        }
    }
    if (!empty($upload)) {
        $feedback = Upload::rows($upload, $token, $server);
        echo json_encode($feedback);
    } else {
        $data = array("error" => "You don't have any new citations enqueued to change!");
        echo json_encode($data);
    }
    $pubs = new Publications($token, $server, $metadata);
    $pubs->setRows($redcapData);
    $pubs->deduplicateCitations($recordId);
    exit;
} else if (isset($_POST['pmid'])) {
    $pmids = [Sanitizer::sanitize($_POST['pmid'])];
} else if (isset($_POST['pmids'])) {
    $pmids = [];
    foreach ($_POST['pmids'] as $pmid) {
        $pmids[] = Sanitizer::sanitize($pmid);
    }
} else {
    $data = array("error" => "You don't have any input! This should never happen.");
    echo json_encode($data);
    exit;
}


if ($pmids && !empty($pmids)) {
    $recordId = $_POST['record_id'];
    $citationFields = Application::getCitationFields($metadata);
    $redcapData = Download::fieldsForRecords($token, $server, $citationFields, [$recordId]);

    $existingPMIDs = [];
    foreach ($redcapData as $row) {
        if (($row['redcap_repeat_instrument'] == "citation") && $row['citation_pmid']) {
            $existingPMIDs[] = $row['citation_pmid'];
        }
    }
    $dedupedPMIDs = [];
    foreach ($pmids as $pmid) {
        if (!in_array($pmid, $existingPMIDs)) {
            $dedupedPMIDs[] = $pmid;
        }
    }

    if (!empty($dedupedPMIDs) && $recordId) {
        $maxInstance = REDCapManagement::getMaxInstance($redcapData, "citation", $recordId);
        $maxInstance++;
        $upload = Publications::getCitationsFromPubMed($dedupedPMIDs, $metadata, "manual", $recordId, $maxInstance, [], $pid);
        for ($i = 0; $i < count($upload); $i++) {
            if ($upload[$i]['redcap_repeat_instrument'] == "citation") {
                $upload[$i]['citation_include'] = '1';
            }
        }
        if (!empty($upload)) {
            $feedback = Upload::rows($upload, $token, $server);
            echo json_encode($feedback);
        } else {
            echo json_encode(array("error" => "Upload queue empty!"));
        }
    } else {
        $feedback = [
            "error" => "All of the requested PMIDs exist in the database. Perhaps they have been omitted earlier.",
        ];
        echo json_encode($feedback);
    }
    $pubs = new Publications($token, $server, $metadata);
    $pubs->setRows($redcapData);
    $pubs->deduplicateCitations($recordId);
} else {
    $feedback = [
        "error" => "Empty list of PMIDs",
    ];
    echo json_encode($feedback);
}
