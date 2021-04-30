<?php

use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../Application.php");

header("Location: ".Application::link("wrangler/include.php")."&wranglerType=Patents");
