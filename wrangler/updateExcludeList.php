<?php

use \Vanderbilt\CareerDevLibrary\ExcludeList;
use \Vanderbilt\CareerDevLibrary\Download;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");

$records = Download::recordIds($token, $server);
if (!in_array($_POST['record'], $records)) {
    throw new \Exception("Invalid record ".$_POST['record']);
}

$list = new ExcludeList($_POST['type'], $pid);
$feedback = $list->updateValue($_POST['record'], $_POST['value']);
echo json_encode($feedback);
