<?php

use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/NameMatcher.php");
require_once(dirname(__FILE__)."/../classes/REDCapManagement.php");
require_once(dirname(__FILE__)."/base.php");

$recordId = $_GET['menteeRecord'];
authenticate($userid, $recordId);

if ($_POST['name']) {
    list($firstName, $lastName) = NameMatcher::splitName($_POST['name']);
    $userids = REDCapManagement::getUseridsFromREDCap($firstName, $lastName);
    echo json_encode($userids);
}
