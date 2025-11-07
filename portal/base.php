<?php

use \Vanderbilt\CareerDevLibrary\Portal;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(__DIR__."/../classes/Autoload.php");

$portalJSUrl = Application::link("portal/js/portal.js");
$portalCSSUrl = Application::link("portal/css/portal.css");

echo Portal::getHeaders();
echo "<link rel='stylesheet' href='$portalCSSUrl' />";
echo "<script src='$portalJSUrl'></script>";
