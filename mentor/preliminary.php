<?php

# for NOAUTH to work, must be listed before any redcap_connect inclusion

use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

define('DEBUG', Application::isVanderbilt());
