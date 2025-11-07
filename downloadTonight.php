<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/small_base.php");
require_once(dirname(__FILE__)."/CareerDev.php");

CareerDev::enqueueTonight();

echo  "success!";
