<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../CareerDev.php");

echo "<video id='movie' height='720' width='1280' controls src='".CareerDev::link("help/install_480.mov")."'></video>\n";

