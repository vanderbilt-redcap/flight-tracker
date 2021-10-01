<?php

use \Vanderbilt\CareerDevLibrary\NameMatcher;
use \Vanderbilt\CareerDevLibrary\REDCapManagement;

require_once dirname(__FILE__)."/preliminary.php";
require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/base.php");

$recordId = htmlentities($_GET['menteeRecord'], ENT_QUOTES);

if ($_POST['name']) {
    $name = htmlentities($_POST['name'], ENT_QUOTES);
    list($firstName, $lastName) = NameMatcher::splitName($name);
    $userids = REDCapManagement::getUseridsFromREDCap($firstName, $lastName);
    echo json_encode($userids);
}
