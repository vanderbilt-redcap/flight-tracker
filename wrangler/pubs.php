<?php

use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../small_base.php");

header("Location: ".Application::link("wrangler/include.php", $pid)."&wranglerType=Publications");
