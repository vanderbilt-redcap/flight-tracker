<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");

$home = "https://redcap.vumc.org/plugins/career_dev/help/";

echo "<h1>Training Videos</h1>\n";

$videoPlaceholders = array(
				"intro.php" => "Introduction to Flight Tracker",
				"install.php" => "Installing Flight Tracker",
				);

foreach ($videoPlaceholders as $page => $title) {
	echo "<h4><a href='".$home.$page."'>$title</a></h4>\n";
}
