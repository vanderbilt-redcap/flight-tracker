<?php

# for NOAUTH to work, must be listed before any redcap_connect inclusion

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

define('MMA_DEBUG',Application::isVanderbilt() || Application::isLocalhost());
