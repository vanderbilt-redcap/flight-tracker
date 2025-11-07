<?php

namespace Vanderbilt\CareerDevLibrary;

require_once dirname(__FILE__)."/preliminary.php";
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/base.php");

$recordId = REDCapManagement::sanitize($_GET['menteeRecord']);

if ($_POST['name']) {
    $name = REDCapManagement::sanitize($_POST['name']);
    list($firstName, $lastName) = NameMatcher::splitName($name);
    $userids = REDCapManagement::getUseridsFromREDCap($firstName, $lastName);
    echo json_encode($userids);
}
