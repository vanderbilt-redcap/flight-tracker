<?php

use \Vanderbilt\CareerDevLibrary\Upload;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Upload.php");

$recordId = 16;
$feedback = Upload::deleteForm($token, $server, $pid, "summary_award_", $recordId);
echo json_encode($feedback);