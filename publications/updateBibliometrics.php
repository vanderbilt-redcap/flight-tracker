<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Publications;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../classes/Publications.php");


function updateBibliometrics($token, $server, $pid, $records) {
    $metadata = Download::metadata($token, $server);
    foreach ($records as $recordId) {
        $redcapData = Download::fieldsForRecords($token, $server, Application::getCitationFields($metadata), [$recordId]);
        Application::log("$pid: Updating metrics for Record $recordId (".count($redcapData)." rows)");
        $pubs = new Publications($token, $server, $metadata);
        $pubs->setRows($redcapData);
        $pubs->updateMetrics();
    }
}