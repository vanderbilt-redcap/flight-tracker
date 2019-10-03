<?php

use \Vanderbilt\FlightTrackerExternalModule\CareerDev;

require_once(dirname(__FILE__)."/charts/baseWeb.php");
require_once(dirname(__FILE__)."/CareerDev.php");

echo "<h1>Flight Tracker Consortium</h1>\n";

echo "<div style='margin: 0px auto; max-width: 800px;'>\n";

echo "<p class='centered'>The English word <i>consortium</i> derives from the Latin work <i>consors</i>, which means 'sharing, partner.' In this sense, we partner together to solve shared problems by shared resources about the career development of academics.</p>\n";

echo "<h2>Community Ethic</h2>\n";

echo "<p>Like any community, we maintain certain ground-rules to treat each other with respect.</p>\n";
echo "<ol>\n";
echo "<li>Monthly meetings identify common needs of the community. The development team for Flight Tracker will make strategic additions to the software driven in part by shared needs of the consortium. Priority will be given to projects through a blend of group prioritization, ease of implementation, and current work plan.</li>\n";
echo "<li>The software is offered under the <a href='".CareerDev::link("license.html")."'>MIT License</a>. This license allows for local modifications and customization of the Flight Tracker software.</li>\n";
echo "<li>Those involved in the pilot will have technical support free-of-charge for the duration of the pilot. When the software is broadly released upon the end of the pilot, individualized consultation with the Flight Tracker development team will be offered by the <a href='mailto:datacore@vumc.org'>Vanderbilt University Medical Center's Data Core</a> at a fixed hourly rate. The programming of the changes still must be completed by your local development team.</li>\n";
echo "<li>Member groups are expected to contribute how they can - through, for instance, suggesting new features, testing the software, detecting and reporting bugs, and the development of new code.</li>\n";
echo "</ol>\n";

echo "</div>\n";
