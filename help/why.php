<?php

use Vanderbilt\FlightTrackerExternalModule\CareerDevHelp;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDevHelp.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

echo "<h1>Frequently Asked Questions: Why?</h1>\n";

echo "<div id='faq'>\n";
echo CareerDevHelp::getWhyUse();
echo "</div>\n";
