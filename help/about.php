<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;
use \Vanderbilt\CareerDevLibrary\Application;

require_once(dirname(__FILE__)."/../small_base.php");
require_once(dirname(__FILE__)."/../charts/baseWeb.php");
require_once(dirname(__FILE__)."/../CareerDev.php");
require_once(dirname(__FILE__)."/../Application.php");

$version = CareerDev::getVersion();

?>
<h1>About</h1>
<p class='centered'><img style='width: 500px;' src='<?= CareerDev::link("img/flight_tracker_logo_medium.png") ?>'><br>Version <?= $version ?></p>

<h2>Thanks</h2>
<p class='centered'>We are grateful for those who have gone before us and for those who assist, advise, and teach us. We extend special thanks to:</p>

<p class='centered nomargin'>The CTSA Program from NCATS</p>
<p class='centered nomargin'>The Vanderbilt Institute for Clinical and Translational Research (VICTR)</p>
<p class='centered nomargin'>The REDCap Team at Vanderbilt</p>
<p class='centered nomargin'>Those who provide data to us: the RePORTER programs, PubMed, iCite, and COEUS</p>
<p class='centered nomargin'><a href='https://edgeforscholars.org/'>Edge for Scholars</a></p>

<h3>Development Team</h3>
<p class='centered'><img src="<?= Application::link("img/efs_small.png") ?>" style='width: 200px; height: 124px;' alt="Edge for Scholars"></p>
<p class='centered'>The <a href='https://my.vanderbilt.edu/ctcareerdevelopment/'>Edge for Scholars Team</a> at Vanderbilt University Medical Center</p>
