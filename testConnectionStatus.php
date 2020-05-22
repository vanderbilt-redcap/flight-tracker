<?php

use \Vanderbilt\CareerDevLibrary\ConnectionStatus;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/Application.php");
require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/classes/ConnectionStatus.php");

$name = $_POST['name'];
$server = $_POST['server'];

if ($name && $server) {
    $connStatus = new ConnectionStatus($name, $server);
    $results = $connStatus->test();
    foreach ($results as $key => $result) {
        if (preg_match("/error/i", $result)) {
            Application::log("$server: $key - ".$result);
        }
    }
    echo json_encode($results);
} else {
    echo "[]";
}