<?php

use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Sanitizer;

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/classes/Autoload.php");
require_once(dirname(__FILE__)."/drivers/6d_makeSummary.php");

$records = Download::recordIds($token, $server);
$recordId = Sanitizer::getSanitizedRecord($_POST['record'], $records);
if (!$recordId) {
    die("Error: Invalid Record-ID");
}

$metadata = Download::metadata($token, $server);
try {
    summarizeRecord($token, $server, $pid, $recordId, $metadata);
    echo "Success.";
} catch (\Exception $e) {
    echo "Error: ".$e->getMessage();
}
