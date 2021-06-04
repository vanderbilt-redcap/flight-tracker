<?php

use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../classes/Autoload.php");

header("Location: ".Application::link("wrangler/include.php")."&wranglerType=Publications");
