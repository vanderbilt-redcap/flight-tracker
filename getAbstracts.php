<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;
use \Vanderbilt\CareerDevLibrary\Grant;

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/Application.php");
require_once(dirname(__FILE__)."/classes/Download.php");
require_once(dirname(__FILE__)."/classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/classes/Grants.php");
require_once(dirname(__FILE__)."/classes/Grant.php");

$records = Download::recordIds($token, $server);
$metadata = Download::metadata($token, $server);
$names = Download::names($token, $server);
$choices = REDCapManagement::getChoices($metadata);

$outputRows = [];
foreach ($records as $recordId) {
    $fields = array_unique(array_merge(Application::getExporterFields($metadata), Application::$summaryFields));
    $redcapData = Download::fieldsForRecords($token, $server, $fields, [$recordId]);
    $normativeRow = REDCapManagement::getNormativeRow($redcapData);
    $conversionStatus = $normativeRow['summary_ever_last_any_k_to_r01_equiv'];
    for ($i = 1; $i <= MAX_GRANTS; $i++) {
        if ($normativeRow['summary_award_date_'.$i]) {
            $sponsorNo = Grant::transformToBaseAwardNumber($normativeRow['summary_award_sponsorno_'.$i]);
            foreach ($redcapData as $row) {
                if ($row['redcap_repeat_instrument'] == "exporter") {
                    $exporterBaseAwardNo = Grant::transformToBaseAwardNumber($row['exporter_full_project_num']);
                    if ($row['exporter_abstract'] && ($exporterBaseAwardNo == $sponsorNo)) {
                        $outputRows[$recordId.":".$sponsorNo] = [
                            $recordId,
                            $names[$recordId],
                            $choices['summary_ever_last_any_k_to_r01_equiv'][$conversionStatus],
                            $sponsorNo,
                            $normativeRow['summary_award_title_'.$i],
                            $row['exporter_abstract'],
                        ];
                    }
                }
            }
        }
    }
}
$headers = ["Record ID", "Name", "Conversion Status", "Grant/Award", "Title", "Abstract"];

header('Content-Type: application/csv');
header('Content-Disposition: attachment; filename="Abstracts.csv";');
$fp = fopen('php://output', 'w');
fputcsv($fp, $headers);
foreach ($outputRows as $grantNo => $row) {
    fputcsv($fp, $row);
}
fclose($fp);