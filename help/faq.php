<?php

use Vanderbilt\FlightTrackerExternalModule\CareerDevHelp;
use Vanderbilt\CareerDevLibrary\Application;
use Vanderbilt\CareerDevLibrary\Links;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../classes/Autoload.php");
require_once(dirname(__FILE__)."/../CareerDevHelp.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$printCSSUrl = Application::link("css/print.css");

echo "<link rel=\"stylesheet\" media=\"print\" href=\"$printCSSUrl\" />";
echo "<h1>Frequently Asked Questions</h1>\n";
echo CareerDevHelp::getVideoVaultLinkHTML();

echo "<div id='faq'>\n";
echo CareerDevHelp::getFAQ();
echo "</div>\n";
