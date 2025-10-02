<?php

use Vanderbilt\CareerDevLibrary\Application;

require_once(__DIR__."/classes/Autoload.php");

$url = Application::link("portal/index.php");
header("Location: ".$url);
