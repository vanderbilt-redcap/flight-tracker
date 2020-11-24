<?php

use \Vanderbilt\CareerDevLibrary\Upload;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Citation;
use \Vanderbilt\CareerDevLibrary\CitationCollection;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");

require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Publications.php");
require_once(dirname(__FILE__)."/../classes/Citation.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");

$metadata = Download::metadata($token, $server);

if (isset($_POST['finalized'])) {
    $newFinalized = json_decode($_POST['finalized']);
    $newOmissions = json_decode($_POST['omissions']);
    $newResets = json_decode($_POST['resets']);
    $recordId = $_POST['record_id'];

    $citationFields = Application::getCitationFields($metadata);
    $redcapData = Download::fieldsForRecords($token, $server, $citationFields, [$recordId]);

    $priorPMIDs = [];
    $upload = array();
    $toProcess = array("1" => $newFinalized, "0" => $newOmissions, "" => $newResets);
    foreach ($toProcess as $val => $aryOfPMIDs) {
        foreach ($aryOfPMIDs as $pmid) {
            $matched = FALSE;
            foreach ($redcapData as $row) {
                if (($row['record_id'] == $recordId) && ($row['redcap_repeat_instrument'] == "citation")) {
                    if ($pmid == $row['citation_pmid']) {
                        $uploadRow = array(
                            "record_id" => $recordId,
                            "redcap_repeat_instrument" => "citation",
                            "redcap_repeat_instance" => $row['redcap_repeat_instance'],
                            "citation_include" => $val,
                        );
                        array_push($priorPMIDs, $pmid);
                        array_push($upload, $uploadRow);
                        $matched = TRUE;
                    }
                }
            }
            if (!$matched) {
                # new citation
                $maxInstance = REDCapManagement::getMaxInstance($redcapData, "citation", $recordId);
                $maxInstance++;
                $uploadRows = Publications::getCitationsFromPubMed(array($pmid), $metadata,"manual", $recordId, $maxInstance, [], $pid);
                array_push($priorPMIDs, $pmid);
                foreach ($uploadRows as $uploadRow) {
                    array_push($upload, $uploadRow);
                }
            }
        }
    }
    if (!empty($upload)) {
        $feedback = Upload::rows($upload, $token, $server);
        echo json_encode($feedback);
        // echo " ".REDCapManagement::json_encode_with_spaces($upload);
        $pubs = new Publications($token, $server, $metadata);
        $pubs->setRows($redcapData);
        $pubs->deduplicateCitations($recordId);
    } else {
        $data = array("error" => "You don't have any new citations enqueued to change!");
        echo json_encode($data);
    }
} else if (isset($_POST['pmid'])) {
    $pmids = [$_POST['pmid']];
} else if (isset($_POST['pmids'])) {
    $pmids = $_POST['pmids'];
} else {
    $data = array("error" => "You don't have any input! This should never happen.");
    echo json_encode($data);
}

if ($pmids && !empty($pmids)) {
    $recordId = $_POST['record_id'];
    $citationFields = Application::getCitationFields($metadata);
    $redcapData = Download::fieldsForRecords($token, $server, $citationFields, [$recordId]);
    if ($recordId) {
        $maxInstance = REDCapManagement::getMaxInstance($redcapData, "citation", $recordId);
        $maxInstance++;
        $upload = Publications::getCitationsFromPubMed($pmids, $metadata, "manual", $recordId, $maxInstance, [], $pid);
        if (!empty($upload)) {
            $feedback = Upload::rows($upload, $token, $server);
            echo json_encode($feedback)."\n".REDCapManagement::json_encode_with_spaces($upload)."\n".REDCapManagement::json_encode_with_spaces($redcapData)."\n".REDCapManagement::json_encode_with_spaces($citationFields)."\n".count($metadata)." rows";
        } else {
            echo json_encode(array("error" => "Upload queue empty!"));
        }
    }
    $pubs = new Publications($token, $server, $metadata);
    $pubs->setRows($redcapData);
    $pubs->deduplicateCitations($recordId);
}

