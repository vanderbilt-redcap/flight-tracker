<?php

use \Vanderbilt\CareerDevLibrary\Application;
use \Vanderbilt\CareerDevLibrary\NIHTables;
use \Vanderbilt\CareerDevLibrary\Download;
use \Vanderbilt\CareerDevLibrary\Cohorts;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../Application.php");
require_once(dirname(__FILE__)."/../classes/NIHTables.php");
require_once(dirname(__FILE__)."/../classes/Download.php");
require_once(dirname(__FILE__)."/../classes/Cohorts.php");

$originUrl = $_POST['origin'];
$cohort = $_GET['cohort'];
$data = [];

$metadata = Download::metadata($token, $server);
$tables = new NIHTables($token, $server, $pid, $metadata);
$cohorts = new Cohorts($token, $server, $metadata);

echo json_encode($data);

